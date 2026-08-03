import {liveAnswerFromPayload} from '../live/answer';
import type {LiveQuestion} from '../live/types';
import {
  isStudyMode,
  type AssignmentSettings,
  type AssignmentStatus,
  type AssignmentSummary,
  type AttemptStatus,
  type GradeMethod,
  type GradeSummary,
  type QuestionResult,
  type ReviewSummary,
  type SelfStudyAttemptState,
  type SelfStudyOverview,
  type StudyMode,
  type TeacherAssignment,
  type TeacherAssignmentList,
  type WeeklyGoal,
} from './types';

type JsonRecord = Record<string, unknown>;

function record(value: unknown): JsonRecord {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? value as JsonRecord
    : {};
}

function records(value: unknown): JsonRecord[] {
  return Array.isArray(value)
    ? value.map(record).filter((entry) => Object.keys(entry).length > 0)
    : [];
}

function finiteNumber(value: unknown, fallback = 0): number {
  const candidate = typeof value === 'number'
    ? value
    : typeof value === 'string' && value.trim() !== ''
      ? Number(value)
      : Number.NaN;
  return Number.isFinite(candidate) ? candidate : fallback;
}

/**
 * F8: normalise the closing screen without ever inventing an entry.
 *
 * An absent or unavailable summary yields null, so the client renders
 * nothing at all rather than an empty promise.
 */
function reviewSummary(value: unknown): ReviewSummary | null {
  const source = record(value);
  if (source.available !== true) {
    return null;
  }
  return {
    available: true,
    deferred: source.deferred === true,
    entries: records(source.entries).map((entry) => ({
      explanation: text(entry.explanation),
      index: integer(entry.index),
      questionText: text(entry.questionText),
    })).filter((entry) => entry.explanation !== ''),
    policy: text(source.policy, 'immediate'),
    total: Math.max(0, integer(source.total)),
    withExplanation: Math.max(0, integer(source.withExplanation)),
  };
}

function integer(value: unknown, fallback = 0): number {
  const candidate = finiteNumber(value, fallback);
  return Number.isInteger(candidate) ? candidate : fallback;
}

function positiveInteger(value: unknown): number | null {
  const candidate = integer(value);
  return candidate > 0 ? candidate : null;
}

function text(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

function bool(value: unknown, fallback = false): boolean {
  return typeof value === 'boolean' ? value : fallback;
}

/**
 * The self-study service uses Unix seconds while teacher DTOs expose explicit
 * *Ms fields. Accepting either here keeps timestamps unambiguous at the UI
 * boundary and avoids duplicating conversions across components.
 */
function milliseconds(value: unknown): number {
  const candidate = finiteNumber(value);
  if (candidate <= 0) {
    return 0;
  }
  return candidate < 100_000_000_000 ? candidate * 1000 : candidate;
}

function mode(value: unknown, fallback: StudyMode = 'practice'): StudyMode {
  return isStudyMode(value) ? value : fallback;
}

function assignmentStatus(value: unknown): AssignmentStatus {
  return value === 'draft'
    || value === 'open'
    || value === 'closed'
    || value === 'archived'
    ? value
    : 'closed';
}

function attemptStatus(value: unknown): AttemptStatus {
  return value === 'completed' || value === 'abandoned'
    ? value
    : 'inprogress';
}

function assignmentSettings(
  value: unknown,
  timeDueMs: number,
): AssignmentSettings {
  const source = record(value);
  const maxAttempts = integer(source.maxAttempts, 1);
  const maxQuestions = integer(source.maxQuestions, 0);
  return {
    allowLate: bool(source.allowLate),
    countsTowardsGrade: bool(source.countsTowardsGrade, true),
    maxAttempts: maxAttempts >= 1 && maxAttempts <= 10 ? maxAttempts : 1,
    maxQuestions: maxQuestions >= 0 && maxQuestions <= 200 ? maxQuestions : 0,
    reminderEnabled: bool(source.reminderEnabled, timeDueMs > 0),
    // An unknown strategy is read as the authored order, never as a mixed run
    // the server did not actually draw.
    selectionStrategy: source.selectionStrategy === 'interleaved'
      ? 'interleaved'
      : source.selectionStrategy === 'shuffled'
        ? 'shuffled'
        : 'sequential',
  };
}

function gradeSummary(value: unknown): GradeSummary {
  const source = record(value);
  const method: GradeMethod = source.method === 'last'
    || source.method === 'average'
    ? source.method
    : 'best';
  const rawPercent = source.percent === null || source.percent === undefined
    ? null
    : finiteNumber(source.percent, Number.NaN);
  return {
    attemptCount: Math.max(0, integer(source.attemptCount)),
    method,
    percent: rawPercent === null || !Number.isFinite(rawPercent)
      ? null
      : Math.max(0, Math.min(100, rawPercent)),
  };
}

function attemptReference(source: JsonRecord): JsonRecord {
  const candidates = [
    source.activeAttempt,
    source.completedAttempt,
    source.attempt,
  ];
  for (const candidate of candidates) {
    const parsed = record(candidate);
    if (positiveInteger(parsed.id)) {
      return parsed;
    }
  }
  return {};
}

function assignmentSummary(
  value: unknown,
  completedHint = false,
  attemptOverride: JsonRecord = {},
): AssignmentSummary {
  const source = record(value);
  const attempt = Object.keys(attemptOverride).length > 0
    ? attemptOverride
    : attemptReference(source);
  const questionCount = Math.max(0, integer(
    source.questionCount,
    integer(record(source.progress).total),
  ));
  const completed = completedHint
    || bool(source.completed)
    || text(attempt.status) === 'completed';
  const answered = Math.max(0, Math.min(questionCount, integer(
    record(source.progress).answered,
    integer(
      attempt.answeredCount,
      completed ? questionCount : 0,
    ),
  )));
  const status = assignmentStatus(source.status);
  const timeOpenMs = milliseconds(source.timeOpenMs ?? source.timeOpen);
  const timeDueMs = milliseconds(source.timeDueMs ?? source.timeDue);
  const now = Date.now();
  const available = bool(
    source.available,
    status === 'open'
      && (timeOpenMs <= 0 || timeOpenMs <= now)
      && (timeDueMs <= 0 || timeDueMs >= now),
  );
  const attemptNumber = Math.max(0, integer(
    source.attemptNumber,
    integer(attempt.attemptNumber, positiveInteger(attempt.id) ? 1 : 0),
  ));
  const attemptsUsed = source.attemptsUsed === undefined
      || source.attemptsUsed === null
    ? attemptNumber
    : Math.max(0, integer(source.attemptsUsed));
  const maxAttempts = Math.max(1, Math.min(
    10,
    integer(source.maxAttempts, 1),
  ));
  return {
    attemptNumber,
    attemptsUsed,
    available,
    attemptId: positiveInteger(source.attemptId) || positiveInteger(attempt.id),
    canRetry: bool(
      source.canRetry,
      completed && available && attemptsUsed < maxAttempts,
    ),
    completed,
    countsTowardsGrade: bool(source.countsTowardsGrade, true),
    id: positiveInteger(source.id) || 0,
    maxAttempts,
    mode: mode(source.mode),
    name: text(source.name),
    overdue: bool(source.overdue, timeDueMs > 0 && timeDueMs < now),
    progress: {
      answered,
      total: questionCount,
    },
    selection: source.selection === 'due' ? 'due' : 'fixed',
    // An unknown strategy is read as the authored order — the safe direction:
    // it never claims a mixed run that the server did not draw.
    selectionStrategy: source.selectionStrategy === 'interleaved'
      ? 'interleaved'
      : source.selectionStrategy === 'shuffled'
        ? 'shuffled'
        : 'sequential',
    status,
    timeDueMs,
    timeOpenMs,
  };
}

function weeklyGoal(value: unknown): WeeklyGoal {
  const source = record(value);
  return {
    configured: bool(source.configured),
    progress: Math.max(0, integer(source.progress)),
    target: Math.max(1, integer(source.target, 20)),
    ...(milliseconds(source.weekEndMs ?? source.weekEnd) > 0
      ? {weekEndMs: milliseconds(source.weekEndMs ?? source.weekEnd)}
      : {}),
    ...(milliseconds(source.weekStartMs ?? source.weekStart) > 0
      ? {weekStartMs: milliseconds(source.weekStartMs ?? source.weekStart)}
      : {}),
  };
}

export function normaliseOverview(value: unknown): SelfStudyOverview {
  const source = record(value);
  return {
    canConfigureWeeklyGoal: bool(source.canConfigureWeeklyGoal),
    completedAssignments: records(source.completedAssignments).map(
      (assignment) => assignmentSummary(assignment, true),
    ),
    gradeSummary: gradeSummary(source.gradeSummary),
    openAssignments: records(source.openAssignments).map(
      (assignment) => assignmentSummary(assignment),
    ),
    serverTimeMs: milliseconds(source.serverTimeMs) || Date.now(),
    weeklyGoal: weeklyGoal(source.weeklyGoal),
  };
}

function question(value: unknown): LiveQuestion | null {
  const source = record(value);
  if (!positiveInteger(source.id)
      || text(source.questionToken) === ''
      || text(source.qtype) === '') {
    return null;
  }
  return source as unknown as LiveQuestion;
}

function projectedResult(value: unknown): QuestionResult | null {
  const source = record(value);
  const presented = question(source.question);
  if (!presented) {
    return null;
  }
  return {
    answer: liveAnswerFromPayload(source.answer),
    correct: typeof source.correct === 'boolean' ? source.correct : null,
    explanation: text(source.explanation),
    maxPoints: Math.max(0, integer(source.maxPoints)),
    points: Math.max(0, integer(source.points)),
    question: presented,
  };
}

function legacyResult(
  value: unknown,
  forcedDisclosure = false,
): QuestionResult | null {
  const source = record(value);
  const presented = question(source);
  if (!presented) {
    return null;
  }
  const submission = record(source.submission);
  const rowStatus = text(source.attemptQuestionStatus);
  const disclosed = forcedDisclosure
    || rowStatus === 'revealed'
    || rowStatus === 'submitted';
  if (!disclosed) {
    return null;
  }
  return {
    answer: liveAnswerFromPayload(submission.answer),
    correct: typeof submission.isCorrect === 'boolean'
      ? submission.isCorrect
      : null,
    explanation: text(source.explanation),
    maxPoints: Math.max(0, integer(submission.maxPoints)),
    points: Math.max(0, integer(submission.points)),
    question: presented,
  };
}

export function normaliseAttemptState(value: unknown): SelfStudyAttemptState {
  const source = record(value);
  const rawAttempt = record(source.attempt);
  const rawAssignment = record(source.assignment);
  const rawNavigation = record(source.navigation);
  const presentedQuestion = question(source.question);
  const currentIndex = Math.max(0, integer(
    source.currentIndex,
    integer(rawAttempt.currentIndex, integer(rawNavigation.currentIndex)),
  ));
  const total = Math.max(0, integer(
    source.total,
    integer(rawAttempt.total, presentedQuestion?.total || 0),
  ));
  const rawAnsweredIndices = Array.isArray(source.answeredIndices)
    ? source.answeredIndices
    : rawNavigation.answeredIndexes;
  const answeredIndices = Array.isArray(rawAnsweredIndices)
    ? rawAnsweredIndices
      .map((entry) => integer(entry, -1))
      .filter((entry) => entry >= 0 && entry < total)
    : [];
  const status = attemptStatus(source.status ?? rawAttempt.status);
  const studyMode = mode(source.mode ?? rawAttempt.mode ?? rawAssignment.mode);
  const legacySubmission = record(record(source.question).submission);
  const flatResult = projectedResult(source.result);
  const currentResult = flatResult || legacyResult(
    source.question,
    status === 'completed',
  );
  const flatResults = records(source.results)
    .map(projectedResult)
    .filter((entry): entry is QuestionResult => entry !== null);
  const legacyReviews = records(source.reviews)
    .map((entry) => legacyResult(entry, true))
    .filter((entry): entry is QuestionResult => entry !== null);
  const rawFlashcards = record(source.flashcards);
  const flatFlashcards = Object.keys(rawFlashcards).length > 0
    && Object.prototype.hasOwnProperty.call(rawFlashcards, 'known');
  const flashcardRound = flatFlashcards
    ? Math.max(0, integer(rawFlashcards.round))
    : Math.max(1, integer(rawFlashcards.round, 1));
  const flashcardRemaining = Math.max(0, integer(rawFlashcards.remaining));
  const attemptId = positiveInteger(source.attemptId)
    || positiveInteger(rawAttempt.id)
    || 0;
  const assignment = assignmentSummary(
    rawAssignment,
    status === 'completed',
    {
      answeredCount: integer(rawAttempt.answeredCount, answeredIndices.length),
      id: attemptId,
      status,
    },
  );
  assignment.progress = {
    answered: Math.max(
      assignment.progress.answered,
      Math.min(total, integer(rawAttempt.answeredCount, answeredIndices.length)),
    ),
    total,
  };
  return {
    answeredIndices,
    assignment,
    attemptId,
    canAnswer: bool(
      source.canAnswer,
      status === 'inprogress' && assignment.available,
    ),
    canFinish: bool(source.canFinish, status === 'inprogress'),
    currentIndex,
    flashcards: studyMode === 'flashcards'
      ? {
        known: Math.max(0, integer(
          rawFlashcards.known,
          integer(rawFlashcards.knownCount),
        )),
        repeat: flatFlashcards
          ? Math.max(0, integer(rawFlashcards.repeat))
          : flashcardRound > 1
            ? flashcardRemaining
            : Math.max(0, integer(rawFlashcards.repeatPending)),
        revealed: bool(
          rawFlashcards.revealed,
          text(record(source.question).attemptQuestionStatus) === 'revealed',
        ),
        round: flashcardRound,
        total: Math.max(0, integer(rawFlashcards.total, total)),
      }
      : null,
    gradeSummary: gradeSummary(source.gradeSummary),
    maxScore: Math.max(0, integer(source.maxScore, integer(rawAttempt.maxScore))),
    mode: studyMode,
    ownAnswer: liveAnswerFromPayload(
      Object.prototype.hasOwnProperty.call(source, 'ownAnswer')
        ? source.ownAnswer
        : legacySubmission.answer,
    ),
    question: presentedQuestion,
    result: flatResult
      ? flatResult
      : status === 'completed'
        ? null
        : currentResult,
    results: flatResults.length > 0
      ? flatResults
      : legacyReviews.length > 0
        ? legacyReviews
      : status === 'completed' && currentResult
        ? [currentResult]
        : [],
    reviewSummary: reviewSummary(source.reviewSummary),
    score: Math.max(0, integer(source.score, integer(rawAttempt.score))),
    serverTimeMs: milliseconds(source.serverTimeMs) || Date.now(),
    stateVersion: Math.max(0, integer(
      source.stateVersion,
      integer(rawAttempt.stateVersion),
    )),
    status,
    total,
  };
}

function teacherAssignment(value: unknown): TeacherAssignment {
  const source = record(value);
  const timeDueMs = milliseconds(source.timeDueMs ?? source.timeDue);
  const settings = assignmentSettings(source.settings, timeDueMs);
  return {
    ...assignmentSummary(source),
    countsTowardsGrade: settings.countsTowardsGrade,
    maxAttempts: settings.maxAttempts,
    multistageQuestionCount: Math.max(
      0,
      integer(source.multistageQuestionCount),
    ),
    participantCount: Math.max(0, integer(
      source.participantCount,
      integer(source.attemptCount),
    )),
    settings,
    timeModified: Math.max(0, integer(
      source.timeModified,
      milliseconds(source.timeModifiedMs) > 0
        ? Math.floor(milliseconds(source.timeModifiedMs) / 1000)
        : 0,
    )),
  };
}

export function normaliseTeacherAssignmentList(
  value: unknown,
): TeacherAssignmentList {
  const source = record(value);
  return {
    assignments: records(source.assignments).map(teacherAssignment),
    readyMultistageQuestionCount: Math.max(
      0,
      integer(source.readyMultistageQuestionCount),
    ),
    readyQuestionCount: Math.max(0, integer(source.readyQuestionCount)),
    serverTimeMs: milliseconds(source.serverTimeMs) || Date.now(),
  };
}
