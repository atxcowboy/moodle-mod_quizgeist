import {liveElement} from '../live/dom';

/**
 * F3 Fälligkeitskarte der Lernenden.
 *
 * The card answers exactly one question: what should I repeat today? It shows
 * the learner's own state and nobody else's — the server already cuts the
 * payload down to the requesting user, so there is nothing here to hide.
 *
 * Topic colour is decoration; the label, the figure and the "next due" sentence
 * carry the meaning (DESIGN 7).
 */

export interface DueTopic {
  colorKey: string | null;
  dueCount: number;
  label: string;
  tagKey: string;
  untagged: boolean;
}

export interface DueCard {
  dueCount: number;
  generatedAt: number;
  nextDue: number | null;
  openCount: number;
  topics: DueTopic[];
  trackedCount: number;
}

type Text = (
  key: string,
  fallback: string,
  values?: Record<string, string | number>,
) => string;

const integer = (value: unknown): number => (
  typeof value === 'number' && Number.isFinite(value) && value > 0
    ? Math.floor(value)
    : 0
);

/**
 * Normalise one untrusted due-card payload.
 */
export function normaliseDueCard(raw: unknown): DueCard {
  const empty: DueCard = {
    dueCount: 0,
    generatedAt: 0,
    nextDue: null,
    openCount: 0,
    topics: [],
    trackedCount: 0,
  };
  if (raw === null || typeof raw !== 'object') {
    return empty;
  }
  const record = raw as Record<string, unknown>;
  const nextdue = integer(record.nextDue);
  return {
    dueCount: integer(record.dueCount),
    generatedAt: integer(record.generatedAt),
    nextDue: nextdue > 0 ? nextdue : null,
    openCount: integer(record.openCount),
    topics: Array.isArray(record.topics)
      ? record.topics
        .map(normaliseDueTopic)
        .filter((entry): entry is DueTopic => entry !== null)
      : [],
    trackedCount: integer(record.trackedCount),
  };
}

/**
 * Normalise one untrusted topic row.
 */
function normaliseDueTopic(raw: unknown): DueTopic | null {
  if (raw === null || typeof raw !== 'object') {
    return null;
  }
  const record = raw as Record<string, unknown>;
  return {
    colorKey: typeof record.colorKey === 'string' && record.colorKey !== ''
      ? record.colorKey
      : null,
    dueCount: integer(record.dueCount),
    label: typeof record.label === 'string' ? record.label : '',
    tagKey: typeof record.tagKey === 'string' ? record.tagKey : '',
    untagged: record.untagged === true,
  };
}

/**
 * Render the learner's due card.
 */
export function renderDueCard(
  card: DueCard,
  text: Text,
  formatDate: (timestampMs: number) => string,
): HTMLElement {
  const section = liveElement('section', 'quizgeist-study-goal quizgeist-study-due');
  section.setAttribute('aria-labelledby', 'quizgeist-study-due-title');
  section.dataset.quizgeistView = 'study-due';
  const heading = liveElement('h3', 'quizgeist-study-goal__title', {
    text: text('schedule:due:title', 'Deine Wiederholungen'),
  });
  heading.id = 'quizgeist-study-due-title';
  section.append(heading);

  if (card.openCount === 0) {
    section.append(liveElement('p', 'quizgeist-study-goal__copy', {
      role: 'status',
      text: card.nextDue !== null
        ? text(
          'schedule:due:none:next',
          'Gerade ist nichts fällig. Weiter geht es am {$date}.',
          {date: formatDate(card.nextDue * 1000)},
        )
        : text(
          'schedule:due:none',
          'Gerade ist nichts fällig. Sobald du übst, plant Quizgeist die nächste Wiederholung.',
        ),
    }));
    return section;
  }

  section.append(liveElement('p', 'quizgeist-study-goal__copy', {
    role: 'status',
    text: text(
      'schedule:due:summary',
      '{$count} Fragen warten auf eine Wiederholung.',
      {count: card.openCount},
    ),
  }));

  if (card.topics.length > 0) {
    const list = liveElement('ul', 'quizgeist-study-due-topics');
    card.topics.forEach((topic) => {
      const item = liveElement('li', 'quizgeist-study-due-topic');
      if (topic.colorKey !== null) {
        item.dataset.competenceColor = topic.colorKey;
      }
      item.append(
        liveElement('span', 'quizgeist-study-due-topic-label', {
          text: topic.untagged || topic.label === ''
            ? text('schedule:due:untagged', 'Ohne Thema')
            : topic.label,
        }),
        liveElement('span', 'quizgeist-study-due-topic-count', {
          text: text(
            'schedule:due:topiccount',
            '{$count} fällig',
            {count: topic.dueCount},
          ),
        }),
      );
      list.append(item);
    });
    section.append(list);
  }
  return section;
}

/**
 * Render the deliberate-topic-switch hint of an interleaved run (F4).
 *
 * Interleaving feels like a mistake unless it is announced. This one sentence
 * is the difference between "the app is jumping around" and "I am practising
 * the way that actually works".
 */
export function renderInterleavingHint(text: Text): HTMLElement {
  return liveElement('p', 'quizgeist-study-interleaving-hint', {
    role: 'note',
    text: text(
      'schedule:interleaving:hint',
      'Das Thema wechselt bewusst: Gemischtes Üben bleibt länger im Gedächtnis als Üben in Blöcken.',
    ),
  });
}
