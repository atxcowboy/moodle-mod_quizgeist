import {liveElement} from '../live/dom';
import {
  hingeSentence,
  normaliseHingeStatus,
  type HingeStatus,
} from '../host/misconception-panel';

/**
 * F5 Fehlkonzept-Radar — the report side.
 *
 * One block per question root that carries labels: the answer options with
 * their share, the label a teacher attached to a distractor, and the traffic
 * light with the threshold it was measured against.
 *
 * The panel renders nothing at all when the server sent nothing. Without the
 * reports addon the action does not exist, and an empty headline would be a
 * locked door with a sign on it.
 *
 * DESIGN 7: colour is never the only carrier. Every row states its label, its
 * percentage in figures and whether it is the correct option in words.
 */

export interface MisconceptionAnswer {
  answerKey: string;
  correct: boolean;
  count: number;
  hint: string;
  label: string;
  percent: number;
  text: string;
}

export interface MisconceptionQuestion {
  answers: MisconceptionAnswer[];
  correctPercent: number;
  hingeStatus: HingeStatus | null;
  qtype: string;
  rootId: number;
  sample: number;
  threshold: number;
  title: string;
}

export interface MisconceptionRadar {
  minSample: number;
  questions: MisconceptionQuestion[];
  siteThreshold: number;
}

type Text = (
  key: string,
  fallback: string,
  values?: Record<string, string | number>,
) => string;

const count = (value: unknown): number => (
  typeof value === 'number' && Number.isFinite(value) && value > 0
    ? Math.floor(value)
    : 0
);

const percent = (value: unknown): number => (
  typeof value === 'number' && Number.isFinite(value)
    ? Math.max(0, Math.min(100, value))
    : 0
);

const plain = (value: unknown): string => (
  typeof value === 'string' ? value : ''
);

/**
 * Normalise one untrusted radar payload.
 */
export function normaliseRadar(raw: unknown): MisconceptionRadar {
  const empty: MisconceptionRadar = {
    minSample: 0,
    questions: [],
    siteThreshold: 0,
  };
  if (raw === null || typeof raw !== 'object') {
    return empty;
  }
  const record = raw as Record<string, unknown>;
  return {
    minSample: count(record.minSample),
    questions: Array.isArray(record.questions)
      ? record.questions
        .map(normaliseRadarQuestion)
        .filter((entry): entry is MisconceptionQuestion => entry !== null)
      : [],
    siteThreshold: count(record.siteThreshold),
  };
}

/**
 * Normalise one untrusted question row.
 */
function normaliseRadarQuestion(raw: unknown): MisconceptionQuestion | null {
  if (raw === null || typeof raw !== 'object') {
    return null;
  }
  const record = raw as Record<string, unknown>;
  const rootId = count(record.rootId);
  if (rootId === 0) {
    return null;
  }
  return {
    answers: Array.isArray(record.answers)
      ? record.answers
        .map(normaliseRadarAnswer)
        .filter((entry): entry is MisconceptionAnswer => entry !== null)
      : [],
    correctPercent: percent(record.correctPercent),
    hingeStatus: normaliseHingeStatus(record.hingeStatus),
    qtype: plain(record.qtype),
    rootId,
    sample: count(record.sample),
    threshold: count(record.threshold),
    title: plain(record.title),
  };
}

/**
 * Normalise one untrusted answer row.
 */
function normaliseRadarAnswer(raw: unknown): MisconceptionAnswer | null {
  if (raw === null || typeof raw !== 'object') {
    return null;
  }
  const record = raw as Record<string, unknown>;
  const answerKey = plain(record.answerKey);
  if (answerKey === '') {
    return null;
  }
  return {
    answerKey,
    correct: record.correct === true,
    count: count(record.count),
    hint: plain(record.hint),
    label: plain(record.label),
    percent: percent(record.percent),
    text: plain(record.text),
  };
}

/**
 * Render the radar section, or nothing when there is nothing to show.
 */
export function renderMisconceptionPanel(
  radar: MisconceptionRadar,
  text: Text,
): HTMLElement | null {
  if (radar.questions.length === 0) {
    return null;
  }
  const section = liveElement('section', 'quizgeist-report-section', {
    'data-quizgeist-view': 'report-misconception',
  });
  section.setAttribute('aria-labelledby', 'quizgeist-report-misconceptions');
  const heading = liveElement('h3', 'quizgeist-report-section__title', {
    text: text('report:misconception:title', 'Fehlkonzept-Radar'),
  });
  heading.id = 'quizgeist-report-misconceptions';
  const hint = liveElement('p', 'quizgeist-report-filter-hint', {
    text: text(
      'report:misconception:hint',
      'Die Ampel vergleicht den richtigen Anteil mit der eingestellten Schwelle. Sie ist ein Gespräch wert, kein Urteil.',
    ),
  });
  const list = liveElement('ul', 'quizgeist-misconception-list');
  radar.questions.forEach((question) => {
    list.append(renderQuestion(question, text));
  });
  section.append(heading, hint, list);
  return section;
}

/**
 * Render one question block.
 */
function renderQuestion(
  question: MisconceptionQuestion,
  text: Text,
): HTMLElement {
  const item = liveElement('li', 'quizgeist-misconception-item');
  item.append(liveElement('p', 'quizgeist-misconception-question', {
    text: question.title,
  }));
  item.append(liveElement('p', 'quizgeist-misconception-figure', {
    text: text(
      'report:misconception:figure',
      '{$correct} % richtig aus {$sample} Antworten · Schwelle {$threshold} %',
      {
        correct: String(question.correctPercent),
        sample: String(question.sample),
        threshold: String(question.threshold),
      },
    ),
  }));
  if (question.hingeStatus !== null) {
    const badge = liveElement('p', 'quizgeist-hinge-badge', {
      'data-hinge-status': question.hingeStatus,
      role: 'status',
    });
    badge.append(
      liveElement('span', 'quizgeist-hinge-badge__mark', {
        'aria-hidden': 'true',
        text: question.hingeStatus === 'move_on'
          ? '▲'
          : (question.hingeStatus === 'reteach' ? '■' : '·'),
      }),
      liveElement('span', 'quizgeist-hinge-badge__text', {
        text: hingeSentence(question.hingeStatus, text),
      }),
    );
    item.append(badge);
  }
  const answers = liveElement('ul', 'quizgeist-misconception-answers');
  question.answers.forEach((answer) => {
    answers.append(renderAnswer(answer, question.qtype, text));
  });
  item.append(answers);
  return item;
}

/**
 * Render one answer row of the radar.
 */
function renderAnswer(
  answer: MisconceptionAnswer,
  qtype: string,
  text: Text,
): HTMLElement {
  const row = liveElement('li', 'quizgeist-misconception-answer', {
    'data-misconception-correct': answer.correct ? 'true' : 'false',
  });
  row.append(liveElement('span', 'quizgeist-misconception-answer__text', {
    text: answerLabel(answer, qtype, text),
  }));
  row.append(liveElement('span', 'quizgeist-misconception-answer__figure', {
    text: text(
      'report:misconception:share',
      '{$percent} % ({$count})',
      {count: String(answer.count), percent: String(answer.percent)},
    ),
  }));
  row.append(liveElement('span', 'quizgeist-misconception-answer__role', {
    text: answer.correct
      ? text('report:misconception:correct', 'richtige Antwort')
      : text('report:misconception:distractor', 'Distraktor'),
  }));
  const bar = liveElement('span', 'quizgeist-misconception-bar', {
    role: 'img',
    'aria-label': text('report:misconception:baralt', 'Anteil dieser Antwort'),
  });
  const fill = liveElement('span', 'quizgeist-misconception-fill', {
    'data-misconception-state': answer.correct ? 'correct' : 'distractor',
  });
  fill.style.width = `${answer.percent}%`;
  bar.append(fill);
  row.append(bar);
  if (answer.label !== '') {
    row.append(liveElement('span', 'quizgeist-misconception-label__text', {
      'data-misconception-label': true,
      text: answer.label,
    }));
  }
  if (answer.hint !== '') {
    row.append(liveElement('span', 'quizgeist-misconception-answer__hint', {
      text: answer.hint,
    }));
  }
  return row;
}

/**
 * Readable name of one answer option.
 *
 * A true/false question stores the machine keys `true` and `false`; F16 says
 * a machine key never reaches the DOM, so they get their own sentence here.
 */
function answerLabel(
  answer: MisconceptionAnswer,
  qtype: string,
  text: Text,
): string {
  if (qtype === 'truefalse') {
    return answer.answerKey === 'true'
      ? text('report:misconception:true', 'Wahr')
      : text('report:misconception:false', 'Falsch');
  }
  return answer.text !== ''
    ? answer.text
    : text('report:misconception:unnamed', 'Antwort ohne Text');
}
