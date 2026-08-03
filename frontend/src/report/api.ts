import {LiveApi, LiveApiError} from '../live/api';
import {
  normaliseReportBootstrap,
  normaliseReportData,
} from './normalise';
import type {
  ReportBootstrap,
  ReportConfig,
  ReportData,
  ReportSelection,
} from './types';

export class ReportApi {
  private readonly api: LiveApi;

  public constructor(private readonly config: ReportConfig) {
    this.api = new LiveApi(config);
  }

  public async bootstrap(signal?: AbortSignal): Promise<ReportBootstrap> {
    return normaliseReportBootstrap(
      await this.api.post<unknown>(this.config.bootstrapAction, {}, signal),
      this.config.viewerKind,
    );
  }

  public async data(
    selection: ReportSelection,
    signal?: AbortSignal,
  ): Promise<ReportData> {
    return normaliseReportData(
      await this.api.post<unknown>(
        this.config.dataAction,
        {
          groupId: selection.groupId,
          scope: selection.scope,
          sourceKeys: selection.sourceKeys,
        },
        signal,
      ),
      selection,
    );
  }

  /**
   * Read the teacher's due-topics overview (F3).
   *
   * Deliberately unnormalised here: the panel owns the shape of its own DTO.
   */
  public async schedule(signal?: AbortSignal): Promise<unknown> {
    return this.api.post<unknown>('schedule_overview', {}, signal);
  }

  /**
   * Read the misconception radar (F5).
   *
   * Deliberately unnormalised here: the panel owns the shape of its own DTO.
   */
  public async misconceptions(signal?: AbortSignal): Promise<unknown> {
    return this.api.post<unknown>('misconception_list', {}, signal);
  }

  public errorMessage(error: unknown): string {
    if (error instanceof LiveApiError && error.message !== '') {
      return error.message;
    }
    return this.config.strings['report:error:request']
      || this.config.strings['live:error:request']
      || 'Der Bericht konnte nicht geladen werden.';
  }
}
