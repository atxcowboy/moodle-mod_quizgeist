/**
 * Reusable "answer by speaking" control (F2 spoken, F9, F12).
 *
 * One control for every screen that accepts a spoken answer, because the
 * accessibility promises have to hold on all of them and a second
 * implementation is a second place to forget them:
 *
 *  - the record button carries a VISIBLE label, never only an icon;
 *  - the transcription state lives in an `aria-live="polite"` region, so a
 *    screen reader learns that something is happening without being
 *    interrupted mid-sentence;
 *  - every control is at least 44 px high (`quizgeist-touch-target`);
 *  - and the whole thing degrades to a plain text field, because a learner
 *    without a working microphone must lose convenience, not access.
 *
 * The transcription result arrives by POLLING. There is no socket and no
 * server-sent event anywhere in this file.
 */

import {liveButton, liveElement} from './dom';
import {liveString} from './strings';
import {
  ClipRecorder,
  recordingSupported,
  uploadClip,
  type RecorderState,
  type UploadedClip,
} from './recorder';

export interface SpokenAnswerConfig {
  uploadUrl: string;
  sesskey: string;
  cmid: number;
  purpose: 'answer' | 'reason' | 'speaking' | 'dictation';
  language: string;
  maxBytes: number;
  maxSeconds: number;
  /** Whether the AI addon is present AND may start new work. */
  transcriptionAvailable: boolean;
}

export interface SpokenAnswerCallbacks {
  /** Called once a clip exists on the server. */
  onClip: (clip: UploadedClip) => void;
  /** Called whenever the transcript changes. */
  onTranscript?: (text: string, clip: UploadedClip) => void;
  /** Requests one transcription attempt; resolves with the updated clip. */
  transcribe?: (clipId: number) => Promise<UploadedClip>;
  /** Polls the state of the given clips. */
  pollState?: (clipIds: number[]) => Promise<UploadedClip[]>;
}

const POLL_INTERVAL_MS = 1500;
const POLL_ATTEMPTS = 40;

export class SpokenAnswerControl {
  readonly root: HTMLDivElement;

  private readonly button: HTMLButtonElement;

  private readonly status: HTMLParagraphElement;

  private readonly hint: HTMLParagraphElement;

  private readonly recorder: ClipRecorder;

  private clip: UploadedClip | null = null;

  private polling = false;

  private disposed = false;

  constructor(
    private readonly strings: Record<string, string>,
    private readonly config: SpokenAnswerConfig,
    private readonly callbacks: SpokenAnswerCallbacks,
  ) {
    this.root = liveElement('div', 'quizgeist-spoken');
    this.button = liveButton(
      this.text('clip:record:start', 'Antwort aufnehmen'),
      'quizgeist-spoken__button quizgeist-touch-target',
      {'aria-describedby': ''},
    );
    this.status = liveElement('p', 'quizgeist-spoken__status', {
      // The one place the whole feature reports itself to assistive
      // technology. `polite` and not `assertive`: a transcript arriving must
      // not cut into what a learner is currently reading.
      'aria-live': 'polite',
      role: 'status',
    });
    this.hint = liveElement('p', 'quizgeist-spoken__hint', {
      text: this.text(
        'clip:languagehint',
        'Spracherkennung derzeit nur Deutsch.',
      ),
    });

    this.recorder = new ClipRecorder(
      {maxBytes: config.maxBytes, maxSeconds: config.maxSeconds},
      (state) => this.renderState(state),
    );
    this.button.addEventListener('click', () => {
      void this.toggle();
    });

    this.root.append(this.button, this.status, this.hint);
    if (!recordingSupported()) {
      this.button.disabled = true;
      this.button.textContent = this.text(
        'clip:record:unavailable',
        'Aufnahme auf diesem Gerät nicht möglich',
      );
      this.setStatus(this.text(
        'clip:record:usetext',
        'Bitte tippe deine Antwort ein.',
      ));
    }
  }

  /**
   * Whether this browser could record at all.
   */
  static available(): boolean {
    return recordingSupported();
  }

  /**
   * The clip this control produced, if any.
   */
  getClip(): UploadedClip | null {
    return this.clip;
  }

  /**
   * Stop everything and release the microphone.
   */
  dispose(): void {
    this.disposed = true;
    this.recorder.cancel();
  }

  private text(key: string, fallback: string): string {
    return liveString(this.strings, key, {}, fallback);
  }

  private setStatus(message: string): void {
    this.status.textContent = message;
  }

  private renderState(state: RecorderState): void {
    switch (state) {
      case 'requesting':
        this.button.disabled = true;
        this.setStatus(this.text('clip:record:requesting', 'Mikrofon wird angefragt …'));
        break;
      case 'recording':
        this.button.disabled = false;
        this.button.textContent = this.text('clip:record:stop', 'Aufnahme beenden');
        this.button.classList.add('is-recording');
        this.setStatus(this.text('clip:record:running', 'Aufnahme läuft.'));
        break;
      case 'stopping':
        this.button.disabled = true;
        this.button.classList.remove('is-recording');
        this.setStatus(this.text('clip:record:stopping', 'Aufnahme wird abgeschlossen …'));
        break;
      case 'unavailable':
        this.button.disabled = true;
        this.setStatus(this.text(
          'clip:record:usetext',
          'Bitte tippe deine Antwort ein.',
        ));
        break;
      default:
        this.button.disabled = false;
        this.button.classList.remove('is-recording');
        this.button.textContent = this.text('clip:record:start', 'Antwort aufnehmen');
        break;
    }
  }

  private async toggle(): Promise<void> {
    if (this.recorder.getState() === 'recording') {
      await this.finish();
      return;
    }
    try {
      await this.recorder.start();
    } catch {
      // A refused microphone is a decision, not a fault. Say what happens now
      // instead of what went wrong.
      this.setStatus(this.text(
        'clip:record:denied',
        'Ohne Mikrofon geht es auch: tippe deine Antwort ein.',
      ));
    }
  }

  private async finish(): Promise<void> {
    let recording;
    try {
      recording = await this.recorder.stop();
    } catch {
      this.setStatus(this.text('clip:record:failed', 'Die Aufnahme hat nicht geklappt.'));
      return;
    }
    if (recording.blob.size === 0) {
      this.setStatus(this.text('clip:error:empty', 'Es wurde nichts aufgenommen.'));
      return;
    }
    if (recording.blob.size > this.config.maxBytes) {
      this.setStatus(this.text('clip:error:toolarge', 'Die Aufnahme ist zu groß.'));
      return;
    }

    this.setStatus(this.text('clip:upload:running', 'Aufnahme wird gesendet …'));
    let clip: UploadedClip;
    try {
      clip = await uploadClip(
        this.config.uploadUrl,
        this.config.sesskey,
        this.config.cmid,
        this.config.purpose,
        this.config.language,
        recording,
      );
    } catch (error) {
      // The server shipped a readable sentence with the code; show that.
      const message = error instanceof Error && error.message !== ''
        ? error.message
        : this.text('clip:error:uploadfailed', 'Die Aufnahme konnte nicht gesendet werden.');
      this.setStatus(message);
      return;
    }

    this.clip = clip;
    this.callbacks.onClip(clip);

    if (!this.config.transcriptionAvailable || this.callbacks.transcribe === undefined) {
      // Without the AI addon (or without a licence for new work) the recording
      // stays exactly what it is: an answer that has been given and can be
      // played back. That is the existing-content guarantee of 2.6.
      this.setStatus(this.text(
        'clip:transcript:unavailable',
        'Aufnahme gespeichert. Eine Verschriftung ist hier nicht verfügbar.',
      ));
      return;
    }

    this.setStatus(this.text('clip:transcript:pending', 'Wird verschriftet …'));
    try {
      const updated = await this.callbacks.transcribe(clip.id);
      this.applyClip(updated);
      if (updated.transcriptState === 'pending') {
        await this.pollUntilSettled(updated.id);
      }
    } catch {
      this.setStatus(this.text(
        'clip:transcript:failed',
        'Die Verschriftung hat nicht geklappt. Deine Aufnahme ist gespeichert.',
      ));
    }
  }

  /**
   * Ask the server for the state until it settles.
   *
   * Bounded repetition of a cheap read — the polling rule of HOUSE_RULES taken
   * literally. No socket, no server-sent event, no long poll.
   */
  private async pollUntilSettled(clipId: number): Promise<void> {
    if (this.callbacks.pollState === undefined || this.polling) {
      return;
    }
    this.polling = true;
    try {
      for (let attempt = 0; attempt < POLL_ATTEMPTS; attempt += 1) {
        if (this.disposed) {
          return;
        }
        await new Promise((resolve) => {
          setTimeout(resolve, POLL_INTERVAL_MS);
        });
        const clips = await this.callbacks.pollState([clipId]);
        const found = clips.find((candidate) => candidate.id === clipId);
        if (found === undefined) {
          continue;
        }
        this.applyClip(found);
        if (found.transcriptState !== 'pending') {
          return;
        }
      }
      this.setStatus(this.text(
        'clip:transcript:slow',
        'Die Verschriftung dauert noch. Deine Antwort ist trotzdem abgegeben.',
      ));
    } finally {
      this.polling = false;
    }
  }

  private applyClip(clip: UploadedClip): void {
    this.clip = clip;
    switch (clip.transcriptState) {
      case 'done':
        this.setStatus(this.text('clip:transcript:done', 'Verschriftet.'));
        this.callbacks.onTranscript?.(clip.transcript ?? '', clip);
        break;
      case 'failed':
        // The machine code stays in `transcriptcode` on the server. The
        // learner reads a sentence (F16).
        this.setStatus(this.text(
          'clip:transcript:failed',
          'Die Verschriftung hat nicht geklappt. Deine Aufnahme ist gespeichert.',
        ));
        break;
      case 'pending':
        this.setStatus(this.text('clip:transcript:pending', 'Wird verschriftet …'));
        break;
      default:
        this.setStatus(this.text('clip:upload:done', 'Aufnahme gespeichert.'));
        break;
    }
  }
}
