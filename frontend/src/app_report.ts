import {liveElement} from './live/dom';
import {normaliseReportConfig} from './report/normalise';
import {ReportApp} from './report/report-app';
import type {QuizgeistInitConfig} from './types';

export function init(raw: QuizgeistInitConfig = {}): void {
  const config = normaliseReportConfig(raw);
  const containerId = config?.containerId
    || (typeof raw.containerId === 'string' && raw.containerId !== ''
      ? raw.containerId
      : 'quizgeist-app-report');
  const root = document.getElementById(containerId);
  if (!root) {
    return;
  }
  if (!config) {
    root.replaceChildren(liveElement(
      'section',
      'quizgeist-report-state quizgeist-report-state--error',
      {
        role: 'alert',
        text: raw.strings?.['report:error:config']
          || 'Die Berichtsansicht konnte nicht gestartet werden.',
      },
    ));
    return;
  }
  const app = new ReportApp(root, config);
  void app.init();
}
