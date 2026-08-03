/**
 * Teacher dictation for the generator field (F9).
 *
 * Same recorder, same upload path, `purpose='dictation'`. The one thing that
 * differs is what happens afterwards: the server transcribes and DELETES the
 * recording in the same request, so a teacher's voice never lingers on disk
 * waiting for a retention task. This module therefore never offers playback —
 * by the time it has the text, there is nothing left to play.
 */

import {liveButton, liveElement} from '../live/dom';
import {ClipRecorder, recordingSupported, uploadClip} from '../live/recorder';
import {editorString, type ClientStrings} from './messages';

export interface DictationConfig {
  uploadUrl: string;
  sesskey: string;
  cmid: number;
  language: string;
  maxBytes: number;
  maxSeconds: number;
}

export interface DictationCallbacks {
  /** Sends the clip for transcription; the server deletes it either way. */
  transcribe: (clipId: number) => Promise<{text: string}>;
  /** Receives the dictated text. */
  onText: (text: string) => void;
}

export class DictationControl {
  readonly root: HTMLDivElement;

  private readonly button: HTMLButtonElement;

  private readonly status: HTMLParagraphElement;

  private readonly recorder: ClipRecorder;

  private busy = false;

  constructor(
    private readonly strings: ClientStrings,
    private readonly config: DictationConfig,
    private readonly callbacks: DictationCallbacks,
  ) {
    this.root = liveElement('div', 'quizgeist-dictation');
    this.button = liveButton(
      // A visible label, not just a microphone glyph.
      editorString(this.strings, 'dictation:start', 'Diktat starten'),
      'quizgeist-dictation__button quizgeist-touch-target',
    );
    this.status = liveElement('p', 'quizgeist-dictation__status', {
      'aria-live': 'polite',
      role: 'status',
    });
    this.button.addEventListener('click', () => {
      void this.toggle();
    });
    this.root.append(this.button, this.status);

    this.recorder = new ClipRecorder(
      {maxBytes: config.maxBytes, maxSeconds: config.maxSeconds},
      (state) => {
        if (state === 'recording') {
          this.button.textContent = editorString(
            this.strings,
            'dictation:stop',
            'Diktat beenden',
          );
          this.button.classList.add('is-recording');
          this.setStatus(editorString(this.strings, 'dictation:running', 'Diktat läuft.'));
        } else if (state === 'idle') {
          this.button.textContent = editorString(
            this.strings,
            'dictation:start',
            'Diktat starten',
          );
          this.button.classList.remove('is-recording');
        }
      },
    );

    if (!recordingSupported()) {
      this.button.disabled = true;
      this.setStatus(editorString(
        this.strings,
        'dictation:unavailable',
        'Diktat ist auf diesem Gerät nicht möglich.',
      ));
    }
  }

  /**
   * Whether dictation can be offered at all.
   */
  static available(): boolean {
    return recordingSupported();
  }

  dispose(): void {
    this.recorder.cancel();
  }

  private setStatus(message: string): void {
    this.status.textContent = message;
  }

  private async toggle(): Promise<void> {
    if (this.busy) {
      return;
    }
    if (this.recorder.getState() === 'recording') {
      await this.finish();
      return;
    }
    try {
      await this.recorder.start();
    } catch {
      this.setStatus(editorString(
        this.strings,
        'dictation:denied',
        'Ohne Mikrofonfreigabe kann nicht diktiert werden.',
      ));
    }
  }

  private async finish(): Promise<void> {
    this.busy = true;
    this.button.disabled = true;
    try {
      const recording = await this.recorder.stop();
      if (recording.blob.size === 0) {
        this.setStatus(editorString(this.strings, 'dictation:empty', 'Es wurde nichts aufgenommen.'));
        return;
      }
      this.setStatus(editorString(this.strings, 'dictation:working', 'Diktat wird verschriftet …'));
      const clip = await uploadClip(
        this.config.uploadUrl,
        this.config.sesskey,
        this.config.cmid,
        'dictation',
        this.config.language,
        recording,
      );
      const result = await this.callbacks.transcribe(clip.id);
      const text = typeof result.text === 'string' ? result.text.trim() : '';
      if (text === '') {
        this.setStatus(editorString(
          this.strings,
          'dictation:nothingheard',
          'Es war nichts zu verstehen. Die Aufnahme wurde gelöscht.',
        ));
        return;
      }
      this.callbacks.onText(text);
      this.setStatus(editorString(
        this.strings,
        'dictation:done',
        'Diktat übernommen. Die Aufnahme wurde gelöscht.',
      ));
    } catch (error) {
      const message = error instanceof Error && error.message !== ''
        ? error.message
        : editorString(this.strings, 'dictation:failed', 'Das Diktat hat nicht geklappt.');
      this.setStatus(message);
    } finally {
      this.busy = false;
      this.button.disabled = false;
    }
  }
}
