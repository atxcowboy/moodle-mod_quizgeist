import type {
  LiveConfig,
  LiveApiEnvelope,
  LiveErrorData,
} from './types';

export class LiveApiError extends Error {
  public readonly code: string;
  public readonly data: LiveErrorData | null;
  public readonly status: number;

  public constructor(
    message: string,
    code = 'request_failed',
    status = 0,
    data: LiveErrorData | null = null,
  ) {
    super(message);
    this.name = 'LiveApiError';
    this.code = code;
    this.status = status;
    this.data = data;
  }
}

export class LiveApi {
  public constructor(private readonly config: LiveConfig) {
  }

  public async post<T>(
    action: string,
    payload: Record<string, unknown> = {},
    signal?: AbortSignal,
  ): Promise<T> {
    let response: Response;
    try {
      response = await fetch(this.config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          action,
          cmid: this.config.cmid,
          sesskey: this.config.sesskey,
          ...payload,
        }),
        signal,
      });
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        throw error;
      }
      throw new LiveApiError(
        this.config.strings['live:connection:offline']
          || 'Die Verbindung ist gerade unterbrochen.',
        'network_error',
      );
    }

    let envelope: LiveApiEnvelope<T>;
    try {
      envelope = await response.json() as LiveApiEnvelope<T>;
    } catch (_error) {
      throw new LiveApiError(
        this.config.strings['live:error:request']
          || 'Die Live-Session konnte die Anfrage nicht verarbeiten.',
        'invalid_response',
        response.status,
      );
    }

    if (!response.ok || !envelope.ok || envelope.data === undefined) {
      const message = typeof envelope.message === 'string' && envelope.message !== ''
        ? envelope.message
        : this.config.strings['live:error:request']
          || 'Die Live-Session konnte die Anfrage nicht verarbeiten.';
      const data = envelope.data && typeof envelope.data === 'object'
        ? envelope.data as LiveErrorData
        : null;
      throw new LiveApiError(
        message,
        envelope.error || 'request_failed',
        response.status,
        data,
      );
    }

    return envelope.data;
  }
}
