import {LiveApi, LiveApiError} from '../live/api';
import type {
  AssignmentInput,
  SelfStudyAttemptState,
  SelfStudyConfig,
  SelfStudyOverview,
  StateResult,
  TeacherAssignmentList,
} from './types';
import {
  normaliseAttemptState,
  normaliseOverview,
  normaliseTeacherAssignmentList,
} from './normalise';

export {LiveApiError as SelfStudyApiError};

/**
 * Typed P5 action adapter. Keeping action names here makes the UI independent
 * from handler file layout and prevents request-shape drift across screens.
 */
export class SelfStudyApi {
  private readonly api: LiveApi;

  public constructor(private readonly config: SelfStudyConfig) {
    this.api = new LiveApi(config);
  }

  /**
   * Pass one action straight through (P11/C4).
   *
   * The clip and speaking endpoints have no normalisation of their own: their
   * payloads are already the shape the caller wants, and inventing a second
   * one would only add a place for the two to drift apart.
   *
   * @param action Action name.
   * @param payload Request payload.
   * @param signal Optional abort signal.
   */
  public async post<T>(
    action: string,
    payload: Record<string, unknown> = {},
    signal?: AbortSignal,
  ): Promise<T> {
    return this.api.post<T>(action, payload, signal);
  }

  public async overview(signal?: AbortSignal): Promise<SelfStudyOverview> {
    return normaliseOverview(
      await this.api.post<unknown>('selfstudy_overview', {}, signal),
    );
  }

  /**
   * Read the learner's own due repetitions (F3).
   *
   * The panel owns the shape of its DTO, so the raw payload is handed on.
   */
  public async dueCard(signal?: AbortSignal): Promise<unknown> {
    return this.api.post<unknown>('schedule_due', {}, signal);
  }

  /**
   * Read the question workshop (F7).
   *
   * The panel owns the shape of its DTO, so the raw payload is handed on.
   */
  public async workshop(signal?: AbortSignal): Promise<unknown> {
    return this.api.post<unknown>('workshop_list', {}, signal);
  }

  /**
   * Submit one learner-written question.
   *
   * The payload carries content only; the lifecycle belongs to the server.
   */
  public async workshopSubmit(payload: Record<string, unknown>): Promise<unknown> {
    return this.api.post<unknown>('workshop_submit', payload);
  }

  public async workshopRate(
    workshopId: number,
    rating: {comment: string; difficulty: number; quality: number},
  ): Promise<unknown> {
    return this.api.post<unknown>('workshop_rate', {workshopId, ...rating});
  }

  /**
   * Decide one submission (teacher surface, same addon action).
   */
  public async workshopCurate(
    workshopId: number,
    state: string,
    note: string,
  ): Promise<unknown> {
    return this.api.post<unknown>('workshop_curate', {note, state, workshopId});
  }

  public async start(
    assignmentId: number,
    newAttempt = false,
  ): Promise<StateResult> {
    const result = await this.api.post<{state?: unknown}>(
      'selfstudy_start',
      {assignmentId, newAttempt},
    );
    return {state: normaliseAttemptState(result.state)};
  }

  public async state(
    attemptId: number,
    signal?: AbortSignal,
  ): Promise<StateResult> {
    const result = await this.api.post<{state?: unknown}>(
      'selfstudy_state',
      {attemptId},
      signal,
    );
    return {state: normaliseAttemptState(result.state)};
  }

  public async navigate(
    attemptId: number,
    index: number,
    stateVersion: number,
  ): Promise<StateResult> {
    const result = await this.api.post<{state?: unknown}>('selfstudy_navigate', {
      attemptId,
      index,
      stateVersion,
    });
    return {state: normaliseAttemptState(result.state)};
  }

  public async submit(
    state: SelfStudyAttemptState,
    answer: Record<string, unknown>,
  ): Promise<StateResult> {
    const result = await this.api.post<{state?: unknown}>('selfstudy_submit', {
      answer,
      attemptId: state.attemptId,
      questionId: state.question?.id || 0,
      questionToken: state.question?.questionToken || '',
      stateVersion: state.stateVersion,
    });
    return {state: normaliseAttemptState(result.state)};
  }

  public async finish(state: SelfStudyAttemptState): Promise<StateResult> {
    const result = await this.api.post<{state?: unknown}>('selfstudy_finish', {
      attemptId: state.attemptId,
      stateVersion: state.stateVersion,
    });
    return {state: normaliseAttemptState(result.state)};
  }

  public async revealFlashcard(
    state: SelfStudyAttemptState,
  ): Promise<StateResult> {
    const result = await this.api.post<{state?: unknown}>('flashcard_reveal', {
      attemptId: state.attemptId,
      questionId: state.question?.id || 0,
      questionToken: state.question?.questionToken || '',
      stateVersion: state.stateVersion,
    });
    return {state: normaliseAttemptState(result.state)};
  }

  public async markFlashcard(
    state: SelfStudyAttemptState,
    known: boolean,
  ): Promise<StateResult> {
    const result = await this.api.post<{state?: unknown}>('flashcard_mark', {
      attemptId: state.attemptId,
      known,
      questionId: state.question?.id || 0,
      questionToken: state.question?.questionToken || '',
      stateVersion: state.stateVersion,
    });
    return {state: normaliseAttemptState(result.state)};
  }

  public async saveWeeklyGoal(target: number): Promise<SelfStudyOverview> {
    return normaliseOverview(
      await this.api.post<unknown>('weekly_goal_save', {target}),
    );
  }

  public async assignmentList(
    signal?: AbortSignal,
  ): Promise<TeacherAssignmentList> {
    return normaliseTeacherAssignmentList(
      await this.api.post<unknown>('assignment_list', {}, signal),
    );
  }

  public async assignmentCreate(
    assignment: AssignmentInput,
  ): Promise<TeacherAssignmentList> {
    // F3: die Auswahlart entscheidet ueber die AKTION, nicht ueber ein Feld
    // in der Nutzlast. Der Server erzwingt sie dort noch einmal, damit eine
    // manipulierte Anfrage keine Wiederholungs-Zuweisung erschleichen kann.
    const action = assignment.selection === 'due'
      ? 'review_assignment_create'
      : 'assignment_create';
    return normaliseTeacherAssignmentList(
      await this.api.post<unknown>(action, {assignment}),
    );
  }

  public async assignmentUpdate(
    assignment: AssignmentInput,
  ): Promise<TeacherAssignmentList> {
    return normaliseTeacherAssignmentList(
      await this.api.post<unknown>('assignment_update', {assignment}),
    );
  }

  public async assignmentClose(
    assignmentId: number,
    timeModified: number,
  ): Promise<TeacherAssignmentList> {
    return normaliseTeacherAssignmentList(
      await this.api.post<unknown>('assignment_close', {
        assignmentId,
        timeModified,
      }),
    );
  }

  /**
   * Optimistic-state conflicts include the authoritative self-study state.
   * Applying it lets the learner continue immediately without retry loops.
   */
  public conflictState(error: unknown): SelfStudyAttemptState | null {
    if (!(error instanceof LiveApiError) || error.status !== 409) {
      return null;
    }
    const data = error.data as unknown;
    if (!data || typeof data !== 'object' || Array.isArray(data)) {
      return null;
    }
    const rawState = (data as Record<string, unknown>).state;
    if (!rawState || typeof rawState !== 'object' || Array.isArray(rawState)) {
      return null;
    }
    const state = normaliseAttemptState(rawState);
    return state.attemptId > 0 ? state : null;
  }

  public errorMessage(error: unknown): string {
    if (error instanceof LiveApiError && error.message !== '') {
      return error.message;
    }
    return this.config.strings['selfstudy:error:request']
      || this.config.strings['live:error:request']
      || 'Die Anfrage konnte nicht verarbeitet werden.';
  }
}
