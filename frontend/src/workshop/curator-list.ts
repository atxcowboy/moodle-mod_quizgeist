import {liveButton, liveElement} from '../live/dom';
import {
  stateSentence,
  type WorkshopAiCheck,
  type WorkshopSubmission,
  type WorkshopText,
  type WorkshopView,
} from './student-form';

/**
 * F7 Fragenwerkstatt — the teacher's curation queue.
 *
 * The release button is offered here, but the decision is not made here: the
 * server additionally requires mod/quizgeist:manage and re-validates the
 * question before it becomes playable (peer_service::curate()). A teacher who
 * lacks the right sees the button and receives a readable refusal — that is
 * deliberate, because a silently missing button teaches nobody anything.
 *
 * The AI pre-check is rendered as what it is: three observations with a
 * source. It never pre-selects a decision.
 */

export type CurationDecision = 'approved' | 'rejected' | 'revising';

/**
 * Render the curation queue.
 */
export function renderCuratorList(
  view: WorkshopView,
  t: WorkshopText,
  onDecide: (submissionId: number, state: CurationDecision, note: string) => void,
  onCheck: ((submissionId: number) => void) | null = null,
): HTMLElement {
  const section = liveElement('section', 'quizgeist-workshop-curate', {
    'data-quizgeist-view': 'workshop-curate',
    'aria-labelledby': 'quizgeist-workshop-curate-title',
  });
  const heading = liveElement('h3', 'quizgeist-workshop-curate__title', {
    text: t('workshop:curate:title', 'Fragenwerkstatt'),
  });
  heading.id = 'quizgeist-workshop-curate-title';
  section.append(
    heading,
    liveElement('p', 'quizgeist-workshop-curate__copy', {
      text: t(
        'workshop:curate:copy',
        'Eingereichte Schülerfragen bleiben Entwürfe, bis Sie sie freigeben. Erst dann sind sie spielbar.',
      ),
    }),
  );
  const errors = liveElement('div', 'quizgeist-workshop-errors', {role: 'alert'});
  section.append(errors);

  if (view.queue.length === 0) {
    section.append(liveElement('p', 'quizgeist-workshop-empty', {
      text: t('workshop:curate:empty', 'Zurzeit liegt keine Einreichung vor.'),
    }));
    return section;
  }
  const list = liveElement('ul', 'quizgeist-workshop-list');
  view.queue.forEach((submission) => {
    list.append(renderQueueItem(submission, t, onDecide, onCheck));
  });
  section.append(list);
  return section;
}

/**
 * One submission in the queue.
 */
function renderQueueItem(
  submission: WorkshopSubmission,
  t: WorkshopText,
  onDecide: (submissionId: number, state: CurationDecision, note: string) => void,
  onCheck: ((submissionId: number) => void) | null,
): HTMLElement {
  const item = liveElement('li', 'quizgeist-workshop-item', {
    'data-workshop-state': submission.state,
    'data-workshop-id': submission.id,
  });
  item.append(
    liveElement('p', 'quizgeist-workshop-item__author', {
      text: submission.authorName,
    }),
    liveElement('p', 'quizgeist-workshop-item__question', {
      text: submission.questionText,
    }),
    liveElement('p', 'quizgeist-workshop-item__explanation', {
      text: submission.explanation,
    }),
    liveElement('p', 'quizgeist-workshop-item__state', {
      text: stateSentence(submission.state, t),
    }),
  );
  if (submission.ratingCount > 0) {
    item.append(liveElement('p', 'quizgeist-workshop-item__figures', {
      text: t(
        'workshop:curate:figures',
        '{$count} Rückmeldungen · Qualität {$quality} · Schwierigkeit {$difficulty}',
        {
          count: submission.ratingCount,
          difficulty: String(submission.averageDifficulty ?? 0),
          quality: String(submission.averageQuality ?? 0),
        },
      ),
    }));
  }
  if (submission.aiCheck !== null) {
    item.append(renderAiCheck(submission.aiCheck, t));
  }

  const note = liveElement('input', 'quizgeist-workshop-input', {
    type: 'text',
    maxlength: 1000,
    value: submission.curatorNote,
    'aria-label': t('workshop:curate:note', 'Rückmeldung an die Lernenden'),
  }) as HTMLInputElement;
  const actions = liveElement('div', 'quizgeist-workshop-actions');
  const decision = (
    state: CurationDecision,
    label: string,
    variant: string,
  ): HTMLButtonElement => {
    const control = liveButton(
      label,
      `quizgeist-button quizgeist-button--${variant}`,
      {'data-workshop-action': state},
    );
    control.addEventListener('click', () => onDecide(submission.id, state, note.value));
    return control;
  };
  actions.append(
    decision('approved', t('workshop:curate:approve', 'Freigeben'), 'primary'),
    decision('revising', t('workshop:curate:revise', 'Zurück zur Überarbeitung'), 'secondary'),
    decision('rejected', t('workshop:curate:reject', 'Nicht übernehmen'), 'quiet-danger'),
  );
  if (onCheck !== null) {
    const check = liveButton(
      t('workshop:curate:aicheck', 'KI-Vorprüfung'),
      'quizgeist-button quizgeist-button--secondary',
      {'data-workshop-action': 'aicheck'},
    );
    check.addEventListener('click', () => onCheck(submission.id));
    actions.append(check);
  }
  item.append(note, actions);
  return item;
}

/**
 * The advisory AI pre-check, labelled as advice.
 */
function renderAiCheck(check: WorkshopAiCheck, t: WorkshopText): HTMLElement {
  const box = liveElement('div', 'quizgeist-workshop-aicheck', {
    'data-workshop-aicheck': check.origin === 'gateway' ? 'gateway' : 'fallback',
  });
  box.append(liveElement('p', 'quizgeist-workshop-aicheck__lead', {
    text: check.origin === 'gateway'
      ? t('workshop:aicheck:gateway', 'KI-Hinweis (Vorschlag, keine Entscheidung):')
      : t('workshop:aicheck:fallback', 'Regelprüfung ohne KI (Vorschlag, keine Entscheidung):'),
  }));
  const list = liveElement('ul', 'quizgeist-workshop-aicheck__list');
  check.checks.forEach((entry) => {
    const row = liveElement('li', 'quizgeist-workshop-aicheck__item', {
      'data-aicheck-verdict': entry.verdict,
    });
    row.append(
      liveElement('span', 'quizgeist-workshop-aicheck__mark', {
        'aria-hidden': 'true',
        text: entry.verdict === 'ok' ? '▲' : '■',
      }),
      liveElement('span', 'quizgeist-workshop-aicheck__text', {
        text: checkSentence(entry.key, entry.verdict, entry.note, t),
      }),
    );
    list.append(row);
  });
  box.append(list);
  return box;
}

/**
 * Readable sentence of one observation (F16: no machine key in the DOM).
 */
function checkSentence(
  key: string,
  verdict: string,
  note: string,
  t: WorkshopText,
): string {
  const name = key === 'comprehensible'
    ? t('workshop:aicheck:comprehensible', 'Verständlichkeit')
    : (key === 'unique_solution'
      ? t('workshop:aicheck:unique', 'Eindeutigkeit der Lösung')
      : (key === 'explanation_complete'
        ? t('workshop:aicheck:explanation', 'Vollständigkeit der Erklärung')
        : t('workshop:aicheck:other', 'Weitere Beobachtung')));
  const state = verdict === 'ok'
    ? t('workshop:aicheck:ok', 'unauffällig')
    : t('workshop:aicheck:attention', 'einen Blick wert');
  return note === '' ? `${name}: ${state}` : `${name}: ${state} — ${note}`;
}
