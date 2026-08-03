import {EditorApp} from './editor/editor-app';
import type {EditorConfig} from './editor/types';
import {normalizeSelfStudyConfig} from './selfstudy/config';
import {mountAssignmentTeacherApp} from './selfstudy/teacher-app';
import type {QuizgeistInitConfig, QuizgeistTtsVoice} from './types';

function voices(value: unknown): QuizgeistTtsVoice[] {
  if (!Array.isArray(value)) {
    return [];
  }
  return value.flatMap((entry) => {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
      return [];
    }
    const voice = entry as Record<string, unknown>;
    const id = Number(voice.id || 0);
    const label = typeof voice.label === 'string' ? voice.label : '';
    if (!Number.isInteger(id) || id <= 0 || label === '') {
      return [];
    }
    return [{
      id,
      label,
      ...(typeof voice.lang === 'string' ? {lang: voice.lang} : {}),
      ...(typeof voice.region === 'string' ? {region: voice.region} : {}),
      ...(typeof voice.gender === 'string' ? {gender: voice.gender} : {}),
    }];
  });
}

function normalizeConfig(raw: QuizgeistInitConfig): EditorConfig | null {
  const cmid = Number(raw.cmid || 0);
  const ajaxUrl = typeof raw.ajaxUrl === 'string' ? raw.ajaxUrl : '';
  const sesskey = typeof raw.sesskey === 'string' ? raw.sesskey : '';
  if (!Number.isInteger(cmid) || cmid <= 0 || ajaxUrl === '' || sesskey === '') {
    return null;
  }
  const configuredVoices = voices(raw.tts?.voices);
  const speakUrl = typeof raw.tts?.speakUrl === 'string'
    ? raw.tts.speakUrl
    : '';
  return {
    ...raw,
    ajaxUrl,
    brandIconUrl: typeof raw.brandIconUrl === 'string' ? raw.brandIconUrl : '',
    cmid,
    containerId: typeof raw.containerId === 'string' && raw.containerId !== ''
      ? raw.containerId
      : 'quizgeist-app-edit',
    hostUrl: typeof raw.hostUrl === 'string' ? raw.hostUrl : '',
    initialView: raw.initialView === 'templates' ? 'templates' : 'editor',
    kahootImportUrl: typeof raw.kahootImportUrl === 'string' ? raw.kahootImportUrl : '',
    mediaUrl: typeof raw.mediaUrl === 'string' ? raw.mediaUrl : '',
    season: typeof raw.season === 'string' ? raw.season : 'herbst',
    sesskey,
    strings: raw.strings || {},
    theme: typeof raw.theme === 'string' ? raw.theme : 'hell',
    tts: {
      available: Boolean(raw.tts?.available && speakUrl && configuredVoices.length > 0),
      defaultVoiceId: Number(raw.tts?.defaultVoiceId || configuredVoices[0]?.id || 0),
      speakUrl,
      voices: configuredVoices,
    },
  };
}

export function init(raw: QuizgeistInitConfig = {}): void {
  if (raw.initialView === 'assignments'
      && raw.features?.selfstudy?.installed === true) {
    const assignmentConfig = normalizeSelfStudyConfig(raw, 'quizgeist-app-edit');
    const assignmentContainerId = assignmentConfig?.containerId
      || (typeof raw.containerId === 'string' ? raw.containerId : 'quizgeist-app-edit');
    const assignmentRoot = document.getElementById(assignmentContainerId);
    if (!assignmentRoot) {
      return;
    }
    if (!assignmentConfig) {
      const error = document.createElement('div');
      error.className = 'quizgeist-study-state quizgeist-study-state--error';
      error.setAttribute('role', 'alert');
      error.textContent = raw.strings?.['selfstudy:error:config']
        || raw.strings?.['editor:error:config']
        || 'Die Zuweisungsverwaltung konnte nicht gestartet werden.';
      assignmentRoot.replaceChildren(error);
      return;
    }
    mountAssignmentTeacherApp(assignmentRoot, assignmentConfig);
    return;
  }
  const config = normalizeConfig(raw);
  const containerId = config?.containerId
    || (typeof raw.containerId === 'string' ? raw.containerId : 'quizgeist-app-edit');
  const root = document.getElementById(containerId);
  if (!root) {
    return;
  }
  if (!config) {
    root.replaceChildren();
    const error = document.createElement('div');
    error.className = 'quizgeist-editor-error';
    error.setAttribute('role', 'alert');
    error.textContent = raw.strings?.['editor:error:config']
      || 'Der Editor konnte nicht gestartet werden.';
    root.append(error);
    return;
  }
  const app = new EditorApp(root, config);
  void app.init();
}
