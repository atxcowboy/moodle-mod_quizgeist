export type Child = Node | string | null | undefined | false;

const dialogOpeners = new WeakMap<HTMLDialogElement, HTMLElement>();

export function appendChildren(parent: HTMLElement, ...children: Child[]): void {
  for (const child of children) {
    if (child === null || child === undefined || child === false) {
      continue;
    }
    parent.append(child instanceof Node ? child : document.createTextNode(child));
  }
}

export function element<K extends keyof HTMLElementTagNameMap>(
  tagName: K,
  className = '',
  attributes: Record<string, string | number | boolean | null | undefined> = {},
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tagName);
  if (className !== '') {
    node.className = className;
  }
  for (const [name, value] of Object.entries(attributes)) {
    if (value === undefined || value === null || value === false) {
      continue;
    }
    if (name === 'text') {
      node.textContent = String(value);
    } else if (name === 'checked' && node instanceof HTMLInputElement) {
      node.checked = Boolean(value);
    } else if (name === 'disabled' && 'disabled' in node) {
      (node as HTMLButtonElement).disabled = Boolean(value);
    } else if (name === 'value' && 'value' in node) {
      (node as HTMLInputElement).value = String(value);
    } else if (value === true) {
      node.setAttribute(name, '');
    } else {
      node.setAttribute(name, String(value));
    }
  }
  return node;
}

export function button(
  label: string,
  className = '',
  onClick?: (event: MouseEvent) => void,
): HTMLButtonElement {
  const node = element('button', className, {type: 'button', text: label});
  if (onClick) {
    node.addEventListener('click', onClick);
  }
  return node;
}

export function labelledField(
  labelText: string,
  control: HTMLElement,
  hint?: string,
  className = '',
): HTMLDivElement {
  const wrapper = element('div', `quizgeist-field ${className}`.trim());
  const label = element('label', 'quizgeist-field__label', {text: labelText});
  const labelTarget = control.matches('button, input, meter, output, progress, select, textarea')
    ? control
    : control.querySelector<HTMLElement>(
      'button, input, meter, output, progress, select, textarea',
    );
  if (labelTarget && !labelTarget.id) {
    labelTarget.id = `quizgeist-field-${crypto.randomUUID()}`;
  }
  if (labelTarget) {
    label.htmlFor = labelTarget.id;
  }
  appendChildren(wrapper, label, control);
  if (hint) {
    const hintNode = element('p', 'quizgeist-field__hint', {text: hint});
    hintNode.id = `${labelTarget?.id || `quizgeist-field-${crypto.randomUUID()}`}-hint`;
    labelTarget?.setAttribute('aria-describedby', hintNode.id);
    wrapper.append(hintNode);
  }
  return wrapper;
}

export function visuallyHidden(text: string): HTMLSpanElement {
  return element('span', 'quizgeist-visually-hidden', {text});
}

export function setButtonBusy(buttonNode: HTMLButtonElement, busy: boolean, label: string): void {
  buttonNode.disabled = busy;
  buttonNode.setAttribute('aria-busy', busy ? 'true' : 'false');
  buttonNode.textContent = label;
}

export function closeDialog(dialog: HTMLDialogElement): void {
  const opener = dialogOpeners.get(dialog);
  if (dialog.open) {
    dialog.close();
  }
  dialog.remove();
  dialogOpeners.delete(dialog);
  if (opener?.isConnected) {
    opener.focus();
  }
}

export function openDialog(
  title: string,
  closeLabel: string,
  className = '',
): {
  body: HTMLDivElement;
  closeButton: HTMLButtonElement;
  dialog: HTMLDialogElement;
  footer: HTMLElement;
} {
  const dialog = element('dialog', `quizgeist-dialog ${className}`.trim());
  const activeElement = document.activeElement instanceof HTMLElement
    ? document.activeElement
    : null;
  const themeRoot = activeElement?.closest<HTMLElement>('[data-quizgeist-root]')
    || document.querySelector<HTMLElement>('.quizgeist-editor-root[data-quizgeist-root]');
  dialog.dataset.quizgeistRoot = themeRoot?.dataset.quizgeistRoot || 'dialog';
  dialog.dataset.quizgeistTheme = themeRoot?.dataset.quizgeistTheme || 'hell';
  dialog.dataset.quizgeistSeason = themeRoot?.dataset.quizgeistSeason || 'herbst';
  if (themeRoot?.id) {
    dialog.dataset.quizgeistOwner = themeRoot.id;
  }
  const surface = element('div', 'quizgeist-dialog__surface');
  const header = element('header', 'quizgeist-dialog__header');
  const heading = element('h2', 'quizgeist-dialog__title', {text: title});
  heading.id = `quizgeist-dialog-${crypto.randomUUID()}`;
  dialog.setAttribute('aria-labelledby', heading.id);
  const closeButton = button(closeLabel, 'quizgeist-icon-button');
  closeButton.setAttribute('aria-label', closeLabel);
  header.append(heading, closeButton);
  const body = element('div', 'quizgeist-dialog__body', {tabindex: 0});
  const footer = element('footer', 'quizgeist-dialog__footer');
  surface.append(header, body, footer);
  dialog.append(surface);
  document.body.append(dialog);
  if (activeElement) {
    dialogOpeners.set(dialog, activeElement);
  }

  const close = (): void => closeDialog(dialog);
  closeButton.addEventListener('click', close);
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    close();
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      close();
    }
  });
  dialog.showModal();
  closeButton.focus();

  return {body, closeButton, dialog, footer};
}
