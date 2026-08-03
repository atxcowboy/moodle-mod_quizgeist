import {answerPayload} from '../live/answer';
import {liveElement} from '../live/dom';
import {createLiveMedia} from '../live/media';
import {
  questionSpeechText,
  renderLiveResponse,
  renderQuestionSolution,
} from '../live/qtype/registry';
import type {UploadedClip} from '../live/recorder';
import {createTtsControl, TtsPlayer} from '../live/tts';
import {SpeakingTrainer, type SpeakingTurnResult} from './speaking';
import type {LiveAnswer, LiveQuestion} from '../live/types';
import {SelfStudyApi} from './api';
import {
  normaliseDueCard,
  renderDueCard,
  renderInterleavingHint,
  type DueCard,
} from './schedule';
import type {
  AssignmentSummary,
  GradeSummary,
  QuestionResult,
  SelfStudyAttemptState,
  SelfStudyConfig,
  SelfStudyOverview,
} from './types';
import {renderPeerList} from '../workshop/peer-list';
import {
  normaliseWorkshopView,
  readFieldErrors,
  renderStudentForm,
  showFormErrors,
  submitPayload,
  type WorkshopRating,
  type WorkshopView,
} from '../workshop/student-form';
import {
  deadlineBadge,
  errorCard,
  formatDate,
  icon,
  loadingCard,
  modeLabel,
  studyButton,
  studyText,
} from './ui';

export class StudentSelfStudyApp {
  private readonly api: SelfStudyApi;
  private answerDraft: LiveAnswer | null = null;
  private busy = false;
  private readonly liveRegion: HTMLDivElement;
  private dueCard: DueCard | null = null;
  private workshop: WorkshopView | null = null;
  private overview: SelfStudyOverview | null = null;
  private readonly stage: HTMLDivElement;
  private state: SelfStudyAttemptState | null = null;
  private readonly tts: TtsPlayer;

  /** F12: einmal gebaut und wiederverwendet, damit ein Neuzeichnen keine
      laufende Aufnahme abbricht und das Mikrofon nicht erneut erfragt. */
  private speaking: SpeakingTrainer | null = null;

  public constructor(
    private readonly root: HTMLElement,
    private readonly config: SelfStudyConfig,
  ) {
    this.api = new SelfStudyApi(config);
    this.tts = new TtsPlayer(config);
    this.liveRegion = liveElement('div', 'quizgeist-live-visually-hidden', {
      'aria-atomic': 'true',
      'aria-live': 'polite',
      role: 'status',
    });
    this.stage = liveElement('div', 'quizgeist-study-stage');
  }

  public async init(): Promise<void> {
    this.root.classList.add('quizgeist-study-root');
    this.root.dataset.quizgeistRoot = 'selfstudy';
    this.root.dataset.quizgeistTheme = this.config.theme || 'hell';
    this.root.dataset.quizgeistSeason = this.config.season || 'herbst';
    this.root.replaceChildren(this.liveRegion, this.stage);
    if (this.config.attemptId > 0) {
      await this.loadAttempt(this.config.attemptId);
    } else if (this.config.assignmentId > 0) {
      await this.startAssignment(this.config.assignmentId);
    } else {
      await this.loadOverview();
    }
  }

  private text(
    key: string,
    fallback: string,
    values: Record<string, string | number> = {},
  ): string {
    return studyText(this.config, key, fallback, values);
  }

  private replaceStage(content: HTMLElement, focus = true): void {
    this.stage.replaceChildren(content);
    if (focus) {
      window.setTimeout(() => {
        content.querySelector<HTMLElement>('[data-study-heading]')?.focus();
      }, 0);
    }
  }

  private announce(message: string): void {
    this.liveRegion.textContent = '';
    window.requestAnimationFrame(() => {
      this.liveRegion.textContent = message;
    });
  }

  private async loadOverview(): Promise<void> {
    this.state = null;
    this.answerDraft = null;
    this.busy = true;
    this.replaceStage(loadingCard(this.config, 'selfstudy:loading:overview'), false);
    try {
      this.overview = await this.api.overview();
      // F3: die Faelligkeitskarte ist eine zweite, unabhaengige Auskunft.
      // Faellt sie aus, bleibt der Lernbereich vollstaendig bedienbar.
      try {
        this.dueCard = normaliseDueCard(await this.api.dueCard());
      } catch (_error) {
        this.dueCard = null;
      }
      // F7: die Fragenwerkstatt ist ebenfalls eine unabhaengige Auskunft.
      // Fehlt die Aktion oder die Berechtigung, verschwindet der Bereich —
      // statt eine verschlossene Tuer mit Schild zu zeigen.
      try {
        this.workshop = normaliseWorkshopView(await this.api.workshop());
      } catch (_error) {
        this.workshop = null;
      }
      this.busy = false;
      this.renderOverview();
      this.updateLocation();
    } catch (error) {
      this.busy = false;
      this.replaceStage(errorCard(
        this.config,
        this.api.errorMessage(error),
        () => void this.loadOverview(),
      ));
    }
  }

  private renderOverview(): void {
    const overview = this.overview;
    if (!overview) {
      return;
    }
    const main = liveElement('main', 'quizgeist-study-overview');
    const header = liveElement('header', 'quizgeist-study-overview__header');
    const heading = liveElement('h2', 'quizgeist-study-title', {
      text: this.text('selfstudy:overview:title', 'Dein Lernbereich'),
    });
    heading.dataset.studyHeading = '';
    heading.tabIndex = -1;
    header.append(
      heading,
      liveElement('p', 'quizgeist-study-copy', {
        text: this.text(
          'selfstudy:overview:description',
          'Lerne in deinem Tempo und behalte deine Ziele im Blick.',
        ),
      }),
    );
    main.append(
      header,
      this.renderWeeklyGoal(overview),
      this.renderGradeSummary(overview.gradeSummary),
    );
    if (this.dueCard !== null) {
      main.append(renderDueCard(
        this.dueCard,
        (key, fallback, values) => this.text(key, fallback, values),
        formatDate,
      ));
    }

    const openSection = liveElement('section', 'quizgeist-study-section', {
      'aria-labelledby': 'quizgeist-study-open-heading',
    });
    openSection.append(liveElement('h3', 'quizgeist-study-section__title', {
      id: 'quizgeist-study-open-heading',
      text: this.text('selfstudy:overview:open', 'Offene Zuweisungen'),
    }));
    if (overview.openAssignments.length === 0) {
      openSection.append(this.renderEmptyState());
    } else {
      const grid = liveElement('div', 'quizgeist-study-assignment-grid');
      overview.openAssignments.forEach((assignment) => {
        grid.append(this.renderAssignmentCard(
          assignment,
          overview.serverTimeMs,
          false,
        ));
      });
      openSection.append(grid);
    }
    main.append(openSection);
    if (this.workshop !== null) {
      main.append(this.renderWorkshop(this.workshop));
    }

    if (overview.completedAssignments.length > 0) {
      const completed = liveElement('details', 'quizgeist-study-completed');
      const summary = liveElement('summary', 'quizgeist-study-completed__summary');
      summary.append(
        icon('check'),
        document.createTextNode(this.text(
          'selfstudy:overview:completed',
          'Erledigt ({$count})',
          {count: overview.completedAssignments.length},
        )),
      );
      const grid = liveElement('div', 'quizgeist-study-assignment-grid');
      overview.completedAssignments.forEach((assignment) => {
        grid.append(this.renderAssignmentCard(
          assignment,
          overview.serverTimeMs,
          true,
        ));
      });
      completed.append(summary, grid);
      main.append(completed);
    }
    this.replaceStage(main);
  }

  /**
   * F7 Fragenwerkstatt: eigene Frage schreiben, fremde Fragen bewerten.
   *
   * Die Maske schickt Inhalt, nie Lebenszyklus. `status` gibt es in der
   * Nutzlast nicht, und der Server wuerde ihn ohnehin ueberschreiben
   * (peer_service::submit()).
   */
  private renderWorkshop(view: WorkshopView): HTMLElement {
    const wrapper = liveElement('section', 'quizgeist-study-section', {
      'aria-labelledby': 'quizgeist-workshop-form-title',
    });
    const form = renderStudentForm(
      (key, fallback, values) => this.text(key, fallback, values),
      (draft) => void this.submitWorkshopQuestion(form, draft),
    );
    wrapper.append(form);
    wrapper.append(renderPeerList(
      view,
      (key, fallback, values) => this.text(key, fallback, values),
      (submissionId, rating) => void this.rateWorkshopQuestion(
        submissionId,
        rating,
      ),
    ));
    return wrapper;
  }

  private async submitWorkshopQuestion(
    form: HTMLElement,
    draft: Parameters<typeof submitPayload>[0],
  ): Promise<void> {
    try {
      const result = await this.api.workshopSubmit(submitPayload(draft));
      const errors = readFieldErrors(result);
      showFormErrors(
        form,
        errors,
        (key, fallback, values) => this.text(key, fallback, values),
      );
      if (errors.length === 0) {
        await this.loadOverview();
      }
    } catch (error) {
      this.announce(this.api.errorMessage(error));
    }
  }

  private async rateWorkshopQuestion(
    submissionId: number,
    rating: WorkshopRating,
  ): Promise<void> {
    try {
      await this.api.workshopRate(submissionId, rating);
      await this.loadOverview();
    } catch (error) {
      this.announce(this.api.errorMessage(error));
    }
  }

  private renderWeeklyGoal(overview: SelfStudyOverview): HTMLElement {
    const goal = overview.weeklyGoal;
    const section = liveElement('section', 'quizgeist-study-goal', {
      'aria-labelledby': 'quizgeist-study-goal-heading',
    });
    const copy = goal.progress >= goal.target
      ? this.text(
        'selfstudy:goal:reached',
        'Geschafft! Alles Weitere ist dein persönlicher Bonus.',
      )
      : this.text(
        'selfstudy:goal:encouragement',
        'Jede bearbeitete Frage bringt dich deinem Ziel näher.',
      );
    const title = liveElement('div', 'quizgeist-study-goal__title');
    title.append(
      icon('goal'),
      liveElement('h3', '', {
        id: 'quizgeist-study-goal-heading',
        text: this.text('selfstudy:goal:title', 'Mein Wochenziel'),
      }),
    );
    const progress = liveElement('progress', 'quizgeist-study-goal__progress', {
      max: Math.max(1, goal.target),
      value: Math.min(goal.progress, Math.max(1, goal.target)),
    });
    progress.textContent = `${goal.progress} / ${goal.target}`;
    const progressText = liveElement('strong', 'quizgeist-study-goal__value', {
      text: this.text(
        'selfstudy:goal:progress',
        '{$progress} / {$target} Fragen',
        {progress: goal.progress, target: goal.target},
      ),
    });
    const form = liveElement('form', 'quizgeist-study-goal__form');
    const label = liveElement('label', '', {
      for: 'quizgeist-weekly-goal',
      text: this.text('selfstudy:goal:edit', 'Ziel bearbeiten'),
    });
    const input = liveElement('input', 'quizgeist-study-input quizgeist-study-input--number', {
      id: 'quizgeist-weekly-goal',
      inputmode: 'numeric',
      max: 500,
      min: 1,
      name: 'target',
      required: true,
      type: 'number',
      value: goal.target,
    });
    const save = studyButton(
      this.text('selfstudy:goal:save', 'Speichern'),
      'secondary',
    );
    save.type = 'submit';
    if (goal.configured || overview.canConfigureWeeklyGoal) {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const target = Number(input.value);
        if (Number.isInteger(target) && target >= 1 && target <= 500) {
          void this.saveGoal(target, save);
        } else {
          input.focus();
          input.reportValidity();
        }
      });
      form.append(label, input, save);
    }
    section.append(
      title,
      progressText,
      progress,
      liveElement('p', 'quizgeist-study-goal__copy', {text: copy}),
    );
    if (form.childElementCount > 0) {
      section.append(form);
    } else {
      section.append(liveElement('p', 'quizgeist-study-goal__copy', {
        role: 'status',
        text: this.text(
          'selfstudy:goal:locked',
          'Ein neues persönliches Wochenziel ist derzeit nicht verfügbar.',
        ),
      }));
    }
    return section;
  }

  private renderGradeSummary(grade: GradeSummary): HTMLElement {
    const method = this.text(
      `selfstudy:grade:${grade.method}`,
      {
        average: 'Durchschnitt aller Wertungsversuche',
        best: 'Bester Wertungsversuch',
        last: 'Letzter Wertungsversuch',
      }[grade.method],
    );
    const section = liveElement(
      'section',
      'quizgeist-study-goal quizgeist-study-grade',
      {'aria-labelledby': 'quizgeist-study-grade-heading'},
    );
    section.append(liveElement('h3', '', {
      id: 'quizgeist-study-grade-heading',
      text: this.text('selfstudy:grade:title', 'Dein Bewertungsstand'),
    }));
    if (grade.percent === null) {
      section.append(liveElement('p', 'quizgeist-study-goal__copy', {
        text: this.text(
          'selfstudy:grade:empty',
          'Noch kein gewerteter Versuch · Berechnung: {$method}',
          {method},
        ),
      }));
      return section;
    }
    const percent = Math.round(grade.percent * 10) / 10;
    section.append(liveElement('strong', 'quizgeist-study-goal__value', {
      text: this.text(
        'selfstudy:grade:value',
        '{$percent} % aus {$count} Wertungsversuchen · {$method}',
        {count: grade.attemptCount, method, percent},
      ),
    }));
    return section;
  }

  private async saveGoal(target: number, button: HTMLButtonElement): Promise<void> {
    if (this.busy) {
      return;
    }
    this.setBusy(true, button);
    try {
      this.overview = await this.api.saveWeeklyGoal(target);
      this.busy = false;
      this.renderOverview();
      this.announce(this.text('selfstudy:goal:saved', 'Wochenziel gespeichert.'));
    } catch (error) {
      this.announce(this.api.errorMessage(error));
      this.setBusy(false, button);
    }
  }

  private renderEmptyState(): HTMLElement {
    const empty = liveElement('div', 'quizgeist-study-empty');
    if (this.config.brandIconUrl !== '') {
      empty.append(liveElement('img', 'quizgeist-study-empty__illustration', {
        alt: '',
        src: this.config.brandIconUrl,
      }));
    }
    empty.append(
      liveElement('h4', '', {
        text: this.text('selfstudy:empty:title', 'Aktuell nichts offen'),
      }),
      liveElement('p', '', {
        text: this.text(
          'selfstudy:empty:description',
          'Schau später wieder vorbei – neue Lernaufgaben erscheinen hier.',
        ),
      }),
    );
    return empty;
  }

  private renderAssignmentCard(
    assignment: AssignmentSummary,
    serverTimeMs: number,
    completed: boolean,
  ): HTMLElement {
    const card = liveElement('article', `quizgeist-study-assignment quizgeist-study-assignment--${
      assignment.mode
    }`);
    const header = liveElement('header', 'quizgeist-study-assignment__header');
    header.append(
      liveElement('span', 'quizgeist-study-mode', {
        text: modeLabel(this.config, assignment.mode),
      }),
      deadlineBadge(this.config, assignment.timeDueMs, serverTimeMs),
    );
    const heading = liveElement('h4', 'quizgeist-study-assignment__title', {
      text: assignment.name,
    });
    const progress = assignment.progress;
    const progressNode = liveElement('div', 'quizgeist-study-assignment__progress');
    if (assignment.mode === 'flashcards') {
      const ratio = progress.total > 0
        ? Math.min(100, Math.round(progress.answered * 100 / progress.total))
        : 0;
      const ring = liveElement('div', 'quizgeist-study-progress-ring', {
        'aria-label': this.text(
          'selfstudy:flashcards:knownprogress',
          '{$known} von {$total} gewusst',
          {known: progress.answered, total: progress.total},
        ),
        role: 'img',
      });
      ring.style.setProperty('--mq-study-progress', `${ratio}%`);
      ring.append(liveElement('strong', '', {text: `${ratio} %`}));
      progressNode.append(ring);
    } else if (progress.total > 0) {
      const bar = liveElement('progress', '', {
        max: progress.total,
        value: Math.min(progress.answered, progress.total),
      });
      bar.textContent = `${progress.answered} / ${progress.total}`;
      progressNode.append(
        bar,
        liveElement('span', '', {
          text: this.text(
            'selfstudy:assignment:progress',
            '{$answered} / {$total} bearbeitet',
            {answered: progress.answered, total: progress.total},
          ),
        }),
      );
    }
    const attempts = this.text(
      'selfstudy:assignment:attempts',
      'Versuche: {$used} / {$max}',
      {max: assignment.maxAttempts, used: assignment.attemptsUsed},
    );
    const grading = assignment.countsTowardsGrade
      && (assignment.mode === 'solo' || assignment.mode === 'test')
      ? this.text(
        'selfstudy:assignment:graded',
        'Zählt zum Bewertungsstand',
      )
      : this.text(
        'selfstudy:assignment:ungraded',
        'Zählt nicht zum Bewertungsstand',
      );
    const assignmentMeta = liveElement(
      'p',
      'quizgeist-study-assignment__availability',
      {text: `${attempts} · ${grading}`},
    );
    const action = studyButton(
      completed
        ? this.text('selfstudy:assignment:review', 'Ergebnis ansehen')
        : assignment.attemptId
          ? this.text('selfstudy:assignment:resume', 'Weiterlernen')
          : assignment.available
            ? this.text('selfstudy:assignment:start', 'Starten')
            : this.text(
              'selfstudy:assignment:unavailable',
              'Noch nicht verfügbar',
            ),
      completed ? 'secondary' : 'primary',
    );
    action.disabled = (!assignment.available && !assignment.attemptId)
      || (completed && !assignment.attemptId);
    action.addEventListener('click', () => {
      if (assignment.attemptId) {
        void this.loadAttempt(assignment.attemptId);
      } else {
        void this.startAssignment(assignment.id, action);
      }
    });
    card.append(header, heading, progressNode, assignmentMeta);
    if (!completed
        && !assignment.available
        && assignment.timeOpenMs > serverTimeMs) {
      card.append(liveElement('p', 'quizgeist-study-assignment__availability', {
        text: this.text(
          'selfstudy:assignment:opens',
          'Verfügbar ab {$date}',
          {date: formatDate(assignment.timeOpenMs)},
        ),
      }));
    }
    const actions = liveElement('div', 'quizgeist-study-navigation__actions');
    actions.append(action);
    if (completed && assignment.canRetry) {
      const retry = studyButton(
        this.text('selfstudy:action:retry', 'Erneut versuchen'),
        'primary',
      );
      retry.addEventListener('click', () => {
        void this.startAssignment(assignment.id, retry, true);
      });
      actions.append(retry);
    }
    card.append(actions);
    return card;
  }

  private async startAssignment(
    assignmentId: number,
    button?: HTMLButtonElement,
    newAttempt = false,
  ): Promise<void> {
    if (this.busy) {
      return;
    }
    this.setBusy(true, button);
    if (!button) {
      this.replaceStage(loadingCard(this.config, 'selfstudy:loading:attempt'), false);
    }
    try {
      const result = await this.api.start(assignmentId, newAttempt);
      this.applyState(result.state);
    } catch (error) {
      this.setBusy(false, button);
      this.replaceStage(errorCard(
        this.config,
        this.api.errorMessage(error),
        () => void this.startAssignment(assignmentId, undefined, newAttempt),
      ));
    }
  }

  private async loadAttempt(attemptId: number): Promise<void> {
    if (this.busy) {
      return;
    }
    this.busy = true;
    this.replaceStage(loadingCard(this.config, 'selfstudy:loading:attempt'), false);
    try {
      const result = await this.api.state(attemptId);
      this.applyState(result.state);
    } catch (error) {
      this.busy = false;
      this.replaceStage(errorCard(
        this.config,
        this.api.errorMessage(error),
        () => void this.loadAttempt(attemptId),
      ));
    }
  }

  private applyState(next: SelfStudyAttemptState): void {
    const previousQuestion = this.state?.question;
    const changedQuestion = previousQuestion?.id !== next.question?.id
      || previousQuestion?.questionToken !== next.question?.questionToken;
    this.state = next;
    this.busy = false;
    if (changedQuestion) {
      this.answerDraft = next.ownAnswer;
      this.tts.stop();
    } else if (next.ownAnswer) {
      this.answerDraft = next.ownAnswer;
    }
    this.renderAttempt();
    this.updateLocation(next);
  }

  private renderAttempt(): void {
    const state = this.state;
    if (!state) {
      return;
    }
    if (state.status === 'completed') {
      this.renderCompleted(state);
      return;
    }
    const main = liveElement('main', 'quizgeist-study-attempt');
    const header = liveElement('header', 'quizgeist-study-attempt__header');
    const back = studyButton(
      this.text('selfstudy:action:overview', 'Zur Übersicht'),
      'quiet',
    );
    back.addEventListener('click', () => void this.loadOverview());
    const identity = liveElement('div', 'quizgeist-study-attempt__identity');
    identity.append(
      liveElement('span', 'quizgeist-study-mode', {
        text: modeLabel(this.config, state.mode),
      }),
      liveElement('h2', 'quizgeist-study-title', {
        text: state.assignment.name,
      }),
    );
    header.append(back, identity);
    main.append(header);

    const progress = liveElement('div', 'quizgeist-study-attempt__progress');
    progress.append(
      liveElement('span', '', {
        text: this.text(
          'selfstudy:attempt:progress',
          'Frage {$current} von {$total}',
          {current: state.currentIndex + 1, total: state.total},
        ),
      }),
      liveElement('progress', '', {
        max: Math.max(1, state.total),
        value: Math.min(state.currentIndex + 1, Math.max(1, state.total)),
      }),
    );
    main.append(progress);

    // F4: Interleaving fuehlt sich wie ein Fehler an, solange es niemand
    // ansagt. Dieser eine Satz ist der Unterschied zwischen "die App springt"
    // und "ich uebe so, wie es wirklich wirkt".
    if (state.assignment.selectionStrategy === 'interleaved') {
      main.append(renderInterleavingHint(
        (key, fallback, values) => this.text(key, fallback, values),
      ));
    }

    if (!state.canAnswer) {
      main.append(liveElement('p', 'quizgeist-study-test-note', {
        role: 'status',
        text: this.text(
          'selfstudy:attempt:readonly',
          'Weitere Antworten sind nicht mehr möglich. Bereits gespeicherte Antworten bleiben erhalten.',
        ),
      }));
    }
    if (state.mode === 'speaking') {
      // F12: Der Sprech-Trainer ist eine eigene Runde und keine Frage mit
      // Aufnahmeknopf. Er bringt seine Aufgabenanzeige selbst mit.
      main.append(this.renderSpeaking(state));
      main.append(this.renderAttemptNavigation(state));
    } else if (state.mode === 'flashcards') {
      main.append(this.renderFlashcard(state));
      if (state.canFinish) {
        main.append(this.renderAttemptNavigation(state));
      }
    } else {
      main.append(this.renderQuestion(state));
      main.append(this.renderAttemptNavigation(state));
    }
    this.replaceStage(main);
  }

  /**
   * One round of the speaking trainer (F12).
   *
   * The trainer is created once and re-used, so a redraw does not drop a
   * running recording and does not ask for the microphone again.
   *
   * [P11-E2]: the trainer's three actions (`speaking_turn`, `clip_transcribe`,
   * `clip_state`) all belong to the AI addon. An assignment created while the
   * addon was there keeps its mode after an uninstall, so the mode alone is no
   * permission to ask. Without the addon report the trainer is not built at
   * all — and therefore asks nothing.
   */
  private renderSpeaking(state: SelfStudyAttemptState): HTMLElement {
    const question = state.result?.question || state.question;
    const clips = this.config.clips;
    if (!question
        || clips === undefined
        || this.config.features?.ai?.installed !== true) {
      return liveElement('p', 'quizgeist-study-test-note', {
        text: this.text('selfstudy:question:unavailable', 'Die Frage ist nicht verfügbar.'),
      });
    }
    if (this.speaking === null) {
      this.speaking = new SpeakingTrainer(
        this.config.strings,
        {
          uploadUrl: clips.uploadUrl,
          sesskey: this.config.sesskey,
          cmid: this.config.cmid,
          purpose: 'speaking',
          language: clips.language,
          maxBytes: clips.maxBytes,
          maxSeconds: clips.maxSeconds,
          transcriptionAvailable: clips.canTranscribe,
        },
        {
          submitTurn: (payload) => this.api
            .post<{turn: SpeakingTurnResult}>('speaking_turn', payload)
            .then(async (result) => {
              // [P11-C4-O4]: Eine Sprechrunde ist eine ANTWORT, keine blosse
              // Rueckmeldung. Sie geht deshalb durch denselben
              // Selbstlern-Abgabeweg wie jede andere Antwort — gleiche
              // Pruefung, gleiche Bindung des Clips (U3), gleiche Zeile in
              // quizgeist_answers. Ohne diesen Aufruf uebte eine Klasse
              // sichtbar und hinterliesse keine Spur.
              await this.recordSpeakingAnswer(payload.clipId, payload.text);
              return result.turn;
            }),
          transcribe: (clipId) => this.api
            .post<{clip: UploadedClip}>('clip_transcribe', {clipId})
            .then((result) => result.clip),
          pollState: (clipIds) => this.api
            .post<{clips: UploadedClip[]}>('clip_state', {clipIds})
            .then((result) => result.clips),
        },
        this.tts,
      );
    }
    this.speaking.setQuestion({
      task: question.questionText,
      accepted: [],
    }, false);
    const wrapper = liveElement('section', 'quizgeist-study-question-card');
    wrapper.append(this.speaking.root);
    return wrapper;
  }

  /**
   * Persist one speaking round as the answer of the current question.
   *
   * Deliberately fault tolerant: a refused submission (question already
   * answered, attempt finished) must never swallow the round's feedback. The
   * learner has spoken and gets their answer either way; only the ledger entry
   * is skipped, and the next round retries it.
   */
  private async recordSpeakingAnswer(
    clipId: number | undefined,
    text: string | undefined,
  ): Promise<void> {
    const state = this.state;
    if (state === null || !state.canAnswer || !state.question) {
      return;
    }
    const answer: Record<string, unknown> = typeof clipId === 'number' && clipId > 0
      ? {kind: 'clip', clipId}
      : {kind: 'text', text: typeof text === 'string' ? text : ''};
    try {
      const result = await this.api.submit(state, answer);
      if (result.state) {
        this.applyState(result.state);
      }
    } catch {
      // See the doc block: the round stands even when the ledger refuses.
    }
  }

  private questionHeader(question: LiveQuestion): HTMLElement {
    const section = liveElement('section', 'quizgeist-study-question');
    const heading = liveElement('h3', 'quizgeist-study-question__title', {
      text: question.questionText,
    });
    heading.dataset.studyHeading = '';
    heading.tabIndex = -1;
    section.append(
      heading,
      createTtsControl(this.tts, questionSpeechText(question), this.config),
    );
    if (!['pin', 'reveal', 'slide'].includes(question.qtype)) {
      const media = createLiveMedia(question, {
        className: 'quizgeist-study-question__media',
        label: question.questionText,
      });
      if (media) {
        section.append(media);
      }
    }
    return section;
  }

  private renderQuestion(state: SelfStudyAttemptState): HTMLElement {
    const wrapper = liveElement('section', 'quizgeist-study-question-card');
    const question = state.result?.question || state.question;
    if (!question) {
      wrapper.append(liveElement('p', '', {
        text: this.text('selfstudy:question:unavailable', 'Die Frage ist nicht verfügbar.'),
      }));
      return wrapper;
    }
    wrapper.append(this.questionHeader(question));
    if (state.mode === 'solo') {
      const hasPoints = question.pointMode !== 'none';
      wrapper.append(liveElement('p', 'quizgeist-study-test-note', {
        text: !hasPoints
          ? this.text(
            'selfstudy:solo:nopoints',
            'Diese Aufgabe wird ohne Punktewertung bearbeitet.',
          )
          : question.timeLimit > 0
            ? this.text(
              'selfstudy:solo:timerule',
              'Die Zeitwertung beginnt beim ersten Öffnen. Auch nach {$seconds} Sekunden erhält eine richtige Antwort mindestens 50 % der Basispunkte.',
              {seconds: question.timeLimit},
            )
            : this.text(
              'selfstudy:solo:notimed',
              'Für diese Solo-Aufgabe gibt es keinen zeitbedingten Punkteabzug.',
            ),
      }));
    }
    if ((question.policyDescriptor?.stages?.length || 0) > 1) {
      wrapper.append(liveElement('p', 'quizgeist-study-test-note', {
        text: this.text(
          'selfstudy:multistage:warning',
          'Diese mehrstufige Frage wird im Selbstlernen als einzelne Aufgabe in ihrer ersten Eingabestufe bearbeitet.',
        ),
      }));
    }
    if (state.result) {
      wrapper.append(this.renderResult(state.result));
      return wrapper;
    }
    const response = renderLiveResponse(question, {
      answer: this.answerDraft || state.ownAnswer,
      audience: 'player',
      disabled: this.busy || !state.canAnswer,
      interactive: state.canAnswer,
      nowMs: state.serverTimeMs,
      onChange: (answer) => {
        this.answerDraft = answer;
      },
      onSubmit: (answer) => {
        if (answer) {
          void this.submitAnswer(answer, response.querySelector<HTMLButtonElement>(
            '[data-live-submit]',
          ) || undefined);
        }
      },
      text: (key, fallback, values = {}) => this.text(key, fallback, values),
    });
    response.setAttribute('aria-label', question.questionText);
    wrapper.append(response);
    if (state.mode === 'test') {
      wrapper.append(liveElement('p', 'quizgeist-study-test-note', {
        text: this.text(
          'selfstudy:test:feedbacklater',
          'Deine Antwort wird gespeichert. Die Auswertung erscheint erst nach der Abgabe.',
        ),
      }));
    }
    return wrapper;
  }

  private renderResult(result: QuestionResult): HTMLElement {
    const wrapper = liveElement('div', 'quizgeist-study-review');
    wrapper.append(renderQuestionSolution(result.question, {
      answer: result.answer,
      correct: result.correct,
      text: (key, fallback, values = {}) => this.text(key, fallback, values),
    }));
    if (result.explanation.trim() !== '') {
      const explanation = liveElement('section', 'quizgeist-study-explanation');
      explanation.append(
        liveElement('h4', '', {
          text: this.text('selfstudy:review:explanation', 'Erklärung'),
        }),
        liveElement('p', '', {text: result.explanation}),
      );
      wrapper.append(explanation);
    }
    return wrapper;
  }

  private renderAttemptNavigation(state: SelfStudyAttemptState): HTMLElement {
    const nav = liveElement('nav', 'quizgeist-study-navigation', {
      'aria-label': this.text('selfstudy:navigation:label', 'Testnavigation'),
    });
    if (state.mode === 'test') {
      const positions = liveElement('div', 'quizgeist-study-navigation__positions');
      for (let index = 0; index < state.total; index += 1) {
        const position = studyButton(String(index + 1), 'quiet');
        position.classList.add('quizgeist-study-position');
        position.classList.toggle('is-current', index === state.currentIndex);
        position.classList.toggle('is-answered', state.answeredIndices.includes(index));
        position.setAttribute(
          'aria-current',
          index === state.currentIndex ? 'step' : 'false',
        );
        position.setAttribute('aria-label', this.text(
          state.answeredIndices.includes(index)
            ? 'selfstudy:navigation:answered'
            : 'selfstudy:navigation:unanswered',
          state.answeredIndices.includes(index)
            ? 'Frage {$number}, beantwortet'
            : 'Frage {$number}, noch offen',
          {number: index + 1},
        ));
        position.disabled = this.busy || !state.canAnswer;
        position.addEventListener('click', () => void this.navigate(index, position));
        positions.append(position);
      }
      nav.append(positions);
    }

    const actions = liveElement('div', 'quizgeist-study-navigation__actions');
    if (state.mode !== 'flashcards') {
      const previous = studyButton(
        this.text('selfstudy:navigation:previous', 'Zurück'),
        'secondary',
      );
      previous.disabled = state.currentIndex <= 0
        || this.busy
        || !state.canAnswer;
      previous.addEventListener('click', () => void this.navigate(
        state.currentIndex - 1,
        previous,
      ));
      actions.append(previous);
      if (state.currentIndex < state.total - 1) {
        const next = studyButton(
          this.text('selfstudy:navigation:next', 'Weiter'),
          'secondary',
        );
        next.disabled = this.busy
          || !state.canAnswer
          || (state.mode !== 'test'
            && !state.answeredIndices.includes(state.currentIndex));
        next.addEventListener('click', () => void this.navigate(
          state.currentIndex + 1,
          next,
        ));
        actions.append(next);
      }
    }
    if (state.canFinish) {
      const finish = studyButton(
        state.mode === 'test'
          ? this.text('selfstudy:test:finish', 'Test abgeben und auswerten')
          : this.text('selfstudy:attempt:finish', 'Zuweisung abschließen'),
        'primary',
      );
      finish.disabled = this.busy;
      finish.addEventListener('click', () => void this.finish(finish));
      actions.append(finish);
    }
    nav.append(actions);
    return nav;
  }

  private renderFlashcard(state: SelfStudyAttemptState): HTMLElement {
    const card = liveElement('section', 'quizgeist-study-flashcard');
    const progress = state.flashcards;
    if (progress && progress.round > 0) {
      const repeat = liveElement('p', 'quizgeist-study-repeat-banner', {
        role: 'status',
      });
      repeat.append(
        icon('repeat'),
        document.createTextNode(this.text(
          'selfstudy:flashcards:repeatround',
          'Wiederholrunde: Jetzt kommen die Karten zurück, die noch unsicher waren.',
        )),
      );
      card.append(repeat);
    }
    if (progress) {
      const summary = liveElement('div', 'quizgeist-study-flashcard__progress');
      const ring = liveElement('div', 'quizgeist-study-progress-ring', {
        'aria-label': this.text(
          'selfstudy:flashcards:knownprogress',
          '{$known} von {$total} gewusst',
          {known: progress.known, total: progress.total},
        ),
        role: 'img',
      });
      const percentage = progress.total > 0
        ? Math.round(progress.known * 100 / progress.total)
        : 0;
      ring.style.setProperty('--mq-study-progress', `${percentage}%`);
      ring.append(liveElement('strong', '', {text: `${percentage} %`}));
      summary.append(
        ring,
        liveElement('p', '', {
          text: this.text(
            'selfstudy:flashcards:stacks',
            '{$known} gewusst · {$repeat} zum Wiederholen',
            {known: progress.known, repeat: progress.repeat},
          ),
        }),
      );
      card.append(summary);
    }
    const question = state.result?.question || state.question;
    if (!question) {
      return card;
    }
    card.append(this.questionHeader(question));
    if (progress?.revealed || state.result) {
      card.append(renderQuestionSolution(question, {
        text: (key, fallback, values = {}) => this.text(key, fallback, values),
      }));
      if (state.result?.explanation.trim()) {
        card.append(liveElement('p', 'quizgeist-study-explanation', {
          text: state.result.explanation,
        }));
      }
      const actions = liveElement('div', 'quizgeist-study-flashcard__actions');
      const unknown = studyButton(
        this.text('selfstudy:flashcards:notknown', 'Noch nicht gewusst'),
        'secondary',
      );
      const known = studyButton(
        this.text('selfstudy:flashcards:known', 'Gewusst'),
        'primary',
      );
      unknown.disabled = this.busy || !state.canAnswer;
      known.disabled = this.busy || !state.canAnswer;
      unknown.addEventListener('click', () => void this.markFlashcard(false, unknown));
      known.addEventListener('click', () => void this.markFlashcard(true, known));
      actions.append(unknown, known);
      card.append(actions);
    } else {
      card.append(liveElement('p', 'quizgeist-study-flashcard__prompt', {
        text: this.text(
          'selfstudy:flashcards:think',
          'Überlege zuerst selbst. Drehe die Karte erst um, wenn deine Antwort steht.',
        ),
      }));
      const reveal = studyButton(
        this.text('selfstudy:flashcards:reveal', 'Antwort anzeigen'),
        'primary',
      );
      reveal.disabled = this.busy || !state.canAnswer;
      reveal.addEventListener('click', () => void this.revealFlashcard(reveal));
      card.append(reveal);
    }
    return card;
  }

  private async submitAnswer(
    answer: LiveAnswer,
    button?: HTMLButtonElement,
  ): Promise<void> {
    const state = this.state;
    if (!state || !state.question || this.busy || !state.canAnswer) {
      return;
    }
    this.setBusy(true, button);
    try {
      const result = await this.api.submit(state, answerPayload(answer));
      this.applyState(result.state);
      this.announce(state.mode === 'test'
        ? this.text('selfstudy:test:saved', 'Antwort gespeichert.')
        : this.text('selfstudy:answer:submitted', 'Antwort abgegeben.'));
    } catch (error) {
      if (!this.recoverConflict(error)) {
        this.setBusy(false, button);
        this.announce(this.api.errorMessage(error));
      }
    }
  }

  private async navigate(index: number, button?: HTMLButtonElement): Promise<void> {
    const state = this.state;
    if (!state
        || this.busy
        || !state.canAnswer
        || index < 0
        || index >= state.total) {
      return;
    }
    this.setBusy(true, button);
    try {
      const result = await this.api.navigate(
        state.attemptId,
        index,
        state.stateVersion,
      );
      this.applyState(result.state);
    } catch (error) {
      if (!this.recoverConflict(error)) {
        this.setBusy(false, button);
        this.announce(this.api.errorMessage(error));
      }
    }
  }

  private async finish(button: HTMLButtonElement): Promise<void> {
    const state = this.state;
    if (!state || this.busy || !state.canFinish) {
      return;
    }
    const answered = new Set(state.answeredIndices).size;
    const remaining = Math.max(0, state.total - answered);
    if (remaining > 0 && !window.confirm(this.text(
      'selfstudy:attempt:finishconfirm',
      '{$count} Aufgaben sind noch offen. Trotzdem mit dem bisherigen Stand abschließen?',
      {count: remaining},
    ))) {
      return;
    }
    this.setBusy(true, button);
    try {
      const result = await this.api.finish(state);
      this.applyState(result.state);
      this.announce(this.text('selfstudy:attempt:completed', 'Zuweisung abgeschlossen.'));
    } catch (error) {
      if (!this.recoverConflict(error)) {
        this.setBusy(false, button);
        this.announce(this.api.errorMessage(error));
      }
    }
  }

  private async revealFlashcard(button: HTMLButtonElement): Promise<void> {
    const state = this.state;
    if (!state || this.busy || !state.canAnswer) {
      return;
    }
    this.setBusy(true, button);
    try {
      const result = await this.api.revealFlashcard(state);
      this.applyState(result.state);
      this.announce(this.text('selfstudy:flashcards:revealed', 'Antwort aufgedeckt.'));
    } catch (error) {
      if (!this.recoverConflict(error)) {
        this.setBusy(false, button);
        this.announce(this.api.errorMessage(error));
      }
    }
  }

  private async markFlashcard(
    known: boolean,
    button: HTMLButtonElement,
  ): Promise<void> {
    const state = this.state;
    if (!state || this.busy || !state.canAnswer) {
      return;
    }
    this.setBusy(true, button);
    try {
      const result = await this.api.markFlashcard(state, known);
      this.applyState(result.state);
      this.announce(known
        ? this.text('selfstudy:flashcards:markedknown', 'Als gewusst markiert.')
        : this.text(
          'selfstudy:flashcards:markedrepeat',
          'Für die Wiederholrunde vorgemerkt.',
        ));
    } catch (error) {
      if (!this.recoverConflict(error)) {
        this.setBusy(false, button);
        this.announce(this.api.errorMessage(error));
      }
    }
  }

  private renderCompleted(state: SelfStudyAttemptState): void {
    const main = liveElement('main', 'quizgeist-study-finished');
    const heading = liveElement('h2', 'quizgeist-study-title', {
      text: this.text('selfstudy:completed:title', 'Geschafft!'),
    });
    heading.dataset.studyHeading = '';
    heading.tabIndex = -1;
    const summary = liveElement('section', 'quizgeist-study-finished__summary');
    summary.append(
      heading,
      liveElement('p', '', {
        text: this.text(
          'selfstudy:completed:description',
          'Dein Versuch ist abgeschlossen. Hier kannst du deine Antworten in Ruhe prüfen.',
        ),
      }),
    );
    if (state.maxScore > 0) {
      const percentage = Math.round(state.score * 1000 / state.maxScore) / 10;
      summary.append(
        liveElement('strong', 'quizgeist-study-finished__score', {
          text: this.text(
            'selfstudy:completed:score',
            '{$score} / {$max} Punkte · {$percent} %',
            {max: state.maxScore, percent: percentage, score: state.score},
          ),
        }),
      );
    } else if (state.flashcards) {
      summary.append(liveElement('strong', 'quizgeist-study-finished__score', {
        text: this.text(
          'selfstudy:completed:cards',
          '{$known} von {$total} Karten gewusst',
          {known: state.flashcards.known, total: state.flashcards.total},
        ),
      }));
    }
    main.append(summary);
    main.append(this.renderGradeSummary(state.gradeSummary));
    // F8 Erklär-Geist: "Alle Lösungswege". After a performance run this is
    // the only place a withheld worked solution becomes readable.
    const reviewSummary = this.renderReviewSummary(state);
    if (reviewSummary) {
      main.append(reviewSummary);
    }

    if (state.results.length > 0) {
      const reviews = liveElement('section', 'quizgeist-study-finished__reviews', {
        'aria-labelledby': 'quizgeist-study-review-heading',
      });
      reviews.append(liveElement('h3', '', {
        id: 'quizgeist-study-review-heading',
        text: this.text('selfstudy:completed:review', 'Auswertung'),
      }));
      state.results.forEach((result, index) => {
        reviews.append(this.renderCompletedResult(result, index));
      });
      main.append(reviews);
    }
    const actions = liveElement('div', 'quizgeist-study-navigation__actions');
    const overview = studyButton(
      this.text('selfstudy:action:overview', 'Zur Übersicht'),
      state.assignment.canRetry ? 'secondary' : 'primary',
    );
    overview.addEventListener('click', () => void this.loadOverview());
    actions.append(overview);
    if (state.assignment.canRetry) {
      const retry = studyButton(
        this.text('selfstudy:action:retry', 'Erneut versuchen'),
        'primary',
      );
      retry.addEventListener('click', () => {
        void this.startAssignment(state.assignment.id, retry, true);
      });
      actions.append(retry);
    }
    main.append(actions);
    this.replaceStage(main);
  }

  /**
   * F8: collect every worked solution of a finished run.
   *
   * The server already decided what may be here. With policy `never` the
   * payload carries no summary at all and this returns null.
   */
  private renderReviewSummary(
    state: SelfStudyAttemptState,
  ): HTMLElement | null {
    const summary = state.reviewSummary;
    if (!summary || !summary.available) {
      return null;
    }
    const section = liveElement('section', 'quizgeist-study-solutions');
    section.dataset.studyReviewSummary = '';
    section.append(liveElement('h3', 'quizgeist-study-solutions__title', {
      text: this.text(
        'selfstudy:review:summary:title',
        'Alle Lösungswege',
      ),
    }));
    if (summary.deferred) {
      section.append(liveElement('p', 'quizgeist-study-solutions__note', {
        text: this.text(
          'selfstudy:review:summary:deferred',
          'Während des Durchgangs waren die Lösungswege zurückgehalten.',
        ),
      }));
    }
    if (summary.entries.length === 0) {
      section.append(liveElement('p', 'quizgeist-study-solutions__empty', {
        text: this.text(
          'selfstudy:review:summary:empty',
          'Zu diesem Durchgang ist noch kein Lösungsweg hinterlegt.',
        ),
      }));
      return section;
    }
    section.append(liveElement('p', 'quizgeist-study-solutions__count', {
      text: this.text(
        'selfstudy:review:summary:count',
        '{$count} von {$total} Fragen haben einen Lösungsweg.',
        {count: summary.withExplanation, total: summary.total},
      ),
    }));
    const list = liveElement('ol', 'quizgeist-study-solutions__list');
    summary.entries.forEach((entry) => {
      const item = liveElement('li', 'quizgeist-study-solutions__item');
      item.append(
        liveElement('h4', 'quizgeist-study-solutions__question', {
          text: entry.questionText,
        }),
        liveElement('p', 'quizgeist-study-solutions__text', {
          text: entry.explanation,
        }),
      );
      list.append(item);
    });
    section.append(list);
    return section;
  }

  private renderCompletedResult(result: QuestionResult, index: number): HTMLElement {
    const details = liveElement('details', 'quizgeist-study-result-card');
    if (index === 0) {
      details.open = true;
    }
    const summary = liveElement('summary', 'quizgeist-study-result-card__summary');
    const status = typeof result.correct !== 'boolean'
      ? this.text('selfstudy:review:ungraded', 'Selbstkontrolle')
      : result.correct
        ? this.text('selfstudy:review:correctshort', 'Richtig')
        : this.text('selfstudy:review:incorrectshort', 'Noch üben');
    summary.append(
      liveElement('span', 'quizgeist-study-result-card__number', {
        text: String(index + 1),
      }),
      liveElement('span', 'quizgeist-study-result-card__question', {
        text: result.question.questionText,
      }),
      liveElement('span', `quizgeist-study-result-card__status${
        result.correct === true
          ? ' is-correct'
          : result.correct === false
            ? ' is-incorrect'
            : ''
      }`, {text: status}),
    );
    const content = liveElement('div', 'quizgeist-study-result-card__content');
    content.append(this.renderResult(result));
    details.append(summary, content);
    return details;
  }

  private setBusy(busy: boolean, button?: HTMLButtonElement): void {
    this.busy = busy;
    if (button) {
      button.disabled = busy;
      button.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
  }

  private recoverConflict(error: unknown): boolean {
    const state = this.api.conflictState(error);
    if (!state) {
      return false;
    }
    this.applyState(state);
    this.announce(this.text(
      'selfstudy:error:conflict',
      'Der Lernstand wurde auf den neuesten Stand gebracht.',
    ));
    return true;
  }

  private updateLocation(state?: SelfStudyAttemptState): void {
    try {
      const url = new URL(window.location.href);
      if (state) {
        url.searchParams.set('view', 'attempt');
        url.searchParams.set('attemptid', String(state.attemptId));
        url.searchParams.set('assignmentid', String(state.assignment.id));
      } else {
        url.searchParams.set('view', 'overview');
        url.searchParams.delete('attemptid');
        url.searchParams.delete('assignmentid');
      }
      window.history.replaceState({}, '', url);
    } catch (_error) {
      // The Moodle page remains usable even when history access is restricted.
    }
  }
}

export function mountStudentSelfStudyApp(
  root: HTMLElement,
  config: SelfStudyConfig,
): void {
  const app = new StudentSelfStudyApp(root, config);
  void app.init();
}
