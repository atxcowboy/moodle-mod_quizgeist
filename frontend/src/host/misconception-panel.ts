import {liveElement} from '../live/dom';

/**
 * F5 Fehlkonzept-Radar — the teacher's side of the live distribution.
 *
 * Two things are rendered here that a learner must never see: the label a
 * teacher attached to a distractor, and the traffic light that states how
 * large the correct share is. Both are cut on the SERVER
 * (state_projector::aggregate_projection() enriches the host role only), so
 * this module simply renders what arrived. It never derives the traffic light
 * from a percentage it computed itself — a client-side calculation would be a
 * second, quieter source of truth.
 *
 * DESIGN 7: the traffic light never speaks through colour alone. It carries a
 * word, a shape marker and the figures it is based on.
 */

export type HingeStatus = 'insufficient' | 'reteach' | 'move_on';

export interface MisconceptionRow {
  choiceId: string;
  count: number;
  label: string | null;
  percent: number;
}

type Text = (
  key: string,
  fallback: string,
  values?: Record<string, string | number>,
) => string;

const HINGE_STATUSES: readonly HingeStatus[] = [
  'insufficient',
  'reteach',
  'move_on',
];

/**
 * Read the traffic light out of an untrusted host payload.
 *
 * An absent key is the normal case (no reports addon, no correctness notion,
 * player role) and means "there is no traffic light", not "unknown".
 */
export function normaliseHingeStatus(raw: unknown): HingeStatus | null {
  return typeof raw === 'string'
    && (HINGE_STATUSES as readonly string[]).includes(raw)
    ? raw as HingeStatus
    : null;
}

/**
 * Read the misconception label of one distribution row.
 */
export function normaliseMisconceptionLabel(raw: unknown): string | null {
  return typeof raw === 'string' && raw.trim() !== '' ? raw : null;
}

/**
 * Render the traffic light, or nothing when the server sent none.
 */
export function renderHingeBadge(
  status: HingeStatus | null,
  text: Text,
): HTMLElement | null {
  if (status === null) {
    return null;
  }
  const badge = liveElement('p', 'quizgeist-hinge-badge', {
    'data-hinge-status': status,
    'data-quizgeist-view': 'host-misconception',
    role: 'status',
  });
  badge.append(
    liveElement('span', 'quizgeist-hinge-badge__mark', {
      'aria-hidden': 'true',
      text: status === 'move_on' ? '▲' : (status === 'reteach' ? '■' : '·'),
    }),
    liveElement('span', 'quizgeist-hinge-badge__text', {
      text: hingeSentence(status, text),
    }),
  );
  return badge;
}

/**
 * The readable sentence of one traffic-light state.
 *
 * F16: every machine state has its own sentence here. No lookup in this file
 * ever falls back to the raw lookup key, so a missing translation can never
 * put a machine code on a beamer.
 */
export function hingeSentence(status: HingeStatus, text: Text): string {
  if (status === 'move_on') {
    return text(
      'host:hinge:moveon',
      'Genug verstanden — ihr könnt weitergehen.',
    );
  }
  if (status === 'reteach') {
    return text(
      'host:hinge:reteach',
      'Noch nicht sicher — diese Stelle lohnt eine Runde mehr.',
    );
  }
  return text(
    'host:hinge:insufficient',
    'Zu wenige Antworten für eine Aussage.',
  );
}

/**
 * Render one misconception label next to a distribution bar.
 */
export function renderMisconceptionLabel(
  label: string | null,
  text: Text,
): HTMLElement | null {
  if (label === null) {
    return null;
  }
  const node = liveElement('span', 'quizgeist-misconception-label', {
    'data-misconception-label': true,
  });
  node.append(
    liveElement('span', 'quizgeist-misconception-label__lead', {
      text: text('host:misconception:lead', 'Fehlvorstellung:'),
    }),
    // textContent, never innerHTML: the label is teacher input.
    liveElement('span', 'quizgeist-misconception-label__text', {text: label}),
  );
  return node;
}

/**
 * Attach labels and traffic light to an already rendered distribution.
 *
 * The distribution markup belongs to the host app; this function only adds
 * the teacher-only layer to it, so a change of the bar rendering cannot
 * silently drop the radar.
 */
export function decorateDistribution(
  container: HTMLElement,
  rows: MisconceptionRow[],
  status: HingeStatus | null,
  text: Text,
): void {
  container.querySelectorAll('[data-misconception-label]').forEach((node) => {
    node.remove();
  });
  rows.forEach((row) => {
    const label = normaliseMisconceptionLabel(row.label);
    if (label === null) {
      return;
    }
    const target = container.querySelector<HTMLElement>(
      `[data-live-choice-id="${cssEscape(row.choiceId)}"]`,
    );
    const node = renderMisconceptionLabel(label, text);
    if (target !== null && node !== null) {
      target.append(node);
    }
  });
  const existing = container.querySelector('.quizgeist-hinge-badge');
  if (existing !== null) {
    existing.remove();
  }
  const badge = renderHingeBadge(status, text);
  if (badge !== null) {
    container.append(badge);
  }
}

/**
 * Escape an opaque choice handle for use inside an attribute selector.
 */
function cssEscape(value: string): string {
  return value.replace(/["\\]/g, '\\$&');
}
