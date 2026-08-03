export interface RelayConnection {
  channel: string;
  url: string;
}

export interface RelaySocketOptions {
  connection: RelayConnection;
  onStateVersion: (stateVersion: number) => void;
  onStatus?: (connected: boolean) => void;
}

const CHANNEL_DEADLINE_MS = 10_000;
const STABLE_CONNECTION_MS = 10_000;
const MAX_FAILURES = 3;
const RECONNECT_DELAYS_MS = [1_000, 2_000, 4_000, 8_000, 15_000] as const;

/**
 * Receives state-version hints from the optional live-session relay.
 *
 * The socket deliberately carries no session data. Polling remains the
 * authoritative data path; a newer number merely asks the poller to run now.
 */
export class RelaySocket {
  private socket: WebSocket | null = null;
  private reconnectTimer: number | null = null;
  private channelTimer: number | null = null;
  private stableTimer: number | null = null;
  private stopped = true;
  private failureCount = 0;
  private lastStateVersion = -1;

  public constructor(private readonly options: RelaySocketOptions) {
  }

  public start(): void {
    if (!this.stopped) {
      return;
    }
    this.stopped = false;
    this.failureCount = 0;
    this.connect();
  }

  public stop(): void {
    this.stopped = true;
    if (this.reconnectTimer !== null) {
      window.clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    this.clearChannelTimer();
    this.clearStableTimer();
    const socket = this.socket;
    this.socket = null;
    if (socket !== null) {
      socket.onopen = null;
      socket.onmessage = null;
      socket.onerror = null;
      socket.onclose = null;
      try {
        socket.close();
      } catch (_error) {
        // Closing an already failed browser socket is best effort.
      }
    }
    this.options.onStatus?.(false);
  }

  public isConnected(): boolean {
    return this.socket?.readyState === WebSocket.OPEN;
  }

  private connect(): void {
    if (this.stopped || this.socket !== null) {
      return;
    }
    let socket: WebSocket;
    try {
      socket = new WebSocket(this.options.connection.url);
    } catch (_error) {
      this.connectionFailed();
      return;
    }
    this.socket = socket;
    this.channelTimer = window.setTimeout(() => {
      if (this.socket !== socket || socket.readyState !== WebSocket.CONNECTING) {
        return;
      }
      this.failSocket(socket);
    }, CHANNEL_DEADLINE_MS);

    socket.onopen = () => {
      if (this.socket !== socket || this.stopped) {
        return;
      }
      this.clearChannelTimer();
      try {
        // The relay accepts exactly one first frame: the opaque channel key.
        socket.send(this.options.connection.channel);
      } catch (_error) {
        this.failSocket(socket);
        return;
      }
      this.clearStableTimer();
      this.stableTimer = window.setTimeout(() => {
        if (this.socket !== socket
            || this.stopped
            || socket.readyState !== WebSocket.OPEN) {
          return;
        }
        this.failureCount = 0;
        this.stableTimer = null;
      }, STABLE_CONNECTION_MS);
      this.options.onStatus?.(true);
    };
    socket.onmessage = (event: MessageEvent) => {
      if (this.socket !== socket || this.stopped || typeof event.data !== 'string') {
        return;
      }
      let message: unknown;
      try {
        message = JSON.parse(event.data);
      } catch (_error) {
        return;
      }
      if (!message || typeof message !== 'object') {
        return;
      }
      const version = (message as {v?: unknown}).v;
      if (!Number.isSafeInteger(version) || Number(version) < 0) {
        return;
      }
      const stateVersion = Number(version);
      if (stateVersion <= this.lastStateVersion) {
        return;
      }
      this.lastStateVersion = stateVersion;
      this.options.onStateVersion(stateVersion);
    };
    socket.onerror = () => {
      if (this.socket !== socket || this.stopped) {
        return;
      }
      this.failSocket(socket);
    };
    socket.onclose = () => {
      if (this.socket !== socket) {
        return;
      }
      this.socket = null;
      this.clearChannelTimer();
      this.clearStableTimer();
      this.options.onStatus?.(false);
      if (!this.stopped) {
        this.connectionFailed();
      }
    };
  }

  private connectionFailed(): void {
    if (this.stopped || this.socket !== null) {
      return;
    }
    this.options.onStatus?.(false);
    this.failureCount += 1;
    if (this.failureCount >= MAX_FAILURES) {
      return;
    }
    const delay = RECONNECT_DELAYS_MS[
      Math.min(this.failureCount - 1, RECONNECT_DELAYS_MS.length - 1)
    ];
    this.reconnectTimer = window.setTimeout(() => {
      this.reconnectTimer = null;
      this.connect();
    }, delay);
  }

  private failSocket(socket: WebSocket): void {
    if (this.socket !== socket) {
      return;
    }
    this.socket = null;
    this.clearChannelTimer();
    this.clearStableTimer();
    try {
      socket.close();
    } catch (_error) {
      // Closing a failed browser socket is best effort.
    }
    this.connectionFailed();
  }

  private clearChannelTimer(): void {
    if (this.channelTimer !== null) {
      window.clearTimeout(this.channelTimer);
      this.channelTimer = null;
    }
  }

  private clearStableTimer(): void {
    if (this.stableTimer !== null) {
      window.clearTimeout(this.stableTimer);
      this.stableTimer = null;
    }
  }
}

export function createRelaySocket(options: RelaySocketOptions): RelaySocket {
  return new RelaySocket(options);
}
