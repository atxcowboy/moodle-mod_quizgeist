/**
 * The short-clip channel as the server describes it (P11/U3).
 *
 * `canRecord` follows 2.6: without the AI addon the recording button does not
 * appear at all, because a recording nobody can transcribe is a locked bait.
 * `canTranscribe` is the licence half of the same question — false there means
 * the recording is stored and playable and simply stays without text.
 */
export interface QuizgeistClipConfig {
  uploadUrl: string;
  playUrlBase: string;
  canRecord: boolean;
  canTranscribe: boolean;
  maxBytes: number;
  maxSeconds: number;
  language: string;
  retentionDays: number;
}

export interface QuizgeistInitConfig {
  ajaxUrl?: string;
  assignmentId?: number;
  bootstrapAction?: string;
  brandIconUrl?: string;
  clips?: QuizgeistClipConfig;
  hostUrl?: string;
  capabilities?: {
    host?: boolean;
    manage?: boolean;
    play?: boolean;
    viewReports?: boolean;
  };
  cmid?: number;
  containerId?: string;
  dataAction?: string;
  exportUrl?: string;
  features?: {
    ai?: QuizgeistFeatureAvailability;
    /** P11/F13 Bühnen-Check. */
    buehne?: QuizgeistFeatureAvailability;
    modes?: QuizgeistFeatureAvailability;
    qtypes?: QuizgeistFeatureAvailability;
    reports?: QuizgeistFeatureAvailability;
    selfstudy?: QuizgeistFeatureAvailability;
  };
  initialJoinCode?: string;
  initialView?:
    | 'assignments'
    | 'attempt'
    | 'editor'
    | 'live'
    | 'overview'
    | 'reports'
    | 'templates';
  instanceId?: number;
  kahootImportUrl?: string;
  locale?: string;
  mediaUrl?: string;
  attemptId?: number;
  overviewUrl?: string;
  playerUrlBase?: string;
  route?: 'host' | 'manage' | 'play' | 'report';
  season?: string;
  sesskey?: string;
  strings?: Record<string, string>;
  theme?: string;
  tts?: QuizgeistTtsConfig;
  userId?: number;
  viewerKind?: 'student' | 'teacher';
}

export interface QuizgeistFeatureAvailability {
  canCreate?: boolean;
  canUseExisting?: boolean;
  diagnosis?: string;
  installed?: boolean;
  status?: string;
}

export interface QuizgeistTtsVoice {
  gender?: string;
  id: number;
  label: string;
  lang?: string;
  region?: string;
}

export interface QuizgeistTtsConfig {
  available: boolean;
  defaultVoiceId: number;
  speakUrl: string;
  voices: QuizgeistTtsVoice[];
}

export interface QuizgeistApp {
  init(config?: QuizgeistInitConfig): void;
}
