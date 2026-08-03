/**
 * Short audio recording for spoken answers and the speaking trainer (U3).
 *
 * Audio only, on purpose. mod_redewerkstatt records video and splits an audio
 * track off it; Quizgeist never wants the picture, so it never asks for the
 * camera. A permission prompt that mentions a camera the feature does not use
 * is the kind of thing that makes a class say no.
 *
 * Recording is a hard-bounded activity: the controller stops itself at the
 * server's duration limit. That is a courtesy to the learner (a forgotten
 * running recording is refused by the server, and losing the answer would be
 * the learner's problem), not a security measure — the real limit is measured
 * server-side out of the container.
 */

export interface RecorderLimits {
  /** Maximum accepted size in bytes. */
  maxBytes: number;
  /** Maximum accepted duration in seconds. */
  maxSeconds: number;
}

export interface RecordingResult {
  blob: Blob;
  mimeType: string;
  durationMs: number;
}

export type RecorderState = 'idle' | 'requesting' | 'recording' | 'stopping' | 'unavailable';

const AUDIO_TYPES = [
  'audio/webm;codecs=opus',
  'audio/webm',
  'audio/ogg;codecs=opus',
  'audio/mp4',
];

/**
 * Whether this browser can record at all.
 *
 * Used to decide whether the recording button is offered — never to decide
 * whether the FEATURE is offered. Every screen that records also accepts
 * typed input, so a device without a microphone loses convenience, not access.
 */
export function recordingSupported(): boolean {
  return typeof navigator !== 'undefined'
    && typeof navigator.mediaDevices?.getUserMedia === 'function'
    && typeof MediaRecorder !== 'undefined'
    && typeof window !== 'undefined'
    && typeof window.MediaStream !== 'undefined';
}

function firstSupportedType(): string | undefined {
  if (typeof MediaRecorder === 'undefined'
      || typeof MediaRecorder.isTypeSupported !== 'function') {
    return undefined;
  }
  return AUDIO_TYPES.find((type) => MediaRecorder.isTypeSupported(type));
}

/**
 * One recording session.
 */
export class ClipRecorder {
  private stream: MediaStream | null = null;

  private recorder: MediaRecorder | null = null;

  private chunks: Blob[] = [];

  private startedAt = 0;

  private autoStop: ReturnType<typeof setTimeout> | null = null;

  private state: RecorderState = 'idle';

  constructor(
    private readonly limits: RecorderLimits,
    private readonly onStateChange: (state: RecorderState) => void = () => {},
  ) {}

  getState(): RecorderState {
    return this.state;
  }

  private setState(state: RecorderState): void {
    this.state = state;
    this.onStateChange(state);
  }

  /**
   * Ask for the microphone and start recording.
   *
   * Rejects when the browser cannot record or the user declines. The caller is
   * expected to fall back to typed input rather than to insist.
   */
  async start(): Promise<void> {
    if (!recordingSupported()) {
      this.setState('unavailable');
      throw new Error('recording-unsupported');
    }
    if (this.state === 'recording' || this.state === 'requesting') {
      return;
    }
    this.setState('requesting');
    let stream: MediaStream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio: {echoCancellation: true, noiseSuppression: true},
        video: false,
      });
    } catch (error) {
      this.setState('idle');
      throw error;
    }

    this.stream = stream;
    this.chunks = [];
    const mimeType = firstSupportedType();
    try {
      this.recorder = mimeType
        ? new MediaRecorder(stream, {mimeType})
        : new MediaRecorder(stream);
    } catch (error) {
      this.release();
      this.setState('idle');
      throw error;
    }
    this.recorder.addEventListener('dataavailable', (event: BlobEvent) => {
      if (event.data.size > 0) {
        this.chunks.push(event.data);
      }
    });
    this.startedAt = Date.now();
    // Timeslice 1000 ms, as in mod_redewerkstatt: it makes the container write
    // cluster timecodes, which is what lets the server measure the duration of
    // a stream that states no duration of its own.
    this.recorder.start(1000);
    this.setState('recording');

    const limitMs = Math.max(1, this.limits.maxSeconds) * 1000;
    this.autoStop = setTimeout(() => {
      if (this.state === 'recording') {
        void this.stop().catch(() => {});
      }
    }, limitMs);
  }

  /**
   * Stop and return the recording.
   */
  async stop(): Promise<RecordingResult> {
    if (this.autoStop !== null) {
      clearTimeout(this.autoStop);
      this.autoStop = null;
    }
    const recorder = this.recorder;
    if (recorder === null) {
      throw new Error('recording-not-started');
    }
    this.setState('stopping');

    const blob = await new Promise<Blob>((resolve, reject) => {
      const finish = (): void => {
        const type = recorder.mimeType || this.chunks[0]?.type || 'audio/webm';
        resolve(new Blob(this.chunks, {type}));
      };
      recorder.addEventListener('stop', finish, {once: true});
      recorder.addEventListener('error', () => reject(new Error('recording-failed')), {once: true});
      if (recorder.state === 'inactive') {
        finish();
        return;
      }
      recorder.stop();
    });

    const durationMs = Math.max(0, Date.now() - this.startedAt);
    this.release();
    this.setState('idle');
    return {
      blob,
      // Strip the codec parameter: the server compares the container family,
      // and `audio/webm;codecs=opus` is the same family as `audio/webm`.
      mimeType: (blob.type || 'audio/webm').split(';')[0].trim(),
      durationMs,
    };
  }

  /**
   * Abandon a running recording without producing a result.
   */
  cancel(): void {
    if (this.autoStop !== null) {
      clearTimeout(this.autoStop);
      this.autoStop = null;
    }
    if (this.recorder !== null && this.recorder.state !== 'inactive') {
      try {
        this.recorder.stop();
      } catch {
        // A recorder that refuses to stop is released below anyway.
      }
    }
    this.chunks = [];
    this.release();
    this.setState('idle');
  }

  /**
   * Release the microphone.
   *
   * Called on every exit path. A stream left open keeps the browser's
   * recording indicator lit, and a learner has every right to read that
   * indicator as "this page is still listening".
   */
  private release(): void {
    if (this.stream !== null) {
      this.stream.getTracks().forEach((track) => track.stop());
      this.stream = null;
    }
    this.recorder = null;
  }
}

export interface UploadedClip {
  id: number;
  purpose: string;
  durationMs: number;
  language: string;
  transcriptState: string;
  audioAvailable: boolean;
  transcript?: string | null;
}

export interface ClipUploadError {
  code: string;
  message: string;
}

/**
 * Send one recording to the dedicated multipart entry point.
 *
 * Same origin, no external host, one discrete POST. The transcription that
 * follows is polled, never streamed (HOUSE_RULES).
 */
export async function uploadClip(
  uploadUrl: string,
  sesskey: string,
  cmid: number,
  purpose: string,
  language: string,
  recording: RecordingResult,
): Promise<UploadedClip> {
  const form = new FormData();
  form.append('id', String(cmid));
  form.append('sesskey', sesskey);
  form.append('purpose', purpose);
  form.append('language', language);
  form.append('clip', recording.blob, `clip.${extensionFor(recording.mimeType)}`);

  const response = await fetch(uploadUrl, {
    method: 'POST',
    body: form,
    credentials: 'same-origin',
    headers: {Accept: 'application/json'},
  });
  const payload = await response.json().catch(() => null) as
    {clip?: UploadedClip; error?: ClipUploadError} | null;

  if (!response.ok || payload === null || payload.clip === undefined) {
    const error = payload?.error;
    // The server always ships a ready-made sentence next to the code, so the
    // client never has to render a machine code (F16).
    const failure = new Error(error?.message ?? '') as Error & {code?: string};
    failure.code = error?.code ?? 'clip_upload_failed';
    throw failure;
  }
  return payload.clip;
}

function extensionFor(mimeType: string): string {
  switch (mimeType) {
    case 'audio/ogg':
      return 'ogg';
    case 'audio/mp4':
    case 'audio/x-m4a':
      return 'm4a';
    case 'audio/wav':
    case 'audio/x-wav':
      return 'wav';
    default:
      return 'webm';
  }
}
