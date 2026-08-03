import type {QuizgeistInitConfig} from '../types';
import type {SelfStudyConfig} from './types';

function positiveId(value: unknown): number {
  const candidate = Number(value || 0);
  return Number.isInteger(candidate) && candidate > 0 ? candidate : 0;
}

export function normalizeSelfStudyConfig(
  raw: QuizgeistInitConfig,
  defaultContainerId: string,
): SelfStudyConfig | null {
  const ajaxUrl = typeof raw.ajaxUrl === 'string' ? raw.ajaxUrl : '';
  const cmid = positiveId(raw.cmid);
  const sesskey = typeof raw.sesskey === 'string' ? raw.sesskey : '';
  if (ajaxUrl === '' || cmid <= 0 || sesskey === '') {
    return null;
  }
  const initialView = raw.initialView === 'assignments'
    || raw.initialView === 'attempt'
    || raw.initialView === 'live'
    ? raw.initialView
    : 'overview';
  return {
    ...raw,
    ajaxUrl,
    assignmentId: positiveId(raw.assignmentId),
    attemptId: positiveId(raw.attemptId),
    brandIconUrl: typeof raw.brandIconUrl === 'string' ? raw.brandIconUrl : '',
    canCreate: raw.features?.selfstudy?.canCreate === true,
    cmid,
    containerId: typeof raw.containerId === 'string' && raw.containerId !== ''
      ? raw.containerId
      : defaultContainerId,
    initialView,
    overviewUrl: typeof raw.overviewUrl === 'string' && raw.overviewUrl !== ''
      ? raw.overviewUrl
      : window.location.pathname,
    playerUrlBase: typeof raw.playerUrlBase === 'string'
      ? raw.playerUrlBase
      : '',
    season: typeof raw.season === 'string' ? raw.season : 'herbst',
    sesskey,
    strings: raw.strings || {},
    theme: typeof raw.theme === 'string' ? raw.theme : 'hell',
  };
}
