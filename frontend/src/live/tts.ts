import type {QuizgeistInitConfig} from '../types';
import {liveButton, liveElement} from './dom';

interface TtsConfig extends QuizgeistInitConfig {
  sesskey?: string;
  strings?: Record<string, string>;
}

function text(config: TtsConfig, key: string, fallback: string): string {
  const editorAlias: Record<string, string> = {
    'live:tts:error': 'editor:tts:error',
    'live:tts:play': 'editor:tts:play',
    'live:tts:stop': 'editor:tts:loading',
    'live:tts:unavailable': 'editor:tts:unavailable',
  };
  return config.strings?.[key]
    || config.strings?.[editorAlias[key]]
    || fallback;
}

const MAX_TTS_CHUNK_CODEPOINTS = 600;

/**
 * Split local_voces input without silently discarding long question or slide
 * text. Boundaries prefer sentences, then whitespace, and finally a hard
 * Unicode-code-point boundary.
 */
export function chunkTtsText(
  content: string,
  maximum = MAX_TTS_CHUNK_CODEPOINTS,
): string[] {
  if (!Number.isInteger(maximum) || maximum < 1) {
    throw new RangeError('The TTS chunk size must be a positive integer.');
  }
  const chunks: string[] = [];
  let remaining = Array.from(content.trim());
  while (remaining.length > 0) {
    if (remaining.length <= maximum) {
      const finalChunk = remaining.join('').trim();
      if (finalChunk !== '') {
        chunks.push(finalChunk);
      }
      break;
    }

    const minimumPreferredBoundary = Math.max(1, Math.floor(maximum * 0.45));
    let sentenceBoundary = 0;
    let whitespaceBoundary = 0;
    for (let index = maximum; index >= minimumPreferredBoundary; index -= 1) {
      const previous = remaining[index - 1] || '';
      const next = remaining[index] || '';
      if (
        sentenceBoundary === 0
        && (previous === '.' || previous === '!' || previous === '?' || previous === '\n')
        && (next === '' || /\s/u.test(next))
      ) {
        sentenceBoundary = index;
      }
      if (whitespaceBoundary === 0 && /\s/u.test(previous)) {
        whitespaceBoundary = index - 1;
      }
      if (sentenceBoundary > 0 && whitespaceBoundary > 0) {
        break;
      }
    }
    const cut = sentenceBoundary || whitespaceBoundary || maximum;
    const chunk = remaining.slice(0, cut).join('').trim();
    if (chunk !== '') {
      chunks.push(chunk);
    }
    remaining = remaining.slice(cut);
    while (remaining.length > 0 && /\s/u.test(remaining[0])) {
      remaining.shift();
    }
  }
  return chunks;
}

/**
 * One local_voces player shared by editor, host and player surfaces.
 */
export class TtsPlayer {
  private audio: HTMLAudioElement | null = null;
  private controller: AbortController | null = null;
  private currentPlaybackReject: ((reason: Error) => void) | null = null;
  private objectUrl: string | null = null;
  private playbackGeneration = 0;
  private playing = false;

  public constructor(private readonly config: TtsConfig) {
  }

  public isAvailable(): boolean {
    return Boolean(
      this.config.tts?.available
      && this.config.tts.speakUrl
      && this.config.tts.voices.length > 0
      && this.config.sesskey,
    );
  }

  public isPlaying(): boolean {
    return this.playing;
  }

  public stop(): void {
    this.playbackGeneration += 1;
    this.playing = false;
    this.controller?.abort();
    this.controller = null;
    const reject = this.currentPlaybackReject;
    this.currentPlaybackReject = null;
    this.releaseAudio();
    reject?.(new DOMException('Playback stopped.', 'AbortError'));
  }

  private releaseAudio(): void {
    if (this.audio) {
      this.audio.pause();
      this.audio.currentTime = 0;
      this.audio = null;
    }
    if (this.objectUrl) {
      URL.revokeObjectURL(this.objectUrl);
      this.objectUrl = null;
    }
  }

  public async play(content: string, voiceId?: number): Promise<void> {
    this.stop();
    const chunks = chunkTtsText(content);
    if (!this.isAvailable() || chunks.length === 0) {
      throw new Error(text(
        this.config,
        'live:tts:unavailable',
        'Vorlesen ist für dieses Nutzerkonto nicht verfügbar.',
      ));
    }

    const selectedVoice = voiceId
      || this.config.tts?.defaultVoiceId
      || this.config.tts?.voices[0]?.id
      || 0;
    const generation = this.playbackGeneration;
    this.playing = true;
    try {
      for (const chunk of chunks) {
        if (generation !== this.playbackGeneration) {
          throw new DOMException('Playback stopped.', 'AbortError');
        }
        const blob = await this.requestAudio(chunk, selectedVoice, generation);
        await this.playAudio(blob, generation);
      }
    } finally {
      if (generation === this.playbackGeneration) {
        this.playing = false;
        this.controller = null;
        this.currentPlaybackReject = null;
        this.releaseAudio();
      }
    }
  }

  private async requestAudio(
    value: string,
    selectedVoice: number,
    generation: number,
  ): Promise<Blob> {
    const form = new FormData();
    form.append('action', 'speak');
    form.append('sesskey', String(this.config.sesskey || ''));
    form.append('text', value);
    form.append('voiceid', String(selectedVoice));
    form.append('speed', '0.9');
    const controller = new AbortController();
    this.controller = controller;
    const response = await fetch(String(this.config.tts?.speakUrl || ''), {
      method: 'POST',
      credentials: 'same-origin',
      body: form,
      signal: controller.signal,
    });
    if (generation !== this.playbackGeneration) {
      throw new DOMException('Playback stopped.', 'AbortError');
    }
    const contentType = (response.headers.get('Content-Type') || '')
      .split(';', 1)[0]
      .trim()
      .toLowerCase();
    if (
      !response.ok
      || (contentType !== 'audio/mpeg' && contentType !== 'audio/mp3')
    ) {
      this.controller = null;
      throw new Error(text(
        this.config,
        'live:tts:error',
        'Der Text konnte nicht vorgelesen werden.',
      ));
    }
    const blob = await response.blob();
    this.controller = null;
    if (blob.size === 0) {
      throw new Error(text(
        this.config,
        'live:tts:error',
        'Der Text konnte nicht vorgelesen werden.',
      ));
    }
    return blob;
  }

  private async playAudio(blob: Blob, generation: number): Promise<void> {
    this.objectUrl = URL.createObjectURL(blob);
    this.audio = new Audio(this.objectUrl);
    await new Promise<void>((resolve, reject) => {
      let settled = false;
      const finish = (error?: Error): void => {
        if (settled) {
          return;
        }
        settled = true;
        this.currentPlaybackReject = null;
        this.releaseAudio();
        if (error) {
          reject(error);
        } else {
          resolve();
        }
      };
      this.currentPlaybackReject = (reason) => finish(reason);
      this.audio?.addEventListener('ended', () => finish(), {once: true});
      this.audio?.addEventListener(
        'error',
        () => finish(new Error(text(
          this.config,
          'live:tts:error',
          'Der Text konnte nicht vorgelesen werden.',
        ))),
        {once: true},
      );
      this.audio?.play().catch((error) => finish(
        error instanceof Error
          ? error
          : new Error(text(
            this.config,
            'live:tts:error',
            'Der Text konnte nicht vorgelesen werden.',
          )),
      ));
    });
    if (generation !== this.playbackGeneration) {
      throw new DOMException('Playback stopped.', 'AbortError');
    }
  }
}

/**
 * Accessible TTS control used in every live question/slide card.
 */
export function createTtsControl(
  player: TtsPlayer,
  content: string,
  config: TtsConfig,
): HTMLElement {
  const wrapper = liveElement('div', 'quizgeist-live-tts', {
    'data-live-tts': true,
  });
  const status = liveElement('span', 'quizgeist-live-tts__status', {
    'aria-live': 'polite',
    'data-live-tts-status': true,
  });
  const playLabel = text(config, 'live:tts:play', 'Vorlesen');
  const stopLabel = text(config, 'live:tts:stop', 'Stoppen');
  const button = liveButton(
    playLabel,
    'quizgeist-live-tts__button',
    {
      'aria-pressed': 'false',
      'data-live-tts-toggle': true,
      disabled: !player.isAvailable() || content.trim() === '',
    },
  );
  if (button.disabled) {
    button.title = text(
      config,
      'live:tts:unavailable',
      'Vorlesen ist für dieses Nutzerkonto nicht verfügbar.',
    );
  }
  button.addEventListener('click', () => {
    if (player.isPlaying()) {
      player.stop();
      button.textContent = playLabel;
      button.setAttribute('aria-pressed', 'false');
      status.textContent = '';
      return;
    }
    button.textContent = stopLabel;
    button.setAttribute('aria-pressed', 'true');
    status.textContent = stopLabel;
    void player.play(content).catch((error) => {
      if (!(error instanceof DOMException && error.name === 'AbortError')) {
        status.textContent = error instanceof Error
          ? error.message
          : text(config, 'live:tts:error', 'Der Text konnte nicht vorgelesen werden.');
      }
    }).finally(() => {
      button.textContent = playLabel;
      button.setAttribute('aria-pressed', 'false');
      if (status.textContent === stopLabel) {
        status.textContent = '';
      }
    });
  });
  wrapper.append(button, status);
  return wrapper;
}
