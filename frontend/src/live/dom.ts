export type LiveChild = Node | string | number | null | undefined | false;

export function appendChildren(parent: HTMLElement, ...children: LiveChild[]): void {
  for (const child of children) {
    if (child === null || child === undefined || child === false) {
      continue;
    }
    parent.append(
      child instanceof Node ? child : document.createTextNode(String(child)),
    );
  }
}

export function liveElement<K extends keyof HTMLElementTagNameMap>(
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
    } else if (name === 'selected' && node instanceof HTMLOptionElement) {
      node.selected = Boolean(value);
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

export function liveButton(
  label: string,
  className = '',
  attributes: Record<string, string | number | boolean | null | undefined> = {},
): HTMLButtonElement {
  return liveElement('button', className, {
    type: 'button',
    text: label,
    ...attributes,
  });
}

export function liveVisuallyHidden(text: string): HTMLSpanElement {
  return liveElement('span', 'quizgeist-live-visually-hidden', {text});
}

export function formatJoinCode(joinCode: string): string {
  const cleaned = joinCode.replace(/\s+/g, '');
  return cleaned.length === 6
    ? `${cleaned.slice(0, 3)} ${cleaned.slice(3)}`
    : cleaned;
}

export function setNodeText(
  root: ParentNode,
  selector: string,
  value: string | number,
): void {
  const node = root.querySelector<HTMLElement>(selector);
  if (node && node.textContent !== String(value)) {
    node.textContent = String(value);
  }
}

export function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError';
}
