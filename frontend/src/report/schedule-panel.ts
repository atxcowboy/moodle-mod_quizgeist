import {liveElement} from '../live/dom';

/**
 * F3 Lehrer-Dashboard „fällige Themen".
 *
 * Grouped along the tag axis of U1, coloured with `quizgeist_tags.colorkey` —
 * and never coloured only. Every group states its label, its due count and its
 * share in figures; the group without a topic says so in words rather than
 * appearing as an unnamed grey block (DESIGN 7).
 */

export interface ScheduleGroup {
  colorKey: string | null;
  dueCount: number;
  duePercent: number;
  label: string;
  learnerCount: number;
  tagKey: string;
  trackedCount: number;
  untagged: boolean;
}

export interface ScheduleOverview {
  generatedAt: number;
  groups: ScheduleGroup[];
  totals: {
    dueCount: number;
    learnerCount: number;
    trackedCount: number;
  };
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
 * Normalise one untrusted schedule overview payload.
 */
export function normaliseScheduleOverview(raw: unknown): ScheduleOverview {
  const empty: ScheduleOverview = {
    generatedAt: 0,
    groups: [],
    totals: {dueCount: 0, learnerCount: 0, trackedCount: 0},
  };
  if (raw === null || typeof raw !== 'object') {
    return empty;
  }
  const record = raw as Record<string, unknown>;
  const totals = record.totals !== null && typeof record.totals === 'object'
    ? record.totals as Record<string, unknown>
    : {};
  return {
    generatedAt: integer(record.generatedAt),
    groups: Array.isArray(record.groups)
      ? record.groups
        .map(normaliseScheduleGroup)
        .filter((entry): entry is ScheduleGroup => entry !== null)
      : [],
    totals: {
      dueCount: integer(totals.dueCount),
      learnerCount: integer(totals.learnerCount),
      trackedCount: integer(totals.trackedCount),
    },
  };
}

/**
 * Normalise one untrusted group row.
 */
function normaliseScheduleGroup(raw: unknown): ScheduleGroup | null {
  if (raw === null || typeof raw !== 'object') {
    return null;
  }
  const record = raw as Record<string, unknown>;
  return {
    colorKey: typeof record.colorKey === 'string' && record.colorKey !== ''
      ? record.colorKey
      : null,
    dueCount: integer(record.dueCount),
    duePercent: Math.max(0, Math.min(100, integer(record.duePercent))),
    label: typeof record.label === 'string' ? record.label : '',
    learnerCount: integer(record.learnerCount),
    tagKey: typeof record.tagKey === 'string' ? record.tagKey : '',
    trackedCount: integer(record.trackedCount),
    untagged: record.untagged === true,
  };
}

/**
 * Render the teacher's due-topics panel.
 */
export function renderSchedulePanel(
  overview: ScheduleOverview,
  text: Text,
): HTMLElement {
  const section = liveElement('section', 'quizgeist-report-section');
  section.setAttribute('aria-labelledby', 'quizgeist-report-schedule');
  section.dataset.quizgeistView = 'report-schedule';
  const heading = liveElement('h3', 'quizgeist-report-section__title', {
    text: text('schedule:overview:title', 'Fällige Themen'),
  });
  heading.id = 'quizgeist-report-schedule';
  section.append(heading);

  if (overview.groups.length === 0 || overview.totals.trackedCount === 0) {
    section.append(liveElement('p', 'quizgeist-report-inline-empty', {
      role: 'status',
      text: text(
        'schedule:overview:empty',
        'Noch niemand hat geübt — sobald Antworten eintreffen, entsteht hier der Wiederholungsplan.',
      ),
    }));
    return section;
  }

  section.append(liveElement('p', 'quizgeist-report-filter-hint', {
    text: text(
      'schedule:overview:summary',
      '{$due} fällige Wiederholungen bei {$learners} Lernenden, {$tracked} beobachtete Fragen.',
      {
        due: overview.totals.dueCount,
        learners: overview.totals.learnerCount,
        tracked: overview.totals.trackedCount,
      },
    ),
  }));

  const list = liveElement('ul', 'quizgeist-schedule-list');
  overview.groups.forEach((group) => {
    list.append(renderScheduleGroup(group, text));
  });
  section.append(list);
  return section;
}

/**
 * Render one topic group.
 */
function renderScheduleGroup(group: ScheduleGroup, text: Text): HTMLElement {
  const item = liveElement('li', 'quizgeist-schedule-item');
  if (group.colorKey !== null) {
    item.dataset.competenceColor = group.colorKey;
  }
  const label = liveElement('span', 'quizgeist-schedule-label', {
    text: group.untagged || group.label === ''
      ? text('schedule:overview:untagged', 'Ohne Thema')
      : group.label,
  });
  const figure = liveElement('span', 'quizgeist-schedule-figure', {
    text: text(
      'schedule:overview:groupvalue',
      '{$due} von {$tracked} fällig · {$percent} % aller Fälligkeiten',
      {
        due: group.dueCount,
        percent: group.duePercent,
        tracked: group.trackedCount,
      },
    ),
  });
  const bar = liveElement('span', 'quizgeist-schedule-bar');
  bar.setAttribute('role', 'img');
  bar.setAttribute(
    'aria-label',
    text(
      'schedule:overview:barlabel',
      '{$label}: {$figure}',
      {figure: figure.textContent || '', label: label.textContent || ''},
    ),
  );
  const fill = liveElement('span', 'quizgeist-schedule-fill');
  fill.style.width = `${group.duePercent}%`;
  if (group.colorKey !== null) {
    fill.dataset.competenceColor = group.colorKey;
  }
  // Shape marker beside the colour: urgency must be readable in greyscale.
  fill.dataset.scheduleState = group.duePercent >= 50
    ? 'high'
    : group.duePercent >= 20
      ? 'medium'
      : 'low';
  bar.append(fill);
  const meta = liveElement('span', 'quizgeist-schedule-meta', {
    text: text(
      'schedule:overview:learners',
      '{$count} Lernende betroffen',
      {count: group.learnerCount},
    ),
  });
  item.append(label, figure, bar, meta);
  return item;
}
