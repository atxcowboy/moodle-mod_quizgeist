import type {HostState} from '../live/types';

export const HOST_MUTATION_MAX_ATTEMPTS = 3;
export const HOST_MUTATION_RETRY_DELAYS_MS = [60, 120] as const;

export interface HostPosition {
  currentIndex: number;
  interactionStage: string | null;
  phase: string;
  questionToken: string | null;
  sessionId: number;
}

export type HostMutationResult<T> =
  | {status: 'success'; value: T}
  | {error: unknown; status: 'position-changed'}
  | {error: unknown; status: 'conflict-exhausted'};

export interface HostMutationRetryOptions<T> {
  applyConflictState: (state: HostState) => void;
  conflictState: (error: unknown) => HostState | null;
  currentState: () => HostState | null;
  initialState: HostState;
  maxAttempts?: number;
  request: (expectedState: HostState) => Promise<T>;
  wait?: (delayMs: number) => Promise<void>;
}

/**
 * Return the complete identity of one live position.
 *
 * stateVersion is deliberately excluded: joins and other concurrent updates
 * may change it without changing the position in which an action was intended.
 */
export function hostPosition(state: HostState): HostPosition {
  return {
    currentIndex: state.currentIndex,
    interactionStage: state.interactionStage ?? null,
    phase: state.phase,
    questionToken: state.question?.questionToken ?? null,
    sessionId: state.sessionId,
  };
}

/**
 * A retry is safe only while phase, index, visit and interaction stage match.
 */
export function isSameHostPosition(
  expected: HostState,
  fresh: HostState,
): boolean {
  const left = hostPosition(expected);
  const right = hostPosition(fresh);
  return left.sessionId === right.sessionId
    && left.phase === right.phase
    && left.currentIndex === right.currentIndex
    && left.questionToken === right.questionToken
    && left.interactionStage === right.interactionStage;
}

function waitForRetry(delayMs: number): Promise<void> {
  return new Promise((resolve) => {
    globalThis.setTimeout(resolve, delayMs);
  });
}

/**
 * Retry a version-conflicted host mutation without crossing a live position.
 *
 * The latest local state is checked again after every backoff. This prevents a
 * delayed request from being sent when polling or another moderator changed the
 * visit or interaction stage while the retry was waiting.
 */
export async function retryHostMutation<T>(
  options: HostMutationRetryOptions<T>,
): Promise<HostMutationResult<T>> {
  const requestedAttempts = options.maxAttempts ?? HOST_MUTATION_MAX_ATTEMPTS;
  const maxAttempts = Number.isFinite(requestedAttempts) && requestedAttempts >= 1
    ? Math.floor(requestedAttempts)
    : HOST_MUTATION_MAX_ATTEMPTS;
  const wait = options.wait ?? waitForRetry;
  let expectedState = options.initialState;

  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    try {
      return {
        status: 'success',
        value: await options.request(expectedState),
      };
    } catch (error) {
      const fresh = options.conflictState(error);
      if (!fresh) {
        throw error;
      }

      options.applyConflictState(fresh);
      if (!isSameHostPosition(expectedState, fresh)) {
        return {error, status: 'position-changed'};
      }
      if (attempt + 1 >= maxAttempts) {
        return {error, status: 'conflict-exhausted'};
      }

      const delay = HOST_MUTATION_RETRY_DELAYS_MS[
        Math.min(attempt, HOST_MUTATION_RETRY_DELAYS_MS.length - 1)
      ];
      await wait(delay);

      const current = options.currentState();
      if (!current || !isSameHostPosition(fresh, current)) {
        return {error, status: 'position-changed'};
      }
      expectedState = current.stateVersion >= fresh.stateVersion
        ? current
        : fresh;
    }
  }

  // maxAttempts is normalised to at least one, so this is unreachable.
  throw new Error('Host mutation retry ended without a result.');
}
