import {liveElement} from '../live/dom';
import {SelfStudyApi, SelfStudyApiError} from './api';
import {
  AI_STUDY_MODES,
  STRATEGIES,
  STUDY_MODES,
  type AssignmentInput,
  type SelectionStrategy,
  type SelfStudyConfig,
  type StudyMode,
  type TeacherAssignment,
  type TeacherAssignmentList,
} from './types';
import {
  deadlineBadge,
  errorCard,
  formatDate,
  loadingCard,
  modeLabel,
  studyButton,
  studyText,
} from './ui';

function localDateTime(timestampMs: number): string {
  if (!Number.isFinite(timestampMs) || timestampMs <= 0) {
    return '';
  }
  const date = new Date(timestampMs);
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 16);
}

function timestampSeconds(input: HTMLInputElement, enabled: boolean): number {
  if (!enabled || input.value === '') {
    return 0;
  }
  const value = new Date(input.value).getTime();
  return Number.isFinite(value) ? Math.floor(value / 1000) : 0;
}

export class AssignmentTeacherApp {
  private readonly api: SelfStudyApi;
  private busy = false;
  private editing: TeacherAssignment | null = null;
  private formOpen = false;
  private readonly liveRegion: HTMLDivElement;
  private list: TeacherAssignmentList | null = null;
  private readonly stage: HTMLDivElement;

  public constructor(
    private readonly root: HTMLElement,
    private readonly config: SelfStudyConfig,
  ) {
    this.api = new SelfStudyApi(config);
    this.liveRegion = liveElement('div', 'quizgeist-live-visually-hidden', {
      'aria-atomic': 'true',
      'aria-live': 'polite',
      role: 'status',
    });
    this.stage = liveElement('div', 'quizgeist-assignment-stage');
  }

  public async init(): Promise<void> {
    this.root.classList.add('quizgeist-assignment-root');
    this.root.dataset.quizgeistRoot = 'selfstudy-teacher';
    this.root.dataset.quizgeistTheme = this.config.theme || 'hell';
    this.root.dataset.quizgeistSeason = this.config.season || 'herbst';
    this.root.replaceChildren(this.liveRegion, this.stage);
    await this.load();
  }

  private text(
    key: string,
    fallback: string,
    values: Record<string, string | number> = {},
  ): string {
    return studyText(this.config, key, fallback, values);
  }

  private announce(message: string): void {
    this.liveRegion.textContent = '';
    window.requestAnimationFrame(() => {
      this.liveRegion.textContent = message;
    });
  }

  private async load(): Promise<void> {
    this.stage.replaceChildren(loadingCard(
      this.config,
      'selfstudy:teacher:loading',
    ));
    try {
      this.list = await this.api.assignmentList();
      this.render();
    } catch (error) {
      this.stage.replaceChildren(errorCard(
        this.config,
        this.api.errorMessage(error),
        () => void this.load(),
      ));
    }
  }

  private render(): void {
    const data = this.list;
    if (!data) {
      return;
    }
    const main = liveElement('main', 'quizgeist-assignment-manager');
    const header = liveElement('header', 'quizgeist-assignment-manager__header');
    const copy = liveElement('div', '');
    const heading = liveElement('h2', 'quizgeist-study-title', {
      text: this.text('selfstudy:teacher:title', 'Zuweisungen'),
    });
    heading.dataset.studyHeading = '';
    heading.tabIndex = -1;
    copy.append(
      heading,
      liveElement('p', 'quizgeist-study-copy', {
        text: this.text(
          'selfstudy:teacher:description',
          'Stellen Sie selbstständige Lernwege mit oder ohne Frist bereit.',
        ),
      }),
    );
    const create = studyButton(
      this.text('selfstudy:teacher:create', 'Neue Zuweisung'),
      'primary',
    );
    create.disabled = !this.config.canCreate || data.readyQuestionCount <= 0;
    create.addEventListener('click', () => {
      this.editing = null;
      this.formOpen = true;
      this.render();
      window.setTimeout(() => {
        this.stage.querySelector<HTMLInputElement>('[name="assignment-name"]')?.focus();
      }, 0);
    });
    header.append(copy, create);
    main.append(header);

    if (!this.config.canCreate) {
      main.append(liveElement('p', 'quizgeist-assignment-readiness', {
        role: 'status',
        text: this.text(
          'selfstudy:teacher:locked',
          'Vorhandene Zuweisungen bleiben bearbeitbar. Für eine neue Zuweisung '
            + 'wird eine aktive Selfstudy-Lizenz benötigt.',
        ),
      }));
    } else if (data.readyQuestionCount <= 0) {
      main.append(liveElement('p', 'quizgeist-assignment-readiness', {
        role: 'status',
        text: this.text(
          'selfstudy:teacher:noquestions',
          'Es gibt noch keine spielbereite Frage. Stellen Sie zuerst mindestens '
            + 'eine Frage bereit, bevor Sie eine Zuweisung anlegen.',
        ),
      }));
    } else {
      main.append(liveElement('p', 'quizgeist-assignment-readiness', {
        text: this.text(
          'selfstudy:teacher:questioncount',
          '{$count} spielbereite Fragen werden beim Öffnen versionsfest zugeordnet.',
          {count: data.readyQuestionCount},
        ),
      }));
    }

    if (this.formOpen && (this.editing !== null || this.config.canCreate)) {
      main.append(this.renderForm(this.editing));
    }
    main.append(this.renderAssignmentList(data));
    this.stage.replaceChildren(main);
  }

  private renderAssignmentList(data: TeacherAssignmentList): HTMLElement {
    const section = liveElement('section', 'quizgeist-assignment-list', {
      'aria-labelledby': 'quizgeist-assignment-list-heading',
    });
    section.append(liveElement('h3', 'quizgeist-study-section__title', {
      id: 'quizgeist-assignment-list-heading',
      text: this.text('selfstudy:teacher:list', 'Vorhandene Zuweisungen'),
    }));
    if (data.assignments.length === 0) {
      section.append(liveElement('div', 'quizgeist-study-empty', {
        text: this.text(
          'selfstudy:teacher:empty',
          'Noch keine Zuweisung angelegt. Mit der ersten können Sie sofort '
            + 'einen Lernweg öffnen.',
        ),
      }));
      return section;
    }
    const list = liveElement('div', 'quizgeist-assignment-list__items');
    data.assignments.forEach((assignment) => {
      list.append(this.renderAssignment(assignment, data.serverTimeMs));
    });
    section.append(list);
    return section;
  }

  private renderAssignment(
    assignment: TeacherAssignment,
    serverTimeMs: number,
  ): HTMLElement {
    const card = liveElement('article', 'quizgeist-assignment-row');
    const body = liveElement('div', 'quizgeist-assignment-row__body');
    const meta = liveElement('div', 'quizgeist-assignment-row__meta');
    meta.append(
      liveElement('span', 'quizgeist-study-mode', {
        text: modeLabel(this.config, assignment.mode),
      }),
      liveElement('span', `quizgeist-assignment-status quizgeist-assignment-status--${
        assignment.status
      }`, {
        text: this.text(
          `selfstudy:status:${assignment.status}`,
          {
            archived: 'Archiviert',
            closed: 'Geschlossen',
            draft: 'Entwurf',
            open: 'Offen',
          }[assignment.status],
        ),
      }),
      deadlineBadge(this.config, assignment.timeDueMs, serverTimeMs),
    );
    body.append(
      meta,
      liveElement('h4', 'quizgeist-assignment-row__title', {text: assignment.name}),
      liveElement('p', 'quizgeist-assignment-row__participants', {
        text: this.text(
          'selfstudy:teacher:participants',
          '{$count} gestartete Versuche',
          {count: assignment.participantCount},
        ),
      }),
      liveElement('p', 'quizgeist-assignment-row__participants', {
        text: this.text(
          'selfstudy:teacher:attemptlimit',
          'Bis zu {$count} Versuche je Lernendem',
          {count: assignment.settings.maxAttempts},
        ),
      }),
      liveElement('p', 'quizgeist-assignment-row__participants', {
        text: assignment.settings.countsTowardsGrade
          && (assignment.mode === 'solo' || assignment.mode === 'test')
          ? this.text(
            'selfstudy:teacher:graded',
            'Zählt zum Bewertungsstand',
          )
          : this.text(
            'selfstudy:teacher:ungraded',
            'Ohne Notenwertung',
          ),
      }),
    );
    if (assignment.multistageQuestionCount > 0) {
      body.append(liveElement('p', 'quizgeist-assignment-readiness', {
        text: this.text(
          'selfstudy:teacher:multistagewarning',
          '{$count} mehrstufige Fragen werden im Selbstlernen jeweils auf ihre erste Eingabestufe vereinfacht.',
          {count: assignment.multistageQuestionCount},
        ),
      }));
    }
    if (assignment.timeOpenMs > serverTimeMs) {
      body.append(liveElement('p', 'quizgeist-assignment-row__availability', {
        text: this.text(
          'selfstudy:teacher:opens',
          'Öffnet am {$date}',
          {date: formatDate(assignment.timeOpenMs)},
        ),
      }));
    }
    const actions = liveElement('div', 'quizgeist-assignment-row__actions');
    if (assignment.status === 'draft' || assignment.status === 'open') {
      const edit = studyButton(
        this.text('selfstudy:teacher:edit', 'Bearbeiten'),
        'secondary',
      );
      edit.addEventListener('click', () => {
        this.editing = assignment;
        this.formOpen = true;
        this.render();
        window.setTimeout(() => {
          this.stage.querySelector<HTMLInputElement>('[name="assignment-name"]')?.focus();
        }, 0);
      });
      actions.append(edit);
    }
    if (assignment.status === 'open') {
      const close = studyButton(
        this.text('selfstudy:teacher:close', 'Schließen'),
        'danger',
      );
      close.addEventListener('click', () => void this.closeAssignment(
        assignment,
        close,
      ));
      actions.append(close);
    }
    card.append(body, actions);
    return card;
  }

  private renderForm(assignment: TeacherAssignment | null): HTMLElement {
    const form = liveElement('form', 'quizgeist-assignment-form');
    form.setAttribute('aria-labelledby', 'quizgeist-assignment-form-heading');
    const title = liveElement('h3', 'quizgeist-assignment-form__title', {
      id: 'quizgeist-assignment-form-heading',
      text: assignment
        ? this.text('selfstudy:teacher:editheading', 'Zuweisung bearbeiten')
        : this.text('selfstudy:teacher:createheading', 'Neue Zuweisung'),
    });
    const nameLabel = liveElement('label', 'quizgeist-study-field');
    const name = liveElement('input', 'quizgeist-study-input', {
      maxlength: 255,
      name: 'assignment-name',
      required: true,
      type: 'text',
      value: assignment?.name || '',
    });
    nameLabel.append(
      liveElement('span', 'quizgeist-study-field__label', {
        text: this.text('selfstudy:teacher:name', 'Name'),
      }),
      name,
    );

    const modes = liveElement('fieldset', 'quizgeist-study-fieldset');
    modes.append(liveElement('legend', 'quizgeist-study-field__label', {
      text: this.text('selfstudy:teacher:mode', 'Lernmodus'),
    }));
    const modeGrid = liveElement('div', 'quizgeist-assignment-mode-grid');
    const modeInputs: HTMLInputElement[] = [];
    // [P11-C4-O4] / 2.6: ohne installiertes KI-Addon erscheint der
    // Sprech-Trainer GAR NICHT — kein gesperrter Koeder. Ist das Addon da und
    // die Lizenz abgelaufen, bleibt der Modus sichtbar und der Server lehnt
    // die Neuanlage mit Klartext ab; eine bestehende Sprechzuweisung laeuft
    // unveraendert weiter.
    const aiInstalled = this.config.features?.ai?.installed === true;
    const offeredModes = STUDY_MODES.filter(
      (mode) => aiInstalled || !AI_STUDY_MODES.includes(mode),
    );
    offeredModes.forEach((mode) => {
      const label = liveElement('label', 'quizgeist-assignment-mode-option');
      const radio = liveElement('input', '', {
        checked: (assignment?.mode || 'practice') === mode,
        name: 'assignment-mode',
        type: 'radio',
        value: mode,
      });
      modeInputs.push(radio);
      label.append(
        radio,
        liveElement('strong', '', {text: modeLabel(this.config, mode)}),
        liveElement('span', '', {
          text: this.modeDescription(mode),
        }),
      );
      modeGrid.append(label);
    });
    modes.append(modeGrid);
    const multistageQuestionCount = assignment
      ? assignment.multistageQuestionCount
      : this.list?.readyMultistageQuestionCount || 0;
    const multistageWarning = multistageQuestionCount > 0
      ? liveElement('p', 'quizgeist-assignment-readiness', {
        role: 'note',
        text: this.text(
          'selfstudy:teacher:multistagewarning',
          '{$count} mehrstufige Fragen werden im Selbstlernen jeweils auf ihre erste Eingabestufe vereinfacht.',
          {count: multistageQuestionCount},
        ),
      })
      : null;

    const timing = liveElement('fieldset', 'quizgeist-study-fieldset');
    timing.append(liveElement('legend', 'quizgeist-study-field__label', {
      text: this.text('selfstudy:teacher:timing', 'Zeitraum'),
    }));
    const hasOpen = Boolean(assignment && assignment.timeOpenMs > 0);
    const openToggle = liveElement('input', '', {
      checked: hasOpen,
      id: 'quizgeist-assignment-has-open',
      type: 'checkbox',
    });
    const openInput = liveElement('input', 'quizgeist-study-input', {
      disabled: !hasOpen,
      id: 'quizgeist-assignment-open',
      type: 'datetime-local',
      value: localDateTime(assignment?.timeOpenMs || 0),
    });
    openToggle.addEventListener('change', () => {
      openInput.disabled = !openToggle.checked;
      if (openToggle.checked && openInput.value === '') {
        openInput.value = localDateTime(Date.now() + 60 * 60 * 1000);
      }
    });
    const dueToggle = liveElement('input', '', {
      checked: Boolean(assignment && assignment.timeDueMs > 0),
      id: 'quizgeist-assignment-has-due',
      type: 'checkbox',
    });
    const dueInput = liveElement('input', 'quizgeist-study-input', {
      disabled: !dueToggle.checked,
      id: 'quizgeist-assignment-due',
      type: 'datetime-local',
      value: localDateTime(assignment?.timeDueMs || 0),
    });
    dueToggle.addEventListener('change', () => {
      dueInput.disabled = !dueToggle.checked;
      if (dueToggle.checked && dueInput.value === '') {
        dueInput.value = localDateTime(Date.now() + 7 * 24 * 60 * 60 * 1000);
      }
    });
    timing.append(
      this.toggleDateField(
        openToggle,
        openInput,
        this.text('selfstudy:teacher:openlater', 'Erst später öffnen'),
        this.text('selfstudy:teacher:openat', 'Öffnen am'),
      ),
      this.toggleDateField(
        dueToggle,
        dueInput,
        this.text('selfstudy:teacher:deadline', 'Frist setzen'),
        this.text('selfstudy:teacher:dueat', 'Fällig am'),
      ),
    );

    const assignmentSettings = assignment?.settings || {
      allowLate: false,
      countsTowardsGrade: true,
      maxAttempts: 3,
      maxQuestions: 0,
      reminderEnabled: true,
      selectionStrategy: 'sequential' as const,
    };
    const settings = liveElement('fieldset', 'quizgeist-study-fieldset');
    settings.append(liveElement('legend', 'quizgeist-study-field__label', {
      text: this.text('selfstudy:teacher:settings', 'Versuche und Bewertung'),
    }));
    const maxAttempts = liveElement(
      'input',
      'quizgeist-study-input quizgeist-study-input--number',
      {
        'aria-describedby': 'quizgeist-assignment-max-attempts-help',
        id: 'quizgeist-assignment-max-attempts',
        inputmode: 'numeric',
        max: 10,
        min: 1,
        required: true,
        type: 'number',
        value: assignmentSettings.maxAttempts,
      },
    );
    const maxAttemptsField = liveElement('label', 'quizgeist-study-field');
    maxAttemptsField.append(
      liveElement('span', 'quizgeist-study-field__label', {
        text: this.text(
          'selfstudy:teacher:maxattempts',
          'Maximale Versuche je Lernendem',
        ),
      }),
      maxAttempts,
      liveElement('span', 'quizgeist-study-copy', {
        id: 'quizgeist-assignment-max-attempts-help',
        text: this.text(
          'selfstudy:teacher:maxattemptshelp',
          'Ein weiterer Versuch wird nur über „Erneut versuchen“ gestartet.',
        ),
      }),
    );
    const countsTowardsGrade = liveElement('input', '', {
      checked: assignmentSettings.countsTowardsGrade,
      type: 'checkbox',
    });
    const countsTowardsGradeField = liveElement(
      'label',
      'quizgeist-study-toggle',
    );
    countsTowardsGradeField.append(
      countsTowardsGrade,
      liveElement('span', '', {
        text: this.text(
          'selfstudy:teacher:countstowardsgrade',
          'Solo- und Testversuche in die Bewertung einbeziehen',
        ),
      }),
    );
    const allowLate = liveElement('input', '', {
      checked: assignmentSettings.allowLate,
      type: 'checkbox',
    });
    const allowLateField = liveElement('label', 'quizgeist-study-toggle');
    allowLateField.append(
      allowLate,
      liveElement('span', '', {
        text: this.text(
          'selfstudy:teacher:allowlate',
          'Antworten nach der Frist erlauben',
        ),
      }),
    );
    const reminderEnabled = liveElement('input', '', {
      checked: assignmentSettings.reminderEnabled,
      type: 'checkbox',
    });
    const reminderField = liveElement('label', 'quizgeist-study-toggle');
    reminderField.append(
      reminderEnabled,
      liveElement('span', '', {
        text: this.text(
          'selfstudy:teacher:reminderenabled',
          '24 Stunden vor der Frist erinnern',
        ),
      }),
    );
    // F3/F4: Wiederholungsart und Ziehstrategie. Beides ist beim Anlegen
    // waehlbar und danach Teil der eingefrorenen Zusage; eine bestehende
    // Zuweisung aendert ihre Auswahlart nie.
    const isreview = (assignment?.selection ?? 'fixed') === 'due';
    const reviewToggle = liveElement('input', '', {
      checked: isreview,
      disabled: Boolean(assignment),
      type: 'checkbox',
    });
    const reviewField = liveElement('label', 'quizgeist-study-toggle');
    reviewField.append(
      reviewToggle,
      liveElement('span', '', {
        text: this.text(
          'selfstudy:teacher:review',
          'Wiederholung: fällige Fragen statt fester Liste',
        ),
      }),
    );
    const strategySelect = liveElement(
      'select',
      'quizgeist-study-select',
      {id: 'quizgeist-assignment-strategy'},
    );
    ([
      ['sequential', this.text(
        'selfstudy:teacher:strategy:sequential',
        'In der vorgegebenen Reihenfolge',
      )],
      ['shuffled', this.text(
        'selfstudy:teacher:strategy:shuffled',
        'Zufällig gemischt',
      )],
      ['interleaved', this.text(
        'selfstudy:teacher:strategy:interleaved',
        'Themen bewusst durchmischt (Interleaving)',
      )],
    ] as Array<[SelectionStrategy, string]>).forEach(([value, label]) => {
      strategySelect.append(liveElement('option', '', {
        selected: assignmentSettings.selectionStrategy === value,
        text: label,
        value,
      }));
    });
    const strategyField = liveElement('label', 'quizgeist-study-field');
    strategyField.append(
      liveElement('span', 'quizgeist-study-field__label', {
        text: this.text(
          'selfstudy:teacher:strategy',
          'Reihenfolge der Fragen',
        ),
      }),
      strategySelect,
      liveElement('span', 'quizgeist-study-copy', {
        text: this.text(
          'selfstudy:teacher:strategyhelp',
          'Gemischtes Üben fühlt sich schwerer an und bleibt länger im Gedächtnis.',
        ),
      }),
    );
    settings.append(
      maxAttemptsField,
      reviewField,
      strategyField,
      countsTowardsGradeField,
      allowLateField,
      reminderField,
    );
    const updateSettingsAvailability = (): void => {
      const selectedMode = modeInputs.find((input) => input.checked)?.value;
      countsTowardsGrade.disabled = selectedMode !== 'solo'
        && selectedMode !== 'test';
      allowLate.disabled = !dueToggle.checked;
      reminderEnabled.disabled = !dueToggle.checked;
    };
    modeInputs.forEach((input) => {
      input.addEventListener('change', updateSettingsAvailability);
    });
    dueToggle.addEventListener('change', updateSettingsAvailability);
    updateSettingsAvailability();

    const status = liveElement('label', 'quizgeist-study-field');
    const statusSelect = liveElement('select', 'quizgeist-study-input', {
      name: 'assignment-status',
    });
    statusSelect.append(
      liveElement('option', '', {
        selected: assignment?.status === 'draft',
        text: this.text('selfstudy:status:draft', 'Entwurf'),
        value: 'draft',
      }),
      liveElement('option', '', {
        selected: assignment?.status !== 'draft',
        text: this.text('selfstudy:status:open', 'Offen'),
        value: 'open',
      }),
    );
    status.append(
      liveElement('span', 'quizgeist-study-field__label', {
        text: this.text('selfstudy:teacher:status', 'Status'),
      }),
      statusSelect,
    );

    const error = liveElement('p', 'quizgeist-study-form-error', {
      'aria-live': 'polite',
      role: 'alert',
    });
    const actions = liveElement('div', 'quizgeist-assignment-form__actions');
    const cancel = studyButton(
      this.text('selfstudy:teacher:cancel', 'Abbrechen'),
      'quiet',
    );
    cancel.addEventListener('click', () => {
      this.editing = null;
      this.formOpen = false;
      this.render();
    });
    const save = studyButton(
      this.text('selfstudy:teacher:save', 'Zuweisung speichern'),
      'primary',
    );
    save.type = 'submit';
    actions.append(cancel, save);
    form.append(title, nameLabel, modes);
    if (multistageWarning) {
      form.append(multistageWarning);
    }
    form.append(timing, settings, status, error, actions);
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const selectedMode = form.querySelector<HTMLInputElement>(
        '[name="assignment-mode"]:checked',
      )?.value;
      const mode = STUDY_MODES.includes(selectedMode as StudyMode)
        ? selectedMode as StudyMode
        : null;
      const timeOpen = timestampSeconds(openInput, openToggle.checked);
      const timeDue = timestampSeconds(dueInput, dueToggle.checked);
      const maxAttemptCount = Number(maxAttempts.value);
      if (name.value.trim() === '' || !mode) {
        error.textContent = this.text(
          'selfstudy:teacher:invalid',
          'Bitte füllen Sie alle Pflichtfelder aus.',
        );
        name.focus();
        return;
      }
      if (openToggle.checked && timeOpen <= 0) {
        openInput.focus();
        openInput.reportValidity();
        return;
      }
      if (dueToggle.checked && timeDue <= 0) {
        dueInput.focus();
        dueInput.reportValidity();
        return;
      }
      if (timeDue > 0 && timeOpen > 0 && timeDue <= timeOpen) {
        error.textContent = this.text(
          'selfstudy:teacher:invaliddeadline',
          'Die Frist muss nach dem Öffnungszeitpunkt liegen.',
        );
        dueInput.focus();
        return;
      }
      if (!Number.isInteger(maxAttemptCount)
          || maxAttemptCount < 1
          || maxAttemptCount > 10) {
        maxAttempts.focus();
        maxAttempts.reportValidity();
        return;
      }
      const strategy = (STRATEGIES as readonly string[]).includes(
        strategySelect.value,
      )
        ? strategySelect.value as SelectionStrategy
        : 'sequential';
      const input: AssignmentInput = {
        ...(assignment ? {
          id: assignment.id,
          timeModified: assignment.timeModified,
        } : {}),
        mode,
        name: name.value.trim(),
        ...(assignment
          ? {}
          : {selection: reviewToggle.checked ? 'due' as const : 'fixed' as const}),
        settings: {
          allowLate: dueToggle.checked && allowLate.checked,
          countsTowardsGrade: (mode === 'solo' || mode === 'test')
            && countsTowardsGrade.checked,
          maxAttempts: maxAttemptCount,
          maxQuestions: assignmentSettings.maxQuestions,
          reminderEnabled: dueToggle.checked && reminderEnabled.checked,
          selectionStrategy: strategy,
        },
        status: statusSelect.value === 'draft' ? 'draft' : 'open',
        timeDue,
        timeOpen,
      };
      void this.saveAssignment(input, save, error);
    });
    return form;
  }

  private toggleDateField(
    toggle: HTMLInputElement,
    input: HTMLInputElement,
    toggleLabel: string,
    inputLabel: string,
  ): HTMLElement {
    const group = liveElement('div', 'quizgeist-assignment-date-field');
    const toggleWrapper = liveElement('label', 'quizgeist-study-toggle');
    toggleWrapper.append(toggle, liveElement('span', '', {text: toggleLabel}));
    const inputWrapper = liveElement('label', 'quizgeist-study-field');
    inputWrapper.append(
      liveElement('span', 'quizgeist-study-field__label', {text: inputLabel}),
      input,
    );
    group.append(toggleWrapper, inputWrapper);
    return group;
  }

  private modeDescription(mode: StudyMode): string {
    const descriptions: Record<StudyMode, string> = {
      flashcards: 'Gewusst-/Nicht-gewusst-Stapel mit Wiederholrunde',
      practice: 'Direkte Rückmeldung ohne Zeitdruck-Punkte',
      solo: 'Tempo-Punkte; richtige Antworten behalten mindestens 50 % der Basispunkte',
      test: 'Rücknavigation, gesamte Auswertung erst am Ende',
      speaking: 'Rundenweise sprechen üben: vorlesen lassen, antworten, Rückmeldung bekommen',
    };
    return this.text(`selfstudy:mode:${mode}:description`, descriptions[mode]);
  }

  private async saveAssignment(
    assignment: AssignmentInput,
    button: HTMLButtonElement,
    errorNode: HTMLElement,
  ): Promise<void> {
    if (this.busy) {
      return;
    }
    this.setBusy(true, button);
    errorNode.textContent = '';
    try {
      this.list = assignment.id
        ? await this.api.assignmentUpdate(assignment)
        : await this.api.assignmentCreate(assignment);
      this.editing = null;
      this.formOpen = false;
      this.busy = false;
      this.render();
      this.announce(this.text(
        'selfstudy:teacher:saved',
        'Zuweisung gespeichert.',
      ));
    } catch (error) {
      this.setBusy(false, button);
      errorNode.textContent = error instanceof SelfStudyApiError
        && error.status === 409
        ? this.text(
          'selfstudy:teacher:conflict',
          'Die Zuweisung wurde inzwischen geändert. Laden Sie die Liste neu '
            + 'und versuchen Sie es erneut.',
        )
        : this.api.errorMessage(error);
    }
  }

  private async closeAssignment(
    assignment: TeacherAssignment,
    button: HTMLButtonElement,
  ): Promise<void> {
    if (this.busy || !window.confirm(this.text(
      'selfstudy:teacher:closeconfirm',
      'Diese Zuweisung schließen? Bereits gespeicherte Ergebnisse bleiben erhalten.',
    ))) {
      return;
    }
    this.setBusy(true, button);
    try {
      this.list = await this.api.assignmentClose(
        assignment.id,
        assignment.timeModified,
      );
      this.busy = false;
      this.render();
      this.announce(this.text(
        'selfstudy:teacher:closed',
        'Zuweisung geschlossen.',
      ));
    } catch (error) {
      this.setBusy(false, button);
      this.announce(this.api.errorMessage(error));
    }
  }

  private setBusy(busy: boolean, button: HTMLButtonElement): void {
    this.busy = busy;
    button.disabled = busy;
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
  }
}

export function mountAssignmentTeacherApp(
  root: HTMLElement,
  config: SelfStudyConfig,
): void {
  const app = new AssignmentTeacherApp(root, config);
  void app.init();
}
