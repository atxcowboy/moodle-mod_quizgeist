import {normaliseCompetences} from './competence-panel';
import type {
  LiveAggregate,
  LiveChoice,
  LiveDistributionEntry,
  LivePointMode,
  LiveQuestion,
  LiveQuestionType,
} from '../live/types';
import type {
  QuizgeistInitConfig,
  QuizgeistTtsConfig,
  QuizgeistTtsVoice,
} from '../types';
import {
  REPORT_SCOPES,
  type ReportBootstrap,
  type ReportConfig,
  type ReportData,
  type ReportGroup,
  type ReportHardestQuestion,
  type ReportModerationEntry,
  type ReportOpenResponse,
  type ReportParticipant,
  type ReportQuestion,
  type ReportQuestionOccurrence,
  type ReportQuestionVersion,
  type ReportScope,
  type ReportSelection,
  type ReportSource,
  type ReportSourceKind,
  type ReportSummary,
  type ReportTimelineEntry,
  type ReportViewerKind,
} from './types';

type JsonRecord = Record<string, unknown>;

const QUESTION_TYPES: readonly LiveQuestionType[] = [
  'quiz',
  'truefalse',
  'shortanswer',
  'puzzle',
  'poll',
  'wordcloud',
  'scale',
  'slider',
  'pin',
  'reveal',
  'brainstorm',
  'open',
  'slide',
];

function record(value: unknown): JsonRecord {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? value as JsonRecord
    : {};
}

function records(value: unknown): JsonRecord[] {
  return Array.isArray(value)
    ? value.map(record).filter((entry) => Object.keys(entry).length > 0)
    : [];
}

function unwrap(value: unknown, key: string): JsonRecord {
  const source = record(value);
  const wrapped = record(source[key]);
  return Object.keys(wrapped).length > 0 ? wrapped : source;
}

function text(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value.trim() : fallback;
}

function identifier(value: unknown, fallback = ''): string {
  if (typeof value === 'string') {
    return value.trim();
  }
  return typeof value === 'number' && Number.isFinite(value)
    ? String(value)
    : fallback;
}

function stableHexToken(value: string): string {
  const seeds = [
    0x811c9dc5,
    0x9e3779b9,
    0x85ebca6b,
    0xc2b2ae35,
  ];
  return seeds.map((seed, seedIndex) => {
    let hash = seed >>> 0;
    for (let index = 0; index < value.length; index += 1) {
      hash ^= value.charCodeAt(index) + seedIndex;
      hash = Math.imul(hash, 0x01000193) >>> 0;
    }
    return hash.toString(16).padStart(8, '0');
  }).join('');
}

function questionToken(value: unknown, fallback: string): string {
  const candidate = text(value).toLowerCase();
  return /^[a-f0-9]{32}$/.test(candidate)
    ? candidate
    : stableHexToken(fallback);
}

function finiteNumber(value: unknown, fallback = 0): number {
  const candidate = typeof value === 'number'
    ? value
    : typeof value === 'string' && value.trim() !== ''
      ? Number(value)
      : Number.NaN;
  return Number.isFinite(candidate) ? candidate : fallback;
}

function nullableNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === '') {
    return null;
  }
  const candidate = finiteNumber(value, Number.NaN);
  return Number.isFinite(candidate) ? candidate : null;
}

function integer(value: unknown, fallback = 0): number {
  const candidate = finiteNumber(value, fallback);
  return Number.isInteger(candidate) ? candidate : fallback;
}

function positiveInteger(value: unknown, fallback = 0): number {
  const candidate = integer(value, fallback);
  return candidate > 0 ? candidate : fallback;
}

function nonNegativeInteger(value: unknown, fallback = 0): number {
  return Math.max(0, integer(value, fallback));
}

function boolean(value: unknown, fallback = false): boolean {
  if (typeof value === 'boolean') {
    return value;
  }
  if (value === 1 || value === '1' || value === 'true') {
    return true;
  }
  if (value === 0 || value === '0' || value === 'false') {
    return false;
  }
  return fallback;
}

function milliseconds(value: unknown): number {
  const candidate = finiteNumber(value);
  if (candidate <= 0) {
    return 0;
  }
  return candidate < 100_000_000_000 ? candidate * 1000 : candidate;
}

function boundedPercent(value: unknown): number | null {
  const candidate = nullableNumber(value);
  return candidate === null
    ? null
    : Math.max(0, Math.min(100, candidate));
}

function duration(value: unknown): number | null {
  const candidate = nullableNumber(value);
  return candidate === null ? null : Math.max(0, candidate);
}

function scope(value: unknown, fallback: ReportScope = 'session'): ReportScope {
  return typeof value === 'string'
    && (REPORT_SCOPES as readonly string[]).includes(value)
    ? value as ReportScope
    : fallback;
}

function viewerKind(
  value: unknown,
  fallback: ReportViewerKind = 'student',
): ReportViewerKind {
  return value === 'teacher' || value === 'student' ? value : fallback;
}

function sourceKind(value: unknown): ReportSourceKind | null {
  return value === 'session' || value === 'assignment' ? value : null;
}

function qtype(value: unknown, fallback: LiveQuestionType = 'open'): LiveQuestionType {
  return typeof value === 'string'
    && (QUESTION_TYPES as readonly string[]).includes(value)
    ? value as LiveQuestionType
    : fallback;
}

function pointMode(value: unknown): LivePointMode {
  return value === 'double' || value === 'none' ? value : 'standard';
}

function stringArray(value: unknown): string[] {
  if (!Array.isArray(value)) {
    return [];
  }
  return [...new Set(value
    .map((entry) => text(entry))
    .filter((entry) => entry !== ''))];
}

function ttsVoice(value: unknown): QuizgeistTtsVoice | null {
  const raw = record(value);
  const id = positiveInteger(raw.id);
  const label = text(raw.label);
  if (id <= 0 || label === '') {
    return null;
  }
  return {
    id,
    label,
    ...(text(raw.gender) !== '' ? {gender: text(raw.gender)} : {}),
    ...(text(raw.lang) !== '' ? {lang: text(raw.lang)} : {}),
    ...(text(raw.region) !== '' ? {region: text(raw.region)} : {}),
  };
}

function ttsConfig(value: unknown): QuizgeistTtsConfig {
  const raw = record(value);
  const voices = (Array.isArray(raw.voices) ? raw.voices : [])
    .map(ttsVoice)
    .filter((voice): voice is QuizgeistTtsVoice => voice !== null);
  const speakUrl = text(raw.speakUrl);
  const defaultVoiceId = positiveInteger(raw.defaultVoiceId, voices[0]?.id || 0);
  return {
    available: boolean(raw.available) && speakUrl !== '' && voices.length > 0,
    defaultVoiceId: voices.some((voice) => voice.id === defaultVoiceId)
      ? defaultVoiceId
      : voices[0]?.id || 0,
    speakUrl,
    voices,
  };
}

function source(value: unknown): ReportSource | null {
  const raw = record(value);
  const kind = sourceKind(raw.kind);
  const id = positiveInteger(raw.id);
  if (!kind || id <= 0) {
    return null;
  }
  const key = text(raw.key, `${kind}:${id}`);
  if (!/^(session|assignment):[1-9][0-9]*$/.test(key)) {
    return null;
  }
  const label = text(raw.label, text(raw.name, key));
  return {
    cmid: positiveInteger(raw.cmid),
    endedAtMs: milliseconds(raw.endedAtMs ?? raw.endedAt),
    id,
    instanceName: text(raw.instanceName),
    key,
    kind,
    label,
    quizgeistId: positiveInteger(raw.quizgeistId ?? raw.instanceId),
    startedAtMs: milliseconds(raw.startedAtMs ?? raw.startedAt),
    status: text(raw.status),
  };
}

function group(value: unknown): ReportGroup | null {
  const raw = record(value);
  const id = integer(raw.id, -1);
  const name = text(raw.name, text(raw.label));
  return id >= 0 && name !== '' ? {id, name} : null;
}

function selection(
  value: unknown,
  fallback: ReportSelection = {
    groupId: 0,
    scope: 'session',
    sourceKeys: [],
  },
): ReportSelection {
  const raw = record(value);
  return {
    groupId: nonNegativeInteger(raw.groupId ?? raw.groupid, fallback.groupId),
    scope: scope(raw.scope, fallback.scope),
    sourceKeys: stringArray(raw.sourceKeys ?? raw.sourcekeys),
  };
}

export function normaliseReportConfig(
  raw: QuizgeistInitConfig,
): ReportConfig | null {
  const ajaxUrl = text(raw.ajaxUrl);
  const cmid = positiveInteger(raw.cmid);
  const sesskey = text(raw.sesskey);
  if (ajaxUrl === '' || cmid <= 0 || sesskey === '') {
    return null;
  }
  const documentLocale = text(document.documentElement.lang, 'de');
  const requestedLocale = text(raw.locale, documentLocale);
  const locale = /^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i.test(requestedLocale)
    ? requestedLocale
    : 'de';
  return {
    ajaxUrl,
    bootstrapAction: text(raw.bootstrapAction, 'report_bootstrap'),
    brandIconUrl: text(raw.brandIconUrl),
    cmid,
    containerId: text(raw.containerId, 'quizgeist-app-report'),
    dataAction: text(raw.dataAction, 'report_data'),
    exportUrl: text(raw.exportUrl),
    locale,
    reportsAddonInstalled: raw.features?.reports?.installed === true,
    selfstudyAddonInstalled: raw.features?.selfstudy?.installed === true,
    season: text(raw.season, 'herbst'),
    sesskey,
    strings: raw.strings && typeof raw.strings === 'object' ? raw.strings : {},
    theme: text(raw.theme, 'hell'),
    tts: ttsConfig(raw.tts),
    viewerKind: viewerKind(raw.viewerKind),
  };
}

export function normaliseReportBootstrap(
  value: unknown,
  fallbackViewerKind: ReportViewerKind,
): ReportBootstrap {
  const raw = unwrap(value, 'bootstrap');
  const rawViewer = record(raw.viewer);
  const sources = records(raw.sources)
    .map(source)
    .filter((entry): entry is ReportSource => entry !== null);
  const sourceKeys = new Set(sources.map((entry) => entry.key));
  const groups = records(raw.groups)
    .map(group)
    .filter((entry): entry is ReportGroup => entry !== null);
  const courseGroups = records(raw.courseGroups)
    .map(group)
    .filter((entry): entry is ReportGroup => entry !== null);
  const defaults = selection(raw.defaults ?? raw.selection);
  defaults.sourceKeys = defaults.sourceKeys.filter((key) => sourceKeys.has(key));
  return {
    courseCanSelectAllGroups: boolean(raw.courseCanSelectAllGroups),
    courseDefaultGroupId: nonNegativeInteger(raw.courseDefaultGroupId),
    courseGroups,
    defaults,
    groups,
    sources,
    viewer: {
      canExport: boolean(rawViewer.canExport),
      canViewOthers: boolean(
        rawViewer.canViewOthers,
        viewerKind(rawViewer.kind, fallbackViewerKind) === 'teacher',
      ),
      canViewProfiles: boolean(
        rawViewer.canProfileLinks,
      ),
      kind: viewerKind(rawViewer.kind, fallbackViewerKind),
    },
  };
}

function choice(value: unknown, index: number): LiveChoice {
  const raw = record(value);
  return {
    ...(typeof raw.correct === 'boolean' ? {correct: raw.correct} : {}),
    id: text(raw.id, String(index + 1)),
    ...(text(raw.mediaMimeType) !== ''
      ? {mediaMimeType: text(raw.mediaMimeType) as LiveChoice['mediaMimeType']}
      : {}),
    ...(text(raw.mediaUrl) !== '' ? {mediaUrl: text(raw.mediaUrl)} : {}),
    text: text(raw.text, text(raw.label)),
  };
}

function liveQuestion(
  value: unknown,
  fallback: {
    id: number;
    qtype: LiveQuestionType;
    questionText: string;
    rootId: number;
    sourceKey: string;
    version: number;
    visit: string;
  },
): LiveQuestion {
  const raw = record(value);
  const id = positiveInteger(raw.id, fallback.id);
  const type = qtype(raw.qtype, fallback.qtype);
  const typeData = record(raw.typeData ?? raw.typedata);
  return {
    allowsMultipleSubmissions: boolean(raw.allowsMultipleSubmissions),
    choices: (Array.isArray(raw.choices) ? raw.choices : [])
      .map((entry, index) => choice(entry, index)),
    id,
    index: nonNegativeInteger(raw.index),
    interactionStage: text(raw.interactionStage) || undefined,
    mediaMimeType: text(raw.mediaMimeType) !== ''
      ? text(raw.mediaMimeType) as LiveQuestion['mediaMimeType']
      : null,
    mediaUrl: text(raw.mediaUrl) || null,
    multiple: boolean(raw.multiple),
    pointMode: pointMode(raw.pointMode),
    policyDescriptor: Object.keys(record(raw.policyDescriptor)).length > 0
      ? record(raw.policyDescriptor) as unknown as LiveQuestion['policyDescriptor']
      : undefined,
    questionText: text(
      raw.questionText ?? raw.questiontext,
      fallback.questionText,
    ),
    questionToken: questionToken(
      raw.questionToken,
      `report:${fallback.sourceKey}:${id}:${fallback.version}:${fallback.visit}`,
    ),
    qtype: type,
    responseType: text(raw.responseType) || undefined,
    rootId: positiveInteger(raw.rootId ?? raw.rootid, fallback.rootId),
    speechText: text(raw.speechText) || undefined,
    timeLimit: nonNegativeInteger(raw.timeLimit),
    total: positiveInteger(raw.total, 1),
    typeData,
    version: positiveInteger(raw.version, fallback.version),
  };
}

function aggregate(
  value: unknown,
): LiveAggregate | LiveDistributionEntry[] | null {
  if (Array.isArray(value)) {
    return value
      .map(record)
      .filter((entry) => Object.keys(entry).length > 0)
      .map((entry) => ({
        ...entry,
        choiceId: text(entry.choiceId ?? entry.id),
        count: nonNegativeInteger(entry.count),
        percent: Math.max(0, Math.min(100, finiteNumber(entry.percent))),
      })) as LiveDistributionEntry[];
  }
  const raw = record(value);
  return Object.keys(raw).length > 0 ? raw as LiveAggregate : null;
}

function version(
  value: unknown,
  fallbackType: LiveQuestionType,
  fallbackText: string,
): ReportQuestionVersion | null {
  const raw = record(value);
  const questionId = positiveInteger(raw.questionId ?? raw.id);
  if (questionId <= 0) {
    return null;
  }
  return {
    questionId,
    questionText: text(
      raw.questionText ?? raw.questiontext,
      fallbackText,
    ),
    qtype: qtype(raw.qtype, fallbackType),
    version: positiveInteger(raw.version, 1),
  };
}

function occurrence(
  value: unknown,
  parent: {
    qtype: LiveQuestionType;
    rootId: number;
    title: string;
    versions: ReportQuestionVersion[];
  },
): ReportQuestionOccurrence | null {
  const raw = record(value);
  const questionId = positiveInteger(
    raw.questionId,
    positiveInteger(record(raw.question).id),
  );
  if (questionId <= 0) {
    return null;
  }
  const knownVersion = parent.versions.find(
    (entry) => entry.questionId === questionId,
  );
  const sourceKey = text(raw.sourceKey ?? raw.sourcekey);
  const versionNumber = positiveInteger(
    raw.version,
    knownVersion?.version || positiveInteger(record(raw.question).version, 1),
  );
  const projectionKey = identifier(raw.projectionKey);
  const presented = liveQuestion(raw.question ?? knownVersion, {
    id: questionId,
    qtype: knownVersion?.qtype || parent.qtype,
    questionText: knownVersion?.questionText || parent.title,
    rootId: parent.rootId,
    sourceKey,
    version: versionNumber,
    visit: projectionKey,
  });
  return {
    aggregate: aggregate(raw.aggregate ?? raw.distribution),
    authoritative: boolean(raw.authoritative, true),
    occurrenceCount: positiveInteger(
      raw.occurrenceCount,
      1,
    ),
    projectionKey,
    question: presented,
    questionId,
    sourceKey,
    sourceLabel: text(raw.sourceLabel ?? raw.sourcelabel, sourceKey),
    stage: text(raw.stage),
    version: versionNumber,
  };
}

function reportQuestion(value: unknown): ReportQuestion | null {
  const raw = record(value);
  const rootId = positiveInteger(raw.rootId ?? raw.rootid);
  const rootKey = text(raw.rootKey, rootId > 0 ? String(rootId) : '');
  if (rootId <= 0 || rootKey === '') {
    return null;
  }
  const rawQtypes = Array.isArray(raw.qtypes) ? raw.qtypes : [];
  const type = qtype(raw.qtype ?? rawQtypes[0]);
  const title = text(raw.title, text(raw.questionText, `Frage ${rootId}`));
  const versions = records(raw.versions)
    .map((entry) => version(entry, type, title))
    .filter((entry): entry is ReportQuestionVersion => entry !== null);
  const rawOccurrences = Array.isArray(raw.occurrences)
    ? raw.occurrences
    : raw.distributions;
  const occurrences = records(rawOccurrences)
    .map((entry) => occurrence(entry, {
      qtype: type,
      rootId,
      title,
      versions,
    }))
    .filter((entry): entry is ReportQuestionOccurrence => entry !== null);
  occurrences.forEach((entry) => {
    if (!versions.some((candidate) => (
      candidate.questionId === entry.questionId
      && candidate.version === entry.version
    ))) {
      versions.push({
        questionId: entry.questionId,
        questionText: entry.question.questionText,
        qtype: entry.question.qtype,
        version: entry.version,
      });
    }
  });
  const gradedCount = nonNegativeInteger(raw.gradedCount ?? raw.gradableCount);
  const correctCount = Math.min(
    gradedCount || Number.MAX_SAFE_INTEGER,
    nonNegativeInteger(raw.correctCount),
  );
  const rate = boundedPercent(raw.correctRate ?? raw.correctPercent)
    ?? (gradedCount > 0 ? (correctCount / gradedCount) * 100 : null);
  return {
    averageResponseTimeMs: duration(raw.averageResponseTimeMs),
    correctCount,
    correctRate: rate,
    // The pedagogical difficulty marker belongs to the server and to nobody
    // else. `report_metrics::finish_questions()` applies both halves of the
    // rule - the site's evidence floor `report_difficult_min_sample` and the
    // site's `report_difficult_threshold` - and `report_service::
    // apply_feature_visibility()` clears the flag when the reports addon is
    // not installed. Recomputing it here silently ignored both settings, put
    // the badge on questions with too thin a sample and made the screen
    // disagree with the very same report's DTO and XLSX export.
    difficult: raw.difficult === true,
    gradedCount,
    maxPoints: Math.max(0, finiteNumber(raw.maxPoints)),
    missingCount: nonNegativeInteger(raw.missingCount),
    quizgeistId: positiveInteger(raw.quizgeistId),
    occurrences,
    points: Math.max(0, finiteNumber(raw.points)),
    qtype: type,
    responseCount: nonNegativeInteger(raw.responseCount),
    rootId,
    rootKey,
    title,
    versions: versions
      .filter((entry, index, all) => all.findIndex((candidate) => (
        candidate.questionId === entry.questionId
        && candidate.version === entry.version
      )) === index)
      .sort((left, right) => left.version - right.version),
  };
}

function participant(value: unknown): ReportParticipant | null {
  const raw = record(value);
  const displayName = text(raw.displayName ?? raw.name);
  if (displayName === '') {
    return null;
  }
  const gradedCount = nonNegativeInteger(raw.gradedCount ?? raw.gradableCount);
  const correctCount = Math.min(
    gradedCount || Number.MAX_SAFE_INTEGER,
    nonNegativeInteger(raw.correctCount),
  );
  const rate = boundedPercent(raw.correctRate ?? raw.correctPercent)
    ?? (gradedCount > 0 ? (correctCount / gradedCount) * 100 : null);
  const profileUrl = text(raw.profileUrl);
  const userId = positiveInteger(raw.userId ?? raw.userid ?? raw.id);
  return {
    attemptCount: nonNegativeInteger(raw.attemptCount),
    averageResponseTimeMs: duration(raw.averageResponseTimeMs),
    correctCount,
    correctRate: rate,
    displayName,
    gradedCount,
    maxPoints: Math.max(0, finiteNumber(raw.maxPoints)),
    points: Math.max(0, finiteNumber(raw.points)),
    ...(profileUrl !== '' ? {profileUrl} : {}),
    responseCount: nonNegativeInteger(raw.responseCount),
    sourceCount: nonNegativeInteger(raw.sourceCount),
    userId,
    userIdentifier: text(
      raw.userIdentifier ?? raw.idnumber ?? raw.username,
      userId > 0 ? `#${userId}` : '',
    ),
  };
}

function openResponse(value: unknown): ReportOpenResponse | null {
  const raw = record(value);
  const responseText = text(raw.text ?? raw.response ?? raw.answer);
  const id = identifier(raw.id, identifier(raw.responseId));
  if (id === '' && responseText === '') {
    return null;
  }
  return {
    authoritative: boolean(raw.authoritative, true),
    displayName: text(raw.displayName ?? raw.authorName),
    id: id || `${text(raw.sourceKey)}:${positiveInteger(raw.questionId)}:${positiveInteger(raw.userId)}`,
    kind: text(raw.kind ?? raw.qtype, 'open'),
    metricEligible: boolean(raw.metricEligible, true),
    questionId: positiveInteger(raw.questionId),
    questionTitle: text(raw.questionTitle ?? raw.title),
    rootId: positiveInteger(raw.rootId ?? raw.rootid),
    rootKey: text(raw.rootKey),
    sourceKey: text(raw.sourceKey),
    sourceLabel: text(raw.sourceLabel, text(raw.sourceKey)),
    status: text(raw.status, 'recorded'),
    text: responseText,
    timeCreatedMs: milliseconds(raw.timeCreatedMs ?? raw.timeCreated),
    userId: positiveInteger(raw.userId ?? raw.userid),
    userIdentifier: text(
      raw.userIdentifier ?? raw.idnumber ?? raw.username,
    ),
    version: positiveInteger(raw.version, 1),
  };
}

function moderationEntry(value: unknown): ReportModerationEntry | null {
  const raw = record(value);
  const action = text(raw.action ?? raw.decision ?? raw.status);
  const id = identifier(raw.id);
  if (id === '' && action === '') {
    return null;
  }
  return {
    action: action || 'recorded',
    actorName: text(raw.actorName ?? raw.displayName),
    id: id || `${text(raw.sourceKey)}:${positiveInteger(raw.questionId)}:${integer(raw.timeCreated)}`,
    questionId: positiveInteger(raw.questionId),
    rootId: positiveInteger(raw.rootId ?? raw.rootid),
    rootKey: text(raw.rootKey),
    sourceKey: text(raw.sourceKey),
    sourceLabel: text(raw.sourceLabel, text(raw.sourceKey)),
    targetKey: text(raw.targetKey),
    targetType: text(raw.targetType),
    timeCreatedMs: milliseconds(raw.timeCreatedMs ?? raw.timeCreated),
    version: positiveInteger(raw.version, 1),
  };
}

function hardestQuestion(
  value: unknown,
  questions: ReportQuestion[],
): ReportHardestQuestion | null {
  const raw = record(value);
  if (Object.keys(raw).length > 0) {
    const rootId = positiveInteger(raw.rootId ?? raw.rootid);
    const rootKey = text(raw.rootKey, rootId > 0 ? String(rootId) : '');
    return {
      correctRate: boundedPercent(raw.correctRate ?? raw.correctPercent),
      rootId,
      rootKey,
      title: text(raw.title, `Frage ${rootId}`),
    };
  }
  const key = text(value);
  if (key !== '') {
    const match = questions.find((question) => (
      question.rootKey === key || String(question.rootId) === key
    ));
    if (match) {
      return {
        correctRate: match.correctRate,
        rootId: match.rootId,
        rootKey: match.rootKey,
        title: match.title,
      };
    }
  }
  const graded = questions
    .filter((question) => question.gradedCount > 0 && question.correctRate !== null)
    .sort((left, right) => (
      (left.correctRate || 0) - (right.correctRate || 0)
    ));
  const match = graded[0];
  return match ? {
    correctRate: match.correctRate,
    rootId: match.rootId,
    rootKey: match.rootKey,
    title: match.title,
  } : null;
}

function summary(
  value: unknown,
  questions: ReportQuestion[],
): ReportSummary {
  const raw = record(value);
  const participating = nonNegativeInteger(
    raw.participating ?? raw.participantCount,
  );
  const eligible = nonNegativeInteger(raw.eligible ?? raw.eligibleCount);
  const participationRate = boundedPercent(
    raw.participationRate ?? raw.participationPercent,
  ) ?? (eligible > 0 ? (participating / eligible) * 100 : null);
  return {
    averageCorrectRate: boundedPercent(
      raw.averageCorrectRate ?? raw.averageCorrectPercent,
    ),
    eligible,
    hardestQuestion: hardestQuestion(
      raw.hardestQuestion ?? raw.hardestRootKey,
      questions,
    ),
    participating,
    participationRate,
    pointsPercent: boundedPercent(raw.pointsPercent ?? raw.averagePoints),
  };
}

function timelineEntry(value: unknown): ReportTimelineEntry | null {
  const raw = record(value);
  const sourceKey = text(raw.sourceKey ?? raw.sourcekey);
  const kind = sourceKind(raw.kind)
    ?? sourceKind(sourceKey.split(':', 1)[0]);
  if (sourceKey === '' || kind === null) {
    return null;
  }
  return {
    averageCorrectRate: boundedPercent(
      raw.averageCorrectRate ?? raw.averageCorrectPercent,
    ),
    instanceName: text(raw.instanceName),
    kind,
    quizgeistId: positiveInteger(raw.quizgeistId ?? raw.instanceId),
    participantCount: nonNegativeInteger(raw.participantCount),
    pointsPercent: boundedPercent(raw.pointsPercent ?? raw.averagePoints),
    sourceKey,
    sourceLabel: text(raw.sourceLabel ?? raw.sourcelabel, sourceKey),
    timestampMs: milliseconds(raw.timestampMs ?? raw.timestamp),
  };
}

export function normaliseReportData(
  value: unknown,
  fallbackSelection: ReportSelection,
): ReportData {
  const raw = unwrap(value, 'report');
  const questions = records(raw.questions)
    .map(reportQuestion)
    .filter((entry): entry is ReportQuestion => entry !== null);
  const reportSelection = selection(raw.selection, fallbackSelection);
  return {
    // F6: ohne reports-Addon liefert der Server gar keine Kompetenzachse.
    // Die leere Liste ist deshalb kein Fehler, sondern der Sollzustand.
    competences: normaliseCompetences(raw.competences),
    moderationTrailTruncated: boolean(raw.moderationTrailTruncated),
    moderationTrail: records(raw.moderationTrail)
      .map(moderationEntry)
      .filter((entry): entry is ReportModerationEntry => entry !== null),
    omittedSources: records(raw.omittedSources).map((entry) => ({
      cmid: positiveInteger(entry.cmid),
      instanceName: text(entry.instanceName),
      reason: text(entry.reason),
    })),
    openResponsesTruncated: boolean(raw.openResponsesTruncated),
    openResponses: records(raw.openResponses)
      .map(openResponse)
      .filter((entry): entry is ReportOpenResponse => entry !== null),
    participants: records(raw.participants ?? raw.students)
      .map(participant)
      .filter((entry): entry is ReportParticipant => entry !== null),
    questions,
    selection: reportSelection,
    subtitle: text(raw.subtitle),
    summary: summary(raw.summary ?? raw.kpis, questions),
    timeline: records(raw.timeline)
      .map(timelineEntry)
      .filter((entry): entry is ReportTimelineEntry => entry !== null)
      .sort((left, right) => (
        left.timestampMs - right.timestampMs
        || left.sourceLabel.localeCompare(right.sourceLabel)
        || left.sourceKey.localeCompare(right.sourceKey)
      )),
    title: text(raw.title),
  };
}
