import {HostApp} from './host/host-app';
import {liveElement} from './live/dom';
import {isHostConfig} from './live/types';
import type {QuizgeistInitConfig} from './types';

export {
  hostPosition,
  isSameHostPosition,
  retryHostMutation,
} from './host/conflict-retry';

let activeApp: HostApp | null = null;

export function init(config: QuizgeistInitConfig = {}): void {
  const containerId = config.containerId || 'quizgeist-app-host';
  const root = document.getElementById(containerId);
  if (!root) {
    return;
  }
  const hostConfig = {...config, containerId};
  activeApp?.destroy();
  activeApp = null;

  if (!isHostConfig(hostConfig)) {
    root.dataset.quizgeistRoot = 'host';
    root.classList.add('quizgeist-host-root');
    const error = liveElement('div', 'quizgeist-host-state-card quizgeist-host-state-card--error', {
      role: 'alert',
    });
    error.append(
      liveElement('h1', 'quizgeist-host-state-card__title', {
        text: config.strings?.['live:error:config']
          || 'Die Live-Ansicht konnte nicht gestartet werden.',
      }),
    );
    root.replaceChildren(error);
    return;
  }

  activeApp = new HostApp(root, hostConfig);
  void activeApp.init();
}
