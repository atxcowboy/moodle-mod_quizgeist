import {LiveApi} from '../live/api';
import {liveButton, liveElement} from '../live/dom';
import type {HostCardSet, HostConfig, LiveTeamOption} from '../live/types';

/**
 * F11a Karten-Modus — preparing the printed answer cards.
 *
 * The scan itself happens on the question screen (card-scan.ts). A class can
 * only hold up cards that were printed BEFORE the lesson, so the sets are
 * created here, on the setup screen: name, card layout, the Moodle group
 * whose participants get a card, and a few reserve cards. The server picks
 * the holders itself; this module only names the group.
 *
 * Both actions belong to the AI addon. The module reads the page's capability
 * report (`features.ai.installed`) itself and renders nothing without it.
 */

const LAYOUTS = ['abcd', 'abcdef', 'truefalse'] as const;
const MAX_NAME = 255;
const MAX_RESERVES = 50;

type Text = (key: string, fallback: string, values?: Record<string, string | number>) => string;

interface CardSetResult {
  cardSet?: HostCardSet | null;
  cardSets?: HostCardSet[];
  validationErrors?: Array<{code: string; field: string; message?: string}>;
}

export interface CardSetPanelContext {
  api: LiveApi;
  config: HostConfig;
  groups: LiveTeamOption[];
  /** Hands the new list to the host, which owns `config.cardSets`. */
  onChange: (sets: HostCardSet[]) => void;
  text: Text;
}

interface PanelState {
  confirmId: number;
  error: boolean;
  open: boolean;
  status: string;
}

function normaliseSets(raw: unknown): HostCardSet[] {
  if (!Array.isArray(raw)) {
    return [];
  }
  return raw
    .filter((entry): entry is Record<string, unknown> => typeof entry === 'object' && entry !== null)
    .map((entry) => ({
      cardCount: Number(entry.cardCount) || 0,
      id: Number(entry.id) || 0,
      layout: String(entry.layout || ''),
      name: String(entry.name || ''),
    }))
    .filter((entry) => entry.id > 0);
}

/**
 * Render the card set block of the setup screen, or null when the card mode
 * is not offered on this page (no AI addon, or no right to scan cards).
 */
export function renderCardSetPanel(context: CardSetPanelContext): HTMLElement | null {
  const {config, text} = context;
  const printUrl = config.cardsPrintUrl;
  if (config.features?.ai?.installed !== true
      || !Array.isArray(config.cardSets)
      || typeof printUrl !== 'string'
      || printUrl === '') {
    return null;
  }
  const canCreate = config.features?.ai?.canCreate !== false;
  let sets = config.cardSets.slice();
  const state: PanelState = {confirmId: 0, error: false, open: false, status: ''};

  const details = liveElement('details', 'quizgeist-host-cardsets');
  details.dataset.liveCardsets = '';
  const summary = liveElement('summary', 'quizgeist-host-cardsets__summary');
  const body = liveElement('div', 'quizgeist-host-cardsets__body');
  details.append(summary, body);
  details.addEventListener('toggle', () => {
    state.open = details.open;
  });

  const layoutLabel = (layout: string): string => text(
    `cards:layout:${layout}`,
    layout === 'truefalse' ? 'Wahr/Falsch' : layout.toUpperCase(),
  );

  const failMessage = (result: CardSetResult): string => {
    const first = Array.isArray(result.validationErrors) ? result.validationErrors[0] : undefined;
    return first?.message && first.message !== ''
      ? first.message
      : text('cards:sets:error', 'Der Kartensatz konnte nicht gespeichert werden.');
  };

  const run = (
    trigger: HTMLButtonElement,
    work: () => Promise<CardSetResult>,
    success: (result: CardSetResult) => string,
  ): void => {
    trigger.disabled = true;
    void work()
      .then((result) => {
        const errors = Array.isArray(result.validationErrors) ? result.validationErrors : [];
        if (Array.isArray(result.cardSets)) {
          sets = normaliseSets(result.cardSets);
          context.onChange(sets);
        }
        state.error = errors.length > 0;
        state.status = state.error ? failMessage(result) : success(result);
        if (!state.error) {
          state.confirmId = 0;
        }
      })
      .catch((error: unknown) => {
        state.error = true;
        state.status = error instanceof Error && error.message !== ''
          ? error.message
          : text('cards:sets:error', 'Der Kartensatz konnte nicht gespeichert werden.');
      })
      .finally(() => {
        trigger.disabled = false;
        render();
        body.querySelector<HTMLElement>('.quizgeist-host-cardsets__status')?.focus();
      });
  };

  const renderSet = (set: HostCardSet): HTMLElement => {
    const item = liveElement('li', 'quizgeist-host-cardsets__item');
    item.dataset.cardSetId = String(set.id);
    const head = liveElement('div', 'quizgeist-host-cardsets__head');
    head.append(
      liveElement('span', 'quizgeist-host-cardsets__name', {text: set.name}),
      liveElement('span', 'quizgeist-host-cardsets__meta', {
        text: text('cards:sets:meta', '{$a->layout} · {$a->count} Karten', {
          count: set.cardCount,
          layout: layoutLabel(set.layout),
        }),
      }),
    );
    item.append(head);
    const actions = liveElement('div', 'quizgeist-host-cardsets__actions');
    if (state.confirmId === set.id) {
      item.append(liveElement('p', 'quizgeist-host-cardsets__confirm', {
        text: text(
          'cards:sets:deleteconfirm',
          'Kartensatz „{$a}“ löschen? Gedruckte Karten dieses Satzes lassen sich danach nicht mehr scannen.',
          {a: set.name},
        ),
      }));
      const confirm = liveButton(
        text('cards:sets:deleteyes', 'Endgültig löschen'),
        'quizgeist-host-button quizgeist-host-button--quiet-danger quizgeist-touch-target',
        {'data-live-cardset-delete-confirm': set.id},
      );
      confirm.addEventListener('click', () => run(
        confirm,
        () => context.api.post<CardSetResult>('cardset_delete', {cardSetId: set.id}),
        () => text('cards:sets:deleted', 'Der Kartensatz ist gelöscht.'),
      ));
      const cancel = liveButton(
        text('cards:sets:cancel', 'Abbrechen'),
        'quizgeist-host-button quizgeist-host-button--secondary quizgeist-touch-target',
      );
      cancel.addEventListener('click', () => {
        state.confirmId = 0;
        render();
      });
      actions.append(confirm, cancel);
      window.requestAnimationFrame(() => cancel.focus());
    } else {
      const print = liveElement('a', 'quizgeist-host-button quizgeist-host-button--secondary quizgeist-touch-target', {
        href: `${printUrl}&cardset=${set.id}`,
        rel: 'noopener',
        target: '_blank',
        text: text('cards:scan:print', 'Kartenbogen als PDF drucken'),
      });
      const remove = liveButton(
        text('cards:sets:delete', 'Löschen'),
        'quizgeist-host-button quizgeist-host-button--quiet-danger quizgeist-touch-target',
        {
          'aria-label': `${text('cards:sets:delete', 'Löschen')}: ${set.name}`,
          'data-live-cardset-delete': set.id,
        },
      );
      remove.addEventListener('click', () => {
        state.confirmId = set.id;
        render();
      });
      actions.append(print, remove);
    }
    item.append(actions);
    return item;
  };

  const renderForm = (): HTMLElement => {
    const form = liveElement('form', 'quizgeist-host-cardsets__form', {
      'data-live-cardset-create': true,
    });
    form.noValidate = true;
    const id = (name: string): string => `quizgeist-cardset-${name}-${config.cmid}`;
    const field = (name: string, label: string, control: HTMLElement): HTMLElement => {
      const wrapper = liveElement('div', 'quizgeist-host-cardsets__field');
      wrapper.append(
        liveElement('label', 'quizgeist-host-field__label', {for: id(name), text: label}),
        control,
      );
      return wrapper;
    };
    const name = liveElement('input', 'quizgeist-host-text-input', {
      id: id('name'),
      maxlength: MAX_NAME,
      type: 'text',
      value: text('cards:sets:defaultname', 'Antwortkarten'),
    });
    const layout = liveElement('select', 'quizgeist-host-select', {id: id('layout')});
    LAYOUTS.forEach((value) => {
      layout.append(liveElement('option', '', {
        text: text(`cards:layout:${value}:long`, layoutLabel(value)),
        value,
      }));
    });
    const group = liveElement('select', 'quizgeist-host-select', {id: id('group')});
    group.append(liveElement('option', '', {
      text: text('cards:sets:allparticipants', 'Alle Teilnehmenden'),
      value: '0',
    }));
    context.groups.forEach((option) => {
      const groupId = Number(option.id);
      if (Number.isInteger(groupId) && groupId > 0) {
        group.append(liveElement('option', '', {text: option.name, value: String(groupId)}));
      }
    });
    const reserves = liveElement('input', 'quizgeist-host-text-input', {
      id: id('reserves'),
      inputmode: 'numeric',
      max: MAX_RESERVES,
      min: 0,
      type: 'number',
      value: 4,
    });
    const submit = liveElement('button', 'quizgeist-host-button quizgeist-host-button--primary quizgeist-touch-target', {
      text: text('cards:sets:create', 'Kartensatz anlegen'),
      type: 'submit',
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const cleanName = name.value.trim().slice(0, MAX_NAME);
      if (cleanName === '') {
        name.focus();
        return;
      }
      const reserveCount = Math.max(0, Math.min(MAX_RESERVES, Math.round(Number(reserves.value) || 0)));
      run(
        submit,
        () => context.api.post<CardSetResult>('cardset_create', {
          groupId: Number(group.value) || 0,
          layout: layout.value,
          name: cleanName,
          reserves: reserveCount,
        }),
        (result) => text(
          'cards:sets:created',
          'Kartensatz „{$a->name}“ mit {$a->count} Karten angelegt. Drucke ihn jetzt aus.',
          {
            count: Number(result.cardSet?.cardCount) || 0,
            name: String(result.cardSet?.name || cleanName),
          },
        ),
      );
    });
    const grid = liveElement('div', 'quizgeist-host-cardsets__fields');
    grid.append(
      field('name', text('cards:sets:name', 'Name des Kartensatzes'), name),
      field('layout', text('cards:sets:layout', 'Kartenformat'), layout),
      field('group', text('cards:sets:group', 'Karten für'), group),
      field('reserves', text('cards:sets:reserves', 'Reservekarten'), reserves),
    );
    form.append(
      liveElement('h3', 'quizgeist-host-cardsets__subtitle', {
        text: text('cards:sets:new', 'Neuer Kartensatz'),
      }),
      grid,
      submit,
    );
    return form;
  };

  const render = (): void => {
    summary.textContent = text('cards:sets:title', 'Antwortkarten für den Karten-Modus ({$a})', {
      a: sets.length,
    });
    const nodes: HTMLElement[] = [
      liveElement('p', 'quizgeist-host-cardsets__intro', {
        text: text(
          'cards:sets:intro',
          'Jede Person bekommt eine gedruckte Karte. Wer kein Gerät hat, nimmst du in der Lobby über die Namensliste auf; es zählt der Zeitpunkt des Fotos.',
        ),
      }),
    ];
    if (sets.length) {
      const list = liveElement('ul', 'quizgeist-host-cardsets__list');
      sets.forEach((set) => list.append(renderSet(set)));
      nodes.push(list);
    } else {
      nodes.push(liveElement('p', 'quizgeist-host-cardsets__empty', {
        text: text('cards:sets:empty', 'Noch kein Kartensatz angelegt.'),
      }));
    }
    if (canCreate) {
      nodes.push(renderForm());
    } else {
      nodes.push(liveElement('p', 'quizgeist-host-cardsets__locked', {
        text: text(
          'cards:error:scanlocked',
          'Für den Karten-Modus fehlt eine gültige Lizenz des KI-Pakets.',
        ),
      }));
    }
    const status = liveElement('p', 'quizgeist-host-cardsets__status', {
      'aria-live': 'polite',
      role: state.error ? 'alert' : 'status',
      tabindex: -1,
      text: state.status,
    });
    status.dataset.error = state.error ? 'true' : 'false';
    status.hidden = state.status === '';
    nodes.push(status);
    body.replaceChildren(...nodes);
    details.open = state.open;
  };

  render();
  return details;
}
