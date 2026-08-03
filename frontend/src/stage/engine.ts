/**
 * The stage-check engine (F13): camera in, numbers out.
 *
 * This module is the ONLY consumer of the camera in Quizgeist, and it is the
 * complete list of what happens to the picture:
 *
 *   1. `getUserMedia` hands over a MediaStream.
 *   2. The stream is attached to a <video> with `srcObject`. It is never read
 *      into a Blob, a canvas, an ImageBitmap or a data URL — none of those
 *      APIs is called anywhere in this bundle.
 *   3. Ten times a second the <video> element itself is handed to the local
 *      MediaPipe task, which returns landmarks. The landmarks are folded into
 *      running sums immediately and are not kept.
 *   4. `finishRecording()` returns numbers.
 *
 * There is no `fetch`, no `XMLHttpRequest`, no `FormData`, no `sendBeacon` and
 * no `MediaRecorder` in this file. The engine has no network side at all: the
 * page it is embedded in does the one JSON request, with the numbers from
 * step 4. That is a structural promise, not a policy — and the static gate
 * `clientNoMediaUpload` measures exactly it.
 */

import {loadPoseLandmarker, PoseAnalyzer} from './pose';
import type {
  StageCheck,
  StageDelegate,
  StageEngine,
  StageEngineOptions,
  StageResult,
} from './types';

const DEFAULT_MAX_SECONDS = 180;

/** Camera request: front camera, modest resolution, explicitly no audio. */
const VIDEO_CONSTRAINTS: MediaStreamConstraints = {
  audio: false,
  video: {
    facingMode: 'user',
    width: {ideal: 640},
    height: {ideal: 480},
    frameRate: {ideal: 24, max: 30},
  },
};

class stage_engine implements StageEngine {
  private stream: MediaStream | null = null;
  private analyzer: PoseAnalyzer | null = null;
  private delegate: StageDelegate = 'none';
  private startedAtMs: number | null = null;
  private tickTimer: number | null = null;
  private closed = false;

  constructor(private readonly options: StageEngineOptions) {}

  async start(): Promise<StageDelegate> {
    if (this.closed) {
      throw new Error('Stage engine is closed');
    }
    const media = navigator.mediaDevices;
    if (!media || typeof media.getUserMedia !== 'function') {
      this.options.onFailure?.('camera_missing');
      return 'none';
    }
    try {
      this.stream = await media.getUserMedia(VIDEO_CONSTRAINTS);
    } catch (error) {
      this.options.onFailure?.('camera_denied');
      return 'none';
    }
    // The one and only thing that ever happens to the stream.
    this.options.video.srcObject = this.stream;
    this.options.video.muted = true;
    this.options.video.playsInline = true;
    try {
      await this.options.video.play();
    } catch (error) {
      // Autoplay refusal is not fatal: the element still decodes frames once
      // the learner interacts, and the task reads the element, not a promise.
    }

    try {
      const loaded = await loadPoseLandmarker(this.options.assetsUrl, {
        minConfidence: 0.5,
      });
      this.delegate = this.options.allowGpu === false && loaded.delegate === 'GPU'
        ? 'CPU'
        : loaded.delegate;
      this.analyzer = new PoseAnalyzer(loaded.landmarker);
      this.analyzer.setMode(this.options.mode ?? 'stehen');
      this.analyzer.start(this.options.video, {
        onStageCheck: (check: StageCheck) => this.options.onHint?.(check),
      });
    } catch (error) {
      // Without the engine the stage check still works as a presentation
      // exercise — it just has no body-language feedback. The task stays
      // readable and answerable, which is the accessibility promise.
      this.delegate = 'none';
      this.options.onFailure?.('engine_unavailable');
    }
    return this.delegate;
  }

  beginRecording(): void {
    this.startedAtMs = performance.now();
    this.analyzer?.beginRecording(this.startedAtMs);
    this.stopTicking();
    this.tickTimer = window.setInterval(() => {
      this.options.onTick?.(this.elapsedSeconds());
    }, 1000);
  }

  finishRecording(): StageResult {
    this.stopTicking();
    const durationSecs = Math.min(
      Math.round(this.elapsedSeconds()),
      Math.max(1, this.options.maxSeconds ?? DEFAULT_MAX_SECONDS),
    );
    this.startedAtMs = null;
    const metrics = this.analyzer
      ? this.analyzer.finishRecording(durationSecs)
      : emptyMetrics(this.options.mode ?? 'stehen');
    return {durationSecs, metrics, delegate: this.delegate};
  }

  close(): void {
    if (this.closed) {
      return;
    }
    this.closed = true;
    this.stopTicking();
    this.analyzer?.close();
    this.analyzer = null;
    if (this.stream) {
      for (const track of this.stream.getTracks()) {
        track.stop();
      }
      this.stream = null;
    }
    this.options.video.srcObject = null;
  }

  private elapsedSeconds(): number {
    return this.startedAtMs === null
      ? 0
      : (performance.now() - this.startedAtMs) / 1000;
  }

  private stopTicking(): void {
    if (this.tickTimer !== null) {
      window.clearInterval(this.tickTimer);
      this.tickTimer = null;
    }
  }
}

/**
 * An all-zero result, used when the engine could not be created at all.
 *
 * It is deliberately a valid document: the learner presented, so the
 * presentation counts, and the report says honestly that nothing was measured.
 */
function emptyMetrics(mode: 'stehen' | 'sitzen'): StageResult['metrics'] {
  return {
    mode,
    eyecontactpct: 0,
    gesturescore: 0,
    gestureactivpct: 0,
    posturescore: 0,
    movementscore: 0,
    movementclass: 'ruhig',
    visibilitypct: 0,
    timeline: [],
    events: [],
  };
}

/**
 * Create one stage engine.
 *
 * @param options Engine options.
 * @returns The engine.
 */
export function createStageEngine(options: StageEngineOptions): StageEngine {
  return new stage_engine(options);
}
