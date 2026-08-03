import {appendChildren, button, element, labelledField} from './dom';
import {editorString, validationMessages} from './messages';
import type {
  AnswerOption,
  EditorConfig,
  EditorQuestion,
  MediaFile,
  PinOptions,
  PointMode,
  PuzzleItem,
  QuestionType,
  SlideLayout,
  ValidationErrors,
} from './types';

interface QuestionFormCallbacks {
  isAiAvailable: () => boolean;
  onAiExplanation: () => void;
  onChange: () => void;
  onMedia: (target: string) => void;
  onPreview: () => void;
}

const NON_SCORING_TYPES: QuestionType[] = [
  'poll',
  'wordcloud',
  'scale',
  'brainstorm',
  'open',
  'slide',
];

export class QuestionFormRenderer {
  private draggedPuzzleId: string | null = null;
  private readonly issuedItemIds = new Set<string>();
  private itemIdSequence = 0;

  public constructor(
    private readonly config: EditorConfig,
    private readonly callbacks: QuestionFormCallbacks,
  ) {
  }

  private s(key: string, fallback = ''): string {
    return editorString(this.config.strings, key, fallback);
  }

  private label(key: string, fallback: string): string {
    return editorString(this.config.strings, key, fallback);
  }

  public render(question: EditorQuestion): HTMLElement {
    const panel = element('section', 'quizgeist-question-form');
    panel.dataset.questionId = String(question.id);
    const headingRow = element('div', 'quizgeist-question-form__heading');
    const headingText = element('div');
    const eyebrow = element('p', 'quizgeist-eyebrow', {
      text: this.s(`editor:qtype:${question.qtype}`),
    });
    const heading = element('h2', 'quizgeist-question-form__title', {
      text: this.questionDisplayTitle(question),
    });
    appendChildren(headingText, eyebrow, heading);
    const previewButton = button(
      this.s('editor:action:preview'),
      'quizgeist-button quizgeist-button--secondary',
      this.callbacks.onPreview,
    );
    const headingActions = element('div', 'quizgeist-question-form__actions');
    if (question.id > 0 && this.callbacks.isAiAvailable()) {
      const explanationButton = button(
        this.label(
          'editor:ai:explanation:action',
          'KI-Lösungsweg vorschlagen',
        ),
        'quizgeist-button quizgeist-button--secondary',
        this.callbacks.onAiExplanation,
      );
      explanationButton.dataset.action = 'ai-explanation';
      headingActions.append(explanationButton);
    }
    headingActions.append(previewButton);
    headingRow.append(headingText, headingActions);
    panel.append(headingRow);

    const validationSummary = this.renderValidationSummary(question);
    if (validationSummary) {
      panel.append(validationSummary);
    }

    const form = element('div', 'quizgeist-question-form__fields');
    if (question.qtype !== 'slide') {
      const questionText = element('textarea', 'quizgeist-textarea', {
        rows: 3,
        maxlength: 4000,
        value: question.questiontext,
      });
      questionText.addEventListener('input', () => {
        question.questiontext = questionText.value;
        this.callbacks.onChange();
      });
      form.append(labelledField(
        this.s('editor:field:questiontext'),
        questionText,
        this.s('editor:field:questiontext:hint'),
      ));
    }

    form.append(this.renderMediaControl(
      question,
      () => this.getMedia(question),
      this.s(question.qtype === 'pin' || question.qtype === 'reveal'
        ? 'editor:field:media:required'
        : 'editor:field:media'),
    ));

    const typeFields = element('div', 'quizgeist-type-fields');
    switch (question.qtype) {
      case 'quiz':
        this.renderQuiz(question, typeFields);
        break;
      case 'truefalse':
        this.renderTrueFalse(question, typeFields);
        break;
      case 'shortanswer':
        this.renderShortAnswer(question, typeFields);
        break;
      case 'puzzle':
        this.renderPuzzle(question, typeFields);
        break;
      case 'poll':
        this.renderPoll(question, typeFields);
        break;
      case 'wordcloud':
        this.renderWordcloud(question, typeFields);
        break;
      case 'scale':
        this.renderScale(question, typeFields);
        break;
      case 'slider':
        this.renderSlider(question, typeFields);
        break;
      case 'pin':
        this.renderPin(question, typeFields);
        break;
      case 'reveal':
        this.renderReveal(question, typeFields);
        break;
      case 'brainstorm':
        this.renderBrainstorm(question, typeFields);
        break;
      case 'open':
        this.renderOpen(question, typeFields);
        break;
      case 'slide':
        this.renderSlide(question, typeFields);
        break;
    }
    form.append(typeFields);

    const metadata = element('div', 'quizgeist-field-grid quizgeist-field-grid--three');
    metadata.append(
      this.renderTimeLimit(question),
      this.renderPointMode(question),
    );
    const explanation = element('textarea', 'quizgeist-textarea', {
      rows: 3,
      maxlength: 6000,
      value: question.explanation,
    });
    explanation.addEventListener('input', () => {
      question.explanation = explanation.value;
      this.callbacks.onChange();
    });
    metadata.append(labelledField(
      this.s('editor:field:explanation'),
      explanation,
      this.s('editor:field:explanation:hint'),
    ));
    form.append(metadata);
    panel.append(form);
    return panel;
  }

  public renderValidationSummary(question: EditorQuestion): HTMLElement | null {
    const errors = this.flattenErrors(question.validationErrors);
    if (errors.length === 0) {
      return null;
    }
    const errorBox = element('div', 'quizgeist-validation-summary', {
      role: 'alert',
      tabindex: '-1',
    });
    const errorHeading = element('h3', 'quizgeist-validation-summary__title', {
      text: this.s('editor:validation:title'),
    });
    const list = element('ul');
    for (const error of errors) {
      list.append(element('li', '', {text: error}));
    }
    errorBox.append(errorHeading, list);
    return errorBox;
  }

  private questionDisplayTitle(question: EditorQuestion): string {
    if (question.qtype === 'slide') {
      const options = question.options as {
        title: string;
      };
      return options.title.trim()
        || this.s('editor:question:untitled');
    }
    return question.questiontext.trim()
      || this.s('editor:question:untitled');
  }

  private flattenErrors(
    value: ValidationErrors,
  ): string[] {
    return validationMessages(this.config.strings, value);
  }

  private renderQuiz(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {
      answers: AnswerOption[];
      multiple: boolean;
    };
    target.append(this.renderSwitch(
      this.s('editor:field:multiple'),
      this.s('editor:field:multiple:hint'),
      options.multiple,
      (checked) => {
        options.multiple = checked;
        if (!checked) {
          const firstCorrect = options.answers.findIndex((answer) => answer.correct);
          options.answers.forEach((answer, index) => {
            answer.correct = index === Math.max(0, firstCorrect);
          });
        }
        this.callbacks.onChange();
        this.refreshCurrentForm(question);
      },
    ));
    target.append(this.renderAnswers(question, options.answers, true));
  }

  private renderTrueFalse(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {correct: boolean};
    const fieldset = element('fieldset', 'quizgeist-choice-field');
    fieldset.append(element('legend', 'quizgeist-field__label', {
      text: this.s('editor:field:correctanswer'),
    }));
    const choices = element('div', 'quizgeist-segmented');
    for (const [value, key] of [
      [true, 'editor:true'],
      [false, 'editor:false'],
    ] as const) {
      const label = element('label', 'quizgeist-segmented__item');
      const input = element('input', '', {
        type: 'radio',
        name: `truefalse-${question.id}`,
        checked: options.correct === value,
      });
      input.addEventListener('change', () => {
        if (input.checked) {
          options.correct = value;
          this.callbacks.onChange();
        }
      });
      appendChildren(label, input, element('span', '', {text: this.s(key)}));
      choices.append(label);
    }
    fieldset.append(choices);
    target.append(fieldset);
  }

  private renderShortAnswer(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {
      acceptedAnswers: string[];
      typoTolerance: boolean;
    };
    target.append(
      this.renderStringList(
        question,
        this.s('editor:field:acceptedanswers'),
        options.acceptedAnswers,
        this.s('editor:action:addacceptedanswer'),
        12,
      ),
      this.renderSwitch(
        this.s('editor:field:typotolerance'),
        this.s('editor:field:typotolerance:hint'),
        options.typoTolerance,
        (checked) => {
          options.typoTolerance = checked;
          this.callbacks.onChange();
        },
      ),
    );
  }

  private renderPuzzle(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {items: PuzzleItem[]};
    const wrapper = element('fieldset', 'quizgeist-repeater');
    wrapper.append(element('legend', 'quizgeist-field__label', {
      text: this.s('editor:field:puzzleitems'),
    }));
    const hint = element('p', 'quizgeist-field__hint', {
      text: this.s('editor:field:puzzleitems:hint'),
    });
    wrapper.append(hint);
    options.items.forEach((item, index) => {
      const row = element('div', 'quizgeist-repeater__row');
      row.dataset.puzzleItemId = item.id;
      row.draggable = true;
      const indexLabel = element('span', 'quizgeist-repeater__index', {text: String(index + 1)});
      const dragHandle = element('span', 'quizgeist-drag-handle', {
        title: this.s('editor:action:drag'),
        role: 'img',
        'aria-label': this.s('editor:action:drag'),
      });
      const input = element('input', 'quizgeist-input', {
        type: 'text',
        maxlength: 500,
        value: item.text,
        'aria-label': `${this.s('editor:field:puzzleitem')} ${index + 1}`,
      });
      input.addEventListener('input', () => {
        item.text = input.value;
        this.callbacks.onChange();
      });
      const media = this.renderInlineMediaControl(
        question,
        item.media,
        item.id,
        index + 1,
      );
      const up = button(
        this.s('editor:action:up'),
        'quizgeist-button quizgeist-button--quiet',
        () => this.moveArrayItem(question, options.items, index, index - 1),
      );
      up.disabled = index === 0;
      const down = button(
        this.s('editor:action:down'),
        'quizgeist-button quizgeist-button--quiet',
        () => this.moveArrayItem(question, options.items, index, index + 1),
      );
      down.disabled = index === options.items.length - 1;
      const remove = button(
        this.s('editor:action:remove'),
        'quizgeist-button quizgeist-button--danger-quiet',
        () => {
          if (options.items.length <= 2) {
            return;
          }
          options.items.splice(index, 1);
          this.callbacks.onChange();
          this.refreshCurrentForm(question);
        },
      );
      remove.disabled = options.items.length <= 2;
      row.append(indexLabel, dragHandle, input, media, up, down, remove);
      row.addEventListener('dragstart', (event) => {
        if (event.target instanceof HTMLInputElement
            || event.target instanceof HTMLButtonElement
            || event.target instanceof HTMLSelectElement) {
          event.preventDefault();
          return;
        }
        this.draggedPuzzleId = item.id;
        row.classList.add('is-dragging');
        event.dataTransfer?.setData('text/plain', item.id);
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
        }
      });
      row.addEventListener('dragend', () => {
        this.draggedPuzzleId = null;
        row.classList.remove('is-dragging');
        row.parentElement?.querySelectorAll('.is-drag-target')
          .forEach((node) => node.classList.remove('is-drag-target'));
      });
      row.addEventListener('dragover', (event) => {
        if (this.draggedPuzzleId === null || this.draggedPuzzleId === item.id) {
          return;
        }
        event.preventDefault();
        row.classList.add('is-drag-target');
      });
      row.addEventListener('dragleave', () => row.classList.remove('is-drag-target'));
      row.addEventListener('drop', (event) => {
        event.preventDefault();
        row.classList.remove('is-drag-target');
        const source = options.items.findIndex(
          (candidate) => candidate.id === this.draggedPuzzleId,
        );
        const destination = options.items.findIndex(
          (candidate) => candidate.id === item.id,
        );
        this.draggedPuzzleId = null;
        this.moveArrayItem(question, options.items, source, destination);
      });
      wrapper.append(row);
    });
    const add = button(
      this.s('editor:action:addpuzzleitem'),
      'quizgeist-button quizgeist-button--secondary',
      () => {
        if (options.items.length >= 6) {
          return;
        }
        options.items.push({
          id: this.nextId(options.items),
          text: '',
          media: null,
        });
        this.callbacks.onChange();
        this.refreshCurrentForm(question);
      },
    );
    add.disabled = options.items.length >= 6;
    wrapper.append(add);
    target.append(wrapper);
  }

  private renderPoll(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {answers: AnswerOption[]; multiple: boolean};
    const note = element('div', 'quizgeist-info-box', {
      text: this.s('editor:poll:nopoints'),
    });
    const multiple = this.renderSwitch(
      this.s('editor:field:multiple'),
      this.s('editor:field:multiple:hint'),
      options.multiple,
      (checked) => {
        options.multiple = checked;
        this.callbacks.onChange();
      },
    );
    target.append(note, multiple, this.renderAnswers(question, options.answers, false));
  }

  private renderWordcloud(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {maxChars: number; moderation: boolean};
    const grid = element('div', 'quizgeist-field-grid');
    grid.append(
      this.numberField(
        this.s('editor:field:maxchars'),
        options.maxChars,
        10,
        120,
        1,
        (value) => {
          options.maxChars = value;
          this.callbacks.onChange();
        },
      ),
      this.renderSwitch(
        this.s('editor:field:moderation'),
        this.s('editor:field:moderation:hint'),
        options.moderation,
        (checked) => {
          options.moderation = checked;
          this.callbacks.onChange();
        },
      ),
    );
    target.append(grid);
  }

  private renderScale(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {
      maxLabel: string;
      minLabel: string;
      steps: number;
    };
    const grid = element('div', 'quizgeist-field-grid quizgeist-field-grid--three');
    const minLabel = element('input', 'quizgeist-input', {
      type: 'text',
      maxlength: 120,
      value: options.minLabel,
    });
    minLabel.addEventListener('input', () => {
      options.minLabel = minLabel.value;
      this.callbacks.onChange();
    });
    const maxLabel = element('input', 'quizgeist-input', {
      type: 'text',
      maxlength: 120,
      value: options.maxLabel,
    });
    maxLabel.addEventListener('input', () => {
      options.maxLabel = maxLabel.value;
      this.callbacks.onChange();
    });
    grid.append(
      labelledField(this.s('editor:field:minlabel'), minLabel),
      labelledField(this.s('editor:field:maxlabel'), maxLabel),
      this.rangeField(
        this.s('editor:field:steps'),
        options.steps,
        3,
        10,
        1,
        (value) => {
          options.steps = value;
          this.callbacks.onChange();
        },
      ),
    );
    target.append(grid);
  }

  private renderSlider(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {
      max: number;
      min: number;
      step: number;
      target: number;
      tolerance: number;
    };
    const grid = element('div', 'quizgeist-field-grid quizgeist-field-grid--five');
    const fields: Array<[string, keyof typeof options, number, number]> = [
      ['editor:field:min', 'min', -1000000, 1000000],
      ['editor:field:max', 'max', -1000000, 1000000],
      ['editor:field:step', 'step', 0.001, 1000000],
      ['editor:field:target', 'target', -1000000, 1000000],
      ['editor:field:tolerance', 'tolerance', 0, 1000000],
    ];
    for (const [label, key, min, max] of fields) {
      grid.append(this.numberField(
        this.s(label),
        options[key],
        min,
        max,
        key === 'step' ? 0.001 : 1,
        (value) => {
          options[key] = value;
          this.callbacks.onChange();
        },
      ));
    }
    target.append(grid);
  }

  private renderPin(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as PinOptions;
    const media = this.selectedMedia(question, options.media);
    if (media) {
      const canvas = element('button', 'quizgeist-pin-target', {
        type: 'button',
        'aria-label': this.s('editor:field:pintarget:hint'),
      });
      const image = element('img', 'quizgeist-pin-target__image', {
        src: media.url,
        alt: '',
      });
      canvas.append(image);
      if (options.hasTarget !== false) {
        const marker = element('span', 'quizgeist-pin-target__marker', {
          'aria-hidden': 'true',
        });
        marker.style.left = `${options.target.x}%`;
        marker.style.top = `${options.target.y}%`;
        canvas.append(marker);
        canvas.addEventListener('click', (event) => {
          const rectangle = canvas.getBoundingClientRect();
          options.target.x = Math.round(
            Math.max(0, Math.min(100, (event.clientX - rectangle.left) * 100 / rectangle.width)),
          );
          options.target.y = Math.round(
            Math.max(0, Math.min(100, (event.clientY - rectangle.top) * 100 / rectangle.height)),
          );
          this.callbacks.onChange();
          this.refreshCurrentForm(question);
        });
      }
      target.append(canvas);
    } else {
      target.append(element('div', 'quizgeist-media-empty', {
        text: this.s('editor:pin:mediarequired'),
      }));
    }
    if (options.hasTarget === false) {
      target.append(element('p', 'quizgeist-field-hint', {
        text: this.s('editor:pin:heatmap'),
      }));
      return;
    }
    const grid = element('div', 'quizgeist-field-grid quizgeist-field-grid--three');
    grid.append(
      this.numberField(
        this.s('editor:field:targetx'),
        options.target.x,
        0,
        100,
        1,
        (value) => {
          options.target.x = value;
          this.callbacks.onChange();
        },
      ),
      this.numberField(
        this.s('editor:field:targety'),
        options.target.y,
        0,
        100,
        1,
        (value) => {
          options.target.y = value;
          this.callbacks.onChange();
        },
      ),
      this.rangeField(
        this.s('editor:field:radius'),
        options.radius,
        1,
        50,
        1,
        (value) => {
          options.radius = value;
          this.callbacks.onChange();
        },
        this.s('editor:unit:percent'),
      ),
    );
    target.append(grid);
  }

  private renderReveal(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {
      acceptedAnswers: string[];
      grid: number;
      revealSeconds: number;
    };
    const grid = element('div', 'quizgeist-field-grid');
    grid.append(
      this.rangeField(
        this.s('editor:field:grid'),
        options.grid,
        3,
        6,
        1,
        (value) => {
          options.grid = value;
          this.callbacks.onChange();
        },
        this.s('editor:unit:grid'),
        (value) => `${value} \u00d7 ${value}`,
      ),
      this.rangeField(
        this.s('editor:field:revealseconds'),
        options.revealSeconds,
        1,
        30,
        1,
        (value) => {
          options.revealSeconds = value;
          this.callbacks.onChange();
        },
        this.s('editor:unit:seconds'),
      ),
    );
    target.append(
      grid,
      this.renderStringList(
        question,
        this.s('editor:field:acceptedanswers'),
        options.acceptedAnswers,
        this.s('editor:action:addacceptedanswer'),
        12,
      ),
    );
  }

  private renderBrainstorm(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {
      collectSeconds: number;
      grouping: 'manual' | 'ai';
      voteSeconds: number;
    };
    const grid = element('div', 'quizgeist-field-grid quizgeist-field-grid--three');
    grid.append(
      this.numberField(
        this.s('editor:field:collectseconds'),
        options.collectSeconds,
        15,
        600,
        5,
        (value) => {
          options.collectSeconds = value;
          this.callbacks.onChange();
        },
        this.s('editor:unit:seconds'),
      ),
      this.selectField(
        this.s('editor:field:grouping'),
        options.grouping,
        [
          ['manual', this.s('editor:grouping:manual')],
          ['ai', this.s('editor:grouping:ai')],
        ],
        (value) => {
          options.grouping = value === 'ai' ? 'ai' : 'manual';
          this.callbacks.onChange();
        },
        this.s('editor:grouping:hint'),
      ),
      this.numberField(
        this.s('editor:field:voteseconds'),
        options.voteSeconds,
        15,
        600,
        5,
        (value) => {
          options.voteSeconds = value;
          this.callbacks.onChange();
        },
        this.s('editor:unit:seconds'),
      ),
    );
    target.append(grid);
  }

  private renderOpen(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {sampleAnswer: string};
    target.append(element('div', 'quizgeist-info-box', {
      text: this.s('editor:open:nopoints'),
    }));
    const sample = element('textarea', 'quizgeist-textarea', {
      rows: 4,
      maxlength: 6000,
      value: options.sampleAnswer,
    });
    sample.addEventListener('input', () => {
      options.sampleAnswer = sample.value;
      this.callbacks.onChange();
    });
    target.append(labelledField(
      this.s('editor:field:sampleanswer'),
      sample,
      this.s('editor:field:sampleanswer:hint'),
    ));
    this.renderStageCheckSwitch(question, target);
  }

  /**
   * F13 Bühnen-Check: the one teacher-facing switch of the sub-mode.
   *
   * Three states, in the order of P11_PLAN.md 2.6:
   *   - addon code absent      -> nothing is rendered at all (no locked bait)
   *   - licence read-only, off -> nothing is rendered either
   *   - licence read-only, on  -> rendered WITH a plain sentence: existing
   *     content stays editable and switchable-off, switching it back on needs
   *     an active licence. No raw code, no dead end (F16).
   *
   * @param question The edited question.
   * @param target Container of the type-specific fields.
   * @returns void
   */
  private renderStageCheckSwitch(
    question: EditorQuestion,
    target: HTMLElement,
  ): void {
    const availability = this.config.features?.buehne;
    const options = question.options as {stageCheck?: boolean};
    const current = options.stageCheck === true;
    if (availability === undefined || availability.installed !== true) {
      return;
    }
    if (availability.canCreate !== true && !current) {
      return;
    }
    const field = this.renderSwitch(
      this.s('editor:field:stagecheck'),
      this.s('editor:field:stagecheck:hint'),
      current,
      (checked) => {
        options.stageCheck = checked;
        this.callbacks.onChange();
      },
    );
    if (availability.canCreate !== true) {
      field.append(element('p', 'quizgeist-field__hint', {
        text: this.s('editor:field:stagecheck:locked'),
      }));
    }
    field.dataset.quizgeistView = 'stage-setup';
    target.append(field);
  }

  private renderSlide(question: EditorQuestion, target: HTMLElement): void {
    const options = question.options as {
      attribution: string;
      body: string;
      bullets: string[];
      layout: SlideLayout;
      quote: string;
      reactions: boolean;
      title: string;
    };
    const layout = this.selectField(
      this.s('editor:field:slidelayout'),
      options.layout,
      [
        ['title', this.s('editor:layout:title')],
        ['text-image', this.s('editor:layout:textimage')],
        ['bullets', this.s('editor:layout:bullets')],
        ['quote', this.s('editor:layout:quote')],
        ['video', this.s('editor:layout:video')],
        ['fullscreen', this.s('editor:layout:fullscreen')],
      ],
      (value) => {
        options.layout = value as SlideLayout;
        this.callbacks.onChange();
        this.refreshCurrentForm(question);
      },
    );
    target.append(layout);

    const title = element('input', 'quizgeist-input', {
      type: 'text',
      maxlength: 500,
      value: options.title,
    });
    title.addEventListener('input', () => {
      options.title = title.value;
      question.questiontext = title.value;
      this.callbacks.onChange();
    });
    target.append(labelledField(this.s('editor:field:slidetitle'), title));

    if (options.layout === 'bullets') {
      target.append(this.renderStringList(
        question,
        this.s('editor:field:bullets'),
        options.bullets,
        this.s('editor:action:addbullet'),
        12,
      ));
    } else if (options.layout === 'quote') {
      const quote = element('textarea', 'quizgeist-textarea', {
        rows: 4,
        maxlength: 3000,
        value: options.quote,
      });
      quote.addEventListener('input', () => {
        options.quote = quote.value;
        this.callbacks.onChange();
      });
      const attribution = element('input', 'quizgeist-input', {
        type: 'text',
        maxlength: 300,
        value: options.attribution,
      });
      attribution.addEventListener('input', () => {
        options.attribution = attribution.value;
        this.callbacks.onChange();
      });
      target.append(
        labelledField(this.s('editor:field:quote'), quote),
        labelledField(this.s('editor:field:attribution'), attribution),
      );
    } else {
      const body = element('textarea', 'quizgeist-textarea', {
        rows: 6,
        maxlength: 8000,
        value: options.body,
      });
      body.addEventListener('input', () => {
        options.body = body.value;
        this.callbacks.onChange();
      });
      target.append(labelledField(
        this.s(options.layout === 'video'
          ? 'editor:field:videocaption'
          : 'editor:field:slidebody'),
        body,
      ));
    }

    target.append(this.renderSwitch(
      this.s('editor:field:reactions'),
      this.s('editor:field:reactions:hint'),
      options.reactions,
      (checked) => {
        options.reactions = checked;
        this.callbacks.onChange();
      },
    ));
    this.renderStageCheckSwitch(question, target);
  }

  private renderAnswers(
    question: EditorQuestion,
    answers: AnswerOption[],
    correctable: boolean,
  ): HTMLElement {
    const options = question.options as {multiple?: boolean};
    const fieldset = element('fieldset', 'quizgeist-repeater quizgeist-answer-editor');
    fieldset.append(element('legend', 'quizgeist-field__label', {
      text: this.s('editor:field:answers'),
    }));
    answers.forEach((answer, index) => {
      const row = element('div', 'quizgeist-answer-row');
      row.dataset.answerSlot = String(index);
      const shape = element('span', `quizgeist-answer-shape quizgeist-answer-shape--${index + 1}`, {
        'aria-hidden': 'true',
      });
      const input = element('input', 'quizgeist-input', {
        type: 'text',
        maxlength: 1000,
        value: answer.text,
        'aria-label': `${this.s('editor:field:answer')} ${index + 1}`,
      });
      input.addEventListener('input', () => {
        answer.text = input.value;
        this.callbacks.onChange();
      });
      const media = this.renderInlineMediaControl(
        question,
        answer.media,
        answer.id,
        index + 1,
      );
      row.append(shape, input, media);

      if (correctable) {
        const correctLabel = element('label', 'quizgeist-correct-choice');
        const correct = element('input', '', {
          type: options.multiple ? 'checkbox' : 'radio',
          name: options.multiple ? undefined : `correct-answer-${question.id}`,
          checked: Boolean(answer.correct),
          'aria-label': `${this.s('editor:field:correct')} ${index + 1}`,
        });
        correct.addEventListener('change', () => {
          if (options.multiple) {
            answer.correct = correct.checked;
          } else if (correct.checked) {
            answers.forEach((item) => {
              item.correct = item === answer;
            });
          }
          this.callbacks.onChange();
          this.refreshCurrentForm(question);
        });
        appendChildren(correctLabel, correct, element('span', '', {
          text: this.s('editor:field:correct'),
        }));
        row.append(correctLabel);
      }

      const remove = button(
        this.s('editor:action:remove'),
        'quizgeist-button quizgeist-button--danger-quiet',
        () => {
          if (answers.length <= 2) {
            return;
          }
          answers.splice(index, 1);
          if (correctable && !answers.some((entry) => entry.correct)) {
            answers[0].correct = true;
          }
          this.callbacks.onChange();
          this.refreshCurrentForm(question);
        },
      );
      remove.disabled = answers.length <= 2;
      row.append(remove);
      fieldset.append(row);
    });
    const add = button(
      this.s('editor:action:addanswer'),
      'quizgeist-button quizgeist-button--secondary',
      () => {
        if (answers.length >= 6) {
          return;
        }
        answers.push({
          id: this.nextId(answers),
          text: '',
          media: null,
          ...(correctable ? {correct: false} : {}),
        });
        this.callbacks.onChange();
        this.refreshCurrentForm(question);
      },
    );
    add.disabled = answers.length >= 6;
    fieldset.append(add);
    return fieldset;
  }

  private renderStringList(
    question: EditorQuestion,
    labelText: string,
    values: string[],
    addLabel: string,
    maximum: number,
  ): HTMLElement {
    const fieldset = element('fieldset', 'quizgeist-repeater');
    fieldset.append(element('legend', 'quizgeist-field__label', {text: labelText}));
    values.forEach((value, index) => {
      const row = element('div', 'quizgeist-repeater__row quizgeist-repeater__row--simple');
      const input = element('input', 'quizgeist-input', {
        type: 'text',
        maxlength: 1000,
        value,
        'aria-label': `${labelText} ${index + 1}`,
      });
      input.addEventListener('input', () => {
        values[index] = input.value;
        this.callbacks.onChange();
      });
      const remove = button(
        this.s('editor:action:remove'),
        'quizgeist-button quizgeist-button--danger-quiet',
        () => {
          if (values.length <= 1) {
            return;
          }
          values.splice(index, 1);
          this.callbacks.onChange();
          this.refreshCurrentForm(question);
        },
      );
      remove.disabled = values.length <= 1;
      row.append(input, remove);
      fieldset.append(row);
    });
    const add = button(addLabel, 'quizgeist-button quizgeist-button--secondary', () => {
      if (values.length >= maximum) {
        return;
      }
      values.push('');
      this.callbacks.onChange();
      this.refreshCurrentForm(question);
    });
    add.disabled = values.length >= maximum;
    fieldset.append(add);
    return fieldset;
  }

  private renderMediaControl(
    question: EditorQuestion,
    getter: () => string | null,
    labelText: string,
  ): HTMLElement {
    const wrapper = element('div', 'quizgeist-media-control');
    const heading = element('div', 'quizgeist-media-control__heading');
    heading.append(
      element('span', 'quizgeist-field__label', {text: labelText}),
      element('span', 'quizgeist-field__hint', {text: this.s('editor:field:media:hint')}),
    );
    wrapper.append(heading);
    const manage = button(
      this.s('editor:action:managemedia'),
      'quizgeist-button quizgeist-button--secondary quizgeist-media-control__button',
      () => this.callbacks.onMedia('question'),
    );
    wrapper.append(manage);
    const selected = this.selectedMedia(question, getter());
    if (selected) {
      const preview = element('figure', 'quizgeist-media-preview');
      preview.append(
        this.renderMediaElement(selected),
        element('figcaption', '', {text: selected.filename}),
      );
      wrapper.append(preview);
    } else {
      wrapper.append(element('p', 'quizgeist-media-empty', {
        text: this.s('editor:media:none'),
      }));
    }
    return wrapper;
  }

  private renderInlineMediaControl(
    question: EditorQuestion,
    current: string | null,
    answerId: string,
    index: number,
  ): HTMLElement {
    const wrapper = element('div', 'quizgeist-answer-media');
    const selected = this.selectedMedia(question, current);
    if (selected) {
      wrapper.append(this.renderMediaElement(selected, true));
    }
    const choose = button(
      selected
        ? this.s('editor:action:changemedia')
        : this.s('editor:action:addmedia'),
      'quizgeist-button quizgeist-button--quiet quizgeist-answer-media__button',
      () => this.callbacks.onMedia(`answers/${answerId}`),
    );
    choose.setAttribute(
      'aria-label',
      `${this.s('editor:field:answerimage')} ${index}`,
    );
    wrapper.append(choose);
    return wrapper;
  }

  private renderMediaElement(file: MediaFile, compact = false): HTMLElement {
    const mimetype = file.mimetype || '';
    const extension = file.filename.split('.').pop()?.toLowerCase() || '';
    const className = compact
      ? 'quizgeist-media-preview__asset quizgeist-media-preview__asset--compact'
      : 'quizgeist-media-preview__asset';
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

  private mediaPath(file: MediaFile): string {
    if (file.path) {
      return file.path;
    }
    const filepath = file.filepath && file.filepath !== '/' ? file.filepath : '';
    return `${filepath}${file.filename}`;
  }

  private selectedMedia(question: EditorQuestion, value: string | null): MediaFile | null {
    if (!value) {
      return null;
    }
    return question.files.find((file) => {
      const path = this.mediaPath(file);
      return path === value || file.filename === value || file.url === value;
    }) || null;
  }

  private getMedia(question: EditorQuestion): string | null {
    return (question.options as {media: string | null}).media;
  }

  private renderTimeLimit(question: EditorQuestion): HTMLElement {
    if (question.qtype === 'slide') {
      question.timelimit = 0;
    }
    if (question.qtype === 'slide') {
      return this.rangeField(
        this.s('editor:field:timelimit'),
        5,
        5,
        240,
        5,
        () => undefined,
        '',
        () => this.s('editor:time:none'),
        true,
      );
    }
    return this.rangeField(
      this.s('editor:field:timelimit'),
      Math.max(5, Math.min(240, question.timelimit)),
      5,
      240,
      5,
      (value) => {
        question.timelimit = value;
        this.callbacks.onChange();
      },
      this.s('editor:unit:seconds'),
    );
  }

  private renderPointMode(question: EditorQuestion): HTMLElement {
    const disabled = NON_SCORING_TYPES.includes(question.qtype);
    if (disabled) {
      question.pointmode = 'none';
    }
    const field = this.selectField(
      this.s('editor:field:pointmode'),
      question.pointmode,
      [
        ['standard', this.s('editor:pointmode:standard')],
        ['double', this.s('editor:pointmode:double')],
        ['none', this.s('editor:pointmode:none')],
      ],
      (value) => {
        question.pointmode = value as PointMode;
        this.callbacks.onChange();
      },
      disabled ? this.s('editor:pointmode:forcednone') : undefined,
    );
    const select = field.querySelector('select');
    if (select) {
      select.disabled = disabled;
    }
    return field;
  }

  private renderSwitch(
    labelText: string,
    hint: string,
    checked: boolean,
    change: (value: boolean) => void,
  ): HTMLElement {
    const wrapper = element('div', 'quizgeist-switch-field');
    const label = element('label', 'quizgeist-switch');
    const input = element('input', 'quizgeist-switch__input', {
      type: 'checkbox',
      checked,
    });
    input.addEventListener('change', () => change(input.checked));
    const track = element('span', 'quizgeist-switch__track', {'aria-hidden': 'true'});
    const text = element('span', 'quizgeist-switch__label', {text: labelText});
    appendChildren(label, input, track, text);
    wrapper.append(label, element('p', 'quizgeist-field__hint', {text: hint}));
    return wrapper;
  }

  private selectField(
    labelText: string,
    value: string,
    values: Array<[string, string]>,
    change: (value: string) => void,
    hint?: string,
  ): HTMLElement {
    const select = element('select', 'quizgeist-select');
    for (const [optionValue, optionLabel] of values) {
      select.append(element('option', '', {
        value: optionValue,
        text: optionLabel,
      }));
    }
    select.value = value;
    select.addEventListener('change', () => change(select.value));
    return labelledField(labelText, select, hint);
  }

  private numberField(
    labelText: string,
    value: number,
    min: number,
    max: number,
    step: number,
    change: (value: number) => void,
    suffix?: string,
  ): HTMLElement {
    const input = element('input', 'quizgeist-input', {
      type: 'number',
      value,
      min,
      max,
      step,
    });
    input.addEventListener('input', () => {
      const parsed = Number(input.value);
      if (Number.isFinite(parsed)) {
        change(parsed);
      }
    });
    const control = suffix
      ? (() => {
        const wrapper = element('div', 'quizgeist-input-suffix');
        wrapper.append(input, element('span', '', {text: suffix}));
        return wrapper;
      })()
      : input;
    return labelledField(labelText, control);
  }

  private rangeField(
    labelText: string,
    value: number,
    min: number,
    max: number,
    step: number,
    change: (value: number) => void,
    suffix = '',
    format?: (value: number) => string,
    disabled = false,
  ): HTMLElement {
    const field = element('div', 'quizgeist-field quizgeist-range-field');
    const id = `quizgeist-range-${crypto.randomUUID()}`;
    const label = element('label', 'quizgeist-field__label', {
      text: labelText,
      for: id,
    });
    const control = element('div', 'quizgeist-range-control');
    const input = element('input', 'quizgeist-range', {
      id,
      type: 'range',
      value,
      min,
      max,
      step,
      disabled,
    });
    const displayValue = (current: number): string => (
      format ? format(current) : `${current}${suffix ? ` ${suffix}` : ''}`
    );
    const output = element('output', 'quizgeist-range-output', {
      for: id,
      text: displayValue(value),
    });
    input.setAttribute('aria-valuetext', displayValue(value));
    input.addEventListener('input', () => {
      const parsed = Number(input.value);
      if (!Number.isFinite(parsed)) {
        return;
      }
      output.value = displayValue(parsed);
      output.textContent = displayValue(parsed);
      input.setAttribute('aria-valuetext', displayValue(parsed));
      change(parsed);
    });
    control.append(input, output);
    field.append(label, control);
    return field;
  }

  private nextId(items: Array<{id: string}>): string {
    const used = new Set(items.map((item) => item.id));
    let id: string;
    do {
      this.itemIdSequence += 1;
      const randomValues = globalThis.crypto?.getRandomValues
        ? globalThis.crypto.getRandomValues(new Uint32Array(2))
        : null;
      const entropy = randomValues
        ? Array.from(randomValues, (value) => value.toString(36)).join('')
        : Math.random().toString(36).slice(2);
      id = `i${Date.now().toString(36)}_${this.itemIdSequence.toString(36)}_${entropy}`
        .slice(0, 32)
        .toLowerCase();
    } while (used.has(id) || this.issuedItemIds.has(id));
    this.issuedItemIds.add(id);
    return id;
  }

  private moveArrayItem<T>(
    question: EditorQuestion,
    items: T[],
    from: number,
    to: number,
  ): void {
    if (from < 0 || from >= items.length || to < 0 || to >= items.length || from === to) {
      return;
    }
    const [moved] = items.splice(from, 1);
    items.splice(to, 0, moved);
    this.callbacks.onChange();
    this.refreshCurrentForm(question);
  }

  private refreshCurrentForm(question: EditorQuestion): void {
    const current = document.querySelector<HTMLElement>(
      `.quizgeist-question-form[data-question-id="${question.id}"]`,
    );
    current?.replaceWith(this.render(question));
  }
}
