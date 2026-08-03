import {liveButton, liveElement} from './dom';

type SoundPreset =
  | 'tap'
  | 'countdown'
  | 'go'
  | 'correct'
  | 'incorrect'
  | 'podium'
  | 'streak'
  | 'warning';

interface AudioWindow extends Window {
  webkitAudioContext?: typeof AudioContext;
}

const STORAGE_KEY = 'mod_quizgeist:live-sound';

/**
 * Local WebAudio synthesis shared by host and player.
 *
 * The context is created lazily from a user gesture, so browsers never receive
 * an autoplay request during bootstrap/reconnect.
 */
export class SoundEngine {
  private context: AudioContext | null = null;
  private master: GainNode | null = null;
  private lobbyTimer: number | null = null;
  private lobbyBeat = 0;
  private muted = false;
  private volume = .55;
  // F1: the activity-wide off switch. It is decided on the server and can
  // never be re-enabled from the client — unlike the personal mute button.
  private allowed = true;

  public constructor() {
    try {
      const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') as {
        muted?: boolean;
        volume?: number;
      };
      this.muted = stored.muted === true;
      if (Number.isFinite(stored.volume)) {
        this.volume = Math.max(0, Math.min(1, Number(stored.volume)));
      }
    } catch (_error) {
      // Storage can be unavailable in private browsing; in-memory controls work.
    }
  }

  public isMuted(): boolean {
    return this.muted || !this.allowed;
  }

  /**
   * Apply the activity-wide sound switch (F1 stress-free standard).
   *
   * With sounds disallowed the engine stays silent regardless of the
   * learner's personal setting, and the controls disappear.
   */
  public setAllowed(allowed: boolean): void {
    this.allowed = allowed;
    if (!allowed) {
      this.stopLobby();
    }
    this.applyGain();
  }

  public isAllowed(): boolean {
    return this.allowed;
  }

  public getVolume(): number {
    return this.volume;
  }

  public async unlock(): Promise<boolean> {
    if (!this.context) {
      const Constructor = window.AudioContext
        || (window as AudioWindow).webkitAudioContext;
      if (!Constructor) {
        return false;
      }
      this.context = new Constructor();
      this.master = this.context.createGain();
      this.master.connect(this.context.destination);
      this.applyGain();
    }
    if (this.context.state === 'suspended') {
      try {
        await this.context.resume();
      } catch (_error) {
        return false;
      }
    }
    return this.context.state === 'running';
  }

  public setMuted(muted: boolean): void {
    this.muted = muted;
    this.applyGain();
    this.persist();
  }

  public setVolume(volume: number): void {
    this.volume = Math.max(0, Math.min(1, volume));
    this.applyGain();
    this.persist();
  }

  public startLobby(): void {
    if (this.lobbyTimer !== null || !this.context || !this.allowed) {
      return;
    }
    const beat = (): void => {
      if (!this.context || this.context.state !== 'running') {
        return;
      }
      const chord = [261.63, 329.63, 392];
      chord.forEach((frequency, index) => {
        this.tone(frequency, 2.35, 'triangle', .018, index * .025, 1.1);
      });
      if (this.lobbyBeat % 2 === 0) {
        const melody = [523.25, 587.33, 659.25, 783.99, 659.25, 587.33];
        this.tone(
          melody[(this.lobbyBeat / 2) % melody.length],
          .18,
          'sine',
          .035,
          .05,
          .12,
        );
      }
      this.lobbyBeat += 1;
    };
    beat();
    this.lobbyTimer = window.setInterval(beat, 2600);
  }

  public stopLobby(): void {
    if (this.lobbyTimer !== null) {
      window.clearInterval(this.lobbyTimer);
      this.lobbyTimer = null;
    }
  }

  public play(preset: SoundPreset): void {
    if (!this.allowed
        || !this.context
        || !this.master
        || this.context.state !== 'running') {
      return;
    }
    switch (preset) {
      case 'tap':
        this.tone(660, .035, 'sine', .04, 0, .018);
        break;
      case 'countdown':
        this.tone(220, .09, 'square', .035, 0, .06, 1200);
        break;
      case 'go':
        this.sweep(440, 660, .18, 'square', .075, 1200);
        break;
      case 'correct':
        [523.25, 659.25, 783.99, 1046.5].forEach((frequency, index) => {
          this.tone(frequency, .22, index % 2 ? 'triangle' : 'sine', .065, index * .09, .12);
        });
        break;
      case 'incorrect':
        this.tone(329.63, .22, 'sine', .045, 0, .15, 900);
        this.tone(261.63, .25, 'sine', .04, .14, .15, 900);
        break;
      case 'podium':
        [349.23, 392, 440, 523.25].forEach((root, index) => {
          [1, 1.25, 1.5].forEach((ratio) => {
            this.tone(root * ratio, index === 3 ? .7 : .36, 'sawtooth', .03, index * .3, .32, 2500);
          });
        });
        break;
      case 'streak':
        this.sweep(440, 880, .35, 'triangle', .06);
        break;
      case 'warning':
        this.tone(220, .06, 'sine', .045, 0, .04);
        this.tone(220, .06, 'sine', .045, .14, .04);
        break;
    }
  }

  public destroy(): void {
    this.stopLobby();
    this.context?.close().catch(() => undefined);
    this.context = null;
    this.master = null;
  }

  private tone(
    frequency: number,
    duration: number,
    waveform: OscillatorType,
    level: number,
    delay = 0,
    release = .1,
    lowpass = 0,
  ): void {
    if (!this.context || !this.master) {
      return;
    }
    const start = this.context.currentTime + delay;
    const oscillator = this.context.createOscillator();
    const gain = this.context.createGain();
    oscillator.type = waveform;
    oscillator.frequency.setValueAtTime(frequency, start);
    gain.gain.setValueAtTime(.0001, start);
    gain.gain.exponentialRampToValueAtTime(Math.max(.0001, level), start + .008);
    gain.gain.exponentialRampToValueAtTime(.0001, start + duration + release);
    if (lowpass > 0) {
      const filter = this.context.createBiquadFilter();
      filter.type = 'lowpass';
      filter.frequency.setValueAtTime(lowpass, start);
      oscillator.connect(filter);
      filter.connect(gain);
    } else {
      oscillator.connect(gain);
    }
    gain.connect(this.master);
    oscillator.start(start);
    oscillator.stop(start + duration + release + .02);
  }

  private sweep(
    from: number,
    to: number,
    duration: number,
    waveform: OscillatorType,
    level: number,
    lowpass = 0,
  ): void {
    if (!this.context || !this.master) {
      return;
    }
    const start = this.context.currentTime;
    const oscillator = this.context.createOscillator();
    const gain = this.context.createGain();
    oscillator.type = waveform;
    oscillator.frequency.setValueAtTime(from, start);
    oscillator.frequency.exponentialRampToValueAtTime(to, start + duration);
    gain.gain.setValueAtTime(.0001, start);
    gain.gain.exponentialRampToValueAtTime(level, start + .008);
    gain.gain.exponentialRampToValueAtTime(.0001, start + duration + .1);
    if (lowpass > 0) {
      const filter = this.context.createBiquadFilter();
      filter.type = 'lowpass';
      filter.frequency.value = lowpass;
      oscillator.connect(filter);
      filter.connect(gain);
    } else {
      oscillator.connect(gain);
    }
    gain.connect(this.master);
    oscillator.start(start);
    oscillator.stop(start + duration + .12);
  }

  private applyGain(): void {
    if (this.master && this.context) {
      this.master.gain.setTargetAtTime(
        this.isMuted() ? 0 : this.volume,
        this.context.currentTime,
        .015,
      );
    }
  }

  private persist(): void {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        muted: this.muted,
        volume: this.volume,
      }));
    } catch (_error) {
      // Keep the current page functional without storage.
    }
  }
}

export function createSoundControls(
  engine: SoundEngine,
  strings: Record<string, string>,
): HTMLElement {
  const wrapper = liveElement('div', 'quizgeist-live-sound', {
    'data-live-sound-controls': true,
  });
  if (!engine.isAllowed()) {
    // F1: the activity switched sound off. Offering a control that cannot
    // do anything would be a lie, so the block stays empty.
    wrapper.hidden = true;
    return wrapper;
  }
  const muteLabel = strings['live:sound:mute'] || 'Ton aus';
  const unmuteLabel = strings['live:sound:unmute'] || 'Ton an';
  const mute = liveButton(
    engine.isMuted() ? unmuteLabel : muteLabel,
    'quizgeist-live-sound__mute',
    {
      'aria-pressed': engine.isMuted() ? 'true' : 'false',
      'data-live-sound-mute': true,
    },
  );
  const volume = liveElement('input', 'quizgeist-live-sound__volume', {
    'aria-label': strings['live:sound:label'] || 'Lautstärke',
    'data-live-sound-volume': true,
    max: '100',
    min: '0',
    step: '5',
    type: 'range',
    value: String(Math.round(engine.getVolume() * 100)),
  });
  mute.addEventListener('click', () => {
    void engine.unlock();
    engine.setMuted(!engine.isMuted());
    mute.textContent = engine.isMuted() ? unmuteLabel : muteLabel;
    mute.setAttribute('aria-pressed', engine.isMuted() ? 'true' : 'false');
  });
  volume.addEventListener('input', () => {
    void engine.unlock();
    engine.setVolume(Number(volume.value) / 100);
    if (engine.getVolume() > 0 && engine.isMuted()) {
      engine.setMuted(false);
      mute.textContent = muteLabel;
      mute.setAttribute('aria-pressed', 'false');
    }
  });
  wrapper.append(mute, volume);
  return wrapper;
}
