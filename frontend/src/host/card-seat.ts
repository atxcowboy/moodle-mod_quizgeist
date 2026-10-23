import {LiveApi} from '../live/api';
import {liveButton, liveElement} from '../live/dom';
import type {HostCardSet, LivePlayer} from '../live/types';

/**
 * 1.2.0-rc.6 — „Ein Gerät, eine Klasse“: the roll call in the lobby.
 *
 * A card scan books only for players of the session, and a
 * player used to exist only after a device join — so a class without
 * devices could print cards but never play. This panel lists the holders of
 * one printed set; the teacher unticks whoever is absent and seats the rest.
 * Presence is the teacher's decision, never something read from a photo.
 *
 * The lobby is a stage of fixed height, so the list does not live in it: the
 * lobby gets one button, and the list opens in a modal dialog with its own
 * scroll area (the pattern of the abort dialog). On a mirrored projector the
 * class list therefore appears only while the teacher has the dialog open.
 * The object survives the lobby's re-render on every state change, like
 * CardScanPanel on the question screen.
 */

type Text = (key: string, fallback: string, values?: Record<string, string | number>) => string;

type HolderState = 'none' | 'card' | 'device';

interface RosterHolder {
  available: boolean;
  cardCode: string;
  name: string;
  state: HolderState;
  userId: number;
}

interface Roster {
  blocked: 'none' | 'freeteams' | 'started';
  cardSetId: number;
  holders: RosterHolder[];
  reserveCount: number;
  sessionId: number;
}

interface SeatResult {
  refused?: Array<{code: string; userId: number}>;
  removed?: number;
  roster?: unknown;
  seated?: number;
}

export interface CardSeatContext {
  api: LiveApi;
  /** False when the AI licence does not allow a new card round. */
  canSeat: boolean;
  cardSets: HostCardSet[];
  cmid: number;
  /** Where the dialog lives: the app root, so it also shows in full screen. */
  host: HTMLElement;
  /** Asks the host to poll at once, so the player grid and start button follow. */
  onSeated: () => void;
  text: Text;
}

function normaliseRoster(raw: unknown): Roster | null {
  if (typeof raw !== 'object' || raw === null) {
    return null;
  }
  const value = raw as Record<string, unknown>;
  const holders = Array.isArray(value.holders) ? value.holders : [];
  const blocked = value.blocked === 'freeteams' || value.blocked === 'started' ? value.blocked : 'none';
  return {
    blocked,
    cardSetId: Number(value.cardSetId) || 0,
    holders: holders
      .filter((entry): entry is Record<string, unknown> => typeof entry === 'object' && entry !== null)
      .map((entry): RosterHolder => ({
        available: entry.available === true,
        cardCode: String(entry.cardCode || ''),
        name: String(entry.name || ''),
        state: entry.state === 'card' || entry.state === 'device' ? entry.state as HolderState : 'none',
        userId: Number(entry.userId) || 0,
      }))
      .filter((entry) => entry.userId > 0),
    reserveCount: Math.max(0, Number(value.reserveCount) || 0),
    sessionId: Number(value.sessionId) || 0,
  };
}

export class CardSeatPanel {
  private readonly trigger: HTMLButtonElement;
  private dialog: HTMLDialogElement | null = null;
  private body: HTMLElement | null = null;
  private busy = false;
  private cardSetId = 0;
  private cardCount = 0;
  private checked = new Set<number>();
  private error = false;
  private roster: Roster | null = null;
  private sessionId = 0;
  private status = '';

  public constructor(private readonly context: CardSeatContext) {
    this.cardSetId = context.cardSets[0]?.id || 0;
    this.trigger = liveButton(
      this.text('cards:seat:open', 'Antwortkarten ohne Gerät'),
      'quizgeist-host-button quizgeist-host-button--secondary quizgeist-host-cardseat-open',
      {'aria-haspopup': 'dialog', 'data-live-cardseat-open': true},
    );
    this.trigger.addEventListener('click', () => this.open());
  }

  /**
   * Follow the lobby: session and the number of card seats already taken.
   * The open dialog is NOT rebuilt here, so a tick is never reset by a
   * learner who happens to join from a device meanwhile.
   */
  public update(sessionId: number, players: LivePlayer[]): HTMLElement {
    this.cardCount = players.filter((player) => player.status === 'card').length;
    if (sessionId !== this.sessionId) {
      this.sessionId = sessionId;
      this.close();
      this.roster = null;
      this.checked.clear();
      this.status = '';
      this.error = false;
    }
    this.trigger.textContent = this.cardCount > 0
      ? this.text('cards:seat:summary', 'Antwortkarten: {$a} ohne Gerät', {a: this.cardCount})
      : this.text('cards:seat:open', 'Antwortkarten ohne Gerät');
    return this.trigger;
  }

  /** Leaving the lobby takes the dialog (and the class list) with it. */
  public destroy(): void {
    this.close();
  }

  private text(key: string, fallback: string, values: Record<string, string | number> = {}): string {
    return this.context.text(key, fallback, values);
  }

  private open(): void {
    if (this.dialog !== null) {
      return;
    }
    const titleId = `quizgeist-cardseat-title-${this.context.cmid}`;
    const dialog = liveElement('dialog', 'quizgeist-host-dialog quizgeist-host-cardseat', {
      'aria-modal': 'true',
      'data-live-cardseat': true,
    }) as HTMLDialogElement;
    dialog.setAttribute('aria-labelledby', titleId);
    const head = liveElement('div', 'quizgeist-host-cardseat__head');
    const close = liveButton(
      this.text('cards:seat:close', 'Schließen'),
      'quizgeist-host-button quizgeist-host-button--secondary',
      {'data-live-cardseat-close': true},
    );
    close.addEventListener('click', () => this.close());
    head.append(
      liveElement('h2', 'quizgeist-host-dialog__title', {
        id: titleId,
        text: this.text('cards:seat:title', 'Ohne Geräte mitspielen: Antwortkarten'),
      }),
      close,
    );
    const body = liveElement('div', 'quizgeist-host-cardseat__body');
    dialog.append(head, body);
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      this.close();
    });
    this.dialog = dialog;
    this.body = body;
    this.context.host.append(dialog);
    try {
      dialog.showModal();
    } catch (_error) {
      dialog.setAttribute('open', '');
    }
    // Each opening reads the list afresh: somebody may have joined from a
    // device since the last look.
    this.roster = null;
    this.status = '';
    this.error = false;
    void this.load();
  }

  private close(): void {
    const dialog = this.dialog;
    if (dialog === null) {
      return;
    }
    this.dialog = null;
    this.body = null;
    if (dialog.open) {
      dialog.close();
    }
    dialog.remove();
    if (this.trigger.isConnected) {
      this.trigger.focus();
    }
  }

  private async load(): Promise<void> {
    if (this.sessionId <= 0 || this.cardSetId <= 0) {
      this.render();
      return;
    }
    this.busy = true;
    this.status = this.text('cards:seat:loading', 'Die Namensliste wird geladen …');
    this.error = false;
    this.render();
    try {
      const result = await this.context.api.post<{roster?: unknown}>('cardset_roster', {
        cardSetId: this.cardSetId,
        sessionId: this.sessionId,
      });
      this.applyRoster(normaliseRoster(result.roster), true);
      this.status = '';
    } catch (error: unknown) {
      this.roster = null;
      this.error = true;
      this.status = error instanceof Error && error.message !== ''
        ? error.message
        : this.text('cards:seat:loaderror', 'Die Namensliste konnte nicht geladen werden.');
    } finally {
      this.busy = false;
      this.render();
    }
  }

  /**
   * Take a roster from the server. While nobody of the set is seated, every
   * available holder starts ticked — the teacher only unticks the absent.
   * Afterwards the ticks mirror what the server holds.
   */
  private applyRoster(roster: Roster | null, initial: boolean): void {
    this.roster = roster;
    this.checked.clear();
    if (roster === null) {
      return;
    }
    const anySeated = roster.holders.some((holder) => holder.state === 'card');
    roster.holders.forEach((holder) => {
      if (holder.state === 'device' || holder.state === 'card'
          || (initial && !anySeated && holder.available)) {
        this.checked.add(holder.userId);
      }
    });
  }

  private async seat(): Promise<void> {
    const roster = this.roster;
    if (roster === null || this.busy) {
      return;
    }
    this.busy = true;
    this.render();
    const userIds = roster.holders
      .filter((holder) => holder.available && holder.state !== 'device' && this.checked.has(holder.userId))
      .map((holder) => holder.userId);
    try {
      const result = await this.context.api.post<SeatResult>('cardset_seat', {
        cardSetId: roster.cardSetId,
        sessionId: this.sessionId,
        userIds,
      });
      const names = new Map(roster.holders.map((holder) => [holder.userId, holder.name]));
      this.applyRoster(normaliseRoster(result.roster), false);
      const lines = [this.text('cards:seat:done', '{$a->seated} aufgenommen, {$a->removed} wieder entfernt.', {
        removed: Number(result.removed) || 0,
        seated: Number(result.seated) || 0,
      })];
      (Array.isArray(result.refused) ? result.refused : []).forEach((refusal) => {
        const name = names.get(Number(refusal.userId)) || '';
        lines.push(refusal.code === 'not_enrolled'
          ? this.text('cards:seat:refused:notenrolled', '{$a} ist nicht mehr in diesem Kurs eingeschrieben und wurde nicht aufgenommen.', {a: name})
          : this.text('cards:seat:refused:unavailable', 'Das Konto von {$a} ist gesperrt oder gelöscht und wurde nicht aufgenommen.', {a: name}));
      });
      this.status = lines.join(' ');
      this.error = false;
      this.context.onSeated();
    } catch (error: unknown) {
      this.error = true;
      this.status = error instanceof Error && error.message !== ''
        ? error.message
        : this.text('cards:seat:error', 'Die Namensliste konnte nicht gespeichert werden.');
    } finally {
      this.busy = false;
      this.render();
      this.body?.querySelector<HTMLElement>('.quizgeist-host-cardseat__status')?.focus();
    }
  }

  private renderSetPicker(): HTMLElement | null {
    if (this.context.cardSets.length < 2) {
      return null;
    }
    const id = `quizgeist-cardseat-set-${this.context.cmid}`;
    const wrapper = liveElement('div', 'quizgeist-host-cardseat__field');
    const select = liveElement('select', 'quizgeist-host-select', {id}) as HTMLSelectElement;
    this.context.cardSets.forEach((set) => {
      const option = liveElement('option', '', {text: set.name, value: String(set.id)}) as HTMLOptionElement;
      option.selected = set.id === this.cardSetId;
      select.append(option);
    });
    select.disabled = this.busy;
    select.addEventListener('change', () => {
      this.cardSetId = Number(select.value) || 0;
      this.roster = null;
      this.status = '';
      void this.load();
    });
    wrapper.append(
      liveElement('label', 'quizgeist-host-field__label', {for: id, text: this.text('cards:scan:setlabel', 'Kartensatz')}),
      select,
    );
    return wrapper;
  }

  private renderHolder(holder: RosterHolder): HTMLElement {
    const item = liveElement('li', 'quizgeist-host-cardseat__holder');
    item.dataset.holderState = holder.available ? holder.state : 'unavailable';
    const id = `quizgeist-cardseat-${this.context.cmid}-${holder.userId}`;
    const box = liveElement('input', 'quizgeist-host-cardseat__check', {id, type: 'checkbox'}) as HTMLInputElement;
    box.dataset.liveCardseatUser = String(holder.userId);
    box.checked = holder.available && this.checked.has(holder.userId);
    box.disabled = this.busy || !holder.available || holder.state === 'device';
    box.addEventListener('change', () => {
      if (box.checked) {
        this.checked.add(holder.userId);
      } else {
        this.checked.delete(holder.userId);
      }
      this.renderCount();
    });
    const label = liveElement('label', 'quizgeist-host-cardseat__label', {for: id});
    label.append(
      liveElement('span', 'quizgeist-host-cardseat__name', {text: holder.name}),
      liveElement('span', 'quizgeist-host-cardseat__code', {text: holder.cardCode}),
    );
    let note = '';
    if (!holder.available) {
      note = this.text('cards:seat:state:unavailable', 'nicht mehr in diesem Kurs');
    } else if (holder.state === 'device') {
      note = this.text('cards:seat:state:device', 'spielt mit eigenem Gerät');
    } else if (holder.state === 'card') {
      note = this.text('cards:seat:state:card', 'aufgenommen');
    }
    if (note !== '') {
      label.append(liveElement('span', 'quizgeist-host-cardseat__note', {text: note}));
    }
    item.append(box, label);
    return item;
  }

  private countText(): string {
    const holders = this.roster?.holders.filter((holder) => holder.available && holder.state !== 'device') || [];
    const ticked = holders.filter((holder) => this.checked.has(holder.userId)).length;
    return this.text('cards:seat:count', '{$a->checked} von {$a->total} angehakt', {
      checked: ticked,
      total: holders.length,
    });
  }

  private renderCount(): void {
    const count = this.body?.querySelector<HTMLElement>('[data-live-cardseat-count]');
    if (count) {
      count.textContent = this.countText();
    }
  }

  private renderList(roster: Roster): HTMLElement[] {
    const nodes: HTMLElement[] = [];
    if (roster.blocked === 'freeteams') {
      nodes.push(liveElement('p', 'quizgeist-host-cardseat__locked', {
        text: this.text(
          'cards:seat:freeteams',
          'Bei frei gewählten Teams wählt jede Person ihr Team am eigenen Gerät. Für Antwortkarten ohne Gerät lege die Session mit Moodle-Gruppen als Teams an.',
        ),
      }));
      return nodes;
    }
    const list = liveElement('ul', 'quizgeist-host-cardseat__list');
    roster.holders.forEach((holder) => list.append(this.renderHolder(holder)));
    nodes.push(list);
    if (roster.reserveCount > 0) {
      nodes.push(liveElement('p', 'quizgeist-host-cardseat__hint', {
        text: this.text('cards:seat:reserve', '{$a} Reservekarten tragen keinen Namen und zählen nicht.', {a: roster.reserveCount}),
      }));
    }
    const actions = liveElement('div', 'quizgeist-host-cardseat__actions');
    const all = liveButton(
      this.text('cards:seat:all', 'Alle anhaken'),
      'quizgeist-host-button quizgeist-host-button--secondary',
    );
    all.disabled = this.busy;
    all.addEventListener('click', () => {
      roster.holders.forEach((holder) => {
        if (holder.available) {
          this.checked.add(holder.userId);
        }
      });
      this.render();
    });
    const none = liveButton(
      this.text('cards:seat:none', 'Alle abhaken'),
      'quizgeist-host-button quizgeist-host-button--secondary',
    );
    none.disabled = this.busy;
    none.addEventListener('click', () => {
      roster.holders.forEach((holder) => {
        if (holder.state !== 'device') {
          this.checked.delete(holder.userId);
        }
      });
      this.render();
    });
    const apply = liveButton(
      this.text('cards:seat:apply', 'Angehakte aufnehmen'),
      'quizgeist-host-button quizgeist-host-button--primary',
      {'data-live-cardseat-apply': true},
    );
    apply.disabled = this.busy || !this.context.canSeat;
    apply.addEventListener('click', () => {
      void this.seat();
    });
    actions.append(
      liveElement('span', 'quizgeist-host-cardseat__count', {
        'data-live-cardseat-count': true,
        text: this.countText(),
      }),
      all,
      none,
      apply,
    );
    nodes.push(actions);
    return nodes;
  }

  private render(): void {
    const body = this.body;
    if (body === null) {
      return;
    }
    const nodes: HTMLElement[] = [
      liveElement('p', 'quizgeist-host-cardseat__intro', {
        text: this.text(
          'cards:seat:intro',
          'Wer kein eigenes Gerät hat, antwortet mit der gedruckten Karte. Hake ab, wer heute fehlt, und nimm die anderen auf. Danach kannst du auch ohne einen einzigen Gerätebeitritt starten.',
        ),
      }),
      liveElement('p', 'quizgeist-host-cardseat__hint', {
        text: this.text(
          'cards:seat:privacy',
          'Solange dieses Fenster offen ist, steht die Liste auch auf dem Beamer. Schließe es nach dem Aufnehmen.',
        ),
      }),
    ];
    if (!this.context.canSeat) {
      nodes.push(liveElement('p', 'quizgeist-host-cardseat__locked', {
        text: this.text('cards:error:scanlocked', 'Für den Karten-Modus fehlt eine gültige Lizenz des KI-Pakets.'),
      }));
    }
    if (this.context.cardSets.length === 0) {
      nodes.push(liveElement('p', 'quizgeist-host-cardseat__locked', {
        text: this.text(
          'cards:scan:noset',
          'Für diese Aktivität ist noch kein Kartensatz angelegt. Du legst ihn vor dem Spielstart im Startbildschirm unter „Antwortkarten“ an.',
        ),
      }));
    } else {
      const picker = this.renderSetPicker();
      if (picker !== null) {
        nodes.push(picker);
      }
      if (this.roster !== null) {
        nodes.push(...this.renderList(this.roster));
      } else if (!this.busy) {
        const retry = liveButton(
          this.text('cards:seat:load', 'Namensliste laden'),
          'quizgeist-host-button quizgeist-host-button--secondary',
        );
        retry.addEventListener('click', () => {
          void this.load();
        });
        nodes.push(retry);
      }
    }
    const status = liveElement('p', 'quizgeist-host-cardseat__status', {
      'aria-live': 'polite',
      role: this.error ? 'alert' : 'status',
      tabindex: -1,
      text: this.status,
    });
    status.dataset.error = this.error ? 'true' : 'false';
    status.hidden = this.status === '';
    nodes.push(status);
    body.replaceChildren(...nodes);
  }
}
