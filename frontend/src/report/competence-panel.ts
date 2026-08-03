import {liveElement} from '../live/dom';

/**
 * F6 Kompetenz-Bericht — one bar per curriculum competence.
 *
 * DESIGN 7 rule, applied literally: colour is never the only carrier of
 * meaning. Every bar additionally states its label, its percentage in figures
 * and the size of its sample, and the weakest area comes first. A reader who
 * perceives no colour at all loses nothing but decoration.
 *
 * The panel renders nothing at all when the server sent no competences —
 * without the reports addon the axis does not exist, and an empty headline
 * would be a locked door with a sign on it.
 */

export interface ReportCompetence {
  color: string | null;
  correctPercent: number | null;
  key: string;
  label: string;
  pointsPercent: number | null;
  questionCount: number;
  sample: number;
}

type Text = (
  key: string,
  fallback: string,
  values?: Record<string, string | number>,
) => string;

/**
 * Normalise one untrusted competence row from the server payload.
 */
export function normaliseCompetence(raw: unknown): ReportCompetence | null {
  if (raw === null || typeof raw !== 'object') {
    return null;
  }
  const record = raw as Record<string, unknown>;
  const key = typeof record.key === 'string' ? record.key : '';
  const label = typeof record.label === 'string' ? record.label : '';
  if (key === '' || label === '') {
    return null;
  }
  const percent = (value: unknown): number | null => (
    typeof value === 'number' && Number.isFinite(value)
      ? Math.max(0, Math.min(100, value))
      : null
  );
  const count = (value: unknown): number => (
    typeof value === 'number' && Number.isFinite(value) && value > 0
      ? Math.floor(value)
      : 0
  );
  return {
    color: typeof record.color === 'string' && record.color !== ''
      ? record.color
      : null,
    correctPercent: percent(record.correctPercent),
    key,
    label,
    pointsPercent: percent(record.pointsPercent),
    questionCount: count(record.questionCount),
    sample: count(record.sample),
  };
}

/**
 * Normalise the competence list of one report payload.
 */
export function normaliseCompetences(raw: unknown): ReportCompetence[] {
  if (!Array.isArray(raw)) {
    return [];
  }
  return raw
    .map(normaliseCompetence)
    .filter((entry): entry is ReportCompetence => entry !== null);
}

/**
 * Render the competence panel, or nothing when there is nothing to show.
 */
export function renderCompetencePanel(
  competences: ReportCompetence[],
  text: Text,
): HTMLElement | null {
  if (competences.length === 0) {
    return null;
  }
  const section = liveElement('section', 'quizgeist-report-section');
  section.setAttribute('aria-labelledby', 'quizgeist-report-competences');
  const heading = liveElement('h3', 'quizgeist-report-section__title', {
    text: text('report:competence:title', 'Kompetenzbereiche'),
  });
  heading.id = 'quizgeist-report-competences';
  const hint = liveElement('p', 'quizgeist-report-filter-hint', {
    text: text(
      'report:competence:hint',
      'Der schwächste Bereich steht oben. Die Farbe wiederholt nur, was Beschriftung und Zahl bereits sagen.',
    ),
  });
  const list = liveElement('ul', 'quizgeist-competence-list');
  competences.forEach((competence) => {
    list.append(renderCompetenceRow(competence, text));
  });
  section.append(heading, hint, list);
  return section;
}

/**
 * Render one competence row: label, figures, bar.
 */
function renderCompetenceRow(
  competence: ReportCompetence,
  text: Text,
): HTMLElement {
  const item = liveElement('li', 'quizgeist-competence-item');
  if (competence.color !== null) {
    item.dataset.competenceColor = competence.color;
  }
  const label = liveElement('span', 'quizgeist-competence-label', {
    text: competence.label,
  });
  const measured = competence.correctPercent !== null;
  const figure = liveElement('span', 'quizgeist-competence-figure', {
    text: measured
      ? text(
        'report:competence:value',
        '{$percent} % richtig aus {$sample} Antworten',
        {
          percent: formatPercent(competence.correctPercent as number),
          sample: competence.sample,
        },
      )
      : text(
        'report:competence:nosample',
        'Noch keine bewertete Antwort in diesem Bereich',
      ),
  });
  const bar = liveElement('span', 'quizgeist-competence-bar');
  bar.setAttribute('role', 'img');
  bar.setAttribute(
    'aria-label',
    text(
      'report:competence:barlabel',
      '{$label}: {$figure}',
      {figure: figure.textContent || '', label: competence.label},
    ),
  );
  const fill = liveElement('span', 'quizgeist-competence-fill');
  fill.style.width = `${measured ? Math.round(competence.correctPercent as number) : 0}%`;
  if (competence.color !== null) {
    fill.dataset.competenceColor = competence.color;
  }
  // A bar that is only colour is a bar only some readers can use. The pattern
  // marker repeats the same information as a shape.
  fill.dataset.competenceState = measured
    ? (competence.correctPercent as number) < 50
      ? 'weak'
      : (competence.correctPercent as number) < 80
        ? 'medium'
        : 'strong'
    : 'unknown';
  bar.append(fill);
  const meta = liveElement('span', 'quizgeist-competence-meta', {
    text: text(
      'report:competence:questions',
      '{$count} Fragen',
      {count: competence.questionCount},
    ),
  });
  item.append(label, figure, bar, meta);
  return item;
}

/**
 * Format a percentage without a trailing zero decimal.
 */
function formatPercent(value: number): string {
  return Number.isInteger(value)
    ? String(value)
    : String(Math.round(value * 10) / 10);
}
