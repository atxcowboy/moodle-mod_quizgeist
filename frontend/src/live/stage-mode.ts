export type StageMode = 'off' | 'fullscreen';

export interface StageModeHooks {
  announce: (message: string) => void;
  text: (key: string, fallback: string) => string;
}

export interface StageModeOptions {
  /** Use exact root equality instead of treating fullscreen descendants as active. */
  exactRoot?: boolean;
  buttonClassName?: string;
  toggleKey?: string;
  toggleFallback?: string;
  titleKey?: string;
  titleFallback?: string;
  onKey?: string;
  onFallback?: string;
  offKey?: string;
  offFallback?: string;
  /** Focus the toggle (or focusFallback) only for a controller-initiated change. */
  focusOnlyOwnChange?: boolean;
  /** Keep an active text/editing control focused when announcing a change. */
  avoidInputFocus?: boolean;
  focusFallback?: () => void;
  onModeChange?: (mode: StageMode) => void;
}

interface FullscreenDocument extends Document {
  webkitFullscreenEnabled?: boolean;
  webkitFullscreenElement?: Element | null;
  webkitExitFullscreen?: () => void | Promise<void>;
}

interface FullscreenElement extends HTMLElement {
  webkitRequestFullscreen?: () => void | Promise<void>;
}

export class StageModeController {
  private readonly document: FullscreenDocument;
  private readonly available: boolean;
  private readonly options: StageModeOptions;
  private readonly exactRoot: boolean;
  private readonly focusOnlyOwnChange: boolean;
  private readonly avoidInputFocus: boolean;
  private toggleButton: HTMLButtonElement | null = null;
  private attached = false;
  private _mode: StageMode = 'off';
  private rootSessionActive = false;
  private pendingEnter = false;
  private pendingLeave = false;
  private ownEnterPending = false;
  private ownLeavePending = false;
  private leaveAfterEnter = false;

  public constructor(
    private readonly root: HTMLElement,
    private readonly hooks: StageModeHooks,
    options: StageModeOptions = {},
  ) {
    this.options = options;
    this.exactRoot = options.exactRoot ?? false;
    this.focusOnlyOwnChange = options.focusOnlyOwnChange ?? false;
    this.avoidInputFocus = options.avoidInputFocus ?? false;
    this.document = root.ownerDocument as FullscreenDocument;
    const fullscreenRoot = root as FullscreenElement;
    this.available = (
      (this.document.fullscreenEnabled === true
        || this.document.webkitFullscreenEnabled === true)
      && (typeof root.requestFullscreen === 'function'
        || typeof fullscreenRoot.webkitRequestFullscreen === 'function')
    );
  }

  public mode(): StageMode {
    return this._mode;
  }

  public isAvailable(): boolean {
    return this.available;
  }

  public createToggle(): HTMLButtonElement {
    const label = this.hooks.text(
      this.options.toggleKey ?? 'host:stagemode:toggle',
      this.options.toggleFallback ?? 'Vollbild',
    );
    const button = this.document.createElement('button');
    button.type = 'button';
    button.className = this.options.buttonClassName
      ?? 'quizgeist-host-button quizgeist-host-button--secondary quizgeist-host-toolbar__stagemode';
    button.textContent = label;
    button.title = this.options.titleKey !== undefined
      || this.options.titleFallback !== undefined
      ? this.hooks.text(
        this.options.titleKey ?? 'host:stagemode:title',
        this.options.titleFallback ?? 'Vollbild ein- und ausschalten',
      )
      : label === 'Vollbild' ? 'Vollbild ein- und ausschalten' : label;
    button.setAttribute('aria-pressed', this._mode === 'fullscreen' ? 'true' : 'false');
    button.hidden = !this.available;
    button.addEventListener('click', () => {
      void this.toggle();
    });
    this.toggleButton = button;
    return button;
  }

  public attach(): void {
    if (this.attached) {
      return;
    }
    this.attached = true;
    this.document.addEventListener('fullscreenchange', this.handleFullscreenChange);
    this.document.addEventListener('webkitfullscreenchange', this.handleFullscreenChange);
    this.document.addEventListener('fullscreenerror', this.handleFullscreenError);
  }

  public detach(): void {
    if (!this.attached) {
      return;
    }
    this.document.removeEventListener('fullscreenchange', this.handleFullscreenChange);
    this.document.removeEventListener('webkitfullscreenchange', this.handleFullscreenChange);
    this.document.removeEventListener('fullscreenerror', this.handleFullscreenError);
    this.attached = false;
  }

  public async enter(): Promise<void> {
    if (!this.available || this.isActive()) {
      return;
    }
    this.pendingEnter = true;
    this.ownEnterPending = true;
    this.leaveAfterEnter = false;
    const fullscreenRoot = this.root as FullscreenElement;
    try {
      const request = typeof this.root.requestFullscreen === 'function'
        ? this.root.requestFullscreen()
        : fullscreenRoot.webkitRequestFullscreen?.();
      if (request && typeof (request as Promise<void>).then === 'function') {
        await Promise.resolve(request);
        this.pendingEnter = false;
        if (this.leaveAfterEnter && this.isActive()) {
          void this.leave();
        }
      } else {
        await this.nextFrame();
        if (!this.isActive()) {
          this.failPendingEnter();
        } else {
          this.pendingEnter = false;
          if (this.leaveAfterEnter) {
            void this.leave();
          }
        }
      }
    } catch (_error) {
      this.failPendingEnter();
    }
  }

  public async leave(): Promise<void> {
    if (!this.isActive()) {
      if (this.pendingEnter || this.ownEnterPending) {
        this.leaveAfterEnter = true;
        this.ownLeavePending = true;
      }
      return;
    }
    this.pendingLeave = true;
    this.ownLeavePending = true;
    this.leaveAfterEnter = false;
    try {
      const exit = this.document.exitFullscreen
        || this.document.webkitExitFullscreen;
      if (!exit) {
        this.pendingLeave = false;
        this.ownLeavePending = false;
        return;
      }
      const result = exit.call(this.document);
      if (result && typeof (result as Promise<void>).then === 'function') {
        await Promise.resolve(result);
        this.pendingLeave = false;
      } else {
        await this.nextFrame();
        this.pendingLeave = false;
      }
    } catch (_error) {
      this.pendingLeave = false;
      this.ownLeavePending = false;
    }
  }

  public async toggle(): Promise<void> {
    if (this.isActive()) {
      await this.leave();
    } else {
      await this.enter();
    }
  }

  private readonly handleFullscreenChange = (): void => {
    this.syncFromDocument();
  };

  private readonly handleFullscreenError = (): void => {
    if (this.pendingEnter && !this.isActive()) {
      this.failPendingEnter();
      return;
    }
    if (this.pendingLeave) {
      this.pendingLeave = false;
      this.ownLeavePending = false;
    }
  };

  private fullscreenElement(): Element | null {
    return this.document.fullscreenElement
      ?? this.document.webkitFullscreenElement
      ?? null;
  }

  private isActive(): boolean {
    const fullscreen = this.fullscreenElement();
    if (fullscreen === this.root) {
      return true;
    }
    return !this.exactRoot
      && fullscreen instanceof Node
      && this.root.contains(fullscreen);
  }

  private async nextFrame(): Promise<void> {
    await new Promise<void>((resolve) => {
      const requestAnimationFrame = this.document.defaultView?.requestAnimationFrame;
      if (requestAnimationFrame) {
        requestAnimationFrame(() => resolve());
      } else {
        resolve();
      }
    });
  }

  private failPendingEnter(): void {
    if (!this.pendingEnter) {
      return;
    }
    this.pendingEnter = false;
    this.ownEnterPending = false;
    this.ownLeavePending = false;
    this.leaveAfterEnter = false;
    if (this.isActive()) {
      return;
    }
    this.rootSessionActive = false;
    this.setMode('off');
    this.hooks.announce(this.hooks.text(
      this.options.offKey ?? 'host:stagemode:off',
      this.options.offFallback ?? 'Vollbild ausgeschaltet.',
    ));
  }

  private syncFromDocument(): void {
    const fullscreen = this.fullscreenElement();
    const rootFullscreen = fullscreen === this.root;
    const active = this.isActive();
    const previousMode = this._mode;
    const nextMode: StageMode = active ? 'fullscreen' : 'off';
    const ownEnter = this.ownEnterPending;
    const ownLeave = this.ownLeavePending;
    this.setMode(nextMode);

    if (rootFullscreen && active) {
      const enteredRoot = !this.rootSessionActive;
      this.rootSessionActive = true;
      this.pendingEnter = false;
      this.pendingLeave = false;
      this.ownEnterPending = false;
      this.ownLeavePending = false;
      if (this.leaveAfterEnter) {
        this.leaveAfterEnter = false;
        void this.leave();
        return;
      }
      if (enteredRoot && previousMode === 'off') {
        this.announceAndFocus(
          this.options.onKey ?? 'host:stagemode:on',
          this.options.onFallback ?? 'Vollbild eingeschaltet.',
          ownEnter,
        );
      }
      return;
    }
    if (active) {
      this.pendingEnter = false;
      this.ownEnterPending = false;
      return;
    }

    this.pendingEnter = false;
    this.pendingLeave = false;
    this.ownEnterPending = false;
    this.leaveAfterEnter = false;
    if (previousMode === 'fullscreen' && this.rootSessionActive) {
      this.rootSessionActive = false;
      this.ownLeavePending = false;
      this.announceAndFocus(
        this.options.offKey ?? 'host:stagemode:off',
        this.options.offFallback ?? 'Vollbild ausgeschaltet.',
        ownLeave,
      );
    } else {
      this.rootSessionActive = false;
      this.ownLeavePending = false;
    }
  }

  private announceAndFocus(key: string, fallback: string, ownChange: boolean): void {
    this.hooks.announce(this.hooks.text(key, fallback));
    if (this.focusOnlyOwnChange && !ownChange) {
      return;
    }
    if (this.avoidInputFocus && this.isEditableFocusTarget()) {
      return;
    }
    if (this.toggleButton?.isConnected && !this.toggleButton.hidden) {
      this.toggleButton.focus();
      return;
    }
    if (ownChange) {
      this.focusFallbackTarget();
    }
  }

  private updateToggle(): void {
    if (!this.toggleButton) {
      return;
    }
    this.toggleButton.setAttribute('aria-pressed', this._mode === 'fullscreen' ? 'true' : 'false');
  }

  private setMode(mode: StageMode): void {
    const changed = this._mode !== mode;
    this._mode = mode;
    this.updateToggle();
    if (changed) {
      this.options.onModeChange?.(mode);
    }
  }

  private isEditableFocusTarget(): boolean {
    const active = this.document.activeElement;
    if (!(active instanceof HTMLElement)) {
      return false;
    }
    if (active.isContentEditable) {
      return true;
    }
    const tagName = active.tagName.toLowerCase();
    return tagName === 'input' || tagName === 'textarea' || tagName === 'select'
      || active.closest('[contenteditable]') !== null;
  }

  private focusFallbackTarget(): void {
    const fallback = this.options.focusFallback;
    if (!fallback) {
      return;
    }
    fallback();
  }
}
