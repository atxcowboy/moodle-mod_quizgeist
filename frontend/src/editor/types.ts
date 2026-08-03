import type {QuizgeistInitConfig, QuizgeistTtsConfig} from '../types';

export const QUESTION_TYPES = [
  'quiz',
  'truefalse',
  'shortanswer',
  'puzzle',
  'poll',
  'wordcloud',
  'scale',
  'slider',
  'pin',
  'reveal',
  'brainstorm',
  'open',
  'slide',
] as const;

export type QuestionType = typeof QUESTION_TYPES[number];
export type PointMode = 'standard' | 'double' | 'none';

export const AI_SOURCES = [
  'topic',
  'pdf',
  'pdf_questions',
  'url',
  'wikipedia',
  'slides',
  'handwriting',
] as const;

export type AiSource = typeof AI_SOURCES[number];

export const AI_FORMATS = [
  'quiz',
  'truefalse',
  'micro_lesson',
  'vocabulary',
  'presentation',
  'practice_test',
  'step_by_step',
] as const;

export type AiFormat = typeof AI_FORMATS[number];

export interface AnswerOption {
  correct?: boolean;
  id: string;
  media: string | null;
  text: string;
}

export interface PuzzleItem {
  id: string;
  media: string | null;
  text: string;
}

export interface QuizOptions {
  answers: AnswerOption[];
  media: string | null;
  multiple: boolean;
}

export interface TrueFalseOptions {
  correct: boolean;
  media: string | null;
}

export interface ShortAnswerOptions {
  acceptedAnswers: string[];
  media: string | null;
  typoTolerance: boolean;
}

export interface PuzzleOptions {
  items: PuzzleItem[];
  media: string | null;
}

export interface PollOptions {
  answers: AnswerOption[];
  media: string | null;
  multiple: boolean;
}

export interface WordcloudOptions {
  maxChars: number;
  media: string | null;
  moderation: boolean;
}

export interface ScaleOptions {
  maxLabel: string;
  media: string | null;
  minLabel: string;
  steps: number;
}

export interface SliderOptions {
  max: number;
  media: string | null;
  min: number;
  step: number;
  target: number;
  tolerance: number;
}

export interface PinOptions {
  hasTarget: boolean;
  media: string | null;
  radius: number;
  target: {
    x: number;
    y: number;
  };
}

export interface RevealOptions {
  acceptedAnswers: string[];
  grid: number;
  media: string | null;
  revealSeconds: number;
}

export interface BrainstormOptions {
  collectSeconds: number;
  grouping: 'manual' | 'ai';
  media: string | null;
  voteSeconds: number;
}

export interface OpenOptions {
  media: string | null;
  sampleAnswer: string;
}

export type SlideLayout =
  | 'title'
  | 'text-image'
  | 'bullets'
  | 'quote'
  | 'video'
  | 'fullscreen';

export interface SlideOptions {
  attribution: string;
  body: string;
  bullets: string[];
  layout: SlideLayout;
  media: string | null;
  quote: string;
  reactions: boolean;
  title: string;
}

export type QuestionOptions =
  | QuizOptions
  | TrueFalseOptions
  | ShortAnswerOptions
  | PuzzleOptions
  | PollOptions
  | WordcloudOptions
  | ScaleOptions
  | SliderOptions
  | PinOptions
  | RevealOptions
  | BrainstormOptions
  | OpenOptions
  | SlideOptions;

export interface MediaFile {
  filename: string;
  filepath?: string;
  mimetype?: string;
  path?: string;
  url: string;
}

export interface EditorQuestion {
  explanation: string;
  files: MediaFile[];
  id: number;
  options: QuestionOptions;
  pointmode: PointMode;
  qtype: QuestionType;
  questiontext: string;
  rootid: number;
  sortorder: number;
  status: string;
  timelimit: number;
  timemodified: number;
  validationErrors: ValidationErrors;
  version: number;
}

export interface StructuredValidationError {
  code: string;
  field: string;
  message?: string;
}

export type ValidationErrors =
  | Array<string | StructuredValidationError>
  | Record<string, string | string[]>;

export interface AiBootstrap {
  acceptedDocuments: string[];
  acceptedVision: string[];
  aiSourcePickerUrl: string;
  available: boolean;
  entitlementStatus: string;
  fallbackAvailable: boolean;
  formats: AiFormat[];
  gatewayAvailable: boolean;
  installed: boolean;
  managedServerInstalled: boolean;
  maxPdfPages: number;
  maxQuestions: number;
  notice: string;
  outboundFetchAllowed: boolean;
  visionAvailable: boolean;
}

export interface AiDraftFile {
  filename: string;
  filesize?: number;
  mimetype?: string;
  path?: string;
  url?: string;
}

export interface AiDraftItem {
  files: AiDraftFile[];
  itemId: string;
  question: EditorQuestion;
  status: string;
  validationErrors: ValidationErrors;
}

export interface AiDraft {
  expiresAt: number | string | null;
  format: AiFormat;
  items: AiDraftItem[];
  kind: AiSource;
  origin: string;
  status: string;
  title: string;
  token: string;
  warnings: string[];
}

export interface AiExplanationDraft {
  expiresAt: number | string | null;
  explanation: string;
  origin: string;
  questionId: number;
  status: 'draft';
  token: string;
  warnings: string[];
}

export interface AiSourceSelection {
  draftItemId: string;
  file: {
    filename: string;
    filesize?: number;
    mimetype?: string;
  };
  purpose: AiSource;
  sourceToken: string;
}

export interface EditorActivity {
  allowbacktrack: boolean;
  background: MediaFile[];
  id: number;
  logo: MediaFile[];
  name: string;
  season: string;
  theme: string;
  timemodified: number;
}

export interface EditorBootstrap {
  activity: EditorActivity;
  ai?: Partial<AiBootstrap> & {
    sourcePickerUrl?: string;
  };
  invalidQuestionCount?: number;
  mediaPickerUrl?: string;
  questionDefaults: Partial<Record<QuestionType, Record<string, unknown> & {
    options: QuestionOptions;
  }>>;
  questions: EditorQuestion[];
  liveSupportedTypes: string[];
  seasons?: string[];
  supportedTypes?: string[];
  themes?: string[];
  tts?: {
    available?: boolean;
    defaultVoiceId?: number;
    speakUrl?: string | null;
    voices?: QuizgeistTtsConfig['voices'];
  };
}

export interface TemplateSummary {
  canDelete: boolean;
  description: string;
  id: number;
  name: string;
  questionCount: number;
  tags: string[];
  theme: string;
  timemodified: number;
}

export interface EditorConfig extends QuizgeistInitConfig {
  ajaxUrl: string;
  cmid: number;
  containerId: string;
  hostUrl: string;
  initialView: 'editor' | 'templates';
  kahootImportUrl: string;
  mediaUrl: string;
  sesskey: string;
  strings: Record<string, string>;
  tts: QuizgeistTtsConfig;
}

export interface ApiEnvelope<T> {
  action?: string;
  data: T;
  error?: string;
  message?: string;
  ok: boolean;
}
