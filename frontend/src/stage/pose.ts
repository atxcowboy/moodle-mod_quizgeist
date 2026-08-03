/**
 * Body-language analysis of the stage check (F13) — client-side only.
 *
 * ÜBERNAHME, KEIN NEUBAU. Diese Datei ist die auf diesem Server erprobte
 * Fassung aus mod_redewerkstatt (frontend/src/metrics/pose.ts), unverändert
 * bis auf diesen Kopf und den Typ-Import: PoseAnalyzer, PoseMetricsAccumulator,
 * checkStage(), WeightedSeries und SegmentTracker samt ihrer Konstanten
 * (SAMPLE_INTERVAL_MS 100, VISIBILITY_THRESHOLD 0.5,
 * MAX_CONTINUOUS_GAP_SECONDS 0.4, MIN_SHOULDER_WIDTH 0.05,
 * WRIST_ACTIVE_SPEED 0.25).
 *
 * Genau EIN MediaPipe-Task: PoseLandmarker, runningMode 'VIDEO', numPoses 1,
 * outputSegmentationMasks false, Delegate GPU -> CPU -> ohne Pose. Kein
 * FaceLandmarker, kein HandLandmarker.
 *
 * Es gibt in dieser Datei keinen fetch, kein XMLHttpRequest, kein FormData,
 * keinen MediaRecorder, kein toBlob/toDataURL/getImageData und kein
 * captureStream. Das Videobild wird gelesen und verworfen; nach draußen geht
 * ausschließlich das Zahlenwerk aus finish().
 *
 * @license Apache-2.0 für die aufgerufene Bibliothek @mediapipe/tasks-vision
 *          (siehe THIRD_PARTY_NOTICES.md); dieser Code steht unter GPL v3+.
 */
import {
  FilesetResolver,
  PoseLandmarker,
  type NormalizedLandmark,
} from '@mediapipe/tasks-vision';

import type {
  MetricEvent,
  MovementClass,
  PoseMetrics,
  PresentationMode,
  StageCheck,
  TimelinePoint,
} from './types';

const VISIBILITY_THRESHOLD = 0.5;
const SAMPLE_INTERVAL_MS = 100;
const MAX_CONTINUOUS_GAP_SECONDS = 0.4;
const MIN_SHOULDER_WIDTH = 0.05;
const WRIST_ACTIVE_SPEED = 0.25;
const STANDING_CORE_INDICES = [0, 11, 12, 23, 24] as const;
const SITTING_CORE_INDICES = [0, 11, 12, 13, 14, 15, 16] as const;
const TRACKING_BASE_INDICES = [0, 11, 12] as const;

const INDEX = {
  nose: 0,
  leftEye: 2,
  rightEye: 5,
  leftEar: 7,
  rightEar: 8,
  leftShoulder: 11,
  rightShoulder: 12,
  leftElbow: 13,
  rightElbow: 14,
  leftWrist: 15,
  rightWrist: 16,
  leftHip: 23,
  rightHip: 24,
} as const;

interface TrackedPoint {
  x: number;
  y: number;
  t: number;
}

interface WristObservation {
  visible: boolean;
  aboveGestureLine: boolean;
  aboveWaist: boolean;
  speed: number | null;
}

interface TimelineBucket {
  eyeWeight: number;
  eyeGoodWeight: number;
  gestureWeight: number;
  gestureActiveWeight: number;
  movementDistance: number;
  movementTime: number;
}

class WeightedSeries {
  private weight = 0;
  private sum = 0;
  private sumSquares = 0;

  add(value: number, weight: number): void {
    if (!Number.isFinite(value) || !Number.isFinite(weight) || weight <= 0) {
      return;
    }
    this.weight += weight;
    this.sum += value * weight;
    this.sumSquares += value * value * weight;
  }

  reset(): void {
    this.weight = 0;
    this.sum = 0;
    this.sumSquares = 0;
  }

  hasValues(): boolean {
    return this.weight > 0;
  }

  mean(): number {
    return this.weight > 0 ? this.sum / this.weight : 0;
  }

  standardDeviation(): number {
    if (this.weight <= 0) {
      return 0;
    }
    const mean = this.mean();
    return Math.sqrt(Math.max(0, this.sumSquares / this.weight - mean * mean));
  }
}

class SegmentTracker {
  private readonly events: MetricEvent[] = [];
  private start: number | null = null;

  constructor(
    private readonly type: 'gazeaway' | 'handsidle',
    private readonly minimumDuration: number,
  ) {}

  push(bad: boolean | undefined, time: number): void {
    if (bad === true) {
      if (this.start === null) {
        this.start = time;
      }
      return;
    }

    this.closeAt(time);
  }

  reset(): void {
    this.events.length = 0;
    this.start = null;
  }

  result(endTime: number): MetricEvent[] {
    const result = this.events.map((event) => ({...event}));
    if (this.start !== null && endTime - this.start >= this.minimumDuration) {
      result.push({
        t: this.start,
        dur: endTime - this.start,
        type: this.type,
      });
    }
    return result;
  }

  private closeAt(time: number): void {
    if (this.start === null) {
      return;
    }

    const duration = Math.max(0, time - this.start);
    if (duration >= this.minimumDuration) {
      this.events.push({t: this.start, dur: duration, type: this.type});
    }
    this.start = null;
  }
}

export interface LoadedPoseLandmarker {
  landmarker: PoseLandmarker;
  delegate: 'GPU' | 'CPU';
}

export interface PoseLoaderOptions {
  canvas?: HTMLCanvasElement | OffscreenCanvas;
  minConfidence?: number;
}

export async function loadPoseLandmarker(
  assetsUrl: string,
  options: PoseLoaderOptions = {},
): Promise<LoadedPoseLandmarker> {
  const baseUrl = assetsUrl.replace(/\/+$/, '');
  const vision = await FilesetResolver.forVisionTasks(`${baseUrl}/wasm`);
  const modelAssetPath = `${baseUrl}/pose_landmarker_lite.task`;
  const minConfidence = clamp(finite(options.minConfidence, 0.5), 0, 1);
  const common = {
    runningMode: 'VIDEO' as const,
    numPoses: 1,
    outputSegmentationMasks: false,
    minPoseDetectionConfidence: minConfidence,
    minPosePresenceConfidence: minConfidence,
    minTrackingConfidence: minConfidence,
  };

  try {
    const landmarker = await PoseLandmarker.createFromOptions(vision, {
      ...common,
      canvas: options.canvas,
      baseOptions: {modelAssetPath, delegate: 'GPU'},
    });
    return {landmarker, delegate: 'GPU'};
  } catch {
    const landmarker = await PoseLandmarker.createFromOptions(vision, {
      ...common,
      baseOptions: {modelAssetPath, delegate: 'CPU'},
    });
    return {landmarker, delegate: 'CPU'};
  }
}

export function checkStage(
  landmarks: readonly NormalizedLandmark[] | null | undefined,
  mode: PresentationMode = 'stehen',
): StageCheck {
  const personPresent = isVisible(landmarks?.[INDEX.nose]);
  const selectedMode = normaliseMode(mode);
  const coreVisible = hasVisibleStageCore(landmarks, selectedMode);
  if (!personPresent) {
    return {
      ready: false,
      personPresent: false,
      coreVisible: false,
      centered: false,
      hint: 'no-pose',
    };
  }

  if (!coreVisible || !landmarks) {
    return {
      ready: false,
      personPresent: true,
      coreVisible: false,
      centered: false,
      hint: 'stepback',
    };
  }

  const shoulderWidth = distanceX(landmarks[INDEX.leftShoulder], landmarks[INDEX.rightShoulder]);
  const shoulderMidX = midpointX(landmarks[INDEX.leftShoulder], landmarks[INDEX.rightShoulder]);
  if (selectedMode === 'sitzen') {
    const centered = Math.abs(shoulderMidX - 0.5) <= 0.18;
    const tooClose = shoulderWidth > 0.65
      || landmarks[INDEX.nose].y < 0.025
      || Math.max(
        landmarks[INDEX.leftShoulder].y,
        landmarks[INDEX.rightShoulder].y,
      ) > 0.985;

    return {
      ready: centered && !tooClose,
      personPresent: true,
      coreVisible: true,
      centered,
      hint: tooClose ? 'stepback' : centered ? 'ok' : 'center',
    };
  }

  const hipWidth = distanceX(landmarks[INDEX.leftHip], landmarks[INDEX.rightHip]);
  const hipMidX = midpointX(landmarks[INDEX.leftHip], landmarks[INDEX.rightHip]);
  const centerX = (shoulderMidX + hipMidX) / 2;
  const centered = Math.abs(centerX - 0.5) <= 0.18;
  const tooClose = shoulderWidth > 0.65
    || hipWidth > 0.65
    || landmarks[INDEX.nose].y < 0.025
    || Math.max(landmarks[INDEX.leftHip].y, landmarks[INDEX.rightHip].y) > 0.985;

  return {
    ready: centered && !tooClose,
    personPresent: true,
    coreVisible: true,
    centered,
    hint: tooClose ? 'stepback' : centered ? 'ok' : 'center',
  };
}

export class PoseMetricsAccumulator {
  private mode: PresentationMode;
  private lastElapsed: number | null = null;
  private attemptedWeight = 0;
  private validWeight = 0;
  private eyeWeight = 0;
  private eyeGoodWeight = 0;
  private gestureWeight = 0;
  private gestureActiveWeight = 0;
  private movementDistance = 0;
  private movementTime = 0;
  private leftWrist: TrackedPoint | null = null;
  private rightWrist: TrackedPoint | null = null;
  private movementMidpoint: TrackedPoint | null = null;
  private readonly shoulderAngles = new WeightedSeries();
  private readonly headAngles = new WeightedSeries();
  private readonly nosePositions = new WeightedSeries();
  private readonly shoulderWidths = new WeightedSeries();
  private readonly timeline = new Map<number, TimelineBucket>();
  private readonly gazeSegments = new SegmentTracker('gazeaway', 0.75);
  private readonly idleHandSegments = new SegmentTracker('handsidle', 8);

  constructor(mode: PresentationMode = 'stehen') {
    this.mode = normaliseMode(mode);
  }

  setMode(mode: PresentationMode): void {
    const selectedMode = normaliseMode(mode);
    if (selectedMode === this.mode) {
      return;
    }
    this.mode = selectedMode;
    this.reset();
  }

  reset(): void {
    this.lastElapsed = null;
    this.attemptedWeight = 0;
    this.validWeight = 0;
    this.eyeWeight = 0;
    this.eyeGoodWeight = 0;
    this.gestureWeight = 0;
    this.gestureActiveWeight = 0;
    this.movementDistance = 0;
    this.movementTime = 0;
    this.leftWrist = null;
    this.rightWrist = null;
    this.movementMidpoint = null;
    this.shoulderAngles.reset();
    this.headAngles.reset();
    this.nosePositions.reset();
    this.shoulderWidths.reset();
    this.timeline.clear();
    this.gazeSegments.reset();
    this.idleHandSegments.reset();
  }

  add(
    landmarks: readonly NormalizedLandmark[] | null | undefined,
    elapsedSeconds: number,
  ): void {
    const elapsed = Math.max(
      0,
      this.lastElapsed ?? 0,
      finite(elapsedSeconds, this.lastElapsed ?? 0),
    );
    const rawDelta = this.lastElapsed === null ? 0.1 : elapsed - this.lastElapsed;
    const continuous = rawDelta > 0 && rawDelta <= MAX_CONTINUOUS_GAP_SECONDS;
    const weight = continuous ? clamp(rawDelta, 0.04, 0.25) : 0.1;
    this.lastElapsed = elapsed;
    this.attemptedWeight += rawDelta > 0 ? rawDelta : 0.1;

    const bucket = this.bucketFor(elapsed);
    if (!hasVisibleTrackingBase(landmarks) || !landmarks) {
      this.validTrackingInterrupted(elapsed);
      return;
    }

    const metricCoreVisible = hasVisibleMetricCore(landmarks, this.mode);
    if (metricCoreVisible) {
      this.validWeight += weight;
    }
    const shoulderWidth = distanceX(
      landmarks[INDEX.leftShoulder],
      landmarks[INDEX.rightShoulder],
    );
    const shoulderLine = (
      landmarks[INDEX.leftShoulder].y + landmarks[INDEX.rightShoulder].y
    ) / 2;
    const waistLine = shoulderLine + 0.9 * shoulderWidth;
    const hipsVisible = isVisible(landmarks[INDEX.leftHip])
      && isVisible(landmarks[INDEX.rightHip]);
    const gestureLine = hipsVisible
      ? (landmarks[INDEX.leftHip].y + landmarks[INDEX.rightHip].y) / 2
      : waistLine;

    if (this.mode === 'sitzen' || metricCoreVisible) {
      this.addEyeObservation(landmarks, elapsed, weight, bucket);
      this.addPostureObservation(landmarks, shoulderWidth, weight);
    } else {
      // Preserve the standing-mode legacy gate when hips disappear. Only the
      // explicitly required waist-line gesture fallback continues for this sample.
      this.gazeSegments.push(undefined, elapsed);
    }

    if (shoulderWidth >= MIN_SHOULDER_WIDTH) {
      const left = this.observeWrist(
        landmarks[INDEX.leftWrist],
        this.leftWrist,
        shoulderWidth,
        gestureLine,
        waistLine,
        elapsed,
        continuous,
      );
      const right = this.observeWrist(
        landmarks[INDEX.rightWrist],
        this.rightWrist,
        shoulderWidth,
        gestureLine,
        waistLine,
        elapsed,
        continuous,
      );
      this.leftWrist = left.point;
      this.rightWrist = right.point;
      this.addGestureObservation(left.observation, right.observation, elapsed, weight, bucket);
    } else {
      this.leftWrist = null;
      this.rightWrist = null;
      this.idleHandSegments.push(undefined, elapsed);
    }

    this.addMovementObservation(landmarks, elapsed, continuous, bucket);
  }

  finish(durationSeconds: number): PoseMetrics {
    const requestedDuration = Math.max(0, finite(durationSeconds, 0));
    const duration = requestedDuration > 0 ? requestedDuration : Math.max(0, this.lastElapsed ?? 0);
    const gesturePercent = percent(this.gestureActiveWeight, this.gestureWeight);
    const movementSpeed = this.movementTime > 0
      ? 100 * this.movementDistance / this.movementTime
      : 0;

    const events = [
      ...this.gazeSegments.result(duration),
      ...this.idleHandSegments.result(duration),
    ]
      .map((event) => sanitiseEvent(event, duration))
      .filter((event): event is MetricEvent => event !== null)
      .sort((a, b) => a.t - b.t);

    return {
      mode: this.mode,
      eyecontactpct: round(clamp(percent(this.eyeGoodWeight, this.eyeWeight), 0, 100), 1),
      gesturescore: round(gestureScore(gesturePercent), 1),
      gestureactivpct: round(clamp(gesturePercent, 0, 100), 1),
      posturescore: round(this.postureScore(), 1),
      movementscore: round(
        this.movementTime > 0 ? movementScore(movementSpeed, this.mode) : 0,
        1,
      ),
      movementclass: movementClass(movementSpeed, this.mode),
      visibilitypct: round(clamp(percent(this.validWeight, this.attemptedWeight), 0, 100), 1),
      timeline: this.finishTimeline(duration),
      events,
    };
  }

  private validTrackingInterrupted(elapsed: number): void {
    this.leftWrist = null;
    this.rightWrist = null;
    this.movementMidpoint = null;
    this.gazeSegments.push(undefined, elapsed);
    this.idleHandSegments.push(undefined, elapsed);
  }

  private addEyeObservation(
    landmarks: readonly NormalizedLandmark[],
    elapsed: number,
    weight: number,
    bucket: TimelineBucket,
  ): void {
    const required = [INDEX.nose, INDEX.leftEye, INDEX.rightEye, INDEX.leftEar, INDEX.rightEar];
    if (!required.every((index) => isVisible(landmarks[index]))) {
      this.gazeSegments.push(undefined, elapsed);
      return;
    }

    const leftEar = landmarks[INDEX.leftEar];
    const rightEar = landmarks[INDEX.rightEar];
    const earDistance = distanceX(leftEar, rightEar);
    if (earDistance <= 0.02) {
      this.gazeSegments.push(undefined, elapsed);
      return;
    }

    const nose = landmarks[INDEX.nose];
    const earMidX = midpointX(leftEar, rightEar);
    const eyeLineY = (landmarks[INDEX.leftEye].y + landmarks[INDEX.rightEye].y) / 2;
    const yawOffset = (nose.x - earMidX) / earDistance;
    const pitchRatio = (nose.y - eyeLineY) / earDistance;
    const facing = Math.abs(yawOffset) < 0.25 && pitchRatio < 0.45;

    this.eyeWeight += weight;
    bucket.eyeWeight += weight;
    if (facing) {
      this.eyeGoodWeight += weight;
      bucket.eyeGoodWeight += weight;
    }
    this.gazeSegments.push(!facing, elapsed);
  }

  private addPostureObservation(
    landmarks: readonly NormalizedLandmark[],
    shoulderWidth: number,
    weight: number,
  ): void {
    const shoulderAngle = lineAngleDegrees(
      landmarks[INDEX.leftShoulder],
      landmarks[INDEX.rightShoulder],
    );
    if (shoulderAngle !== null) {
      this.shoulderAngles.add(shoulderAngle, weight);
    }

    let headAngle: number | null = null;
    if (isVisible(landmarks[INDEX.leftEar]) && isVisible(landmarks[INDEX.rightEar])) {
      headAngle = lineAngleDegrees(landmarks[INDEX.leftEar], landmarks[INDEX.rightEar]);
    } else if (isVisible(landmarks[INDEX.leftEye]) && isVisible(landmarks[INDEX.rightEye])) {
      headAngle = lineAngleDegrees(landmarks[INDEX.leftEye], landmarks[INDEX.rightEye]);
    }
    if (headAngle !== null) {
      this.headAngles.add(headAngle, weight);
    }

    this.nosePositions.add(landmarks[INDEX.nose].x, weight);
    if (shoulderWidth >= MIN_SHOULDER_WIDTH) {
      this.shoulderWidths.add(shoulderWidth, weight);
    }
  }

  private observeWrist(
    wrist: NormalizedLandmark,
    previous: TrackedPoint | null,
    shoulderWidth: number,
    gestureLine: number,
    waistLine: number,
    elapsed: number,
    continuous: boolean,
  ): {observation: WristObservation; point: TrackedPoint | null} {
    if (!isVisible(wrist)) {
      return {
        observation: {
          visible: false,
          aboveGestureLine: false,
          aboveWaist: false,
          speed: null,
        },
        point: null,
      };
    }

    const x = previous && continuous ? 0.45 * wrist.x + 0.55 * previous.x : wrist.x;
    const y = previous && continuous ? 0.45 * wrist.y + 0.55 * previous.y : wrist.y;
    let speed: number | null = null;
    if (previous && continuous) {
      const delta = elapsed - previous.t;
      if (delta > 0) {
        speed = Math.hypot(x - previous.x, y - previous.y) / shoulderWidth / delta;
      }
    }

    return {
      observation: {
        visible: true,
        aboveGestureLine: wrist.y < gestureLine,
        aboveWaist: wrist.y < waistLine,
        speed: speed !== null && Number.isFinite(speed) ? speed : null,
      },
      point: {x, y, t: elapsed},
    };
  }

  private addGestureObservation(
    left: WristObservation,
    right: WristObservation,
    elapsed: number,
    weight: number,
    bucket: TimelineBucket,
  ): void {
    const leftActive = left.aboveGestureLine
      && left.speed !== null
      && left.speed > WRIST_ACTIVE_SPEED;
    const rightActive = right.aboveGestureLine
      && right.speed !== null
      && right.speed > WRIST_ACTIVE_SPEED;
    const evaluable = left.speed !== null || right.speed !== null;
    const active = leftActive || rightActive;

    if (evaluable) {
      this.gestureWeight += weight;
      bucket.gestureWeight += weight;
      if (active) {
        this.gestureActiveWeight += weight;
        bucket.gestureActiveWeight += weight;
      }
    }

    const handsIdle = (!left.visible || !left.aboveWaist)
      && (!right.visible || !right.aboveWaist);
    this.idleHandSegments.push(handsIdle, elapsed);
  }

  private addMovementObservation(
    landmarks: readonly NormalizedLandmark[],
    elapsed: number,
    continuous: boolean,
    bucket: TimelineBucket,
  ): void {
    const leftIndex = this.mode === 'sitzen' ? INDEX.leftShoulder : INDEX.leftHip;
    const rightIndex = this.mode === 'sitzen' ? INDEX.rightShoulder : INDEX.rightHip;
    const left = landmarks[leftIndex];
    const right = landmarks[rightIndex];
    if (!isVisible(left) || !isVisible(right)) {
      this.movementMidpoint = null;
      return;
    }

    const rawX = midpointX(left, right);
    const x = this.movementMidpoint && continuous
      ? 0.35 * rawX + 0.65 * this.movementMidpoint.x
      : rawX;

    if (this.movementMidpoint && continuous) {
      const delta = elapsed - this.movementMidpoint.t;
      if (delta > 0) {
        const distance = Math.abs(x - this.movementMidpoint.x);
        this.movementDistance += distance;
        this.movementTime += delta;
        bucket.movementDistance += distance;
        bucket.movementTime += delta;
      }
    }
    this.movementMidpoint = {x, y: 0, t: elapsed};
  }

  private postureScore(): number {
    const components: Array<{score: number; weight: number}> = [];
    if (this.shoulderAngles.hasValues()) {
      components.push({score: quality(this.shoulderAngles.mean(), 3, 15), weight: 0.35});
    }
    if (this.headAngles.hasValues()) {
      components.push({score: quality(this.headAngles.mean(), 4, 18), weight: 0.25});
    }
    if (this.nosePositions.hasValues() && this.shoulderWidths.mean() >= MIN_SHOULDER_WIDTH) {
      const normalisedSway = this.nosePositions.standardDeviation() / this.shoulderWidths.mean();
      components.push({score: quality(normalisedSway, 0.03, 0.2), weight: 0.4});
    }

    const totalWeight = components.reduce((sum, component) => sum + component.weight, 0);
    if (totalWeight <= 0) {
      return 0;
    }
    return clamp(
      components.reduce((sum, component) => sum + component.score * component.weight, 0)
        / totalWeight,
      0,
      100,
    );
  }

  private bucketFor(elapsed: number): TimelineBucket {
    const second = Math.max(0, Math.floor(elapsed));
    let bucket = this.timeline.get(second);
    if (!bucket) {
      bucket = {
        eyeWeight: 0,
        eyeGoodWeight: 0,
        gestureWeight: 0,
        gestureActiveWeight: 0,
        movementDistance: 0,
        movementTime: 0,
      };
      this.timeline.set(second, bucket);
    }
    return bucket;
  }

  private finishTimeline(duration: number): TimelinePoint[] {
    const length = Math.max(0, Math.ceil(duration));
    const result: TimelinePoint[] = [];
    for (let second = 0; second < length; second += 1) {
      const bucket = this.timeline.get(second);
      result.push({
        t: second,
        eye: bucket && percent(bucket.eyeGoodWeight, bucket.eyeWeight) >= 50 ? 1 : 0,
        gest: bucket && percent(bucket.gestureActiveWeight, bucket.gestureWeight) >= 30 ? 1 : 0,
        sway: bucket && bucket.movementTime > 0
          ? round(clamp(100 * bucket.movementDistance / bucket.movementTime, 0, 100), 2)
          : 0,
      });
    }
    return result;
  }
}

export interface PoseAnalyzerCallbacks {
  onStageCheck?: (check: StageCheck) => void;
  onError?: (error: unknown) => void;
}

/** Owns the throttled video loop; only aggregate state escapes the inference callback. */
export class PoseAnalyzer {
  private readonly accumulator = new PoseMetricsAccumulator();
  private mode: PresentationMode = 'stehen';
  private video: HTMLVideoElement | null = null;
  private animationFrame: number | null = null;
  private lastInference = Number.NEGATIVE_INFINITY;
  private recordingStart: number | null = null;
  private callbacks: PoseAnalyzerCallbacks = {};
  private closed = false;

  constructor(
    private readonly landmarker: PoseLandmarker,
    private readonly sampleIntervalMs = SAMPLE_INTERVAL_MS,
  ) {}

  start(video: HTMLVideoElement, callbacks: PoseAnalyzerCallbacks = {}): void {
    if (this.closed) {
      throw new Error('PoseAnalyzer is closed');
    }
    this.stop();
    this.video = video;
    this.callbacks = callbacks;
    this.animationFrame = requestAnimationFrame(this.loop);
  }

  setMode(mode: PresentationMode): void {
    this.mode = normaliseMode(mode);
    this.accumulator.setMode(this.mode);
  }

  beginRecording(startPerformanceMs = performance.now()): void {
    this.accumulator.reset();
    this.recordingStart = startPerformanceMs;
  }

  finishRecording(durationSeconds?: number): PoseMetrics {
    const duration = durationSeconds ?? (
      this.recordingStart === null ? 0 : (performance.now() - this.recordingStart) / 1000
    );
    this.recordingStart = null;
    return this.accumulator.finish(duration);
  }

  stop(): void {
    if (this.animationFrame !== null) {
      cancelAnimationFrame(this.animationFrame);
      this.animationFrame = null;
    }
    this.video = null;
  }

  close(): void {
    if (this.closed) {
      return;
    }
    this.stop();
    this.recordingStart = null;
    this.landmarker.close();
    this.closed = true;
  }

  private readonly loop = (now: number): void => {
    if (!this.video || this.closed) {
      return;
    }

    if (
      now - this.lastInference >= this.sampleIntervalMs
      && this.video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA
      && this.video.videoWidth > 0
      && this.video.videoHeight > 0
    ) {
      this.lastInference = now;
      try {
        this.landmarker.detectForVideo(this.video, now, (result) => {
          const landmarks = result.landmarks[0];
          this.callbacks.onStageCheck?.(checkStage(landmarks, this.mode));
          if (this.recordingStart !== null) {
            this.accumulator.add(landmarks, (now - this.recordingStart) / 1000);
          }
        });
      } catch (error) {
        this.callbacks.onStageCheck?.(checkStage(undefined, this.mode));
        if (this.recordingStart !== null) {
          this.accumulator.add(undefined, (now - this.recordingStart) / 1000);
        }
        this.callbacks.onError?.(error);
      }
    }

    this.animationFrame = requestAnimationFrame(this.loop);
  };
}

function hasVisibleStageCore(
  landmarks: readonly NormalizedLandmark[] | null | undefined,
  mode: PresentationMode,
): landmarks is readonly NormalizedLandmark[] {
  const indices = mode === 'sitzen' ? TRACKING_BASE_INDICES : STANDING_CORE_INDICES;
  const lastIndex = indices[indices.length - 1];
  return Boolean(
    landmarks
    && landmarks.length > lastIndex
    && indices.every((index) => isVisible(landmarks[index])),
  );
}

function hasVisibleTrackingBase(
  landmarks: readonly NormalizedLandmark[] | null | undefined,
): landmarks is readonly NormalizedLandmark[] {
  return Boolean(
    landmarks
    && landmarks.length > INDEX.rightShoulder
    && TRACKING_BASE_INDICES.every((index) => isVisible(landmarks[index])),
  );
}

function hasVisibleMetricCore(
  landmarks: readonly NormalizedLandmark[],
  mode: PresentationMode,
): boolean {
  const indices = mode === 'sitzen' ? SITTING_CORE_INDICES : STANDING_CORE_INDICES;
  return indices.every((index) => isVisible(landmarks[index]));
}

function isVisible(landmark: NormalizedLandmark | null | undefined): landmark is NormalizedLandmark {
  return Boolean(
    landmark
    && Number.isFinite(landmark.x)
    && Number.isFinite(landmark.y)
    && Number.isFinite(landmark.visibility)
    && landmark.visibility > VISIBILITY_THRESHOLD,
  );
}

function midpointX(left: NormalizedLandmark, right: NormalizedLandmark): number {
  return (left.x + right.x) / 2;
}

function distanceX(left: NormalizedLandmark, right: NormalizedLandmark): number {
  return Math.abs(left.x - right.x);
}

function lineAngleDegrees(left: NormalizedLandmark, right: NormalizedLandmark): number | null {
  const deltaX = Math.abs(right.x - left.x);
  const deltaY = Math.abs(right.y - left.y);
  if (!Number.isFinite(deltaX) || !Number.isFinite(deltaY) || deltaX < 0.005) {
    return null;
  }
  return Math.atan2(deltaY, deltaX) * 180 / Math.PI;
}

function gestureScore(activityPercent: number): number {
  const activity = clamp(finite(activityPercent, 0), 0, 100);
  if (activity <= 30) {
    return 100 * activity / 30;
  }
  if (activity <= 60) {
    return 100;
  }
  return clamp(100 * (100 - activity) / 40, 0, 100);
}

function movementClass(speed: number, mode: PresentationMode = 'stehen'): MovementClass {
  if (mode !== 'sitzen') {
    if (speed < 2) {
      return 'ruhig';
    }
    return speed > 8 ? 'unruhig' : 'lebendig';
  }
  if (speed < 1.5) {
    return 'ruhig';
  }
  return speed > 6 ? 'unruhig' : 'lebendig';
}

function movementScore(speed: number, mode: PresentationMode = 'stehen'): number {
  const safeSpeed = Math.max(0, finite(speed, 0));
  if (mode !== 'sitzen') {
    if (safeSpeed < 2) {
      return clamp(60 + 10 * safeSpeed, 0, 100);
    }
    if (safeSpeed <= 8) {
      return clamp(80 + 20 * (1 - Math.abs(safeSpeed - 5) / 3), 0, 100);
    }
    return clamp(80 - 10 * (safeSpeed - 8), 0, 100);
  }
  if (safeSpeed < 1.5) {
    return clamp(60 + 20 * safeSpeed / 1.5, 0, 100);
  }
  if (safeSpeed <= 6) {
    return clamp(80 + 20 * (1 - Math.abs(safeSpeed - 3.75) / 2.25), 0, 100);
  }
  return clamp(80 - 10 * (safeSpeed - 6), 0, 100);
}

function normaliseMode(mode: PresentationMode): PresentationMode {
  return mode === 'sitzen' ? 'sitzen' : 'stehen';
}

function quality(value: number, good: number, bad: number): number {
  if (bad <= good) {
    return 0;
  }
  return 100 * (1 - clamp((finite(value, bad) - good) / (bad - good), 0, 1));
}

function percent(numerator: number, denominator: number): number {
  if (!Number.isFinite(numerator) || !Number.isFinite(denominator) || denominator <= 0) {
    return 0;
  }
  return 100 * numerator / denominator;
}

function sanitiseEvent(event: MetricEvent, duration: number): MetricEvent | null {
  const start = clamp(finite(event.t, 0), 0, duration);
  const eventDuration = clamp(finite(event.dur, 0), 0, Math.max(0, duration - start));
  if (eventDuration <= 0) {
    return null;
  }
  return {
    t: round(start, 2),
    dur: round(eventDuration, 2),
    type: event.type,
  };
}

function finite(value: number | undefined, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

function clamp(value: number, minimum: number, maximum: number): number {
  return Math.min(maximum, Math.max(minimum, value));
}

function round(value: number, digits: number): number {
  const factor = 10 ** digits;
  return Math.round(finite(value, 0) * factor) / factor;
}
