import {liveElement} from './dom';
import type {LiveQuestionType} from './types';

export const LIVE_CHOICE_SLOTS = ['a', 'b', 'c', 'd', 'e', 'f'] as const;

export type LiveChoiceSlot = typeof LIVE_CHOICE_SLOTS[number];

export function choiceSlot(
  qtype: LiveQuestionType,
  index: number,
): LiveChoiceSlot {
  if (qtype === 'truefalse' && index === 1) {
    return 'd';
  }
  return LIVE_CHOICE_SLOTS[Math.max(0, Math.min(LIVE_CHOICE_SLOTS.length - 1, index))];
}

export function choiceShape(slot: LiveChoiceSlot): HTMLSpanElement {
  return liveElement(
    'span',
    `quizgeist-live-choice-shape quizgeist-live-choice-shape--${slot}`,
    {
      'aria-hidden': 'true',
      'data-choice-slot': slot,
    },
  );
}

export function choiceShapeStringKey(slot: LiveChoiceSlot): string {
  return `live:shape:${slot}`;
}
