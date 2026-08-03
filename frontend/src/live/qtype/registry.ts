import {
  choiceShape,
  choiceShapeStringKey,
  choiceSlot,
} from '../choices';
import {liveButton, liveElement} from '../dom';
import {createLiveMedia} from '../media';
import type {
  LiveAggregate,
  LiveAnswer,
  LiveBrainstormGroup,
  LiveBrainstormIdea,
  LiveChoice,
  LiveDistributionEntry,
  LiveQuestion,
  LiveReactionOption,
} from '../types';

export type LiveAudience = 'host' | 'player';
export type LiveStringResolver = (
  key: string,
  fallback: string,
  values?: Record<string, string | number>,
) => string;

export interface LiveResponseRenderContext {
  aggregate?: LiveAggregate | LiveDistributionEntry[] | null;
  answer: LiveAnswer | null;
  audience: LiveAudience;
  disabled?: boolean;
  interactive: boolean;
  nowMs: number;
  phaseStartedAtMs?: number;
  onAggregateAction?: (data: Record<string, unknown>) => void;
  onChange?: (answer: LiveAnswer | null) => void;
  onSubmit?: (answer?: LiveAnswer) => void;
  onTap?: () => void;
  text: LiveStringResolver;
}

export interface QuestionSolutionRenderContext {
  answer?: LiveAnswer | null;
  correct?: boolean | null;
  text: LiveStringResolver;
}

type ResponseRenderer = (
  question: LiveQuestion,
  context: LiveResponseRenderContext,
) => HTMLElement;

function data(question: LiveQuestion): Record<string, unknown> {
  return question.typeData && typeof question.typeData === 'object'
    ? question.typeData
    : {};
}

function value<T>(
  question: LiveQuestion,
  key: string,
  fallback: T,
): T {
  const source = data(question);
  const direct = (question as unknown as Record<string, unknown>)[key];
  const candidate = source[key] ?? direct;
  return candidate === undefined || candidate === null ? fallback : candidate as T;
}

function responseType(question: LiveQuestion): string {
  if (typeof question.responseType === 'string' && question.responseType !== '') {
    return question.responseType;
  }
  return {
    quiz: 'choices',
    truefalse: 'choices',
    poll: 'choices',
    shortanswer: 'text',
    puzzle: 'order',
    wordcloud: 'text',
    scale: 'scale',
    slider: 'slider',
    pin: 'pin',
    reveal: 'reveal',
    brainstorm: 'brainstorm',
    open: 'text',
    slide: 'slide',
  }[question.qtype] || 'text';
}

function responseRoot(
  question: LiveQuestion,
  kind: string,
  context: LiveResponseRenderContext,
): HTMLElement {
  return liveElement(
    'section',
    `quizgeist-live-response quizgeist-live-response--${kind} quizgeist-live-response--${context.audience}`,
    {
      'data-live-answer-kind': kind,
      'data-live-qtype': question.qtype,
    },
  );
}

function submitButton(
  context: LiveResponseRenderContext,
  answer: () => LiveAnswer | null,
): HTMLButtonElement {
  const submit = liveButton(
    context.text('play:answer:send', 'Antwort senden'),
    context.audience === 'player'
      ? 'quizgeist-player-primary quizgeist-answer-send'
      : 'quizgeist-host-button quizgeist-host-button--primary',
    {
      'data-action': 'answer',
      'data-live-submit': true,
      disabled: context.disabled || answer() === null,
    },
  );
  submit.addEventListener('click', () => {
    const current = answer();
    if (current) {
      context.onTap?.();
      context.onSubmit?.(current);
    }
  });
  return submit;
}

function currentChoiceIds(answer: LiveAnswer | null): string[] {
  return answer && 'choiceIds' in answer && Array.isArray(answer.choiceIds)
    ? answer.choiceIds
    : [];
}

const renderChoices: ResponseRenderer = (question, context) => {
  const root = responseRoot(question, 'choices', context);
  const choices = Array.isArray(question.choices) ? question.choices : [];
  const multiple = Boolean(question.multiple);
  let selected = new Set(currentChoiceIds(context.answer));
  const grid = liveElement(
    'div',
    context.audience === 'host'
      ? `quizgeist-host-answer-grid quizgeist-host-answer-grid--${
        choices.length <= 2 ? 'two' : choices.length <= 4 ? 'four' : 'six'
      }`
      : 'quizgeist-player-choices',
    {
      role: context.interactive ? (multiple ? 'group' : 'radiogroup') : 'list',
      'data-live-choice-grid': true,
    },
  );

  const patch = (): void => {
    grid.querySelectorAll<HTMLElement>('[data-choice-id]').forEach((node) => {
      const checked = selected.has(node.dataset.choiceId || '');
      node.classList.toggle('is-selected', checked);
      if (multiple) {
        node.setAttribute('aria-pressed', checked ? 'true' : 'false');
      } else if (context.interactive) {
        node.setAttribute('aria-checked', checked ? 'true' : 'false');
        node.tabIndex = checked || (selected.size === 0
          && node === grid.querySelector('[data-choice-id]')) ? 0 : -1;
      }
    });
    const answer: LiveAnswer | null = selected.size > 0
      ? {kind: 'choices', choiceIds: Array.from(selected)}
      : null;
    context.onChange?.(answer);
    const send = root.querySelector<HTMLButtonElement>('[data-live-submit]');
    if (send) {
      send.disabled = Boolean(context.disabled || !answer);
    }
  };

  choices.forEach((choice, index) => {
    const slot = choiceSlot(question.qtype, index);
    const className = context.audience === 'host'
      ? `quizgeist-host-answer quizgeist-host-answer--${slot}`
      : `quizgeist-choice quizgeist-choice--${slot}`;
    const tile = context.interactive
      ? liveButton(choice.text, className, {
        'data-action': 'select-answer',
        'data-choice-id': choice.id,
        'data-choice-slot': slot,
        'data-live-choice-id': choice.id,
        disabled: context.disabled,
      })
      : liveElement('div', className, {
        'data-choice-id': choice.id,
        'data-choice-slot': slot,
        'data-live-choice-id': choice.id,
        role: 'listitem',
      });
    tile.setAttribute(
      'aria-label',
      `${context.text(choiceShapeStringKey(slot), slot.toUpperCase())}: ${
        choice.text || context.text('editor:field:answerimage', 'Antwortmedium')
      }`,
    );
    if (context.interactive) {
      if (multiple) {
        tile.setAttribute('aria-pressed', selected.has(choice.id) ? 'true' : 'false');
      } else {
        tile.setAttribute('role', 'radio');
        tile.setAttribute('aria-checked', selected.has(choice.id) ? 'true' : 'false');
      }
      tile.addEventListener('click', () => {
        context.onTap?.();
        if (multiple) {
          if (selected.has(choice.id)) {
            selected.delete(choice.id);
          } else {
            selected.add(choice.id);
          }
        } else {
          selected = new Set([choice.id]);
        }
        patch();
      });
    }
    tile.replaceChildren(choiceShape(slot));
    const media = createLiveMedia(choice, {
      allowPlayback: false,
      className: context.audience === 'host'
        ? 'quizgeist-host-answer__media'
        : 'quizgeist-choice__media',
      label: '',
    });
    if (media) {
      tile.append(media);
    }
    tile.append(liveElement('span', context.audience === 'host'
      ? 'quizgeist-host-answer__text'
      : 'quizgeist-choice__text', {text: choice.text}));
    grid.append(tile);
  });

  if (context.interactive) {
    grid.addEventListener('keydown', (event) => {
      if (/^[1-6]$/.test(event.key)) {
        const choice = choices[Number(event.key) - 1];
        if (choice) {
          event.preventDefault();
          const control = grid.querySelector<HTMLButtonElement>(
            `[data-choice-id="${CSS.escape(choice.id)}"]`,
          );
          control?.click();
          control?.focus();
        }
      }
    });
  }
  root.append(grid);
  if (context.interactive) {
    root.append(submitButton(context, () => selected.size > 0
      ? {kind: 'choices', choiceIds: Array.from(selected)}
      : null));
  }
  patch();
  return root;
};

function textAnswer(answer: LiveAnswer | null): string {
  return answer?.kind === 'text' || answer?.kind === 'brainstormIdea'
    ? answer.text
    : '';
}

function renderText(
  question: LiveQuestion,
  context: LiveResponseRenderContext,
  kind = 'text',
): HTMLElement {
  const root = responseRoot(question, kind, context);
  const maxChars = Math.max(1, Number(value(question, 'maxChars', question.qtype === 'open' ? 2000 : 160)));
  const multiline = question.qtype === 'open'
    || question.qtype === 'wordcloud'
    || question.qtype === 'brainstorm';
  if (!context.interactive) {
    root.append(liveElement('p', 'quizgeist-live-response__prompt', {
      text: question.qtype === 'open'
        ? context.text('live:response:open', 'Freie Antwort auf dem eigenen Gerät')
        : context.text('live:response:text', 'Antwort auf dem eigenen Gerät eingeben'),
    }));
    return root;
  }
  let current = textAnswer(context.answer);
  const label = liveElement('label', 'quizgeist-live-response__label', {
    text: context.text('play:answer:text', 'Deine Antwort'),
  });
  const input = multiline
    ? liveElement('textarea', 'quizgeist-live-text-answer', {
      'data-live-text-answer': true,
      maxlength: maxChars,
      rows: question.qtype === 'open' ? 5 : 3,
      value: current,
    })
    : liveElement('input', 'quizgeist-live-text-answer', {
      'data-live-text-answer': true,
      maxlength: maxChars,
      type: 'text',
      value: current,
    });
  const counter = liveElement('span', 'quizgeist-live-character-count', {
    'aria-live': 'polite',
    'data-live-character-count': true,
    text: `${current.length} / ${maxChars}`,
  });
  label.append(input);
  const currentAnswer = (): LiveAnswer | null => current.trim() === ''
    ? null
    : {kind: 'text', text: current.trim()};
  const submit = submitButton(context, currentAnswer);
  input.addEventListener('input', () => {
    current = input.value.slice(0, maxChars);
    counter.textContent = `${current.length} / ${maxChars}`;
    const answer = currentAnswer();
    context.onChange?.(answer);
    submit.disabled = Boolean(context.disabled || !answer);
  });
  root.append(label, counter, submit);
  return root;
}

const renderPuzzle: ResponseRenderer = (question, context) => {
  const root = responseRoot(question, 'order', context);
  const items = value<LiveChoice[]>(question, 'items', []);
  let order = context.answer?.kind === 'order'
    ? context.answer.orderIds.filter((id) => items.some((item) => item.id === id))
    : items.map((item) => item.id);
  items.forEach((item) => {
    if (!order.includes(item.id)) {
      order.push(item.id);
    }
  });
  const list = liveElement('ol', 'quizgeist-live-puzzle', {
    'data-live-puzzle': true,
  });
  let draggedId = '';
  const answer = (): LiveAnswer => ({kind: 'order', orderIds: [...order]});
  const render = (): void => {
    list.replaceChildren();
    order.forEach((id, index) => {
      const item = items.find((candidate) => candidate.id === id);
      if (!item) {
        return;
      }
      const row = liveElement('li', 'quizgeist-live-puzzle__item', {
        'data-live-puzzle-item': item.id,
        draggable: context.interactive && !context.disabled,
      });
      const content = liveElement('span', 'quizgeist-live-puzzle__text', {
        text: item.text,
      });
      row.append(
        liveElement('span', 'quizgeist-live-puzzle__handle', {
          'aria-hidden': 'true',
          text: '⠿',
        }),
        liveElement('span', 'quizgeist-live-puzzle__index', {text: String(index + 1)}),
        content,
      );
      const media = createLiveMedia(item, {
        allowPlayback: false,
        className: 'quizgeist-live-puzzle__media',
        label: '',
      });
      if (media) {
        content.append(media);
      }
      if (context.interactive) {
        const up = liveButton('↑', 'quizgeist-live-order-button', {
          'aria-label': context.text('live:puzzle:up', 'Nach oben'),
          'data-live-order-up': item.id,
          disabled: context.disabled || index === 0,
        });
        const down = liveButton('↓', 'quizgeist-live-order-button', {
          'aria-label': context.text('live:puzzle:down', 'Nach unten'),
          'data-live-order-down': item.id,
          disabled: context.disabled || index >= order.length - 1,
        });
        const move = (offset: number): void => {
          const next = index + offset;
          if (next < 0 || next >= order.length) {
            return;
          }
          [order[index], order[next]] = [order[next], order[index]];
          context.onTap?.();
          context.onChange?.(answer());
          render();
        };
        up.addEventListener('click', () => move(-1));
        down.addEventListener('click', () => move(1));
        row.addEventListener('dragstart', () => {
          draggedId = item.id;
        });
        row.addEventListener('dragover', (event) => event.preventDefault());
        row.addEventListener('drop', (event) => {
          event.preventDefault();
          const from = order.indexOf(draggedId);
          const to = order.indexOf(item.id);
          if (from >= 0 && to >= 0 && from !== to) {
            order.splice(to, 0, order.splice(from, 1)[0]);
            context.onChange?.(answer());
            render();
          }
        });
        const controls = liveElement('span', 'quizgeist-live-puzzle__controls');
        controls.append(up, down);
        row.append(controls);
      }
      list.append(row);
    });
  };
  render();
  root.append(list);
  if (context.interactive) {
    context.onChange?.(answer());
    root.append(submitButton(context, answer));
  }
  return root;
};

const renderScale: ResponseRenderer = (question, context) => {
  const root = responseRoot(question, 'number', context);
  const steps = Math.max(3, Math.min(10, Number(value(question, 'steps', 5))));
  const minLabel = String(value(question, 'minLabel', '1'));
  const maxLabel = String(value(question, 'maxLabel', String(steps)));
  let selected = context.answer?.kind === 'number' ? context.answer.value : 0;
  const labels = liveElement('div', 'quizgeist-live-scale__labels', {
    'aria-hidden': 'true',
    'data-live-scale-labels': true,
  });
  labels.style.display = 'flex';
  labels.style.gap = 'var(--mq-space-3)';
  labels.style.justifyContent = 'space-between';
  labels.style.width = '100%';
  labels.append(
    liveElement('span', '', {text: minLabel}),
    liveElement('span', '', {text: maxLabel}),
  );
  const controls = liveElement('div', 'quizgeist-live-scale', {
    role: context.interactive ? 'radiogroup' : 'list',
  });
  const patch = (): void => {
    controls.querySelectorAll<HTMLButtonElement>('[data-live-scale-value]').forEach((button) => {
      const checked = Number(button.dataset.liveScaleValue) === selected;
      button.classList.toggle('is-selected', checked);
      button.setAttribute('aria-checked', checked ? 'true' : 'false');
      button.tabIndex = checked || (selected === 0
        && button.dataset.liveScaleValue === '1') ? 0 : -1;
    });
    context.onChange?.(selected > 0 ? {kind: 'number', value: selected} : null);
    const submit = root.querySelector<HTMLButtonElement>('[data-live-submit]');
    if (submit) {
      submit.disabled = Boolean(context.disabled || selected <= 0);
    }
  };
  for (let number = 1; number <= steps; number += 1) {
    const chip = context.interactive
      ? liveButton(String(number), 'quizgeist-live-scale__value', {
        'aria-label': number === 1
          ? `${number}: ${minLabel}`
          : number === steps
            ? `${number}: ${maxLabel}`
            : String(number),
        'data-live-scale-value': number,
        disabled: context.disabled,
        role: 'radio',
      })
      : liveElement('span', 'quizgeist-live-scale__value', {
        'data-live-scale-value': number,
        role: 'listitem',
        text: String(number),
      });
    if (context.interactive) {
      chip.addEventListener('click', () => {
        selected = number;
        context.onTap?.();
        patch();
      });
    }
    controls.append(chip);
  }
  if (context.interactive) {
    controls.addEventListener('keydown', (event) => {
      if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) {
        return;
      }
      const focused = event.target instanceof HTMLElement
        ? Number(event.target.dataset.liveScaleValue || 0)
        : 0;
      const origin = selected > 0 ? selected : Math.max(1, focused);
      let next = origin;
      if (event.key === 'Home') {
        next = 1;
      } else if (event.key === 'End') {
        next = steps;
      } else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
        next = Math.max(1, origin - 1);
      } else {
        next = Math.min(steps, origin + 1);
      }
      event.preventDefault();
      selected = next;
      context.onTap?.();
      patch();
      controls.querySelector<HTMLButtonElement>(
        `[data-live-scale-value="${next}"]`,
      )?.focus();
    });
  }
  root.append(labels, controls);
  if (context.interactive) {
    root.append(submitButton(context, () => selected > 0
      ? {kind: 'number', value: selected}
      : null));
    patch();
  }
  return root;
};

const renderSlider: ResponseRenderer = (question, context) => {
  const root = responseRoot(question, 'number', context);
  const min = Number(value(question, 'min', 0));
  const max = Number(value(question, 'max', 100));
  const step = Math.max(.000001, Number(value(question, 'step', 1)));
  const maximumStep = Math.max(0, Math.floor(((max - min) / step) + 1.0e-7));
  const snap = (next: number): number => {
    const stepIndex = Math.max(
      0,
      Math.min(maximumStep, Math.round((next - min) / step)),
    );
    return Number((min + stepIndex * step).toFixed(6));
  };
  let current = context.answer?.kind === 'number'
    ? snap(context.answer.value)
    : snap((min + max) / 2);
  const output = liveElement('output', 'quizgeist-live-slider__value', {
    'data-live-slider-value': true,
    text: String(current),
  });
  const slider = liveElement('input', 'quizgeist-live-slider', {
    'data-live-slider': true,
    disabled: context.disabled || !context.interactive,
    max,
    min,
    step,
    type: 'range',
    value: current,
  });
  const set = (next: number): void => {
    current = snap(next);
    slider.value = String(current);
    output.value = String(current);
    output.textContent = String(current);
    context.onChange?.({kind: 'number', value: current});
  };
  slider.addEventListener('input', () => set(Number(slider.value)));
  const minus = liveButton('−', 'quizgeist-live-slider__adjust', {
    'aria-label': context.text('live:slider:minus', 'Wert verkleinern'),
    'data-live-number-minus': true,
    disabled: context.disabled || !context.interactive,
  });
  const plus = liveButton('+', 'quizgeist-live-slider__adjust', {
    'aria-label': context.text('live:slider:plus', 'Wert vergrößern'),
    'data-live-number-plus': true,
    disabled: context.disabled || !context.interactive,
  });
  minus.addEventListener('click', () => set(current - step));
  plus.addEventListener('click', () => set(current + step));
  const row = liveElement('div', 'quizgeist-live-slider__controls');
  row.append(minus, slider, plus);
  root.append(output, row);
  if (context.interactive) {
    set(current);
    root.append(submitButton(context, () => ({kind: 'number', value: current})));
  }
  return root;
};

function pinMedia(question: LiveQuestion): {
  mediaMimeType?: LiveQuestion['mediaMimeType'];
  mediaUrl?: LiveQuestion['mediaUrl'];
} {
  const typeData = data(question);
  return {
    mediaMimeType: (typeData.mediaMimeType || question.mediaMimeType) as LiveQuestion['mediaMimeType'],
    mediaUrl: (typeData.mediaUrl || question.mediaUrl) as LiveQuestion['mediaUrl'],
  };
}

interface PinImageGeometry {
  canvasHeight: number;
  canvasWidth: number;
  height: number;
  left: number;
  top: number;
  width: number;
}

function pinImageGeometry(
  canvas: HTMLElement,
  image: HTMLImageElement | null,
): PinImageGeometry | null {
  const canvasBounds = canvas.getBoundingClientRect();
  if (canvasBounds.width <= 0 || canvasBounds.height <= 0) {
    return null;
  }
  const imageBounds = image?.getBoundingClientRect();
  const box = imageBounds && imageBounds.width > 0 && imageBounds.height > 0
    ? imageBounds
    : canvasBounds;
  let width = box.width;
  let height = box.height;
  let left = box.left - canvasBounds.left;
  let top = box.top - canvasBounds.top;
  if (image && image.naturalWidth > 0 && image.naturalHeight > 0) {
    const scale = Math.min(
      box.width / image.naturalWidth,
      box.height / image.naturalHeight,
    );
    width = image.naturalWidth * scale;
    height = image.naturalHeight * scale;
    left += (box.width - width) / 2;
    top += (box.height - height) / 2;
  }
  return {
    canvasHeight: canvasBounds.height,
    canvasWidth: canvasBounds.width,
    height,
    left,
    top,
    width,
  };
}

function positionPinNode(
  canvas: HTMLElement,
  image: HTMLImageElement | null,
  node: HTMLElement,
  point: {x: number; y: number},
): void {
  const geometry = pinImageGeometry(canvas, image);
  if (!geometry) {
    return;
  }
  node.style.left = `${
    (geometry.left + geometry.width * point.x / 100) * 100 / geometry.canvasWidth
  }%`;
  node.style.top = `${
    (geometry.top + geometry.height * point.y / 100) * 100 / geometry.canvasHeight
  }%`;
}

const renderPin: ResponseRenderer = (question, context) => {
  const root = responseRoot(question, 'pin', context);
  let point = context.answer?.kind === 'pin'
    ? {x: context.answer.x, y: context.answer.y}
    : null;
  const canvas = liveElement('div', 'quizgeist-live-pin', {
    'aria-label': context.text('live:pin:place', 'Pin auf dem Bild platzieren'),
    'aria-disabled': context.interactive && context.disabled ? 'true' : undefined,
    'data-live-pin-canvas': true,
    role: context.interactive ? 'button' : 'img',
    tabindex: context.interactive && !context.disabled ? 0 : undefined,
  });
  const image = createLiveMedia(pinMedia(question), {
    allowPlayback: false,
    className: 'quizgeist-live-pin__image',
    label: question.questionText,
  });
  const pinImage = image instanceof HTMLImageElement ? image : null;
  if (image) {
    canvas.append(image);
  }
  const marker = liveElement('span', 'quizgeist-live-pin__marker', {
    'aria-hidden': 'true',
    'data-live-pin-marker': true,
    hidden: !point,
  });
  canvas.append(marker);
  const patch = (): void => {
    marker.hidden = !point;
    if (point) {
      positionPinNode(canvas, pinImage, marker, point);
      canvas.setAttribute(
        'aria-label',
        `${context.text('live:pin:place', 'Pin auf dem Bild platzieren')}: ${
          Math.round(point.x)
        } %, ${Math.round(point.y)} %`,
      );
      context.onChange?.({kind: 'pin', x: point.x, y: point.y});
    }
    const submit = root.querySelector<HTMLButtonElement>('[data-live-submit]');
    if (submit) {
      submit.disabled = Boolean(context.disabled || !point);
    }
  };
  if (context.interactive) {
    canvas.addEventListener('pointerdown', (event) => {
      if (context.disabled) {
        return;
      }
      const bounds = canvas.getBoundingClientRect();
      const geometry = pinImageGeometry(canvas, pinImage);
      if (!geometry) {
        return;
      }
      const x = event.clientX - bounds.left - geometry.left;
      const y = event.clientY - bounds.top - geometry.top;
      if (x < 0 || x > geometry.width || y < 0 || y > geometry.height) {
        return;
      }
      point = {
        x: Math.max(0, Math.min(100, x * 100 / geometry.width)),
        y: Math.max(0, Math.min(100, y * 100 / geometry.height)),
      };
      context.onTap?.();
      patch();
    });
    canvas.addEventListener('keydown', (event) => {
      if (context.disabled) {
        return;
      }
      const distance = event.shiftKey ? 5 : 1;
      const next = point ? {...point} : {x: 50, y: 50};
      if (event.key === 'ArrowLeft') {
        next.x -= distance;
      } else if (event.key === 'ArrowRight') {
        next.x += distance;
      } else if (event.key === 'ArrowUp') {
        next.y -= distance;
      } else if (event.key === 'ArrowDown') {
        next.y += distance;
      } else if ((event.key === ' ' || event.key === 'Enter') && !point) {
        // Keyboard activation places the first pin in the image centre.
      } else {
        return;
      }
      event.preventDefault();
      point = {
        x: Math.max(0, Math.min(100, next.x)),
        y: Math.max(0, Math.min(100, next.y)),
      };
      context.onTap?.();
      patch();
    });
  }
  pinImage?.addEventListener('load', patch, {once: true});
  patch();
  root.append(canvas);
  if (context.interactive) {
    root.append(submitButton(context, () => point
      ? {kind: 'pin', x: point.x, y: point.y}
      : null));
  }
  return root;
};

function renderRevealImage(
  question: LiveQuestion,
  context: LiveResponseRenderContext,
): HTMLElement {
  const frame = liveElement('div', 'quizgeist-live-image-reveal', {
    'data-live-reveal-grid': true,
  });
  const media = createLiveMedia(pinMedia(question), {
    allowPlayback: false,
    className: 'quizgeist-live-image-reveal__image',
    label: question.questionText,
  });
  if (media) {
    frame.append(media);
  }
  const gridSize = Math.max(3, Math.min(6, Number(value(question, 'grid', 3))));
  const count = gridSize * gridSize;
  const order = value<number[]>(question, 'tileOrder', Array.from({length: count}, (_, i) => i));
  const seconds = Math.max(1, Number(value(question, 'revealSeconds', 12)));
  const stepMs = Math.max(80, Number(value(question, 'stepMs', (seconds * 1000) / count)));
  const phaseStartedAtMs = Number(context.phaseStartedAtMs);
  const elapsedMs = Number.isFinite(phaseStartedAtMs) && phaseStartedAtMs > 0
    ? Math.max(0, context.nowMs - phaseStartedAtMs)
    : 0;
  const bonus = liveElement('span', 'quizgeist-live-image-reveal__bonus', {
    'aria-live': 'off',
    'data-live-reveal-bonus': true,
    'data-live-reveal-label': context.text('live:reveal:bonus', 'Frühbonus'),
    'data-live-reveal-start-ms': phaseStartedAtMs,
    'data-live-reveal-step-ms': stepMs,
    'data-live-reveal-total': count,
  });
  const overlay = liveElement('div', 'quizgeist-live-image-reveal__tiles', {
    'aria-hidden': 'true',
  });
  overlay.style.gridTemplateColumns = `repeat(${gridSize}, 1fr)`;
  for (let index = 0; index < count; index += 1) {
    const orderIndex = Math.max(0, order.indexOf(index));
    const tile = liveElement('span', 'quizgeist-live-image-reveal__tile');
    tile.style.animationDelay = `${orderIndex * stepMs - elapsedMs}ms`;
    overlay.append(tile);
  }
  frame.append(overlay, bonus);
  patchLiveResponseClock(frame, context.nowMs);
  return frame;
}

/**
 * Update time-derived response details without re-rendering an answer form.
 *
 * Both live surfaces call this helper from their existing server-offset clock.
 */
export function patchLiveResponseClock(root: ParentNode, nowMs: number): void {
  root.querySelectorAll<HTMLElement>('[data-live-reveal-bonus]').forEach((node) => {
    const start = Number(node.dataset.liveRevealStartMs);
    const step = Number(node.dataset.liveRevealStepMs);
    const total = Math.max(1, Number(node.dataset.liveRevealTotal));
    if (!Number.isFinite(start) || !Number.isFinite(step) || step <= 0) {
      return;
    }
    const revealed = Math.max(0, Math.floor(Math.max(0, nowMs - start) / step));
    const remaining = Math.max(0, total - revealed);
    node.textContent = `${node.dataset.liveRevealLabel || 'Frühbonus'}: ${remaining} / ${total}`;
  });
}

const renderImageReveal: ResponseRenderer = (question, context) => {
  const root = responseRoot(question, 'reveal', context);
  root.append(renderRevealImage(question, context));
  if (context.interactive) {
    const form = renderText(question, context, 'text');
    form.querySelector('[data-live-qtype]')?.removeAttribute('data-live-qtype');
    root.append(...Array.from(form.childNodes));
  }
  return root;
};

function brainstormStage(question: LiveQuestion): string {
  return String(
    question.interactionStage
      || value(question, 'interactionStage', value(question, 'stage', 'collect')),
  );
}

function brainstormGroups(
  question: LiveQuestion,
  aggregate?: LiveAggregate | LiveDistributionEntry[] | null,
): LiveBrainstormGroup[] {
  const aggregateGroups = objectRows(aggregateRecord(aggregate).groups).map((group) => ({
    ideas: objectRows(group.ideas).map((idea) => ({
      groupKey: String(group.key || ''),
      id: String(idea.id || idea.key || ''),
      own: idea.own === true,
      text: String(idea.text || ''),
      votes: idea.votes === null || idea.votes === undefined
        ? undefined
        : Number(idea.votes),
    })),
    key: String(group.key || ''),
    label: String(group.label || ''),
    votes: group.votes === null || group.votes === undefined
      ? undefined
      : Number(group.votes),
  }));
  if (aggregateGroups.length > 0) {
    return aggregateGroups;
  }
  const groups = value<LiveBrainstormGroup[]>(question, 'groups', []);
  if (Array.isArray(groups) && groups.length > 0) {
    return groups;
  }
  const ideas = value<LiveBrainstormIdea[]>(question, 'ideas', []);
  return ideas.length > 0 ? [{
    key: 'all',
    label: '',
    ideas,
    votes: ideas.reduce((sum, idea) => sum + Number(idea.votes || 0), 0),
  }] : [];
}

const renderBrainstorm: ResponseRenderer = (question, context) => {
  const root = responseRoot(question, 'brainstorm', context);
  const stage = brainstormStage(question);
  root.dataset.liveBrainstormStage = stage;
  root.append(liveElement('p', 'quizgeist-live-brainstorm__stage', {
    'data-live-brainstorm-stage': stage,
    text: context.text(`live:brainstorm:${stage}`, {
      collect: 'Ideen sammeln',
      group: 'Ideen gruppieren',
      vote: 'Ideen abstimmen',
      done: 'Ergebnis',
    }[stage] || stage),
  }));
  if (stage === 'collect' && context.interactive) {
    let current = '';
    const input = liveElement('textarea', 'quizgeist-live-text-answer', {
      'data-live-brainstorm-idea': true,
      'data-live-text-answer': true,
      maxlength: Math.max(
        1,
        Number(value(question, 'maxChars', value(question, 'maxIdeaChars', 280))),
      ),
      rows: 3,
    });
    const submit = liveButton(
      context.text('live:brainstorm:submit', 'Idee einreichen'),
      'quizgeist-player-primary',
      {
        'data-live-submit': true,
        disabled: true,
      },
    );
    input.addEventListener('input', () => {
      current = input.value.trim();
      submit.disabled = Boolean(context.disabled || current === '');
      context.onChange?.(current === ''
        ? null
        : {kind: 'brainstormIdea', text: current});
    });
    submit.addEventListener('click', () => {
      if (current !== '') {
        const answer: LiveAnswer = {kind: 'brainstormIdea', text: current};
        context.onSubmit?.(answer);
      }
    });
    root.append(input, submit);
    return root;
  }
  const groups = brainstormGroups(question, context.aggregate);
  const list = liveElement('div', 'quizgeist-live-brainstorm__groups');
  let selectedGroup = context.answer?.kind === 'brainstormVote'
    ? context.answer.groupKey
    : String(value(question, 'ownGroupKey', ''));
  groups.forEach((group) => {
    const section = stage === 'vote' && context.interactive
      ? liveButton('', 'quizgeist-live-brainstorm__group', {
        'aria-pressed': selectedGroup === group.key ? 'true' : 'false',
        'data-live-brainstorm-vote': group.key,
        disabled: context.disabled,
      })
      : liveElement('section', 'quizgeist-live-brainstorm__group');
    section.dataset.liveBrainstormGroup = group.key;
    if (group.label) {
      section.append(liveElement('h3', '', {text: group.label}));
    }
    group.ideas.forEach((idea) => {
      const ideaNode = liveElement('article', 'quizgeist-live-brainstorm__idea', {
          'data-live-brainstorm-idea': idea.id,
          text: idea.text,
        });
      if (typeof idea.votes === 'number') {
        ideaNode.append(liveElement('span', 'quizgeist-live-brainstorm__votes', {
          text: `♥ ${idea.votes}`,
        }));
      }
      section.append(ideaNode);
    });
    if (stage === 'vote' && context.interactive) {
      section.addEventListener('click', () => {
        selectedGroup = group.key;
        context.onTap?.();
        list.querySelectorAll<HTMLElement>('[data-live-brainstorm-vote]').forEach((node) => {
          const checked = node.dataset.liveBrainstormVote === selectedGroup;
          node.classList.toggle('is-selected', checked);
          node.setAttribute('aria-pressed', checked ? 'true' : 'false');
        });
        context.onChange?.({
          groupKey: selectedGroup,
          kind: 'brainstormVote',
        });
        const submit = root.querySelector<HTMLButtonElement>('[data-live-submit]');
        if (submit) {
          submit.disabled = Boolean(context.disabled || selectedGroup === '');
        }
      });
    }
    list.append(section);
  });
  root.append(list);
  if (stage === 'vote' && context.interactive) {
    root.append(submitButton(context, () => selectedGroup !== ''
      ? {groupKey: selectedGroup, kind: 'brainstormVote'}
      : null));
  }
  return root;
};

/**
 * Readable last resort for the server's stable reaction identifiers, so a
 * missing client string never surfaces the identifier itself.
 */
const REACTION_LABEL_FALLBACKS: Record<string, string> = {
  clap: 'Applaus',
  heart: 'Herz',
  idea: 'Idee',
  laugh: 'Lachen',
  wow: 'Wow',
};

function reactionOptions(
  question: LiveQuestion,
  context: LiveResponseRenderContext,
): LiveReactionOption[] {
  const source = value<unknown>(question, 'reactionOptions', value(question, 'reactions', []));
  if (Array.isArray(source)) {
    return source.flatMap((entry, index): LiveReactionOption[] => {
      if (typeof entry === 'string') {
        return [{
          id: entry,
          emoji: entry,
          label: context.text(
            `live:reaction:${entry}`,
            REACTION_LABEL_FALLBACKS[entry] || 'Reaktion',
          ),
        }];
      }
      if (entry && typeof entry === 'object') {
        const record = entry as Record<string, unknown>;
        const id = String(record.id || record.reaction || index);
        return [{
          count: Number(record.count || 0),
          emoji: String(record.emoji || record.reaction || '✨'),
          id,
          label: String(
            record.label
            || context.text(
              `live:reaction:${id}`,
              REACTION_LABEL_FALLBACKS[id] || 'Reaktion',
            ),
          ),
        }];
      }
      return [];
    });
  }
  return [
    {emoji: '❤️', id: 'heart', label: context.text('live:reaction:heart', 'Herz')},
    {emoji: '👏', id: 'clap', label: context.text('live:reaction:clap', 'Applaus')},
    {emoji: '💡', id: 'idea', label: context.text('live:reaction:idea', 'Idee')},
    {emoji: '😄', id: 'laugh', label: context.text('live:reaction:laugh', 'Lachen')},
    {emoji: '😮', id: 'wow', label: context.text('live:reaction:wow', 'Wow')},
  ];
}

const renderSlide: ResponseRenderer = (question, context) => {
  const root = responseRoot(question, 'slide', context);
  const layout = String(value(question, 'layout', 'title'));
  const card = liveElement('article', `quizgeist-live-slide quizgeist-live-slide--${layout}`, {
    'data-live-slide': layout,
  });
  const title = String(value(question, 'title', question.questionText));
  const body = String(value(question, 'body', ''));
  if (title) {
    card.append(liveElement('h2', 'quizgeist-live-slide__title', {text: title}));
  }
  if (layout === 'quote') {
    card.append(
      liveElement('blockquote', 'quizgeist-live-slide__quote', {
        text: String(value(question, 'quote', body)),
      }),
      liveElement('cite', 'quizgeist-live-slide__attribution', {
        text: String(value(question, 'attribution', '')),
      }),
    );
  } else if (layout === 'bullets') {
    const list = liveElement('ul', 'quizgeist-live-slide__bullets');
    value<string[]>(question, 'bullets', []).forEach((bullet) => {
      list.append(liveElement('li', '', {text: bullet}));
    });
    card.append(list);
  } else if (body) {
    card.append(liveElement('p', 'quizgeist-live-slide__body', {text: body}));
  }
  const media = createLiveMedia(pinMedia(question), {
    className: 'quizgeist-live-slide__media',
    label: title,
  });
  if (media) {
    card.append(media);
  }
  root.append(card);
  if (value(question, 'reactions', false) !== false) {
    const reactions = liveElement('div', 'quizgeist-live-reactions', {
      'aria-label': context.text('live:reactions:title', 'Live-Reaktionen'),
      'data-live-reactions': true,
      role: context.interactive ? 'radiogroup' : 'list',
    });
    const ownReaction = String(value(question, 'ownReaction', ''));
    reactionOptions(question, context).forEach((reaction) => {
      const node = context.interactive
        ? liveButton(reaction.emoji, 'quizgeist-live-reaction', {
          'aria-label': reaction.label,
          'aria-checked': ownReaction === reaction.id ? 'true' : 'false',
          'data-live-reaction': reaction.id,
          disabled: context.disabled,
          role: 'radio',
        })
        : liveElement('span', 'quizgeist-live-reaction', {
          'data-live-reaction': reaction.id,
          role: 'listitem',
          text: reaction.emoji,
        });
      if (context.interactive) {
        node.addEventListener('click', () => {
          context.onTap?.();
          const answer: LiveAnswer = {
            kind: 'reaction',
            reaction: reaction.id,
          };
          context.onChange?.(answer);
          context.onSubmit?.(answer);
          reactions.querySelectorAll('[data-live-reaction]').forEach((candidate) => {
            candidate.setAttribute(
              'aria-checked',
              candidate === node ? 'true' : 'false',
            );
          });
        });
      }
      if (!context.interactive && typeof reaction.count === 'number') {
        node.append(liveElement('strong', '', {text: String(reaction.count)}));
      }
      reactions.append(node);
    });
    root.append(reactions);
  }
  return root;
};

const RENDERERS: Record<string, ResponseRenderer> = {
  brainstorm: renderBrainstorm,
  choices: renderChoices,
  choice: renderChoices,
  order: renderPuzzle,
  puzzle: renderPuzzle,
  reaction: renderSlide,
  pin: renderPin,
  reveal: renderImageReveal,
  scale: renderScale,
  slide: renderSlide,
  slider: renderSlider,
  text: renderText,
};

/**
 * Single response renderer registry consumed by both live surfaces.
 */
export function renderLiveResponse(
  question: LiveQuestion,
  context: LiveResponseRenderContext,
): HTMLElement {
  const renderer = RENDERERS[responseType(question)]
    || RENDERERS[question.qtype]
    || renderText;
  return renderer(question, context);
}

export function questionAllowsRepeatedSubmissions(question: LiveQuestion): boolean {
  return Boolean(
    question.policyDescriptor?.allowsMultipleSubmissions
    ?? question.allowsMultipleSubmissions
    ?? false,
  );
}

export function questionSpeechText(question: LiveQuestion): string {
  if (typeof question.speechText === 'string' && question.speechText.trim() !== '') {
    return question.speechText;
  }
  const chunks = [question.questionText];
  if (question.qtype === 'slide') {
    chunks.push(
      String(value(question, 'title', '')),
      String(value(question, 'body', '')),
      ...value<string[]>(question, 'bullets', []),
      String(value(question, 'quote', '')),
      String(value(question, 'attribution', '')),
    );
  }
  return chunks.filter(Boolean).join('. ');
}

function answerLabels(question: LiveQuestion, ids: string[]): string[] {
  const candidates = [
    ...(Array.isArray(question.choices) ? question.choices : []),
    ...value<LiveChoice[]>(question, 'items', []),
  ];
  return ids.flatMap((id) => {
    const match = candidates.find((candidate) => candidate.id === id);
    return match ? [match.text] : [];
  });
}

function renderOwnAnswer(
  question: LiveQuestion,
  answer: LiveAnswer,
  context: QuestionSolutionRenderContext,
): HTMLElement {
  const section = liveElement('section', 'quizgeist-study-solution__own');
  section.append(liveElement('h4', '', {
    text: context.text('selfstudy:review:ownanswer', 'Deine Antwort'),
  }));
  let lines: string[] = [];
  if ('choiceIds' in answer) {
    lines = answerLabels(question, answer.choiceIds);
  } else if (answer.kind === 'order') {
    lines = answerLabels(question, answer.orderIds);
  } else if (answer.kind === 'text' || answer.kind === 'brainstormIdea') {
    lines = [answer.text];
  } else if (answer.kind === 'number') {
    lines = [String(answer.value)];
  } else if (answer.kind === 'pin') {
    lines = [`x: ${Math.round(answer.x * 10) / 10} %, y: ${
      Math.round(answer.y * 10) / 10
    } %`];
  } else if (answer.kind === 'reaction') {
    lines = [answer.reaction];
  } else if (answer.kind === 'brainstormVote') {
    lines = [answer.groupKey];
  }
  if (lines.length === 0) {
    section.append(liveElement('p', '', {
      text: context.text(
        'selfstudy:review:answersaved',
        'Deine Antwort wurde gespeichert.',
      ),
    }));
  } else if (lines.length === 1) {
    section.append(liveElement('p', '', {text: lines[0]}));
  } else {
    const list = liveElement('ol', 'quizgeist-study-solution__list');
    lines.forEach((line) => list.append(liveElement('li', '', {text: line})));
    section.append(list);
  }
  return section;
}

/**
 * Render solution information that was explicitly released by the server.
 *
 * This function only presents fields emitted by the PHP type strategies. It
 * deliberately contains no validation, correctness inference, or scoring.
 */
export function renderQuestionSolution(
  question: LiveQuestion,
  context: QuestionSolutionRenderContext,
): HTMLElement {
  const root = liveElement('section', 'quizgeist-study-solution', {
    'data-selfstudy-solution': true,
  });
  const heading = liveElement('h3', 'quizgeist-study-solution__title', {
    text: context.text('selfstudy:review:solution', 'Lösung und Rückmeldung'),
  });
  heading.tabIndex = -1;
  root.append(heading);

  if (typeof context.correct === 'boolean') {
    root.append(liveElement(
      'p',
      `quizgeist-study-result quizgeist-study-result--${
        context.correct ? 'correct' : 'incorrect'
      }`,
      {
        role: 'status',
        text: context.correct
          ? context.text('selfstudy:review:correct', 'Richtig beantwortet')
          : context.text(
            'selfstudy:review:incorrect',
            'Noch nicht richtig – nutze die Lösung zum Weiterlernen.',
          ),
      },
    ));
  }
  if (context.answer) {
    root.append(renderOwnAnswer(question, context.answer, context));
  }

  const solution = liveElement('section', 'quizgeist-study-solution__canonical');
  solution.append(liveElement('h4', '', {
    text: context.text('selfstudy:review:correctanswer', 'Richtige Antwort'),
  }));
  let hasSolution = false;

  const correctChoices = question.choices.filter((choice) => choice.correct === true);
  if (correctChoices.length > 0) {
    const list = liveElement('ul', 'quizgeist-study-solution__list');
    correctChoices.forEach((choice) => {
      const item = liveElement('li', '', {text: choice.text});
      const media = createLiveMedia(choice, {
        allowPlayback: false,
        className: 'quizgeist-study-solution__media',
        label: choice.text,
      });
      if (media) {
        item.append(media);
      }
      list.append(item);
    });
    solution.append(list);
    hasSolution = true;
  }

  const acceptedAnswers = value<string[]>(question, 'acceptedAnswers', [])
    .filter((entry) => typeof entry === 'string' && entry.trim() !== '');
  if (acceptedAnswers.length > 0) {
    const list = liveElement('ul', 'quizgeist-study-solution__list');
    acceptedAnswers.forEach((entry) => {
      list.append(liveElement('li', '', {text: entry}));
    });
    solution.append(list);
    hasSolution = true;
  }

  const correctOrder = value<string[]>(question, 'correctOrderIds', []);
  if (correctOrder.length > 0) {
    const labels = answerLabels(question, correctOrder);
    if (labels.length === correctOrder.length) {
      const list = liveElement('ol', 'quizgeist-study-solution__list');
      labels.forEach((entry) => list.append(liveElement('li', '', {text: entry})));
      solution.append(list);
      hasSolution = true;
    }
  }

  const target = value<unknown>(question, 'target', null);
  if (typeof target === 'number' && Number.isFinite(target)) {
    const tolerance = Number(value(question, 'tolerance', 0));
    solution.append(liveElement('p', '', {
      text: Number.isFinite(tolerance) && tolerance > 0
        ? context.text(
          'selfstudy:review:targettolerance',
          'Zielwert: {$target} (Toleranz ± {$tolerance})',
          {target, tolerance},
        )
        : context.text(
          'selfstudy:review:target',
          'Zielwert: {$target}',
          {target},
        ),
    }));
    hasSolution = true;
  } else if (target && typeof target === 'object' && !Array.isArray(target)) {
    const point = target as Record<string, unknown>;
    const x = Number(point.x);
    const y = Number(point.y);
    if (Number.isFinite(x) && Number.isFinite(y)) {
      const radius = Number(value(question, 'radius', 0));
      solution.append(liveElement('p', '', {
        text: context.text(
          'selfstudy:review:pin',
          'Zielbereich bei x: {$x} %, y: {$y} % (Radius {$radius} %)',
          {
            radius: Number.isFinite(radius) ? radius : 0,
            x: Math.round(x * 10) / 10,
            y: Math.round(y * 10) / 10,
          },
        ),
      }));
      hasSolution = true;
    }
  }

  const sampleAnswer = value<string>(question, 'sampleAnswer', '').trim();
  if (sampleAnswer !== '') {
    solution.append(liveElement('p', 'quizgeist-study-solution__sample', {
      text: sampleAnswer,
    }));
    hasSolution = true;
  }

  if (!hasSolution) {
    solution.append(liveElement('p', '', {
      text: context.text(
        'selfstudy:review:selfcheck',
        'Für diese Frage gibt es keine automatisch bewertete Musterlösung.',
      ),
    }));
  }
  root.append(solution);
  return root;
}

function aggregateRecord(
  aggregate: LiveAggregate | LiveDistributionEntry[] | null | undefined,
): Record<string, unknown> {
  return aggregate && !Array.isArray(aggregate) && typeof aggregate === 'object'
    ? aggregate
    : {};
}

function renderChoiceAggregate(
  question: LiveQuestion,
  entries: LiveDistributionEntry[],
  context: LiveResponseRenderContext,
): HTMLElement {
  const container = liveElement('div', 'quizgeist-host-distribution', {
    'data-live-aggregate-kind': 'choice',
    'data-live-distribution': true,
  });
  const byChoice = new Map(entries.map((entry) => [entry.choiceId, entry]));
  question.choices.forEach((choice: LiveChoice, index) => {
    const entry = byChoice.get(choice.id) || {
      choiceId: choice.id,
      count: 0,
      percent: 0,
    };
    const slot = choiceSlot(question.qtype, index);
    const percent = Math.max(0, Math.min(100, Number(entry.percent || 0)));
    const row = liveElement('div', 'quizgeist-host-distribution-row', {
      'data-live-choice-id': choice.id,
    });
    const label = liveElement('div', 'quizgeist-host-distribution-row__label');
    label.append(choiceShape(slot), liveElement('span', '', {text: choice.text}));
    const track = liveElement('div', 'quizgeist-host-distribution-row__track');
    const bar = liveElement(
      'div',
      `quizgeist-host-distribution-row__bar quizgeist-host-distribution-row__bar--${slot}`,
      {text: `${Math.round(percent)} %`},
    );
    bar.style.width = `${percent}%`;
    track.append(bar);
    row.append(
      label,
      track,
      liveElement('span', 'quizgeist-host-distribution-row__count', {
        text: String(Math.max(0, Number(entry.count || 0))),
      }),
    );
    if (question.policyDescriptor?.showsCorrectness !== false
        && (entry.correct || choice.correct)) {
      row.classList.add('is-correct');
      row.append(liveElement('span', 'quizgeist-host-distribution-row__correct', {
        text: context.text('host:reveal:correct', 'Richtige Antwort'),
      }));
    }
    container.append(row);
  });
  return container;
}

function objectRows(raw: unknown): Array<Record<string, unknown>> {
  return Array.isArray(raw)
    ? raw.filter((entry): entry is Record<string, unknown> =>
      Boolean(entry && typeof entry === 'object' && !Array.isArray(entry)))
    : [];
}

/**
 * Shared host aggregate renderer. Player projections may reuse it for a
 * read-only final state without receiving host-only data during questions.
 */
export function renderLiveAggregate(
  question: LiveQuestion,
  aggregate: LiveAggregate | LiveDistributionEntry[] | null | undefined,
  context: LiveResponseRenderContext,
): HTMLElement {
  if (Array.isArray(aggregate)) {
    return renderChoiceAggregate(question, aggregate, context);
  }
  const record = aggregateRecord(aggregate);
  const kind = String(record.kind || (
    ['quiz', 'truefalse', 'poll'].includes(question.qtype) ? 'choice' : question.qtype
  ));
  if (kind === 'choice') {
    const entries = objectRows(record.entries || record.distribution)
      .map((entry) => ({
        choiceId: String(entry.choiceId || entry.id || ''),
        correct: typeof entry.correct === 'boolean' ? entry.correct : undefined,
        count: Number(entry.count || 0),
        percent: Number(entry.percent || 0),
      }));
    return renderChoiceAggregate(question, entries, context);
  }
  const root = liveElement(
    'section',
    `quizgeist-live-aggregate quizgeist-live-aggregate--${kind}`,
    {'data-live-aggregate-kind': kind},
  );
  if (kind === 'wordcloud') {
    const cloud = liveElement('div', 'quizgeist-live-wordcloud__cloud', {
      'data-live-wordcloud-published': true,
    });
    objectRows(record.words || record.entries).forEach((word, index) => {
      const count = Math.max(1, Number(word.count || word.value || 1));
      const node = liveElement('span', 'quizgeist-live-word', {
        'data-live-word': String(word.text || word.word || ''),
        text: String(word.text || word.word || ''),
      });
      node.style.setProperty('--mq-word-weight', String(Math.min(6, 1 + Math.log2(count))));
      node.style.setProperty('--mq-word-slot', String(index % 6));
      node.append(liveElement('small', '', {text: ` ${count}`}));
      cloud.append(node);
    });
    if (cloud.childElementCount === 0) {
      cloud.append(liveElement('p', 'quizgeist-live-aggregate__empty', {
        text: context.text(
          'live:wordcloud:nopublished',
          'Noch keine freigegebenen Begriffe.',
        ),
      }));
    }
    root.append(cloud);

    const moderation = record.moderation;
    if (context.audience === 'host'
        && moderation
        && typeof moderation === 'object'
        && !Array.isArray(moderation)) {
      const moderationRecord = moderation as Record<string, unknown>;
      const panel = liveElement('section', 'quizgeist-live-wordcloud-moderation', {
        'aria-label': context.text(
          'live:wordcloud:moderation',
          'Begriffe moderieren',
        ),
        'data-live-wordcloud-moderation': true,
      });
      panel.append(liveElement('h2', 'quizgeist-live-wordcloud-moderation__title', {
        text: context.text('live:wordcloud:moderation', 'Begriffe moderieren'),
      }));
      const items = objectRows(moderationRecord.items);
      items.forEach((item) => {
        const key = String(item.key || '');
        const status = ['approved', 'rejected'].includes(String(item.status))
          ? String(item.status)
          : 'pending';
        const row = liveElement('article', 'quizgeist-live-wordcloud-moderation__item', {
          'data-live-wordcloud-status': status,
          'data-live-wordcloud-term-key': key,
        });
        const term = liveElement('span', 'quizgeist-live-wordcloud-moderation__term', {
          text: String(item.text || key),
        });
        term.append(liveElement('small', '', {
          text: ` ${Math.max(1, Number(item.count || 1))}`,
        }));
        const statusLabel = liveElement(
          'span',
          'quizgeist-live-wordcloud-moderation__status',
          {
            text: context.text(
              `live:wordcloud:${status}`,
              status === 'approved'
                ? 'Freigegeben'
                : status === 'rejected'
                  ? 'Abgelehnt'
                  : 'Ausstehend',
            ),
          },
        );
        row.append(term, statusLabel);
        if (context.onAggregateAction) {
          const actions = liveElement(
            'div',
            'quizgeist-live-wordcloud-moderation__actions',
          );
          const approve = liveButton(
            context.text('live:wordcloud:approve', 'Freigeben'),
            'quizgeist-host-button quizgeist-host-button--secondary',
            {
              'aria-busy': context.disabled ? 'true' : 'false',
              'aria-pressed': status === 'approved' ? 'true' : 'false',
              'data-live-host-interaction': true,
              'data-live-wordcloud-moderate': 'approved',
              'data-live-wordcloud-term-key': key,
              disabled: context.disabled || status === 'approved',
            },
          );
          const reject = liveButton(
            context.text('live:wordcloud:reject', 'Ablehnen'),
            'quizgeist-host-button quizgeist-host-button--secondary',
            {
              'aria-busy': context.disabled ? 'true' : 'false',
              'aria-pressed': status === 'rejected' ? 'true' : 'false',
              'data-live-host-interaction': true,
              'data-live-wordcloud-moderate': 'rejected',
              'data-live-wordcloud-term-key': key,
              disabled: context.disabled || status === 'rejected',
            },
          );
          approve.addEventListener('click', () => {
            context.onAggregateAction?.({
              decision: 'approved',
              interactionKind: 'moderation',
              termKey: key,
            });
          });
          reject.addEventListener('click', () => {
            context.onAggregateAction?.({
              decision: 'rejected',
              interactionKind: 'moderation',
              termKey: key,
            });
          });
          actions.append(approve, reject);
          row.append(actions);
        }
        panel.append(row);
      });
      if (items.length === 0) {
        panel.append(liveElement('p', 'quizgeist-live-aggregate__empty', {
          text: context.text('live:aggregate:empty', 'Noch keine Antworten.'),
        }));
      }
      root.append(panel);
    }
    return root;
  }
  if (kind === 'scale' || kind === 'slider') {
    const buckets = objectRows(record.histogram || record.buckets || record.entries);
    const chart = liveElement('div', 'quizgeist-live-scale-aggregate');
    buckets.forEach((bucket) => {
      const percent = Math.max(0, Math.min(100, Number(bucket.percent || 0)));
      const column = liveElement('div', 'quizgeist-live-scale-aggregate__column', {
        'data-live-aggregate-value': String(bucket.value ?? ''),
      });
      const bar = liveElement('span', 'quizgeist-live-scale-aggregate__bar', {
        text: String(bucket.count || 0),
      });
      bar.style.height = `${Math.max(4, percent)}%`;
      column.append(bar, liveElement('strong', '', {
        text: String(bucket.value ?? ''),
      }));
      chart.append(column);
    });
    root.append(chart);
    if (Number.isFinite(Number(record.mean))) {
      root.append(liveElement('p', 'quizgeist-live-aggregate__mean', {
        text: `${context.text('live:aggregate:mean', 'Mittelwert')}: ${Number(record.mean).toFixed(1)}`,
      }));
    }
    if (Number.isFinite(Number(record.median))) {
      root.append(liveElement('p', 'quizgeist-live-aggregate__median', {
        text: `${context.text('live:aggregate:median', 'Median')}: ${Number(record.median).toFixed(1)}`,
      }));
    }
    if (kind === 'slider' && buckets.length === 0 && Array.isArray(record.values)) {
      const values = record.values.filter((entry): entry is number =>
        typeof entry === 'number' && Number.isFinite(entry));
      const valuesNode = liveElement('p', 'quizgeist-live-aggregate__values', {
        'data-live-slider-values': true,
        text: values.join(' · '),
      });
      root.prepend(valuesNode);
    }
    return root;
  }
  if (kind === 'pin') {
    const canvas = liveElement('div', 'quizgeist-live-pin quizgeist-live-pin--aggregate', {
      'data-live-pin-heatmap': true,
    });
    const image = createLiveMedia(pinMedia(question), {
      allowPlayback: false,
      className: 'quizgeist-live-pin__image',
      label: question.questionText,
    });
    if (image) {
      canvas.append(image);
    }
    objectRows(record.points || record.heatmap).forEach((point) => {
      const dot = liveElement('span', 'quizgeist-live-pin__heat', {'aria-hidden': 'true'});
      dot.style.setProperty('--mq-pin-weight', String(Number(point.weight || point.count || 1)));
      canvas.append(dot);
      positionPinNode(canvas, image instanceof HTMLImageElement ? image : null, dot, {
        x: Number(point.x || 0),
        y: Number(point.y || 0),
      });
    });
    const target = record.target;
    if (target && typeof target === 'object') {
      const targetPoint = target as Record<string, unknown>;
      const marker = liveElement('span', 'quizgeist-live-pin__target', {
        'aria-label': context.text('live:pin:target', 'Zielbereich'),
      });
      canvas.append(marker);
      positionPinNode(canvas, image instanceof HTMLImageElement ? image : null, marker, {
        x: Number(targetPoint.x || 0),
        y: Number(targetPoint.y || 0),
      });
    }
    if (image instanceof HTMLImageElement) {
      image.addEventListener('load', () => {
        objectRows(record.points || record.heatmap).forEach((point, index) => {
          const dot = canvas.querySelectorAll<HTMLElement>('.quizgeist-live-pin__heat')[index];
          if (dot) {
            positionPinNode(canvas, image, dot, {
              x: Number(point.x || 0),
              y: Number(point.y || 0),
            });
          }
        });
        const marker = canvas.querySelector<HTMLElement>('.quizgeist-live-pin__target');
        if (marker && target && typeof target === 'object') {
          const targetPoint = target as Record<string, unknown>;
          positionPinNode(canvas, image, marker, {
            x: Number(targetPoint.x || 0),
            y: Number(targetPoint.y || 0),
          });
        }
      }, {once: true});
    }
    root.append(canvas);
    return root;
  }
  if (kind === 'brainstorm') {
    const groups = objectRows(record.groups);
    groups.forEach((group) => {
      const section = liveElement('section', 'quizgeist-live-brainstorm__group', {
        'data-live-brainstorm-group': String(group.key || ''),
      });
      section.append(liveElement('h3', '', {
        text: String(group.label || ''),
      }));
      if (group.votes !== null
          && group.votes !== undefined
          && Number.isFinite(Number(group.votes))) {
        section.append(liveElement('strong', 'quizgeist-live-brainstorm__votes', {
          text: `♥ ${Number(group.votes)}`,
        }));
      }
      objectRows(group.ideas).forEach((idea) => {
        const ideaNode = liveElement('article', 'quizgeist-live-brainstorm__idea', {
          'data-live-brainstorm-idea': String(idea.id || idea.key || ''),
          text: String(idea.text || ''),
        });
        if (idea.votes !== null
            && idea.votes !== undefined
            && Number.isFinite(Number(idea.votes))) {
          ideaNode.append(liveElement('span', 'quizgeist-live-brainstorm__votes', {
            text: `♥ ${Number(idea.votes)}`,
          }));
        }
        section.append(ideaNode);
      });
      root.append(section);
    });
    root.dataset.liveBrainstormStage = String(record.stage || '');

    const moderation = record.moderation;
    if (context.audience === 'host'
        && moderation
        && typeof moderation === 'object'
        && !Array.isArray(moderation)) {
      const moderationRecord = moderation as Record<string, unknown>;
      const panel = liveElement('section', 'quizgeist-live-wordcloud-moderation', {
        'aria-label': context.text(
          'live:brainstorm:moderation',
          'Ideen moderieren',
        ),
        'data-live-brainstorm-moderation': true,
      });
      panel.append(liveElement('h2', 'quizgeist-live-wordcloud-moderation__title', {
        text: context.text('live:brainstorm:moderation', 'Ideen moderieren'),
      }));
      const items = objectRows(moderationRecord.items);
      items.forEach((item) => {
        const id = String(item.id || '');
        const status = ['approved', 'rejected'].includes(String(item.status))
          ? String(item.status)
          : 'pending';
        const row = liveElement('article', 'quizgeist-live-wordcloud-moderation__item', {
          'data-live-brainstorm-idea-id': id,
          'data-live-brainstorm-status': status,
        });
        row.append(
          liveElement('span', 'quizgeist-live-wordcloud-moderation__term', {
            text: String(item.text || ''),
          }),
          liveElement('span', 'quizgeist-live-wordcloud-moderation__status', {
            text: context.text(
              `live:wordcloud:${status}`,
              status === 'approved'
                ? 'Freigegeben'
                : status === 'rejected'
                  ? 'Abgelehnt'
                  : 'Ausstehend',
            ),
          }),
        );
        if (context.onAggregateAction) {
          const actions = liveElement(
            'div',
            'quizgeist-live-wordcloud-moderation__actions',
          );
          const approve = liveButton(
            context.text('live:wordcloud:approve', 'Freigeben'),
            'quizgeist-host-button quizgeist-host-button--secondary',
            {
              'aria-busy': context.disabled ? 'true' : 'false',
              'aria-pressed': status === 'approved' ? 'true' : 'false',
              'data-live-brainstorm-moderate': 'approved',
              'data-live-host-interaction': true,
              disabled: context.disabled || status === 'approved',
            },
          );
          const reject = liveButton(
            context.text('live:wordcloud:reject', 'Ablehnen'),
            'quizgeist-host-button quizgeist-host-button--secondary',
            {
              'aria-busy': context.disabled ? 'true' : 'false',
              'aria-pressed': status === 'rejected' ? 'true' : 'false',
              'data-live-brainstorm-moderate': 'rejected',
              'data-live-host-interaction': true,
              disabled: context.disabled || status === 'rejected',
            },
          );
          approve.addEventListener('click', () => {
            context.onAggregateAction?.({
              decision: 'approved',
              ideaId: id,
              interactionKind: 'moderation',
            });
          });
          reject.addEventListener('click', () => {
            context.onAggregateAction?.({
              decision: 'rejected',
              ideaId: id,
              interactionKind: 'moderation',
            });
          });
          actions.append(approve, reject);
          row.append(actions);
        }
        panel.append(row);
      });
      if (items.length === 0) {
        panel.append(liveElement('p', 'quizgeist-live-aggregate__empty', {
          text: context.text('live:aggregate:empty', 'Noch keine Antworten.'),
        }));
      }
      root.append(panel);
    }
    return root;
  }
  if (kind === 'reactions') {
    const options = new Map(
      reactionOptions(question, context).map((option) => [option.id, option]),
    );
    objectRows(record.counts || record.entries).forEach((entry) => {
      const key = String(entry.reaction || entry.id || '');
      const option = options.get(key);
      const badge = String(
        entry.emoji || option?.emoji || option?.label
          || REACTION_LABEL_FALLBACKS[key] || '✨',
      );
      root.append(liveElement('span', 'quizgeist-live-reaction', {
        'data-live-reaction': key,
        text: `${badge} ${Number(entry.count || 0)}`,
      }));
    });
    return root;
  }
  if (kind === 'puzzle') {
    const positions = objectRows(record.positions);
    const order = Array.isArray(record.correctOrder)
      ? record.correctOrder
      : value<string[]>(question, 'correctOrderIds', []);
    const list = liveElement('ol', 'quizgeist-live-puzzle');
    const ids = order.length > 0
      ? order
      : positions
        .sort((left, right) => Number(left.position || 0) - Number(right.position || 0))
        .map((entry) => String(entry.itemId || entry.id || ''));
    ids.forEach((id) => {
      const item = value<LiveChoice[]>(question, 'items', [])
        .find((candidate) => candidate.id === id);
      const position = positions.find((entry) => String(entry.itemId || entry.id || '') === id);
      list.append(liveElement('li', 'quizgeist-live-puzzle__item', {
        text: `${item?.text || 'Puzzle-Element'}${
          position ? ` · ${Number(position.correctPositionCount || 0)} richtig platziert` : ''
        }`,
      }));
    });
    root.append(list);
    return root;
  }
  const responses = objectRows(record.responses || record.entries || record.answers);
  responses.forEach((entry) => {
    root.append(liveElement('blockquote', 'quizgeist-live-text-response', {
      'data-live-text-response': String(entry.id || ''),
      text: String(entry.text || entry.answer || ''),
    }));
  });
  if (responses.length === 0) {
    root.append(liveElement('p', 'quizgeist-live-aggregate__empty', {
      text: context.text('live:aggregate:empty', 'Noch keine Antworten.'),
    }));
  }
  return root;
}
