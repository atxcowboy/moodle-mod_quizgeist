import {liveButton, liveElement} from '../live/dom';
import type {SelfStudyConfig, StudyMode} from './types';

export type StudyStringValues = Record<string, string | number>;

export function studyText(
  config: SelfStudyConfig,
  key: string,
  fallback: string,
  values: StudyStringValues = {},
): string {
  let text = config.strings[key] || fallback;
  Object.entries(values).forEach(([name, value]) => {
    // Moodle's own notation `{$a->name}` and the short form `{$name}` used by
    // the TypeScript fallbacks are BOTH substituted. Without the first one a
    // translated string renders its placeholder verbatim — a raw machine
    // token in the DOM, which is exactly what F16 forbids.
    text = text.split(`{$a->${name}}`).join(String(value));
    text = text.split(`{$${name}}`).join(String(value));
  });
  if ('a' in values) {
    text = text.split('{$a}').join(String(values.a));
  }
  return text;
}

export function studyButton(
  label: string,
  variant: 'primary' | 'secondary' | 'quiet' | 'danger' = 'primary',
): HTMLButtonElement {
  return liveButton(
    label,
    `quizgeist-study-button quizgeist-study-button--${variant}`,
  );
}

export function icon(
  name: 'calendar' | 'check' | 'clock' | 'goal' | 'repeat',
): HTMLElement {
  const fontAwesome: Record<typeof name, string> = {
    calendar: 'fa-calendar',
    check: 'fa-check',
    clock: 'fa-clock-o',
    goal: 'fa-bullseye',
    repeat: 'fa-repeat',
  };
  return liveElement(
    'span',
    `quizgeist-study-icon icon fa ${fontAwesome[name]}`,
    {'aria-hidden': 'true'},
  );
}

export function modeLabel(config: SelfStudyConfig, mode: StudyMode): string {
  const fallbacks: Record<StudyMode, string> = {
    flashcards: 'Lernkarten',
    practice: 'Übung',
    solo: 'Solo-Tempo',
    test: 'Übungstest',
    speaking: 'Sprech-Trainer',
  };
  return studyText(config, `selfstudy:mode:${mode}`, fallbacks[mode]);
}

export function formatDate(timestampMs: number): string {
  if (!Number.isFinite(timestampMs) || timestampMs <= 0) {
    return '';
  }
  return new Intl.DateTimeFormat(document.documentElement.lang || 'de-DE', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(timestampMs));
}

export function deadlineBadge(
  config: SelfStudyConfig,
  timeDueMs: number,
  serverTimeMs: number,
): HTMLElement {
  const badge = liveElement('span', 'quizgeist-study-deadline');
  badge.append(icon(timeDueMs > 0 ? 'clock' : 'calendar'));
  if (timeDueMs <= 0) {
    badge.classList.add('quizgeist-study-deadline--none');
    badge.append(document.createTextNode(
      studyText(config, 'selfstudy:deadline:none', 'Ohne Frist'),
    ));
    return badge;
  }
  const remaining = timeDueMs - serverTimeMs;
  let label: string;
  if (remaining < 0) {
    badge.classList.add('quizgeist-study-deadline--urgent');
    label = studyText(config, 'selfstudy:deadline:overdue', 'Überfällig seit {$date}', {
      date: formatDate(timeDueMs),
    });
  } else if (remaining < 24 * 60 * 60 * 1000) {
    badge.classList.add('quizgeist-study-deadline--urgent');
    label = studyText(config, 'selfstudy:deadline:today', 'Fällig bis {$date}', {
      date: formatDate(timeDueMs),
    });
  } else if (remaining <= 72 * 60 * 60 * 1000) {
    badge.classList.add('quizgeist-study-deadline--soon');
    label = studyText(config, 'selfstudy:deadline:soon', 'Fällig am {$date}', {
      date: formatDate(timeDueMs),
    });
  } else {
    badge.classList.add('quizgeist-study-deadline--comfortable');
    label = studyText(config, 'selfstudy:deadline:comfortable', 'Fällig am {$date}', {
      date: formatDate(timeDueMs),
    });
  }
  badge.append(document.createTextNode(label));
  return badge;
}

export function loadingCard(config: SelfStudyConfig, labelKey: string): HTMLElement {
  const card = liveElement('div', 'quizgeist-study-state', {
    'aria-live': 'polite',
    role: 'status',
  });
  card.append(
    liveElement('span', 'quizgeist-study-spinner', {'aria-hidden': 'true'}),
    liveElement('p', '', {
      text: studyText(config, labelKey, 'Inhalte werden geladen …'),
    }),
  );
  return card;
}

export function errorCard(
  config: SelfStudyConfig,
  message: string,
  retry: () => void,
): HTMLElement {
  const card = liveElement('section', 'quizgeist-study-state quizgeist-study-state--error', {
    role: 'alert',
  });
  const button = studyButton(
    studyText(config, 'selfstudy:action:retry', 'Erneut versuchen'),
    'secondary',
  );
  button.addEventListener('click', retry);
  card.append(
    liveElement('h2', '', {
      text: studyText(config, 'selfstudy:error:title', 'Das hat noch nicht geklappt'),
    }),
    liveElement('p', '', {text: message}),
    button,
  );
  return card;
}
