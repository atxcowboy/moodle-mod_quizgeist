import {EditorApi, EditorApiError} from './api';
import {
  cloneQuestionForSave,
  isQuestionType,
  normalizeQuestion,
} from './defaults';
import {
  appendChildren,
  button,
  closeDialog,
  element,
  labelledField,
  openDialog,
  setButtonBusy,
} from './dom';
import {DictationControl} from './dictation';
import {aiWarningMessages, editorString, validationMessages} from './messages';
import {QuestionFormRenderer} from './question-form';
import {countPlayableQuestions, isPlayableQuestion} from './readiness';
import {renderTagPicker, TagStore} from './tags';
import {
  renderCuratorList,
  type CurationDecision,
} from '../workshop/curator-list';
import {
  normaliseWorkshopView,
  readFieldErrors,
  workshopErrorMessage,
  type WorkshopView,
} from '../workshop/student-form';
import {TtsPlayer} from './tts';
import {createTtsControl} from '../live/tts';
import {
  AI_FORMATS,
  AI_SOURCES,
  type AiBootstrap,
  type AiDraft,
  type AiDraftFile,
  type AiDraftItem,
  type AiExplanationDraft,
  type AiFormat,
  type AiSource,
  type AiSourceSelection,
  type EditorActivity,
  type EditorBootstrap,
  type EditorConfig,
  type EditorQuestion,
  type MediaFile,
  type QuestionOptions,
  type QuestionType,
  type SlideOptions,
  type TemplateSummary,
} from './types';

type SaveState = 'saved' | 'saving' | 'dirty' | 'error' | 'conflict';
type EditorView = 'editor' | 'templates';
type TemplateMode = 'append' | 'replace';

const FILE_AI_SOURCES: readonly AiSource[] = [
  'pdf',
  'pdf_questions',
  'slides',
  'handwriting',
];

interface RawRecord {
  [key: string]: unknown;
}

interface AiSourceRequest {
  draftItemId: string;
  onSelected: (selection: AiSourceSelection) => void;
  purpose: AiSource;
  sourceToken: string;
  sourceWindow: Window | null;
}

export class EditorApp {
  private readonly api: EditorApi;
  private readonly tts: TtsPlayer;
  private activity: EditorActivity = {
    allowbacktrack: true,
    background: [],
    id: 0,
    logo: [],
    name: '',
    season: 'herbst',
    theme: 'hell',
    timemodified: 0,
  };
  private availableThemes: string[] = [
    'hell',
    'dunkel',
    'weltraum',
    'ozean',
    'retro-arcade',
  ];
  private availableSeasons: string[] = [];
  private questions: EditorQuestion[] = [];
  private selectedQuestionId: number | null = null;
  private view: EditorView;
  private saveState: SaveState = 'saved';
  private saveError = '';
  private saveStatusElement: HTMLElement | null = null;
  private statusLiveElement: HTMLElement | null = null;
  private toastRegion: HTMLElement | null = null;
  private workshop: WorkshopView | null = null;
  private autosaveTimer: number | null = null;
  private saveLoopRunning = false;
  private readonly saveLoopWaiters: Array<() => void> = [];
  private activityDirtyVersion = 0;
  private activitySavedVersion = 0;
  private questionOrderDirtyVersion = 0;
  private questionOrderSavedVersion = 0;
  private readonly questionDirtyVersions = new Map<number, number>();
  private readonly questionSavedVersions = new Map<number, number>();
  private readonly questionIdAliases = new Map<number, number>();
  private draggedQuestionId: number | null = null;
  private bootstrapController: AbortController | null = null;
  private aiGenerateController: AbortController | null = null;
  private aiExplanationController: AbortController | null = null;
  private aiExplanationDialog: HTMLDialogElement | null = null;
  private aiDraft: AiDraft | null = null;
  private aiDialog: HTMLDialogElement | null = null;
  private aiSourceDialog: HTMLDialogElement | null = null;
  private aiSourceSelection: AiSourceSelection | null = null;
  private aiSourceRequest: AiSourceRequest | null = null;
  private aiConfig: AiBootstrap = {
    acceptedDocuments: [],
    acceptedVision: [],
    aiSourcePickerUrl: '',
    available: false,
    entitlementStatus: 'not_installed',
    fallbackAvailable: false,
    formats: [...AI_FORMATS],
    gatewayAvailable: false,
    installed: false,
    managedServerInstalled: false,
    maxPdfPages: 150,
    maxQuestions: 50,
    notice: '',
    outboundFetchAllowed: false,
    visionAvailable: false,
  };
  private templateSearchController: AbortController | null = null;
  private templateSearchTimer: number | null = null;
  private mediaDialog: HTMLDialogElement | null = null;
  private mediaDialogQuestionId: number | null = null;
  private conflictDialog: HTMLDialogElement | null = null;
  private questionDefaults: EditorBootstrap['questionDefaults'] = {};
  private supportedTypes: QuestionType[] = [];
  private liveSupportedTypes: string[] = [];
  private liveSupportedTypeSet = new Set<string>();
  private releaseResult = '';
  private releaseAllRunning = false;
  private readonly releasingQuestionIds = new Set<number>();
  private formRenderer: QuestionFormRenderer;
  /** U1 Tagging-Kern. Free of charge; hangs on no addon. */
  private tagStore: TagStore;

  public constructor(
    private readonly root: HTMLElement,
    private readonly config: EditorConfig,
  ) {
    this.api = new EditorApi(config);
    this.tts = new TtsPlayer(config);
    this.tagStore = new TagStore(this.api, config.strings);
    this.view = config.initialView === 'templates' ? 'templates' : 'editor';
    this.formRenderer = new QuestionFormRenderer(config, {
      isAiAvailable: () => this.aiConfig.available,
      onAiExplanation: () => {
        const question = this.selectedQuestion();
        if (question) {
          void this.openAiExplanation(question);
        }
      },
      onChange: () => {
        const question = this.selectedQuestion();
        if (question) {
          this.markQuestionDirty(question.id);
          this.refreshQuestionTitle(question);
        }
      },
      onMedia: (target) => {
        const question = this.selectedQuestion();
        if (question) {
          void this.openMediaDialog('questionmedia', question.id, target);
        }
      },
      onPreview: () => this.openPreview(),
    });
  }

  private s(key: string, fallback = ''): string {
    return editorString(this.config.strings, key, fallback);
  }

  /**
   * Editor string with the {$name} substitution the panels of P11 use.
   *
   * editorString() itself deliberately knows no placeholders; the workshop
   * list needs them, and re-implementing the lookup would create a second
   * place where a missing key could become visible.
   */
  private workshopText(
    key: string,
    fallback: string,
    values: Record<string, string | number> = {},
  ): string {
    return Object.entries(values).reduce(
      (text, [name, value]) => text
        .split(`{$a->${name}}`).join(String(value))
        .split(`{$${name}}`).join(String(value)),
      this.s(key, fallback),
    );
  }

  public async init(): Promise<void> {
    this.root.dataset.quizgeistRoot = 'edit';
    this.root.dataset.quizgeistTheme = this.config.theme || 'hell';
    this.root.dataset.quizgeistSeason = this.config.season || 'herbst';
    this.root.classList.add('quizgeist-editor-root');
    this.installGlobalListeners();
    this.renderLoading();
    await this.loadBootstrap();
  }

  private installGlobalListeners(): void {
    window.addEventListener('beforeunload', (event) => {
      if (!this.hasUnsavedChanges()) {
        return;
      }
      event.preventDefault();
      event.returnValue = '';
    });
    window.addEventListener('online', () => {
      if (this.saveState === 'error') {
        void this.runSaveLoop();
      }
    });
    window.addEventListener('message', (event) => {
      if (event.origin !== window.location.origin) {
        return;
      }
      const message = event.data;
      const cancelled = message && typeof message === 'object'
        && message.type === 'quizgeist-media-cancelled';
      if (cancelled) {
        if (this.mediaDialog) {
          closeDialog(this.mediaDialog);
          this.mediaDialog = null;
        }
        this.mediaDialogQuestionId = null;
        return;
      }
      const saved = message && typeof message === 'object'
        && message.type === 'quizgeist-media-saved';
      if (saved) {
        this.handleMediaSavedMessage(message);
        return;
      }
      const aiSourceSelected = message && typeof message === 'object'
        && message.type === 'quizgeist-ai-source-selected';
      if (aiSourceSelected) {
        this.handleAiSourceSelected(message, event.source);
        return;
      }
      const aiSourceReady = message && typeof message === 'object'
        && message.type === 'quizgeist-ai-source-ready';
      if (aiSourceReady) {
        this.handleAiSourceReady(message, event.source);
        return;
      }
      const aiSourceCancelled = message && typeof message === 'object'
        && message.type === 'quizgeist-ai-source-cancelled';
      if (aiSourceCancelled) {
        this.handleAiSourceCancelled(message, event.source);
      }
    });
  }

  private renderLoading(): void {
    this.root.replaceChildren();
    const loading = element('div', 'quizgeist-editor-loading', {
      role: 'status',
      'aria-live': 'polite',
    });
    const spinner = element('span', 'quizgeist-spinner', {'aria-hidden': 'true'});
    appendChildren(loading, spinner, this.s('editor:loading'));
    this.root.append(loading);
  }

  private async loadBootstrap(preserveSelection = false): Promise<void> {
    this.bootstrapController?.abort();
    this.bootstrapController = new AbortController();
    const previousSelection = preserveSelection ? this.selectedQuestionId : null;
    try {
      const data = await this.api.post<EditorBootstrap>(
        'editor_bootstrap',
        {},
        this.bootstrapController.signal,
      );
      if (!Array.isArray(data.liveSupportedTypes)) {
        throw new Error(this.s('editor:error:response'));
      }
      this.questionDefaults = data.questionDefaults || {};
      // Tagging is a base feature: a failure here must never break the
      // editor, it only leaves the label block out.
      void this.tagStore.load(this.bootstrapController.signal)
        .then(() => this.render())
        .catch(() => undefined);
      // F7: die Kuratierungsliste haengt am selfstudy-Addon und an
      // mod/quizgeist:curatequestions. Fehlt eines von beidem, erscheint der
      // Bereich gar nicht — ein Fehler ist das nicht.
      void this.loadWorkshop(this.bootstrapController.signal)
        .then(() => this.render())
        .catch(() => undefined);
      const defaultTypes = Object.keys(this.questionDefaults)
        .filter(isQuestionType);
      const serverTypes = Array.isArray(data.supportedTypes)
        ? data.supportedTypes.filter(isQuestionType)
        : [];
      this.supportedTypes = serverTypes.filter((qtype) => (
        Object.prototype.hasOwnProperty.call(this.questionDefaults, qtype)
      ));
      if (this.supportedTypes.length === 0) {
        this.supportedTypes = defaultTypes;
      }
      if (this.supportedTypes.length === 0) {
        throw new Error(this.s('editor:error:response'));
      }
      this.liveSupportedTypes = [...new Set(data.liveSupportedTypes
        .filter((qtype): qtype is string => (
          typeof qtype === 'string' && qtype.trim() !== ''
        ))
        .map((qtype) => qtype.trim()))];
      this.liveSupportedTypeSet = new Set(this.liveSupportedTypes);
      if (typeof data.mediaPickerUrl === 'string' && data.mediaPickerUrl !== '') {
        this.config.mediaUrl = data.mediaPickerUrl;
      }
      this.aiConfig = this.normalizeAiBootstrap(data.ai);
      if (data.tts) {
        const voices = Array.isArray(data.tts.voices) ? data.tts.voices : [];
        const speakUrl = data.tts.speakUrl || '';
        this.config.tts = {
          available: Boolean(data.tts.available && speakUrl && voices.length > 0),
          defaultVoiceId: Number(data.tts.defaultVoiceId || voices[0]?.id || 0),
          speakUrl,
          voices,
        };
      }
      this.activity = this.normalizeActivity(data.activity);
      const knownThemes = [
        'hell',
        'dunkel',
        'weltraum',
        'ozean',
        'retro-arcade',
        'jahreszeiten',
      ];
      this.availableThemes = Array.isArray(data.themes)
        ? data.themes.filter((theme) => knownThemes.includes(theme))
        : [...knownThemes.slice(0, 5)];
      if (!this.availableThemes.includes(this.activity.theme)
          && knownThemes.includes(this.activity.theme)) {
        this.availableThemes.push(this.activity.theme);
      }
      const knownSeasons = ['herbst', 'winter', 'fruehling', 'sommer'];
      this.availableSeasons = Array.isArray(data.seasons)
        ? data.seasons.filter((season) => knownSeasons.includes(season))
        : [];
      if (this.activity.theme === 'jahreszeiten'
          && !this.availableSeasons.includes(this.activity.season)
          && knownSeasons.includes(this.activity.season)) {
        this.availableSeasons.push(this.activity.season);
      }
      this.questions = Array.isArray(data.questions)
        ? data.questions.map((question) => normalizeQuestion(question))
          .filter((question) => question.id > 0)
          .sort((left, right) => left.sortorder - right.sortorder)
        : [];
      this.questions.forEach((question, index) => {
        question.sortorder = index;
      });
      this.selectedQuestionId = previousSelection
        && this.questions.some((question) => question.id === previousSelection)
        ? previousSelection
        : this.questions[0]?.id || null;
      this.activityDirtyVersion = 0;
      this.activitySavedVersion = 0;
      this.questionOrderDirtyVersion = 0;
      this.questionOrderSavedVersion = 0;
      this.questionDirtyVersions.clear();
      this.questionSavedVersions.clear();
      this.questionIdAliases.clear();
      this.setSaveState('saved');
      this.applyAppearance();
      this.render();
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        return;
      }
      this.renderLoadError(error);
    }
  }

  private normalizeActivity(raw: unknown): EditorActivity {
    const source = this.record(raw);
    return {
      allowbacktrack: source.allowbacktrack === true,
      background: this.normalizeFiles(source.background),
      id: Number(source.id || 0),
      logo: this.normalizeFiles(source.logo),
      name: typeof source.name === 'string' ? source.name : '',
      season: typeof source.season === 'string' && source.season !== ''
        ? source.season
        : 'herbst',
      theme: typeof source.theme === 'string' && source.theme !== ''
        ? source.theme
        : 'hell',
      timemodified: Number(source.timemodified || 0),
    };
  }

  private normalizeFiles(raw: unknown): MediaFile[] {
    const entries = Array.isArray(raw) ? raw : [];
    if (entries.length === 0) {
      return [];
    }
    return entries.map((entry) => {
      const file = this.record(entry);
      return {
        filename: String(file.filename || ''),
        filepath: String(file.filepath || '/'),
        mimetype: String(file.mimetype || ''),
        path: String(file.path || ''),
        url: String(file.url || ''),
      };
    }).filter((file) => file.filename !== '' && file.url !== '');
  }

  private renderLoadError(error: unknown): void {
    this.root.replaceChildren();
    const box = element('section', 'quizgeist-editor-error', {role: 'alert'});
    const heading = element('h2', '', {text: this.s('editor:error:load:title')});
    const message = element('p', '', {
      text: error instanceof Error ? error.message : this.s('editor:error:request'),
    });
    const retry = button(
      this.s('editor:action:retry'),
      'quizgeist-button quizgeist-button--primary',
      () => {
        this.renderLoading();
        void this.loadBootstrap();
      },
    );
    box.append(heading, message, retry);
    this.root.append(box);
  }

  private render(): void {
    this.root.replaceChildren();
    this.saveStatusElement = null;
    this.statusLiveElement = element('div', 'quizgeist-visually-hidden', {
      role: 'status',
      'aria-live': 'polite',
      'aria-atomic': 'true',
    });
    this.toastRegion = element('div', 'quizgeist-toast-region', {
      'aria-live': 'polite',
      'aria-atomic': 'true',
    });
    this.root.append(this.statusLiveElement, this.toastRegion);
    if (this.view === 'templates') {
      this.renderTemplateLibrary();
    } else {
      this.renderEditor();
    }
  }

  private renderEditor(): void {
    const frame = element('section', 'quizgeist-editor');
    frame.dataset.view = 'editor';
    const customBackground = this.activity.background[0];
    if (customBackground) {
      frame.append(element('img', 'quizgeist-editor__custom-background', {
        src: customBackground.url,
        alt: '',
        'aria-hidden': 'true',
      }));
      frame.dataset.hasCustomBackground = 'true';
    }

    frame.append(this.renderToolbar(), this.renderBacktrackBar());
    const body = element('div', 'quizgeist-editor__body');
    body.append(this.renderQuestionSidebar(), this.renderDetail());
    frame.append(body);
    if (this.workshop !== null && this.workshop.canCurate) {
      frame.append(renderCuratorList(
        this.workshop,
        (key, fallback, values) => this.workshopText(key, fallback, values),
        (submissionId, state, note) => void this.decideSubmission(
          submissionId,
          state,
          note,
        ),
        null,
      ));
    }
    const nextStep = this.renderNextStep();
    if (nextStep) {
      frame.append(nextStep);
    }
    this.root.append(frame);
    this.updateSaveStatusElement();
  }

  private renderNextStep(): HTMLElement | null {
    const total = this.questions.length;
    if (total === 0) {
      return null;
    }

    const heading = element('h2', 'quizgeist-editor-nextstep__title', {
      text: this.s('editor:nextstep:title', 'Fertig? Dann kann es losgehen.'),
    });
    heading.id = `quizgeist-editor-nextstep-${crypto.randomUUID()}`;
    const section = element('section', 'quizgeist-editor-nextstep', {
      'aria-labelledby': heading.id,
    });
    const actions = element('div', 'quizgeist-editor-nextstep__actions');

    if (this.config.capabilities?.host === true) {
      const status = element('p', 'quizgeist-editor-nextstep__status');
      const playable = countPlayableQuestions(this.questions, this.liveSupportedTypes);
      const readinessKey = playable === total
        ? 'host:readiness:ready'
        : playable === 0
          ? 'host:readiness:none'
          : 'host:readiness:partial';
      status.textContent = this.workshopText(
        readinessKey,
        playable === total
          ? '{$a} Fragen sind spielbereit.'
          : playable === 0
            ? 'Es gibt noch keine spielbereite Frage. Eine Live-Runde startet erst, '
              + 'wenn mindestens eine Frage fertig ist.'
            : '{$a->playable} von {$a->total} Fragen sind spielbereit.',
        {a: playable, playable, total},
      );

      actions.append(element('a', 'quizgeist-button quizgeist-button--primary', {
        href: this.config.hostUrl,
        text: this.s('editor:nextstep:host', 'Live-Session starten'),
      }));

      if (playable < total) {
        const firstUnplayable = this.questions.find((question) => (
          !isPlayableQuestion(question, this.liveSupportedTypes)
        ));
        if (!firstUnplayable) {
          section.append(heading, status, actions);
          return section;
        }
        const jump = element('a', 'quizgeist-button quizgeist-button--secondary', {
          href: '#',
          text: this.s('editor:nextstep:jump', 'Zur ersten unvollständigen Frage'),
        });
        jump.addEventListener('click', (event) => {
          event.preventDefault();
          this.jumpToQuestionValidation(firstUnplayable);
        });
        actions.append(jump);
      }
      section.append(heading, status, actions);
    } else if (
      this.config.capabilities?.manage === true
      && this.config.features?.selfstudy?.installed === true
    ) {
      const assignmentUrl = new URL(window.location.href);
      assignmentUrl.searchParams.set('view', 'assignments');
      actions.append(element('a', 'quizgeist-button quizgeist-button--primary', {
        href: assignmentUrl.toString(),
        text: this.s('editor:nextstep:assignment', 'Zuweisung anlegen'),
      }));
      section.append(heading, actions);
    } else {
      return null;
    }

    return section;
  }

  private jumpToQuestionValidation(question: EditorQuestion): void {
    this.selectedQuestionId = question.id;
    this.render();
    window.requestAnimationFrame(() => {
      const target = this.root.querySelector<HTMLElement>(
        `.quizgeist-question-form[data-question-id="${question.id}"] `
        + '.quizgeist-validation-summary',
      ) || this.root.querySelector<HTMLElement>(
        `.quizgeist-question-item[data-question-id="${question.id}"] `
        + '.quizgeist-question-item__readiness',
      );
      target?.focus();
    });
  }

  /**
   * Load the curation queue of the question workshop.
   *
   * [P11-E2]: `workshop_list` is an action of the self-study addon. Without
   * that addon the dispatcher does not know the action, so the request can
   * only ever end as a 404 — a console error and a red line in the network
   * trace of a base installation that has done nothing wrong. The fetch
   * therefore does not START; it is not started and then forgiven. A caught
   * exception is still a failed request, and hiding it in a `catch` would
   * merely make the noise harder to find.
   *
   * The remaining `catch` covers the honest failures of an INSTALLED addon
   * (offline, missing permission): those are situations in which asking was
   * the right thing to do.
   */
  private async loadWorkshop(signal?: AbortSignal): Promise<void> {
    this.workshop = null;
    if (this.config.features?.selfstudy?.installed !== true) {
      return;
    }
    try {
      this.workshop = normaliseWorkshopView(
        await this.api.post<unknown>('workshop_list', {}, signal),
      );
    } catch (_error) {
      this.workshop = null;
    }
  }

  /**
   * Decide one submission and reload the editor.
   *
   * The refusal of the server is shown verbatim: releasing additionally
   * requires mod/quizgeist:manage, and a broken question is refused even
   * then. The client never pre-empts that judgement.
   */
  private async decideSubmission(
    submissionId: number,
    state: CurationDecision,
    note: string,
  ): Promise<void> {
    try {
      const result = await this.api.post<unknown>('workshop_curate', {
        note,
        state,
        workshopId: submissionId,
      });
      const errors = readFieldErrors(result);
      if (errors.length > 0) {
        this.announce(workshopErrorMessage(
          errors[0],
          (key, fallback, values) => this.workshopText(key, fallback, values),
        ));
        return;
      }
      await this.loadWorkshop();
      await this.loadBootstrap(true);
    } catch (error) {
      this.announce(error instanceof Error
        ? error.message
        : this.s('editor:error:request'));
    }
  }

  private renderToolbar(): HTMLElement {
    const toolbar = element('header', 'quizgeist-editor-toolbar');
    const brand = element('div', 'quizgeist-editor-brand');
    const logo = this.activity.logo[0];
    const brandImage = element('img', 'quizgeist-editor-brand__icon', {
      src: logo?.url || this.config.brandIconUrl,
      alt: this.s(logo ? 'editor:logo:schoolalt' : 'editor:logo:alt'),
    });
    brand.append(brandImage);

    const titleInput = element('input', 'quizgeist-editor-title', {
      type: 'text',
      value: this.activity.name,
      maxlength: 255,
      'aria-label': this.s('editor:field:quiztitle'),
    });
    titleInput.addEventListener('input', () => {
      this.activity.name = titleInput.value;
      this.markActivityDirty();
    });
    brand.append(titleInput);
    toolbar.append(brand);

    const controls = element('div', 'quizgeist-editor-toolbar__controls');
    const theme = this.themeSelect();
    theme.setAttribute('aria-label', this.s('editor:field:theme'));
    controls.append(theme);
    if (this.activity.theme === 'jahreszeiten') {
      const season = this.seasonSelect();
      season.setAttribute('aria-label', this.s('editor:field:season'));
      controls.append(season);
    }

    this.saveStatusElement = element('div', 'quizgeist-save-status', {
      role: 'status',
      'aria-live': 'polite',
    });
    controls.append(this.saveStatusElement);
    const appearance = button(
      this.s('editor:action:appearance'),
      'quizgeist-button quizgeist-button--secondary',
      () => this.openAppearanceDialog(),
    );
    appearance.dataset.action = 'appearance';
    const preview = button(
      this.s('editor:action:preview'),
      'quizgeist-button quizgeist-button--secondary',
      () => this.openPreview(),
    );
    preview.dataset.action = 'preview';
    if (this.aiConfig.installed) {
      const aiWorkshop = button(
        this.s('editor:action:aiworkshop'),
        'quizgeist-button quizgeist-button--secondary',
        () => void this.openAiWorkshop(),
      );
      aiWorkshop.dataset.action = 'ai-workshop';
      aiWorkshop.disabled = !this.aiConfig.available;
      if (!this.aiConfig.available) {
        aiWorkshop.title = this.aiLabel(
          'editor:ai:unavailable',
          'Die KI-Werkstatt ist derzeit nicht verfügbar.',
        );
      }
      controls.append(aiWorkshop);
    }
    const templates = button(
      this.s('editor:action:templates'),
      'quizgeist-button quizgeist-button--secondary',
      () => {
        this.view = 'templates';
        this.render();
      },
    );
    templates.dataset.action = 'templates';
    const kahootImport = button(
      this.s('editor:action:kahootimport'),
      'quizgeist-button quizgeist-button--secondary',
      () => this.openKahootImport(),
    );
    kahootImport.dataset.action = 'kahoot-import';
    kahootImport.disabled = this.config.kahootImportUrl === '';
    const publish = button(
      this.s('editor:action:publish'),
      'quizgeist-button quizgeist-button--secondary',
      () => this.openPublishDialog(),
    );
    publish.dataset.action = 'publish-template';
    controls.append(appearance, preview, templates, kahootImport, publish);
    toolbar.append(controls);
    return toolbar;
  }

  private renderBacktrackBar(): HTMLElement {
    const bar = element('section', 'quizgeist-backtrack-bar');
    const content = element('div');
    const title = element('h2', 'quizgeist-backtrack-bar__title', {
      text: this.s('editor:backtrack:title'),
    });
    const description = element('p', 'quizgeist-backtrack-bar__description', {
      text: this.s('editor:backtrack:description'),
    });
    content.append(title, description);
    const label = element('label', 'quizgeist-switch quizgeist-switch--compact');
    const input = element('input', 'quizgeist-switch__input', {
      type: 'checkbox',
      checked: this.activity.allowbacktrack,
    });
    input.addEventListener('change', () => {
      this.activity.allowbacktrack = input.checked;
      this.markActivityDirty();
    });
    appendChildren(
      label,
      input,
      element('span', 'quizgeist-switch__track', {'aria-hidden': 'true'}),
      element('span', 'quizgeist-switch__label', {
        text: this.s('editor:backtrack:toggle'),
      }),
    );
    bar.append(content, label);
    return bar;
  }

  private renderQuestionSidebar(): HTMLElement {
    const sidebar = element('aside', 'quizgeist-question-sidebar', {
      'aria-label': this.s('editor:questions:label'),
    });
    const header = element('div', 'quizgeist-question-sidebar__header');
    const heading = element('h2', 'quizgeist-question-sidebar__title', {
      text: this.s('editor:questions:count').replace('{$a}', String(this.questions.length)),
    });
    const add = button(
      this.s('editor:action:addquestion'),
      'quizgeist-button quizgeist-button--primary quizgeist-button--square',
      () => this.openQuestionPalette(),
    );
    add.setAttribute('aria-label', this.s('editor:action:addquestion'));
    header.append(heading, add);
    sidebar.append(header);

    if (this.questions.length === 0) {
      sidebar.append(this.renderEmptyState());
      return sidebar;
    }

    sidebar.append(this.renderReleaseControls());

    const list = element('ol', 'quizgeist-question-list', {
      role: 'listbox',
      'aria-label': this.s('editor:questions:label'),
    });
    this.questions.forEach((question, index) => {
      list.append(this.renderQuestionListItem(question, index));
    });
    sidebar.append(list);
    const addWide = button(
      this.s('editor:action:addquestion'),
      'quizgeist-add-question',
      () => this.openQuestionPalette(),
    );
    sidebar.append(addWide);
    return sidebar;
  }

  private renderQuestionListItem(question: EditorQuestion, index: number): HTMLLIElement {
    const selected = question.id === this.selectedQuestionId;
    const item = element('li', 'quizgeist-question-item', {
      role: 'option',
      'aria-selected': selected ? 'true' : 'false',
      'aria-posinset': index + 1,
      'aria-setsize': this.questions.length,
      tabindex: selected ? 0 : -1,
    });
    item.dataset.questionId = String(question.id);
    item.draggable = true;
    if (selected) {
      item.classList.add('is-active');
      item.setAttribute('aria-current', 'true');
    }
    if (this.questionHasErrors(question)) {
      item.classList.add('has-errors');
    }
    const select = button('', 'quizgeist-question-item__select', () => {
      this.selectedQuestionId = question.id;
      this.render();
    });
    const indexNode = element('span', 'quizgeist-question-item__index', {
      text: String(index + 1),
    });
    const icon = this.questionTypeIcon(question.qtype);
    const text = element('span', 'quizgeist-question-item__text');
    const title = element('span', 'quizgeist-question-item__title', {
      text: this.questionTitle(question),
    });
    const metadata = this.renderQuestionMetadata(question);
    text.append(title, metadata, this.renderQuestionStatusBadge(question));
    const readiness = this.renderQuestionReadiness(question);
    if (readiness) {
      text.append(readiness);
    }
    select.append(indexNode, icon, text);
    if (this.questionHasErrors(question)) {
      select.append(element('span', 'quizgeist-question-item__error', {
        title: this.s('editor:validation:item'),
        'aria-label': this.s('editor:validation:item'),
      }));
    }
    const dragHandle = element('span', 'quizgeist-question-item__drag-handle', {
      title: this.s('editor:action:drag'),
      'aria-hidden': 'true',
    });
    item.append(dragHandle, select);
    const release = this.renderQuestionReleaseButton(question);
    if (release) {
      item.append(release);
    }

    const actions = element('details', 'quizgeist-question-actions');
    const summary = element('summary', 'quizgeist-question-actions__summary', {
      text: this.s('editor:action:questionmenu'),
      'aria-label': this.s('editor:action:questionmenu'),
    });
    const menu = element('div', 'quizgeist-question-actions__menu');
    const up = button(
      this.s('editor:action:up'),
      'quizgeist-question-actions__button',
      () => this.moveQuestion(question.id, -1),
    );
    up.disabled = index === 0;
    const down = button(
      this.s('editor:action:down'),
      'quizgeist-question-actions__button',
      () => this.moveQuestion(question.id, 1),
    );
    down.disabled = index === this.questions.length - 1;
    menu.append(
      up,
      down,
      button(
        this.s('editor:action:duplicate'),
        'quizgeist-question-actions__button',
        () => void this.duplicateQuestion(question.id),
      ),
      button(
        this.s('editor:action:delete'),
        'quizgeist-question-actions__button quizgeist-question-actions__button--danger',
        () => void this.deleteQuestion(question.id),
      ),
    );
    actions.append(summary, menu);
    item.append(actions);

    item.addEventListener('dragstart', (event) => {
      this.draggedQuestionId = question.id;
      item.classList.add('is-dragging');
      event.dataTransfer?.setData('text/plain', String(question.id));
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
      }
    });
    item.addEventListener('dragend', () => {
      this.draggedQuestionId = null;
      item.classList.remove('is-dragging');
      this.root.querySelectorAll('.is-drag-target')
        .forEach((node) => node.classList.remove('is-drag-target'));
    });
    item.addEventListener('dragover', (event) => {
      if (this.draggedQuestionId === null || this.draggedQuestionId === question.id) {
        return;
      }
      event.preventDefault();
      item.classList.add('is-drag-target');
    });
    item.addEventListener('dragleave', () => item.classList.remove('is-drag-target'));
    item.addEventListener('drop', (event) => {
      event.preventDefault();
      item.classList.remove('is-drag-target');
      if (this.draggedQuestionId !== null) {
        this.reorderQuestion(this.draggedQuestionId, question.id);
      }
    });
    item.addEventListener('keydown', (event) => {
      if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) {
        return;
      }
      if (event.target !== item && event.target !== select) {
        return;
      }
      event.preventDefault();
      this.moveQuestion(question.id, event.key === 'ArrowUp' ? -1 : 1);
      window.requestAnimationFrame(() => {
        this.root.querySelector<HTMLElement>(
          `.quizgeist-question-item[data-question-id="${question.id}"]`,
        )?.focus();
      });
    });
    return item;
  }

  private renderQuestionStatusBadge(question: EditorQuestion): HTMLElement {
    const ready = question.status === 'ready';
    const status = this.s(
      ready ? 'editor:status:ready' : 'editor:status:draft',
      ready ? 'Fertig' : 'Entwurf',
    );
    const label = this.s('editor:status:label', 'Status: {$a}')
      .replace('{$a}', status);
    return element(
      'span',
      `quizgeist-question-item__status quizgeist-question-item__status--${ready ? 'ready' : 'draft'}`,
      {
        'aria-label': label,
        text: status,
      },
    );
  }

  private questionValidationMessages(question: EditorQuestion): string[] {
    return validationMessages(this.config.strings, question.validationErrors);
  }

  private questionReadinessReason(question: EditorQuestion): string | null {
    const validation = this.questionValidationMessages(question)[0];
    if (validation) {
      return validation;
    }
    if (question.status === 'media_pending') {
      return this.s(
        'editor:readiness:media',
        'Medien werden noch verarbeitet.',
      );
    }
    if (!this.liveSupportedTypeSet.has(question.qtype)) {
      return this.s(
        'editor:readiness:unsupported',
        'Dieser Fragetyp läuft nicht in der Live-Runde.',
      );
    }
    if (question.status !== 'ready') {
      return this.s('editor:readiness:notreleased', 'Noch nicht freigegeben.');
    }
    return null;
  }

  private isReleaseCandidate(question: EditorQuestion): boolean {
    return question.status !== 'ready'
      && question.status !== 'media_pending'
      && this.questionValidationMessages(question).length === 0;
  }

  private renderQuestionReadiness(question: EditorQuestion): HTMLElement | null {
    const reason = this.questionReadinessReason(question);
    if (reason === null) {
      return null;
    }
    return element('span', 'quizgeist-question-item__readiness', {
      tabindex: -1,
      text: reason,
    });
  }

  private renderQuestionReleaseButton(question: EditorQuestion): HTMLButtonElement | null {
    if (!this.isReleaseCandidate(question)) {
      return null;
    }
    const release = button(
      this.s('editor:action:release', 'Freigeben'),
      'quizgeist-button quizgeist-button--secondary quizgeist-question-item__release',
      (event) => {
        const target = event.currentTarget;
        if (target instanceof HTMLButtonElement) {
          void this.releaseQuestion(question.id, target);
        }
      },
    );
    release.setAttribute('aria-label', this.s('editor:action:release', 'Freigeben'));
    release.disabled = this.releaseAllRunning || this.releasingQuestionIds.has(question.id);
    return release;
  }

  private renderReleaseAllButton(): HTMLButtonElement {
    const releaseAll = button(
      this.s('editor:action:releaseall', 'Alle vollständigen Fragen freigeben'),
      'quizgeist-button quizgeist-button--secondary quizgeist-question-release-all',
      (event) => {
        const target = event.currentTarget;
        if (target instanceof HTMLButtonElement) {
          void this.releaseAllQuestions(target);
        }
      },
    );
    setButtonBusy(
      releaseAll,
      this.releaseAllRunning,
      this.releaseAllRunning
        ? this.s('editor:release:working', 'Wird freigegeben …')
        : this.s('editor:action:releaseall', 'Alle vollständigen Fragen freigeben'),
    );
    return releaseAll;
  }

  private renderReleaseControls(): HTMLElement {
    const controls = element('section', 'quizgeist-question-sidebar__release-controls');
    if (this.releaseAllRunning || this.questions.some((question) => (
      this.isReleaseCandidate(question)
    ))) {
      controls.append(this.renderReleaseAllButton());
    }
    controls.append(element('div', 'quizgeist-question-release-result', {
      role: 'status',
      'aria-live': 'polite',
      'aria-atomic': 'true',
      text: this.releaseResult,
    }));
    return controls;
  }

  private refreshReleaseControls(): void {
    const controls = this.root.querySelector<HTMLElement>(
      '.quizgeist-question-sidebar__release-controls',
    );
    if (!controls) {
      return;
    }
    const shouldShowReleaseAll = this.releaseAllRunning || this.questions.some((question) => (
      this.isReleaseCandidate(question)
    ));
    const existing = controls.querySelector<HTMLButtonElement>(
      '.quizgeist-question-release-all',
    );
    const result = controls.querySelector('.quizgeist-question-release-result');
    if (shouldShowReleaseAll && !existing) {
      controls.insertBefore(this.renderReleaseAllButton(), result);
    } else if (!shouldShowReleaseAll) {
      existing?.remove();
    } else if (existing) {
      setButtonBusy(
        existing,
        this.releaseAllRunning,
        this.releaseAllRunning
          ? this.s('editor:release:working', 'Wird freigegeben …')
          : this.s('editor:action:releaseall', 'Alle vollständigen Fragen freigeben'),
      );
    }
  }

  private setReleaseResult(message: string): void {
    this.releaseResult = message;
    this.root.querySelector<HTMLElement>('.quizgeist-question-release-result')
      ?.replaceChildren(document.createTextNode(message));
  }

  private renderEmptyState(): HTMLElement {
    const empty = element('div', 'quizgeist-editor-empty');
    const brand = element('img', 'quizgeist-editor-empty__mark', {
      src: this.config.brandIconUrl,
      alt: '',
      'aria-hidden': 'true',
    });
    const heading = element('h3', '', {text: this.s('editor:empty:title')});
    const text = element('p', '', {text: this.s('editor:empty:description')});
    const actions = element('div', 'quizgeist-editor-empty__actions');
    const manual = button(
      this.s('editor:empty:manual'),
      'quizgeist-empty-action',
      () => this.openQuestionPalette(),
    );
    if (this.aiConfig.installed) {
      const ai = button(
        this.s('editor:empty:ai'),
        'quizgeist-empty-action',
        () => void this.openAiWorkshop(),
      );
      ai.disabled = !this.aiConfig.available;
      if (!this.aiConfig.available) {
        ai.title = this.aiLabel(
          'editor:ai:unavailable',
          'Die KI-Werkstatt ist derzeit nicht verfügbar.',
        );
      }
      actions.append(ai);
    }
    const templates = button(
      this.s('editor:empty:templates'),
      'quizgeist-empty-action',
      () => {
        this.view = 'templates';
        this.render();
      },
    );
    const kahoot = button(
      this.s('editor:empty:kahoot'),
      'quizgeist-empty-action',
      () => this.openKahootImport(),
    );
    kahoot.disabled = this.config.kahootImportUrl === '';
    actions.prepend(manual);
    actions.append(templates, kahoot);
    empty.append(brand, heading, text, actions);
    return empty;
  }

  private openKahootImport(): void {
    if (this.config.kahootImportUrl === '') {
      return;
    }
    try {
      const url = new URL(this.config.kahootImportUrl, window.location.href);
      if (url.origin === window.location.origin) {
        window.location.assign(url.toString());
      }
    } catch (_error) {
      this.showToast(this.s('editor:error:config'), true);
    }
  }

  private renderDetail(): HTMLElement {
    const detail = element('section', 'quizgeist-question-detail');
    const validationOverview = this.renderValidationOverview();
    if (validationOverview) {
      detail.append(validationOverview);
    }
    const question = this.selectedQuestion();
    if (!question) {
      detail.append(this.renderEmptyState());
      return detail;
    }
    detail.append(
      createTtsControl(this.tts, this.ttsText(question), this.config),
      this.formRenderer.render(question),
    );
    if (this.tagStore.isLoaded()) {
      // U1: the assignment hangs on the question ROOT, so it survives every
      // further edit of this question.
      detail.append(renderTagPicker(
        this.tagStore,
        this.config.strings,
        question.id,
        question.rootid || question.id,
        (message) => this.announce(message),
      ));
    }
    return detail;
  }

  /**
   * Build the dictation control, or nothing at all (F9).
   *
   * Null whenever this browser cannot record or the clip channel is closed.
   * The recording is transcribed and DELETED in the same server request, so
   * this control never offers playback: by the time it has the text, there is
   * nothing left to play.
   */
  private dictationControl(onText: (text: string) => void): HTMLElement | null {
    const clips = this.config.clips;
    if (clips === undefined || !clips.canRecord || !clips.canTranscribe
        || !DictationControl.available()) {
      return null;
    }
    const control = new DictationControl(
      this.config.strings,
      {
        uploadUrl: clips.uploadUrl,
        sesskey: this.config.sesskey,
        cmid: this.config.cmid,
        language: clips.language,
        maxBytes: clips.maxBytes,
        maxSeconds: clips.maxSeconds,
      },
      {
        transcribe: (clipId) => this.api.post<{text: string}>('dictation', {clipId}),
        onText,
      },
    );
    return control.root;
  }

  private aiLabel(key: string, fallback: string): string {
    return editorString(this.config.strings, key, fallback);
  }

  /**
   * Read the AI report of the editor bootstrap.
   *
   * [P11-E2]: the addon is reported to the editor twice and independently —
   * once by view.php in `features.ai`, once by `editor_bootstrap` in its own
   * block. Both have to say yes. Every AI action of this file (`ai_generate`,
   * `ai_apply`, `ai_discard`, `ai_explanation_*`, `dictation`) hangs on
   * `installed`/`available`, and one report alone must not be able to open a
   * path to an action the dispatcher does not know.
   */
  private normalizeAiBootstrap(raw: unknown): AiBootstrap {
    const source = this.record(raw);
    const addonReported = this.config.features?.ai?.installed === true;
    const booleanValue = (value: unknown): boolean => (
      value === true || value === 1 || value === '1' || value === 'true'
    );
    const stringArray = (value: unknown): string[] => (
      Array.isArray(value)
        ? value.filter((entry): entry is string => (
          typeof entry === 'string' && entry.trim() !== ''
        )).map((entry) => entry.trim())
        : []
    );
    const configuredFormats = stringArray(source.formats)
      .filter((format): format is AiFormat => (
        AI_FORMATS.includes(format as AiFormat)
      ));
    const maximumQuestions = Number(source.maxQuestions || 50);
    const maximumPdfPages = Number(source.maxPdfPages || 150);
    const gatewayAvailable = booleanValue(source.gatewayAvailable);
    const fallbackAvailable = booleanValue(source.fallbackAvailable);
    const sourcePickerUrl = typeof source.aiSourcePickerUrl === 'string'
      ? source.aiSourcePickerUrl
      : typeof source.sourcePickerUrl === 'string'
        ? source.sourcePickerUrl
        : '';
    return {
      acceptedDocuments: stringArray(source.acceptedDocuments),
      acceptedVision: stringArray(source.acceptedVision),
      aiSourcePickerUrl: sourcePickerUrl,
      available: addonReported && booleanValue(source.available),
      entitlementStatus: typeof source.entitlementStatus === 'string'
        ? source.entitlementStatus
        : 'read_only',
      fallbackAvailable,
      formats: configuredFormats.length > 0 ? configuredFormats : [...AI_FORMATS],
      gatewayAvailable,
      installed: addonReported && booleanValue(source.installed),
      managedServerInstalled: booleanValue(source.managedServerInstalled),
      maxPdfPages: Number.isInteger(maximumPdfPages) && maximumPdfPages > 0
        ? Math.min(maximumPdfPages, 150)
        : 150,
      maxQuestions: Number.isInteger(maximumQuestions) && maximumQuestions > 0
        ? Math.min(maximumQuestions, 100)
        : 50,
      notice: typeof source.notice === 'string' ? source.notice : '',
      outboundFetchAllowed: booleanValue(source.outboundFetchAllowed),
      visionAvailable: booleanValue(source.visionAvailable),
    };
  }

  private async openAiWorkshop(): Promise<void> {
    if (!this.aiConfig.available) {
      this.showToast(this.aiLabel(
        'editor:ai:unavailable',
        'Die KI-Werkstatt ist derzeit nicht verfügbar.',
      ), true);
      return;
    }
    if (this.aiDialog?.open) {
      this.aiDialog.focus();
      return;
    }

    this.aiDraft = null;
    this.aiSourceSelection = null;
    const parts = openDialog(
      this.s('editor:action:aiworkshop'),
      this.s('editor:action:close'),
      'quizgeist-ai-dialog',
    );
    this.aiDialog = parts.dialog;
    let applied = false;
    let applying = false;
    let generating = false;
    let submittedSourceDraftId = '';
    const shell = element('div', 'quizgeist-ai-workshop');
    const intro = element('p', 'quizgeist-dialog__intro', {
      text: this.aiLabel(
        'editor:ai:intro',
        'Erzeugen Sie einen prüfbaren Entwurf. Erst Ihre Bestätigung übernimmt '
          + 'ausgewählte Inhalte in den Editor.',
      ),
    });
    const availability = element('div', 'quizgeist-ai-availability', {
      role: 'status',
    });
    availability.append(
      element('span', 'quizgeist-ai-chip quizgeist-ai-chip--draft', {
        text: this.aiLabel('editor:ai:draft', 'ENTWURF'),
      }),
      element('span', `quizgeist-ai-chip ${
        this.aiConfig.gatewayAvailable
          ? 'quizgeist-ai-chip--gateway'
          : 'quizgeist-ai-chip--fallback'
      }`, {
        text: this.aiConfig.gatewayAvailable
          ? this.aiLabel('editor:ai:gatewayavailable', 'Lokales KI-Gateway konfiguriert')
          : this.aiLabel(
            'editor:ai:fallbackavailable',
            'Regelbasierter Fallback aktiv',
          ),
      }),
    );
    shell.append(intro, availability);
    if (this.aiConfig.notice !== '') {
      shell.append(element('p', 'quizgeist-ai-warning', {
        role: 'note',
        text: this.aiConfig.notice,
      }));
    }

    const form = element('form', 'quizgeist-ai-form');
    form.addEventListener('submit', (event) => event.preventDefault());
    const sourceFieldset = element('fieldset', 'quizgeist-ai-source-fieldset');
    sourceFieldset.append(element('legend', 'quizgeist-ai-form__legend', {
      text: this.aiLabel('editor:ai:source', 'Quelle'),
    }));
    const sourceGrid = element('div', 'quizgeist-ai-source-grid');
    const sources = AI_SOURCES.filter((source) => (
      (source !== 'handwriting' || this.aiConfig.visionAvailable)
      && (
        this.aiConfig.outboundFetchAllowed
        || (source !== 'url' && source !== 'wikipedia')
      )
    ));
    sources.forEach((source, index) => {
      const input = element('input', 'quizgeist-ai-source-card__input', {
        type: 'radio',
        name: 'quizgeist-ai-source',
        value: source,
        checked: index === 0,
      });
      const card = element('label', 'quizgeist-ai-source-card');
      card.append(
        input,
        element('span', 'quizgeist-ai-source-card__title', {
          text: this.aiSourceLabel(source),
        }),
        element('span', 'quizgeist-ai-source-card__description', {
          text: this.aiSourceDescription(source),
        }),
      );
      sourceGrid.append(card);
    });
    sourceFieldset.append(sourceGrid);
    if (!this.aiConfig.outboundFetchAllowed) {
      sourceFieldset.append(element('p', 'quizgeist-ai-warning', {
        role: 'note',
        text: this.aiLabel(
          'editor:ai:outbounddisabled',
          'Der Import einer frei eingegebenen URL und von de.wikipedia.org ist '
            + 'durch die Website-Administration deaktiviert.',
        ),
      }));
    }
    form.append(sourceFieldset);

    const fields = element('div', 'quizgeist-ai-form__fields');
    const topicInput = element('input', 'quizgeist-input', {
      type: 'text',
      maxlength: 2000,
      required: true,
    });
    const topicField = labelledField(
      this.aiLabel('editor:ai:topic', 'Thema'),
      topicInput,
      this.aiLabel(
        'editor:ai:topic:hint',
        'Formulieren Sie Thema, Lernziel oder gewünschten Schwerpunkt.',
      ),
    );
    const topicLabel = topicField.querySelector('label');
    // P11/F9: Diktat fuer das Themenfeld. Es erscheint nur, wenn dieses Geraet
    // aufnehmen kann UND der Kurzclip-Kanal offensteht — ein Knopf, der nichts
    // tun kann, ist schlechter als kein Knopf (2.6).
    const dictationHost = this.dictationControl((dictated) => {
      const field = topicInput as HTMLInputElement;
      const current = field.value.trim();
      field.value = current === '' ? dictated : `${current} ${dictated}`;
      field.dispatchEvent(new Event('input', {bubbles: true}));
    });
    if (dictationHost !== null) {
      topicField.append(dictationHost);
    }
    const gradeInput = element('input', 'quizgeist-input', {
      type: 'text',
      maxlength: 80,
      required: true,
      value: '10',
    });
    const countInput = element('input', 'quizgeist-input', {
      type: 'number',
      min: 1,
      max: this.aiConfig.maxQuestions,
      step: 1,
      value: Math.min(10, this.aiConfig.maxQuestions),
      required: true,
    });
    const formatSelect = element('select', 'quizgeist-select');
    this.aiConfig.formats.forEach((format) => {
      formatSelect.append(element('option', '', {
        value: format,
        text: this.aiFormatLabel(format),
      }));
    });
    const gradeField = labelledField(
      this.aiLabel('editor:ai:grade', 'Jahrgangsstufe'),
      gradeInput,
    );
    const countField = labelledField(
      this.aiLabel('editor:ai:count', 'Anzahl'),
      countInput,
      this.aiLabel(
        'editor:ai:count:hint',
        `Maximal ${this.aiConfig.maxQuestions} Inhalte pro Entwurf.`,
      ),
    );
    const formatField = labelledField(
      this.aiLabel('editor:ai:format', 'Format'),
      formatSelect,
    );
    fields.append(topicField, gradeField, countField, formatField);
    form.append(fields);

    const filePanel = element('section', 'quizgeist-ai-file-panel', {hidden: true});
    const fileCopy = element('div', 'quizgeist-ai-file-panel__copy');
    fileCopy.append(
      element('h3', '', {
        text: this.aiLabel('editor:ai:file:title', 'Quelldatei'),
      }),
      element('p', '', {
        text: this.aiLabel(
          'editor:ai:file:hint',
          `PDFs werden serverseitig bis ${this.aiConfig.maxPdfPages} Seiten verarbeitet.`,
        ),
      }),
    );
    const selectedFile = element('p', 'quizgeist-ai-file-panel__selection', {
      role: 'status',
      'aria-live': 'polite',
      text: this.aiLabel('editor:ai:file:none', 'Noch keine Datei gewählt.'),
    });
    const chooseFile = button(
      this.aiLabel('editor:ai:file:choose', 'Datei auswählen'),
      'quizgeist-button quizgeist-button--secondary',
    );
    chooseFile.disabled = this.aiConfig.aiSourcePickerUrl === '';
    if (chooseFile.disabled) {
      chooseFile.title = this.s('editor:media:unavailable');
    }
    filePanel.append(fileCopy, selectedFile, chooseFile);
    form.append(filePanel);
    shell.append(form);

    const resultRegion = element('div', 'quizgeist-ai-results', {
      'aria-live': 'polite',
    });
    shell.append(resultRegion);
    parts.body.append(shell);

    const cancel = button(
      this.s('editor:action:cancel'),
      'quizgeist-button quizgeist-button--secondary',
      () => closeDialog(parts.dialog),
    );
    const generate = button(
      this.aiLabel('editor:ai:generate', 'Entwurf erzeugen'),
      'quizgeist-button quizgeist-button--secondary',
    );
    const apply = button(
      this.aiLabel(
        'editor:ai:apply',
        'Auswahl als Entwurf in den Editor übernehmen',
      ),
      'quizgeist-button quizgeist-button--primary',
    );
    apply.disabled = true;
    parts.footer.append(cancel, generate, apply);
    const setGenerationControls = (busy: boolean): void => {
      sourceFieldset.disabled = busy;
      topicInput.disabled = busy;
      gradeInput.disabled = busy;
      countInput.disabled = busy;
      formatSelect.disabled = busy;
      chooseFile.disabled = busy || this.aiConfig.aiSourcePickerUrl === '';
    };

    const preventCloseWhileApplying = (event: Event): void => {
      if (!applying) {
        return;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
    };
    parts.dialog.addEventListener('cancel', preventCloseWhileApplying, {
      capture: true,
    });
    parts.dialog.addEventListener('click', (event) => {
      if (event.target === parts.dialog) {
        preventCloseWhileApplying(event);
      }
    }, {capture: true});

    let selectedItemIds = new Set<string>();
    const selectedSource = (): AiSource => {
      const input = sourceGrid.querySelector<HTMLInputElement>(
        'input[name="quizgeist-ai-source"]:checked',
      );
      return AI_SOURCES.includes(input?.value as AiSource)
        ? input?.value as AiSource
        : 'topic';
    };
    const updateSelection = (
      selection: AiSourceSelection | null,
      discardPrevious = true,
    ): void => {
      const previous = this.aiSourceSelection;
      if (
        discardPrevious
        && previous
        && previous.draftItemId !== selection?.draftItemId
      ) {
        void this.discardAiSource(previous);
      }
      this.aiSourceSelection = selection;
      selectedFile.setAttribute('role', 'status');
      selectedFile.textContent = selection
        ? [
          selection.file.filename,
          selection.file.mimetype || '',
          selection.file.filesize
            ? this.formatFileSize(selection.file.filesize)
            : '',
        ].filter(Boolean).join(' · ')
        : this.aiLabel('editor:ai:file:none', 'Noch keine Datei gewählt.');
    };
    const updateSourceUi = (): void => {
      const source = selectedSource();
      const needsFile = FILE_AI_SOURCES.includes(source);
      const isSlideImport = source === 'slides';
      filePanel.hidden = !needsFile;
      gradeField.hidden = isSlideImport;
      countField.hidden = isSlideImport;
      formatField.hidden = isSlideImport;
      topicInput.required = !needsFile;
      topicInput.type = source === 'url' ? 'url' : 'text';
      topicInput.maxLength = source === 'url' ? 2048 : 255;
      if (topicLabel) {
        topicLabel.textContent = source === 'url'
          ? this.aiLabel('editor:ai:url', 'Webadresse')
          : source === 'wikipedia'
            ? this.aiLabel('editor:ai:wikipedia', 'Wikipedia-Thema oder Artikeltitel')
            : needsFile
              ? this.aiLabel('editor:ai:focus', 'Optionaler Schwerpunkt')
              : this.aiLabel('editor:ai:topic', 'Thema');
      }
      topicInput.placeholder = source === 'url'
        ? 'https://…'
        : source === 'wikipedia'
          ? this.aiLabel('editor:ai:wikipedia:placeholder', 'z. B. Gewaltenteilung')
          : '';
      if (this.aiSourceSelection?.purpose !== source) {
        updateSelection(null);
      }
    };
    sourceGrid.addEventListener('change', updateSourceUi);
    updateSourceUi();

    chooseFile.addEventListener('click', () => {
      this.openAiSourcePicker(selectedSource(), updateSelection);
    });

    generate.addEventListener('click', async() => {
      const source = selectedSource();
      if (!topicInput.reportValidity()
          || !gradeInput.reportValidity()
          || !countInput.reportValidity()) {
        return;
      }
      if (FILE_AI_SOURCES.includes(source)
          && this.aiSourceSelection?.purpose !== source) {
        selectedFile.setAttribute('role', 'alert');
        selectedFile.textContent = this.aiLabel(
          'editor:ai:file:required',
          'Bitte wählen Sie zuerst eine passende Quelldatei.',
        );
        chooseFile.focus();
        return;
      }
      const count = Number(countInput.value);
      if (!Number.isInteger(count) || count < 1 || count > this.aiConfig.maxQuestions) {
        countInput.setCustomValidity(this.aiLabel(
          'editor:ai:count:invalid',
          `Wählen Sie eine ganze Zahl zwischen 1 und ${this.aiConfig.maxQuestions}.`,
        ));
        countInput.reportValidity();
        countInput.setCustomValidity('');
        return;
      }
      const sourceSelection = this.aiSourceSelection?.purpose === source
        ? this.aiSourceSelection
        : null;

      this.aiGenerateController?.abort();
      const controller = new AbortController();
      this.aiGenerateController = controller;
      generating = true;
      setGenerationControls(true);
      setButtonBusy(
        generate,
        true,
        this.aiLabel('editor:ai:generating', 'Entwurf wird erzeugt …'),
      );
      apply.disabled = true;
      resultRegion.replaceChildren(element('div', 'quizgeist-ai-loading', {
        role: 'status',
        text: this.aiLabel(
          'editor:ai:generating:detail',
          'Quelle wird verarbeitet und anschließend sicher validiert.',
        ),
      }));
      try {
        await this.flushPendingSaves();
        if (this.aiDraft?.token) {
          await this.discardAiDraft(this.aiDraft.token);
          this.aiDraft = null;
        }
        const format = AI_FORMATS.includes(formatSelect.value as AiFormat)
          ? formatSelect.value as AiFormat
          : 'quiz';
        const topic = topicInput.value.trim();
        const payload: Record<string, unknown> = {
          source,
          format,
          topic,
          grade: gradeInput.value.trim(),
          questionCount: count,
        };
        if (source === 'url' || source === 'wikipedia') {
          payload.url = topic;
        }
        if (sourceSelection) {
          payload.draftItemId = sourceSelection.draftItemId;
          payload.sourceToken = sourceSelection.sourceToken;
        }
        submittedSourceDraftId = sourceSelection?.draftItemId || '';
        const response = await this.api.post<unknown>(
          'ai_generate',
          payload,
          controller.signal,
        );
        const draft = this.normalizeAiDraft(response);
        this.aiDraft = draft;
        selectedItemIds = new Set(draft.items.map((item) => item.itemId));
        const results = this.renderAiDraft(
          draft,
          selectedItemIds,
          () => {
            apply.disabled = selectedItemIds.size === 0;
          },
        );
        resultRegion.replaceChildren(results);
        apply.disabled = selectedItemIds.size === 0;
        results.focus();
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
          resultRegion.replaceChildren(element('div', 'quizgeist-ai-error', {
            role: 'alert',
            text: error instanceof Error
              ? error.message
              : this.s('editor:error:request'),
          }));
        }
      } finally {
        generating = false;
        if (
          sourceSelection
          && this.aiSourceSelection?.draftItemId === sourceSelection.draftItemId
        ) {
          // The server consumes every submitted source draft in its own
          // finally block, whether generation succeeds or falls back.
          updateSelection(null, false);
        }
        if (sourceSelection) {
          // This is deliberately idempotent with the server-side finally
          // cleanup. It also covers a network failure before the request ever
          // reaches Moodle.
          await this.discardAiSource(sourceSelection);
        }
        submittedSourceDraftId = '';
        if (this.aiGenerateController === controller) {
          this.aiGenerateController = null;
        }
        if (parts.dialog.open) {
          setGenerationControls(false);
          setButtonBusy(
            generate,
            false,
            this.aiLabel('editor:ai:generate', 'Entwurf erzeugen'),
          );
        }
      }
    });

    apply.addEventListener('click', async() => {
      const draft = this.aiDraft;
      if (!draft || selectedItemIds.size === 0) {
        return;
      }
      applying = true;
      parts.closeButton.disabled = true;
      cancel.disabled = true;
      setButtonBusy(
        apply,
        true,
        this.aiLabel('editor:ai:applying', 'Entwurf wird übernommen …'),
      );
      generate.disabled = true;
      try {
        await this.flushPendingSaves();
        await this.api.post('ai_apply', {
          token: draft.token,
          itemIds: [...selectedItemIds],
        });
        applied = true;
        this.aiDraft = null;
        applying = false;
        closeDialog(parts.dialog);
        this.renderLoading();
        await this.loadBootstrap(true);
        this.showToast(this.aiLabel(
          'editor:ai:applied',
          'Die Auswahl wurde als Entwurf in den Editor übernommen.',
        ));
      } catch (error) {
        applying = false;
        this.showToast(
          error instanceof Error ? error.message : this.s('editor:error:request'),
          true,
        );
        if (parts.dialog.open) {
          parts.closeButton.disabled = false;
          cancel.disabled = false;
          setButtonBusy(
            apply,
            false,
            this.aiLabel(
              'editor:ai:apply',
              'Auswahl als Entwurf in den Editor übernehmen',
            ),
          );
          generate.disabled = false;
        }
      }
    });

    parts.dialog.addEventListener('close', () => {
      this.aiGenerateController?.abort();
      this.aiGenerateController = null;
      this.tts.stop();
      if (this.aiSourceDialog?.open) {
        closeDialog(this.aiSourceDialog);
      }
      const token = !applied ? this.aiDraft?.token : null;
      const sourceSelection = this.aiSourceSelection;
      this.aiDraft = null;
      this.aiDialog = null;
      this.aiSourceSelection = null;
      this.aiSourceRequest = null;
      if (token) {
        void this.discardAiDraft(token);
      }
      if (
        sourceSelection
        && (!generating || sourceSelection.draftItemId !== submittedSourceDraftId)
      ) {
        void this.discardAiSource(sourceSelection);
      }
    }, {once: true});
  }

  private aiSourceLabel(source: AiSource): string {
    const labels: Record<AiSource, string> = {
      topic: 'Thema',
      pdf: 'PDF zu Quiz',
      pdf_questions: 'PDF-Fragen extrahieren',
      url: 'URL zu Quiz',
      wikipedia: 'Wikipedia',
      slides: 'PPTX/PDF zu Inhaltsfolien',
      handwriting: 'Handschrift-Scan',
    };
    return this.aiLabel(`editor:ai:source:${source}`, labels[source]);
  }

  private aiSourceDescription(source: AiSource): string {
    const descriptions: Record<AiSource, string> = {
      topic: 'Thema, Jahrgang und Anzahl vorgeben.',
      pdf: 'Aus einem PDF einen Quiz-Entwurf entwickeln.',
      pdf_questions: 'Vorhandene Fragen aus einem PDF erkennen.',
      url: 'Eine freigegebene Webseite als Quelle verwenden.',
      wikipedia: 'Ein Thema über Wikipedia erschließen.',
      slides: 'PDF- oder PPTX-Inhalte als Folien übernehmen.',
      handwriting: 'Handschrift nur mit erreichbarem Vision-Modell auswerten.',
    };
    return this.aiLabel(
      `editor:ai:source:${source}:description`,
      descriptions[source],
    );
  }

  private aiFormatLabel(format: AiFormat): string {
    const labels: Record<AiFormat, string> = {
      quiz: 'Quiz',
      truefalse: 'Richtig/Falsch',
      micro_lesson: 'Mikro-Lektion',
      vocabulary: 'Wortschatzüberprüfung',
      presentation: 'Präsentation',
      practice_test: 'Übungstest',
      step_by_step: 'Schritt-für-Schritt-Lösungsweg',
    };
    return this.aiLabel(`editor:ai:format:${format}`, labels[format]);
  }

  private openAiSourcePicker(
    purpose: AiSource,
    onSelected: (selection: AiSourceSelection | null) => void,
  ): void {
    if (!FILE_AI_SOURCES.includes(purpose)
        || (purpose === 'handwriting' && !this.aiConfig.visionAvailable)) {
      return;
    }
    let sourceUrl: URL;
    try {
      sourceUrl = new URL(this.aiConfig.aiSourcePickerUrl, window.location.href);
    } catch (_error) {
      this.showToast(this.s('editor:media:unavailable'), true);
      return;
    }
    if (sourceUrl.origin !== window.location.origin) {
      this.showToast(this.s('editor:media:unavailable'), true);
      return;
    }
    if (this.aiSourceDialog?.open) {
      closeDialog(this.aiSourceDialog);
    }
    sourceUrl.searchParams.set('id', String(this.config.cmid));
    sourceUrl.searchParams.set('purpose', purpose);
    const parts = openDialog(
      this.aiLabel('editor:ai:file:title', 'Quelldatei'),
      this.s('editor:action:close'),
      'quizgeist-ai-source-dialog',
    );
    this.aiSourceDialog = parts.dialog;
    const iframe = element('iframe', 'quizgeist-ai-source-dialog__frame', {
      src: sourceUrl.toString(),
      title: this.aiLabel(
        'editor:ai:file:iframe',
        'Quelldatei für die KI-Werkstatt auswählen',
      ),
      loading: 'eager',
    });
    const request: AiSourceRequest = {
      draftItemId: '',
      purpose,
      sourceToken: '',
      onSelected: (selection) => onSelected(selection),
      sourceWindow: iframe.contentWindow,
    };
    this.aiSourceRequest = request;
    parts.body.append(iframe);
    request.sourceWindow = iframe.contentWindow;
    parts.dialog.addEventListener('close', () => {
      const draftItemId = request.draftItemId;
      const sourceToken = request.sourceToken;
      request.draftItemId = '';
      request.sourceToken = '';
      if (draftItemId !== '' && sourceToken !== '') {
        void this.discardAiSource({
          purpose,
          sourceToken,
        });
      }
      if (this.aiSourceDialog === parts.dialog) {
        this.aiSourceDialog = null;
      }
      if (this.aiSourceRequest === request) {
        this.aiSourceRequest = null;
      }
    }, {once: true});
  }

  private handleAiSourceReady(
    message: unknown,
    messageSource: MessageEventSource | null,
  ): void {
    const pending = this.aiSourceRequest;
    const payload = this.record(message);
    const draftItemId = String(payload.draftItemId ?? payload.draftitemid ?? '');
    const sourceToken = typeof payload.sourceToken === 'string'
      ? payload.sourceToken
      : '';
    if (
      !pending
      || messageSource !== pending.sourceWindow
      || payload.purpose !== pending.purpose
      || !/^[1-9][0-9]*$/.test(draftItemId)
      || !/^[a-f0-9]{64}$/.test(sourceToken)
    ) {
      return;
    }
    pending.draftItemId = draftItemId;
    pending.sourceToken = sourceToken;
  }

  private handleAiSourceSelected(
    message: unknown,
    messageSource: MessageEventSource | null,
  ): void {
    const pending = this.aiSourceRequest;
    if (!pending || messageSource !== pending.sourceWindow) {
      return;
    }
    const payload = this.record(message);
    const draftItemId = String(payload.draftItemId ?? payload.draftitemid ?? '');
    const sourceToken = typeof payload.sourceToken === 'string'
      ? payload.sourceToken
      : '';
    const purpose = typeof payload.purpose === 'string'
      && AI_SOURCES.includes(payload.purpose as AiSource)
      ? payload.purpose as AiSource
      : null;
    const file = this.record(payload.file);
    const filename = typeof file.filename === 'string' ? file.filename.trim() : '';
    if (
      !/^[1-9][0-9]*$/.test(draftItemId)
      || !/^[a-f0-9]{64}$/.test(sourceToken)
      || purpose !== pending.purpose
      || (pending.sourceToken !== '' && pending.sourceToken !== sourceToken)
      || filename === ''
    ) {
      return;
    }
    const filesize = Number(file.filesize || 0);
    const selection: AiSourceSelection = {
      draftItemId,
      purpose,
      file: {
        filename,
        ...(typeof file.mimetype === 'string' && file.mimetype !== ''
          ? {mimetype: file.mimetype}
          : {}),
        ...(Number.isFinite(filesize) && filesize > 0 ? {filesize} : {}),
      },
      sourceToken,
    };
    pending.draftItemId = '';
    pending.sourceToken = '';
    pending.onSelected(selection);
    if (this.aiSourceDialog?.open) {
      closeDialog(this.aiSourceDialog);
    }
  }

  private handleAiSourceCancelled(
    message: unknown,
    messageSource: MessageEventSource | null,
  ): void {
    const pending = this.aiSourceRequest;
    const payload = this.record(message);
    const sourceToken = typeof payload.sourceToken === 'string'
      ? payload.sourceToken
      : '';
    if (
      !pending
      || messageSource !== pending.sourceWindow
      || payload.purpose !== pending.purpose
      || !/^[a-f0-9]{64}$/.test(sourceToken)
      || (pending.sourceToken !== '' && pending.sourceToken !== sourceToken)
    ) {
      return;
    }
    // The picker deletes its exact user draft before posting cancellation.
    pending.draftItemId = '';
    pending.sourceToken = '';
    if (this.aiSourceDialog?.open) {
      closeDialog(this.aiSourceDialog);
    }
  }

  private normalizeAiDraft(raw: unknown): AiDraft {
    const envelope = this.record(raw);
    const source = this.record(envelope.draft || raw);
    const token = typeof source.token === 'string' ? source.token.trim() : '';
    const status = typeof source.status === 'string' ? source.status : '';
    const kind = typeof source.kind === 'string'
      && AI_SOURCES.includes(source.kind as AiSource)
      ? source.kind as AiSource
      : null;
    const format = typeof source.format === 'string'
      && AI_FORMATS.includes(source.format as AiFormat)
      ? source.format as AiFormat
      : null;
    if (
      !/^[a-f0-9]{64}$/.test(token)
      || status !== 'draft'
      || !kind
      || !format
      || !Array.isArray(source.items)
    ) {
      throw new Error(this.s('editor:error:response'));
    }
    const items = source.items.map((entry, index): AiDraftItem => {
      const item = this.record(entry);
      const questionSource = this.record(item.question);
      if (!isQuestionType(questionSource.qtype)) {
        throw new Error(this.s('editor:error:response'));
      }
      const files = Array.isArray(item.files)
        ? item.files.map((file) => this.normalizeAiDraftFile(file))
          .filter((file): file is AiDraftFile => file !== null)
        : [];
      const question = normalizeQuestion({
        ...questionSource,
        id: 0,
        rootid: 0,
        sortorder: index,
        status: 'draft',
        timemodified: 0,
        version: 0,
        validationErrors: item.validationErrors ?? questionSource.validationErrors ?? [],
        files: questionSource.files ?? files,
      });
      question.id = 0;
      question.rootid = 0;
      question.status = 'draft';
      question.version = 0;
      question.timemodified = 0;
      const itemId = typeof item.itemId === 'string'
        ? item.itemId.trim()
        : typeof item.itemId === 'number'
          ? String(item.itemId)
          : '';
      if (itemId === '') {
        throw new Error(this.s('editor:error:response'));
      }
      return {
        files,
        itemId,
        question,
        status: 'draft',
        validationErrors: question.validationErrors,
      };
    });
    if (items.length === 0 || new Set(items.map((item) => item.itemId)).size !== items.length) {
      throw new Error(this.s('editor:error:response'));
    }
    const warnings = Array.isArray(source.warnings)
      ? source.warnings.flatMap((warning): string[] => {
        if (typeof warning === 'string' && warning.trim() !== '') {
          return [warning.trim()];
        }
        const structured = this.record(warning);
        const message = typeof structured.message === 'string'
          ? structured.message.trim()
          : typeof structured.code === 'string'
            ? structured.code.trim()
            : '';
        return message === '' ? [] : [message];
      })
      : [];
    const expiresAt = source.expiresAt;
    return {
      expiresAt: typeof expiresAt === 'number' || typeof expiresAt === 'string'
        ? expiresAt
        : null,
      format,
      items,
      kind,
      origin: typeof source.origin === 'string' && source.origin.trim() !== ''
        ? source.origin.trim()
        : 'unknown',
      status: 'draft',
      title: typeof source.title === 'string' && source.title.trim() !== ''
        ? source.title.trim()
        : this.aiSourceLabel(kind),
      token,
      warnings,
    };
  }

  private normalizeAiDraftFile(raw: unknown): AiDraftFile | null {
    const source = this.record(raw);
    const filename = typeof source.filename === 'string' ? source.filename.trim() : '';
    if (filename === '') {
      return null;
    }
    const filesize = Number(source.filesize || 0);
    return {
      filename,
      ...(Number.isFinite(filesize) && filesize > 0 ? {filesize} : {}),
      ...(typeof source.mimetype === 'string' && source.mimetype !== ''
        ? {mimetype: source.mimetype}
        : {}),
      ...(typeof source.path === 'string' && source.path !== ''
        ? {path: source.path}
        : {}),
      ...(typeof source.url === 'string' && source.url !== ''
        ? {url: source.url}
        : {}),
    };
  }

  private renderAiDraft(
    draft: AiDraft,
    selectedItemIds: Set<string>,
    onSelectionChange: () => void,
  ): HTMLElement {
    const section = element('section', 'quizgeist-ai-draft', {tabindex: -1});
    const header = element('header', 'quizgeist-ai-draft__header');
    const headingGroup = element('div');
    headingGroup.append(
      element('span', 'quizgeist-ai-chip quizgeist-ai-chip--draft', {
        text: this.aiLabel('editor:ai:draft', 'ENTWURF'),
      }),
      element('h3', '', {text: draft.title}),
      element('p', '', {
        text: [
          this.aiSourceLabel(draft.kind),
          this.aiFormatLabel(draft.format),
          this.formatAiExpiry(draft.expiresAt),
        ].filter(Boolean).join(' · '),
      }),
    );
    const fallback = draft.origin === 'fallback';
    const gateway = draft.origin === 'gateway';
    header.append(
      headingGroup,
      element('span', `quizgeist-ai-chip ${
        fallback
          ? 'quizgeist-ai-chip--fallback'
          : gateway
            ? 'quizgeist-ai-chip--gateway'
            : ''
      }`, {
        text: fallback
          ? this.aiLabel('editor:ai:fallback', 'Regelbasierter Fallback')
          : gateway
            ? this.aiLabel('editor:ai:gateway', 'Lokales KI-Gateway')
            : draft.origin === 'import'
              ? this.aiLabel('editor:ai:import', 'Serverseitiger Folienimport')
              : this.aiLabel('editor:ai:server', 'Serverseitig erzeugt'),
      }),
    );
    section.append(header);

    const draftWarnings = aiWarningMessages(this.config.strings, draft.warnings);
    if (draftWarnings.length > 0) {
      const warning = element('div', 'quizgeist-ai-warning', {role: 'status'});
      warning.append(element('strong', '', {
        text: this.aiLabel('editor:ai:warnings', 'Hinweise'),
      }));
      const list = element('ul');
      draftWarnings.forEach((message) => {
        list.append(element('li', '', {text: message}));
      });
      warning.append(list);
      section.append(warning);
    }

    const selectionBar = element('div', 'quizgeist-ai-selection-bar');
    const selectionStatus = element('span', '', {
      role: 'status',
      'aria-live': 'polite',
    });
    const updateSelectionStatus = (): void => {
      selectionStatus.textContent = this.aiLabel(
        'editor:ai:selected',
        '{$a} von {$b} ausgewählt',
      ).replace('{$a}', String(selectedItemIds.size))
        .replace('{$b}', String(draft.items.length));
      onSelectionChange();
    };
    const selectAll = button(
      this.aiLabel('editor:ai:selectall', 'Alle auswählen'),
      'quizgeist-button quizgeist-button--quiet',
    );
    const selectNone = button(
      this.aiLabel('editor:ai:selectnone', 'Auswahl aufheben'),
      'quizgeist-button quizgeist-button--quiet',
    );
    selectionBar.append(selectionStatus, selectAll, selectNone);
    section.append(selectionBar);

    const cards = element('div', 'quizgeist-ai-draft__items');
    const checkboxes = new Map<string, HTMLInputElement>();
    draft.items.forEach((item, index) => {
      const question = item.question;
      const card = element('article', 'quizgeist-ai-draft-item');
      const cardHeader = element('header', 'quizgeist-ai-draft-item__header');
      const choice = element('label', 'quizgeist-ai-draft-item__choice');
      const checkbox = element('input', '', {
        type: 'checkbox',
        checked: selectedItemIds.has(item.itemId),
      });
      checkboxes.set(item.itemId, checkbox);
      checkbox.addEventListener('change', () => {
        if (checkbox.checked) {
          selectedItemIds.add(item.itemId);
        } else {
          selectedItemIds.delete(item.itemId);
        }
        updateSelectionStatus();
      });
      choice.append(
        checkbox,
        element('span', '', {
          text: `${index + 1}. ${this.questionTitle(question)}`,
        }),
      );
      cardHeader.append(
        choice,
        element('span', 'quizgeist-ai-chip', {
          text: this.s(`editor:qtype:${question.qtype}`),
        }),
      );
      card.append(cardHeader);

      const prompt = element('p', 'quizgeist-ai-draft-item__prompt', {
        text: question.questiontext || this.questionTitle(question),
      });
      card.append(
        prompt,
        createTtsControl(this.tts, this.ttsText(question), this.config),
        this.renderPreviewResponse(question),
      );
      if (question.explanation.trim() !== '') {
        const explanation = element('details', 'quizgeist-ai-draft-item__explanation');
        explanation.append(
          element('summary', '', {text: this.s('editor:field:explanation')}),
          element('p', '', {text: question.explanation}),
        );
        card.append(explanation);
      }
      if (item.files.length > 0) {
        const files = element('ul', 'quizgeist-ai-draft-item__files');
        item.files.forEach((file) => {
          files.append(element('li', '', {
            text: [
              file.filename,
              file.mimetype || '',
              file.filesize ? this.formatFileSize(file.filesize) : '',
            ].filter(Boolean).join(' · '),
          }));
        });
        card.append(files);
      }
      const errors = this.aiValidationMessages(item.validationErrors);
      if (errors.length > 0) {
        const validation = element('div', 'quizgeist-ai-draft-item__validation', {
          role: 'status',
        });
        validation.append(element('strong', '', {
          text: this.aiLabel(
            'editor:ai:validation',
            'Im Editor noch zu vervollständigen',
          ),
        }));
        const list = element('ul');
        errors.forEach((error) => list.append(element('li', '', {text: error})));
        validation.append(list);
        card.append(validation);
      }
      cards.append(card);
    });
    section.append(cards);

    selectAll.addEventListener('click', () => {
      draft.items.forEach((item) => {
        selectedItemIds.add(item.itemId);
        const checkbox = checkboxes.get(item.itemId);
        if (checkbox) {
          checkbox.checked = true;
        }
      });
      updateSelectionStatus();
    });
    selectNone.addEventListener('click', () => {
      selectedItemIds.clear();
      checkboxes.forEach((checkbox) => {
        checkbox.checked = false;
      });
      updateSelectionStatus();
    });
    updateSelectionStatus();
    return section;
  }

  /**
   * Render the workshop card's findings exactly like the editor's own summary.
   *
   * The draft transport keeps the server's stable `field`/`code` pair; only the
   * presentation is resolved here, so the acceptance boundary stays intact.
   */
  private aiValidationMessages(errors: AiDraftItem['validationErrors']): string[] {
    return validationMessages(this.config.strings, errors);
  }

  private formatAiExpiry(value: AiDraft['expiresAt']): string {
    const numeric = typeof value === 'number'
      ? value
      : typeof value === 'string' && value.trim() !== ''
        ? Number(value)
        : Number.NaN;
    if (!Number.isFinite(numeric) || numeric <= 0) {
      return '';
    }
    const milliseconds = numeric < 100_000_000_000 ? numeric * 1000 : numeric;
    const date = new Date(milliseconds);
    if (Number.isNaN(date.getTime())) {
      return '';
    }
    return this.aiLabel(
      'editor:ai:expires',
      'Gültig bis {$a}',
    ).replace('{$a}', date.toLocaleTimeString([], {
      hour: '2-digit',
      minute: '2-digit',
    }));
  }

  private formatFileSize(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
      return '';
    }
    if (bytes < 1024) {
      return `${Math.round(bytes)} B`;
    }
    if (bytes < 1024 * 1024) {
      return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  private async discardAiDraft(token: string): Promise<void> {
    if (token === '') {
      return;
    }
    try {
      await this.api.post('ai_discard', {token});
    } catch (_error) {
      // Drafts also have a server-side expiry. Closing the editor must not
      // surface cleanup failures or leak upstream diagnostics.
    }
  }

  private async discardAiSource(
    selection: Pick<AiSourceSelection, 'purpose' | 'sourceToken'>,
  ): Promise<void> {
    try {
      await this.api.post('ai_discard', {
        source: selection.purpose,
        sourceToken: selection.sourceToken,
      });
    } catch (_error) {
      // Moodle eventually expires abandoned user drafts as a final safety
      // net. Cleanup failures must not make the editor unusable.
    }
  }

  private async openAiExplanation(question: EditorQuestion): Promise<void> {
    if (!this.aiConfig.available || question.id <= 0) {
      this.showToast(this.aiLabel(
        'editor:ai:unavailable',
        'Die KI-Werkstatt ist derzeit nicht verfügbar.',
      ), true);
      return;
    }
    if (this.aiExplanationDialog?.open) {
      this.aiExplanationDialog.focus();
      return;
    }

    const parts = openDialog(
      this.aiLabel(
        'editor:ai:explanation:title',
        'KI-Lösungsweg als Entwurf',
      ),
      this.s('editor:action:close'),
      'quizgeist-ai-explanation-dialog',
    );
    this.aiExplanationDialog = parts.dialog;
    const shell = element('section', 'quizgeist-ai-explanation');
    const intro = element('p', 'quizgeist-dialog__intro', {
      text: this.aiLabel(
        'editor:ai:explanation:intro',
        'Der Vorschlag wird aus der kanonischen Frage und ihrer geprüften '
          + 'Lösung erstellt. Er wird erst nach Ihrer Bestätigung gespeichert.',
      ),
    });
    const content = element('div', 'quizgeist-ai-explanation__content', {
      'aria-live': 'polite',
    });
    shell.append(intro, content);
    parts.body.append(shell);

    const cancel = button(
      this.s('editor:action:cancel'),
      'quizgeist-button quizgeist-button--secondary',
      () => closeDialog(parts.dialog),
    );
    const confirm = button(
      this.aiLabel(
        'editor:ai:explanation:confirm',
        'Lösungsweg übernehmen',
      ),
      'quizgeist-button quizgeist-button--primary',
    );
    confirm.dataset.action = 'ai-explanation-apply';
    confirm.disabled = true;
    parts.footer.append(cancel, confirm);

    let draft: AiExplanationDraft | null = null;
    let committed = false;
    let applying = false;
    const preventCloseWhileApplying = (event: Event): void => {
      if (!applying) {
        return;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
    };
    parts.dialog.addEventListener('cancel', preventCloseWhileApplying, {
      capture: true,
    });
    parts.dialog.addEventListener('click', (event) => {
      if (event.target === parts.dialog) {
        preventCloseWhileApplying(event);
      }
    }, {capture: true});

    const renderLoading = (): void => {
      content.replaceChildren(element('div', 'quizgeist-ai-loading', {
        role: 'status',
        text: this.aiLabel(
          'editor:ai:explanation:loading',
          'Lösungsweg wird vorgeschlagen …',
        ),
      }));
    };
    const generate = async(): Promise<void> => {
      this.aiExplanationController?.abort();
      const controller = new AbortController();
      this.aiExplanationController = controller;
      confirm.disabled = true;
      renderLoading();
      try {
        await this.flushPendingSaves();
        if (!parts.dialog.open || controller.signal.aborted) {
          return;
        }
        if (draft?.token) {
          await this.discardAiDraft(draft.token);
          draft = null;
        }
        const questionId = this.resolveQuestionId(question.id);
        const response = await this.api.post<unknown>(
          'ai_explanation_generate',
          {questionId},
          controller.signal,
        );
        const proposal = this.normalizeAiExplanationDraft(response, questionId);
        draft = proposal;
        content.replaceChildren(this.renderAiExplanationDraft(question, proposal));
        confirm.disabled = false;
        content.querySelector<HTMLElement>('[data-ai-explanation-preview]')?.focus();
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
          const errorBox = element('div', 'quizgeist-ai-error', {
            role: 'alert',
          });
          errorBox.append(
            element('p', '', {
              text: error instanceof Error
                ? error.message
                : this.s('editor:error:request'),
            }),
            button(
              this.s('editor:action:retry'),
              'quizgeist-button quizgeist-button--secondary',
              () => void generate(),
            ),
          );
          content.replaceChildren(errorBox);
        }
      } finally {
        if (this.aiExplanationController === controller) {
          this.aiExplanationController = null;
        }
      }
    };

    confirm.addEventListener('click', async() => {
      const proposal = draft;
      if (!proposal) {
        return;
      }
      applying = true;
      parts.closeButton.disabled = true;
      cancel.disabled = true;
      setButtonBusy(
        confirm,
        true,
        this.aiLabel(
          'editor:ai:explanation:confirming',
          'Lösungsweg wird übernommen …',
        ),
      );
      try {
        const response = await this.api.post<unknown>(
          'ai_explanation_apply',
          {token: proposal.token},
        );
        const result = this.record(response);
        if (result.committed !== true) {
          throw new Error(this.s('editor:error:response'));
        }
        const canonical = this.record(result.question);
        const canonicalId = Number(canonical.id || 0);
        if (Number.isInteger(canonicalId) && canonicalId > 0) {
          this.selectedQuestionId = canonicalId;
        }
        committed = true;
        draft = null;
        applying = false;
        closeDialog(parts.dialog);
        this.renderLoading();
        await this.loadBootstrap(true);
        this.showToast(this.aiLabel(
          'editor:ai:explanation:applied',
          'Der Lösungsweg wurde als bestätigter Editor-Entwurf übernommen.',
        ));
      } catch (error) {
        applying = false;
        if (!parts.dialog.open) {
          void this.discardAiDraft(proposal.token);
          return;
        }
        parts.closeButton.disabled = false;
        cancel.disabled = false;
        setButtonBusy(
          confirm,
          false,
          this.aiLabel(
            'editor:ai:explanation:confirm',
            'Lösungsweg übernehmen',
          ),
        );
        this.showToast(
          error instanceof Error ? error.message : this.s('editor:error:request'),
          true,
        );
      }
    });

    parts.dialog.addEventListener('close', () => {
      this.aiExplanationController?.abort();
      this.aiExplanationController = null;
      this.tts.stop();
      const token = !committed ? draft?.token : null;
      draft = null;
      this.aiExplanationDialog = null;
      if (token) {
        void this.discardAiDraft(token);
      }
    }, {once: true});
    void generate();
  }

  private normalizeAiExplanationDraft(
    raw: unknown,
    expectedQuestionId: number,
  ): AiExplanationDraft {
    const envelope = this.record(raw);
    const source = this.record(envelope.draft || raw);
    const token = typeof source.token === 'string' ? source.token.trim() : '';
    const questionId = Number(source.questionId || 0);
    const explanation = typeof source.explanation === 'string'
      ? source.explanation.trim()
      : '';
    if (
      !/^[a-f0-9]{64}$/.test(token)
      || source.status !== 'draft'
      || !Number.isInteger(questionId)
      || questionId !== expectedQuestionId
      || explanation === ''
      || Array.from(explanation).length > 6000
    ) {
      throw new Error(this.s('editor:error:response'));
    }
    const warnings = Array.isArray(source.warnings)
      ? source.warnings.flatMap((warning): string[] => {
        if (typeof warning === 'string' && warning.trim() !== '') {
          return [warning.trim()];
        }
        const structured = this.record(warning);
        const message = typeof structured.message === 'string'
          ? structured.message.trim()
          : typeof structured.code === 'string'
            ? structured.code.trim()
            : '';
        return message === '' ? [] : [message];
      })
      : [];
    const expiresAt = source.expiresAt;
    return {
      expiresAt: typeof expiresAt === 'number' || typeof expiresAt === 'string'
        ? expiresAt
        : null,
      explanation,
      origin: typeof source.origin === 'string' && source.origin.trim() !== ''
        ? source.origin.trim()
        : 'unknown',
      questionId,
      status: 'draft',
      token,
      warnings,
    };
  }

  private renderAiExplanationDraft(
    question: EditorQuestion,
    draft: AiExplanationDraft,
  ): HTMLElement {
    const preview = element('article', 'quizgeist-ai-explanation__preview', {
      'data-ai-explanation-preview': true,
      'data-ai-explanation-status': 'draft',
      tabindex: -1,
    });
    const fallback = draft.origin === 'fallback';
    const gateway = draft.origin === 'gateway';
    const header = element('header', 'quizgeist-ai-explanation__header');
    header.append(
      element('span', 'quizgeist-ai-chip quizgeist-ai-chip--draft', {
        text: this.aiLabel('editor:ai:draft', 'ENTWURF'),
      }),
      element('span', `quizgeist-ai-chip ${
        fallback
          ? 'quizgeist-ai-chip--fallback'
          : gateway
            ? 'quizgeist-ai-chip--gateway'
            : ''
      }`, {
        text: fallback
          ? this.aiLabel('editor:ai:fallback', 'Regelbasierter Fallback')
          : gateway
            ? this.aiLabel('editor:ai:gateway', 'Lokales KI-Gateway')
            : this.aiLabel('editor:ai:server', 'Serverseitig erzeugt'),
      }),
      element('span', 'quizgeist-ai-explanation__expiry', {
        text: this.formatAiExpiry(draft.expiresAt),
      }),
    );
    preview.append(
      header,
      element('h3', '', {
        text: this.aiLabel(
          'editor:ai:explanation:preview',
          'Vorgeschlagener Lösungsweg',
        ),
      }),
      element('p', 'quizgeist-ai-explanation__question', {
        text: this.questionTitle(question),
      }),
      createTtsControl(
        this.tts,
        [this.questionTitle(question), draft.explanation].filter(Boolean).join('. '),
        this.config,
      ),
      element('div', 'quizgeist-ai-explanation__text', {
        text: draft.explanation,
      }),
      element('p', 'quizgeist-ai-explanation__notice', {
        text: this.aiLabel(
          'editor:ai:explanation:draftnotice',
          'Noch nicht gespeichert: Prüfen Sie den Vorschlag und '
            + 'bestätigen Sie ihn ausdrücklich.',
        ),
      }),
    );
    const explanationWarnings = aiWarningMessages(this.config.strings, draft.warnings);
    if (explanationWarnings.length > 0) {
      const warning = element('div', 'quizgeist-ai-warning', {role: 'status'});
      warning.append(element('strong', '', {
        text: this.aiLabel('editor:ai:warnings', 'Hinweise'),
      }));
      const list = element('ul');
      explanationWarnings.forEach((message) => {
        list.append(element('li', '', {text: message}));
      });
      warning.append(list);
      preview.append(warning);
    }
    return preview;
  }

  private questionTypeIcon(qtype: QuestionType): HTMLElement {
    const icon = element('span', `quizgeist-qtype-icon quizgeist-qtype-icon--${qtype}`, {
      'aria-hidden': 'true',
    });
    icon.append(
      element('span', 'quizgeist-qtype-icon__shape quizgeist-qtype-icon__shape--one'),
      element('span', 'quizgeist-qtype-icon__shape quizgeist-qtype-icon__shape--two'),
    );
    return icon;
  }

  private questionTitle(question: EditorQuestion): string {
    if (question.qtype === 'slide') {
      return (question.options as SlideOptions).title.trim()
        || this.s('editor:question:untitled');
    }
    return question.questiontext.trim() || this.s('editor:question:untitled');
  }

  private renderQuestionMetadata(question: EditorQuestion): HTMLElement {
    const metadata = element('span', 'quizgeist-question-item__meta');
    const type = this.s(`editor:qtype:${question.qtype}`);
    metadata.append(element('span', 'quizgeist-question-item__type', {text: type}));
    const chips = element('span', 'quizgeist-question-item__chips');
    chips.append(
      element('span', 'quizgeist-question-item__chip quizgeist-question-item__chip--time', {
        text: `${question.timelimit} ${this.s('editor:unit:seconds')}`,
      }),
      element('span', 'quizgeist-question-item__chip quizgeist-question-item__chip--points', {
        text: this.s(`editor:pointmode:${question.pointmode}`),
      }),
    );
    if (question.qtype === 'slide') {
      const layout = (question.options as SlideOptions).layout;
      chips.prepend(element('span', 'quizgeist-question-item__chip', {
        text: this.s(`editor:layout:${layout.replace('-', '')}`),
      }));
    }
    metadata.append(chips);
    return metadata;
  }

  private questionHasErrors(question: EditorQuestion): boolean {
    if (Array.isArray(question.validationErrors)) {
      return question.validationErrors.length > 0;
    }
    return Object.keys(question.validationErrors).length > 0;
  }

  private renderValidationOverview(): HTMLElement | null {
    const invalidQuestions = this.questions
      .map((question, index) => ({index, question}))
      .filter(({question}) => this.questionHasErrors(question));
    if (invalidQuestions.length === 0) {
      return null;
    }
    const overview = element('section', 'quizgeist-validation-overview');
    const heading = element('h2', 'quizgeist-validation-overview__title', {
      text: this.s(invalidQuestions.length === 1
        ? 'editor:validation:overview:one'
        : 'editor:validation:overview:many')
        .replace('{$a}', String(invalidQuestions.length)),
    });
    heading.id = `quizgeist-validation-overview-${crypto.randomUUID()}`;
    overview.setAttribute('aria-labelledby', heading.id);
    const list = element('ul', 'quizgeist-validation-overview__list');
    for (const {index, question} of invalidQuestions) {
      const item = element('li');
      const jumpLabel = this.s('editor:validation:jump')
        .replace('{$a}', String(index + 1))
        .replace('{$b}', this.questionTitle(question));
      const jump = button(
        jumpLabel,
        'quizgeist-validation-overview__jump',
        () => this.jumpToQuestionValidation(question),
      );
      item.append(jump);
      list.append(item);
    }
    overview.append(heading, list);
    return overview;
  }

  private refreshValidationOverview(): void {
    const detail = this.root.querySelector<HTMLElement>('.quizgeist-question-detail');
    if (!detail) {
      return;
    }
    const existing = detail.querySelector<HTMLElement>('.quizgeist-validation-overview');
    const replacement = this.renderValidationOverview();
    if (existing && replacement) {
      existing.replaceWith(replacement);
    } else if (existing) {
      existing.remove();
    } else if (replacement) {
      detail.prepend(replacement);
    }
  }

  private refreshQuestionTitle(question: EditorQuestion): void {
    const item = this.root.querySelector<HTMLElement>(
      `.quizgeist-question-item[data-question-id="${question.id}"]`,
    );
    const title = item?.querySelector<HTMLElement>('.quizgeist-question-item__title');
    if (title) {
      title.textContent = this.questionTitle(question);
    }
    const metadata = item?.querySelector<HTMLElement>('.quizgeist-question-item__meta');
    metadata?.replaceWith(this.renderQuestionMetadata(question));
  }

  private refreshQuestionStatus(question: EditorQuestion): void {
    const item = this.root.querySelector<HTMLElement>(
      `.quizgeist-question-item[data-question-id="${question.id}"]`,
    );
    if (item) {
      item.classList.toggle('has-errors', this.questionHasErrors(question));
      const existing = item.querySelector('.quizgeist-question-item__error');
      if (this.questionHasErrors(question) && !existing) {
        item.querySelector('.quizgeist-question-item__select')?.append(
          element('span', 'quizgeist-question-item__error', {
            title: this.s('editor:validation:item'),
            'aria-label': this.s('editor:validation:item'),
          }),
        );
      } else if (!this.questionHasErrors(question)) {
        existing?.remove();
      }
      const text = item.querySelector<HTMLElement>('.quizgeist-question-item__text');
      const status = text?.querySelector<HTMLElement>('.quizgeist-question-item__status');
      if (status) {
        status.replaceWith(this.renderQuestionStatusBadge(question));
      } else {
        text?.append(this.renderQuestionStatusBadge(question));
      }
      const readiness = this.renderQuestionReadiness(question);
      const existingReadiness = text?.querySelector<HTMLElement>(
        '.quizgeist-question-item__readiness',
      );
      if (existingReadiness && readiness) {
        existingReadiness.replaceWith(readiness);
      } else if (existingReadiness) {
        existingReadiness.remove();
      } else if (readiness) {
        text?.append(readiness);
      }
      const release = item.querySelector<HTMLButtonElement>(
        '.quizgeist-question-item__release',
      );
      const replacementRelease = this.renderQuestionReleaseButton(question);
      if (release && replacementRelease) {
        release.replaceWith(replacementRelease);
      } else if (release) {
        release.remove();
      } else if (replacementRelease) {
        const actions = item.querySelector('.quizgeist-question-actions');
        item.insertBefore(replacementRelease, actions);
      }
    }
    if (question.id === this.selectedQuestionId) {
      const form = this.root.querySelector<HTMLElement>(
        `.quizgeist-question-form[data-question-id="${question.id}"]`,
      );
      if (form) {
        const existing = form.querySelector<HTMLElement>('.quizgeist-validation-summary');
        const replacement = this.formRenderer.renderValidationSummary(question);
        const summaryHadFocus = Boolean(
          existing && document.activeElement && existing.contains(document.activeElement),
        );
        if (existing && replacement) {
          existing.replaceWith(replacement);
        } else if (existing) {
          existing.remove();
        } else if (replacement) {
          const heading = form.querySelector('.quizgeist-question-form__heading');
          heading?.insertAdjacentElement('afterend', replacement);
        }
        if (summaryHadFocus) {
          replacement?.focus();
        }
      }
    }
    this.refreshValidationOverview();
    this.refreshReleaseControls();
    this.refreshReadinessPanel();
  }

  private refreshReadinessPanel(): void {
    const existing = this.root.querySelector<HTMLElement>('.quizgeist-editor-nextstep');
    if (!existing) {
      return;
    }
    const replacement = this.renderNextStep();
    if (replacement) {
      existing.replaceWith(replacement);
    } else {
      existing.remove();
    }
  }

  private releaseReasonForFailure(
    question: EditorQuestion | undefined,
    error?: unknown,
  ): string {
    if (error instanceof Error && error.message !== '') {
      return error.message;
    }
    const readiness = question ? this.questionReadinessReason(question) : null;
    if (readiness) {
      return readiness;
    }
    return this.s('editor:error:request', 'Speichern fehlgeschlagen.');
  }

  private async releaseQuestion(
    questionId: number,
    releaseButton: HTMLButtonElement,
  ): Promise<void> {
    if (this.releaseAllRunning) {
      return;
    }
    const resolvedId = this.resolveQuestionId(questionId);
    const question = this.questions.find((candidate) => candidate.id === resolvedId);
    if (!question || !this.isReleaseCandidate(question)
        || this.releasingQuestionIds.has(resolvedId)) {
      return;
    }
    this.releasingQuestionIds.add(resolvedId);
    setButtonBusy(
      releaseButton,
      true,
      this.s('editor:release:working', 'Wird freigegeben …'),
    );
    try {
      this.markQuestionDirty(question.id);
      await this.flushPendingSaves();
      const canonical = this.questions.find((candidate) => (
        candidate.id === this.resolveQuestionId(resolvedId)
      ));
      if (canonical?.status === 'ready') {
        this.setReleaseResult(this.s(
          'editor:release:success',
          'Die Frage wurde freigegeben.',
        ));
      } else {
        const reason = this.releaseReasonForFailure(canonical);
        this.setReleaseResult(this.workshopText(
          'editor:release:draft',
          'Die Frage bleibt Entwurf: {$a}',
          {a: reason},
        ));
      }
    } catch (error) {
      const canonical = this.questions.find((candidate) => (
        candidate.id === this.resolveQuestionId(resolvedId)
      ));
      const reason = this.releaseReasonForFailure(canonical, error);
      this.setReleaseResult(this.workshopText(
        'editor:release:draft',
        'Die Frage bleibt Entwurf: {$a}',
        {a: reason},
      ));
    } finally {
      this.releasingQuestionIds.delete(resolvedId);
      setButtonBusy(
        releaseButton,
        false,
        this.s('editor:action:release', 'Freigeben'),
      );
      this.refreshReleaseControls();
    }
  }

  private async releaseAllQuestions(releaseButton: HTMLButtonElement): Promise<void> {
    if (this.releaseAllRunning) {
      return;
    }
    this.releaseAllRunning = true;
    setButtonBusy(
      releaseButton,
      true,
      this.s('editor:release:working', 'Wird freigegeben …'),
    );
    // Keep the original candidates stable. Autosave can migrate IDs while a
    // question is being saved, and a later refresh can change its status.
    const candidateIds = this.questions
      .filter((question) => this.isReleaseCandidate(question))
      .map((question) => question.id);
    const operationErrors = new Map<number, unknown>();
    let abortError: unknown = null;
    try {
      try {
        await this.flushPendingSaves();
      } catch (error) {
        // The first save may already have committed earlier questions. Count
        // those canonical results below, but do not pretend that the rest was
        // attempted after a real transport/conflict failure.
        abortError = error;
        candidateIds.forEach((candidateId) => {
          const resolvedId = this.resolveQuestionId(candidateId);
          const question = this.questions.find((item) => item.id === resolvedId);
          if (question && question.status !== 'ready') {
            operationErrors.set(candidateId, error);
          }
        });
      }

      if (abortError === null) {
        for (let index = 0; index < candidateIds.length; index += 1) {
          const originalId = candidateIds[index];
          const resolvedId = this.resolveQuestionId(originalId);
          const question = this.questions.find((item) => item.id === resolvedId);
          if (!question) {
            abortError = new Error(this.s('editor:error:response'));
            for (let rest = index; rest < candidateIds.length; rest += 1) {
              operationErrors.set(candidateIds[rest], abortError);
            }
            break;
          }
          // Release is deliberately the normal question_save path. The
          // server decides whether the canonical result is ready; the client
          // never writes a local status or adds a release payload flag.
          this.markQuestionDirty(question.id);
          try {
            await this.flushPendingSaves();
          } catch (error) {
            abortError = error;
            for (let rest = index; rest < candidateIds.length; rest += 1) {
              operationErrors.set(candidateIds[rest], error);
            }
            break;
          }
        }
      }

      const released = candidateIds.filter((candidateId) => {
        const resolvedId = this.resolveQuestionId(candidateId);
        return this.questions.some((question) => (
          question.id === resolvedId && question.status === 'ready'
        ));
      }).length;
      const draftQuestions = this.questions.filter((question) => question.status !== 'ready');
      const reasons = draftQuestions.map((question, index) => {
        const candidateId = candidateIds.find((id) => (
          this.resolveQuestionId(id) === question.id
        ));
        const error = candidateId === undefined
          ? undefined
          : operationErrors.get(candidateId);
        return `${index + 1}. ${this.questionTitle(question)}: `
          + this.releaseReasonForFailure(question, error);
      });
      const drafts = draftQuestions.length;
      let message = this.workshopText(
        'editor:release:bulk:result',
        'Freigegeben: {$a->released}. Im Entwurf geblieben: {$a->draft}.',
        {released, draft: drafts},
      );
      if (reasons.length > 0) {
        message += ` ${this.workshopText(
          'editor:release:bulk:reasons',
          'Gründe: {$a}',
          {a: reasons.join(' · ')},
        )}`;
      }
      this.setReleaseResult(message);
    } finally {
      this.releaseAllRunning = false;
      setButtonBusy(
        releaseButton,
        false,
        this.s('editor:action:releaseall', 'Alle vollständigen Fragen freigeben'),
      );
      this.refreshReleaseControls();
    }
  }

  private selectedQuestion(): EditorQuestion | null {
    return this.questions.find((question) => question.id === this.selectedQuestionId) || null;
  }

  private openQuestionPalette(): void {
    const dialogParts = openDialog(
      this.s('editor:addpalette:title'),
      this.s('editor:action:close'),
      'quizgeist-question-palette-dialog',
    );
    const intro = element('p', 'quizgeist-dialog__intro', {
      text: this.s('editor:addpalette:description'),
    });
    const grid = element('div', 'quizgeist-question-palette');
    for (const qtype of this.supportedTypes) {
      const typeButton = button('', 'quizgeist-question-type-card', () => {
        closeDialog(dialogParts.dialog);
        void this.createQuestion(qtype);
      });
      typeButton.append(
        this.questionTypeIcon(qtype),
        element('span', 'quizgeist-question-type-card__title', {
          text: this.s(`editor:qtype:${qtype}`),
        }),
        element('span', 'quizgeist-question-type-card__description', {
          text: this.s(`editor:qtype:${qtype}:description`),
        }),
      );
      grid.append(typeButton);
    }
    dialogParts.body.append(intro, grid);
  }

  private async createQuestion(qtype: QuestionType): Promise<void> {
    try {
      await this.flushPendingSaves();
      this.setSaveState('saving');
      const data = await this.api.post<unknown>('question_create', {qtype});
      const question = normalizeQuestion(this.extractQuestion(data));
      if (question.id <= 0) {
        throw new Error(this.s('editor:error:response'));
      }
      this.questions.push(question);
      this.questions.sort((left, right) => left.sortorder - right.sortorder);
      this.renumberQuestionSortorders();
      this.selectedQuestionId = question.id;
      this.finishEditorOperation();
      this.render();
      window.requestAnimationFrame(() => {
        this.root.querySelector<HTMLElement>('.quizgeist-question-form textarea, .quizgeist-question-form input')
          ?.focus();
      });
    } catch (error) {
      this.setSaveError(error);
    }
  }

  private async duplicateQuestion(questionId: number): Promise<void> {
    try {
      await this.flushPendingSaves();
      questionId = this.resolveQuestionId(questionId);
      this.setSaveState('saving');
      const data = await this.api.post<unknown>('question_duplicate', {
        questionid: questionId,
      });
      const question = normalizeQuestion(this.extractQuestion(data));
      if (question.id <= 0) {
        await this.loadBootstrap(true);
        return;
      }
      const canonical = this.extractQuestions(data);
      const questionIdMap = this.extractQuestionIdMap(data);
      const sourceIndex = this.questions.findIndex((item) => item.id === questionId);
      const insertionIndex = sourceIndex >= 0 ? sourceIndex + 1 : this.questions.length;
      this.questions.splice(insertionIndex, 0, question);
      const expectedIds = this.questions.map((item) => item.id);
      if (!await this.reconcileCanonicalQuestions(canonical, expectedIds, questionIdMap, true)) {
        return;
      }
      this.selectedQuestionId = this.resolveQuestionId(question.id);
      this.finishEditorOperation();
      this.render();
      this.announce(this.s('editor:announcement:duplicated'));
    } catch (error) {
      this.setSaveError(error);
    }
  }

  private async deleteQuestion(questionId: number): Promise<void> {
    if (!window.confirm(this.s('editor:confirm:deletequestion'))) {
      return;
    }
    try {
      await this.flushPendingSaves();
      questionId = this.resolveQuestionId(questionId);
      this.setSaveState('saving');
      const data = await this.api.post<unknown>('question_delete', {questionid: questionId});
      const canonical = this.extractQuestions(data);
      const questionIdMap = this.extractQuestionIdMap(data);
      const index = this.questions.findIndex((question) => question.id === questionId);
      if (index < 0) {
        throw new Error(this.s('editor:error:response'));
      }
      const deletingSelection = this.selectedQuestionId === questionId;
      this.questions.splice(index, 1);
      this.questionDirtyVersions.delete(questionId);
      this.questionSavedVersions.delete(questionId);
      this.questionIdAliases.forEach((target, alias) => {
        if (alias === questionId || target === questionId) {
          this.questionIdAliases.delete(alias);
        }
      });
      const expectedIds = this.questions.map((question) => question.id);
      if (!await this.reconcileCanonicalQuestions(canonical, expectedIds, questionIdMap, true)) {
        return;
      }
      if (deletingSelection) {
        this.selectedQuestionId = this.questions[
          Math.min(Math.max(index, 0), this.questions.length - 1)
        ]?.id || null;
      }
      this.finishEditorOperation();
      this.render();
      this.announce(this.s('editor:announcement:deleted'));
    } catch (error) {
      this.setSaveError(error);
    }
  }

  private moveQuestion(questionId: number, delta: number): void {
    const index = this.questions.findIndex((question) => question.id === questionId);
    const target = index + delta;
    if (index < 0 || target < 0 || target >= this.questions.length) {
      return;
    }
    const [moved] = this.questions.splice(index, 1);
    this.questions.splice(target, 0, moved);
    this.finishOptimisticReorder(moved.id);
  }

  private reorderQuestion(sourceId: number, targetId: number): void {
    const sourceIndex = this.questions.findIndex((question) => question.id === sourceId);
    const targetIndex = this.questions.findIndex((question) => question.id === targetId);
    if (sourceIndex < 0 || targetIndex < 0 || sourceIndex === targetIndex) {
      return;
    }
    const [moved] = this.questions.splice(sourceIndex, 1);
    this.questions.splice(targetIndex, 0, moved);
    this.finishOptimisticReorder(moved.id);
  }

  private finishOptimisticReorder(movedQuestionId: number): void {
    this.renumberQuestionSortorders();
    this.questionOrderDirtyVersion += 1;
    this.render();
    const position = this.questions.findIndex((question) => question.id === movedQuestionId) + 1;
    this.announce(
      this.s('editor:announcement:moved').replace('{$a}', String(position)),
    );
    this.setSaveState('dirty');
    this.scheduleAutosave();
  }

  private renumberQuestionSortorders(): void {
    this.questions.forEach((question, index) => {
      question.sortorder = index;
    });
  }

  private markQuestionDirty(questionId: number): void {
    const version = (this.questionDirtyVersions.get(questionId) || 0) + 1;
    this.questionDirtyVersions.set(questionId, version);
    this.setSaveState('dirty');
    this.scheduleAutosave();
  }

  private markActivityDirty(): void {
    this.activityDirtyVersion += 1;
    this.applyAppearance();
    this.setSaveState('dirty');
    this.scheduleAutosave();
  }

  private scheduleAutosave(): void {
    if (this.autosaveTimer !== null) {
      window.clearTimeout(this.autosaveTimer);
    }
    this.autosaveTimer = window.setTimeout(() => {
      this.autosaveTimer = null;
      void this.runSaveLoop();
    }, 800);
  }

  private async runSaveLoop(): Promise<void> {
    if (this.saveLoopRunning) {
      await new Promise<void>((resolve) => this.saveLoopWaiters.push(resolve));
      if (
        this.hasUnsavedChanges()
        && this.saveState !== 'error'
        && this.saveState !== 'conflict'
      ) {
        await this.runSaveLoop();
      }
      return;
    }
    this.saveLoopRunning = true;
    this.setSaveState('saving');
    try {
      while (this.hasUnsavedChanges()) {
        if (this.activityDirtyVersion > this.activitySavedVersion) {
          const version = this.activityDirtyVersion;
          const data = await this.api.post<unknown>('quiz_save', {
            activity: {
              name: this.activity.name,
              theme: this.activity.theme,
              season: this.activity.season,
              allowbacktrack: this.activity.allowbacktrack,
              timemodified: this.activity.timemodified,
            },
          });
          const result = this.extractActivity(data);
          if (!result) {
            throw new Error(this.s('editor:error:response'));
          }
          const canonicalActivity = this.normalizeActivity({
            ...this.activity,
            ...result,
          });
          if (version === this.activityDirtyVersion) {
            Object.assign(this.activity, canonicalActivity);
            this.applyAppearance();
          } else {
            this.activity.id = canonicalActivity.id;
            this.activity.timemodified = canonicalActivity.timemodified;
          }
          this.activitySavedVersion = Math.max(this.activitySavedVersion, version);
          continue;
        }

        const pending = this.questions.find((question) => (
          (this.questionDirtyVersions.get(question.id) || 0)
            > (this.questionSavedVersions.get(question.id) || 0)
        ));
        if (pending) {
          const requestQuestionId = pending.id;
          const version = this.questionDirtyVersions.get(requestQuestionId) || 0;
          const data = await this.api.post<unknown>('question_save', {
            question: cloneQuestionForSave(pending),
          });
          const normalized = normalizeQuestion(this.extractQuestion(data));
          if (normalized.id <= 0) {
            throw new Error(this.s('editor:error:response'));
          }
          const latestVersion = this.questionDirtyVersions.get(requestQuestionId) || 0;
          this.questionSavedVersions.set(
            requestQuestionId,
            Math.max(this.questionSavedVersions.get(requestQuestionId) || 0, version),
          );
          this.migrateQuestionId(pending, requestQuestionId, normalized.id);
          if (version === latestVersion) {
            this.mergeSavedQuestion(pending, normalized);
            this.renumberQuestionSortorders();
            this.refreshQuestionStatus(pending);
          } else {
            pending.rootid = normalized.rootid;
            pending.version = normalized.version;
            pending.timemodified = normalized.timemodified;
          }
          continue;
        }

        if (this.questionOrderDirtyVersion > this.questionOrderSavedVersion) {
          const version = this.questionOrderDirtyVersion;
          const questionids = this.questions.map((question) => question.id);
          const data = await this.api.post<unknown>('question_reorder', {questionids});
          const questions = this.extractQuestions(data);
          const questionIdMap = this.extractQuestionIdMap(data);
          const reconciled = await this.reconcileCanonicalQuestions(
            questions,
            questionids,
            questionIdMap,
            version === this.questionOrderDirtyVersion,
          );
          if (!reconciled) {
            return;
          }
          this.questionOrderSavedVersion = Math.max(this.questionOrderSavedVersion, version);
          continue;
        }
        break;
      }
      this.setSaveState('saved');
    } catch (error) {
      this.setSaveError(error);
    } finally {
      this.saveLoopRunning = false;
      this.saveLoopWaiters.splice(0).forEach((resolve) => resolve());
      if (
        this.hasUnsavedChanges()
        && this.saveState !== 'error'
        && this.saveState !== 'conflict'
      ) {
        this.scheduleAutosave();
      }
    }
  }

  private async flushPendingSaves(): Promise<void> {
    if (this.autosaveTimer !== null) {
      window.clearTimeout(this.autosaveTimer);
      this.autosaveTimer = null;
    }
    await this.runSaveLoop();
    if (
      this.saveState === 'error'
      || this.saveState === 'conflict'
      || this.hasUnsavedChanges()
    ) {
      throw new Error(this.saveError || this.s('editor:error:request'));
    }
  }

  private hasUnsavedChanges(): boolean {
    if (this.activityDirtyVersion > this.activitySavedVersion) {
      return true;
    }
    if (this.questionOrderDirtyVersion > this.questionOrderSavedVersion) {
      return true;
    }
    return this.questions.some((question) => (
      (this.questionDirtyVersions.get(question.id) || 0)
        > (this.questionSavedVersions.get(question.id) || 0)
    ));
  }

  private setSaveState(state: SaveState): void {
    this.saveState = state;
    if (state !== 'error' && state !== 'conflict') {
      this.saveError = '';
    }
    this.updateSaveStatusElement();
  }

  private setSaveError(error: unknown): void {
    if (this.saveState === 'conflict') {
      return;
    }
    if (
      error instanceof EditorApiError
      && (error.status === 409 || error.code === 'conflict')
    ) {
      this.handleSaveConflict();
      return;
    }
    this.saveError = error instanceof Error ? error.message : this.s('editor:error:request');
    this.setSaveState('error');
    this.showToast(this.saveError, true);
  }

  private handleSaveConflict(): void {
    this.saveError = this.s('editor:conflict:message');
    this.setSaveState('conflict');
    if (this.autosaveTimer !== null) {
      window.clearTimeout(this.autosaveTimer);
      this.autosaveTimer = null;
    }
    if (this.conflictDialog?.open) {
      return;
    }
    const parts = openDialog(
      this.s('editor:conflict:title'),
      this.s('editor:conflict:stay'),
      'quizgeist-conflict-dialog',
    );
    this.conflictDialog = parts.dialog;
    parts.body.append(element('p', 'quizgeist-dialog__intro', {
      text: this.s('editor:conflict:message'),
    }));
    const stay = button(
      this.s('editor:conflict:stay'),
      'quizgeist-button quizgeist-button--secondary',
      () => closeDialog(parts.dialog),
    );
    const reload = button(
      this.s('editor:conflict:reload'),
      'quizgeist-button quizgeist-button--primary',
      () => void this.reloadAfterConflict(parts.dialog),
    );
    parts.footer.append(stay, reload);
    parts.dialog.addEventListener('close', () => {
      if (this.conflictDialog === parts.dialog) {
        this.conflictDialog = null;
      }
    }, {once: true});
  }

  private async reloadAfterConflict(dialog = this.conflictDialog): Promise<void> {
    if (dialog) {
      closeDialog(dialog);
    }
    this.conflictDialog = null;
    this.setSaveState('saving');
    this.renderLoading();
    await this.loadBootstrap(true);
  }

  private finishEditorOperation(): void {
    if (this.saveState === 'error' || this.saveState === 'conflict') {
      return;
    }
    if (this.hasUnsavedChanges()) {
      this.setSaveState('dirty');
      this.scheduleAutosave();
      return;
    }
    this.setSaveState('saved');
  }

  private updateSaveStatusElement(): void {
    if (!this.saveStatusElement) {
      return;
    }
    this.saveStatusElement.replaceChildren();
    this.saveStatusElement.dataset.state = this.saveState;
    const dot = element('span', 'quizgeist-save-status__dot', {'aria-hidden': 'true'});
    const text = element('span', '', {
      text: this.s(`editor:save:${this.saveState}`),
    });
    this.saveStatusElement.append(dot, text);
    if (this.saveState === 'error' || this.saveState === 'conflict') {
      const retry = button(
        this.s(this.saveState === 'conflict'
          ? 'editor:conflict:reload'
          : 'editor:action:retry'),
        'quizgeist-save-status__retry',
        () => {
          if (this.saveState === 'conflict') {
            void this.reloadAfterConflict();
          } else {
            this.setSaveState('dirty');
            void this.runSaveLoop();
          }
        },
      );
      this.saveStatusElement.append(retry);
      this.saveStatusElement.title = this.saveError;
    } else {
      this.saveStatusElement.removeAttribute('title');
    }
  }

  private applyAppearance(): void {
    this.root.dataset.quizgeistTheme = this.activity.theme || 'hell';
    this.root.dataset.quizgeistSeason = this.activity.season || 'herbst';
    document.querySelectorAll<HTMLDialogElement>('dialog.quizgeist-dialog[data-quizgeist-root]')
      .forEach((dialog) => {
        if (dialog.dataset.quizgeistOwner && dialog.dataset.quizgeistOwner !== this.root.id) {
          return;
        }
        dialog.dataset.quizgeistTheme = this.root.dataset.quizgeistTheme;
        dialog.dataset.quizgeistSeason = this.root.dataset.quizgeistSeason;
      });
  }

  private themeSelect(): HTMLSelectElement {
    const select = element('select', 'quizgeist-select quizgeist-theme-select');
    for (const theme of this.availableThemes) {
      select.append(element('option', '', {
        value: theme,
        text: this.s(`theme:${theme === 'retro-arcade' ? 'retroarcade' : theme}`),
      }));
    }
    select.value = this.activity.theme;
    select.addEventListener('change', () => {
      this.activity.theme = select.value;
      this.markActivityDirty();
      this.render();
    });
    return select;
  }

  private seasonSelect(): HTMLSelectElement {
    const select = element('select', 'quizgeist-select quizgeist-season-select');
    for (const season of this.availableSeasons) {
      select.append(element('option', '', {
        value: season,
        text: this.s(`editor:season:${season}`),
      }));
    }
    select.value = this.activity.season;
    select.addEventListener('change', () => {
      this.activity.season = select.value;
      this.markActivityDirty();
    });
    return select;
  }

  private openAppearanceDialog(): void {
    const parts = openDialog(
      this.s('editor:appearance:title'),
      this.s('editor:action:close'),
      'quizgeist-appearance-dialog',
    );
    const theme = this.themeSelect();
    const season = this.seasonSelect();
    const fields = element('div', 'quizgeist-field-grid');
    fields.append(labelledField(this.s('editor:field:theme'), theme));
    if (this.availableThemes.includes('jahreszeiten')) {
      fields.append(labelledField(this.s('editor:field:season'), season));
    }
    parts.body.append(fields);
    const mediaGrid = element('div', 'quizgeist-appearance-media');
    mediaGrid.append(
      this.appearanceMediaCard(
        'background',
        this.s('editor:appearance:background'),
        this.activity.background[0],
      ),
      this.appearanceMediaCard(
        'logo',
        this.s('editor:appearance:logo'),
        this.activity.logo[0],
      ),
    );
    parts.body.append(mediaGrid);
    parts.footer.append(button(
      this.s('editor:action:done'),
      'quizgeist-button quizgeist-button--primary',
      () => closeDialog(parts.dialog),
    ));
  }

  private appearanceMediaCard(
    area: 'background' | 'logo',
    title: string,
    file?: MediaFile,
  ): HTMLElement {
    const card = element('section', 'quizgeist-appearance-media__card');
    card.append(element('h3', '', {text: title}));
    if (file) {
      card.append(
        element('img', 'quizgeist-appearance-media__preview', {
          src: file.url,
          alt: file.filename,
        }),
        element('p', 'quizgeist-appearance-media__filename', {text: file.filename}),
      );
    } else {
      card.append(element('div', 'quizgeist-media-empty', {
        text: this.s('editor:appearance:nofile'),
      }));
    }
    card.append(button(
      this.s('editor:action:choosefile'),
      'quizgeist-button quizgeist-button--secondary',
      () => void this.openMediaDialog(area, 0, 'appearance'),
    ));
    return card;
  }

  private handleMediaSavedMessage(message: unknown): void {
    const dialogQuestionId = this.mediaDialogQuestionId;
    if (this.mediaDialog) {
      closeDialog(this.mediaDialog);
      this.mediaDialog = null;
    }
    this.mediaDialogQuestionId = null;

    const payload = this.record(message);
    if (payload.area !== 'questionmedia' || !this.isRecord(payload.question)) {
      void this.loadBootstrap(true);
      return;
    }
    const normalized = normalizeQuestion(payload.question);
    if (normalized.id <= 0) {
      void this.loadBootstrap(true);
      return;
    }
    const explicitPreviousId = Number(payload.oldquestionid || 0);
    const messageItemId = Number(payload.itemid || 0);
    const previousId = Number.isInteger(explicitPreviousId) && explicitPreviousId > 0
      ? explicitPreviousId
      : dialogQuestionId || messageItemId;
    const resolvedPreviousId = this.resolveQuestionId(previousId);
    const localQuestion = this.questions.find((question) => (
      question.id === resolvedPreviousId || question.id === normalized.id
    ));
    if (!localQuestion) {
      void this.loadBootstrap(true);
      return;
    }
    const dirty = (this.questionDirtyVersions.get(localQuestion.id) || 0)
      > (this.questionSavedVersions.get(localQuestion.id) || 0);
    this.migrateQuestionId(localQuestion, localQuestion.id, normalized.id);
    if (!dirty) {
      this.mergeSavedQuestion(localQuestion, normalized);
    } else {
      // The picker committed authoritative server state while local text edits
      // remained dirty. Preserve those edits, but advance the concurrency token
      // and media references so the ensuing autosave neither self-conflicts nor
      // sends a stale `media: null` view.
      this.mergeAuthoritativeMedia(localQuestion, normalized);
    }
    this.renumberQuestionSortorders();
    this.render();
  }

  private mergeAuthoritativeMedia(
    target: EditorQuestion,
    source: EditorQuestion,
  ): void {
    target.rootid = source.rootid;
    target.version = source.version;
    target.timemodified = source.timemodified;
    target.files = source.files;

    const targetOptions = this.record(target.options);
    const sourceOptions = this.record(source.options);
    targetOptions.media = typeof sourceOptions.media === 'string'
      ? sourceOptions.media
      : null;
    for (const collection of ['answers', 'items']) {
      const targetRows = Array.isArray(targetOptions[collection])
        ? targetOptions[collection] as unknown[]
        : [];
      const sourceRows = Array.isArray(sourceOptions[collection])
        ? sourceOptions[collection] as unknown[]
        : [];
      const sourceById = new Map(sourceRows.map((entry) => {
        const row = this.record(entry);
        return [String(row.id || ''), row];
      }));
      targetRows.forEach((entry) => {
        const row = this.record(entry);
        const canonical = sourceById.get(String(row.id || ''));
        row.media = canonical && typeof canonical.media === 'string'
          ? canonical.media
          : null;
      });
    }
  }

  private async openMediaDialog(
    area: 'questionmedia' | 'background' | 'logo',
    itemId: number,
    target: string,
  ): Promise<void> {
    if (!this.config.mediaUrl) {
      this.showToast(this.s('editor:media:unavailable'), true);
      return;
    }
    try {
      await this.flushPendingSaves();
    } catch (error) {
      this.showToast(
        error instanceof Error ? error.message : this.s('editor:error:request'),
        true,
      );
      return;
    }
    const resolvedItemId = area === 'questionmedia'
      ? this.resolveQuestionId(itemId)
      : itemId;
    const parts = openDialog(
      this.s(`editor:media:title:${area === 'questionmedia' ? 'question' : area}`),
      this.s('editor:action:close'),
      'quizgeist-media-dialog',
    );
    this.mediaDialog = parts.dialog;
    this.mediaDialogQuestionId = area === 'questionmedia' ? resolvedItemId : null;
    const url = new URL(this.config.mediaUrl, window.location.href);
    url.searchParams.set('id', String(this.config.cmid));
    url.searchParams.set('area', area);
    url.searchParams.set('itemid', String(resolvedItemId));
    url.searchParams.set('target', target);
    const iframe = element('iframe', 'quizgeist-media-dialog__frame', {
      src: url.toString(),
      title: this.s('editor:media:iframe:title'),
      loading: 'eager',
    });
    parts.body.append(iframe);
    parts.dialog.addEventListener('close', () => {
      if (this.mediaDialog === parts.dialog) {
        this.mediaDialog = null;
      }
      this.mediaDialogQuestionId = null;
    }, {once: true});
  }

  private openPreview(): void {
    const question = this.selectedQuestion();
    if (!question) {
      this.showToast(this.s('editor:preview:noquestion'), true);
      return;
    }
    const parts = openDialog(
      this.s('editor:preview:title'),
      this.s('editor:action:close'),
      'quizgeist-preview-dialog',
    );
    const preview = element('article', 'quizgeist-question-preview');
    const background = this.activity.background[0];
    if (background) {
      preview.append(element('img', 'quizgeist-question-preview__background', {
        src: background.url,
        alt: '',
        'aria-hidden': 'true',
      }));
    }
    const header = element('header', 'quizgeist-question-preview__header');
    const logo = this.activity.logo[0];
    header.append(element('img', 'quizgeist-question-preview__logo', {
      src: logo?.url || this.config.brandIconUrl,
      alt: this.s(logo ? 'editor:logo:schoolalt' : 'editor:logo:alt'),
    }));
    header.append(element('span', 'quizgeist-question-preview__type', {
      text: this.s(`editor:qtype:${question.qtype}`),
    }));
    preview.append(header);

    const content = element('div', 'quizgeist-question-preview__content');
    const questionHeading = element('h3', 'quizgeist-question-preview__question', {
      text: this.questionTitle(question),
    });
    content.append(questionHeading);
    content.append(this.renderTtsControls(question, parts.dialog));
    if (question.qtype !== 'pin' && question.qtype !== 'slide') {
      const mainMedia = this.questionMedia(question);
      if (mainMedia) {
        content.append(this.renderMediaElement(
          mainMedia,
          'quizgeist-question-preview__media',
        ));
      }
    }
    content.append(this.renderPreviewResponse(question));
    if (question.explanation.trim() !== '') {
      const details = element('details', 'quizgeist-question-preview__explanation');
      details.append(
        element('summary', '', {text: this.s('editor:field:explanation')}),
        element('p', '', {text: question.explanation}),
      );
      content.append(details);
    }
    preview.append(content);
    parts.body.append(preview);
    parts.footer.append(button(
      this.s('editor:action:closepreview'),
      'quizgeist-button quizgeist-button--primary',
      () => closeDialog(parts.dialog),
    ));
    parts.dialog.addEventListener('close', () => this.tts.stop(), {once: true});
  }

  private renderTtsControls(
    question: EditorQuestion,
    dialog: HTMLDialogElement,
  ): HTMLElement {
    const wrapper = element('div', 'quizgeist-tts-controls');
    const play = button(
      this.s('editor:tts:play'),
      'quizgeist-button quizgeist-button--secondary quizgeist-tts-button',
    );
    play.setAttribute('aria-pressed', 'false');
    play.disabled = !this.tts.isAvailable();
    if (!this.tts.isAvailable()) {
      play.title = this.s('editor:tts:unavailable');
    }
    const voices = element('select', 'quizgeist-select quizgeist-tts-voice', {
      'aria-label': this.s('editor:tts:voice'),
      disabled: !this.tts.isAvailable(),
    });
    this.config.tts.voices.forEach((voice) => {
      voices.append(element('option', '', {
        value: voice.id,
        text: voice.label,
      }));
    });
    voices.value = String(this.config.tts.defaultVoiceId || this.config.tts.voices[0]?.id || '');
    play.addEventListener('click', async() => {
      const active = play.getAttribute('aria-pressed') === 'true';
      if (active) {
        this.tts.stop();
        play.setAttribute('aria-pressed', 'false');
        play.textContent = this.s('editor:tts:play');
        return;
      }
      play.setAttribute('aria-pressed', 'true');
      play.setAttribute('aria-busy', 'true');
      play.textContent = this.s('editor:tts:loading');
      try {
        await this.tts.play(this.ttsText(question), Number(voices.value));
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
          this.showToast(
            error instanceof Error ? error.message : this.s('editor:tts:error'),
            true,
          );
        }
      } finally {
        if (dialog.open) {
          play.setAttribute('aria-pressed', 'false');
          play.setAttribute('aria-busy', 'false');
          play.textContent = this.s('editor:tts:play');
        }
      }
    });
    wrapper.append(play, voices);
    return wrapper;
  }

  private ttsText(question: EditorQuestion): string {
    if (question.qtype !== 'slide') {
      return question.questiontext;
    }
    const options = question.options as SlideOptions;
    return [
      options.title,
      options.body,
      options.quote,
      options.attribution,
      ...options.bullets,
    ].filter(Boolean).join('. ');
  }

  private questionMedia(question: EditorQuestion): MediaFile | null {
    const media = (question.options as {media: string | null}).media;
    if (!media) {
      return null;
    }
    return question.files.find((file) => (
      file.path === media
      || file.filename === media
      || `${file.filepath === '/' ? '' : file.filepath || ''}${file.filename}` === media
      || file.url === media
    )) || null;
  }

  private renderPreviewResponse(question: EditorQuestion): HTMLElement {
    const response = element('div', `quizgeist-preview-response quizgeist-preview-response--${question.qtype}`);
    const options = question.options as unknown as RawRecord;
    if (question.qtype === 'quiz' || question.qtype === 'poll') {
      const answers = Array.isArray(options.answers) ? options.answers : [];
      const grid = element('div', 'quizgeist-preview-answers');
      answers.forEach((raw, index) => {
        const answer = this.record(raw);
        const tile = element('div', `quizgeist-preview-answer quizgeist-preview-answer--${index + 1}`);
        tile.append(
          element('span', `quizgeist-answer-shape quizgeist-answer-shape--${index + 1}`, {
            'aria-hidden': 'true',
          }),
          element('span', '', {
            text: String(answer.text || this.s('editor:preview:emptyanswer')),
          }),
        );
        const answerMedia = this.mediaForPath(question, String(answer.media || ''));
        if (answerMedia) {
          tile.append(this.renderMediaElement(
            answerMedia,
            'quizgeist-preview-answer__media',
          ));
        }
        grid.append(tile);
      });
      response.append(grid);
    } else if (question.qtype === 'truefalse') {
      const grid = element('div', 'quizgeist-preview-answers');
      [this.s('editor:true'), this.s('editor:false')].forEach((label, index) => {
        const tile = element('div', `quizgeist-preview-answer quizgeist-preview-answer--${index === 0 ? 1 : 4}`);
        tile.append(
          element('span', `quizgeist-answer-shape quizgeist-answer-shape--${index === 0 ? 1 : 4}`, {
            'aria-hidden': 'true',
          }),
          element('span', '', {text: label}),
        );
        grid.append(tile);
      });
      response.append(grid);
    } else if (question.qtype === 'shortanswer'
        || question.qtype === 'wordcloud'
        || question.qtype === 'open'
        || question.qtype === 'reveal') {
      response.append(element('textarea', 'quizgeist-textarea', {
        rows: question.qtype === 'open' ? 5 : 2,
        disabled: true,
        placeholder: this.s('editor:preview:typeanswer'),
      }));
    } else if (question.qtype === 'puzzle') {
      const list = element('ol', 'quizgeist-preview-puzzle');
      const items = Array.isArray(options.items) ? options.items : [];
      items.forEach((raw, index) => {
        const item = this.record(raw);
        const listItem = element('li');
        listItem.append(element('span', '', {
          text: `${index + 1}. ${String(item.text || this.s('editor:preview:emptyanswer'))}`,
        }));
        const itemMedia = this.mediaForPath(question, String(item.media || ''));
        if (itemMedia) {
          listItem.append(this.renderMediaElement(
            itemMedia,
            'quizgeist-preview-puzzle__media',
          ));
        }
        list.append(listItem);
      });
      response.append(list);
    } else if (question.qtype === 'scale') {
      const scale = element('div', 'quizgeist-preview-scale');
      const steps = Number(options.steps || 5);
      for (let value = 1; value <= steps; value++) {
        scale.append(element('span', '', {text: String(value)}));
      }
      response.append(scale);
    } else if (question.qtype === 'slider') {
      response.append(
        element('output', 'quizgeist-preview-slider__value', {
          text: String(options.target || 0),
        }),
        element('input', 'quizgeist-preview-slider', {
          type: 'range',
          min: Number(options.min || 0),
          max: Number(options.max || 100),
          step: Number(options.step || 1),
          value: Number(options.target || 0),
          disabled: true,
        }),
      );
    } else if (question.qtype === 'pin') {
      const media = this.questionMedia(question);
      const target = this.record(options.target);
      if (media) {
        const pin = element('div', 'quizgeist-preview-pin');
        pin.append(
          element('img', '', {src: media.url, alt: media.filename}),
          element('span', 'quizgeist-preview-pin__marker', {'aria-hidden': 'true'}),
        );
        const marker = pin.lastElementChild as HTMLElement;
        marker.style.left = `${Number(target.x || 50)}%`;
        marker.style.top = `${Number(target.y || 50)}%`;
        response.append(pin);
      }
    } else if (question.qtype === 'brainstorm') {
      response.append(element('div', 'quizgeist-info-box', {
        text: this.s('editor:preview:brainstorm'),
      }));
    } else if (question.qtype === 'slide') {
      const slide = question.options as SlideOptions;
      const slideCard = element('section', `quizgeist-preview-slide quizgeist-preview-slide--${slide.layout}`);
      if (slide.layout === 'quote') {
        slideCard.append(
          element('blockquote', '', {text: slide.quote}),
          element('cite', '', {text: slide.attribution}),
        );
      } else if (slide.layout === 'bullets') {
        const list = element('ul');
        slide.bullets.forEach((bullet) => list.append(element('li', '', {text: bullet})));
        slideCard.append(list);
      } else {
        slideCard.append(element('p', '', {text: slide.body}));
      }
      const slideMedia = this.questionMedia(question);
      if (slideMedia) {
        slideCard.append(this.renderMediaElement(
          slideMedia,
          'quizgeist-preview-slide__media',
        ));
      }
      response.append(slideCard);
    }
    return response;
  }

  private renderTemplateLibrary(): void {
    const shell = element('section', 'quizgeist-template-library');
    const header = element('header', 'quizgeist-template-library__header');
    const headingBlock = element('div');
    headingBlock.append(
      element('p', 'quizgeist-eyebrow', {text: this.s('editor:templates:eyebrow')}),
      element('h2', '', {text: this.s('editor:templates:title')}),
      element('p', '', {text: this.s('editor:templates:description')}),
    );
    const actions = element('div', 'quizgeist-template-library__actions');
    actions.append(
      button(
        this.s('editor:action:backtoeditor'),
        'quizgeist-button quizgeist-button--secondary',
        () => {
          this.view = 'editor';
          this.render();
        },
      ),
      button(
        this.s('editor:action:publish'),
        'quizgeist-button quizgeist-button--primary',
        () => this.openPublishDialog(),
      ),
    );
    header.append(headingBlock, actions);
    shell.append(header);

    const searchForm = element('form', 'quizgeist-template-search', {
      role: 'search',
    });
    const search = element('input', 'quizgeist-input', {
      type: 'search',
      placeholder: this.s('editor:templates:searchplaceholder'),
      'aria-label': this.s('editor:templates:searchlabel'),
    });
    const submit = button(
      this.s('editor:action:search'),
      'quizgeist-button quizgeist-button--primary',
    );
    submit.type = 'submit';
    searchForm.append(search, submit);
    searchForm.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.searchTemplates(search.value);
    });
    search.addEventListener('input', () => {
      if (this.templateSearchTimer !== null) {
        window.clearTimeout(this.templateSearchTimer);
      }
      this.templateSearchTimer = window.setTimeout(() => {
        this.templateSearchTimer = null;
        void this.searchTemplates(search.value);
      }, 300);
    });
    shell.append(searchForm);
    const results = element('div', 'quizgeist-template-results', {
      'aria-live': 'polite',
    });
    results.dataset.region = 'template-results';
    shell.append(results);
    this.root.append(shell);
    void this.searchTemplates('');
  }

  private async searchTemplates(query: string): Promise<void> {
    const results = this.root.querySelector<HTMLElement>('[data-region="template-results"]');
    if (!results) {
      return;
    }
    this.templateSearchController?.abort();
    this.templateSearchController = new AbortController();
    results.replaceChildren(element('div', 'quizgeist-editor-loading', {
      text: this.s('editor:templates:loading'),
      role: 'status',
    }));
    try {
      const data = await this.api.post<unknown>(
        'template_search',
        {query},
        this.templateSearchController.signal,
      );
      const templates = this.extractTemplates(data);
      results.replaceChildren();
      if (templates.length === 0) {
        results.append(element('div', 'quizgeist-template-empty', {
          text: this.s('editor:templates:empty'),
        }));
        return;
      }
      const grid = element('div', 'quizgeist-template-grid');
      templates.forEach((template) => grid.append(this.renderTemplateCard(template)));
      results.append(grid);
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        return;
      }
      results.replaceChildren(element('div', 'quizgeist-editor-error', {
        role: 'alert',
        text: error instanceof Error ? error.message : this.s('editor:error:request'),
      }));
    }
  }

  private extractTemplates(data: unknown): TemplateSummary[] {
    const source = this.record(data);
    const raw = Array.isArray(source.templates) ? source.templates : [];
    return raw.map((entry) => {
      const template = this.record(entry);
      return {
        id: Number(template.id || 0),
        name: String(template.name || ''),
        description: String(template.description || ''),
        tags: Array.isArray(template.tags)
          ? template.tags.map((tag) => String(tag))
          : [],
        theme: String(template.theme || 'hell'),
        questionCount: Number(template.questionCount || 0),
        timemodified: Number(template.timemodified || 0),
        canDelete: template.canDelete === true,
      };
    }).filter((template) => template.id > 0 && template.name !== '');
  }

  private renderTemplateCard(template: TemplateSummary): HTMLElement {
    const card = element('article', 'quizgeist-template-card');
    card.dataset.theme = template.theme;
    const visual = element('div', 'quizgeist-template-card__visual', {'aria-hidden': 'true'});
    visual.append(this.questionTypeIcon('quiz'));
    const content = element('div', 'quizgeist-template-card__content');
    content.append(
      element('h3', '', {text: template.name}),
      element('p', '', {
        text: template.description || this.s('editor:templates:nodescription'),
      }),
    );
    const metadataItems: string[] = [];
    if (template.questionCount) {
      metadataItems.push(
        this.s('editor:templates:questioncount').replace(
          '{$a}',
          String(template.questionCount),
        ),
      );
    }
    content.append(element('p', 'quizgeist-template-card__meta', {
      text: metadataItems.join(' \u00b7 '),
    }));
    if (template.tags.length > 0) {
      const tagList = element('div', 'quizgeist-template-card__tags');
      template.tags.slice(0, 5)
        .forEach((tag) => tagList.append(element('span', '', {text: tag})));
      content.append(tagList);
    }
    const actions = element('div', 'quizgeist-template-card__actions');
    actions.append(
      button(
        this.s('editor:templates:append'),
        'quizgeist-button quizgeist-button--secondary',
        () => this.openImportDialog(template, 'append'),
      ),
      button(
        this.s('editor:templates:replace'),
        'quizgeist-button quizgeist-button--primary',
        () => this.openImportDialog(template, 'replace'),
      ),
    );
    if (template.canDelete) {
      actions.append(button(
        this.s('editor:action:delete'),
        'quizgeist-button quizgeist-button--danger-quiet',
        () => void this.deleteTemplate(template),
      ));
    }
    content.append(actions);
    card.append(visual, content);
    return card;
  }

  private openPublishDialog(): void {
    const parts = openDialog(
      this.s('editor:publish:title'),
      this.s('editor:action:close'),
      'quizgeist-publish-dialog',
    );
    const name = element('input', 'quizgeist-input', {
      type: 'text',
      value: this.activity.name,
      maxlength: 255,
      required: true,
    });
    const description = element('textarea', 'quizgeist-textarea', {
      rows: 4,
      maxlength: 4000,
    });
    const tags = element('input', 'quizgeist-input', {
      type: 'text',
      placeholder: this.s('editor:publish:tagsplaceholder'),
    });
    parts.body.append(
      labelledField(this.s('editor:publish:name'), name),
      labelledField(this.s('editor:publish:description'), description),
      labelledField(
        this.s('editor:publish:tags'),
        tags,
        this.s('editor:publish:tagshint'),
      ),
    );
    const cancel = button(
      this.s('editor:action:cancel'),
      'quizgeist-button quizgeist-button--secondary',
      () => closeDialog(parts.dialog),
    );
    const publish = button(
      this.s('editor:action:publish'),
      'quizgeist-button quizgeist-button--primary',
    );
    publish.addEventListener('click', async() => {
      if (name.value.trim() === '') {
        name.setAttribute('aria-invalid', 'true');
        name.focus();
        return;
      }
      setButtonBusy(publish, true, this.s('editor:publish:publishing'));
      try {
        await this.flushPendingSaves();
        await this.api.post('template_publish', {
          name: name.value.trim(),
          description: description.value.trim(),
          tags: tags.value.split(',').map((tag) => tag.trim()).filter(Boolean),
        });
        closeDialog(parts.dialog);
        this.showToast(this.s('editor:publish:success'));
        if (this.view === 'templates') {
          void this.searchTemplates('');
        }
      } catch (error) {
        this.showToast(
          error instanceof Error ? error.message : this.s('editor:error:request'),
          true,
        );
        setButtonBusy(publish, false, this.s('editor:action:publish'));
      }
    });
    parts.footer.append(cancel, publish);
  }

  private openImportDialog(template: TemplateSummary, mode: TemplateMode): void {
    const parts = openDialog(
      this.s('editor:import:title'),
      this.s('editor:action:close'),
      'quizgeist-import-dialog',
    );
    parts.body.append(
      element('p', 'quizgeist-dialog__intro', {
        text: this.s(`editor:import:${mode}:description`).replace('{$a}', template.name),
      }),
    );
    const includeLabel = element('label', 'quizgeist-check-field');
    const include = element('input', '', {type: 'checkbox', checked: true});
    appendChildren(
      includeLabel,
      include,
      element('span', '', {text: this.s('editor:import:appearance')}),
    );
    parts.body.append(includeLabel);
    const cancel = button(
      this.s('editor:action:cancel'),
      'quizgeist-button quizgeist-button--secondary',
      () => closeDialog(parts.dialog),
    );
    const importButton = button(
      this.s('editor:action:import'),
      'quizgeist-button quizgeist-button--primary',
    );
    importButton.addEventListener('click', async() => {
      setButtonBusy(importButton, true, this.s('editor:import:working'));
      try {
        await this.flushPendingSaves();
        await this.api.post('template_import', {
          templateid: template.id,
          mode,
          includeappearance: include.checked,
        });
        closeDialog(parts.dialog);
        this.view = 'editor';
        this.renderLoading();
        await this.loadBootstrap();
        this.showToast(this.s('editor:import:success'));
      } catch (error) {
        this.showToast(
          error instanceof Error ? error.message : this.s('editor:error:request'),
          true,
        );
        setButtonBusy(importButton, false, this.s('editor:action:import'));
      }
    });
    parts.footer.append(cancel, importButton);
  }

  private async deleteTemplate(template: TemplateSummary): Promise<void> {
    if (!window.confirm(
      this.s('editor:confirm:deletetemplate').replace('{$a}', template.name),
    )) {
      return;
    }
    try {
      await this.api.post('template_delete', {templateid: template.id});
      this.showToast(this.s('editor:templates:deletedsuccess'));
      await this.searchTemplates('');
    } catch (error) {
      this.showToast(
        error instanceof Error ? error.message : this.s('editor:error:request'),
        true,
      );
    }
  }

  private extractQuestion(data: unknown): unknown {
    const source = this.record(data);
    return source.question || data;
  }

  private extractQuestions(data: unknown): EditorQuestion[] {
    const source = this.record(data);
    if (!Array.isArray(source.questions)) {
      throw new Error(this.s('editor:error:response'));
    }
    const questions = source.questions
      .map((question) => normalizeQuestion(question))
      .filter((question) => question.id > 0)
      .sort((left, right) => left.sortorder - right.sortorder);
    if (
      questions.length !== source.questions.length
      || new Set(questions.map((question) => question.id)).size !== questions.length
    ) {
      throw new Error(this.s('editor:error:response'));
    }
    return questions;
  }

  private extractQuestionIdMap(data: unknown): Map<number, number> {
    const source = this.record(data);
    if (source.questionIdMap === undefined) {
      return new Map();
    }
    if (!this.isRecord(source.questionIdMap)) {
      throw new Error(this.s('editor:error:response'));
    }
    const result = new Map<number, number>();
    Object.entries(source.questionIdMap).forEach(([rawPreviousId, rawNextId]) => {
      const previousId = Number(rawPreviousId);
      const nextId = Number(rawNextId);
      if (
        !Number.isInteger(previousId)
        || previousId <= 0
        || !Number.isInteger(nextId)
        || nextId <= 0
      ) {
        throw new Error(this.s('editor:error:response'));
      }
      result.set(previousId, nextId);
    });
    return result;
  }

  private mergeSavedQuestion(target: EditorQuestion, source: EditorQuestion): void {
    const options: QuestionOptions = target.options;
    this.mergeValueInPlace(options, source.options);
    Object.assign(target, source, {options});
  }

  private mergeValueInPlace(target: unknown, source: unknown): unknown {
    if (Array.isArray(target) && Array.isArray(source)) {
      const available = [...target];
      const merged = source.map((sourceValue, index) => {
        let targetValue = available[index];
        if (this.isRecord(sourceValue) && (
          typeof sourceValue.id === 'string' || typeof sourceValue.id === 'number'
        )) {
          const matched = available.find((candidate) => (
            this.isRecord(candidate) && candidate.id === sourceValue.id
          ));
          if (matched !== undefined) {
            targetValue = matched;
          }
        }
        return this.mergeValueInPlace(targetValue, sourceValue);
      });
      target.splice(0, target.length, ...merged);
      return target;
    }
    if (this.isRecord(target) && this.isRecord(source)) {
      Object.keys(target).forEach((key) => {
        if (!Object.prototype.hasOwnProperty.call(source, key)) {
          delete target[key];
        }
      });
      Object.entries(source).forEach(([key, value]) => {
        target[key] = this.mergeValueInPlace(target[key], value);
      });
      return target;
    }
    return source;
  }

  private migrateQuestionId(
    question: EditorQuestion,
    previousId: number,
    nextId: number,
  ): void {
    if (nextId <= 0) {
      throw new Error(this.s('editor:error:response'));
    }
    if (previousId === nextId) {
      question.id = nextId;
      return;
    }
    if (this.questions.some((candidate) => candidate !== question && candidate.id === nextId)) {
      throw new Error(this.s('editor:error:response'));
    }

    this.questionIdAliases.forEach((target, alias) => {
      if (target === previousId) {
        this.questionIdAliases.set(alias, nextId);
      }
    });
    this.questionIdAliases.set(previousId, nextId);

    const dirtyVersion = Math.max(
      this.questionDirtyVersions.get(previousId) || 0,
      this.questionDirtyVersions.get(nextId) || 0,
    );
    const savedVersion = Math.max(
      this.questionSavedVersions.get(previousId) || 0,
      this.questionSavedVersions.get(nextId) || 0,
    );
    this.questionDirtyVersions.delete(previousId);
    this.questionSavedVersions.delete(previousId);
    if (dirtyVersion > 0) {
      this.questionDirtyVersions.set(nextId, dirtyVersion);
    }
    if (savedVersion > 0) {
      this.questionSavedVersions.set(nextId, savedVersion);
    }

    question.id = nextId;
    if (this.selectedQuestionId === previousId) {
      this.selectedQuestionId = nextId;
    }
    if (this.draggedQuestionId === previousId) {
      this.draggedQuestionId = nextId;
    }
    this.root
      .querySelectorAll<HTMLElement>(`[data-question-id="${previousId}"]`)
      .forEach((node) => {
        node.dataset.questionId = String(nextId);
      });
  }

  private resolveQuestionId(questionId: number): number {
    let resolved = questionId;
    const visited = new Set<number>();
    while (this.questionIdAliases.has(resolved) && !visited.has(resolved)) {
      visited.add(resolved);
      resolved = this.questionIdAliases.get(resolved) as number;
    }
    return resolved;
  }

  private async reconcileCanonicalQuestions(
    canonical: EditorQuestion[],
    requestedIds: number[],
    questionIdMap: Map<number, number>,
    applyCanonicalOrder: boolean,
  ): Promise<boolean> {
    if (canonical.length !== requestedIds.length) {
      await this.loadBootstrap(true);
      return false;
    }
    questionIdMap.forEach((nextId, previousId) => {
      const resolvedPreviousId = this.resolveQuestionId(previousId);
      const localQuestion = this.questions.find((question) => question.id === resolvedPreviousId);
      if (!localQuestion) {
        throw new Error(this.s('editor:error:response'));
      }
      this.migrateQuestionId(localQuestion, resolvedPreviousId, nextId);
    });

    const requestedQuestions: EditorQuestion[] = [];
    canonical.forEach((serverQuestion, index) => {
      const requestedId = this.resolveQuestionId(requestedIds[index]);
      const localQuestion = this.questions.find((question) => (
        question.id === requestedId || question.id === serverQuestion.id
      ));
      if (!localQuestion) {
        throw new Error(this.s('editor:error:response'));
      }
      const dirty = (this.questionDirtyVersions.get(localQuestion.id) || 0)
        > (this.questionSavedVersions.get(localQuestion.id) || 0);
      const previousId = localQuestion.id;
      this.migrateQuestionId(localQuestion, previousId, serverQuestion.id);
      if (!dirty) {
        this.mergeSavedQuestion(localQuestion, serverQuestion);
      }
      requestedQuestions.push(localQuestion);
    });

    if (applyCanonicalOrder) {
      this.questions.splice(0, this.questions.length, ...requestedQuestions);
    }
    this.renumberQuestionSortorders();
    this.questions.forEach((question) => {
      this.refreshQuestionTitle(question);
      this.refreshQuestionStatus(question);
    });
    return true;
  }

  private extractActivity(data: unknown): unknown {
    const source = this.record(data);
    return source.activity || null;
  }

  private mediaForPath(question: EditorQuestion, path: string): MediaFile | null {
    if (path === '') {
      return null;
    }
    return question.files.find((file) => (
      file.path === path
      || file.filename === path
      || `${file.filepath === '/' ? '' : file.filepath || ''}${file.filename}` === path
      || file.url === path
    )) || null;
  }

  private renderMediaElement(file: MediaFile, className: string): HTMLElement {
    const mimetype = file.mimetype || '';
    const extension = file.filename.split('.').pop()?.toLowerCase() || '';
    if (mimetype.startsWith('audio/') || ['mp3', 'ogg', 'wav'].includes(extension)) {
      return element('audio', className, {
        src: file.url,
        controls: true,
        preload: 'metadata',
        'aria-label': file.filename,
      });
    }
    if (mimetype.startsWith('video/') || ['mp4', 'webm'].includes(extension)) {
      return element('video', className, {
        src: file.url,
        controls: true,
        preload: 'metadata',
        playsinline: true,
        'aria-label': file.filename,
      });
    }
    return element('img', className, {
      src: file.url,
      alt: file.filename,
      loading: 'lazy',
    });
  }

  private record(value: unknown): RawRecord {
    return this.isRecord(value) ? value : {};
  }

  private isRecord(value: unknown): value is RawRecord {
    return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
  }

  private showToast(message: string, error = false): void {
    if (!this.toastRegion) {
      return;
    }
    const toast = element('div', `quizgeist-toast${error ? ' quizgeist-toast--error' : ''}`, {
      role: error ? 'alert' : 'status',
    });
    const text = element('span', '', {text: message});
    const close = button(
      this.s('editor:action:close'),
      'quizgeist-toast__close',
      () => toast.remove(),
    );
    close.setAttribute('aria-label', this.s('editor:action:close'));
    toast.append(text, close);
    this.toastRegion.append(toast);
    window.setTimeout(() => toast.remove(), 6000);
  }

  private announce(message: string): void {
    if (!this.statusLiveElement) {
      return;
    }
    this.statusLiveElement.textContent = '';
    window.setTimeout(() => {
      if (this.statusLiveElement) {
        this.statusLiveElement.textContent = message;
      }
    }, 20);
  }
}
