/**
 * Stage-check types (F13).
 *
 * Taken over from mod_redewerkstatt (frontend/src/metrics/types.ts), reduced
 * to the pose half: the stage check has no microphone, no recorder and no
 * transcript, so the audio and transcript types of the template are absent by
 * design and not by omission.
 *
 * Every field here is a NUMBER OR A LABEL. There is deliberately no type for a
 * frame, a picture or a raw landmark that could be transported: what cannot be
 * expressed cannot accidentally be sent.
 */

export type MetricEventType = 'gazeaway' | 'handsidle';

export type MovementClass = 'ruhig' | 'lebendig' | 'unruhig';

export type PresentationMode = 'stehen' | 'sitzen';

export interface TimelinePoint {
  /** Whole second since recording start, starting at zero. */
  t: number;
  eye: 0 | 1;
  gest: 0 | 1;
  /** Horizontal hip (standing) or shoulder (sitting) movement in percent per second. */
  sway: number;
}

export interface MetricEvent {
  t: number;
  dur: number;
  type: MetricEventType;
}

export interface PoseMetrics {
  mode: PresentationMode;
  eyecontactpct: number;
  gesturescore: number;
  gestureactivpct: number;
  posturescore: number;
  movementscore: number;
  movementclass: MovementClass;
  visibilitypct: number;
  timeline: TimelinePoint[];
  events: MetricEvent[];
}

export type StageHint = 'ok' | 'stepback' | 'center' | 'no-pose';

export interface StageCheck {
  ready: boolean;
  personPresent: boolean;
  coreVisible: boolean;
  centered: boolean;
  hint: StageHint;
}

/** Everything the engine ever hands back to the page. */
export interface StageResult {
  durationSecs: number;
  metrics: PoseMetrics;
  /** 'GPU', 'CPU' or 'none' — diagnosis only, never a promise of quality. */
  delegate: StageDelegate;
}

export type StageDelegate = 'GPU' | 'CPU' | 'none';

export interface StageEngineOptions {
  /** Absolute URL of the addon's local MediaPipe directory. */
  assetsUrl: string;
  /** The <video> the camera stream is attached to. Never read out. */
  video: HTMLVideoElement;
  mode?: PresentationMode;
  allowGpu?: boolean;
  maxSeconds?: number;
  onHint?: (check: StageCheck) => void;
  onTick?: (elapsedSecs: number) => void;
  onFailure?: (code: StageFailureCode) => void;
}

export type StageFailureCode =
  | 'camera_denied'
  | 'camera_missing'
  | 'engine_unavailable';

export interface StageEngine {
  /** Ask for the camera and start the analysis loop. */
  start(): Promise<StageDelegate>;
  /** Begin measuring; the loop is already running. */
  beginRecording(): void;
  /** Stop measuring and return the aggregated numbers. */
  finishRecording(): StageResult;
  /** Release camera and engine. Idempotent. */
  close(): void;
}
