import {LiveApi, LiveApiError} from '../live/api';
import {createAvatar} from '../live/avatar';
import {
  appendChildren,
  formatJoinCode,
  isAbortError,
  liveButton,
  liveElement,
  liveVisuallyHidden,
  setNodeText,
} from '../live/dom';
import {
  AdaptivePoller,
  type PollErrorDecision,
  type PollIteration,
} from '../live/poller';
import {createLiveMedia} from '../live/media';
import {renderLocalQrCode} from '../live/qr';
import {
  patchLiveResponseClock,
  questionSpeechText,
  renderLiveAggregate,
  renderLiveResponse,
} from '../live/qtype/registry';
import {createSoundControls, SoundEngine} from '../live/sound-engine';
import {
  decorateDistribution,
  normaliseHingeStatus,
  type MisconceptionRow,
} from './misconception-panel';
import {CardScanPanel} from './card-scan';
import {liveString, type StringValues} from '../live/strings';
import {createTtsControl, TtsPlayer} from '../live/tts';
import type {
  HostCommand,
  HostConfig,
  HostPollResult,
  LiveHostSetup,
  HostState,
  HostStateResult,
  LivePlayer,
  LiveQuestion,
  LiveStanding,
  LiveTeamStanding,
} from '../live/types';
import {retryHostMutation} from './conflict-retry';
import {StageModeController} from '../live/stage-mode';

const ACTIVE_PHASES = new Set([
  'lobby',
  'question',
  'reveal',
  'scoreboard',
  'podium',
]);

const CONFETTI_COLORS = ['a', 'b', 'c', 'd', 'e'] as const;

interface BrainstormEditorGroup {
  ideas: Array<{id: string; text: string}>;
  key: string;
  label: string;
}

interface BrainstormGrouping {
  ideaIds: number[];
  key: string;
  label: string;
}

export class HostApp {
  private readonly api: LiveApi;
  private readonly poller: AdaptivePoller;

  /**
   * F11a: exists only while a question is on screen AND the AI addon shipped a
   * card configuration. Held on the app rather than rebuilt per render so a
   * 1–2 s poll cannot tear the camera down under the teacher's thumb.
   */
  private cardScan: CardScanPanel | null = null;
  private readonly reconnectKey: string;
  private bootstrapController: AbortController | null = null;
  private clockOffsetMs = 0;
  private clockTimer: number | null = null;
  private commandBusy = false;
  private connectionBanner: HTMLDivElement | null = null;
  private connectionTroubled = false;
  private currentScreenKey = '';
  private lastTimerAnnouncement: number | null = null;
  private lastWarningSecond: number | null = null;
  private liveRegion: HTMLDivElement | null = null;
  private readiness: HostStateResult['readiness'] | null = null;
  private setup: LiveHostSetup = {};
  private readonly sound = new SoundEngine();
  private stage: HTMLElement | null = null;
  private toolbar: HTMLElement | null = null;
  private toolbarNav: HTMLElement | null = null;
  private stageMode: StageModeController | null = null;
  private abortDialog: HTMLDialogElement | null = null;
  private state: HostState | null = null;
  private readonly tts: TtsPlayer;

  public constructor(
    private readonly root: HTMLElement,
    private readonly config: HostConfig,
  ) {
    this.api = new LiveApi(config);
    this.tts = new TtsPlayer(config);
    this.reconnectKey = `mod_quizgeist:live-host:${config.cmid}`;
    this.poller = new AdaptivePoller(
      (signal) => this.poll(signal),
      (error, failures) => this.handlePollError(error, failures),
    );
  }

  public async init(): Promise<void> {
    this.root.classList.add('quizgeist-host-root');
    this.root.dataset.quizgeistRoot = 'host';
    this.root.dataset.quizgeistTheme = this.config.theme || 'hell';
    this.root.dataset.quizgeistSeason = this.config.season || 'herbst';
    this.root.replaceChildren();

    this.connectionBanner = liveElement('div', 'quizgeist-host-connection', {
      'aria-live': 'polite',
      hidden: true,
      role: 'status',
      'data-live-connection': true,
    });
    this.liveRegion = liveElement('div', 'quizgeist-live-visually-hidden', {
      'aria-atomic': 'true',
      'aria-live': 'polite',
      role: 'status',
    });
    this.stage = liveElement('section', 'quizgeist-host-stage', {
      'aria-busy': 'true',
      'data-live-screen': 'loading',
    });
    this.toolbar = liveElement('div', 'quizgeist-host-toolbar', {hidden: true});
    this.toolbarNav = liveElement('div', 'quizgeist-host-toolbar__nav');
    this.stageMode = new StageModeController(this.root, {
      announce: (message) => this.announce(message),
      text: (key, fallback) => this.text(key, fallback),
    });
    this.toolbar.append(this.toolbarNav, this.stageMode.createToggle());
    this.root.append(this.connectionBanner, this.liveRegion, this.stage, this.toolbar);
    this.stageMode.attach();
    this.root.addEventListener('pointerdown', this.unlockSound, {once: true});
    this.renderLoading();

    this.clockTimer = window.setInterval(() => this.tickClock(), 250);
    window.addEventListener('online', this.handleOnline);
    await this.bootstrap();
  }

  public destroy(): void {
    this.stageMode?.detach();
    void this.stageMode?.leave();
    if (this.abortDialog) {
      this.abortDialog.remove();
      this.abortDialog = null;
    }
    this.bootstrapController?.abort();
    this.bootstrapController = null;
    this.poller.stop();
    if (this.clockTimer !== null) {
      window.clearInterval(this.clockTimer);
      this.clockTimer = null;
    }
    window.removeEventListener('online', this.handleOnline);
    this.destroyCardScan();
    this.sound.destroy();
    this.tts.stop();
  }

  private readonly unlockSound = (): void => {
    void this.sound.unlock().then(() => {
      if (this.state?.phase === 'lobby') {
        this.sound.startLobby();
      }
    });
  };

  private readonly handleOnline = (): void => {
    if (this.state && ACTIVE_PHASES.has(this.state.phase)) {
      this.poller.kick();
    } else if (!this.state) {
      void this.bootstrap();
    }
  };

  private text(
    key: string,
    fallback: string,
    values: StringValues = {},
  ): string {
    return liveString(this.config.strings, key, values, fallback);
  }

  private setTabsVisible(visible: boolean): void {
    const tabsElementId = this.config.tabsElementId;
    if (tabsElementId) {
      const tabs = this.root.ownerDocument.getElementById(tabsElementId);
      if (tabs) {
        tabs.hidden = !visible;
      }
    }
    if (this.toolbar) {
      this.toolbar.hidden = visible;
      if (visible) {
        this.toolbarNav?.replaceChildren();
      }
    }
  }

  private announce(message: string): void {
    if (!this.liveRegion) {
      return;
    }
    this.liveRegion.textContent = '';
    window.requestAnimationFrame(() => {
      if (this.liveRegion) {
        this.liveRegion.textContent = message;
      }
    });
  }

  private renderLoading(): void {
    this.setTabsVisible(true);
    if (!this.stage) {
      return;
    }
    this.stage.dataset.liveScreen = 'loading';
    this.stage.setAttribute('aria-busy', 'true');
    const loading = liveElement('div', 'quizgeist-host-state-card', {
      role: 'status',
    });
    const spinner = liveElement('span', 'quizgeist-host-spinner', {
      'aria-hidden': 'true',
    });
    appendChildren(
      loading,
      spinner,
      liveElement('p', 'quizgeist-host-state-card__text', {
        text: this.text(
          'live:connection:loading',
          'Live-Session wird geladen …',
        ),
      }),
    );
    this.stage.replaceChildren(loading);
  }

  private async bootstrap(useStoredSession = true): Promise<void> {
    this.bootstrapController?.abort();
    this.bootstrapController = new AbortController();
    this.renderLoading();
    const sessionId = useStoredSession ? this.storedSessionId() : null;
    try {
      const result = await this.api.post<HostStateResult>(
        'live_host_bootstrap',
        sessionId ? {sessionId} : {},
        this.bootstrapController.signal,
      );
      this.setup = result.setup || {};
      this.readiness = result.readiness || null;
      if (result.state) {
        this.applyState(result.state, true);
      } else {
        this.clearStoredSession();
        this.state = null;
        this.poller.stop();
        this.renderSetup();
      }
    } catch (error) {
      if (isAbortError(error)) {
        return;
      }
      if (error instanceof LiveApiError
          && error.status === 404
          && sessionId) {
        this.clearStoredSession();
        await this.bootstrap(false);
        return;
      }
      if (this.isAuthenticationError(error)) {
        this.renderFatalConnection(error);
      } else {
        this.renderBootstrapError(error);
      }
    }
  }

  private async poll(signal: AbortSignal): Promise<PollIteration> {
    const state = this.state;
    if (!state || !ACTIVE_PHASES.has(state.phase)) {
      this.poller.stop();
      return {};
    }
    const result = await this.api.post<HostPollResult>(
      'live_host_poll',
      {
        knownAggregateRevision: state.aggregateRevision || 0,
        knownStateVersion: state.stateVersion,
        sessionId: state.sessionId,
      },
      signal,
    );
    this.updateClock(result.serverTimeMs);
    if (result.changed && result.state) {
      this.applyState(result.state);
    } else if (this.state
        && this.state.sessionId === state.sessionId
        && Number(result.stateVersion) >= this.state.stateVersion) {
      this.state = {
        ...this.state,
        aggregate: result.aggregate === undefined
          ? this.state.aggregate
          : result.aggregate,
        aggregateRevision: Number.isFinite(result.aggregateRevision)
          ? Number(result.aggregateRevision)
          : this.state.aggregateRevision,
        answerCount: Number.isFinite(result.answerCount)
          ? Math.max(0, Number(result.answerCount))
          : this.state.answerCount,
        distribution: Array.isArray(result.distribution)
          ? result.distribution
          : this.state.distribution,
        // F5: the traffic light travels with the aggregate, so an unchanged
        // state version must not resurrect a stale verdict.
        hingeStatus: result.hingeStatus === undefined
          ? this.state.hingeStatus ?? null
          : normaliseHingeStatus(result.hingeStatus),
        serverTimeMs: result.serverTimeMs,
        stateVersion: Math.max(this.state.stateVersion, result.stateVersion),
      };
      this.updateRootStateData();
      this.patchCurrentScreen();
    }
    this.markConnectionRestored();
    return {pollAfterMs: result.pollAfterMs};
  }

  private handlePollError(
    error: unknown,
    failures: number,
  ): PollErrorDecision {
    if (isAbortError(error)) {
      return {stop: true};
    }
    if (error instanceof LiveApiError && error.status === 409) {
      if (error.data?.state) {
        this.applyState(error.data.state);
      }
      this.showConnectionMessage(
        this.text(
          'live:error:conflict',
          'Die Session wurde an anderer Stelle weitergeschaltet.',
        ),
        'warning',
      );
      return {delayMs: 1100};
    }
    if (this.isAuthenticationError(error)) {
      this.renderFatalConnection(error);
      return {stop: true};
    }
    this.connectionTroubled = true;
    this.showConnectionMessage(
      failures > 1
        ? this.text(
          'live:connection:offline',
          'Die Verbindung ist gerade unterbrochen.',
        )
        : this.text(
          'live:connection:reconnecting',
          'Kurz gehakt. Wir versuchen es nochmal …',
        ),
      'warning',
    );
    return {};
  }

  private applyState(incoming: HostState, initial = false): void {
    if (this.state
        && Number(incoming.sessionId) === this.state.sessionId
        && Number(incoming.stateVersion) < this.state.stateVersion) {
      return;
    }
    const previousPhase = this.state?.phase;
    const previousState = this.state;
    const state = this.normaliseState(incoming);
    this.state = state;
    if (state.phase === 'ended' || state.phase === 'aborted') {
      this.clearStoredSession();
    } else {
      this.storeSession(state.sessionId);
    }
    this.updateClock(state.serverTimeMs);
    this.root.dataset.quizgeistTheme = this.config.theme || 'hell';
    this.root.dataset.quizgeistSeason = this.config.season || 'herbst';
    this.updateRootStateData();

    const nextScreenKey = this.screenKey(state);
    if (initial || nextScreenKey !== this.currentScreenKey) {
      this.currentScreenKey = nextScreenKey;
      this.renderState();
    } else {
      this.patchCurrentScreen();
    }

    if (ACTIVE_PHASES.has(state.phase)) {
      if (!this.poller.isRunning()) {
        this.poller.start(false);
      }
    } else {
      this.poller.stop();
    }

    if (previousPhase !== state.phase) {
      this.announce(this.phaseLabel(state.phase));
    }
    this.handleSoundTransition(previousState, state);
    this.markConnectionRestored();
  }

  private handleSoundTransition(
    previous: HostState | null,
    state: HostState,
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
    this.lastWarningSecond = null;
    if (state.phase === 'question' && state.phaseStartedAtMs <= this.serverNow()) {
      this.sound.play('go');
    } else if (state.phase === 'reveal') {
      this.sound.play(
        state.question?.policyDescriptor?.showsCorrectness === false
          ? 'tap'
          : 'correct',
      );
    } else if (state.phase === 'podium') {
      this.sound.play('podium');
    }
  }

  private normaliseState(state: HostState): HostState {
    return {
      ...state,
      answerCount: Math.max(0, Number(state.answerCount || 0)),
      aggregate: state.aggregate && typeof state.aggregate === 'object'
        ? state.aggregate
        : null,
      aggregateRevision: Math.max(0, Number(state.aggregateRevision || 0)),
      currentIndex: Math.max(-1, Number(state.currentIndex ?? -1)),
      distribution: Array.isArray(state.distribution) ? state.distribution : [],
      hingeStatus: normaliseHingeStatus(state.hingeStatus),
      interactionStage: state.interactionStage === null
          || state.interactionStage === undefined
        ? null
        : String(state.interactionStage),
      joinCode: String(state.joinCode || ''),
      phaseEndsAtMs: Number(state.phaseEndsAtMs || 0),
      phaseStartedAtMs: Number(state.phaseStartedAtMs || 0),
      playerCount: Math.max(0, Number(state.playerCount || 0)),
      players: Array.isArray(state.players) ? state.players : [],
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
      serverTimeMs: Number(state.serverTimeMs || Date.now()),
      sessionId: Number(state.sessionId || 0),
      stateVersion: Math.max(0, Number(state.stateVersion || 0)),
      teamPodium: Array.isArray(state.teamPodium) ? state.teamPodium : [],
      teamRanking: Array.isArray(state.teamRanking) ? state.teamRanking : [],
      totalQuestions: Math.max(0, Number(state.totalQuestions || 0)),
    };
  }

  private updateRootStateData(): void {
    if (!this.state) {
      delete this.root.dataset.sessionId;
      delete this.root.dataset.stateVersion;
      delete this.root.dataset.livePhase;
      delete this.root.dataset.questionId;
      delete this.root.dataset.questionRootId;
      delete this.root.dataset.questionVersion;
      return;
    }
    this.root.dataset.sessionId = String(this.state.sessionId);
    this.root.dataset.stateVersion = String(this.state.stateVersion);
    this.root.dataset.livePhase = this.state.phase;
    if (this.state.question) {
      this.root.dataset.questionId = String(this.state.question.id);
      this.root.dataset.questionRootId = String(this.state.question.rootId);
      this.root.dataset.questionVersion = String(this.state.question.version);
    } else {
      delete this.root.dataset.questionId;
      delete this.root.dataset.questionRootId;
      delete this.root.dataset.questionVersion;
    }
  }

  private updateClock(serverTimeMs: number): void {
    if (Number.isFinite(serverTimeMs) && serverTimeMs > 0) {
      this.clockOffsetMs = serverTimeMs - Date.now();
    }
  }

  private serverNow(): number {
    return Date.now() + this.clockOffsetMs;
  }

  private screenKey(state: HostState): string {
    const screen = this.derivedScreen(state);
    const question = state.question;
    const interactionStage = question
      ? String(
        state.interactionStage
        || question.policyDescriptor?.currentStage
        || question.interactionStage
        || question.typeData?.interactionStage
        || question.typeData?.stage
        || '',
      )
      : '';
    return `${screen}:${question?.id || 0}:${question?.questionToken || ''}:${interactionStage}`;
  }

  private derivedScreen(state: HostState): string {
    if (state.phase === 'question'
        && state.phaseStartedAtMs > this.serverNow()) {
      return 'countdown';
    }
    return state.phase;
  }

  private renderState(): void {
    const state = this.state;
    if (!state || !this.stage) {
      this.renderSetup();
      return;
    }
    this.setTabsVisible(false);
    this.stage.setAttribute('aria-busy', 'false');
    this.lastTimerAnnouncement = null;
    const screen = this.derivedScreen(state);
    this.renderToolbarNavigation(screen);
    if (screen !== 'question') {
      // F11a: leaving the question screen releases the camera immediately. A
      // live camera track that survives the question it belonged to is a
      // classroom pointed at a lens nobody is watching.
      this.destroyCardScan();
    }
    switch (screen) {
      case 'lobby':
        this.renderLobby(state);
        break;
      case 'countdown':
        this.renderCountdown(state);
        break;
      case 'question':
        this.renderQuestion(state);
        break;
      case 'reveal':
        this.renderReveal(state);
        break;
      case 'scoreboard':
        this.renderScoreboard(state);
        break;
      case 'podium':
        this.renderPodium(state);
        break;
      case 'aborted':
      case 'ended':
        this.renderEnded(state);
        break;
      default:
        this.renderBootstrapError(
          new Error(this.text('live:error:request', 'Unbekannter Sessionzustand.')),
        );
    }
  }

  private renderToolbarNavigation(screen: string): void {
    const navigation = this.toolbarNav;
    navigation?.replaceChildren();
    if (!navigation || screen !== 'ended') {
      return;
    }
    const capabilities = this.config.capabilities;
    if (capabilities?.viewReports === true && this.config.reportsUrl) {
      navigation.append(liveElement('a', 'quizgeist-host-button quizgeist-host-button--secondary', {
        href: this.config.reportsUrl,
        text: this.config.locale?.toLowerCase().startsWith('en')
          ? 'View report'
          : 'Bericht ansehen',
      }));
    }
    if (capabilities?.manage === true && this.config.editorUrl) {
      navigation.append(liveElement('a', 'quizgeist-host-button quizgeist-host-button--secondary', {
        href: this.config.editorUrl,
        text: this.text('host:action:toeditor', 'Zum Editor'),
      }));
    }
  }

  private renderSetup(): void {
    this.setTabsVisible(true);
    void this.stageMode?.leave();
    if (!this.stage) {
      return;
    }
    this.state = null;
    this.currentScreenKey = 'setup';
    this.clearStoredSession();
    this.updateRootStateData();
    this.root.dataset.livePhase = 'setup';
    this.stage.dataset.liveScreen = 'setup';
    this.stage.setAttribute('aria-busy', 'false');

    const shell = liveElement('div', 'quizgeist-host-setup');
    const brand = this.createBrand();
    const card = liveElement('div', 'quizgeist-host-setup__card');
    const eyebrow = liveElement('p', 'quizgeist-host-eyebrow', {
      text: 'Quizgeist Live',
    });
    const title = liveElement('h1', 'quizgeist-host-setup__title', {
      text: this.text('host:setup:title', 'Eine Live-Session starten'),
      tabindex: -1,
    });
    const description = liveElement('p', 'quizgeist-host-setup__description', {
      text: this.text(
        'host:setup:description',
        'Erstellen Sie eine Lobby und laden Sie Ihre Lerngruppe ein.',
      ),
    });
    const form = liveElement('form', 'quizgeist-host-setup__form', {
      'data-live-host-create': true,
    });
    const selectId = `quizgeist-host-name-mode-${this.config.cmid}`;
    const label = liveElement('label', 'quizgeist-host-field__label', {
      for: selectId,
      text: this.text('host:namemode:label', 'Teilnehmernamen'),
    });
    const select = liveElement('select', 'quizgeist-host-select', {
      id: selectId,
      name: 'nameMode',
    });
    select.append(
      liveElement('option', '', {
        text: this.text('host:namemode:real', 'Echte Namen'),
        value: 'real',
      }),
      liveElement('option', '', {
        text: this.text('host:namemode:custom', 'Eigene Spitznamen'),
        value: 'custom',
      }),
      liveElement('option', '', {
        text: this.text('host:namemode:generated', 'Lustige Namen erzeugen'),
        value: 'generated',
      }),
    );
    const modeId = `quizgeist-host-mode-${this.config.cmid}`;
    const modeLabel = liveElement('label', 'quizgeist-host-field__label', {
      for: modeId,
      text: this.text('defaultmode', 'Spielmodus'),
    });
    const mode = liveElement('select', 'quizgeist-host-select', {
      'data-live-mode': true,
      id: modeId,
      name: 'mode',
    });
    const allowedModes = this.setup.allowedModes?.length
      ? this.setup.allowedModes
      : ['classic', 'accuracy', 'team', 'security'] as const;
    allowedModes.forEach((key) => {
      mode.append(liveElement('option', '', {
        text: this.modeLabel(key),
        value: key,
      }));
    });
    mode.value = this.setup.defaultMode || 'classic';

    const teamFields = liveElement('fieldset', 'quizgeist-host-team-setup', {
      hidden: mode.value !== 'team',
    });
    teamFields.append(liveElement('legend', 'quizgeist-host-field__label', {
      text: this.text('host:team:title', 'Teams'),
    }));
    const source = liveElement('select', 'quizgeist-host-select', {
      'data-live-team-source': true,
      name: 'teamSource',
    });
    const groupReadiness = this.setup.groupReadiness;
    if ((this.setup.moodleGroups?.length || 0) > 0) {
      source.append(liveElement('option', '', {
        disabled: groupReadiness?.tooManyGroups === true,
        text: this.text('host:team:source:groups', 'Moodle-Gruppen'),
        value: 'groups',
      }));
    }
    source.append(liveElement('option', '', {
      text: this.text('host:team:source:free', 'Freie Teams'),
      value: 'free',
    }));
    if (groupReadiness?.tooManyGroups === true) {
      source.value = 'free';
    }
    const tooManyGroups = liveElement('p', 'quizgeist-host-setup__description', {
      hidden: groupReadiness?.tooManyGroups !== true,
      role: 'alert',
      text: this.text(
        'host:team:toomanygroups',
        `${groupReadiness?.groupCount || 0} Moodle-Gruppen überschreiten das Limit `
          + `von ${groupReadiness?.maxGroupCount || 12}; verwenden Sie freie Teams.`,
        {
          groupCount: groupReadiness?.groupCount || 0,
          maxGroupCount: groupReadiness?.maxGroupCount || 12,
        },
      ),
    });
    const unassignedCount = groupReadiness?.unassignedEnrolledCount || 0;
    const unassignedNotice = liveElement('p', 'quizgeist-host-setup__description', {
      hidden: source.value !== 'groups' || unassignedCount <= 0,
      text: this.text(
        'host:team:unassignednotice',
        `${unassignedCount} Personen ohne Moodle-Gruppe werden dem Auffangteam `
          + '„Ohne Moodle-Gruppe“ zugeteilt.',
        {a: unassignedCount},
      ),
    });
    const freeTeams = liveElement('div', 'quizgeist-host-free-teams', {
      hidden: source.value !== 'free',
    });
    const teamNames = liveElement('div', 'quizgeist-host-team-names');
    const addTeam = liveButton(
      this.text('host:team:add', 'Team hinzufügen'),
      'quizgeist-host-button quizgeist-host-button--secondary',
      {'data-live-team-add': true},
    );
    const updateTeamControls = (): void => {
      const rows = Array.from(
        teamNames.querySelectorAll<HTMLElement>('[data-live-team-row]'),
      );
      rows.forEach((row, index) => {
        const input = row.querySelector<HTMLInputElement>('[data-live-team-name]');
        const remove = row.querySelector<HTMLButtonElement>('[data-live-team-remove]');
        if (input) {
          input.setAttribute(
            'aria-label',
            this.text('host:team:label', 'Team {$a}', {a: index + 1}),
          );
          input.placeholder = this.text(
            'host:team:label',
            'Team {$a}',
            {a: index + 1},
          );
        }
        if (remove) {
          remove.disabled = rows.length <= 2;
        }
      });
      addTeam.disabled = rows.length >= 12;
    };
    const appendTeam = (teamName = ''): void => {
      if (teamNames.childElementCount >= 12) {
        return;
      }
      const row = liveElement('div', 'quizgeist-host-team-row', {
        'data-live-team-row': true,
      });
      const teamInput = liveElement('input', 'quizgeist-host-text-input', {
        'data-live-team-name': true,
        maxlength: 40,
        name: 'teamName',
        type: 'text',
        value: teamName,
      });
      const remove = liveButton(
        '×',
        'quizgeist-host-button quizgeist-host-button--secondary quizgeist-host-team-remove',
        {
          'aria-label': this.text('host:team:remove', 'Team entfernen'),
          'data-live-team-remove': true,
        },
      );
      remove.addEventListener('click', () => {
        row.remove();
        updateTeamControls();
      });
      row.append(teamInput, remove);
      teamNames.append(row);
      updateTeamControls();
    };
    ['Moos', 'Funken', 'Wellen', 'Sterne'].forEach((teamName) => {
      appendTeam(teamName);
    });
    addTeam.addEventListener('click', () => {
      appendTeam();
      teamNames.querySelector<HTMLInputElement>(
        '[data-live-team-row]:last-child [data-live-team-name]',
      )?.focus();
    });
    freeTeams.append(teamNames, addTeam);
    const updateTeamSource = (): void => {
      freeTeams.hidden = source.value !== 'free';
      unassignedNotice.hidden = source.value !== 'groups' || unassignedCount <= 0;
    };
    source.addEventListener('change', updateTeamSource);
    updateTeamSource();
    teamFields.append(source, tooManyGroups, unassignedNotice, freeTeams);

    const securityFields = liveElement('div', 'quizgeist-host-security-setup', {
      hidden: mode.value !== 'security',
    });
    const blockedLabel = liveElement('label', 'quizgeist-host-field__label', {
      text: 'Gesperrte Namen (eine Zeile je Begriff)',
    });
    const blockedNames = liveElement('textarea', 'quizgeist-host-text-input', {
      'data-live-blocked-names': true,
      name: 'blockedNames',
      rows: 3,
    });
    blockedLabel.append(blockedNames);
    securityFields.append(
      liveElement('p', 'quizgeist-host-setup__description', {
        text: 'Nur eingeschriebene Nutzer können beitreten; der Namensfilter ist aktiv.',
      }),
      blockedLabel,
    );
    mode.addEventListener('change', () => {
      teamFields.hidden = mode.value !== 'team';
      securityFields.hidden = mode.value !== 'security';
    });
    const submit = liveElement('button', 'quizgeist-host-button quizgeist-host-button--primary', {
      type: 'submit',
      text: this.text('host:action:create', 'Lobby erstellen'),
      'data-action': 'create-session',
    });
    form.append(
      label,
      select,
      modeLabel,
      mode,
      teamFields,
      securityFields,
      this.createStressFreeFields(),
      this.renderReadiness(),
      submit,
    );
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.createSession(form, submit);
    });
    card.append(eyebrow, title, description, form);
    shell.append(brand, card);
    this.stage.replaceChildren(shell);
    title.focus();
  }

  /**
   * F1 Stressarm-Standard: the free block of the base package.
   *
   * Deliberately no feature gate — these four settings are the strongest
   * argument of the base package and must not hang on any addon. The works
   * defaults come from the activity; the teacher may override them per
   * session.
   */
  private createStressFreeFields(): HTMLElement {
    const block = liveElement('fieldset', 'quizgeist-host-setup__stressfree', {
      'data-live-stressfree': true,
    });
    block.append(liveElement('legend', 'quizgeist-host-field__legend', {
      text: this.text('host:setup:stressfree', 'Stressarm'),
    }));
    const defaults = this.setup.stressFree || {};
    const paceId = `quizgeist-host-pace-${this.config.cmid}`;
    block.append(liveElement('label', 'quizgeist-host-field__label', {
      for: paceId,
      text: this.text('host:setup:pace', 'Punktvergabe'),
    }));
    const pace = liveElement('select', 'quizgeist-host-select', {
      'data-live-pace': true,
      id: paceId,
      name: 'pace',
    });
    (['even', 'timed'] as const).forEach((value) => {
      const option = liveElement('option', '', {
        text: this.text(`pacemode:${value}`, value),
        value,
      }) as HTMLOptionElement;
      option.selected = (defaults.pace || 'even') === value;
      pace.append(option);
    });
    block.append(pace);

    const boardId = `quizgeist-host-leaderboard-${this.config.cmid}`;
    block.append(liveElement('label', 'quizgeist-host-field__label', {
      for: boardId,
      text: this.text('host:setup:leaderboard', 'Rangliste'),
    }));
    const board = liveElement('select', 'quizgeist-host-select', {
      'data-live-leaderboard': true,
      id: boardId,
      name: 'leaderboard',
    });
    (['own', 'team', 'full'] as const).forEach((value) => {
      const option = liveElement('option', '', {
        text: this.text(`leaderboard:${value}`, value),
        value,
      }) as HTMLOptionElement;
      option.selected = (defaults.leaderboard || 'own') === value;
      board.append(option);
    });
    block.append(board);

    ([
      ['timer', 'host:setup:timer', 'Countdown anzeigen'],
      ['sound', 'host:setup:sound', 'Töne erlauben'],
    ] as const).forEach(([name, key, fallback]) => {
      const id = `quizgeist-host-${name}-${this.config.cmid}`;
      const wrapper = liveElement('div', 'quizgeist-host-field__switch');
      const input = liveElement('input', 'quizgeist-host-checkbox', {
        id,
        name,
        type: 'checkbox',
        value: '1',
      }) as HTMLInputElement;
      input.checked = name === 'timer'
        ? defaults.timerVisible !== false
        : defaults.soundEnabled !== false;
      wrapper.append(
        input,
        liveElement('label', 'quizgeist-host-field__label', {
          for: id,
          text: this.text(key, fallback),
        }),
      );
      block.append(wrapper);
    });
    return block;
  }

  private renderReadiness(): HTMLElement {
    const readiness = liveElement('div', 'quizgeist-host-setup__readiness', {
      role: 'status',
    });
    const text = liveElement('p', 'quizgeist-host-setup__readiness-text');
    const data = this.readiness;
    const questionCount = Math.max(0, Number(data?.questionCount || 0));
    const playableQuestionCount = Math.max(
      0,
      Number(data?.playableQuestionCount || 0),
    );

    if (data?.ready === true) {
      text.textContent = this.text(
        'host:readiness:ready',
        '{$a} Fragen sind spielbereit.',
        {a: questionCount, count: questionCount},
      );
    } else if (playableQuestionCount <= 0) {
      text.textContent = this.text(
        'host:readiness:none',
        'Keine spielbereiten Fragen vorhanden.',
      );
      this.appendEditorLink(readiness);
    } else if (playableQuestionCount < questionCount) {
      text.textContent = this.text(
        'host:readiness:partial',
        '{$a->playable} von {$a->total} Fragen sind spielbereit.',
        {
          a: playableQuestionCount,
          playable: playableQuestionCount,
          total: questionCount,
        },
      );
      appendChildren(
        text,
        ' ',
        this.text(
          'host:readiness:partialhint',
          this.config.locale?.toLowerCase().startsWith('en')
            ? 'A live round starts only when all questions are ready.'
            : 'Eine Live-Runde startet erst, wenn alle Fragen fertig sind.',
        ),
      );
      this.appendEditorLink(readiness);
    }

    readiness.prepend(text);
    return readiness;
  }

  private appendEditorLink(readiness: HTMLElement): void {
    if (!this.config.editorUrl) {
      return;
    }
    const actions = liveElement('div', 'quizgeist-host-setup__readiness-actions');
    actions.append(liveElement('a', 'quizgeist-host-button quizgeist-host-button--secondary', {
      href: this.config.editorUrl,
      text: this.text('host:action:toeditor', 'Zum Editor'),
    }));
    readiness.append(actions);
  }

  private async createSession(
    form: HTMLFormElement,
    submit: HTMLButtonElement,
  ): Promise<void> {
    const formData = new FormData(form);
    const rawNameMode = String(formData.get('nameMode') || '');
    const nameMode = rawNameMode === 'custom' || rawNameMode === 'generated'
      ? rawNameMode
      : 'real';
    const rawMode = String(formData.get('mode') || 'classic');
    const mode = ['accuracy', 'classic', 'security', 'team'].includes(rawMode)
      ? rawMode
      : 'classic';
    const teamNames = Array.from(
      form.querySelectorAll<HTMLInputElement>('[data-live-team-name]'),
    ).map((input) => input.value.trim()).filter(Boolean);
    const blockedNames = String(formData.get('blockedNames') || '')
      .split(/\r?\n|,/)
      .map((name) => name.trim())
      .filter(Boolean);
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    const originalLabel = submit.textContent || '';
    submit.textContent = this.text(
      'live:connection:loading',
      'Live-Session wird geladen …',
    );
    try {
      const result = await this.api.post<HostStateResult>(
        'live_session_create',
        {
          blockedNames,
          // F1: the stress-free axis travels with the session; the server
          // validates and stores it, the client only proposes.
          leaderboard: String(formData.get('leaderboard') || 'own'),
          mode,
          nameMode,
          pace: String(formData.get('pace') || 'even'),
          sound: formData.get('sound') === '1',
          teamNames,
          teamSource: String(formData.get('teamSource') || 'free'),
          timer: formData.get('timer') === '1',
        },
      );
      if (!result.state) {
        throw new LiveApiError(
          this.text('live:error:request', 'Die Lobby konnte nicht erstellt werden.'),
          'invalid_response',
        );
      }
      this.applyState(result.state, true);
    } catch (error) {
      if (this.isAuthenticationError(error)) {
        this.renderFatalConnection(error);
        return;
      }
      this.setTabsVisible(true);
      void this.stageMode?.leave();
      const message = error instanceof LiveApiError
        && (error.code === 'no_playable_questions'
          || error.code === 'noplayablequestions')
        ? this.text(
          'live:error:noplayablequestions',
          'Für eine Live-Session werden fertige Fragen benötigt.',
        )
        : this.errorMessage(error);
      this.showConnectionMessage(message, 'error');
      this.connectionBanner?.scrollIntoView({block: 'nearest'});
      this.announce(message);
      submit.disabled = false;
      submit.removeAttribute('aria-busy');
      submit.textContent = originalLabel;
    }
  }

  private renderLobby(state: HostState): void {
    if (!this.stage) {
      return;
    }
    this.stage.dataset.liveScreen = 'lobby';
    const shell = liveElement('div', 'quizgeist-host-screen quizgeist-host-lobby');
    const topbar = this.createTopbar(state);
    const main = liveElement('div', 'quizgeist-host-lobby__join');
    const pinCard = liveElement('section', 'quizgeist-host-pin-card');
    const pinLabel = liveElement('h1', 'quizgeist-host-pin-card__label', {
      tabindex: -1,
      text: this.text('host:lobby:pin', 'Spielcode'),
    });
    const pin = liveElement('p', 'quizgeist-host-pin-card__code', {
      text: formatJoinCode(state.joinCode),
      'data-join-code': state.joinCode,
      'data-live-join-code': true,
    });
    const hint = liveElement('p', 'quizgeist-host-pin-card__hint', {
      text: this.text(
        'host:lobby:joinhint',
        'Code am Handy eingeben und los geht’s.',
      ),
    });
    pinCard.append(pinLabel, pin, hint);

    const qrCard = liveElement('section', 'quizgeist-host-qr');
    const qrHost = liveElement('div', 'quizgeist-host-qr__image');
    const qrLabel = liveElement('p', 'quizgeist-host-qr__caption', {
      text: this.text('host:lobby:qr', 'QR-Code zum Beitreten'),
    });
    const joinUrl = this.joinUrl(state.joinCode);
    const joinLink = liveElement('a', 'quizgeist-host-qr__link', {
      href: joinUrl,
      text: this.text('host:lobby:joinhint', 'Mit Spielcode beitreten'),
    });
    qrCard.append(qrHost, qrLabel, joinLink);
    void renderLocalQrCode(
      qrHost,
      joinUrl,
      `${this.text('host:lobby:qr', 'QR-Code zum Beitreten')}: ${formatJoinCode(state.joinCode)}`,
    ).catch(() => {
      if (qrHost.isConnected) {
        qrHost.replaceChildren(
          liveElement('p', 'quizgeist-host-qr__fallback', {
            text: formatJoinCode(state.joinCode),
          }),
        );
      }
    });
    main.append(pinCard, qrCard);

    const players = liveElement('section', 'quizgeist-host-lobby__players');
    const playersHeader = liveElement('div', 'quizgeist-host-section-heading');
    const playersTitle = liveElement('h2', 'quizgeist-host-section-heading__title', {
      text: this.text('host:lobby:players', 'Mitspieler'),
    });
    const playerCount = liveElement('p', 'quizgeist-host-section-heading__count', {
      text: this.playerCountLabel(state.playerCount),
      'data-live-player-count': true,
    });
    playersHeader.append(playersTitle, playerCount);
    const playerGrid = liveElement('div', 'quizgeist-host-player-grid', {
      'aria-live': 'polite',
      'data-live-player-grid': true,
      tabindex: 0,
    });
    this.reconcilePlayers(playerGrid, state.players);
    players.append(playersHeader, playerGrid);

    const footer = liveElement('footer', 'quizgeist-host-controlbar');
    const modes = liveElement('div', 'quizgeist-host-mode-chips');
    appendChildren(
      modes,
      liveElement('span', 'quizgeist-host-mode-chip is-active', {
        text: this.modeLabel(state.mode),
      }),
      liveElement('span', 'quizgeist-host-mode-chip', {
        text: this.nameModeLabel(state.nameMode),
      }),
    );
    const actions = liveElement('div', 'quizgeist-host-controlbar__actions');
    actions.append(
      this.commandButton(
        'abort',
        this.text('host:action:abort', 'Session abbrechen'),
        'quiet-danger',
      ),
      this.commandButton(
        'start',
        this.text('host:action:start', 'Spiel starten'),
        'primary',
        state.playerCount <= 0,
      ),
    );
    footer.append(modes, actions);
    shell.append(topbar, main, players, footer);
    this.stage.replaceChildren(shell);
    pinLabel.focus();
  }

  private renderCountdown(state: HostState): void {
    if (!this.stage) {
      return;
    }
    this.stage.dataset.liveScreen = 'countdown';
    const shell = liveElement('div', 'quizgeist-host-screen quizgeist-host-countdown');
    const topbar = this.createTopbar(state);
    const body = liveElement('div', 'quizgeist-host-countdown__body');
    if (state.question) {
      body.append(
        liveElement('p', 'quizgeist-host-countdown__type', {
          text: this.questionTypeLabel(state.question),
        }),
      );
    }
    const number = liveElement('p', 'quizgeist-host-countdown__number mq-anim-countdown-tick', {
      'aria-hidden': 'true',
      'data-live-countdown': true,
      text: String(this.countdownSeconds(state)),
    });
    const accessible = liveElement('p', 'quizgeist-live-visually-hidden', {
      'aria-live': 'polite',
      'data-live-countdown-status': true,
    });
    body.append(number, accessible);
    shell.append(topbar, body);
    this.stage.replaceChildren(shell);
  }

  private renderQuestion(state: HostState): void {
    if (!this.stage) {
      return;
    }
    const question = state.question;
    if (!question) {
      this.renderBootstrapError(
        new Error(this.text('live:error:request', 'Die Frage konnte nicht geladen werden.')),
      );
      return;
    }
    this.stage.dataset.liveScreen = 'question';
    const media = ['pin', 'reveal', 'slide'].includes(question.qtype)
      ? null
      : this.questionMedia(question);
    const shell = liveElement(
      'div',
      `quizgeist-host-screen quizgeist-host-question${media ? ' has-media' : ''}`,
      {
        'data-live-question-id': question.id,
      },
    );
    const header = liveElement('header', 'quizgeist-host-question__header');
    const meta = liveElement('div', 'quizgeist-host-question__meta');
    meta.append(
      liveElement('span', 'quizgeist-host-chip', {
        text: this.questionProgress(question, state),
      }),
      liveElement('span', 'quizgeist-host-chip', {
        text: this.questionTypeLabel(question),
      }),
    );
    const answered = liveElement('p', 'quizgeist-host-question__answered', {
      'aria-atomic': 'true',
      'aria-live': 'polite',
      'data-live-answer-count': true,
      text: this.answeredLabel(state),
    });
    const tts = createTtsControl(
      this.tts,
      questionSpeechText(question),
      this.config,
    );
    header.append(meta, answered, tts, this.createTimer(state));

    const questionText = liveElement(
      'h1',
      `quizgeist-host-question__text${question.questionText.length > 70 ? ' is-long' : ''}${question.questionText.length > 220 ? ' is-verylong' : ''}`,
      {
        tabindex: -1,
        text: question.questionText,
      },
    );
    const response = renderLiveResponse(question, {
      aggregate: state.aggregate,
      answer: null,
      audience: 'host',
      interactive: false,
      nowMs: this.serverNow(),
      phaseStartedAtMs: state.phaseStartedAtMs,
      text: (key, fallback, values = {}) => this.text(key, fallback, values),
    });
    const controls = liveElement('footer', 'quizgeist-host-controlbar');
    const secondary = liveElement('div', 'quizgeist-host-controlbar__actions');
    secondary.append(
      this.commandButton(
        'previous',
        this.text('host:action:previous', 'Vorherige Frage'),
        'secondary',
        state.currentIndex <= 0,
      ),
      this.commandButton(
        'skip',
        this.text('host:action:skip', 'Überspringen'),
        'secondary',
      ),
    );
    const primary = liveElement('div', 'quizgeist-host-controlbar__actions');
    const policy = question.policyDescriptor;
    const interactionStage = String(
      policy?.currentStage
      || state.interactionStage
      || question.interactionStage
      || '',
    );
    primary.append(
      this.commandButton(
        'abort',
        this.text('host:action:abort', 'Session abbrechen'),
        'quiet-danger',
      ),
    );
    if (policy?.canAdvance) {
      const nextStage = policy.stages.find((stage) => (
        stage.key === policy.nextStageKey
      ));
      const labelKey = policy.nextStageLabelKey || nextStage?.labelKey || '';
      const advance = liveButton(
        labelKey === ''
          ? 'Nächste Stufe'
          : this.text(labelKey, 'Nächste Stufe'),
        'quizgeist-host-button quizgeist-host-button--primary',
        {
          'aria-busy': this.commandBusy ? 'true' : 'false',
          'data-live-host-interaction': true,
          'data-live-interaction-advance': interactionStage,
          disabled: this.commandBusy,
        },
      );
      advance.addEventListener('click', () => {
        void this.advanceInteraction(question, interactionStage);
      });
      primary.append(advance);
    } else {
      primary.append(this.commandButton(
        'reveal',
        this.text('host:action:reveal', 'Antworten auflösen'),
        'primary',
      ));
    }
    controls.append(secondary, primary);
    const content = liveElement('div', 'quizgeist-host-question__content');
    if (media) {
      content.append(media);
    }
    content.append(response);
    const aggregate = liveElement('div', 'quizgeist-live-aggregate-slot', {
      'data-live-aggregate-slot': true,
    });
    if (state.aggregate) {
      aggregate.append(renderLiveAggregate(
        question,
        state.aggregate,
        {
          aggregate: state.aggregate,
          answer: null,
          audience: 'host',
          disabled: this.commandBusy,
          interactive: false,
          nowMs: this.serverNow(),
          onAggregateAction: (data) => {
            this.submitAggregateInteraction(question, data);
          },
          text: (key, fallback, values = {}) => this.text(key, fallback, values),
        },
      ));
    }
    content.append(aggregate);
    if (question.qtype === 'brainstorm' && interactionStage === 'group') {
      content.append(this.renderBrainstormGrouping(state));
    }
    // F11a: the card panel is part of the QUESTION screen and of nothing else.
    // It appears only for the two question types a printed card can answer,
    // and only when the AI addon shipped a card configuration.
    const cards = this.cardScanPanel(state, question);
    if (cards !== null) {
      content.append(cards);
    }
    shell.append(header, questionText, content, controls);
    this.stage.replaceChildren(shell);
    questionText.focus();
    this.patchTimer();
  }

  /**
   * F11a: build or retarget the card panel for the question on screen.
   *
   * Returns null whenever the mode does not apply — no addon, no card set, a
   * question type a card cannot answer, or no visit token to bind the scan to.
   * A locked panel is deliberately NOT offered: 2.6 says a missing addon means
   * the capability does not appear, not that it appears and refuses.
   *
   * [P11-E2]: this is the ONE place that decides whether the panel and its
   * three AI actions (`card_scan_recognise`, `card_scan_state`,
   * `card_scan_confirm`) come into being at all. The addon report is therefore
   * consulted by name here, alongside the configuration keys derived from it;
   * the panel itself never has to weigh an addon question of its own.
   */
  private cardScanPanel(
    state: HostState,
    question: LiveQuestion,
  ): HTMLElement | null {
    const sets = this.config.cardSets;
    const uploadUrl = this.config.cardScanUploadUrl;
    const printUrl = this.config.cardsPrintUrl;
    if (this.config.features?.ai?.installed !== true
        || !Array.isArray(sets)
        || typeof uploadUrl !== 'string'
        || uploadUrl === ''
        || typeof printUrl !== 'string'
        || printUrl === ''
        || !['quiz', 'truefalse'].includes(question.qtype)
        || question.questionToken === '') {
      this.destroyCardScan();
      return null;
    }
    const letters = question.qtype === 'truefalse'
      ? ['A', 'B']
      : question.choices.map((_choice, index) => 'ABCDEF'[index] || '')
        .filter((letter) => letter !== '');
    const target = {
      letters,
      questionId: question.id,
      sessionId: state.sessionId,
      visit: question.questionToken,
    };
    if (this.cardScan === null) {
      this.cardScan = new CardScanPanel(
        {
          api: this.api,
          cardSets: sets,
          cmid: this.config.cmid,
          printUrl,
          sesskey: this.config.sesskey,
          text: (key, fallback, values = {}) => this.text(key, fallback, values),
          uploadUrl,
        },
        target,
      );
    } else {
      this.cardScan.retarget(target);
    }
    return this.cardScan.element();
  }

  /** Release the camera and any open poll of the card panel. */
  private destroyCardScan(): void {
    if (this.cardScan !== null) {
      this.cardScan.destroy();
      this.cardScan = null;
    }
  }

  private renderReveal(state: HostState): void {
    if (!this.stage) {
      return;
    }
    const question = state.question;
    if (!question) {
      this.renderBootstrapError(
        new Error(this.text('live:error:request', 'Die Frage konnte nicht geladen werden.')),
      );
      return;
    }
    this.stage.dataset.liveScreen = 'reveal';
    const shell = liveElement('div', 'quizgeist-host-screen quizgeist-host-reveal', {
      'data-live-question-id': question.id,
    });
    const topbar = this.createTopbar(state);
    const heading = liveElement('div', 'quizgeist-host-reveal__heading');
    heading.append(
      liveElement('p', 'quizgeist-host-eyebrow', {
        text: question.policyDescriptor?.showsCorrectness === false
          ? this.text(
            'host:reveal:nocorrectness',
            'Auswertung ohne Richtig/Falsch',
          )
          : this.questionProgress(question, state),
      }),
      liveElement('h1', 'quizgeist-host-reveal__title', {
        text: this.text('host:reveal:title', 'So wurde geantwortet'),
        tabindex: -1,
      }),
      liveElement('p', 'quizgeist-host-reveal__question', {
        text: question.questionText,
      }),
      createTtsControl(this.tts, questionSpeechText(question), this.config),
    );
    const distribution = liveElement('div', 'quizgeist-live-aggregate-slot', {
      'data-live-aggregate-slot': true,
    });
    distribution.append(renderLiveAggregate(
      question,
      state.aggregate || state.distribution,
      {
        aggregate: state.aggregate,
        answer: null,
        audience: 'host',
        interactive: false,
        nowMs: this.serverNow(),
        text: (key, fallback, values = {}) => this.text(key, fallback, values),
      },
    ));
    // F5 Fehlkonzept-Radar. Only on the reveal screen, and only from what the
    // server sent: the labels and the traffic light exist in the host payload
    // alone. Rendering them earlier would put the solution on the beamer.
    this.decorateMisconceptions(distribution, state);
    const controls = liveElement('footer', 'quizgeist-host-controlbar');
    const left = liveElement('div', 'quizgeist-host-controlbar__actions');
    const right = liveElement('div', 'quizgeist-host-controlbar__actions');
    left.append(
      this.commandButton(
        'previous',
        this.text('host:action:previous', 'Vorherige Frage'),
        'secondary',
        state.currentIndex <= 0,
      ),
    );
    right.append(
      this.commandButton(
        'abort',
        this.text('host:action:abort', 'Session abbrechen'),
        'quiet-danger',
      ),
      this.commandButton(
        'scoreboard',
        this.text('host:action:scoreboard', 'Zwischenstand zeigen'),
        'primary',
      ),
    );
    controls.append(left, right);
    shell.append(
      topbar,
      heading,
      distribution,
      controls,
    );
    this.stage.replaceChildren(shell);
    heading.querySelector<HTMLElement>('h1')?.focus();
  }

  /**
   * Attach the F5 teacher layer to an already rendered distribution.
   *
   * Every value comes from the server payload; nothing is recomputed here.
   * A missing key means "the server did not send it" and therefore "there is
   * nothing to show" — never "unknown, let us guess".
   */
  private decorateMisconceptions(
    container: HTMLElement,
    state: HostState,
  ): void {
    const rows: MisconceptionRow[] = state.distribution.map((entry) => ({
      choiceId: String(entry.choiceId || ''),
      count: Number(entry.count || 0),
      label: typeof entry.misconceptionLabel === 'string'
        ? entry.misconceptionLabel
        : null,
      percent: Number(entry.percent || 0),
    }));
    decorateDistribution(
      container,
      rows,
      normaliseHingeStatus(state.hingeStatus),
      (key, fallback, values = {}) => this.text(key, fallback, values),
    );
  }

  private renderScoreboard(state: HostState): void {
    if (!this.stage) {
      return;
    }
    this.stage.dataset.liveScreen = 'scoreboard';
    const shell = liveElement('div', 'quizgeist-host-screen quizgeist-host-scoreboard');
    const topbar = this.createTopbar(state);
    const heading = liveElement('div', 'quizgeist-host-scoreboard__heading');
    heading.append(
      liveElement('p', 'quizgeist-host-eyebrow', {
        text: this.phaseLabel('scoreboard'),
      }),
      liveElement('h1', 'quizgeist-host-scoreboard__title', {
        text: this.text('host:scoreboard:title', 'Zwischenstand'),
        tabindex: -1,
      }),
    );
    const ranking = liveElement('ol', 'quizgeist-host-ranking', {
      'aria-label': this.text('host:scoreboard:ranking', 'Rangliste'),
      'data-live-ranking': true,
      tabindex: 0,
    });
    this.renderRanking(ranking, state);
    const controls = liveElement('footer', 'quizgeist-host-controlbar');
    const left = liveElement('div', 'quizgeist-host-controlbar__actions');
    const right = liveElement('div', 'quizgeist-host-controlbar__actions');
    left.append(
      this.commandButton(
        'previous',
        this.text('host:action:previous', 'Vorherige Frage'),
        'secondary',
        state.currentIndex <= 0,
      ),
    );
    right.append(
      this.commandButton(
        'abort',
        this.text('host:action:abort', 'Session abbrechen'),
        'quiet-danger',
      ),
      this.commandButton(
        'next',
        state.currentIndex + 1 >= state.totalQuestions
          ? this.text('host:podium:title', 'Zum Podium')
          : this.text('host:action:next', 'Nächste Frage'),
        'primary',
      ),
    );
    controls.append(left, right);
    shell.append(topbar, heading, ranking, controls);
    this.stage.replaceChildren(shell);
    heading.querySelector<HTMLElement>('h1')?.focus();
  }

  private renderPodium(state: HostState): void {
    if (!this.stage) {
      return;
    }
    this.stage.dataset.liveScreen = 'podium';
    const shell = liveElement('div', 'quizgeist-host-screen quizgeist-host-podium', {
      'data-live-podium': true,
    });
    const confetti = this.createConfetti();
    const heading = liveElement('div', 'quizgeist-host-podium__heading');
    heading.append(
      liveElement('h1', 'quizgeist-host-podium__title', {
        text: this.text('host:podium:title', 'Das Podium'),
        tabindex: -1,
      }),
      liveElement('p', 'quizgeist-host-podium__subtitle', {
        text: `${this.playerCountLabel(state.playerCount)} · Quizgeist`,
      }),
    );
    const teamMode = state.mode === 'team' && (state.teamPodium?.length || 0) > 0;
    const podium = liveElement(
      'div',
      `quizgeist-host-podium__places${teamMode ? ' quizgeist-host-podium__places--team' : ''}`,
      teamMode ? {'data-live-team-podium': true} : {},
    );
    const standings = teamMode ? state.teamPodium || [] : state.podium;
    const byRank = new Map(standings.map((standing) => [standing.rank, standing]));
    [2, 1, 3].forEach((rank) => {
      const standing = byRank.get(rank);
      if (standing) {
        podium.append(
          teamMode
            ? this.teamPodiumPlace(standing as LiveTeamStanding)
            : this.podiumPlace(standing as LiveStanding),
        );
      } else {
        podium.append(
          liveElement(
            'div',
            `quizgeist-host-podium-place quizgeist-host-podium-place--${rank} is-empty`,
            {
              'aria-hidden': 'true',
            },
          ),
        );
      }
    });
    const controls = liveElement('footer', 'quizgeist-host-podium__controls');
    const newRound = liveButton(
      this.text('host:action:newround', 'Neue Runde'),
      'quizgeist-host-button quizgeist-host-button--secondary',
      {
        'data-action': 'new-round',
        'data-live-command': true,
      },
    );
    newRound.addEventListener('click', () => {
      void this.endAndPrepareNewRound();
    });
    controls.append(
      newRound,
      this.commandButton(
        'end',
        this.text('host:action:end', 'Session beenden'),
        'primary',
      ),
    );
    shell.append(confetti, heading, podium, controls);
    this.stage.replaceChildren(shell);
    heading.querySelector<HTMLElement>('h1')?.focus();
  }

  private renderEnded(state: HostState): void {
    if (!this.stage) {
      return;
    }
    this.stage.dataset.liveScreen = 'ended';
    const shell = liveElement('div', 'quizgeist-host-screen quizgeist-host-ended');
    const brand = this.createBrand();
    const card = liveElement('div', 'quizgeist-host-state-card');
    const title = liveElement('h1', 'quizgeist-host-state-card__title', {
      tabindex: -1,
      text: state.phase === 'aborted'
        ? this.text('host:action:abort', 'Session abgebrochen')
        : this.text('host:podium:ended', 'Die Runde ist beendet.'),
    });
    const summary = liveElement('p', 'quizgeist-host-state-card__text', {
      text: this.playerCountLabel(state.playerCount),
    });
    const newRound = liveButton(
      this.text('host:action:newround', 'Neue Runde'),
      'quizgeist-host-button quizgeist-host-button--primary',
      {'data-action': 'create-session'},
    );
    newRound.addEventListener('click', () => {
      this.clearStoredSession();
      this.state = null;
      this.poller.stop();
      this.renderSetup();
    });
    card.append(title, summary, newRound);
    shell.append(brand, card);
    this.stage.replaceChildren(shell);
    title.focus();
  }

  private renderBootstrapError(error: unknown): void {
    this.setTabsVisible(true);
    void this.stageMode?.leave();
    if (!this.stage) {
      return;
    }
    this.poller.stop();
    this.stage.dataset.liveScreen = 'error';
    this.stage.setAttribute('aria-busy', 'false');
    const card = liveElement('div', 'quizgeist-host-state-card quizgeist-host-state-card--error', {
      role: 'alert',
      tabindex: 0,
    });
    const title = liveElement('h1', 'quizgeist-host-state-card__title', {
      text: this.text('live:error:config', 'Die Live-Ansicht konnte nicht gestartet werden.'),
    });
    const message = liveElement('p', 'quizgeist-host-state-card__text', {
      text: this.errorMessage(error),
    });
    const retry = liveButton(
      this.text('host:action:retry', 'Erneut versuchen'),
      'quizgeist-host-button quizgeist-host-button--primary',
      {'data-action': 'retry-bootstrap'},
    );
    retry.addEventListener('click', () => {
      void this.bootstrap();
    });
    card.append(title, message, retry);
    this.stage.replaceChildren(card);
    card.focus();
  }

  private renderFatalConnection(error: unknown): void {
    this.setTabsVisible(true);
    void this.stageMode?.leave();
    if (!this.stage) {
      return;
    }
    this.poller.stop();
    this.stage.dataset.liveScreen = 'error';
    const card = liveElement('div', 'quizgeist-host-state-card quizgeist-host-state-card--error', {
      role: 'alert',
      tabindex: 0,
    });
    const title = liveElement('h1', 'quizgeist-host-state-card__title', {
      text: this.text('live:connection:expired', 'Die Anmeldung ist abgelaufen.'),
    });
    const message = liveElement('p', 'quizgeist-host-state-card__text', {
      text: this.errorMessage(error),
    });
    const reload = liveButton(
      this.text('live:connection:reload', 'Neu laden und anmelden'),
      'quizgeist-host-button quizgeist-host-button--primary',
      {'data-action': 'reload'},
    );
    reload.addEventListener('click', () => window.location.reload());
    card.append(title, message, reload);
    this.stage.replaceChildren(card);
    card.focus();
  }

  private createBrand(): HTMLElement {
    const brand = liveElement('div', 'quizgeist-host-brand');
    const icon = liveElement('img', 'quizgeist-host-brand__icon', {
      alt: '',
      'aria-hidden': 'true',
      src: this.config.brandIconUrl,
    });
    const wordmark = liveElement('span', 'quizgeist-host-brand__wordmark');
    wordmark.append(
      liveElement('span', 'quizgeist-host-brand__quiz', {text: 'Quiz'}),
      liveElement('span', 'quizgeist-host-brand__geist', {text: 'geist'}),
    );
    brand.append(icon, wordmark);
    return brand;
  }

  private createTopbar(state: HostState): HTMLElement {
    const topbar = liveElement('header', 'quizgeist-host-topbar');
    const phase = liveElement('div', 'quizgeist-host-topbar__phase');
    phase.append(
      liveElement('span', 'quizgeist-host-chip', {
        text: this.phaseLabel(state.phase),
      }),
      liveElement('span', 'quizgeist-host-chip', {
        text: this.modeLabel(state.mode),
      }),
    );
    topbar.append(
      this.createBrand(),
      phase,
      createSoundControls(this.sound, this.config.strings || {}),
    );
    return topbar;
  }

  private createTimer(state: HostState): HTMLElement {
    const unlimited = state.phaseEndsAtMs <= 0;
    const timer = liveElement('div', `quizgeist-host-timer${unlimited ? ' is-unlimited' : ''}`, {
      'aria-label': unlimited
        ? this.text('editor:time:none', 'Ohne Zeitlimit')
        : '',
      role: 'timer',
      'data-live-timer': true,
    });
    timer.append(
      liveElement('span', 'quizgeist-host-timer__value', {
        'aria-hidden': 'true',
        'data-live-timer-value': true,
        text: unlimited ? '∞' : '0',
      }),
      liveVisuallyHidden(''),
    );
    timer.dataset.phaseStartedAtMs = String(state.phaseStartedAtMs);
    timer.dataset.phaseEndsAtMs = String(state.phaseEndsAtMs);
    return timer;
  }

  private questionMedia(question: LiveQuestion): HTMLElement | null {
    const media = createLiveMedia(question, {
      className: 'quizgeist-host-question__media-asset',
      label: this.text('editor:field:media', 'Fragenmedium'),
    });
    if (!media) {
      return null;
    }
    const frame = liveElement('div', 'quizgeist-host-question__media');
    frame.append(media);
    return frame;
  }

  private renderRanking(container: HTMLOListElement, state: HostState): void {
    container.replaceChildren();
    if (state.mode === 'team' && (state.teamRanking?.length || 0) > 0) {
      container.dataset.liveTeamRanking = '';
      state.teamRanking?.slice(0, 5).forEach((standing) => {
        const key = standing.teamKey || String(standing.id || '');
        const item = liveElement('li', 'quizgeist-host-ranking-row quizgeist-host-ranking-row--team', {
          'data-rank': standing.rank,
          'data-team-key': key,
          'data-live-ranking-team-key': key,
        });
        item.append(
          liveElement('span', 'quizgeist-host-ranking-row__rank', {
            text: String(standing.rank),
          }),
          liveElement('span', 'quizgeist-host-ranking-row__teammark', {
            'aria-hidden': 'true',
            text: '✦',
          }),
          liveElement('span', 'quizgeist-host-ranking-row__name', {
            text: standing.teamName || standing.name || key,
          }),
          liveElement('span', 'quizgeist-host-ranking-row__streak', {
            text: standing.memberCount
              ? `${standing.memberCount} Mitglieder`
              : '',
          }),
          liveElement('span', 'quizgeist-host-ranking-row__delta', {
            text: standing.delta
              ? `${standing.delta > 0 ? '+' : ''}${standing.delta}`
              : '',
          }),
          liveElement('strong', 'quizgeist-host-ranking-row__score', {
            text: this.pointsLabel(standing.score),
          }),
        );
        container.append(item);
      });
      return;
    }
    delete container.dataset.liveTeamRanking;
    const visible = state.ranking.slice(0, 5);
    visible.forEach((standing) => {
      const item = liveElement('li', 'quizgeist-host-ranking-row', {
        'data-player-id': standing.playerId,
        'data-rank': standing.rank,
        'data-live-ranking-player-id': standing.playerId,
      });
      const rank = liveElement('span', 'quizgeist-host-ranking-row__rank', {
        text: String(standing.rank),
      });
      const avatar = this.avatar(
        standing.avatarKey,
        standing.avatarUrl,
        standing.accessoryKey,
      );
      const name = liveElement('span', 'quizgeist-host-ranking-row__name', {
        text: standing.displayName,
      });
      const streak = liveElement('span', 'quizgeist-host-ranking-row__streak', {
        text: standing.streak
          ? `${this.text('live:streak', 'Serie')} ${standing.streak}`
          : '',
      });
      const delta = liveElement('span', 'quizgeist-host-ranking-row__delta', {
        text: standing.delta
          ? `${standing.delta > 0 ? '+' : ''}${standing.delta}`
          : '',
      });
      const score = liveElement('strong', 'quizgeist-host-ranking-row__score', {
        text: this.pointsLabel(standing.score),
      });
      item.append(rank, avatar, name, streak, delta, score);
      container.append(item);
    });
    const remaining = Math.max(0, state.playerCount - visible.length);
    if (remaining > 0) {
      const more = liveElement('li', 'quizgeist-host-ranking-row quizgeist-host-ranking-row--more', {
        text: this.text(
          'host:scoreboard:more',
          `… und ${remaining} weitere`,
          {a: remaining},
        ),
      });
      container.append(more);
    }
  }

  private podiumPlace(standing: LiveStanding): HTMLElement {
    const place = liveElement(
      'section',
      `quizgeist-host-podium-place quizgeist-host-podium-place--${standing.rank}`,
      {
        'data-player-id': standing.playerId,
        'data-rank': standing.rank,
        'data-live-podium-place': standing.rank,
      },
    );
    const person = liveElement('div', 'quizgeist-host-podium-place__person');
    person.append(
      this.avatar(
        standing.avatarKey,
        standing.avatarUrl,
        standing.accessoryKey,
      ),
      liveElement('h2', 'quizgeist-host-podium-place__name', {
        text: standing.displayName,
      }),
      liveElement('p', 'quizgeist-host-podium-place__score', {
        text: this.pointsLabel(standing.score),
      }),
    );
    const column = liveElement(
      'div',
      'quizgeist-host-podium-place__column mq-anim-podium-rise',
      {
        'aria-hidden': 'true',
        text: String(standing.rank),
      },
    );
    place.append(person, column);
    return place;
  }

  private avatar(
    avatarKey?: string | null,
    avatarUrl?: string | null,
    accessoryKey?: string | null,
  ): HTMLElement {
    const frame = liveElement('span', 'quizgeist-host-avatar');
    if (avatarUrl) {
      frame.append(
        liveElement('img', 'quizgeist-host-avatar__image', {
          alt: '',
          src: avatarUrl,
        }),
      );
    } else {
      frame.append(createAvatar({accessoryKey, avatarKey}));
    }
    return frame;
  }

  private teamPodiumPlace(standing: LiveTeamStanding): HTMLElement {
    const key = standing.teamKey || String(standing.id || '');
    const place = liveElement(
      'section',
      `quizgeist-host-podium-place quizgeist-host-podium-place--${standing.rank}`,
      {
        'data-live-team-podium-place': standing.rank,
        'data-rank': standing.rank,
        'data-team-key': key,
      },
    );
    const person = liveElement('div', 'quizgeist-host-podium-place__person');
    person.append(
      liveElement('span', 'quizgeist-host-podium-place__teammark', {
        'aria-hidden': 'true',
        text: '✦',
      }),
      liveElement('h2', 'quizgeist-host-podium-place__name', {
        text: standing.teamName || standing.name || key,
      }),
      liveElement('p', 'quizgeist-host-podium-place__score', {
        text: this.pointsLabel(standing.score),
      }),
    );
    const column = liveElement(
      'div',
      'quizgeist-host-podium-place__column mq-anim-podium-rise',
      {'aria-hidden': 'true', text: String(standing.rank)},
    );
    place.append(person, column);
    return place;
  }

  private createConfetti(): HTMLElement {
    const confetti = liveElement('div', 'quizgeist-host-confetti', {
      'aria-hidden': 'true',
    });
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return confetti;
    }
    for (let index = 0; index < 60; index += 1) {
      const particle = liveElement(
        'span',
        `mq-anim-confetti-particle quizgeist-host-confetti__particle quizgeist-host-confetti__particle--${CONFETTI_COLORS[index % CONFETTI_COLORS.length]}`,
      );
      particle.style.setProperty('--mq-confetti-x', `${(index * 37) % 101}%`);
      particle.style.setProperty('--mq-confetti-delay', `${(index * 73) % 1450}ms`);
      particle.style.setProperty('--mq-confetti-duration', `${2200 + ((index * 97) % 1400)}ms`);
      particle.style.setProperty('--mq-confetti-rotation', `${360 + ((index * 53) % 720)}deg`);
      confetti.append(particle);
    }
    return confetti;
  }

  private reconcilePlayers(container: HTMLElement, players: LivePlayer[]): void {
    const existing = new Map<number, HTMLElement>();
    container.querySelectorAll<HTMLElement>('[data-live-player-id]').forEach((node) => {
      existing.set(Number(node.dataset.livePlayerId), node);
    });
    const ordered: HTMLElement[] = [];
    players.forEach((player) => {
      let tile = existing.get(player.id);
      if (!tile) {
        tile = this.playerTile(player);
      } else {
        setNodeText(tile, '.quizgeist-host-player__name', player.displayName);
        tile.classList.toggle('is-away', player.status === 'away');
      }
      ordered.push(tile);
      existing.delete(player.id);
    });
    existing.forEach((node) => node.remove());
    container.append(...ordered);
    if (players.length === 0) {
      // P13: Diese Methode laeuft bei JEDEM Abrufzyklus. Ohne die Pruefung
      // haengte sie den Hinweis jedes Mal erneut an - in der Lobby wuchs
      // "Warte auf die ersten Mitspieler ..." damit unbegrenzt, solange
      // niemand beigetreten war (vom Betreiber am 2026-08-02 gemeldet).
      if (!container.querySelector('[data-live-player-empty]')) {
        container.append(
          liveElement('p', 'quizgeist-host-player-grid__empty', {
            text: this.text('host:lobby:empty', 'Warte auf die ersten Mitspieler …'),
            'data-live-player-empty': true,
          }),
        );
      }
    } else {
      // querySelectorAll, nicht querySelector: sind aus einem aelteren Stand
      // mehrere Kopien aufgelaufen, muessen ALLE weichen. Sonst blieben beim
      // Beitritt des ersten Mitspielers n-1 Zeilen auf der Projektion stehen.
      container.querySelectorAll('[data-live-player-empty]').forEach((node) => {
        node.remove();
      });
    }
  }

  private playerTile(player: LivePlayer): HTMLElement {
    const tile = liveElement('div', 'quizgeist-host-player', {
      'data-live-player-id': player.id,
    });
    tile.classList.toggle('is-away', player.status === 'away');
    tile.append(
      this.avatar(player.avatarKey, player.avatarUrl, player.accessoryKey),
      liveElement('span', 'quizgeist-host-player__name', {
        text: player.displayName,
      }),
    );
    return tile;
  }

  private patchCurrentScreen(): void {
    const state = this.state;
    if (!state || !this.stage) {
      return;
    }
    const expectedKey = this.screenKey(state);
    if (expectedKey !== this.currentScreenKey) {
      this.currentScreenKey = expectedKey;
      this.renderState();
      return;
    }
    setNodeText(
      this.stage,
      '[data-live-player-count]',
      this.playerCountLabel(state.playerCount),
    );
    setNodeText(
      this.stage,
      '[data-live-answer-count]',
      this.answeredLabel(state),
    );
    const playerGrid = this.stage.querySelector<HTMLElement>('[data-live-player-grid]');
    if (playerGrid) {
      this.reconcilePlayers(playerGrid, state.players);
    }
    const aggregate = this.stage.querySelector<HTMLElement>('[data-live-aggregate-slot]');
    if (aggregate && state.question) {
      const source = state.aggregate
        || (state.phase === 'reveal' ? state.distribution : null);
      aggregate.replaceChildren(...(source ? [renderLiveAggregate(
        state.question,
        source,
        {
          aggregate: state.aggregate,
          answer: null,
          audience: 'host',
          disabled: this.commandBusy,
          interactive: false,
          nowMs: this.serverNow(),
          ...(state.phase === 'question'
            ? {
              onAggregateAction: (data: Record<string, unknown>) => {
                this.submitAggregateInteraction(
                  state.question as LiveQuestion,
                  data,
                );
              },
            }
            : {}),
          text: (key, fallback, values = {}) => this.text(key, fallback, values),
        },
      )] : []));
    }
    const brainstormGrouping = this.stage.querySelector<HTMLElement>(
      '[data-live-brainstorm-grouping]',
    );
    if (brainstormGrouping
        && state.question?.qtype === 'brainstorm'
        && state.interactionStage === 'group') {
      const renderedIdeaIds = Array.from(
        brainstormGrouping.querySelectorAll<HTMLElement>(
          '[data-live-brainstorm-idea-assignment]',
        ),
        (node) => String(node.dataset.liveBrainstormIdeaAssignment || ''),
      ).filter(Boolean).sort();
      const authoritativeIdeaIds = this.brainstormGroups(state)
        .flatMap((group) => group.ideas.map((idea) => idea.id))
        .sort();
      if (JSON.stringify(renderedIdeaIds)
          !== JSON.stringify(authoritativeIdeaIds)) {
        // Moderation can remove or restore an idea without changing the screen
        // key. Rebuild only in that case; ordinary 1–2 s polls must preserve
        // the teacher's unsaved labels and select assignments.
        brainstormGrouping.replaceWith(this.renderBrainstormGrouping(state));
      }
    }
    const ranking = this.stage.querySelector<HTMLOListElement>('[data-live-ranking]');
    if (ranking) {
      this.renderRanking(ranking, state);
    }
    const start = this.stage.querySelector<HTMLButtonElement>('[data-action="start"]');
    if (start) {
      start.disabled = this.commandBusy || state.playerCount <= 0;
    }
    this.patchTimer();
  }

  private tickClock(): void {
    const state = this.state;
    if (!state || !this.stage) {
      return;
    }
    const key = this.screenKey(state);
    if (key !== this.currentScreenKey) {
      const wasCountdown = this.currentScreenKey.startsWith('countdown:');
      this.currentScreenKey = key;
      this.renderState();
      if (wasCountdown && key.startsWith('question:')) {
        this.sound.play('go');
      }
      return;
    }
    if (this.derivedScreen(state) === 'countdown') {
      const seconds = this.countdownSeconds(state);
      setNodeText(this.stage, '[data-live-countdown]', seconds);
      if (seconds !== this.lastTimerAnnouncement) {
        this.lastTimerAnnouncement = seconds;
        this.sound.play('countdown');
        setNodeText(
          this.stage,
          '[data-live-countdown-status]',
          String(seconds),
        );
      }
      return;
    }
    this.patchTimer();
  }

  private patchTimer(): void {
    const state = this.state;
    const timer = this.stage?.querySelector<HTMLElement>('[data-live-timer]');
    if (this.stage) {
      patchLiveResponseClock(this.stage, this.serverNow());
    }
    if (!state || !timer || state.phaseEndsAtMs <= 0) {
      return;
    }
    const now = this.serverNow();
    const remainingMs = Math.max(0, state.phaseEndsAtMs - now);
    const duration = Math.max(1, state.phaseEndsAtMs - state.phaseStartedAtMs);
    const progress = Math.max(0, Math.min(1, remainingMs / duration));
    const seconds = Math.max(0, Math.ceil(remainingMs / 1000));
    timer.style.setProperty('--mq-timer-progress', `${progress * 360}deg`);
    timer.classList.toggle('is-warning', seconds <= 5);
    if (seconds > 0 && seconds <= 5 && seconds !== this.lastWarningSecond) {
      this.lastWarningSecond = seconds;
      this.sound.play('warning');
    }
    setNodeText(timer, '[data-live-timer-value]', seconds);
    timer.setAttribute(
      'aria-label',
      this.text(
        'host:question:remaining',
        `${seconds} Sekunden`,
        {a: seconds},
      ),
    );
    if (seconds === 5 && this.lastTimerAnnouncement !== 5) {
      this.lastTimerAnnouncement = 5;
      this.announce(
        this.text(
          'host:question:remaining',
          '5 Sekunden',
          {a: 5},
        ),
      );
    }
  }

  private brainstormGroups(state: HostState): BrainstormEditorGroup[] {
    const aggregate = state.aggregate && typeof state.aggregate === 'object'
      ? state.aggregate as Record<string, unknown>
      : {};
    const rawGroups = Array.isArray(aggregate.groups) ? aggregate.groups : [];
    return rawGroups.flatMap((rawGroup): BrainstormEditorGroup[] => {
      if (!rawGroup || typeof rawGroup !== 'object' || Array.isArray(rawGroup)) {
        return [];
      }
      const group = rawGroup as Record<string, unknown>;
      const ideas = Array.isArray(group.ideas) ? group.ideas : [];
      return [{
        ideas: ideas.flatMap((rawIdea): Array<{id: string; text: string}> => {
          if (!rawIdea || typeof rawIdea !== 'object' || Array.isArray(rawIdea)) {
            return [];
          }
          const idea = rawIdea as Record<string, unknown>;
          const id = String(idea.id || '');
          return id === '' ? [] : [{
            id,
            text: String(idea.text || ''),
          }];
        }),
        key: String(group.key || ''),
        label: String(group.label || ''),
      }];
    }).filter((group) => group.key !== '');
  }

  private renderBrainstormGrouping(state: HostState): HTMLElement {
    const editor = liveElement('section', 'quizgeist-host-brainstorm-editor', {
      'data-live-brainstorm-grouping': true,
    });
    editor.append(
      liveElement('h2', 'quizgeist-host-section-heading__title', {
        text: this.text('host:action:groupideas', 'Ideen gruppieren'),
      }),
      liveElement('p', 'quizgeist-host-setup__description', {
        text: this.text(
          'host:brainstorm:description',
          'Benennen Sie die Gruppen und ordnen Sie jede Idee genau einer Gruppe zu.',
        ),
      }),
    );
    const groups = this.brainstormGroups(state);
    if (groups.length === 0) {
      groups.push({
        ideas: [],
        key: 'group-1',
        label: this.text('host:brainstorm:defaultgroup', 'Gruppe {$a}', {a: 1}),
      });
    }
    if (groups.length === 1) {
      groups.push({
        ideas: [],
        key: 'group-2',
        label: this.text('host:brainstorm:defaultgroup', 'Gruppe {$a}', {a: 2}),
      });
    }
    const labels = liveElement('div', 'quizgeist-host-brainstorm-editor__groups');
    const assignments = liveElement('div', 'quizgeist-host-brainstorm-editor__ideas');
    const appendGroup = (key: string, labelText: string): void => {
      const label = liveElement('label', 'quizgeist-host-field__label', {
        'data-live-brainstorm-group-editor': key,
        text: this.text('host:brainstorm:groupname', 'Gruppenname'),
      });
      label.append(liveElement('input', 'quizgeist-host-text-input', {
        'data-live-brainstorm-group-label': key,
        maxlength: 80,
        type: 'text',
        value: labelText,
      }));
      labels.append(label);
    };
    groups.forEach((group, index) => {
      appendGroup(
        group.key,
        group.label || this.text(
          'host:brainstorm:defaultgroup',
          'Gruppe {$a}',
          {a: index + 1},
        ),
      );
    });
    groups.flatMap((group) => group.ideas.map((idea) => ({
      ...idea,
      groupKey: group.key,
    }))).forEach((idea) => {
      const row = liveElement('label', 'quizgeist-host-brainstorm-editor__idea', {
        'data-live-brainstorm-idea-assignment': idea.id,
        text: idea.text,
      });
      const select = liveElement('select', 'quizgeist-host-select', {
        'data-live-brainstorm-group-assignment': idea.id,
      });
      groups.forEach((group, index) => {
        select.append(liveElement('option', '', {
          text: group.label || this.text(
            'host:brainstorm:defaultgroup',
            'Gruppe {$a}',
            {a: index + 1},
          ),
          value: group.key,
        }));
      });
      select.value = idea.groupKey;
      row.append(select);
      assignments.append(row);
    });
    const actions = liveElement('div', 'quizgeist-host-controlbar__actions');
    const add = liveButton(
      this.text('host:brainstorm:addgroup', 'Gruppe hinzufügen'),
      'quizgeist-host-button quizgeist-host-button--secondary',
      {'data-live-brainstorm-add-group': true},
    );
    add.addEventListener('click', () => {
      const index = labels.childElementCount + 1;
      const key = `group-${index}`;
      const label = this.text(
        'host:brainstorm:defaultgroup',
        'Gruppe {$a}',
        {a: index},
      );
      appendGroup(key, label);
      assignments.querySelectorAll<HTMLSelectElement>(
        '[data-live-brainstorm-group-assignment]',
      ).forEach((select) => {
        select.append(liveElement('option', '', {text: label, value: key}));
      });
    });
    const save = liveButton(
      this.text('host:brainstorm:savegroups', 'Gruppierung speichern'),
      'quizgeist-host-button quizgeist-host-button--secondary',
      {
        'aria-busy': this.commandBusy ? 'true' : 'false',
        'data-live-brainstorm-save-groups': true,
        'data-live-host-interaction': true,
      },
    );
    save.addEventListener('click', () => {
      const question = this.state?.question;
      const payload = this.readBrainstormGrouping();
      if (question && payload) {
        void this.saveBrainstormGrouping(question, payload);
      }
    });
    actions.append(add, save);
    editor.append(labels, assignments, actions);
    return editor;
  }

  private readBrainstormGrouping(): BrainstormGrouping[] | null {
    const editor = this.stage?.querySelector<HTMLElement>(
      '[data-live-brainstorm-grouping]',
    );
    if (!editor) {
      return null;
    }
    const groups = Array.from(
      editor.querySelectorAll<HTMLInputElement>('[data-live-brainstorm-group-label]'),
    ).map((input, index) => ({
      ideaIds: [] as number[],
      key: input.dataset.liveBrainstormGroupLabel || `group-${index + 1}`,
      label: input.value.trim() || this.text(
        'host:brainstorm:defaultgroup',
        'Gruppe {$a}',
        {a: index + 1},
      ),
    }));
    const byKey = new Map(groups.map((group) => [group.key, group]));
    editor.querySelectorAll<HTMLSelectElement>(
      '[data-live-brainstorm-group-assignment]',
    ).forEach((select) => {
      const group = byKey.get(select.value);
      const ideaId = Number(select.dataset.liveBrainstormGroupAssignment || 0);
      if (group && Number.isInteger(ideaId) && ideaId > 0) {
        group.ideaIds.push(ideaId);
      }
    });
    return groups.filter((group) => group.ideaIds.length > 0);
  }

  private brainstormGroupingFingerprint(groups: BrainstormGrouping[]): string {
    return JSON.stringify(groups.map((group) => ({
      ideaIds: [...group.ideaIds].sort((left, right) => left - right),
      key: group.key,
      label: group.label,
    })).sort((left, right) => left.key.localeCompare(right.key)));
  }

  private brainstormGroupingMatchesState(
    state: HostState,
    groups: BrainstormGrouping[],
  ): boolean {
    const aggregate = state.aggregate && typeof state.aggregate === 'object'
      ? state.aggregate as Record<string, unknown>
      : {};
    if (aggregate.groupingCurrent !== true) {
      return false;
    }
    const authoritative = this.brainstormGroups(state).map((group) => ({
      ideaIds: group.ideas.map((idea) => Number(idea.id)).filter(
        (ideaId) => Number.isInteger(ideaId) && ideaId > 0,
      ),
      key: group.key,
      label: group.label,
    }));
    return this.brainstormGroupingFingerprint(authoritative)
      === this.brainstormGroupingFingerprint(groups);
  }

  private async saveBrainstormGrouping(
    question: LiveQuestion,
    groups: BrainstormGrouping[],
  ): Promise<boolean> {
    const state = this.state;
    if (state
        && state.question?.questionToken === question.questionToken
        && this.brainstormGroupingMatchesState(state, groups)) {
      return true;
    }
    return this.sendHostInteraction(
      question,
      'submit',
      {groups},
      'group',
    );
  }

  private async advanceInteraction(
    question: LiveQuestion,
    stage: string,
  ): Promise<void> {
    if (stage === 'group') {
      const groups = this.readBrainstormGrouping();
      if (groups && groups.length > 0) {
        const saved = await this.saveBrainstormGrouping(question, groups);
        if (!saved) {
          return;
        }
      }
    }
    const current = this.state?.question;
    if (current) {
      await this.sendHostInteraction(current, 'advance');
    }
  }

  private async sendHostInteraction(
    question: LiveQuestion,
    operation: 'advance' | 'submit',
    data?: Record<string, unknown>,
    interactionKind?: 'group' | 'moderation',
  ): Promise<boolean> {
    const state = this.state;
    const questionStage = question.policyDescriptor?.currentStage
      || question.interactionStage
      || null;
    if (!state
        || this.commandBusy
        || state.question?.questionToken !== question.questionToken
        || (questionStage !== null
          && state.interactionStage !== null
          && questionStage !== state.interactionStage)) {
      return false;
    }
    const submissionKey = operation === 'submit'
      ? typeof window.crypto?.randomUUID === 'function'
        ? window.crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`
      : null;
    return this.performHostMutation(state, (expectedState) => (
      this.api.post<HostStateResult>('live_host_interaction', {
        ...(data ? {data} : {}),
        expectedStateVersion: expectedState.stateVersion,
        ...(interactionKind ? {interactionKind} : {}),
        operation,
        questionId: question.id,
        questionToken: question.questionToken,
        sessionId: state.sessionId,
        ...(submissionKey ? {submissionKey} : {}),
      })
    ));
  }

  private submitAggregateInteraction(
    question: LiveQuestion,
    data: Record<string, unknown>,
  ): void {
    const interactionKind = data.interactionKind === 'moderation'
      ? 'moderation'
      : undefined;
    const payload = {...data};
    delete payload.interactionKind;
    void this.sendHostInteraction(
      question,
      'submit',
      payload,
      interactionKind,
    );
  }

  private countdownSeconds(state: HostState): number {
    return Math.max(1, Math.ceil((state.phaseStartedAtMs - this.serverNow()) / 1000));
  }

  private commandButton(
    command: HostCommand,
    label: string,
    variant: 'primary' | 'secondary' | 'quiet-danger',
    disabled = false,
  ): HTMLButtonElement {
    const button = liveButton(
      label,
      `quizgeist-host-button quizgeist-host-button--${variant}`,
      {
        'aria-busy': this.commandBusy ? 'true' : 'false',
        disabled: disabled || this.commandBusy,
        'data-action': command,
        'data-live-command': true,
      },
    );
    button.addEventListener('click', () => {
      void this.sound.unlock();
      this.sound.play('tap');
      void this.sendCommand(command, button);
    });
    return button;
  }

  private async sendCommand(
    command: HostCommand,
    sourceButton?: HTMLButtonElement,
  ): Promise<boolean> {
    const state = this.state;
    if (!state || this.commandBusy) {
      return false;
    }
    if (command === 'abort' && !await this.confirmAbort(sourceButton)) {
      return false;
    }
    return this.performHostMutation(state, (expectedState) => (
      this.api.post<HostStateResult>(
        'live_host_command',
        {
          command,
          expectedStateVersion: expectedState.stateVersion,
          sessionId: state.sessionId,
        },
      )
    ));
  }

  private confirmAbort(sourceButton?: HTMLButtonElement): Promise<boolean> {
    if (this.abortDialog) {
      return Promise.resolve(false);
    }
    const prompt = this.text(
      'host:action:abortconfirm',
      'Diese Live-Session wirklich abbrechen?',
    );
    const dialog = liveElement('dialog', 'quizgeist-host-dialog', {
      'aria-modal': 'true',
    });
    const titleId = `quizgeist-host-abort-title-${this.config.cmid}`;
    const descriptionId = `quizgeist-host-abort-description-${this.config.cmid}`;
    dialog.setAttribute('aria-labelledby', titleId);
    dialog.setAttribute('aria-describedby', descriptionId);
    const title = liveElement('h2', 'quizgeist-host-dialog__title', {
      id: titleId,
      text: this.text('host:action:abort', 'Session abbrechen'),
    });
    const description = liveElement('p', 'quizgeist-host-dialog__description', {
      id: descriptionId,
      text: prompt,
    });
    const actions = liveElement('div', 'quizgeist-host-dialog__actions');
    const confirm = liveButton(
      this.text('host:action:abortconfirmbutton', 'Session abbrechen'),
      'quizgeist-host-button quizgeist-host-button--quiet-danger',
    );
    const continueButton = liveButton(
      this.text('host:action:abortcontinue', 'Weiterspielen'),
      'quizgeist-host-button quizgeist-host-button--secondary',
    );
    actions.append(confirm, continueButton);
    dialog.append(title, description, actions);
    this.abortDialog = dialog;
    return new Promise((resolve) => {
      let settled = false;
      const finish = (confirmed: boolean): void => {
        if (settled) {
          return;
        }
        settled = true;
        if (dialog.open) {
          dialog.close();
        }
        dialog.remove();
        if (this.abortDialog === dialog) {
          this.abortDialog = null;
        }
        if (sourceButton?.isConnected) {
          sourceButton.focus();
        }
        resolve(confirmed);
      };
      confirm.addEventListener('click', () => finish(true));
      continueButton.addEventListener('click', () => finish(false));
      dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        finish(false);
      });
      dialog.addEventListener('close', () => finish(false));
      this.root.append(dialog);
      try {
        dialog.showModal();
        continueButton.focus();
      } catch (_error) {
        finish(false);
      }
    });
  }

  private async performHostMutation(
    state: HostState,
    request: (expectedState: HostState) => Promise<HostStateResult>,
  ): Promise<boolean> {
    this.setCommandBusy(true);
    try {
      const outcome = await retryHostMutation({
        applyConflictState: (fresh) => this.applyState(fresh),
        conflictState: (error) => error instanceof LiveApiError
            && error.status === 409
            && error.data?.state
          ? error.data.state
          : null,
        currentState: () => this.state,
        initialState: state,
        request,
      });
      if (outcome.status !== 'success') {
        const message = outcome.status === 'conflict-exhausted'
          ? this.text(
            'live:error:statechanged',
            'Die Session hat sich während der Aktion mehrfach aktualisiert. '
              + 'Bitte versuchen Sie es erneut.',
          )
          : this.text(
            'live:error:conflict',
            'Die Session wurde inzwischen an anderer Stelle weitergeschaltet.',
          );
        this.showConnectionMessage(message, 'warning');
        this.announce(message);
        return false;
      }
      if (!outcome.value.state) {
        throw new LiveApiError(
          this.text('live:error:request', 'Die Aktion konnte nicht ausgeführt werden.'),
          'invalid_response',
        );
      }
      this.applyState(outcome.value.state);
      return true;
    } catch (error) {
      if (this.isAuthenticationError(error)) {
        this.renderFatalConnection(error);
      } else {
        this.showConnectionMessage(this.errorMessage(error), 'error');
      }
      return false;
    } finally {
      this.setCommandBusy(false);
    }
  }

  private async endAndPrepareNewRound(): Promise<void> {
    if (!await this.sendCommand('end')) {
      return;
    }
    this.clearStoredSession();
    this.state = null;
    this.poller.stop();
    this.renderSetup();
  }

  private setCommandBusy(busy: boolean): void {
    this.commandBusy = busy;
    this.root.setAttribute('aria-busy', busy ? 'true' : 'false');
    this.root.querySelectorAll<HTMLButtonElement>(
      '[data-live-command], [data-live-host-interaction]',
    ).forEach((button) => {
      const action = button.dataset.action;
      const unavailable = (action === 'start' && this.state?.playerCount === 0)
        || (action === 'previous' && (this.state?.currentIndex ?? -1) <= 0)
        || ((button.dataset.liveWordcloudModerate !== undefined
            || button.dataset.liveBrainstormModerate !== undefined)
          && button.getAttribute('aria-pressed') === 'true');
      button.disabled = busy || unavailable;
      button.setAttribute('aria-busy', busy ? 'true' : 'false');
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
  }

  private showConnectionMessage(
    message: string,
    tone: 'warning' | 'error' | 'success',
  ): void {
    if (!this.connectionBanner) {
      return;
    }
    this.connectionBanner.hidden = false;
    this.connectionBanner.dataset.tone = tone;
    this.connectionBanner.textContent = message;
  }

  private markConnectionRestored(): void {
    if (!this.connectionBanner) {
      return;
    }
    if (this.connectionTroubled) {
      this.connectionTroubled = false;
      this.showConnectionMessage(
        this.text('live:connection:restored', 'Wieder verbunden.'),
        'success',
      );
      window.setTimeout(() => {
        if (this.connectionBanner?.dataset.tone === 'success') {
          this.connectionBanner.hidden = true;
          this.connectionBanner.textContent = '';
        }
      }, 1800);
      return;
    }
    this.connectionBanner.hidden = true;
    this.connectionBanner.textContent = '';
  }

  private isAuthenticationError(error: unknown): boolean {
    return error instanceof LiveApiError
      && (error.status === 401 || error.status === 403);
  }

  private errorMessage(error: unknown): string {
    if (error instanceof LiveApiError && error.message !== '') {
      return error.message;
    }
    if (error instanceof Error && error.message !== '') {
      return error.message;
    }
    return this.text(
      'live:error:request',
      'Die Live-Session konnte die Anfrage nicht verarbeiten.',
    );
  }

  private joinUrl(joinCode: string): string {
    const url = new URL(this.config.playerUrlBase, window.location.href);
    url.searchParams.set('code', joinCode);
    return url.toString();
  }

  private phaseLabel(phase: HostState['phase']): string {
    if (phase === 'aborted') {
      return this.text('host:action:abort', 'Session abgebrochen');
    }
    const fallback: Record<string, string> = {
      ended: 'Beendet',
      lobby: 'Lobby',
      podium: 'Podium',
      question: 'Frage',
      reveal: 'Auflösung',
      scoreboard: 'Zwischenstand',
    };
    return this.text(`live:phase:${phase}`, fallback[phase] || 'Live-Session');
  }

  private nameModeLabel(nameMode: HostState['nameMode']): string {
    const fallback: Record<string, string> = {
      custom: 'Eigene Spitznamen',
      generated: 'Lustige Namen erzeugen',
      real: 'Echte Namen',
    };
    return this.text(
      `host:namemode:${nameMode}`,
      fallback[nameMode] || 'Teilnehmernamen',
    );
  }

  private modeLabel(mode: string): string {
    const fallback: Record<string, string> = {
      accuracy: 'Genauigkeit',
      classic: 'Klassisch',
      security: 'Sicherheit',
      team: 'Teammodus',
    };
    return this.text(`mode:${mode}`, fallback[mode] || 'Spielmodus');
  }

  private questionTypeLabel(question: LiveQuestion): string {
    const fallback: Record<string, string> = {
      brainstorm: 'Brainstorming',
      open: 'Offene Frage',
      pin: 'Pin platzieren',
      poll: 'Umfrage',
      puzzle: 'Puzzle',
      quiz: 'Quiz',
      reveal: 'Bild aufdecken',
      scale: 'Skala',
      shortanswer: 'Antwort eingeben',
      slide: 'Inhaltsfolie',
      slider: 'Schieberegler',
      truefalse: 'Wahr oder falsch',
      wordcloud: 'Wortwolke',
    };
    return this.text(
      `live:qtype:${question.qtype}`,
      fallback[question.qtype] || 'Frage',
    );
  }

  private questionProgress(question: LiveQuestion, state: HostState): string {
    const current = question.index + 1;
    const total = question.total > 0
      ? question.total
      : state.totalQuestions;
    return this.text(
      'host:question:progress',
      `Frage ${current} von ${total}`,
      {current, total},
    );
  }

  private answeredLabel(state: HostState): string {
    return this.text(
      'host:question:answered',
      `${state.answerCount} / ${state.playerCount} haben geantwortet`,
      {
        answered: state.answerCount,
        players: state.playerCount,
      },
    );
  }

  private playerCountLabel(playerCount: number): string {
    return `${playerCount} ${this.text('host:lobby:players', 'Mitspieler')}`;
  }

  private pointsLabel(points: number): string {
    const locale = document.documentElement.lang || undefined;
    const value = Math.max(0, Math.round(Number(points || 0)));
    return `${value.toLocaleString(locale)} ${this.text('live:points', 'Punkte')}`;
  }

}
