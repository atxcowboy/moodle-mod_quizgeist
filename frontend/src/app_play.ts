import {mountPlayerApp} from './play/player-app';
import {normalizeSelfStudyConfig} from './selfstudy/config';
import {mountStudentSelfStudyApp} from './selfstudy/student-app';
import type {HostConfig} from './live/types';
import type {QuizgeistInitConfig} from './types';

export function init(config: QuizgeistInitConfig = {}): void {
  const liveRequested = config.features?.selfstudy?.installed !== true
    || config.initialView === 'live'
    || (typeof config.initialJoinCode === 'string'
      && /^[0-9]{6}$/.test(config.initialJoinCode))
    || new URL(window.location.href).searchParams.get('view') === 'play';
  if (liveRequested) {
    mountPlayerApp(config as HostConfig);
    return;
  }
  const normalized = normalizeSelfStudyConfig(config, 'quizgeist-app-play');
  const containerId = normalized?.containerId
    || (typeof config.containerId === 'string'
      ? config.containerId
      : 'quizgeist-app-play');
  const root = document.getElementById(containerId);
  if (!root) {
    return;
  }
  if (!normalized) {
    const error = document.createElement('div');
    error.className = 'quizgeist-study-state quizgeist-study-state--error';
    error.setAttribute('role', 'alert');
    error.textContent = config.strings?.['selfstudy:error:config']
      || config.strings?.['live:error:config']
      || 'Die Lernübersicht konnte nicht gestartet werden.';
    root.replaceChildren(error);
    return;
  }
  mountStudentSelfStudyApp(root, normalized);
}
