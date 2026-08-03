import type {ApiEnvelope, EditorConfig} from './types';

export class EditorApiError extends Error {
  public readonly code: string;
  public readonly status: number;

  public constructor(message: string, code = 'request_failed', status = 0) {
    super(message);
    this.name = 'EditorApiError';
    this.code = code;
    this.status = status;
  }
}

export class EditorApi {
  public constructor(private readonly config: EditorConfig) {
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
      throw new EditorApiError(this.config.strings['editor:error:network']);
    }

    let envelope: ApiEnvelope<T>;
    try {
      envelope = await response.json() as ApiEnvelope<T>;
    } catch (_error) {
      throw new EditorApiError(
        this.config.strings['editor:error:response'],
        'invalid_response',
        response.status,
      );
    }

    if (!response.ok || !envelope.ok) {
      const message = typeof envelope.message === 'string' && envelope.message !== ''
        ? envelope.message
        : this.config.strings['editor:error:request'];
      throw new EditorApiError(message, envelope.error || 'request_failed', response.status);
    }

    return envelope.data;
  }
}
