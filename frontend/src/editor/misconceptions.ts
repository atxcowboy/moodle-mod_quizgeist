import {EditorApi} from './api';
import {button, element} from './dom';
import {editorString, type ClientStrings} from './messages';
import type {QuizgeistFeatureAvailability} from '../types';
import type {EditorQuestion} from './types';

/**
 * F5 Fehlkonzept-Radar — the editor side.
 *
 * A teacher names the misconception behind each wrong answer of a
 * single-choice or true/false question. After the reveal the beamer shows the
 * label next to that answer, and the report collects the labelled questions
 * in the radar. The labels hang on the question ROOT and on the stored answer
 * keys; the server reads both from the stored question, never from here.
 *
 * Both actions belong to the reports addon. This module asks the page's
 * capability report (`features.reports.installed`) itself before it names
 * either of them, so a base installation never sends an unknown action.
 */

const MAX_LABEL = 255;
const MAX_HINT = 1000;

interface MisconceptionAnswer {
  answerKey: string;
  correct: boolean;
  hint: string;
  label: string;
  text: string;
}

interface MisconceptionListResult {
  questions: Array<{rootId: number; answers: MisconceptionAnswer[]}>;
}

interface MisconceptionSaveResult {
  rootId: number;
  validationErrors: Array<{code: string; field: string}>;
}

interface Features {
  reports?: QuizgeistFeatureAvailability;
}

type Entry =
  | {state: 'loading'}
  | {state: 'failed'}
  | {state: 'ready'; answers: MisconceptionAnswer[]};

/** Whether this question type has a single right answer to contrast with. */
export function supportsMisconceptions(question: EditorQuestion): boolean {
  if (question.qtype === 'truefalse') {
    return true;
  }
  if (question.qtype !== 'quiz') {
    return false;
  }
  const options = question.options as {multiple?: boolean};
  return options.multiple !== true;
}

/**
 * Per-editor cache of the stored labels, keyed by the stored question version.
 *
 * Every autosave changes `timemodified`, so a changed answer list is fetched
 * again instead of being shown against stale keys.
 */
export class MisconceptionStore {
  private readonly entries = new Map<string, Entry>();
  private readonly pending = new Map<string, Promise<void>>();

  public constructor(
    private readonly api: EditorApi,
    private readonly features: Features | undefined,
  ) {
  }

  /** The reports addon reports itself installed for this page. */
  public available(): boolean {
    return this.features?.reports?.installed === true;
  }

  /** New labels are a creation; an expired licence keeps them read-only. */
  public canCreate(): boolean {
    return this.features?.reports?.installed === true
      && this.features?.reports?.canCreate !== false;
  }

  private key(question: EditorQuestion): string {
    return `${question.id}:${question.timemodified}`;
  }

  public entry(question: EditorQuestion): Entry | undefined {
    return this.entries.get(this.key(question));
  }

  /**
   * Load the stored labels once per stored version.
   *
   * Every caller gets the same promise, so a block drawn while an earlier
   * block's request is still running fills itself as well.
   */
  public load(question: EditorQuestion): Promise<void> {
    const key = this.key(question);
    const pending = this.pending.get(key);
    if (pending !== undefined) {
      return pending;
    }
    const request = this.fetch(key, question.id);
    this.pending.set(key, request);
    return request;
  }

  private async fetch(key: string, questionId: number): Promise<void> {
    if (this.features?.reports?.installed !== true) {
      return;
    }
    this.entries.set(key, {state: 'loading'});
    try {
      const result = await this.api.post<MisconceptionListResult>(
        'misconception_list',
        {questionId},
      );
      const first = Array.isArray(result.questions) ? result.questions[0] : undefined;
      this.entries.set(key, {
        state: 'ready',
        answers: first && Array.isArray(first.answers) ? first.answers : [],
      });
    } catch (_error) {
      this.entries.set(key, {state: 'failed'});
    }
  }

  public async save(
    question: EditorQuestion,
    labels: Array<{answerKey: string; hint: string; label: string}>,
  ): Promise<{code: string; ok: boolean}> {
    if (this.features?.reports?.installed !== true) {
      return {code: 'generic', ok: false};
    }
    const result = await this.api.post<MisconceptionSaveResult>(
      'misconception_save',
      {questionId: question.id, labels},
    );
    const errors = Array.isArray(result.validationErrors) ? result.validationErrors : [];
    if (errors.length) {
      return {code: errors[0].code, ok: false};
    }
    const entry = this.entries.get(this.key(question));
    if (entry && entry.state === 'ready') {
      const byKey = new Map(labels.map((item) => [item.answerKey, item]));
      entry.answers = entry.answers.map((answer) => ({
        ...answer,
        hint: byKey.get(answer.answerKey)?.hint || '',
        label: byKey.get(answer.answerKey)?.label || '',
      }));
    }
    return {code: '', ok: true};
  }
}

/**
 * Render the misconception block of one question, or nothing at all.
 *
 * Nothing appears for a question type without a single right answer, for a
 * base installation without the reports addon, or when the read fails (the
 * block is an addition, never a reason for an error in the editor). The block
 * fills itself in place once the stored labels arrive, so a teacher typing in
 * the question form above never loses focus to a redraw.
 */
export function renderMisconceptionEditor(
  store: MisconceptionStore,
  strings: ClientStrings,
  question: EditorQuestion,
  dirty: boolean,
  onMessage: (message: string, ok: boolean) => void,
): HTMLElement | null {
  if (!store.available() || !supportsMisconceptions(question)) {
    return null;
  }
  const t = (key: string, fallback: string): string => editorString(strings, key, fallback);
  const block = element('fieldset', 'quizgeist-editor-misconceptions');
  block.dataset.editorMisconceptions = '';

  const fill = (): void => {
    const entry = store.entry(question);
    if (entry !== undefined && entry.state === 'failed') {
      block.remove();
      return;
    }
    const nodes: HTMLElement[] = [
      element('legend', 'quizgeist-editor-tags__legend', {
        text: t('misconception:editor:title', 'Fehlvorstellungen'),
      }),
      element('p', 'quizgeist-field__hint', {
        text: t(
          'misconception:editor:intro',
          'Welche Fehlvorstellung steckt hinter einer falschen Antwort?',
        ),
      }),
    ];
    if (entry === undefined || entry.state !== 'ready') {
      nodes.push(element('p', 'quizgeist-field__hint', {
        role: 'status',
        text: t('misconception:editor:loading', 'Fehlvorstellungen werden geladen …'),
      }));
      block.replaceChildren(...nodes);
      return;
    }
    if (dirty) {
      nodes.push(element('p', 'quizgeist-editor-misconceptions__notice', {
        text: t(
          'misconception:editor:unsaved',
          'Die Etiketten gelten für die zuletzt gespeicherten Antworten.',
        ),
      }));
    }
    const editable = store.canCreate();
    if (!editable) {
      nodes.push(element('p', 'quizgeist-editor-misconceptions__notice', {
        text: t(
          'misconception:editor:locked',
          'Neue Etiketten brauchen eine gültige Lizenz des Berichtspakets.',
        ),
      }));
    }

    const rows: Array<{answerKey: string; hint: HTMLTextAreaElement; label: HTMLInputElement}> = [];
    const list = element('div', 'quizgeist-editor-misconceptions__list');
    entry.answers.forEach((answer, index) => {
      if (answer.correct) {
        return;
      }
      const text = question.qtype === 'truefalse'
        ? (answer.answerKey === 'true'
          ? t('misconception:editor:true', 'Wahr')
          : t('misconception:editor:false', 'Falsch'))
        : (answer.text.trim() || t('misconception:editor:unnamed', 'Antwort ohne Text'));
      const labelId = `quizgeist-misconception-${question.id}-${index}`;
      const hintId = `${labelId}-hint`;
      const row = element('div', 'quizgeist-editor-misconceptions__row');
      row.dataset.answerKey = answer.answerKey;
      const label = element('input', 'quizgeist-input', {
        disabled: !editable,
        id: labelId,
        maxlength: MAX_LABEL,
        type: 'text',
        value: answer.label,
      });
      const hint = element('textarea', 'quizgeist-input quizgeist-editor-misconceptions__hint', {
        disabled: !editable,
        id: hintId,
        maxlength: MAX_HINT,
        rows: 2,
      });
      hint.value = answer.hint;
      row.append(
        element('p', 'quizgeist-editor-misconceptions__answer', {
          text: `${t('misconception:editor:answer', 'Falsche Antwort')}: ${text}`,
        }),
        element('label', 'quizgeist-field__label', {
          for: labelId,
          text: t('misconception:editor:label', 'Fehlvorstellung'),
        }),
        label,
        element('label', 'quizgeist-field__label', {
          for: hintId,
          text: t('misconception:editor:hint', 'Notiz für den Bericht (optional)'),
        }),
        hint,
      );
      list.append(row);
      rows.push({answerKey: answer.answerKey, hint, label});
    });
    if (!rows.length) {
      block.remove();
      return;
    }
    nodes.push(list);

    if (editable) {
      const failure = t(
        'misconception:editor:error',
        'Die Fehlvorstellungen konnten nicht gespeichert werden.',
      );
      const save = button(
        t('misconception:editor:save', 'Fehlvorstellungen speichern'),
        'quizgeist-button quizgeist-button--secondary',
      );
      save.dataset.action = 'save-misconceptions';
      save.addEventListener('click', () => {
        const missing = rows.find((row) => (
          row.label.value.trim() === '' && row.hint.value.trim() !== ''
        ));
        if (missing) {
          onMessage(t(
            'misconception:editor:error:required',
            'Zu einer Notiz gehört auch eine Fehlvorstellung.',
          ), false);
          missing.label.focus();
          return;
        }
        const labels = rows
          .filter((row) => row.label.value.trim() !== '')
          .map((row) => ({
            answerKey: row.answerKey,
            hint: row.hint.value.trim().slice(0, MAX_HINT),
            label: row.label.value.trim().slice(0, MAX_LABEL),
          }));
        save.disabled = true;
        void store.save(question, labels)
          .then((result) => {
            onMessage(
              result.ok
                ? t('misconception:editor:saved', 'Die Fehlvorstellungen sind gespeichert.')
                : failure,
              result.ok,
            );
          })
          .catch(() => onMessage(failure, false))
          .finally(() => {
            save.disabled = false;
          });
      });
      nodes.push(save);
    }
    block.replaceChildren(...nodes);
  };

  const loading = store.load(question);
  fill();
  void loading.then(() => {
    if (block.isConnected) {
      fill();
    }
  });
  return block;
}
