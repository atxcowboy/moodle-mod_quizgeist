import type {LiveAnswer} from './types';

/**
 * Convert the shared response-renderer value into the strategy payload sent to
 * Moodle. This intentionally performs no validation or scoring; both stay in
 * the PHP question-type strategies.
 */
export function answerPayload(answer: LiveAnswer): Record<string, unknown> {
  switch (answer.kind) {
    case 'text':
      return {text: answer.text};
    case 'clip':
      return {clipId: answer.clipId};
    case 'order':
      return {orderIds: answer.orderIds};
    case 'number':
      return {value: answer.value};
    case 'pin':
      return {x: answer.x, y: answer.y};
    case 'brainstormIdea':
      return {text: answer.text};
    case 'brainstormVote':
      return {groupKey: answer.groupKey};
    case 'reaction':
      return {reaction: answer.reaction};
    case 'stage':
      return {reportId: answer.reportId};
    default:
      return {choiceIds: answer.choiceIds};
  }
}

/**
 * Restore a public, visit-bound answer payload for the shared renderer.
 *
 * The server is responsible for translating canonical IDs back into opaque
 * handles before returning a payload. Unknown shapes fail closed to null.
 */
export function liveAnswerFromPayload(raw: unknown): LiveAnswer | null {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return null;
  }
  const value = raw as Record<string, unknown>;
  if (Array.isArray(value.choiceIds)
      && value.choiceIds.length > 0
      && value.choiceIds.every((id) => typeof id === 'string')) {
    return {kind: 'choices', choiceIds: value.choiceIds as string[]};
  }
  if (Array.isArray(value.orderIds)
      && value.orderIds.length > 0
      && value.orderIds.every((id) => typeof id === 'string')) {
    return {kind: 'order', orderIds: value.orderIds as string[]};
  }
  if (typeof value.text === 'string') {
    return {kind: 'text', text: value.text};
  }
  if (typeof value.value === 'number' && Number.isFinite(value.value)) {
    return {kind: 'number', value: value.value};
  }
  if (typeof value.x === 'number'
      && Number.isFinite(value.x)
      && typeof value.y === 'number'
      && Number.isFinite(value.y)) {
    return {kind: 'pin', x: value.x, y: value.y};
  }
  if (typeof value.reaction === 'string') {
    return {kind: 'reaction', reaction: value.reaction};
  }
  if (typeof value.groupKey === 'string') {
    return {kind: 'brainstormVote', groupKey: value.groupKey};
  }
  return null;
}
