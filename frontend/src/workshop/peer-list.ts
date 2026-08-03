import {liveButton, liveElement} from '../live/dom';
import {
  stateSentence,
  type WorkshopRating,
  type WorkshopSubmission,
  type WorkshopText,
  type WorkshopView,
} from './student-form';

/**
 * F7 Fragenwerkstatt — the peer list a learner rates.
 *
 * Anonymity is not decided here: peer_service::serialise() simply does not
 * send an author name to a non-curator, so this file has nothing to hide.
 *
 * Self-rating is likewise not "hidden" — the server refuses it
 * (authorid !== userid) and never puts an own submission into `peers`. The
 * own submissions are listed separately, without rating controls, because
 * that list answers a different question: what happened to my question.
 */

/**
 * Render the learner side of the workshop.
 */
export function renderPeerList(
  view: WorkshopView,
  t: WorkshopText,
  onRate: (submissionId: number, rating: WorkshopRating) => void,
): HTMLElement {
  const section = liveElement('section', 'quizgeist-workshop-peers', {
    'data-quizgeist-view': 'workshop-peer',
    'aria-labelledby': 'quizgeist-workshop-peers-title',
  });
  const heading = liveElement('h3', 'quizgeist-workshop-peers__title', {
    text: t('workshop:peers:title', 'Fragen deiner Klasse'),
  });
  heading.id = 'quizgeist-workshop-peers-title';
  section.append(
    heading,
    liveElement('p', 'quizgeist-workshop-peers__copy', {
      role: 'status',
      text: view.openRatings > 0
        ? t(
          'workshop:peers:open',
          'Für {$count} Fragen fehlt noch deine Rückmeldung.',
          {count: view.openRatings},
        )
        : t('workshop:peers:done', 'Du hast allen Fragen eine Rückmeldung gegeben.'),
    }),
  );

  if (view.mine.length > 0) {
    section.append(renderOwnList(view.mine, t));
  }

  if (view.peers.length === 0) {
    section.append(liveElement('p', 'quizgeist-workshop-empty', {
      text: t(
        'workshop:peers:empty',
        'Noch hat niemand eine Frage eingereicht. Du kannst die erste schreiben.',
      ),
    }));
    return section;
  }
  const list = liveElement('ul', 'quizgeist-workshop-list');
  view.peers.forEach((submission) => {
    list.append(renderPeerItem(submission, view, t, onRate));
  });
  section.append(list);
  return section;
}

/**
 * The learner's own submissions with their current state.
 */
function renderOwnList(
  submissions: WorkshopSubmission[],
  t: WorkshopText,
): HTMLElement {
  const wrapper = liveElement('div', 'quizgeist-workshop-own');
  wrapper.append(liveElement('h4', 'quizgeist-workshop-own__title', {
    text: t('workshop:mine:title', 'Deine Fragen'),
  }));
  const list = liveElement('ul', 'quizgeist-workshop-list');
  submissions.forEach((submission) => {
    const item = liveElement('li', 'quizgeist-workshop-item', {
      'data-workshop-state': submission.state,
    });
    item.append(
      liveElement('p', 'quizgeist-workshop-item__question', {
        text: submission.questionText,
      }),
      liveElement('p', 'quizgeist-workshop-item__state', {
        text: stateSentence(submission.state, t),
      }),
    );
    if (submission.curatorNote !== '') {
      item.append(liveElement('p', 'quizgeist-workshop-item__note', {
        text: submission.curatorNote,
      }));
    }
    list.append(item);
  });
  wrapper.append(list);
  return wrapper;
}

/**
 * One peer submission with its rating controls.
 */
function renderPeerItem(
  submission: WorkshopSubmission,
  view: WorkshopView,
  t: WorkshopText,
  onRate: (submissionId: number, rating: WorkshopRating) => void,
): HTMLElement {
  const item = liveElement('li', 'quizgeist-workshop-item', {
    'data-workshop-state': submission.state,
    'data-workshop-id': submission.id,
  });
  item.append(
    liveElement('p', 'quizgeist-workshop-item__question', {
      text: submission.questionText,
    }),
    liveElement('p', 'quizgeist-workshop-item__explanation', {
      text: submission.explanation,
    }),
    liveElement('p', 'quizgeist-workshop-item__figures', {
      text: submission.ratingCount > 0
        ? t(
          'workshop:peers:figures',
          '{$count} Rückmeldungen · Qualität {$quality} · Schwierigkeit {$difficulty}',
          {
            count: submission.ratingCount,
            difficulty: String(submission.averageDifficulty ?? 0),
            quality: String(submission.averageQuality ?? 0),
          },
        )
        : t('workshop:peers:norating', 'Noch keine Rückmeldung.'),
    }),
  );

  const form = liveElement('div', 'quizgeist-workshop-rating');
  const quality = renderScale(
    'quality',
    t('workshop:rating:quality', 'Wie gut ist die Frage?'),
    view.ratingRange,
    submission.ownRating?.quality ?? 0,
  );
  const difficulty = renderScale(
    'difficulty',
    t('workshop:rating:difficulty', 'Wie schwer war sie?'),
    view.ratingRange,
    submission.ownRating?.difficulty ?? 0,
  );
  const comment = liveElement('input', 'quizgeist-workshop-input', {
    type: 'text',
    maxlength: 500,
    value: submission.ownRating?.comment ?? '',
    'aria-label': t('workshop:rating:comment', 'Kurze Rückmeldung'),
  }) as HTMLInputElement;
  const save = liveButton(
    t('workshop:rating:save', 'Rückmeldung speichern'),
    'quizgeist-button quizgeist-button--secondary',
    {'data-workshop-action': 'rate'},
  );
  save.addEventListener('click', () => {
    onRate(submission.id, {
      comment: comment.value,
      difficulty: Number(difficulty.select.value || 0),
      quality: Number(quality.select.value || 0),
    });
  });
  form.append(quality.field, difficulty.field, comment, save);
  item.append(form);
  return item;
}

/**
 * One rating scale as a labelled select.
 *
 * A select instead of five stars on purpose: stars are colour and shape only,
 * a select states its value in words for every reader (DESIGN 7) and is
 * reachable with the keyboard without a custom widget.
 */
function renderScale(
  name: string,
  label: string,
  range: {max: number; min: number},
  current: number,
): {field: HTMLElement; select: HTMLSelectElement} {
  const field = liveElement('label', 'quizgeist-workshop-field');
  field.append(liveElement('span', 'quizgeist-workshop-field__label', {text: label}));
  const select = liveElement('select', 'quizgeist-workshop-select', {
    'data-workshop-scale': name,
  }) as HTMLSelectElement;
  for (let step = range.min; step <= range.max; step += 1) {
    select.append(liveElement('option', '', {
      value: String(step),
      text: String(step),
      selected: step === current,
    }));
  }
  field.append(select);
  return {field, select};
}
