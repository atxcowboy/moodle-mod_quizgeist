const MIN_POLL_DELAY_MS = 1100;
const MAX_ACTIVE_POLL_DELAY_MS = 4000;
const MAX_ERROR_DELAY_MS = 8000;
const SOCKET_POLL_DELAY_MS = 30_000;

export interface PollIteration {
  pollAfterMs?: number;
}

export interface PollErrorDecision {
  delayMs?: number;
  stop?: boolean;
}

export type PollRequest = (signal: AbortSignal) => Promise<PollIteration | void>;
export type PollErrorHandler = (
  error: unknown,
  consecutiveFailures: number,
) => PollErrorDecision | void;

export class AdaptivePoller {
  private controller: AbortController | null = null;
  private consecutiveFailures = 0;
  private inFlight = false;
  private kickPending = false;
  private running = false;
  private socketActive = false;
  private timeoutId: number | null = null;

  public constructor(
    private readonly request: PollRequest,
    private readonly onError: PollErrorHandler,
  ) {
  }

  public start(immediate = true): void {
    if (this.running) {
      return;
    }
    this.running = true;
    this.consecutiveFailures = 0;
    this.schedule(
      immediate
        ? 0
        : this.socketActive
          ? SOCKET_POLL_DELAY_MS
          : MIN_POLL_DELAY_MS,
    );
  }

  public stop(): void {
    this.running = false;
    this.socketActive = false;
    this.kickPending = false;
    if (this.timeoutId !== null) {
      window.clearTimeout(this.timeoutId);
      this.timeoutId = null;
    }
    this.controller?.abort();
    this.controller = null;
  }

  public kick(): void {
    if (!this.running) {
      this.start(true);
      return;
    }
    if (this.inFlight) {
      this.kickPending = true;
      return;
    }
    if (this.timeoutId !== null) {
      window.clearTimeout(this.timeoutId);
      this.timeoutId = null;
    }
    this.schedule(0);
  }

  /**
   * Keep a slow safety poll while the relay socket is connected. A lost
   * socket immediately returns to the normal adaptive polling cadence.
   */
  public setSocketActive(active: boolean): void {
    if (this.socketActive === active) {
      return;
    }
    this.socketActive = active;
    if (!this.running || this.inFlight) {
      return;
    }
    if (this.timeoutId !== null) {
      window.clearTimeout(this.timeoutId);
      this.timeoutId = null;
    }
    this.schedule(active ? SOCKET_POLL_DELAY_MS : 0);
  }

  public isRunning(): boolean {
    return this.running;
  }

  private schedule(delayMs: number): void {
    if (!this.running) {
      return;
    }
    this.timeoutId = window.setTimeout(() => {
      this.timeoutId = null;
      void this.tick();
    }, Math.max(0, delayMs));
  }

  private async tick(): Promise<void> {
    if (!this.running || this.inFlight) {
      return;
    }
    this.inFlight = true;
    this.controller = new AbortController();
    let nextDelay = MIN_POLL_DELAY_MS;
    try {
      const result = await this.request(this.controller.signal);
      this.consecutiveFailures = 0;
      nextDelay = this.successDelay(result?.pollAfterMs);
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        return;
      }
      this.consecutiveFailures += 1;
      const decision = this.onError(error, this.consecutiveFailures) || {};
      if (decision.stop) {
        this.running = false;
        return;
      }
      nextDelay = decision.delayMs
        ?? Math.min(
          MAX_ERROR_DELAY_MS,
          MIN_POLL_DELAY_MS * (2 ** Math.min(3, this.consecutiveFailures - 1)),
        );
    } finally {
      this.inFlight = false;
      this.controller = null;
    }

    if (!this.running) {
      return;
    }
    if (this.kickPending) {
      this.kickPending = false;
      this.schedule(0);
      return;
    }
    this.schedule(nextDelay);
  }

  private successDelay(requestedDelay?: number): number {
    if (this.socketActive) {
      return SOCKET_POLL_DELAY_MS;
    }
    if (document.visibilityState === 'hidden') {
      return MAX_ACTIVE_POLL_DELAY_MS;
    }
    const numericDelay = Number.isFinite(requestedDelay)
      ? Number(requestedDelay)
      : MIN_POLL_DELAY_MS;
    return Math.max(
      MIN_POLL_DELAY_MS,
      Math.min(MAX_ACTIVE_POLL_DELAY_MS, numericDelay),
    );
  }
}

export const LIVE_POLL_MIN_DELAY_MS = MIN_POLL_DELAY_MS;
