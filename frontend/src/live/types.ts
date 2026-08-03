import type {QuizgeistInitConfig} from '../types';

export type LiveNameMode = 'real' | 'custom' | 'generated';

export type LiveMode = 'classic' | 'accuracy' | 'team' | 'security';

export type LivePhase =
  | 'lobby'
  | 'question'
  | 'reveal'
  | 'scoreboard'
  | 'podium'
  | 'ended'
  | 'aborted';

export type LiveQuestionType =
  | 'quiz'
  | 'truefalse'
  | 'shortanswer'
  | 'puzzle'
  | 'poll'
  | 'wordcloud'
  | 'scale'
  | 'slider'
  | 'pin'
  | 'reveal'
  | 'brainstorm'
  | 'open'
  | 'slide';

export type LivePointMode = 'standard' | 'double' | 'none';

export type LiveMediaMimeType =
  | 'image/png'
  | 'image/jpeg'
  | 'image/gif'
  | 'image/webp'
  | 'image/svg+xml'
  | 'video/mp4'
  | 'video/webm'
  | 'video/ogg'
  | 'audio/mp4'
  | 'audio/webm'
  | 'audio/mp3'
  | 'audio/mpeg'
  | 'audio/ogg'
  | 'audio/wav'
  | 'audio/x-wav'
  | 'application/ogg';

export type HostCommand =
  | 'start'
  | 'reveal'
  | 'scoreboard'
  | 'next'
  | 'previous'
  | 'skip'
  | 'abort'
  | 'end';

export interface LiveChoice {
  correct?: boolean;
  id: string;
  mediaMimeType?: LiveMediaMimeType | null;
  mediaUrl?: string | null;
  text: string;
}

export interface LivePuzzleItem extends LiveChoice {
}

export interface LiveBrainstormIdea {
  groupKey?: string | null;
  id: string;
  own?: boolean;
  text: string;
  votes?: number;
}

export interface LiveBrainstormGroup {
  ideas: LiveBrainstormIdea[];
  key: string;
  label: string;
  votes?: number | null;
}

export interface LiveReactionOption {
  count?: number;
  emoji: string;
  id: string;
  label: string;
}

export interface LivePolicyStage {
  key: string;
  labelKey: string;
}

export interface LivePolicyDescriptor {
  allowsMultipleSubmissions: boolean;
  canAdvance: boolean;
  currentStage: string;
  nextStageKey: string | null;
  nextStageLabelKey: string | null;
  showsCorrectness: boolean;
  stages: LivePolicyStage[];
}

export interface LiveQuestionTypeData {
  acceptedAnswers?: string[];
  attribution?: string;
  body?: string;
  bullets?: string[];
  canSubmit?: boolean;
  grid?: number;
  groups?: LiveBrainstormGroup[];
  ideas?: LiveBrainstormIdea[];
  interactionStage?: 'collect' | 'group' | 'vote' | 'done';
  items?: LivePuzzleItem[];
  layout?: 'title' | 'text-image' | 'bullets' | 'quote' | 'video' | 'fullscreen';
  max?: number;
  maxChars?: number;
  maxLabel?: string;
  maxVotes?: number;
  mediaMimeType?: LiveMediaMimeType | null;
  mediaUrl?: string | null;
  min?: number;
  minLabel?: string;
  moderation?: boolean;
  ownIdeaIds?: string[];
  ownReaction?: string | null;
  ownVoteIds?: string[];
  quote?: string;
  radius?: number;
  reactions?: boolean | LiveReactionOption[];
  reactionOptions?: LiveReactionOption[];
  revealSeconds?: number;
  step?: number;
  stepMs?: number;
  steps?: number;
  target?: number | {x: number; y: number};
  tileOrder?: number[];
  title?: string;
  tolerance?: number;
  typoTolerance?: boolean;
  x?: number;
  y?: number;
  [key: string]: unknown;
}

export interface LiveQuestion {
  allowsMultipleSubmissions?: boolean;
  /** F1: server-decided error-friendly framing for a question marked "new". */
  friendlyNew?: boolean;
  choices: LiveChoice[];
  id: number;
  index: number;
  interactionStage?: string;
  mediaMimeType?: LiveMediaMimeType | null;
  mediaUrl?: string | null;
  multiple: boolean;
  pointMode: LivePointMode;
  policyDescriptor?: LivePolicyDescriptor;
  questionToken: string;
  qtype: LiveQuestionType;
  questionText: string;
  responseType?: string;
  rootId: number;
  speechText?: string;
  timeLimit: number;
  total: number;
  typeData?: LiveQuestionTypeData;
  version: number;
}

export type LiveAnswer =
  | {choiceIds: string[]; kind?: 'choices'}
  | {kind: 'text'; text: string}
  // P11/F9: eine gesprochene Antwort ist eine Antwort ohne Text. Der Text wird
  // serverseitig nachgetragen, sobald das Transkript da ist.
  | {kind: 'clip'; clipId: number}
  | {kind: 'order'; orderIds: string[]}
  | {kind: 'number'; value: number}
  | {kind: 'pin'; x: number; y: number}
  | {kind: 'brainstormIdea'; text: string}
  | {groupKey: string; kind: 'brainstormVote'}
  // P11/F13: der Buehnen-Check meldet nur, WELCHER Bericht entstanden ist.
  // Die Kennzahlen sind laengst ueber ihren eigenen, geprueften Weg gegangen.
  | {kind: 'stage'; reportId: number}
  | {kind: 'reaction'; reaction: string};

export interface LivePlayer {
  accessoryKey?: string | null;
  avatarKey?: string | null;
  avatarUrl?: string | null;
  displayName: string;
  id: number;
  score: number;
  status?: string;
  streak: number;
  teamColor?: string | null;
  teamId?: number | string | null;
  teamName?: string | null;
}

export interface LiveDistributionEntry {
  choiceId: string;
  correct?: boolean;
  count: number;
  percent: number;
  [key: string]: unknown;
}

export interface LiveStanding {
  accessoryKey?: string | null;
  avatarKey?: string | null;
  avatarUrl?: string | null;
  delta?: number;
  displayName: string;
  playerId: number;
  rank: number;
  score: number;
  streak?: number;
  teamColor?: string | null;
  teamId?: number | string | null;
  teamName?: string | null;
}

export interface LiveTeamStanding {
  color?: string | null;
  delta?: number;
  id?: number | string;
  memberCount?: number;
  name?: string;
  rank: number;
  score: number;
  teamKey: string;
  teamName: string;
}

export interface LiveAggregate {
  kind: string;
  [key: string]: unknown;
}

export interface LiveRewardCatalogEntry {
  accessoryKeys?: string[];
  baseKey?: string;
  colorKey?: string;
  key: string;
  label?: string;
  unlockHint?: string;
  unlocked?: boolean;
}

export interface LiveTeamOption {
  color?: string;
  id: number | string;
  key?: string;
  memberCount?: number;
  name: string;
}

export interface LiveHostSetup {
  allowedModes?: LiveMode[];
  defaultMode?: LiveMode;
  freeTeams?: LiveTeamOption[];
  groupReadiness?: {
    groupCount: number;
    maxGroupCount: number;
    tooManyGroups: boolean;
    unassignedEnrolledCount: number;
  };
  moodleGroups?: LiveTeamOption[];
  security?: {
    enrolledOnly?: boolean;
    nameFilterEnabled?: boolean;
  };
  /** F1: the activity's stress-free works defaults for the setup form. */
  stressFree?: LiveStressFree;
}

export interface LiveRewards {
  accessories: Array<{
    key: string;
    label: string;
    unlocked: boolean;
  }>;
  avatars: string[];
  unlockedRewardKeys: string[];
}

export interface LiveTeamConfig {
  source: string;
  teams: LiveTeamOption[];
}

/**
 * F1 Stressarm-Standard. The server decides these four; the client only
 * renders the decision. `leaderboard` is informational — the ranking has
 * already been cut server-side before it reached this payload.
 */
export interface LiveStressFree {
  leaderboard?: 'own' | 'team' | 'full';
  pace?: 'timed' | 'even';
  soundEnabled?: boolean;
  timerVisible?: boolean;
}

export interface HostState extends LiveStressFree {
  aggregate?: LiveAggregate | null;
  aggregateRevision?: number;
  answerCount: number;
  currentIndex: number;
  distribution: LiveDistributionEntry[];
  /**
   * F5 Fehlkonzept-Radar. Carried by the HOST payload only — the player
   * projection does not contain the key at all, and neither the label of a
   * distractor (state_projector::poll_fields()).
   */
  hingeStatus?: string | null;
  interactionStage: string | null;
  joinCode: string;
  mode: LiveMode | string;
  nameMode: LiveNameMode;
  phase: LivePhase;
  phaseEndsAtMs: number;
  phaseStartedAtMs: number;
  playerCount: number;
  players: LivePlayer[];
  podium: LiveStanding[];
  question?: LiveQuestion | null;
  ranking: LiveStanding[];
  rewards?: LiveRewardCatalogEntry[];
  serverTimeMs: number;
  sessionId: number;
  stateVersion: number;
  teamPodium?: LiveTeamStanding[];
  teamConfig?: LiveTeamConfig | null;
  teamRanking?: LiveTeamStanding[];
  teams?: LiveTeamOption[];
  totalQuestions: number;
}

export interface HostStateResult {
  readiness?: {
    playableQuestionCount?: number;
    questionCount?: number;
    ready?: boolean;
  };
  setup?: LiveHostSetup;
  state: HostState | null;
}

export interface HostPollResult {
  aggregate?: LiveAggregate | null;
  aggregateRevision?: number;
  answerCount?: number;
  changed: boolean;
  distribution?: LiveDistributionEntry[];
  hingeStatus?: string | null;
  pollAfterMs: number;
  serverTimeMs: number;
  state?: HostState;
  stateVersion: number;
}

export interface LiveApiEnvelope<T> {
  action?: string;
  data?: T;
  error?: string;
  message?: string;
  ok: boolean;
}

export interface LiveErrorData {
  state?: HostState;
}

export interface LiveConfig extends QuizgeistInitConfig {
  ajaxUrl: string;
  brandIconUrl: string;
  cmid: number;
  containerId: string;
  sesskey: string;
  strings: Record<string, string>;
}

/**
 * F13 Bühnen-Check: where the addon's own bundle and its local assets live.
 *
 * ABSENT when the addon code package is not installed. The sub-mode then does
 * not exist at all instead of existing and being locked (P11_PLAN.md 2.6).
 * Both URLs point into `/mod/quizgeist/addon/buehne/` — the plugin's own
 * origin, never a CDN.
 */
export interface LiveStageConfig {
  assetsUrl: string;
  bundleUrl: string;
  maxSeconds: number;
}

/** F11a: one printable card set of this activity. */
export interface HostCardSet {
  cardCount: number;
  id: number;
  layout: string;
  name: string;
}

export interface HostConfig extends LiveConfig {
  /** Host-only navigation targets supplied by the Moodle page shell. */
  editorUrl?: string;
  reportsUrl?: string;
  tabsElementId?: string;
  /**
   * F11a Karten-Modus. ABSENT when the AI addon is not installed — the card
   * panel then does not exist at all rather than existing and being locked
   * (2.6: no locked bait). An EMPTY list means the addon is there but the
   * teacher has not created a card set yet.
   */
  cardSets?: HostCardSet[];
  /** F13: absent without the buehne addon code package. */
  stage?: LiveStageConfig;
  cardScanUploadUrl?: string;
  cardsPrintUrl?: string;
  playerUrlBase: string;
}

export function isHostConfig(config: QuizgeistInitConfig): config is HostConfig {
  return typeof config.ajaxUrl === 'string'
    && config.ajaxUrl !== ''
    && typeof config.brandIconUrl === 'string'
    && config.brandIconUrl !== ''
    && typeof config.cmid === 'number'
    && Number.isInteger(config.cmid)
    && config.cmid > 0
    && typeof config.containerId === 'string'
    && config.containerId !== ''
    && typeof config.playerUrlBase === 'string'
    && config.playerUrlBase !== ''
    && typeof config.sesskey === 'string'
    && config.sesskey !== ''
    && typeof config.strings === 'object'
    && config.strings !== null;
}
