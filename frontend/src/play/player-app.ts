import {LiveApi, LiveApiError} from '../live/api';
import {answerPayload} from '../live/answer';
import {AVATAR_KEYS, createAvatar, type AvatarKey} from '../live/avatar';
import {createLiveMedia} from '../live/media';
import {AdaptivePoller, type PollIteration} from '../live/poller';
import {
  patchLiveResponseClock,
  questionAllowsRepeatedSubmissions,
  questionSpeechText,
  renderLiveResponse,
} from '../live/qtype/registry';
import {SpokenAnswerControl} from '../live/spoken-answer';
import {StageCheckControl} from '../live/stage-check';
import type {UploadedClip} from '../live/recorder';
import {createSoundControls, SoundEngine} from '../live/sound-engine';
import {liveString, type StringValues} from '../live/strings';
import {StageModeController, type StageMode} from '../live/stage-mode';
import {createTtsControl, TtsPlayer} from '../live/tts';
import type {
  HostConfig,
  LiveAggregate,
  LiveAnswer,
  LiveNameMode,
  LivePhase,
  LiveQuestion,
  LiveRewards,
  LiveStanding,
  LiveStressFree,
  LiveTeamOption,
  LiveTeamStanding,
} from '../live/types';

interface PlayerSummary {
  accessoryKey?: string | null;
  avatarKey?: string | null;
  displayName: string;
  id: number;
  score: number;
  streak: number;
  team?: {
    teamKey: string;
    teamName: string;
  } | null;
  teamName?: string | null;
}

interface PlayerFeedback {
  answer?: Record<string, unknown> | null;
  choiceIds: string[];
  correct: boolean | null;
  points: number;
  responseTimeMs: number;
}

interface PlayerState extends LiveStressFree {
  aggregate?: LiveAggregate | null;
  aggregateRevision?: number;
  currentIndex: number;
  feedback?: PlayerFeedback | null;
  hasAnswered: boolean;
  mode: string;
  nameMode: LiveNameMode;
  ownRank: number | null;
  phase: LivePhase;
  phaseEndsAtMs: number;
  phaseStartedAtMs: number;
  player: PlayerSummary;
  playerCount: number;
  podium: LiveStanding[];
  question?: LiveQuestion | null;
  ranking: LiveStanding[];
  rewards?: LiveRewards;
  serverTimeMs: number;
  sessionId: number;
  stateVersion: number;
  teamPodium?: LiveTeamStanding[];
  teamRanking?: LiveTeamStanding[];
  totalQuestions: number;
}

interface StateResult {
  state: PlayerState | null;
}

interface AnswerAck {
  accepted: boolean;
  hasAnswered: boolean;
  stateVersion: number;
}

interface PlayerPollResult {
  aggregate?: LiveAggregate | null;
  aggregateRevision?: number;
  changed: boolean;
  hasAnswered: boolean;
  pollAfterMs: number;
  serverTimeMs: number;
  state?: PlayerState;
  stateVersion: number;
}

interface LookupResult {
  state: {
    mode: string;
    nameMode: LiveNameMode;
    phase: LivePhase;
    playerCount: number;
    rewards?: LiveRewards;
    sessionId: number;
    teamConfig?: {
      source: string;
      teams: LiveTeamOption[];
    } | null;
    teams?: LiveTeamOption[];
  };
  /** Vorhanden, wenn dieselbe Person bereits Spielerin der laufenden Runde ist. */
  resume?: PlayerState;
}

interface PlayerConfig extends HostConfig {
  initialJoinCode: string;
  overviewUrl: string;
}

type PlayerStageModeScreen = 'loading' | 'join' | 'profile' | 'fatal' | LivePhase;

const AVATAR_NAMES: Record<AvatarKey, string> = {
  federchen: 'Federchen',
  kiesel: 'Kiesel',
  klecks: 'Klecks',
  kubus: 'Kubus',
  mondchen: 'Mondchen',
  stern: 'Stern',
  wirbel: 'Wirbel',
  zweig: 'Zweig',
};

function element<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className = '',
  text?: string,
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  return node;
}

function button(
  className: string,
  text: string,
  action: string,
): HTMLButtonElement {
  const node = element('button', className, text);
  node.type = 'button';
  node.dataset.action = action;
  return node;
}

function normaliseConfig(raw: HostConfig): PlayerConfig | null {
  const cmid = Number(raw.cmid || 0);
  const ajaxUrl = typeof raw.ajaxUrl === 'string' ? raw.ajaxUrl : '';
  const sesskey = typeof raw.sesskey === 'string' ? raw.sesskey : '';
  if (!Number.isInteger(cmid) || cmid <= 0 || ajaxUrl === '' || sesskey === '') {
    return null;
  }
  const initialJoinCode = typeof raw.initialJoinCode === 'string'
    && /^[0-9]{6}$/.test(raw.initialJoinCode)
    ? raw.initialJoinCode
    : '';
  return {
    ...raw,
    ajaxUrl,
    brandIconUrl: typeof raw.brandIconUrl === 'string' ? raw.brandIconUrl : '',
    cmid,
    containerId: typeof raw.containerId === 'string' && raw.containerId
      ? raw.containerId
      : 'quizgeist-app-play',
    initialJoinCode,
    overviewUrl: typeof raw.overviewUrl === 'string' ? raw.overviewUrl : '',
    playerUrlBase: typeof raw.playerUrlBase === 'string' ? raw.playerUrlBase : '',
    sesskey,
    strings: raw.strings || {},
  };
}

export class PlayerApp {
  private readonly reconnectKey: string;
  private readonly api: LiveApi;
  private answerMessage = '';
  private clockId: number | null = null;
  private connectionMessage = '';
  private currentLookup: LookupResult['state'] | null = null;
  private currentState: PlayerState | null = null;
  private joinCode = '';
  private readonly liveRegion: HTMLDivElement;
  private readonly poller: AdaptivePoller;
  private requestedQuestionDelivery = '';
  private answerDraft: LiveAnswer | null = null;
  private lastCountdownSecond: number | null = null;
  private lastWarningSecond: number | null = null;
  private scoreBurst = false;
  private selectedAvatar: AvatarKey = 'kiesel';
  private selectedAccessory = '';
  private selectedTeamKey = '';
  private serverClockOffsetMs = 0;
  private readonly submissionKeys = new Map<string, string>();

  /** Aktive Aufnahmesteuerungen; beim Neuzeichnen wird das Mikrofon frei. */
  private spokenControls: SpokenAnswerControl[] = [];
  private readonly sound = new SoundEngine();
  /** F13: the running stage check, so a screen change can release the camera. */
  private stageCheck: StageCheckControl | null = null;
  private readonly stage: HTMLDivElement;
  private readonly fullscreenBar: HTMLDivElement;
  private readonly fullscreenToggle: HTMLButtonElement;
  private readonly stageMode: StageModeController;
  private stageModeScreen: PlayerStageModeScreen = 'loading';
  private submitting = false;
  private streakBurst = false;
  private readonly tts: TtsPlayer;

  public constructor(
    private readonly root: HTMLElement,
    private readonly config: PlayerConfig,
  ) {
    this.api = new LiveApi(config);
    this.tts = new TtsPlayer(config);
    this.reconnectKey = `mod_quizgeist:live-player:${config.cmid}`;
    this.liveRegion = element('div', 'quizgeist-live-visually-hidden');
    this.liveRegion.setAttribute('aria-atomic', 'true');
    this.liveRegion.setAttribute('aria-live', 'polite');
    this.liveRegion.setAttribute('role', 'status');
    this.stage = element('div', 'quizgeist-player-stage');
    this.fullscreenBar = element('div', 'quizgeist-player-toolbar');
    this.fullscreenBar.dataset.playerToolbar = '';
    this.fullscreenBar.hidden = true;
    this.stageMode = new StageModeController(this.root, {
      announce: (message) => this.announceStageMode(message),
      text: (key, fallback) => this.s(key, {}, fallback),
    }, {
      exactRoot: true,
      buttonClassName: 'quizgeist-player-toolbar__stagemode',
      toggleKey: 'play:stagemode:toggle',
      toggleFallback: 'Vollbild',
      titleKey: 'play:stagemode:title',
      titleFallback: 'Vollbild ein- und ausschalten',
      onKey: 'play:stagemode:on',
      onFallback: 'Vollbild eingeschaltet.',
      offKey: 'play:stagemode:off',
      offFallback: 'Vollbild ausgeschaltet.',
      focusOnlyOwnChange: true,
      avoidInputFocus: true,
      focusFallback: () => this.focusStageModeFallback(),
      onModeChange: (mode) => this.syncStageModeVisibility(mode),
    });
    this.fullscreenToggle = this.stageMode.createToggle();
    this.fullscreenBar.append(this.fullscreenToggle);
    this.poller = new AdaptivePoller(
      (signal) => this.poll(signal),
      (error, failures) => this.pollError(error, failures),
    );
  }

  public async init(): Promise<void> {
    this.root.classList.add('quizgeist-player-root');
    this.root.dataset.quizgeistRoot = 'play';
    this.root.dataset.quizgeistTheme = this.config.theme || 'hell';
    this.root.dataset.quizgeistSeason = this.config.season || 'herbst';
    this.root.replaceChildren(this.liveRegion, this.fullscreenBar, this.stage);
    this.stageMode.attach();
    this.root.addEventListener('pointerdown', this.unlockSound, {once: true});
    this.renderLoading();
    try {
      const sessionId = this.storedSessionId();
      const result = await this.api.post<StateResult>(
        'live_player_bootstrap',
        sessionId ? {sessionId} : {},
      );
      if (result.state) {
        if (this.isTerminalPhase(result.state.phase)) {
          this.clearStoredSession();
          if (this.config.initialJoinCode) {
            this.currentState = null;
            await this.lookup(this.config.initialJoinCode);
            return;
          }
        }
        this.applyState(result.state, true);
        return;
      }
      if (this.config.initialJoinCode) {
        await this.lookup(this.config.initialJoinCode);
      } else {
        this.renderJoin();
      }
    } catch (error) {
      if (error instanceof LiveApiError
          && error.status === 404
          && this.storedSessionId()) {
        this.clearStoredSession();
        if (this.config.initialJoinCode) {
          await this.lookup(this.config.initialJoinCode);
        } else {
          this.renderJoin();
        }
        return;
      }
      this.handleActionError(error, () => {
        void this.init();
      });
    }
  }

  private readonly unlockSound = (): void => {
    void this.sound.unlock().then(() => {
      if (this.currentState?.phase === 'lobby') {
        this.sound.startLobby();
      }
    });
  };

  private s(key: string, values: StringValues = {}, fallback = ''): string {
    return liveString(this.config.strings, key, values, fallback);
  }

  private renderLoading(): void {
    const section = element('section', 'quizgeist-player-card quizgeist-player-loading');
    section.setAttribute('role', 'status');
    section.append(
      this.brand(),
      element('p', 'quizgeist-player-loading__text', this.s('live:connection:loading')),
    );
    this.stage.replaceChildren(section);
    this.setStageModeScreen('loading');
  }

  private brand(): HTMLElement {
    const brand = element('div', 'quizgeist-live-brand');
    if (this.config.brandIconUrl) {
      const icon = element('img', 'quizgeist-live-brand__icon');
      icon.src = this.config.brandIconUrl;
      icon.alt = '';
      brand.append(icon);
    }
    const wordmark = element('span', 'quizgeist-live-brand__wordmark');
    wordmark.append(
      element('span', 'quizgeist-live-brand__quiz', 'Quiz'),
      element('span', 'quizgeist-live-brand__geist', 'geist'),
    );
    brand.append(wordmark);
    return brand;
  }

  private renderJoin(message = ''): void {
    this.stopClock();
    this.setRootState('join', null);
    const card = element('section', 'quizgeist-player-card quizgeist-player-join');
    const heading = element('h2', 'quizgeist-player-title', this.s('play:join:title'));
    const description = element('p', 'quizgeist-player-copy', this.s('play:join:description'));
    const form = element('form', 'quizgeist-join-form');
    form.dataset.action = 'lookup';
    const label = element('label', 'quizgeist-field-label', this.s('play:join:pin'));
    label.htmlFor = 'quizgeist-join-code';
    const input = element('input', 'quizgeist-pin-input');
    input.id = 'quizgeist-join-code';
    input.name = 'joinCode';
    input.autocomplete = 'one-time-code';
    input.inputMode = 'numeric';
    input.maxLength = 6;
    input.pattern = '[0-9]{6}';
    input.required = true;
    input.value = this.joinCode;
    input.setAttribute('aria-describedby', 'quizgeist-join-help quizgeist-join-error');
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(0, 6);
      this.joinCode = input.value;
      this.patchPinSlots(slots, input.value);
    });
    const slots = element('div', 'quizgeist-pin-slots');
    slots.id = 'quizgeist-join-help';
    slots.setAttribute('aria-hidden', 'true');
    this.patchPinSlots(slots, input.value);
    const error = element('p', 'quizgeist-form-error', message);
    error.id = 'quizgeist-join-error';
    error.setAttribute('role', 'alert');
    const submit = element('button', 'quizgeist-player-primary', this.s('play:join:submit'));
    submit.type = 'submit';
    submit.dataset.action = 'lookup-submit';
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (/^[0-9]{6}$/.test(input.value)) {
        void this.lookup(input.value);
      } else {
        error.textContent = this.s('play:join:notfound');
        input.focus();
      }
    });
    form.append(label, input, slots, this.numberPad(input, slots), error, submit);
    card.append(this.brand(), heading, description, form);
    this.stage.replaceChildren(card);
    this.setStageModeScreen('join');
    input.focus();
  }

  private patchPinSlots(container: HTMLElement, value: string): void {
    const slots = Array.from({length: 6}, (_unused, index) => {
      const slot = element('span', 'quizgeist-pin-slot', value[index] || '•');
      slot.classList.toggle('is-filled', index < value.length);
      return slot;
    });
    container.replaceChildren(...slots);
  }

  private numberPad(input: HTMLInputElement, slots: HTMLElement): HTMLElement {
    const pad = element('div', 'quizgeist-number-pad');
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'back'].forEach((key) => {
      const label = key === 'clear' ? 'C' : key === 'back' ? '⌫' : key;
      const keyButton = button('quizgeist-number-key', label, `pin-${key}`);
      keyButton.setAttribute('aria-label', key === 'clear'
        ? this.s('play:pin:clear')
        : key === 'back'
          ? this.s('play:pin:back')
          : key);
      keyButton.addEventListener('click', () => {
        if (key === 'clear') {
          input.value = '';
        } else if (key === 'back') {
          input.value = input.value.slice(0, -1);
        } else if (input.value.length < 6) {
          input.value += key;
        }
        this.joinCode = input.value;
        this.patchPinSlots(slots, input.value);
        input.focus();
      });
      pad.append(keyButton);
    });
    return pad;
  }

  private async lookup(code: string): Promise<void> {
    this.joinCode = code;
    this.renderLoading();
    try {
      const result = await this.api.post<LookupResult>('live_session_lookup', {
        joinCode: code,
      });
      if (result.resume) {
        // Bereits Spielerin dieser laufenden Runde (Tab geschlossen, QR erneut
        // gescannt): direkt zurück ins Spiel, ohne Namens-/Avatarauswahl.
        this.storeSession(result.resume.sessionId);
        this.applyState(result.resume, true);
        return;
      }
      this.currentLookup = result.state;
      this.renderProfile();
    } catch (error) {
      if (this.isAuthenticationError(error)) {
        this.renderExpiredSession();
        return;
      }
      const message = error instanceof LiveApiError
        && (error.status === 404 || error.code === 'not_found')
        ? this.s('play:join:notfound')
        : error instanceof LiveApiError
          ? error.message
          : this.s('live:error:request');
      this.renderJoin(message);
    }
  }

  private renderProfile(message = ''): void {
    const lookup = this.currentLookup;
    if (!lookup) {
      this.renderJoin(message);
      return;
    }
    this.setRootState('profile', null);
    const card = element('section', 'quizgeist-player-card quizgeist-player-profile');
    const heading = element('h2', 'quizgeist-player-title', this.s('play:profile:title'));
    const info = element('p', 'quizgeist-player-copy');
    if (lookup.nameMode === 'real') {
      info.textContent = this.s('host:namemode:real');
    } else if (lookup.nameMode === 'generated') {
      info.textContent = this.s('host:namemode:generated');
    } else {
      info.textContent = this.s('play:profile:namehint');
    }
    const form = element('form', 'quizgeist-profile-form');
    form.dataset.action = 'join';
    let nameInput: HTMLInputElement | null = null;
    if (lookup.nameMode === 'custom') {
      const label = element('label', 'quizgeist-field-label', this.s('play:profile:name'));
      label.htmlFor = 'quizgeist-display-name';
      nameInput = element('input', 'quizgeist-text-input');
      nameInput.id = 'quizgeist-display-name';
      nameInput.name = 'displayName';
      nameInput.maxLength = 40;
      nameInput.required = true;
      nameInput.autocomplete = 'off';
      label.append(nameInput);
      form.append(label);
    }
    const avatarLabel = element('p', 'quizgeist-field-label', this.s('play:profile:avatar'));
    const avatars = element('div', 'quizgeist-avatar-picker');
    avatars.setAttribute('role', 'radiogroup');
    const unlockedAvatars = new Set(
      lookup.rewards?.avatars?.length
        ? lookup.rewards.avatars
        : AVATAR_KEYS,
    );
    if (!unlockedAvatars.has(this.selectedAvatar)) {
      this.selectedAvatar = (AVATAR_KEYS.find((key) => unlockedAvatars.has(key))
        || 'kiesel') as AvatarKey;
    }
    AVATAR_KEYS.forEach((key) => {
      const avatarButton = button('quizgeist-avatar-choice', AVATAR_NAMES[key], `avatar-${key}`);
      avatarButton.dataset.avatarKey = key;
      avatarButton.dataset.liveAvatarKey = key;
      avatarButton.setAttribute('role', 'radio');
      avatarButton.setAttribute('aria-checked', key === this.selectedAvatar ? 'true' : 'false');
      avatarButton.classList.toggle('is-selected', key === this.selectedAvatar);
      const unlocked = unlockedAvatars.has(key);
      avatarButton.classList.toggle('is-locked', !unlocked);
      avatarButton.disabled = !unlocked;
      avatarButton.setAttribute('aria-disabled', unlocked ? 'false' : 'true');
      avatarButton.prepend(createAvatar({
        accessoryKey: this.selectedAccessory,
        avatarKey: key,
      }));
      avatarButton.addEventListener('click', () => {
        this.selectedAvatar = key;
        avatars.querySelectorAll<HTMLButtonElement>('[data-avatar-key]').forEach((candidate) => {
          const selected = candidate.dataset.avatarKey === key;
          candidate.classList.toggle('is-selected', selected);
          candidate.setAttribute('aria-checked', selected ? 'true' : 'false');
        });
      });
      avatars.append(avatarButton);
    });
    form.append(avatarLabel, avatars);

    const accessories = lookup.rewards?.accessories || [];
    if (accessories.length > 0) {
      const accessoryLabel = element(
        'p',
        'quizgeist-field-label',
        this.s('play:profile:accessory') === 'play:profile:accessory'
          ? 'Accessoire'
          : this.s('play:profile:accessory'),
      );
      const picker = element('div', 'quizgeist-accessory-picker');
      picker.setAttribute('role', 'radiogroup');
      const options = [{key: '', label: 'Ohne', unlocked: true}, ...accessories];
      if (!options.some((entry) => entry.key === this.selectedAccessory && entry.unlocked)) {
        this.selectedAccessory = '';
      }
      options.forEach((accessory) => {
        const accessoryButton = button(
          'quizgeist-accessory-choice',
          accessory.label,
          `accessory-${accessory.key || 'none'}`,
        );
        accessoryButton.dataset.liveAccessoryKey = accessory.key;
        accessoryButton.disabled = !accessory.unlocked;
        accessoryButton.classList.toggle('is-locked', !accessory.unlocked);
        accessoryButton.classList.toggle(
          'is-selected',
          accessory.key === this.selectedAccessory,
        );
        accessoryButton.setAttribute('role', 'radio');
        accessoryButton.setAttribute(
          'aria-checked',
          accessory.key === this.selectedAccessory ? 'true' : 'false',
        );
        accessoryButton.addEventListener('click', () => {
          this.selectedAccessory = accessory.key;
          picker.querySelectorAll<HTMLElement>('[data-live-accessory-key]').forEach((node) => {
            const selected = node.dataset.liveAccessoryKey === accessory.key;
            node.classList.toggle('is-selected', selected);
            node.setAttribute('aria-checked', selected ? 'true' : 'false');
          });
          avatars.querySelectorAll<HTMLButtonElement>('[data-live-avatar-key]').forEach((node) => {
            const avatarKey = node.dataset.liveAvatarKey || 'kiesel';
            node.querySelector('.quizgeist-avatar')?.replaceWith(createAvatar({
              accessoryKey: this.selectedAccessory,
              avatarKey,
            }));
          });
        });
        picker.append(accessoryButton);
      });
      form.append(accessoryLabel, picker);
    }

    const teams = lookup.teamConfig?.teams || lookup.teams || [];
    if (lookup.mode === 'team' && lookup.teamConfig?.source === 'groups') {
      form.append(element(
        'p',
        'quizgeist-player-team-hint',
        this.s('play:profile:teamgroups'),
      ));
      this.selectedTeamKey = '';
    } else if (lookup.mode === 'team' && teams.length > 0) {
      const teamLabel = element(
        'label',
        'quizgeist-field-label',
        this.s('play:profile:team'),
      );
      const teamSelect = element('select', 'quizgeist-text-input');
      teamSelect.dataset.liveTeamKey = '';
      teamSelect.name = 'teamKey';
      teams.forEach((team) => {
        const key = String(team.key ?? team.id);
        teamSelect.append(element('option', '', team.name));
        const option = teamSelect.lastElementChild as HTMLOptionElement;
        option.value = key;
      });
      if (!teams.some((team) => String(team.key ?? team.id) === this.selectedTeamKey)) {
        this.selectedTeamKey = String(teams[0]?.key ?? teams[0]?.id ?? '');
      }
      teamSelect.value = this.selectedTeamKey;
      teamSelect.addEventListener('change', () => {
        this.selectedTeamKey = teamSelect.value;
      });
      teamLabel.append(teamSelect);
      form.append(teamLabel);
    }
    const error = element('p', 'quizgeist-form-error', message);
    error.setAttribute('role', 'alert');
    const submit = element('button', 'quizgeist-player-primary', this.s('play:join:submit'));
    submit.type = 'submit';
    submit.dataset.action = 'join-submit';
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const displayName = nameInput?.value.trim() || '';
      if (lookup.nameMode === 'custom' && displayName === '') {
        error.textContent = this.s('play:profile:namehint');
        nameInput?.focus();
        return;
      }
      void this.join(displayName, error, submit);
    });
    form.append(error, submit);
    card.append(this.brand(), heading, info, form);
    this.stage.replaceChildren(card);
    this.setStageModeScreen('profile');
    nameInput?.focus();
  }

  private async join(
    displayName: string,
    errorNode: HTMLElement,
    submit: HTMLButtonElement,
  ): Promise<void> {
    if (this.submitting) {
      return;
    }
    this.submitting = true;
    submit.disabled = true;
    errorNode.textContent = '';
    try {
      const result = await this.api.post<StateResult>('live_session_join', {
        accessoryKey: this.selectedAccessory,
        avatarKey: this.selectedAvatar,
        displayName,
        joinCode: this.joinCode,
        sessionId: this.currentLookup?.sessionId,
        ...(this.currentLookup?.teamConfig?.source === 'free'
          ? {teamKey: this.selectedTeamKey}
          : {}),
      });
      if (!result.state) {
        throw new LiveApiError(this.s('live:error:request'), 'invalid_response');
      }
      this.storeSession(result.state.sessionId);
      this.applyState(result.state, true);
    } catch (error) {
      if (this.isAuthenticationError(error)) {
        this.renderExpiredSession();
        return;
      }
      errorNode.textContent = error instanceof LiveApiError
        ? error.message
        : this.s('live:error:request');
    } finally {
      this.submitting = false;
      submit.disabled = false;
    }
  }

  private async poll(signal: AbortSignal): Promise<PollIteration> {
    if (!this.currentState) {
      return {pollAfterMs: 1800};
    }
    const result = await this.api.post<PlayerPollResult>('live_player_poll', {
      knownAggregateRevision: this.currentState.aggregateRevision || 0,
      knownQuestionToken: this.currentState.question?.questionToken || '',
      knownStateVersion: this.currentState.stateVersion,
      sessionId: this.currentState.sessionId,
    }, signal);
    this.serverClockOffsetMs = result.serverTimeMs - Date.now();
    if (result.changed && result.state) {
      this.applyState(result.state);
    } else if (
      result.hasAnswered !== this.currentState.hasAnswered
      || result.aggregate !== undefined
      || result.aggregateRevision !== undefined
    ) {
      this.applyState({
        ...this.currentState,
        aggregate: result.aggregate === undefined
          ? this.currentState.aggregate
          : result.aggregate,
        aggregateRevision: result.aggregateRevision === undefined
          ? this.currentState.aggregateRevision
          : Number(result.aggregateRevision || 0),
        hasAnswered: result.hasAnswered,
        serverTimeMs: result.serverTimeMs,
      });
    }
    if (!result.changed
        && this.currentState.phase === 'question'
        && !this.currentState.question
        && result.serverTimeMs < this.currentState.phaseStartedAtMs) {
      // A slightly fast client clock may kick just before the authoritative
      // opening instant. Re-arm that one-shot delivery kick with the corrected
      // server clock instead of waiting a full regular poll interval.
      this.requestedQuestionDelivery = '';
    }
    this.connectionMessage = '';
    this.patchConnection();
    return {pollAfterMs: result.pollAfterMs};
  }

  private pollError(error: unknown, failures: number): {delayMs?: number; stop?: boolean} {
    if (this.isAuthenticationError(error)) {
      this.renderExpiredSession();
      return {stop: true};
    }
    this.connectionMessage = failures > 1
      ? this.s('live:connection:offline')
      : this.s('live:connection:reconnecting');
    this.patchConnection();
    return {};
  }

  private applyState(state: PlayerState, force = false): void {
    const previous = this.currentState;
    if (previous
        && state.sessionId === previous.sessionId
        && state.stateVersion < previous.stateVersion) {
      return;
    }
    state = {
      ...state,
      aggregateRevision: Math.max(0, Number(state.aggregateRevision || 0)),
      podium: Array.isArray(state.podium) ? state.podium : [],
      question: state.question
        ? {
          ...state.question,
          choices: Array.isArray(state.question.choices)
            ? state.question.choices
            : [],
          typeData: state.question.typeData && typeof state.question.typeData === 'object'
            ? state.question.typeData
            : {},
        }
        : null,
      ranking: Array.isArray(state.ranking) ? state.ranking : [],
      teamPodium: Array.isArray(state.teamPodium) ? state.teamPodium : [],
      teamRanking: Array.isArray(state.teamRanking) ? state.teamRanking : [],
    };
    this.scoreBurst = Boolean(previous && state.player.score > previous.player.score);
    this.streakBurst = Boolean(
      previous
      && state.player.streak > previous.player.streak
      && state.player.streak >= 2,
    );
    this.serverClockOffsetMs = state.serverTimeMs - Date.now();
    // F1: the activity may forbid sound entirely. The personal mute button
    // can never override that.
    this.sound.setAllowed(state.soundEnabled !== false);
    this.currentState = state;
    const changedQuestion = previous?.question?.id !== state.question?.id
      || previous?.question?.questionToken !== state.question?.questionToken;
    const changedPhase = previous?.phase !== state.phase;
    const changedAnswer = previous?.hasAnswered !== state.hasAnswered;
    const changedAggregate = previous?.aggregateRevision !== state.aggregateRevision;
    if (changedQuestion) {
      this.answerDraft = null;
      this.answerMessage = '';
      this.tts.stop();
      this.lastCountdownSecond = null;
      this.lastWarningSecond = null;
    }
    if (state.question) {
      this.requestedQuestionDelivery = '';
    }
    if (force || changedQuestion || changedPhase || changedAnswer || changedAggregate
        || previous?.stateVersion !== state.stateVersion) {
      this.renderState();
    } else {
      this.patchClock();
    }
    if (state.phase === 'ended' || state.phase === 'aborted') {
      this.clearStoredSession();
      this.poller.stop();
    } else if (!this.poller.isRunning()) {
      this.poller.start(false);
    }
    if (!previous || changedPhase) {
      this.announcePhase(state.phase);
    }
    this.handleSoundTransition(previous, state);
  }

  private handleSoundTransition(
    previous: PlayerState | null,
    state: PlayerState,
  ): void {
    if (state.phase === 'lobby') {
      this.sound.startLobby();
    } else {
      this.sound.stopLobby();
    }
    if (!previous || previous.phase === state.phase) {
      return;
    }
    this.tts.stop();
    if (state.phase === 'question' && state.phaseStartedAtMs <= this.serverNow(state)) {
      this.sound.play('go');
    } else if (state.phase === 'reveal') {
      if (state.feedback?.correct === true) {
        this.sound.play('correct');
      } else if (state.feedback?.correct === false) {
        this.sound.play('incorrect');
      }
      if (state.player.streak > (previous.player.streak || 0) && state.player.streak >= 2) {
        this.sound.play('streak');
      }
    } else if (state.phase === 'podium') {
      this.sound.play('podium');
    }
  }

  private renderState(): void {
    const state = this.currentState;
    if (!state) {
      this.renderJoin();
      return;
    }
    this.setRootState(state.phase, state);
    // F13: the camera dies with the screen. Every path that leaves the stage
    // stage — reveal, scoreboard, the next question, an aborted session —
    // passes here, so there is exactly ONE place that has to be right.
    if (state.phase !== 'question'
        || !state.question
        || state.question.interactionStage !== 'stage'
        || state.hasAnswered) {
      this.stageCheck?.close();
      this.stageCheck = null;
    }
    let content: HTMLElement;
    if (state.phase === 'lobby') {
      content = this.renderLobby(state);
    } else if (state.phase === 'question') {
      content = this.renderQuestion(state);
    } else if (state.phase === 'reveal') {
      content = this.renderFeedback(state);
    } else if (state.phase === 'scoreboard') {
      content = this.renderScoreboard(state);
    } else if (state.phase === 'podium') {
      content = this.renderPodium(state);
    } else {
      content = this.renderEnded(state);
    }
    const shell = element('section', `quizgeist-player-screen quizgeist-player-screen--${state.phase}`);
    const header = element('header', 'quizgeist-player-header');
    const connection = element('p', 'quizgeist-live-connection', this.connectionMessage);
    connection.dataset.liveConnection = '';
    connection.setAttribute('aria-live', 'polite');
    header.append(
      this.brand(),
      this.scoreChip(state.player),
      createSoundControls(this.sound, this.config.strings || {}),
    );
    shell.append(header, connection, content);
    this.stage.replaceChildren(shell);
    this.setStageModeScreen(state.phase);
    if (state.phase === 'ended' || state.phase === 'aborted') {
      void this.stageMode.leave();
    }
    this.startClock();
    const focusSelector = state.phase === 'question'
      && state.question
      && !state.hasAnswered
      ? '[data-question-heading]'
      : ['reveal', 'scoreboard', 'podium', 'ended', 'aborted'].includes(state.phase)
        ? '[data-phase-heading]'
        : '';
    if (focusSelector !== '') {
      window.setTimeout(() => {
        this.stage.querySelector<HTMLElement>(focusSelector)?.focus();
      }, 0);
    }
  }

  private renderLobby(state: PlayerState): HTMLElement {
    const main = element('main', 'quizgeist-player-main quizgeist-player-lobby');
    const avatar = createAvatar({
      accessoryKey: state.player.accessoryKey,
      avatarKey: state.player.avatarKey,
    });
    avatar.classList.add('quizgeist-player-avatar--large');
    const title = element('h2', 'quizgeist-player-title', state.player.displayName);
    const status = element('p', 'quizgeist-player-waiting', this.s('play:lobby:ready'));
    const count = element(
      'p',
      'quizgeist-player-count',
      this.s('play:lobby:count', {a: state.playerCount}),
    );
    count.setAttribute('aria-live', 'polite');
    main.append(avatar, title, status, count);
    const teamName = state.player.team?.teamName || state.player.teamName;
    if (teamName) {
      main.append(element('p', 'quizgeist-player-team', `Team ${teamName}`));
    }
    return main;
  }

  private renderQuestion(state: PlayerState): HTMLElement {
    const now = this.serverNow(state);
    if (state.phaseStartedAtMs > now) {
      const countdown = element('main', 'quizgeist-player-main quizgeist-player-countdown');
      countdown.append(
        element('p', 'quizgeist-player-kicker', this.s('play:countdown:ready')),
        element('strong', 'quizgeist-countdown-number'),
      );
      return countdown;
    }
    const question = state.question;
    if (!question) {
      const waiting = element('main', 'quizgeist-player-main quizgeist-player-countdown');
      waiting.append(
        element('p', 'quizgeist-player-kicker', this.s('play:countdown:ready')),
        element('strong', 'quizgeist-countdown-number', '…'),
      );
      return waiting;
    }
    const main = element('main', 'quizgeist-player-main quizgeist-player-question');
    const meta = element(
      'p',
      'quizgeist-player-kicker',
      this.s('play:question:progress', {
        current: question.index + 1,
        total: question.total,
      }),
    );
    const title = element('h2', 'quizgeist-question-title', question.questionText);
    title.id = 'quizgeist-live-question-heading';
    title.dataset.questionHeading = '';
    title.tabIndex = -1;
    // F1: with the countdown switched off no timer element exists at all.
    // The question still ends on schedule — the server owns the clock.
    const showtimer = state.timerVisible !== false;
    main.append(meta, title);
    if (showtimer) {
      const timer = element('div', 'quizgeist-player-timer');
      timer.dataset.liveTimer = '';
      timer.setAttribute('role', 'timer');
      timer.setAttribute(
        'aria-label',
        this.s('host:question:remaining', {a: question.timeLimit}),
      );
      main.append(timer);
    }
    main.append(
      createTtsControl(this.tts, questionSpeechText(question), this.config),
    );
    if (!['pin', 'reveal', 'slide'].includes(question.qtype)) {
      const questionMedia = createLiveMedia(question, {
        className: 'quizgeist-question-media',
        label: this.s('editor:field:media'),
      });
      if (questionMedia) {
        questionMedia.dataset.questionMedia = '';
        main.append(questionMedia);
      }
    }
    // F2 Denk-Moment: the server moved this question into the reason stage.
    // The learner writes one short justification; it never earns points.
    if (question.interactionStage === 'reason') {
      main.append(this.renderReason(state));
      return main;
    }
    // F13 Bühnen-Check: the server moved this question into the stage. The
    // learner presents the answer in front of the camera; the analysis runs in
    // the browser and nothing but numbers ever leaves the device.
    if (question.interactionStage === 'stage') {
      main.append(this.renderStageCheck(state));
      return main;
    }
    const repeated = questionAllowsRepeatedSubmissions(question);
    if (state.hasAnswered && !repeated) {
      const waiting = element('div', 'quizgeist-answer-waiting');
      waiting.setAttribute('role', 'status');
      waiting.append(
        element('strong', '', this.s('play:answer:submitted')),
        element('p', '', this.s('play:answer:waiting')),
      );
      main.append(waiting);
      return main;
    }
    if (this.answerMessage !== '') {
      const message = element('p', 'quizgeist-form-error', this.answerMessage);
      message.setAttribute('role', 'alert');
      main.append(message);
    }
    let response: HTMLElement;
    response = renderLiveResponse(question, {
      aggregate: state.aggregate,
      answer: this.answerDraft,
      audience: 'player',
      disabled: state.phaseEndsAtMs > 0 && state.phaseEndsAtMs <= now,
      interactive: true,
      nowMs: now,
      phaseStartedAtMs: state.phaseStartedAtMs,
      onChange: (answer) => {
        this.answerDraft = answer;
      },
      onSubmit: (answer) => {
        if (answer) {
          void this.submitAnswer(
            answer,
            response.querySelector<HTMLButtonElement>('[data-live-submit]') || undefined,
          );
        }
      },
      onTap: () => {
        void this.sound.unlock();
        this.sound.play('tap');
      },
      text: (key, fallback, values = {}) => this.s(key, values, fallback),
    });
    response.setAttribute('aria-labelledby', title.id);
    main.append(response);
    window.setTimeout(() => {
      if (!state.hasAnswered || repeated) {
        response.querySelector<HTMLInputElement | HTMLTextAreaElement>(
          '[data-live-text-answer]',
        )?.focus();
      }
    }, 0);
    return main;
  }

  /**
   * F13 Bühnen-Check: the presentation stage of an open question or a slide.
   *
   * The screen is built here, the camera lives in the addon bundle. When the
   * addon code package is absent the server never sends this stage at all, so
   * there is nothing to hide and nothing to lock.
   *
   * @param state Current player state.
   * @returns The stage surface.
   */
  private renderStageCheck(state: PlayerState): HTMLElement {
    const block = element('div', 'quizgeist-player-stagecheck');
    block.dataset.liveStage = '';
    if (state.hasAnswered) {
      const done = element('div', 'quizgeist-answer-waiting');
      done.setAttribute('role', 'status');
      done.append(
        element('strong', '', this.s('stage:sent')),
        element('p', '', this.s('play:answer:waiting')),
      );
      block.append(done);
      return block;
    }
    if (this.stageCheck !== null) {
      this.stageCheck.close();
    }
    const control = new StageCheckControl(
      this.config,
      state.question ? state.question.id : 0,
      null,
      (report) => {
        void this.submitAnswer(
          {kind: 'stage', reportId: report.id} as unknown as LiveAnswer,
        );
      },
    );
    this.stageCheck = control;
    void control.init();
    block.append(control.root);
    return block;
  }

  /**
   * F2 Denk-Moment: one short justification between answer and reveal.
   *
   * The stage is server-owned; this screen only collects the text. A reason
   * carries no points and no correctness, and the copy says so.
   */
  private renderReason(state: PlayerState): HTMLElement {
    const block = element('div', 'quizgeist-player-reason');
    block.dataset.liveReason = '';
    if (state.hasAnswered) {
      const done = element('div', 'quizgeist-answer-waiting');
      done.setAttribute('role', 'status');
      done.append(
        element('strong', '', this.s('play:reason:sent')),
        element('p', '', this.s('play:answer:waiting')),
      );
      block.append(done);
      return block;
    }
    const heading = element(
      'h3',
      'quizgeist-player-reason__title',
      this.s('play:reason:title'),
    );
    const hint = element(
      'p',
      'quizgeist-player-reason__hint',
      this.s('play:reason:hint'),
    );
    const field = element('textarea', 'quizgeist-player-reason__input');
    field.dataset.liveTextAnswer = '';
    field.setAttribute('rows', '4');
    field.setAttribute('maxlength', '2000');
    field.setAttribute('placeholder', this.s('play:reason:placeholder'));
    field.setAttribute('aria-label', this.s('play:reason:title'));
    const send = element(
      'button',
      'quizgeist-player-button quizgeist-player-button--primary',
      this.s('play:reason:send'),
    );
    send.setAttribute('type', 'button');
    send.dataset.liveSubmit = '';
    send.addEventListener('click', () => {
      const text = (field as HTMLTextAreaElement).value.trim();
      if (text === '') {
        field.focus();
        return;
      }
      void this.sound.unlock();
      this.sound.play('tap');
      void this.submitAnswer({kind: 'text', text} as LiveAnswer, send);
    });
    if (this.answerMessage !== '') {
      const message = element('p', 'quizgeist-form-error', this.answerMessage);
      message.setAttribute('role', 'alert');
      block.append(message);
    }
    block.append(heading, hint, field, send);
    // P11/F2 Sprechvariante: Der Denk-Moment darf gesprochen werden. Der
    // Aufnahmeknopf steht NEBEN dem Textfeld und nicht an seiner Stelle —
    // wer lieber tippt, tippt, und wer kein Mikrofon hat, verliert nichts.
    const spoken = this.spokenControl('reason', (clip) => {
      void this.submitAnswer({kind: 'clip', clipId: clip.id} as LiveAnswer, send);
    });
    if (spoken !== null) {
      block.append(spoken.root);
    }
    return block;
  }

  /**
   * Build the recording control, or nothing at all.
   *
   * Returns null when the AI addon is absent or the person may not record.
   * That is the "no locked bait" rule of 2.6 taken literally: an unusable
   * button is worse than no button.
   *
   * [P11-E2]: the addon report is asked FIRST and by name. `canRecord` already
   * carries it today, but it is a derived permission, and a later change to
   * its meaning must not silently re-open a path to `clip_transcribe` and
   * `clip_state` — two actions the dispatcher does not know without the AI
   * addon.
   */
  private spokenControl(
    purpose: 'answer' | 'reason',
    onClip: (clip: UploadedClip) => void,
  ): SpokenAnswerControl | null {
    const clips = this.config.clips;
    if (this.config.features?.ai?.installed !== true
        || clips === undefined
        || !clips.canRecord
        || !SpokenAnswerControl.available()) {
      return null;
    }
    const control = new SpokenAnswerControl(
      this.config.strings,
      {
        uploadUrl: clips.uploadUrl,
        sesskey: this.config.sesskey,
        cmid: this.config.cmid,
        purpose,
        language: clips.language,
        maxBytes: clips.maxBytes,
        maxSeconds: clips.maxSeconds,
        transcriptionAvailable: clips.canTranscribe,
      },
      {
        onClip,
        transcribe: (clipId) => this.api.post<{clip: UploadedClip}>(
          'clip_transcribe',
          {clipId},
        ).then((result) => result.clip),
        pollState: (clipIds) => this.api.post<{clips: UploadedClip[]}>(
          'clip_state',
          {clipIds},
        ).then((result) => result.clips),
      },
    );
    this.spokenControls.push(control);
    return control;
  }

  private submissionKey(
    question: LiveQuestion,
    canonical: Record<string, unknown>,
  ): {cacheKey: string; value: string} {
    const cacheKey = `${question.questionToken}:${JSON.stringify(canonical)}`;
    const existing = this.submissionKeys.get(cacheKey);
    if (existing) {
      return {cacheKey, value: existing};
    }
    const value = typeof window.crypto?.randomUUID === 'function'
      ? window.crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
    this.submissionKeys.set(cacheKey, value);
    return {cacheKey, value};
  }

  private async submitAnswer(
    answer: LiveAnswer,
    buttonNode?: HTMLButtonElement,
  ): Promise<void> {
    const state = this.currentState;
    const question = state?.question;
    if (!state || !question || this.submitting) {
      return;
    }
    this.submitting = true;
    if (buttonNode) {
      buttonNode.disabled = true;
    }
    const canonical = answerPayload(answer);
    const repeated = questionAllowsRepeatedSubmissions(question);
    const submission = repeated
      ? this.submissionKey(question, canonical)
      : null;
    try {
      const result = await this.api.post<AnswerAck>('live_answer', {
        answer: canonical,
        ...('choiceIds' in canonical ? {choiceIds: canonical.choiceIds} : {}),
        questionId: question.id,
        questionToken: question.questionToken,
        sessionId: state.sessionId,
        ...(submission ? {submissionKey: submission.value} : {}),
      });
      if (result.accepted !== true
          || !Number.isInteger(result.stateVersion)) {
        throw new LiveApiError(this.s('live:error:request'), 'invalid_response');
      }
      if (this.currentState?.sessionId === state.sessionId
          && this.currentState.question?.questionToken === question.questionToken) {
        this.answerMessage = '';
        if (questionAllowsRepeatedSubmissions(question)) {
          this.answerDraft = null;
        }
        this.applyState({
          ...this.currentState,
          hasAnswered: result.hasAnswered === true,
          stateVersion: Math.max(
            this.currentState.stateVersion,
            result.stateVersion,
          ),
        }, true);
      }
      if (submission) {
        this.submissionKeys.delete(submission.cacheKey);
      }
      this.poller.kick();
    } catch (error) {
      if (error instanceof LiveApiError && error.status === 409 && error.data?.state) {
        this.applyState(error.data.state as unknown as PlayerState, true);
      } else if (error instanceof LiveApiError && error.code === 'answer_too_late') {
        this.answerMessage = this.s('play:answer:toolate');
        this.renderState();
        this.poller.kick();
      } else if (error instanceof LiveApiError && error.code === 'question_not_open') {
        this.answerMessage = error.message;
        this.renderState();
        this.poller.kick();
      } else if (this.isAuthenticationError(error)) {
        this.renderExpiredSession();
      } else {
        this.connectionMessage = error instanceof LiveApiError
          ? error.message
          : this.s('live:error:request');
        this.patchConnection();
        if (buttonNode) {
          buttonNode.disabled = false;
        }
      }
    } finally {
      this.submitting = false;
    }
  }

  private renderFeedback(state: PlayerState): HTMLElement {
    const main = element('main', 'quizgeist-player-main quizgeist-player-feedback');
    const feedback = state.feedback;
    let title = this.s('play:answer:waiting');
    let modifier = 'neutral';
    if (feedback?.correct === true) {
      title = this.s('play:feedback:correct');
      modifier = 'correct';
    } else if (feedback?.correct === false) {
      // F1: a question marked as a new topic gets an encouraging line
      // instead of "Wrong". The server decides; the client only renders.
      title = state.question?.friendlyNew === true
        ? this.s('play:friendlynew')
        : this.s('play:feedback:incorrect');
      modifier = state.question?.friendlyNew === true
        ? 'friendlynew'
        : 'incorrect';
    } else if (feedback) {
      title = this.s('play:feedback:poll');
      modifier = 'poll';
    }
    main.classList.add(`quizgeist-player-feedback--${modifier}`);
    const heading = element('h2', 'quizgeist-player-title', title);
    heading.dataset.phaseHeading = '';
    heading.tabIndex = -1;
    main.append(heading);
    if (feedback) {
      main.append(element(
        'strong',
        `quizgeist-feedback-points${this.scoreBurst ? ' is-score-pop' : ''}`,
        this.s('play:feedback:points', {a: feedback.points}),
      ));
    }
    main.append(
      element(
        'p',
        'quizgeist-feedback-rank',
        this.s('play:scoreboard:rank', {a: state.ownRank ?? '–'}),
      ),
      this.streak(state.player.streak),
    );
    return main;
  }

  private renderScoreboard(state: PlayerState): HTMLElement {
    const main = element('main', 'quizgeist-player-main quizgeist-player-scoreboard');
    const rank = element('h2', 'quizgeist-player-rank', `#${state.ownRank}`);
    rank.dataset.phaseHeading = '';
    rank.tabIndex = -1;
    rank.setAttribute(
      'aria-label',
      `${this.s('live:phase:scoreboard')}: #${state.ownRank}`,
    );
    main.append(
      element('p', 'quizgeist-player-kicker', this.s('live:phase:scoreboard')),
      rank,
      element('p', 'quizgeist-player-title', state.player.displayName),
      element('strong', 'quizgeist-player-total', `${state.player.score} ${this.s('live:points')}`),
      this.streak(state.player.streak),
    );
    return main;
  }

  private renderPodium(state: PlayerState): HTMLElement {
    const main = element('main', 'quizgeist-player-main quizgeist-player-podium');
    const isTopThree = state.ownRank !== null
      && state.ownRank > 0
      && state.ownRank <= 3;
    const rank = element(
      'h2',
      'quizgeist-player-rank',
      state.ownRank === null ? '–' : `#${state.ownRank}`,
    );
    rank.dataset.phaseHeading = '';
    rank.tabIndex = -1;
    rank.setAttribute(
      'aria-label',
      `${this.s('live:phase:podium')}: ${state.ownRank === null ? '–' : `#${state.ownRank}`}`,
    );
    main.append(
      element('p', 'quizgeist-player-kicker', this.s('play:podium:title')),
      createAvatar({
        accessoryKey: state.player.accessoryKey,
        avatarKey: state.player.avatarKey,
      }),
      rank,
      element(
        'p',
        'quizgeist-player-title',
        isTopThree
          ? this.s('play:podium:topthree')
          : this.s('play:podium:encouragement'),
      ),
      element('strong', 'quizgeist-player-total', `${state.player.score} ${this.s('live:points')}`),
    );
    const teamPodium = state.mode === 'team' && (state.teamPodium?.length || 0) > 0;
    const list = element(
      'ol',
      `quizgeist-player-podium-list${teamPodium ? ' quizgeist-team-podium' : ''}`,
    );
    if (teamPodium) {
      list.dataset.liveTeamPodium = '';
    }
    const entries = teamPodium ? state.teamPodium || [] : state.podium;
    entries.forEach((standing) => {
      const item = element('li', 'quizgeist-player-podium-entry');
      if ('playerId' in standing) {
        item.dataset.playerId = String(standing.playerId);
      } else {
        item.dataset.teamId = String(standing.teamKey || standing.id || '');
      }
      item.dataset.rank = String(standing.rank);
      item.append(
        element('span', 'quizgeist-player-podium-rank', String(standing.rank)),
        'playerId' in standing
          ? createAvatar({
            accessoryKey: standing.accessoryKey,
            avatarKey: standing.avatarKey,
          })
          : element('span', 'quizgeist-team-podium__spark', '✦'),
        element(
          'span',
          'quizgeist-player-podium-name',
          'playerId' in standing
            ? standing.displayName
            : standing.teamName || standing.name || '',
        ),
        element('strong', '', String(standing.score)),
      );
      list.append(item);
    });
    main.append(list);
    return main;
  }

  private renderEnded(state: PlayerState): HTMLElement {
    const main = this.renderPodium(state);
    main.classList.add('quizgeist-player-ended');
    const overview = element('a', 'quizgeist-player-primary', this.s('play:ended:overview'));
    overview.href = this.config.overviewUrl
      || this.config.playerUrlBase
      || window.location.pathname;
    overview.dataset.action = 'overview';
    overview.addEventListener('click', () => this.clearStoredSession());
    main.append(overview);
    return main;
  }

  private scoreChip(player: PlayerSummary): HTMLElement {
    const chip = element(
      'div',
      `quizgeist-player-score-chip${this.scoreBurst ? ' is-score-pop' : ''}`,
    );
    chip.dataset.liveScore = String(player.score);
    chip.append(
      element('strong', '', String(player.score)),
      element('span', '', this.s('live:points')),
    );
    return chip;
  }

  private streak(value: number): HTMLElement {
    const node = element(
      'p',
      `quizgeist-player-streak${this.streakBurst ? ' is-streak-burst' : ''}`,
    );
    node.dataset.streak = String(value);
    node.append(
      element('span', 'quizgeist-player-streak__flame', '◆'),
      document.createTextNode(` ${this.s('live:streak')}: ${value}`),
    );
    return node;
  }

  private startClock(): void {
    this.stopClock();
    this.patchClock();
    this.clockId = window.setInterval(() => this.patchClock(), 250);
  }

  private stopClock(): void {
    if (this.clockId !== null) {
      window.clearInterval(this.clockId);
      this.clockId = null;
    }
  }

  private patchClock(): void {
    const state = this.currentState;
    if (!state) {
      return;
    }
    const now = this.serverNow(state);
    patchLiveResponseClock(this.root, now);
    const countdown = this.root.querySelector<HTMLElement>('.quizgeist-countdown-number');
    if (countdown) {
      const remaining = state.phaseStartedAtMs - now;
      if (remaining <= 0) {
        if (!state.question) {
          const deliveryKey = `${state.sessionId}:${state.stateVersion}`;
          if (this.requestedQuestionDelivery !== deliveryKey) {
            this.requestedQuestionDelivery = deliveryKey;
            this.poller.kick();
          }
          countdown.textContent = '…';
        } else {
          this.sound.play('go');
          this.renderState();
        }
        return;
      }
      const second = Math.max(1, Math.ceil(remaining / 1000));
      countdown.textContent = String(second);
      if (second !== this.lastCountdownSecond) {
        this.lastCountdownSecond = second;
        this.sound.play('countdown');
      }
    }
    const timer = this.root.querySelector<HTMLElement>('[data-live-timer]');
    if (timer) {
      if (state.phaseEndsAtMs <= 0) {
        timer.textContent = '∞';
        timer.classList.remove('is-warning');
        timer.setAttribute('aria-label', this.s('editor:time:none'));
        return;
      }
      const remaining = Math.max(0, state.phaseEndsAtMs - now);
      const second = Math.ceil(remaining / 1000);
      timer.textContent = String(second);
      timer.classList.toggle('is-warning', remaining > 0 && remaining <= 5000);
      if (second > 0 && second <= 5 && second !== this.lastWarningSecond) {
        this.lastWarningSecond = second;
        this.sound.play('warning');
      }
      if (remaining === 0) {
        this.root.querySelectorAll<HTMLButtonElement>(
          '[data-live-answer-kind] button, [data-live-answer-kind] input, '
          + '[data-live-answer-kind] textarea, [data-live-answer-kind] select',
        )
          .forEach((control) => {
            control.disabled = true;
          });
      }
    }
  }

  private serverNow(_state: PlayerState): number {
    return Date.now() + this.serverClockOffsetMs;
  }

  private patchConnection(): void {
    const node = this.root.querySelector<HTMLElement>('[data-live-connection]');
    if (node) {
      node.textContent = this.connectionMessage;
      node.hidden = this.connectionMessage === '';
    }
  }

  private setRootState(phase: string, state: PlayerState | null): void {
    this.root.dataset.livePhase = phase;
    this.root.dataset.stateReceivedAt = String(Date.now());
    if (!state) {
      delete this.root.dataset.sessionId;
      delete this.root.dataset.stateVersion;
      delete this.root.dataset.questionId;
      delete this.root.dataset.questionRootId;
      delete this.root.dataset.questionVersion;
      return;
    }
    this.root.dataset.sessionId = String(state.sessionId);
    this.root.dataset.stateVersion = String(state.stateVersion);
    if (state.question) {
      this.root.dataset.questionId = String(state.question.id);
      this.root.dataset.questionRootId = String(state.question.rootId);
      this.root.dataset.questionVersion = String(state.question.version);
    } else {
      delete this.root.dataset.questionId;
      delete this.root.dataset.questionRootId;
      delete this.root.dataset.questionVersion;
    }
  }

  private setStageModeScreen(screen: PlayerStageModeScreen): void {
    this.stageModeScreen = screen;
    this.syncStageModeVisibility(this.stageMode.mode());
  }

  private syncStageModeVisibility(mode: StageMode): void {
    const terminal = this.stageModeScreen === 'ended'
      || this.stageModeScreen === 'aborted'
      || this.stageModeScreen === 'fatal';
    const visible = this.stageMode.isAvailable() && (
      this.stageModeScreen === 'lobby'
      || this.stageModeScreen === 'reveal'
      || this.stageModeScreen === 'scoreboard'
      || this.stageModeScreen === 'podium'
      || (this.stageModeScreen === 'question' && mode === 'fullscreen')
      || (terminal && mode === 'fullscreen')
    );
    this.fullscreenBar.hidden = !visible;
    this.fullscreenToggle.hidden = !visible;
  }

  private announceStageMode(message: string): void {
    this.liveRegion.textContent = '';
    window.requestAnimationFrame(() => {
      this.liveRegion.textContent = message;
    });
  }

  private focusStageModeFallback(): void {
    const target = this.stage.querySelector<HTMLElement>('[data-phase-heading]')
      || this.stage.querySelector<HTMLElement>('[data-action="recover"]')
      || this.stage;
    if (target === this.stage) {
      this.stage.tabIndex = -1;
    }
    target.focus();
  }

  private renderFatal(message: string, actionLabel: string, action: () => void): void {
    this.poller.stop();
    this.stopClock();
    const card = element('section', 'quizgeist-player-card quizgeist-player-fatal');
    card.setAttribute('role', 'alert');
    const retry = button('quizgeist-player-primary', actionLabel, 'recover');
    retry.addEventListener('click', action);
    card.append(this.brand(), element('h2', 'quizgeist-player-title', message), retry);
    this.stage.replaceChildren(card);
    this.setStageModeScreen('fatal');
    void this.stageMode.leave();
  }

  private handleActionError(error: unknown, retry: () => void): void {
    if (this.isAuthenticationError(error)) {
      this.renderExpiredSession();
      return;
    }
    const message = error instanceof LiveApiError
      ? error.message
      : this.s('live:error:request');
    this.renderFatal(message, this.s('host:action:retry'), retry);
  }

  private isAuthenticationError(error: unknown): boolean {
    return error instanceof LiveApiError
      && (
        error.status === 401
        || error.code === 'session_expired'
        || (error.status === 403 && error.code === 'invalid_sesskey')
      );
  }

  private isTerminalPhase(phase: LivePhase): boolean {
    return phase === 'ended' || phase === 'aborted';
  }

  private renderExpiredSession(): void {
    this.renderFatal(
      this.s('live:connection:expired'),
      this.s('live:connection:reload'),
      () => window.location.reload(),
    );
  }

  private announcePhase(phase: LivePhase): void {
    const key = phase === 'aborted' ? 'live:phase:ended' : `live:phase:${phase}`;
    const message = this.s(key);
    this.liveRegion.textContent = '';
    window.requestAnimationFrame(() => {
      if (this.currentState?.phase === phase) {
        this.liveRegion.textContent = message;
      }
    });
  }

  private storedSessionId(): number | null {
    try {
      const value = window.sessionStorage.getItem(this.reconnectKey);
      return value && /^[1-9][0-9]*$/.test(value) ? Number(value) : null;
    } catch (_error) {
      return null;
    }
  }

  private storeSession(sessionId: number): void {
    try {
      window.sessionStorage.setItem(this.reconnectKey, String(sessionId));
    } catch (_error) {
      // Private browsing policies may disable storage; polling still works.
    }
  }

  private clearStoredSession(): void {
    try {
      window.sessionStorage.removeItem(this.reconnectKey);
    } catch (_error) {
      // Nothing else is required when storage is unavailable.
    }
    try {
      window.localStorage.removeItem(this.reconnectKey);
    } catch (_error) {
      // Legacy persistent reconnect storage may be unavailable in private mode.
    }
  }
}

export function mountPlayerApp(raw: HostConfig): void {
  const config = normaliseConfig(raw);
  const containerId = typeof raw.containerId === 'string' && raw.containerId
    ? raw.containerId
    : 'quizgeist-app-play';
  const root = document.getElementById(containerId);
  if (!root) {
    return;
  }
  if (!config) {
    const alert = element('div', 'quizgeist-player-card', raw.strings?.['live:error:config']
      || 'live:error:config');
    alert.setAttribute('role', 'alert');
    root.replaceChildren(alert);
    return;
  }
  const app = new PlayerApp(root, config);
  void app.init();
}
