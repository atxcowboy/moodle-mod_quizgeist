import {liveButton, liveElement} from '../live/dom';

/**
 * F7 Fragenwerkstatt — the learner's submission form.
 *
 * This file also owns the shared DTO of the workshop, because all three
 * surfaces read the very same payload of `workshop_list`; a fourth file for
 * six interfaces would only add a door to walk through.
 *
 * Two rules are visible in the markup:
 *
 * 1. The explanation is a required field of the SERVER
 *    (workshop_schema::normalise()). The form marks it required and shows the
 *    server objection verbatim — it never decides on its own that a
 *    submission is fine.
 * 2. Objections arrive as {field, code} and are turned into a sentence here
 *    (F16). No lookup in this module ever falls back to the raw lookup key.
 */

export type WorkshopState = 'submitted' | 'revising' | 'approved' | 'rejected';

export interface WorkshopRating {
  comment: string;
  difficulty: number;
  quality: number;
}

export interface WorkshopSubmission {
  aiCheck: WorkshopAiCheck | null;
  authorName: string;
  averageDifficulty: number | null;
  averageQuality: number | null;
  curatorNote: string;
  explanation: string;
  id: number;
  ownRating: WorkshopRating | null;
  qtype: string;
  questionId: number;
  questionStatus: string;
  questionText: string;
  ratingCount: number;
  rootId: number;
  state: WorkshopState;
  timeDecided: number;
  timeSubmitted: number;
}

export interface WorkshopAiCheckItem {
  key: string;
  note: string;
  verdict: string;
}

export interface WorkshopAiCheck {
  checks: WorkshopAiCheckItem[];
  origin: string;
}

export interface WorkshopView {
  canCurate: boolean;
  mine: WorkshopSubmission[];
  openRatings: number;
  peers: WorkshopSubmission[];
  queue: WorkshopSubmission[];
  ratingRange: {max: number; min: number};
}

export interface WorkshopFieldError {
  code: string;
  field: string;
}

export type WorkshopText = (
  key: string,
  fallback: string,
  values?: Record<string, string | number>,
) => string;

export interface WorkshopSubmitDraft {
  answers: Array<{correct: boolean; text: string}>;
  explanation: string;
  questiontext: string;
}

const STATES: readonly WorkshopState[] = [
  'submitted',
  'revising',
  'approved',
  'rejected',
];

const text = (value: unknown): string => (
  typeof value === 'string' ? value : ''
);

const count = (value: unknown): number => (
  typeof value === 'number' && Number.isFinite(value) && value > 0
    ? Math.floor(value)
    : 0
);

/**
 * Normalise one untrusted workshop payload.
 */
export function normaliseWorkshopView(raw: unknown): WorkshopView {
  const empty: WorkshopView = {
    canCurate: false,
    mine: [],
    openRatings: 0,
    peers: [],
    queue: [],
    ratingRange: {max: 5, min: 1},
  };
  if (raw === null || typeof raw !== 'object') {
    return empty;
  }
  const record = raw as Record<string, unknown>;
  const range = record.ratingRange !== null && typeof record.ratingRange === 'object'
    ? record.ratingRange as Record<string, unknown>
    : {};
  return {
    canCurate: record.canCurate === true,
    mine: normaliseSubmissions(record.mine),
    openRatings: count(record.openRatings),
    peers: normaliseSubmissions(record.peers),
    queue: normaliseSubmissions(record.queue),
    ratingRange: {
      max: count(range.max) || 5,
      min: count(range.min) || 1,
    },
  };
}

/**
 * Normalise a list of untrusted submissions.
 */
export function normaliseSubmissions(raw: unknown): WorkshopSubmission[] {
  return Array.isArray(raw)
    ? raw
      .map(normaliseSubmission)
      .filter((entry): entry is WorkshopSubmission => entry !== null)
    : [];
}

/**
 * Normalise one untrusted submission.
 */
function normaliseSubmission(raw: unknown): WorkshopSubmission | null {
  if (raw === null || typeof raw !== 'object') {
    return null;
  }
  const record = raw as Record<string, unknown>;
  const id = count(record.id);
  const state = text(record.state);
  if (id === 0 || !(STATES as readonly string[]).includes(state)) {
    return null;
  }
  const average = (value: unknown): number | null => (
    typeof value === 'number' && Number.isFinite(value) ? value : null
  );
  const own = record.ownRating !== null && typeof record.ownRating === 'object'
    ? record.ownRating as Record<string, unknown>
    : null;
  return {
    aiCheck: normaliseAiCheck(record.aiCheck),
    authorName: text(record.authorName),
    averageDifficulty: average(record.averageDifficulty),
    averageQuality: average(record.averageQuality),
    curatorNote: text(record.curatorNote),
    explanation: text(record.explanation),
    id,
    ownRating: own === null ? null : {
      comment: text(own.comment),
      difficulty: count(own.difficulty),
      quality: count(own.quality),
    },
    qtype: text(record.qtype),
    questionId: count(record.questionId),
    questionStatus: text(record.questionStatus),
    questionText: text(record.questionText),
    ratingCount: count(record.ratingCount),
    rootId: count(record.rootId),
    state: state as WorkshopState,
    timeDecided: count(record.timeDecided),
    timeSubmitted: count(record.timeSubmitted),
  };
}

/**
 * Normalise the advisory AI pre-check.
 */
function normaliseAiCheck(raw: unknown): WorkshopAiCheck | null {
  if (raw === null || typeof raw !== 'object') {
    return null;
  }
  const record = raw as Record<string, unknown>;
  const checks = Array.isArray(record.checks)
    ? record.checks.flatMap((entry): WorkshopAiCheckItem[] => {
      if (entry === null || typeof entry !== 'object') {
        return [];
      }
      const item = entry as Record<string, unknown>;
      const key = text(item.key);
      return key === '' ? [] : [{
        key,
        note: text(item.note),
        verdict: text(item.verdict) === 'ok' ? 'ok' : 'attention',
      }];
    })
    : [];
  return checks.length === 0 ? null : {checks, origin: text(record.origin)};
}

/**
 * Turn one server objection into a readable sentence.
 *
 * F16: every code has its own sentence. An unknown code produces the general
 * sentence, never the code itself.
 */
export function workshopErrorMessage(
  error: WorkshopFieldError,
  t: WorkshopText,
): string {
  if (error.field === 'explanation' && error.code === 'required') {
    return t(
      'workshop:error:explanation:required',
      'Schreibe dazu, warum die Lösung richtig ist. Genau das ist der Teil, an dem man lernt.',
    );
  }
  if (error.field === 'explanation' && error.code === 'too_short') {
    return t(
      'workshop:error:explanation:tooshort',
      'Deine Erklärung ist noch sehr kurz — ein oder zwei Sätze mehr helfen den anderen.',
    );
  }
  if (error.field === 'questiontext') {
    return t('workshop:error:questiontext', 'Die Frage selbst fehlt noch.');
  }
  if (error.field.startsWith('options.answers')) {
    return t(
      'workshop:error:answers',
      'Jede Antwortmöglichkeit braucht einen Text, und genau eine davon muss richtig sein.',
    );
  }
  if (error.code === 'not_submittable') {
    return t(
      'workshop:error:qtype',
      'Dieser Fragetyp lässt sich in der Werkstatt nicht einreichen.',
    );
  }
  if (error.code === 'self_rating') {
    return t(
      'workshop:error:selfrating',
      'Die eigene Frage bewertest du nicht — das übernehmen die anderen.',
    );
  }
  if (error.code === 'requires_manage') {
    return t(
      'workshop:error:requiresmanage',
      'Zum Freigeben fehlt die Berechtigung, Fragen dieser Aktivität zu bearbeiten.',
    );
  }
  if (error.field === 'note' && error.code === 'required') {
    return t(
      'workshop:error:note:required',
      'Schreibe dazu, was überarbeitet werden soll.',
    );
  }
  return t(
    'workshop:error:generic',
    'Die Eingabe wurde nicht angenommen. Bitte sieh sie noch einmal durch.',
  );
}

/**
 * Render the submission form.
 */
export function renderStudentForm(
  t: WorkshopText,
  onSubmit: (draft: WorkshopSubmitDraft) => void,
): HTMLElement {
  const section = liveElement('section', 'quizgeist-workshop-form', {
    'data-quizgeist-view': 'workshop-form',
    'aria-labelledby': 'quizgeist-workshop-form-title',
  });
  const heading = liveElement('h3', 'quizgeist-workshop-form__title', {
    text: t('workshop:form:title', 'Eigene Frage einreichen'),
  });
  heading.id = 'quizgeist-workshop-form-title';
  section.append(
    heading,
    liveElement('p', 'quizgeist-workshop-form__copy', {
      text: t(
        'workshop:form:copy',
        'Schreibe eine Frage, markiere die richtige Antwort und erkläre, warum sie richtig ist. Deine Lehrkraft gibt sie danach frei.',
      ),
    }),
  );

  const errors = liveElement('div', 'quizgeist-workshop-errors', {
    role: 'alert',
  });
  const questionField = liveElement('label', 'quizgeist-workshop-field');
  questionField.append(
    liveElement('span', 'quizgeist-workshop-field__label', {
      text: t('workshop:form:question', 'Deine Frage'),
    }),
  );
  const questionInput = liveElement('textarea', 'quizgeist-workshop-input', {
    rows: 3,
    maxlength: 400,
    required: true,
  });
  questionField.append(questionInput);

  const answersField = liveElement('fieldset', 'quizgeist-workshop-answers');
  answersField.append(liveElement('legend', 'quizgeist-workshop-field__label', {
    text: t('workshop:form:answers', 'Antwortmöglichkeiten'),
  }));
  const answerInputs: HTMLInputElement[] = [];
  const correctInputs: HTMLInputElement[] = [];
  ['a', 'b', 'c', 'd'].forEach((key, index) => {
    const row = liveElement('div', 'quizgeist-workshop-answer');
    const correct = liveElement('input', 'quizgeist-workshop-correct', {
      type: 'radio',
      name: 'quizgeist-workshop-correct',
      value: key,
      checked: index === 0,
      'aria-label': t(
        'workshop:form:correct',
        'Antwort {$index} ist richtig',
        {index: index + 1},
      ),
    }) as HTMLInputElement;
    const answer = liveElement('input', 'quizgeist-workshop-input', {
      type: 'text',
      maxlength: 255,
      'aria-label': t(
        'workshop:form:answer',
        'Antwort {$index}',
        {index: index + 1},
      ),
    }) as HTMLInputElement;
    row.append(correct, answer);
    answersField.append(row);
    answerInputs.push(answer);
    correctInputs.push(correct);
  });

  const explanationField = liveElement('label', 'quizgeist-workshop-field');
  explanationField.append(
    liveElement('span', 'quizgeist-workshop-field__label', {
      text: t('workshop:form:explanation', 'Warum ist das richtig? (Pflicht)'),
    }),
  );
  const explanationInput = liveElement('textarea', 'quizgeist-workshop-input', {
    rows: 4,
    maxlength: 2000,
    required: true,
    'aria-describedby': 'quizgeist-workshop-explanation-hint',
  });
  const explanationHint = liveElement('span', 'quizgeist-workshop-field__hint', {
    text: t(
      'workshop:form:explanation:hint',
      'Ohne Erklärung wird die Frage nicht angenommen.',
    ),
  });
  explanationHint.id = 'quizgeist-workshop-explanation-hint';
  explanationField.append(explanationInput, explanationHint);

  const submit = liveButton(
    t('workshop:form:submit', 'Frage einreichen'),
    'quizgeist-button quizgeist-button--primary quizgeist-workshop-submit',
    {'data-workshop-action': 'submit'},
  );
  submit.addEventListener('click', () => {
    onSubmit({
      answers: answerInputs.map((input, index) => ({
        correct: correctInputs[index]?.checked === true,
        text: input.value,
      })),
      explanation: explanationInput.value,
      questiontext: questionInput.value,
    });
  });

  section.append(errors, questionField, answersField, explanationField, submit);
  return section;
}

/**
 * Show the server objections of the last submission attempt.
 */
export function showFormErrors(
  form: HTMLElement,
  fieldErrors: WorkshopFieldError[],
  t: WorkshopText,
): void {
  const region = form.querySelector<HTMLElement>('.quizgeist-workshop-errors');
  if (!region) {
    return;
  }
  region.replaceChildren();
  if (fieldErrors.length === 0) {
    return;
  }
  const list = liveElement('ul', 'quizgeist-workshop-errors__list');
  const seen = new Set<string>();
  fieldErrors.forEach((error) => {
    const message = workshopErrorMessage(error, t);
    if (seen.has(message)) {
      return;
    }
    seen.add(message);
    list.append(liveElement('li', 'quizgeist-workshop-errors__item', {
      text: message,
    }));
  });
  region.append(list);
}

/**
 * Read field objections out of an untrusted server answer.
 */
export function readFieldErrors(raw: unknown): WorkshopFieldError[] {
  if (raw === null || typeof raw !== 'object') {
    return [];
  }
  const list = (raw as Record<string, unknown>).validationErrors;
  if (!Array.isArray(list)) {
    return [];
  }
  return list.flatMap((entry): WorkshopFieldError[] => {
    if (entry === null || typeof entry !== 'object') {
      return [];
    }
    const record = entry as Record<string, unknown>;
    const field = text(record.field);
    const code = text(record.code);
    return field === '' || code === '' ? [] : [{code, field}];
  });
}

/**
 * Build the canonical question payload out of the form draft.
 *
 * The client sends content, never lifecycle: there is no `status` field here,
 * and the server would ignore one anyway (peer_service::submit()).
 */
export function submitPayload(draft: WorkshopSubmitDraft): Record<string, unknown> {
  const keys = ['a', 'b', 'c', 'd'];
  const answers = draft.answers
    .map((answer, index) => ({
      correct: answer.correct,
      id: keys[index] || `x${index}`,
      media: null,
      text: answer.text.trim(),
    }))
    .filter((answer, index) => answer.text !== '' || index < 2);
  return {
    question: {
      explanation: draft.explanation.trim(),
      options: {
        answers,
        media: null,
        multiple: false,
      },
      pointmode: 'standard',
      qtype: 'quiz',
      questiontext: draft.questiontext.trim(),
      timelimit: 20,
    },
  };
}

/**
 * Readable sentence of one submission state (F16).
 */
export function stateSentence(state: WorkshopState, t: WorkshopText): string {
  if (state === 'approved') {
    return t('workshop:state:approved', 'Freigegeben — deine Frage ist im Spiel.');
  }
  if (state === 'rejected') {
    return t('workshop:state:rejected', 'Nicht übernommen.');
  }
  if (state === 'revising') {
    return t('workshop:state:revising', 'Zurück zur Überarbeitung.');
  }
  return t('workshop:state:submitted', 'Eingereicht — wartet auf die Lehrkraft.');
}
