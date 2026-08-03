import type {LiveAnswer, LiveConfig, LiveQuestion} from '../live/types';

// P11/F12: `speaking` ist der fuenfte Zuweisungsmodus. Die Liste spiegelt
// \mod_quizgeist\local\selfstudy\assignment_settings::MODES.
export const STUDY_MODES = [
  'solo', 'practice', 'test', 'flashcards', 'speaking',
] as const;
export type StudyMode = typeof STUDY_MODES[number];

/**
 * Modes that cannot work without the AI addon.
 *
 * Mirrors \mod_quizgeist\local\selfstudy\assignment_settings::AI_MODES.
 * Kept as data on both sides so the teacher form and the server refuse the
 * same set — [P11-C4-O4].
 */
export const AI_STUDY_MODES: readonly StudyMode[] = ['speaking'];

export type AssignmentStatus = 'draft' | 'open' | 'closed' | 'archived';
export type AttemptStatus = 'inprogress' | 'completed' | 'abandoned';

export interface SelfStudyConfig extends LiveConfig {
  assignmentId: number;
  attemptId: number;
  canCreate: boolean;
  initialView: 'assignments' | 'attempt' | 'live' | 'overview';
  overviewUrl: string;
  playerUrlBase: string;
}

export interface AssignmentProgress {
  answered: number;
  total: number;
}

export interface AssignmentSettings {
  allowLate: boolean;
  countsTowardsGrade: boolean;
  maxAttempts: number;
  maxQuestions: number;
  reminderEnabled: boolean;
  selectionStrategy: SelectionStrategy;
}

export interface AssignmentSummary {
  attemptNumber: number;
  attemptsUsed: number;
  available: boolean;
  attemptId: number | null;
  canRetry: boolean;
  completed: boolean;
  countsTowardsGrade: boolean;
  id: number;
  maxAttempts: number;
  mode: StudyMode;
  name: string;
  overdue: boolean;
  progress: AssignmentProgress;
  selection: AssignmentSelection;
  selectionStrategy: SelectionStrategy;
  status: AssignmentStatus;
  timeDueMs: number;
  timeOpenMs: number;
}

export type AssignmentSelection = 'fixed' | 'due';

export const STRATEGIES = [
  'sequential',
  'shuffled',
  'interleaved',
] as const;

export type SelectionStrategy = typeof STRATEGIES[number];

export interface WeeklyGoal {
  configured: boolean;
  progress: number;
  target: number;
  weekEndMs?: number;
  weekStartMs?: number;
}

export type GradeMethod = 'best' | 'last' | 'average';

export interface GradeSummary {
  attemptCount: number;
  method: GradeMethod;
  percent: number | null;
}

export interface SelfStudyOverview {
  canConfigureWeeklyGoal: boolean;
  completedAssignments: AssignmentSummary[];
  gradeSummary: GradeSummary;
  openAssignments: AssignmentSummary[];
  serverTimeMs: number;
  weeklyGoal: WeeklyGoal;
}

export interface FlashcardProgress {
  known: number;
  repeat: number;
  revealed: boolean;
  round: number;
  total: number;
}

export interface QuestionResult {
  answer: LiveAnswer | null;
  correct: boolean | null;
  explanation: string;
  maxPoints: number;
  points: number;
  question: LiveQuestion;
}

/**
 * F8 Erklär-Geist: the closing screen "Alle Lösungswege".
 *
 * `available` is a server decision. With policy `never` it stays false and
 * no entry was ever sent — there is nothing for a client to un-hide.
 */
export interface ReviewSummary {
  available: boolean;
  deferred: boolean;
  entries: Array<{
    explanation: string;
    index: number;
    questionText: string;
  }>;
  policy: string;
  total: number;
  withExplanation: number;
}

export interface SelfStudyAttemptState {
  answeredIndices: number[];
  assignment: AssignmentSummary;
  attemptId: number;
  canAnswer: boolean;
  canFinish: boolean;
  currentIndex: number;
  flashcards: FlashcardProgress | null;
  gradeSummary: GradeSummary;
  maxScore: number;
  mode: StudyMode;
  ownAnswer: LiveAnswer | null;
  question: LiveQuestion | null;
  result: QuestionResult | null;
  results: QuestionResult[];
  reviewSummary: ReviewSummary | null;
  score: number;
  serverTimeMs: number;
  stateVersion: number;
  status: AttemptStatus;
  total: number;
}

export interface TeacherAssignment extends AssignmentSummary {
  multistageQuestionCount: number;
  participantCount: number;
  settings: AssignmentSettings;
  timeModified: number;
}

export interface TeacherAssignmentList {
  assignments: TeacherAssignment[];
  readyMultistageQuestionCount: number;
  readyQuestionCount: number;
  serverTimeMs: number;
}

export interface AssignmentInput {
  id?: number;
  mode: StudyMode;
  name: string;
  // F3: 'due' friert Wurzeln statt Versionen ein. Der Server entscheidet das
  // ueber die Aktion; dieses Feld steuert nur, welche Aktion gerufen wird.
  selection?: AssignmentSelection;
  settings: AssignmentSettings;
  status: 'draft' | 'open';
  timeDue: number;
  timeModified?: number;
  timeOpen: number;
}

export interface StateResult {
  state: SelfStudyAttemptState;
}

export function isStudyMode(value: unknown): value is StudyMode {
  return typeof value === 'string'
    && (STUDY_MODES as readonly string[]).includes(value);
}
