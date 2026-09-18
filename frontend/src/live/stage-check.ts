/**
 * The stage check on the learner's screen (F13).
 *
 * This module renders three surfaces — `stage-setup`, `stage-live` and
 * `stage-report` — and owns the ONE JSON request the feature makes. It does
 * not contain any camera or analysis code: that lives in the addon bundle
 * `addon/buehne/bundles/app_stage.js`, which is loaded on demand and which
 * has no network side at all.
 *
 * The split is the point. Everything that can talk to the server is here and
 * sends numbers; everything that can see the camera is over there and sends
 * nothing. `clientNoMediaUpload` measures both halves.
 */

import {LiveApi, LiveApiError} from './api';
import {liveString, type StringValues} from './strings';
import type {LiveConfig, LiveStageConfig} from './types';

/** What the addon bundle exposes on window once it is loaded. */
interface StageBundle {
  contract: number;
  createStageEngine(options: StageEngineOptions): StageEngine;
}

interface StageCheckState {
  ready: boolean;
  personPresent: boolean;
  coreVisible: boolean;
  centered: boolean;
  hint: 'ok' | 'stepback' | 'center' | 'no-pose';
}

interface StageEngineOptions {
  assetsUrl: string;
  video: HTMLVideoElement;
  mode?: 'stehen' | 'sitzen';
  allowGpu?: boolean;
  maxSeconds?: number;
  onHint?: (check: StageCheckState) => void;
  onTick?: (elapsedSecs: number) => void;
  onFailure?: (code: string) => void;
}

interface StageEngine {
  start(): Promise<string>;
  beginRecording(): void;
  finishRecording(): {
    durationSecs: number;
    metrics: Record<string, unknown>;
    delegate: string;
  };
  close(): void;
}

interface StageStartResponse {
  questionId: number;
  maxSeconds: number;
  allowGpu: boolean;
  canSave: boolean;
  licenceStatus: string;
  attempts: number;
  reports: StageReport[];
}

export interface StageReport {
  id: number;
  questionId: number;
  answerId: number | null;
  durationSecs: number;
  aiUsed: boolean;
  metrics: Record<string, number | string | unknown[]> | null;
  feedback: string[];
  timeCreated: number;
}

interface StageFinishResponse {
  report: StageReport;
  reports: StageReport[];
}

declare global {
  interface Window {
    QuizgeistStageApp?: StageBundle;
  }
}

const BUNDLE_CONTRACT = 1;
const METRIC_KEYS = [
  'eyecontactpct',
  'gesturescore',
  'gestureactivpct',
  'posturescore',
  'movementscore',
  'visibilitypct',
] as const;

let bundlePromise: Promise<StageBundle | null> | null = null;

/**
 * Small local element helper, identical in shape to the one in player-app.
 *
 * @param tag Element name.
 * @param className CSS class.
 * @param text Optional text content.
 * @returns The element.
 */
function element<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className = '',
  text?: string,
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className !== '') {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  return node;
}

/**
 * Load the addon bundle exactly once, the house way: a direct script tag plus
 * a poller on the global, never RequireJS (HOUSE_RULES, P1 lesson).
 *
 * @param url Absolute URL of the addon bundle.
 * @returns The bundle, or null when the addon is not deployed.
 */
function loadStageBundle(url: string): Promise<StageBundle | null> {
  if (window.QuizgeistStageApp?.contract === BUNDLE_CONTRACT) {
    return Promise.resolve(window.QuizgeistStageApp);
  }
  if (bundlePromise !== null) {
    return bundlePromise;
  }
  bundlePromise = new Promise<StageBundle | null>((resolve) => {
    const script = document.createElement('script');
    script.src = url;
    script.async = true;
    script.dataset.quizgeistBundle = 'app_stage';
    script.addEventListener('load', () => {
      const bundle = window.QuizgeistStageApp;
      resolve(bundle && bundle.contract === BUNDLE_CONTRACT ? bundle : null);
    });
    script.addEventListener('error', () => {
      bundlePromise = null;
      resolve(null);
    });
    document.head.append(script);
  });
  return bundlePromise;
}

/**
 * Format whole seconds as m:ss for a status line.
 *
 * @param seconds Elapsed seconds.
 * @returns Readable clock.
 */
function clock(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));
  const minutes = Math.floor(total / 60);
  return `${minutes}:${String(total % 60).padStart(2, '0')}`;
}

/** One learner-facing stage check, from camera consent to report. */
export class StageCheckControl {
  public readonly root: HTMLElement;

  private readonly api: LiveApi;
  private readonly video: HTMLVideoElement;
  private readonly hintLine: HTMLElement;
  private readonly statusLine: HTMLElement;
  private readonly clockLine: HTMLElement;
  private readonly startButton: HTMLButtonElement;
  private readonly stopButton: HTMLButtonElement;
  private readonly modeSelect: HTMLSelectElement;
  private readonly reportBox: HTMLElement;
  private readonly noticeLine: HTMLElement;

  private engine: StageEngine | null = null;
  private session: StageStartResponse | null = null;
  private recording = false;
  private busy = false;
  private lastHint = '';

  public constructor(
    private readonly config: LiveConfig & {stage?: LiveStageConfig},
    private readonly questionId: number,
    private readonly answerId: number | null,
    private readonly onSubmitted: (report: StageReport) => void,
  ) {
    this.api = new LiveApi(config);
    this.root = element('section', 'quizgeist-stage');
    this.root.dataset.quizgeistView = 'stage-setup';

    const heading = element('h3', 'quizgeist-stage__title', this.s('stage:title'));
    const intro = element('p', 'quizgeist-stage__intro', this.s('stage:intro'));
    this.noticeLine = element('p', 'quizgeist-stage__notice', this.s('stage:privacy'));

    this.video = document.createElement('video');
    this.video.className = 'quizgeist-stage__video';
    this.video.setAttribute('playsinline', '');
    this.video.setAttribute('muted', '');
    this.video.setAttribute('aria-label', this.s('stage:videolabel'));

    const frame = element('div', 'quizgeist-stage__frame');
    frame.append(this.video);

    this.hintLine = element('p', 'quizgeist-stage__hint', this.s('stage:hint:idle'));
    // polite, deliberately not assertive: a posture hint that interrupts the
    // screen reader on every frame would make the feature unusable.
    this.hintLine.setAttribute('aria-live', 'polite');
    this.hintLine.setAttribute('role', 'status');

    this.clockLine = element('p', 'quizgeist-stage__clock', '');
    this.clockLine.setAttribute('aria-live', 'off');

    this.statusLine = element('p', 'quizgeist-stage__status', '');
    this.statusLine.setAttribute('aria-live', 'polite');
    this.statusLine.setAttribute('role', 'status');

    const modeLabel = element(
      'label',
      'quizgeist-stage__mode-label',
      this.s('stage:mode'),
    );
    this.modeSelect = document.createElement('select');
    this.modeSelect.className = 'quizgeist-stage__mode quizgeist-touch-target';
    this.modeSelect.id = `quizgeist-stage-mode-${this.questionId}`;
    for (const mode of ['stehen', 'sitzen'] as const) {
      const option = document.createElement('option');
      option.value = mode;
      option.textContent = this.s(`stage:mode:${mode}`);
      this.modeSelect.append(option);
    }
    modeLabel.setAttribute('for', this.modeSelect.id);

    this.startButton = document.createElement('button');
    this.startButton.type = 'button';
    this.startButton.className =
      'quizgeist-stage__start quizgeist-player-button quizgeist-touch-target';
    this.startButton.textContent = this.s('stage:action:start');
    this.startButton.addEventListener('click', () => {
      void this.start();
    });

    this.stopButton = document.createElement('button');
    this.stopButton.type = 'button';
    this.stopButton.className =
      'quizgeist-stage__stop quizgeist-player-button quizgeist-touch-target';
    this.stopButton.textContent = this.s('stage:action:stop');
    this.stopButton.hidden = true;
    this.stopButton.addEventListener('click', () => {
      void this.finish();
    });

    const controls = element('div', 'quizgeist-stage__controls');
    controls.append(modeLabel, this.modeSelect, this.startButton, this.stopButton);

    this.reportBox = element('div', 'quizgeist-stage__report');

    const skip = document.createElement('button');
    skip.type = 'button';
    skip.className =
      'quizgeist-stage__skip quizgeist-player-button quizgeist-touch-target';
    skip.textContent = this.s('stage:action:skip');
    skip.addEventListener('click', () => {
      this.close();
      this.onSubmitted({
        id: 0,
        questionId: this.questionId,
        answerId: this.answerId,
        durationSecs: 0,
        aiUsed: false,
        metrics: null,
        feedback: [],
        timeCreated: 0,
      });
    });

    this.root.append(
      heading,
      intro,
      this.noticeLine,
      frame,
      this.hintLine,
      this.clockLine,
      controls,
      this.statusLine,
      this.reportBox,
      skip,
    );
  }

  /**
   * Ask the server for the run parameters and render the setup surface.
   *
   * [P11-E2]: `stage_start` belongs to the buehne addon. Whether the addon is
   * there is something the SERVER says — in the bootstrap flag and in the
   * stage configuration, which view.php only emits for an installed addon.
   * Without that report nothing is asked at all. The same condition already
   * guarded start(); asking first and only then noticing would produce a 404
   * that no browser console forgets.
   */
  public async init(): Promise<void> {
    if (this.config.features?.buehne?.installed !== true
        || this.config.stage === undefined) {
      this.statusLine.textContent = this.s('stage:error:notavailable');
      this.startButton.disabled = true;
      return;
    }
    try {
      this.session = await this.api.post<StageStartResponse>('stage_start', {
        questionId: this.questionId,
      });
    } catch (error) {
      this.fail(error);
      return;
    }
    if (!this.session.canSave) {
      // Honest BEFORE the presentation, not after it: read-only means the
      // exercise still works and the report is not kept.
      this.noticeLine.textContent = this.s('stage:notice:readonly');
      this.noticeLine.classList.add('quizgeist-stage__notice--warning');
    }
    this.renderReports(this.session.reports);
  }

  /** Release camera and engine. Safe to call more than once. */
  public close(): void {
    this.recording = false;
    this.engine?.close();
    this.engine = null;
  }

  private s(key: string, values: StringValues = {}, fallback = ''): string {
    return liveString(this.config.strings, key, values, fallback);
  }

  private async start(): Promise<void> {
    if (this.busy || this.session === null) {
      return;
    }
    this.busy = true;
    this.startButton.disabled = true;
    this.statusLine.textContent = this.s('stage:status:starting');
    const stage = this.config.stage;
    if (stage === undefined) {
      this.statusLine.textContent = this.s('stage:error:notavailable');
      this.busy = false;
      return;
    }
    const bundle = await loadStageBundle(stage.bundleUrl);
    if (bundle === null) {
      this.statusLine.textContent = this.s('stage:error:enginemissing');
      this.startButton.disabled = false;
      this.busy = false;
      return;
    }
    this.engine = bundle.createStageEngine({
      assetsUrl: stage.assetsUrl,
      video: this.video,
      mode: this.modeSelect.value === 'sitzen' ? 'sitzen' : 'stehen',
      allowGpu: this.session.allowGpu,
      maxSeconds: this.session.maxSeconds,
      onHint: (check) => this.showHint(check),
      onTick: (elapsed) => this.showClock(elapsed),
      onFailure: (code) => this.showFailure(code),
    });
    const delegate = await this.engine.start();
    this.root.dataset.quizgeistView = 'stage-live';
    this.root.dataset.stageDelegate = delegate;
    this.engine.beginRecording();
    this.recording = true;
    this.startButton.hidden = true;
    this.stopButton.hidden = false;
    this.modeSelect.disabled = true;
    this.statusLine.textContent = this.s('stage:status:running');
    this.stopButton.focus();
    this.busy = false;
  }

  private async finish(): Promise<void> {
    if (this.busy || !this.recording || this.engine === null) {
      return;
    }
    this.busy = true;
    this.recording = false;
    this.stopButton.disabled = true;
    const result = this.engine.finishRecording();
    this.close();
    this.statusLine.textContent = this.s('stage:status:saving');
    if (this.session !== null && !this.session.canSave) {
      this.root.dataset.quizgeistView = 'stage-report';
      this.statusLine.textContent = this.s('stage:notice:readonly');
      this.busy = false;
      return;
    }
    try {
      // The ONE request of this feature. Its body is JSON with numbers; there
      // is no FormData, no Blob and no beacon anywhere in this module.
      const response = await this.api.post<StageFinishResponse>('stage_finish', {
        questionId: this.questionId,
        answerId: this.answerId ?? 0,
        durationSecs: result.durationSecs,
        metrics: result.metrics,
      });
      this.root.dataset.quizgeistView = 'stage-report';
      this.statusLine.textContent = this.s('stage:status:saved');
      this.renderReports(response.reports);
      this.onSubmitted(response.report);
    } catch (error) {
      this.fail(error);
    }
    this.busy = false;
  }

  private showHint(check: StageCheckState): void {
    const key = `stage:hint:${check.hint}`;
    if (key === this.lastHint) {
      return;
    }
    this.lastHint = key;
    this.hintLine.textContent = this.s(key);
    this.hintLine.dataset.stageHint = check.hint;
  }

  private showClock(elapsed: number): void {
    const maximum = this.session?.maxSeconds ?? 180;
    this.clockLine.textContent = this.s('stage:clock', {
      elapsed: clock(elapsed),
      total: clock(maximum),
    });
    if (elapsed >= maximum) {
      void this.finish();
    }
  }

  private showFailure(code: string): void {
    const known = ['camera_denied', 'camera_missing', 'engine_unavailable'];
    // F16: the raw code stays in the console, the sentence goes on screen.
    const key = known.includes(code)
      ? `stage:failure:${code}`
      : 'stage:failure:engine_unavailable';
    this.statusLine.textContent = this.s(key);
    this.startButton.hidden = false;
    this.startButton.disabled = false;
    this.stopButton.hidden = true;
  }

  private fail(error: unknown): void {
    const message = error instanceof LiveApiError && error.message !== ''
      ? error.message
      : this.s('stage:error:generic');
    this.statusLine.textContent = message;
    this.statusLine.setAttribute('role', 'alert');
    this.startButton.disabled = false;
  }

  private renderReports(reports: StageReport[]): void {
    this.reportBox.replaceChildren();
    if (reports.length === 0) {
      return;
    }
    const latest = reports[0];
    const heading = element(
      'h4',
      'quizgeist-stage__report-title',
      this.s('stage:report:title'),
    );
    const list = element('ul', 'quizgeist-stage__metrics');
    for (const key of METRIC_KEYS) {
      const value = latest.metrics === null
        ? null
        : Number(latest.metrics[key] ?? 0);
      const item = element('li', 'quizgeist-stage__metric');
      item.append(
        element('span', 'quizgeist-stage__metric-label', this.s(`stage:metric:${key}`)),
        element(
          'span',
          'quizgeist-stage__metric-value',
          value === null ? '—' : `${Math.round(value)} %`,
        ),
      );
      item.dataset.stageMetric = key;
      list.append(item);
    }
    const coach = element('ul', 'quizgeist-stage__coach');
    for (const code of latest.feedback) {
      coach.append(element('li', 'quizgeist-stage__coach-item', this.s(code)));
    }
    this.reportBox.append(heading, list);
    if (latest.feedback.length > 0) {
      this.reportBox.append(coach);
    }
  }
}
