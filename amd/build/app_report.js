"use strict";
var QuizgeistReportApp = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __defNormalProp = (obj, key, value2) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value: value2 }) : obj[key] = value2;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);
  var __publicField = (obj, key, value2) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value2);

  // src/app_report.ts
  var app_report_exports = {};
  __export(app_report_exports, {
    init: () => init
  });

  // src/live/dom.ts
  function liveElement(tagName, className = "", attributes = {}) {
    const node = document.createElement(tagName);
    if (className !== "") {
      node.className = className;
    }
    for (const [name, value2] of Object.entries(attributes)) {
      if (value2 === void 0 || value2 === null || value2 === false) {
        continue;
      }
      if (name === "text") {
        node.textContent = String(value2);
      } else if (name === "checked" && node instanceof HTMLInputElement) {
        node.checked = Boolean(value2);
      } else if (name === "disabled" && "disabled" in node) {
        node.disabled = Boolean(value2);
      } else if (name === "selected" && node instanceof HTMLOptionElement) {
        node.selected = Boolean(value2);
      } else if (name === "value" && "value" in node) {
        node.value = String(value2);
      } else if (value2 === true) {
        node.setAttribute(name, "");
      } else {
        node.setAttribute(name, String(value2));
      }
    }
    return node;
  }
  function liveButton(label, className = "", attributes = {}) {
    return liveElement("button", className, {
      type: "button",
      text: label,
      ...attributes
    });
  }

  // src/report/competence-panel.ts
  function normaliseCompetence(raw) {
    if (raw === null || typeof raw !== "object") {
      return null;
    }
    const record2 = raw;
    const key = typeof record2.key === "string" ? record2.key : "";
    const label = typeof record2.label === "string" ? record2.label : "";
    if (key === "" || label === "") {
      return null;
    }
    const percent2 = (value2) => typeof value2 === "number" && Number.isFinite(value2) ? Math.max(0, Math.min(100, value2)) : null;
    const count2 = (value2) => typeof value2 === "number" && Number.isFinite(value2) && value2 > 0 ? Math.floor(value2) : 0;
    return {
      color: typeof record2.color === "string" && record2.color !== "" ? record2.color : null,
      correctPercent: percent2(record2.correctPercent),
      key,
      label,
      pointsPercent: percent2(record2.pointsPercent),
      questionCount: count2(record2.questionCount),
      sample: count2(record2.sample)
    };
  }
  function normaliseCompetences(raw) {
    if (!Array.isArray(raw)) {
      return [];
    }
    return raw.map(normaliseCompetence).filter((entry) => entry !== null);
  }
  function renderCompetencePanel(competences, text3) {
    if (competences.length === 0) {
      return null;
    }
    const section = liveElement("section", "quizgeist-report-section");
    section.setAttribute("aria-labelledby", "quizgeist-report-competences");
    const heading = liveElement("h3", "quizgeist-report-section__title", {
      text: text3("report:competence:title", "Kompetenzbereiche")
    });
    heading.id = "quizgeist-report-competences";
    const hint = liveElement("p", "quizgeist-report-filter-hint", {
      text: text3(
        "report:competence:hint",
        "Der schw\xE4chste Bereich steht oben. Die Farbe wiederholt nur, was Beschriftung und Zahl bereits sagen."
      )
    });
    const list = liveElement("ul", "quizgeist-competence-list");
    competences.forEach((competence) => {
      list.append(renderCompetenceRow(competence, text3));
    });
    section.append(heading, hint, list);
    return section;
  }
  function renderCompetenceRow(competence, text3) {
    const item = liveElement("li", "quizgeist-competence-item");
    if (competence.color !== null) {
      item.dataset.competenceColor = competence.color;
    }
    const label = liveElement("span", "quizgeist-competence-label", {
      text: competence.label
    });
    const measured = competence.correctPercent !== null;
    const figure = liveElement("span", "quizgeist-competence-figure", {
      text: measured ? text3(
        "report:competence:value",
        "{$percent} % richtig aus {$sample} Antworten",
        {
          percent: formatPercent(competence.correctPercent),
          sample: competence.sample
        }
      ) : text3(
        "report:competence:nosample",
        "Noch keine bewertete Antwort in diesem Bereich"
      )
    });
    const bar = liveElement("span", "quizgeist-competence-bar");
    bar.setAttribute("role", "img");
    bar.setAttribute(
      "aria-label",
      text3(
        "report:competence:barlabel",
        "{$label}: {$figure}",
        { figure: figure.textContent || "", label: competence.label }
      )
    );
    const fill = liveElement("span", "quizgeist-competence-fill");
    fill.style.width = `${measured ? Math.round(competence.correctPercent) : 0}%`;
    if (competence.color !== null) {
      fill.dataset.competenceColor = competence.color;
    }
    fill.dataset.competenceState = measured ? competence.correctPercent < 50 ? "weak" : competence.correctPercent < 80 ? "medium" : "strong" : "unknown";
    bar.append(fill);
    const meta = liveElement("span", "quizgeist-competence-meta", {
      text: text3(
        "report:competence:questions",
        "{$count} Fragen",
        { count: competence.questionCount }
      )
    });
    item.append(label, figure, bar, meta);
    return item;
  }
  function formatPercent(value2) {
    return Number.isInteger(value2) ? String(value2) : String(Math.round(value2 * 10) / 10);
  }

  // src/report/types.ts
  var REPORT_SCOPES = ["session", "combined", "course"];

  // src/report/normalise.ts
  var QUESTION_TYPES = [
    "quiz",
    "truefalse",
    "shortanswer",
    "puzzle",
    "poll",
    "wordcloud",
    "scale",
    "slider",
    "pin",
    "reveal",
    "brainstorm",
    "open",
    "slide"
  ];
  function record(value2) {
    return value2 && typeof value2 === "object" && !Array.isArray(value2) ? value2 : {};
  }
  function records(value2) {
    return Array.isArray(value2) ? value2.map(record).filter((entry) => Object.keys(entry).length > 0) : [];
  }
  function unwrap(value2, key) {
    const source2 = record(value2);
    const wrapped = record(source2[key]);
    return Object.keys(wrapped).length > 0 ? wrapped : source2;
  }
  function text(value2, fallback = "") {
    return typeof value2 === "string" ? value2.trim() : fallback;
  }
  function identifier(value2, fallback = "") {
    if (typeof value2 === "string") {
      return value2.trim();
    }
    return typeof value2 === "number" && Number.isFinite(value2) ? String(value2) : fallback;
  }
  function stableHexToken(value2) {
    const seeds = [
      2166136261,
      2654435769,
      2246822507,
      3266489909
    ];
    return seeds.map((seed, seedIndex) => {
      let hash = seed >>> 0;
      for (let index = 0; index < value2.length; index += 1) {
        hash ^= value2.charCodeAt(index) + seedIndex;
        hash = Math.imul(hash, 16777619) >>> 0;
      }
      return hash.toString(16).padStart(8, "0");
    }).join("");
  }
  function questionToken(value2, fallback) {
    const candidate = text(value2).toLowerCase();
    return /^[a-f0-9]{32}$/.test(candidate) ? candidate : stableHexToken(fallback);
  }
  function finiteNumber(value2, fallback = 0) {
    const candidate = typeof value2 === "number" ? value2 : typeof value2 === "string" && value2.trim() !== "" ? Number(value2) : Number.NaN;
    return Number.isFinite(candidate) ? candidate : fallback;
  }
  function nullableNumber(value2) {
    if (value2 === null || value2 === void 0 || value2 === "") {
      return null;
    }
    const candidate = finiteNumber(value2, Number.NaN);
    return Number.isFinite(candidate) ? candidate : null;
  }
  function integer(value2, fallback = 0) {
    const candidate = finiteNumber(value2, fallback);
    return Number.isInteger(candidate) ? candidate : fallback;
  }
  function positiveInteger(value2, fallback = 0) {
    const candidate = integer(value2, fallback);
    return candidate > 0 ? candidate : fallback;
  }
  function nonNegativeInteger(value2, fallback = 0) {
    return Math.max(0, integer(value2, fallback));
  }
  function boolean(value2, fallback = false) {
    if (typeof value2 === "boolean") {
      return value2;
    }
    if (value2 === 1 || value2 === "1" || value2 === "true") {
      return true;
    }
    if (value2 === 0 || value2 === "0" || value2 === "false") {
      return false;
    }
    return fallback;
  }
  function milliseconds(value2) {
    const candidate = finiteNumber(value2);
    if (candidate <= 0) {
      return 0;
    }
    return candidate < 1e11 ? candidate * 1e3 : candidate;
  }
  function boundedPercent(value2) {
    const candidate = nullableNumber(value2);
    return candidate === null ? null : Math.max(0, Math.min(100, candidate));
  }
  function duration(value2) {
    const candidate = nullableNumber(value2);
    return candidate === null ? null : Math.max(0, candidate);
  }
  function scope(value2, fallback = "session") {
    return typeof value2 === "string" && REPORT_SCOPES.includes(value2) ? value2 : fallback;
  }
  function viewerKind(value2, fallback = "student") {
    return value2 === "teacher" || value2 === "student" ? value2 : fallback;
  }
  function sourceKind(value2) {
    return value2 === "session" || value2 === "assignment" ? value2 : null;
  }
  function qtype(value2, fallback = "open") {
    return typeof value2 === "string" && QUESTION_TYPES.includes(value2) ? value2 : fallback;
  }
  function pointMode(value2) {
    return value2 === "double" || value2 === "none" ? value2 : "standard";
  }
  function stringArray(value2) {
    if (!Array.isArray(value2)) {
      return [];
    }
    return [...new Set(value2.map((entry) => text(entry)).filter((entry) => entry !== ""))];
  }
  function ttsVoice(value2) {
    const raw = record(value2);
    const id = positiveInteger(raw.id);
    const label = text(raw.label);
    if (id <= 0 || label === "") {
      return null;
    }
    return {
      id,
      label,
      ...text(raw.gender) !== "" ? { gender: text(raw.gender) } : {},
      ...text(raw.lang) !== "" ? { lang: text(raw.lang) } : {},
      ...text(raw.region) !== "" ? { region: text(raw.region) } : {}
    };
  }
  function ttsConfig(value2) {
    var _a, _b;
    const raw = record(value2);
    const voices = (Array.isArray(raw.voices) ? raw.voices : []).map(ttsVoice).filter((voice) => voice !== null);
    const speakUrl = text(raw.speakUrl);
    const defaultVoiceId = positiveInteger(raw.defaultVoiceId, ((_a = voices[0]) == null ? void 0 : _a.id) || 0);
    return {
      available: boolean(raw.available) && speakUrl !== "" && voices.length > 0,
      defaultVoiceId: voices.some((voice) => voice.id === defaultVoiceId) ? defaultVoiceId : ((_b = voices[0]) == null ? void 0 : _b.id) || 0,
      speakUrl,
      voices
    };
  }
  function source(value2) {
    var _a, _b, _c;
    const raw = record(value2);
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
      endedAtMs: milliseconds((_a = raw.endedAtMs) != null ? _a : raw.endedAt),
      id,
      instanceName: text(raw.instanceName),
      key,
      kind,
      label,
      quizgeistId: positiveInteger((_b = raw.quizgeistId) != null ? _b : raw.instanceId),
      startedAtMs: milliseconds((_c = raw.startedAtMs) != null ? _c : raw.startedAt),
      status: text(raw.status)
    };
  }
  function group(value2) {
    const raw = record(value2);
    const id = integer(raw.id, -1);
    const name = text(raw.name, text(raw.label));
    return id >= 0 && name !== "" ? { id, name } : null;
  }
  function selection(value2, fallback = {
    groupId: 0,
    scope: "session",
    sourceKeys: []
  }) {
    var _a, _b;
    const raw = record(value2);
    return {
      groupId: nonNegativeInteger((_a = raw.groupId) != null ? _a : raw.groupid, fallback.groupId),
      scope: scope(raw.scope, fallback.scope),
      sourceKeys: stringArray((_b = raw.sourceKeys) != null ? _b : raw.sourcekeys)
    };
  }
  function normaliseReportConfig(raw) {
    var _a, _b, _c, _d;
    const ajaxUrl = text(raw.ajaxUrl);
    const cmid = positiveInteger(raw.cmid);
    const sesskey = text(raw.sesskey);
    if (ajaxUrl === "" || cmid <= 0 || sesskey === "") {
      return null;
    }
    const documentLocale = text(document.documentElement.lang, "de");
    const requestedLocale = text(raw.locale, documentLocale);
    const locale = /^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i.test(requestedLocale) ? requestedLocale : "de";
    return {
      ajaxUrl,
      bootstrapAction: text(raw.bootstrapAction, "report_bootstrap"),
      brandIconUrl: text(raw.brandIconUrl),
      cmid,
      containerId: text(raw.containerId, "quizgeist-app-report"),
      dataAction: text(raw.dataAction, "report_data"),
      exportUrl: text(raw.exportUrl),
      locale,
      reportsAddonInstalled: ((_b = (_a = raw.features) == null ? void 0 : _a.reports) == null ? void 0 : _b.installed) === true,
      selfstudyAddonInstalled: ((_d = (_c = raw.features) == null ? void 0 : _c.selfstudy) == null ? void 0 : _d.installed) === true,
      season: text(raw.season, "herbst"),
      sesskey,
      strings: raw.strings && typeof raw.strings === "object" ? raw.strings : {},
      theme: text(raw.theme, "hell"),
      tts: ttsConfig(raw.tts),
      viewerKind: viewerKind(raw.viewerKind)
    };
  }
  function normaliseReportBootstrap(value2, fallbackViewerKind) {
    var _a;
    const raw = unwrap(value2, "bootstrap");
    const rawViewer = record(raw.viewer);
    const sources = records(raw.sources).map(source).filter((entry) => entry !== null);
    const sourceKeys = new Set(sources.map((entry) => entry.key));
    const groups = records(raw.groups).map(group).filter((entry) => entry !== null);
    const courseGroups = records(raw.courseGroups).map(group).filter((entry) => entry !== null);
    const defaults = selection((_a = raw.defaults) != null ? _a : raw.selection);
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
          viewerKind(rawViewer.kind, fallbackViewerKind) === "teacher"
        ),
        canViewProfiles: boolean(
          rawViewer.canProfileLinks
        ),
        kind: viewerKind(rawViewer.kind, fallbackViewerKind)
      }
    };
  }
  function choice(value2, index) {
    const raw = record(value2);
    return {
      ...typeof raw.correct === "boolean" ? { correct: raw.correct } : {},
      id: text(raw.id, String(index + 1)),
      ...text(raw.mediaMimeType) !== "" ? { mediaMimeType: text(raw.mediaMimeType) } : {},
      ...text(raw.mediaUrl) !== "" ? { mediaUrl: text(raw.mediaUrl) } : {},
      text: text(raw.text, text(raw.label))
    };
  }
  function liveQuestion(value2, fallback) {
    var _a, _b, _c;
    const raw = record(value2);
    const id = positiveInteger(raw.id, fallback.id);
    const type = qtype(raw.qtype, fallback.qtype);
    const typeData = record((_a = raw.typeData) != null ? _a : raw.typedata);
    return {
      allowsMultipleSubmissions: boolean(raw.allowsMultipleSubmissions),
      choices: (Array.isArray(raw.choices) ? raw.choices : []).map((entry, index) => choice(entry, index)),
      id,
      index: nonNegativeInteger(raw.index),
      interactionStage: text(raw.interactionStage) || void 0,
      mediaMimeType: text(raw.mediaMimeType) !== "" ? text(raw.mediaMimeType) : null,
      mediaUrl: text(raw.mediaUrl) || null,
      multiple: boolean(raw.multiple),
      pointMode: pointMode(raw.pointMode),
      policyDescriptor: Object.keys(record(raw.policyDescriptor)).length > 0 ? record(raw.policyDescriptor) : void 0,
      questionText: text(
        (_b = raw.questionText) != null ? _b : raw.questiontext,
        fallback.questionText
      ),
      questionToken: questionToken(
        raw.questionToken,
        `report:${fallback.sourceKey}:${id}:${fallback.version}:${fallback.visit}`
      ),
      qtype: type,
      responseType: text(raw.responseType) || void 0,
      rootId: positiveInteger((_c = raw.rootId) != null ? _c : raw.rootid, fallback.rootId),
      speechText: text(raw.speechText) || void 0,
      timeLimit: nonNegativeInteger(raw.timeLimit),
      total: positiveInteger(raw.total, 1),
      typeData,
      version: positiveInteger(raw.version, fallback.version)
    };
  }
  function aggregate(value2) {
    if (Array.isArray(value2)) {
      return value2.map(record).filter((entry) => Object.keys(entry).length > 0).map((entry) => {
        var _a;
        return {
          ...entry,
          choiceId: text((_a = entry.choiceId) != null ? _a : entry.id),
          count: nonNegativeInteger(entry.count),
          percent: Math.max(0, Math.min(100, finiteNumber(entry.percent)))
        };
      });
    }
    const raw = record(value2);
    return Object.keys(raw).length > 0 ? raw : null;
  }
  function version(value2, fallbackType, fallbackText) {
    var _a, _b;
    const raw = record(value2);
    const questionId = positiveInteger((_a = raw.questionId) != null ? _a : raw.id);
    if (questionId <= 0) {
      return null;
    }
    return {
      questionId,
      questionText: text(
        (_b = raw.questionText) != null ? _b : raw.questiontext,
        fallbackText
      ),
      qtype: qtype(raw.qtype, fallbackType),
      version: positiveInteger(raw.version, 1)
    };
  }
  function occurrence(value2, parent) {
    var _a, _b, _c, _d;
    const raw = record(value2);
    const questionId = positiveInteger(
      raw.questionId,
      positiveInteger(record(raw.question).id)
    );
    if (questionId <= 0) {
      return null;
    }
    const knownVersion = parent.versions.find(
      (entry) => entry.questionId === questionId
    );
    const sourceKey = text((_a = raw.sourceKey) != null ? _a : raw.sourcekey);
    const versionNumber = positiveInteger(
      raw.version,
      (knownVersion == null ? void 0 : knownVersion.version) || positiveInteger(record(raw.question).version, 1)
    );
    const projectionKey = identifier(raw.projectionKey);
    const presented = liveQuestion((_b = raw.question) != null ? _b : knownVersion, {
      id: questionId,
      qtype: (knownVersion == null ? void 0 : knownVersion.qtype) || parent.qtype,
      questionText: (knownVersion == null ? void 0 : knownVersion.questionText) || parent.title,
      rootId: parent.rootId,
      sourceKey,
      version: versionNumber,
      visit: projectionKey
    });
    return {
      aggregate: aggregate((_c = raw.aggregate) != null ? _c : raw.distribution),
      authoritative: boolean(raw.authoritative, true),
      occurrenceCount: positiveInteger(
        raw.occurrenceCount,
        1
      ),
      projectionKey,
      question: presented,
      questionId,
      sourceKey,
      sourceLabel: text((_d = raw.sourceLabel) != null ? _d : raw.sourcelabel, sourceKey),
      stage: text(raw.stage),
      version: versionNumber
    };
  }
  function reportQuestion(value2) {
    var _a, _b, _c, _d, _e;
    const raw = record(value2);
    const rootId = positiveInteger((_a = raw.rootId) != null ? _a : raw.rootid);
    const rootKey = text(raw.rootKey, rootId > 0 ? String(rootId) : "");
    if (rootId <= 0 || rootKey === "") {
      return null;
    }
    const rawQtypes = Array.isArray(raw.qtypes) ? raw.qtypes : [];
    const type = qtype((_b = raw.qtype) != null ? _b : rawQtypes[0]);
    const title = text(raw.title, text(raw.questionText, `Frage ${rootId}`));
    const versions = records(raw.versions).map((entry) => version(entry, type, title)).filter((entry) => entry !== null);
    const rawOccurrences = Array.isArray(raw.occurrences) ? raw.occurrences : raw.distributions;
    const occurrences = records(rawOccurrences).map((entry) => occurrence(entry, {
      qtype: type,
      rootId,
      title,
      versions
    })).filter((entry) => entry !== null);
    occurrences.forEach((entry) => {
      if (!versions.some((candidate) => candidate.questionId === entry.questionId && candidate.version === entry.version)) {
        versions.push({
          questionId: entry.questionId,
          questionText: entry.question.questionText,
          qtype: entry.question.qtype,
          version: entry.version
        });
      }
    });
    const gradedCount = nonNegativeInteger((_c = raw.gradedCount) != null ? _c : raw.gradableCount);
    const correctCount = Math.min(
      gradedCount || Number.MAX_SAFE_INTEGER,
      nonNegativeInteger(raw.correctCount)
    );
    const rate = (_e = boundedPercent((_d = raw.correctRate) != null ? _d : raw.correctPercent)) != null ? _e : gradedCount > 0 ? correctCount / gradedCount * 100 : null;
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
      versions: versions.filter((entry, index, all) => all.findIndex((candidate) => candidate.questionId === entry.questionId && candidate.version === entry.version) === index).sort((left, right) => left.version - right.version)
    };
  }
  function participant(value2) {
    var _a, _b, _c, _d, _e, _f, _g, _h;
    const raw = record(value2);
    const displayName = text((_a = raw.displayName) != null ? _a : raw.name);
    if (displayName === "") {
      return null;
    }
    const gradedCount = nonNegativeInteger((_b = raw.gradedCount) != null ? _b : raw.gradableCount);
    const correctCount = Math.min(
      gradedCount || Number.MAX_SAFE_INTEGER,
      nonNegativeInteger(raw.correctCount)
    );
    const rate = (_d = boundedPercent((_c = raw.correctRate) != null ? _c : raw.correctPercent)) != null ? _d : gradedCount > 0 ? correctCount / gradedCount * 100 : null;
    const profileUrl = text(raw.profileUrl);
    const userId = positiveInteger((_f = (_e = raw.userId) != null ? _e : raw.userid) != null ? _f : raw.id);
    return {
      attemptCount: nonNegativeInteger(raw.attemptCount),
      averageResponseTimeMs: duration(raw.averageResponseTimeMs),
      correctCount,
      correctRate: rate,
      displayName,
      gradedCount,
      maxPoints: Math.max(0, finiteNumber(raw.maxPoints)),
      points: Math.max(0, finiteNumber(raw.points)),
      ...profileUrl !== "" ? { profileUrl } : {},
      responseCount: nonNegativeInteger(raw.responseCount),
      sourceCount: nonNegativeInteger(raw.sourceCount),
      userId,
      userIdentifier: text(
        (_h = (_g = raw.userIdentifier) != null ? _g : raw.idnumber) != null ? _h : raw.username,
        userId > 0 ? `#${userId}` : ""
      )
    };
  }
  function openResponse(value2) {
    var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j;
    const raw = record(value2);
    const responseText = text((_b = (_a = raw.text) != null ? _a : raw.response) != null ? _b : raw.answer);
    const id = identifier(raw.id, identifier(raw.responseId));
    if (id === "" && responseText === "") {
      return null;
    }
    return {
      authoritative: boolean(raw.authoritative, true),
      displayName: text((_c = raw.displayName) != null ? _c : raw.authorName),
      id: id || `${text(raw.sourceKey)}:${positiveInteger(raw.questionId)}:${positiveInteger(raw.userId)}`,
      kind: text((_d = raw.kind) != null ? _d : raw.qtype, "open"),
      metricEligible: boolean(raw.metricEligible, true),
      questionId: positiveInteger(raw.questionId),
      questionTitle: text((_e = raw.questionTitle) != null ? _e : raw.title),
      rootId: positiveInteger((_f = raw.rootId) != null ? _f : raw.rootid),
      rootKey: text(raw.rootKey),
      sourceKey: text(raw.sourceKey),
      sourceLabel: text(raw.sourceLabel, text(raw.sourceKey)),
      status: text(raw.status, "recorded"),
      text: responseText,
      timeCreatedMs: milliseconds((_g = raw.timeCreatedMs) != null ? _g : raw.timeCreated),
      userId: positiveInteger((_h = raw.userId) != null ? _h : raw.userid),
      userIdentifier: text(
        (_j = (_i = raw.userIdentifier) != null ? _i : raw.idnumber) != null ? _j : raw.username
      ),
      version: positiveInteger(raw.version, 1)
    };
  }
  function moderationEntry(value2) {
    var _a, _b, _c, _d, _e;
    const raw = record(value2);
    const action = text((_b = (_a = raw.action) != null ? _a : raw.decision) != null ? _b : raw.status);
    const id = identifier(raw.id);
    if (id === "" && action === "") {
      return null;
    }
    return {
      action: action || "recorded",
      actorName: text((_c = raw.actorName) != null ? _c : raw.displayName),
      id: id || `${text(raw.sourceKey)}:${positiveInteger(raw.questionId)}:${integer(raw.timeCreated)}`,
      questionId: positiveInteger(raw.questionId),
      rootId: positiveInteger((_d = raw.rootId) != null ? _d : raw.rootid),
      rootKey: text(raw.rootKey),
      sourceKey: text(raw.sourceKey),
      sourceLabel: text(raw.sourceLabel, text(raw.sourceKey)),
      targetKey: text(raw.targetKey),
      targetType: text(raw.targetType),
      timeCreatedMs: milliseconds((_e = raw.timeCreatedMs) != null ? _e : raw.timeCreated),
      version: positiveInteger(raw.version, 1)
    };
  }
  function hardestQuestion(value2, questions) {
    var _a, _b;
    const raw = record(value2);
    if (Object.keys(raw).length > 0) {
      const rootId = positiveInteger((_a = raw.rootId) != null ? _a : raw.rootid);
      const rootKey = text(raw.rootKey, rootId > 0 ? String(rootId) : "");
      return {
        correctRate: boundedPercent((_b = raw.correctRate) != null ? _b : raw.correctPercent),
        rootId,
        rootKey,
        title: text(raw.title, `Frage ${rootId}`)
      };
    }
    const key = text(value2);
    if (key !== "") {
      const match2 = questions.find((question) => question.rootKey === key || String(question.rootId) === key);
      if (match2) {
        return {
          correctRate: match2.correctRate,
          rootId: match2.rootId,
          rootKey: match2.rootKey,
          title: match2.title
        };
      }
    }
    const graded = questions.filter((question) => question.gradedCount > 0 && question.correctRate !== null).sort((left, right) => (left.correctRate || 0) - (right.correctRate || 0));
    const match = graded[0];
    return match ? {
      correctRate: match.correctRate,
      rootId: match.rootId,
      rootKey: match.rootKey,
      title: match.title
    } : null;
  }
  function summary(value2, questions) {
    var _a, _b, _c, _d, _e, _f, _g;
    const raw = record(value2);
    const participating = nonNegativeInteger(
      (_a = raw.participating) != null ? _a : raw.participantCount
    );
    const eligible = nonNegativeInteger((_b = raw.eligible) != null ? _b : raw.eligibleCount);
    const participationRate = (_d = boundedPercent(
      (_c = raw.participationRate) != null ? _c : raw.participationPercent
    )) != null ? _d : eligible > 0 ? participating / eligible * 100 : null;
    return {
      averageCorrectRate: boundedPercent(
        (_e = raw.averageCorrectRate) != null ? _e : raw.averageCorrectPercent
      ),
      eligible,
      hardestQuestion: hardestQuestion(
        (_f = raw.hardestQuestion) != null ? _f : raw.hardestRootKey,
        questions
      ),
      participating,
      participationRate,
      pointsPercent: boundedPercent((_g = raw.pointsPercent) != null ? _g : raw.averagePoints)
    };
  }
  function timelineEntry(value2) {
    var _a, _b, _c, _d, _e, _f, _g;
    const raw = record(value2);
    const sourceKey = text((_a = raw.sourceKey) != null ? _a : raw.sourcekey);
    const kind = (_b = sourceKind(raw.kind)) != null ? _b : sourceKind(sourceKey.split(":", 1)[0]);
    if (sourceKey === "" || kind === null) {
      return null;
    }
    return {
      averageCorrectRate: boundedPercent(
        (_c = raw.averageCorrectRate) != null ? _c : raw.averageCorrectPercent
      ),
      instanceName: text(raw.instanceName),
      kind,
      quizgeistId: positiveInteger((_d = raw.quizgeistId) != null ? _d : raw.instanceId),
      participantCount: nonNegativeInteger(raw.participantCount),
      pointsPercent: boundedPercent((_e = raw.pointsPercent) != null ? _e : raw.averagePoints),
      sourceKey,
      sourceLabel: text((_f = raw.sourceLabel) != null ? _f : raw.sourcelabel, sourceKey),
      timestampMs: milliseconds((_g = raw.timestampMs) != null ? _g : raw.timestamp)
    };
  }
  function normaliseReportData(value2, fallbackSelection) {
    var _a, _b;
    const raw = unwrap(value2, "report");
    const questions = records(raw.questions).map(reportQuestion).filter((entry) => entry !== null);
    const reportSelection = selection(raw.selection, fallbackSelection);
    return {
      // F6: ohne reports-Addon liefert der Server gar keine Kompetenzachse.
      // Die leere Liste ist deshalb kein Fehler, sondern der Sollzustand.
      competences: normaliseCompetences(raw.competences),
      moderationTrailTruncated: boolean(raw.moderationTrailTruncated),
      moderationTrail: records(raw.moderationTrail).map(moderationEntry).filter((entry) => entry !== null),
      omittedSources: records(raw.omittedSources).map((entry) => ({
        cmid: positiveInteger(entry.cmid),
        instanceName: text(entry.instanceName),
        reason: text(entry.reason)
      })),
      openResponsesTruncated: boolean(raw.openResponsesTruncated),
      openResponses: records(raw.openResponses).map(openResponse).filter((entry) => entry !== null),
      participants: records((_a = raw.participants) != null ? _a : raw.students).map(participant).filter((entry) => entry !== null),
      questions,
      selection: reportSelection,
      subtitle: text(raw.subtitle),
      summary: summary((_b = raw.summary) != null ? _b : raw.kpis, questions),
      timeline: records(raw.timeline).map(timelineEntry).filter((entry) => entry !== null).sort((left, right) => left.timestampMs - right.timestampMs || left.sourceLabel.localeCompare(right.sourceLabel) || left.sourceKey.localeCompare(right.sourceKey)),
      title: text(raw.title)
    };
  }

  // src/live/choices.ts
  var LIVE_CHOICE_SLOTS = ["a", "b", "c", "d", "e", "f"];
  function choiceSlot(qtype2, index) {
    if (qtype2 === "truefalse" && index === 1) {
      return "d";
    }
    return LIVE_CHOICE_SLOTS[Math.max(0, Math.min(LIVE_CHOICE_SLOTS.length - 1, index))];
  }
  function choiceShape(slot) {
    return liveElement(
      "span",
      `quizgeist-live-choice-shape quizgeist-live-choice-shape--${slot}`,
      {
        "aria-hidden": "true",
        "data-choice-slot": slot
      }
    );
  }

  // src/live/media.ts
  var IMAGE_MIME_TYPES = /* @__PURE__ */ new Set([
    "image/png",
    "image/jpeg",
    "image/gif",
    "image/webp",
    "image/svg+xml"
  ]);
  var VIDEO_MIME_TYPES = /* @__PURE__ */ new Set([
    "video/mp4",
    "video/webm",
    "video/ogg"
  ]);
  var AUDIO_MIME_TYPES = /* @__PURE__ */ new Set([
    "audio/mp4",
    "audio/webm",
    "audio/mp3",
    "audio/mpeg",
    "audio/ogg",
    "audio/wav",
    "audio/x-wav",
    "application/ogg"
  ]);
  function mediaKind(mimetype) {
    if (IMAGE_MIME_TYPES.has(mimetype)) {
      return "image";
    }
    if (VIDEO_MIME_TYPES.has(mimetype)) {
      return "video";
    }
    if (AUDIO_MIME_TYPES.has(mimetype)) {
      return "audio";
    }
    return null;
  }
  function sameOriginUrl(raw) {
    try {
      const url = new URL(raw, document.baseURI);
      if (url.protocol !== "http:" && url.protocol !== "https:" || url.origin !== window.location.origin) {
        return null;
      }
      return url.href;
    } catch (_error) {
      return null;
    }
  }
  function createLiveMedia(source2, options) {
    const rawUrl = typeof source2.mediaUrl === "string" ? source2.mediaUrl : "";
    const mimetype = source2.mediaMimeType;
    if (rawUrl === "" || !mimetype) {
      return null;
    }
    const kind = mediaKind(mimetype);
    const url = sameOriginUrl(rawUrl);
    if (!kind || !url || kind !== "image" && options.allowPlayback === false) {
      return null;
    }
    if (kind === "image") {
      const image = document.createElement("img");
      image.className = options.className;
      image.src = url;
      image.alt = options.label;
      image.dataset.mediaMimeType = mimetype;
      return image;
    }
    const media = document.createElement(kind);
    media.className = options.className;
    media.controls = true;
    media.preload = "metadata";
    media.setAttribute("aria-label", options.label);
    media.dataset.mediaMimeType = mimetype;
    if (media instanceof HTMLVideoElement) {
      media.playsInline = true;
    }
    const mediaSource = document.createElement("source");
    mediaSource.src = url;
    mediaSource.type = mimetype;
    media.append(mediaSource);
    return media;
  }

  // src/live/qtype/registry.ts
  function data(question) {
    return question.typeData && typeof question.typeData === "object" ? question.typeData : {};
  }
  function value(question, key, fallback) {
    var _a;
    const source2 = data(question);
    const direct = question[key];
    const candidate = (_a = source2[key]) != null ? _a : direct;
    return candidate === void 0 || candidate === null ? fallback : candidate;
  }
  function pinMedia(question) {
    const typeData = data(question);
    return {
      mediaMimeType: typeData.mediaMimeType || question.mediaMimeType,
      mediaUrl: typeData.mediaUrl || question.mediaUrl
    };
  }
  function pinImageGeometry(canvas, image) {
    const canvasBounds = canvas.getBoundingClientRect();
    if (canvasBounds.width <= 0 || canvasBounds.height <= 0) {
      return null;
    }
    const imageBounds = image == null ? void 0 : image.getBoundingClientRect();
    const box = imageBounds && imageBounds.width > 0 && imageBounds.height > 0 ? imageBounds : canvasBounds;
    let width = box.width;
    let height = box.height;
    let left = box.left - canvasBounds.left;
    let top = box.top - canvasBounds.top;
    if (image && image.naturalWidth > 0 && image.naturalHeight > 0) {
      const scale = Math.min(
        box.width / image.naturalWidth,
        box.height / image.naturalHeight
      );
      width = image.naturalWidth * scale;
      height = image.naturalHeight * scale;
      left += (box.width - width) / 2;
      top += (box.height - height) / 2;
    }
    return {
      canvasHeight: canvasBounds.height,
      canvasWidth: canvasBounds.width,
      height,
      left,
      top,
      width
    };
  }
  function positionPinNode(canvas, image, node, point) {
    const geometry = pinImageGeometry(canvas, image);
    if (!geometry) {
      return;
    }
    node.style.left = `${(geometry.left + geometry.width * point.x / 100) * 100 / geometry.canvasWidth}%`;
    node.style.top = `${(geometry.top + geometry.height * point.y / 100) * 100 / geometry.canvasHeight}%`;
  }
  var REACTION_LABEL_FALLBACKS = {
    clap: "Applaus",
    heart: "Herz",
    idea: "Idee",
    laugh: "Lachen",
    wow: "Wow"
  };
  function reactionOptions(question, context) {
    const source2 = value(question, "reactionOptions", value(question, "reactions", []));
    if (Array.isArray(source2)) {
      return source2.flatMap((entry, index) => {
        if (typeof entry === "string") {
          return [{
            id: entry,
            emoji: entry,
            label: context.text(
              `live:reaction:${entry}`,
              REACTION_LABEL_FALLBACKS[entry] || "Reaktion"
            )
          }];
        }
        if (entry && typeof entry === "object") {
          const record2 = entry;
          const id = String(record2.id || record2.reaction || index);
          return [{
            count: Number(record2.count || 0),
            emoji: String(record2.emoji || record2.reaction || "\u2728"),
            id,
            label: String(
              record2.label || context.text(
                `live:reaction:${id}`,
                REACTION_LABEL_FALLBACKS[id] || "Reaktion"
              )
            )
          }];
        }
        return [];
      });
    }
    return [
      { emoji: "\u2764\uFE0F", id: "heart", label: context.text("live:reaction:heart", "Herz") },
      { emoji: "\u{1F44F}", id: "clap", label: context.text("live:reaction:clap", "Applaus") },
      { emoji: "\u{1F4A1}", id: "idea", label: context.text("live:reaction:idea", "Idee") },
      { emoji: "\u{1F604}", id: "laugh", label: context.text("live:reaction:laugh", "Lachen") },
      { emoji: "\u{1F62E}", id: "wow", label: context.text("live:reaction:wow", "Wow") }
    ];
  }
  function questionSpeechText(question) {
    if (typeof question.speechText === "string" && question.speechText.trim() !== "") {
      return question.speechText;
    }
    const chunks = [question.questionText];
    if (question.qtype === "slide") {
      chunks.push(
        String(value(question, "title", "")),
        String(value(question, "body", "")),
        ...value(question, "bullets", []),
        String(value(question, "quote", "")),
        String(value(question, "attribution", ""))
      );
    }
    return chunks.filter(Boolean).join(". ");
  }
  function answerLabels(question, ids) {
    const candidates = [
      ...Array.isArray(question.choices) ? question.choices : [],
      ...value(question, "items", [])
    ];
    return ids.flatMap((id) => {
      const match = candidates.find((candidate) => candidate.id === id);
      return match ? [match.text] : [];
    });
  }
  function renderOwnAnswer(question, answer, context) {
    const section = liveElement("section", "quizgeist-study-solution__own");
    section.append(liveElement("h4", "", {
      text: context.text("selfstudy:review:ownanswer", "Deine Antwort")
    }));
    let lines = [];
    if ("choiceIds" in answer) {
      lines = answerLabels(question, answer.choiceIds);
    } else if (answer.kind === "order") {
      lines = answerLabels(question, answer.orderIds);
    } else if (answer.kind === "text" || answer.kind === "brainstormIdea") {
      lines = [answer.text];
    } else if (answer.kind === "number") {
      lines = [String(answer.value)];
    } else if (answer.kind === "pin") {
      lines = [`x: ${Math.round(answer.x * 10) / 10} %, y: ${Math.round(answer.y * 10) / 10} %`];
    } else if (answer.kind === "reaction") {
      lines = [answer.reaction];
    } else if (answer.kind === "brainstormVote") {
      lines = [answer.groupKey];
    }
    if (lines.length === 0) {
      section.append(liveElement("p", "", {
        text: context.text(
          "selfstudy:review:answersaved",
          "Deine Antwort wurde gespeichert."
        )
      }));
    } else if (lines.length === 1) {
      section.append(liveElement("p", "", { text: lines[0] }));
    } else {
      const list = liveElement("ol", "quizgeist-study-solution__list");
      lines.forEach((line) => list.append(liveElement("li", "", { text: line })));
      section.append(list);
    }
    return section;
  }
  function renderQuestionSolution(question, context) {
    const root = liveElement("section", "quizgeist-study-solution", {
      "data-selfstudy-solution": true
    });
    const heading = liveElement("h3", "quizgeist-study-solution__title", {
      text: context.text("selfstudy:review:solution", "L\xF6sung und R\xFCckmeldung")
    });
    heading.tabIndex = -1;
    root.append(heading);
    if (typeof context.correct === "boolean") {
      root.append(liveElement(
        "p",
        `quizgeist-study-result quizgeist-study-result--${context.correct ? "correct" : "incorrect"}`,
        {
          role: "status",
          text: context.correct ? context.text("selfstudy:review:correct", "Richtig beantwortet") : context.text(
            "selfstudy:review:incorrect",
            "Noch nicht richtig \u2013 nutze die L\xF6sung zum Weiterlernen."
          )
        }
      ));
    }
    if (context.answer) {
      root.append(renderOwnAnswer(question, context.answer, context));
    }
    const solution = liveElement("section", "quizgeist-study-solution__canonical");
    solution.append(liveElement("h4", "", {
      text: context.text("selfstudy:review:correctanswer", "Richtige Antwort")
    }));
    let hasSolution = false;
    const correctChoices = question.choices.filter((choice2) => choice2.correct === true);
    if (correctChoices.length > 0) {
      const list = liveElement("ul", "quizgeist-study-solution__list");
      correctChoices.forEach((choice2) => {
        const item = liveElement("li", "", { text: choice2.text });
        const media = createLiveMedia(choice2, {
          allowPlayback: false,
          className: "quizgeist-study-solution__media",
          label: choice2.text
        });
        if (media) {
          item.append(media);
        }
        list.append(item);
      });
      solution.append(list);
      hasSolution = true;
    }
    const acceptedAnswers = value(question, "acceptedAnswers", []).filter((entry) => typeof entry === "string" && entry.trim() !== "");
    if (acceptedAnswers.length > 0) {
      const list = liveElement("ul", "quizgeist-study-solution__list");
      acceptedAnswers.forEach((entry) => {
        list.append(liveElement("li", "", { text: entry }));
      });
      solution.append(list);
      hasSolution = true;
    }
    const correctOrder = value(question, "correctOrderIds", []);
    if (correctOrder.length > 0) {
      const labels = answerLabels(question, correctOrder);
      if (labels.length === correctOrder.length) {
        const list = liveElement("ol", "quizgeist-study-solution__list");
        labels.forEach((entry) => list.append(liveElement("li", "", { text: entry })));
        solution.append(list);
        hasSolution = true;
      }
    }
    const target = value(question, "target", null);
    if (typeof target === "number" && Number.isFinite(target)) {
      const tolerance = Number(value(question, "tolerance", 0));
      solution.append(liveElement("p", "", {
        text: Number.isFinite(tolerance) && tolerance > 0 ? context.text(
          "selfstudy:review:targettolerance",
          "Zielwert: {$target} (Toleranz \xB1 {$tolerance})",
          { target, tolerance }
        ) : context.text(
          "selfstudy:review:target",
          "Zielwert: {$target}",
          { target }
        )
      }));
      hasSolution = true;
    } else if (target && typeof target === "object" && !Array.isArray(target)) {
      const point = target;
      const x = Number(point.x);
      const y = Number(point.y);
      if (Number.isFinite(x) && Number.isFinite(y)) {
        const radius = Number(value(question, "radius", 0));
        solution.append(liveElement("p", "", {
          text: context.text(
            "selfstudy:review:pin",
            "Zielbereich bei x: {$x} %, y: {$y} % (Radius {$radius} %)",
            {
              radius: Number.isFinite(radius) ? radius : 0,
              x: Math.round(x * 10) / 10,
              y: Math.round(y * 10) / 10
            }
          )
        }));
        hasSolution = true;
      }
    }
    const sampleAnswer = value(question, "sampleAnswer", "").trim();
    if (sampleAnswer !== "") {
      solution.append(liveElement("p", "quizgeist-study-solution__sample", {
        text: sampleAnswer
      }));
      hasSolution = true;
    }
    if (!hasSolution) {
      solution.append(liveElement("p", "", {
        text: context.text(
          "selfstudy:review:selfcheck",
          "F\xFCr diese Frage gibt es keine automatisch bewertete Musterl\xF6sung."
        )
      }));
    }
    root.append(solution);
    return root;
  }
  function aggregateRecord(aggregate2) {
    return aggregate2 && !Array.isArray(aggregate2) && typeof aggregate2 === "object" ? aggregate2 : {};
  }
  function renderChoiceAggregate(question, entries, context) {
    const container = liveElement("div", "quizgeist-host-distribution", {
      "data-live-aggregate-kind": "choice",
      "data-live-distribution": true
    });
    const byChoice = new Map(entries.map((entry) => [entry.choiceId, entry]));
    question.choices.forEach((choice2, index) => {
      var _a;
      const entry = byChoice.get(choice2.id) || {
        choiceId: choice2.id,
        count: 0,
        percent: 0
      };
      const slot = choiceSlot(question.qtype, index);
      const percent2 = Math.max(0, Math.min(100, Number(entry.percent || 0)));
      const row = liveElement("div", "quizgeist-host-distribution-row", {
        "data-live-choice-id": choice2.id
      });
      const label = liveElement("div", "quizgeist-host-distribution-row__label");
      label.append(choiceShape(slot), liveElement("span", "", { text: choice2.text }));
      const track = liveElement("div", "quizgeist-host-distribution-row__track");
      const bar = liveElement(
        "div",
        `quizgeist-host-distribution-row__bar quizgeist-host-distribution-row__bar--${slot}`,
        { text: `${Math.round(percent2)} %` }
      );
      bar.style.width = `${percent2}%`;
      track.append(bar);
      row.append(
        label,
        track,
        liveElement("span", "quizgeist-host-distribution-row__count", {
          text: String(Math.max(0, Number(entry.count || 0)))
        })
      );
      if (((_a = question.policyDescriptor) == null ? void 0 : _a.showsCorrectness) !== false && (entry.correct || choice2.correct)) {
        row.classList.add("is-correct");
        row.append(liveElement("span", "quizgeist-host-distribution-row__correct", {
          text: context.text("host:reveal:correct", "Richtige Antwort")
        }));
      }
      container.append(row);
    });
    return container;
  }
  function objectRows(raw) {
    return Array.isArray(raw) ? raw.filter((entry) => Boolean(entry && typeof entry === "object" && !Array.isArray(entry))) : [];
  }
  function renderLiveAggregate(question, aggregate2, context) {
    if (Array.isArray(aggregate2)) {
      return renderChoiceAggregate(question, aggregate2, context);
    }
    const record2 = aggregateRecord(aggregate2);
    const kind = String(record2.kind || (["quiz", "truefalse", "poll"].includes(question.qtype) ? "choice" : question.qtype));
    if (kind === "choice") {
      const entries = objectRows(record2.entries || record2.distribution).map((entry) => ({
        choiceId: String(entry.choiceId || entry.id || ""),
        correct: typeof entry.correct === "boolean" ? entry.correct : void 0,
        count: Number(entry.count || 0),
        percent: Number(entry.percent || 0)
      }));
      return renderChoiceAggregate(question, entries, context);
    }
    const root = liveElement(
      "section",
      `quizgeist-live-aggregate quizgeist-live-aggregate--${kind}`,
      { "data-live-aggregate-kind": kind }
    );
    if (kind === "wordcloud") {
      const cloud = liveElement("div", "quizgeist-live-wordcloud__cloud", {
        "data-live-wordcloud-published": true
      });
      objectRows(record2.words || record2.entries).forEach((word, index) => {
        const count2 = Math.max(1, Number(word.count || word.value || 1));
        const node = liveElement("span", "quizgeist-live-word", {
          "data-live-word": String(word.text || word.word || ""),
          text: String(word.text || word.word || "")
        });
        node.style.setProperty("--mq-word-weight", String(Math.min(6, 1 + Math.log2(count2))));
        node.style.setProperty("--mq-word-slot", String(index % 6));
        node.append(liveElement("small", "", { text: ` ${count2}` }));
        cloud.append(node);
      });
      if (cloud.childElementCount === 0) {
        cloud.append(liveElement("p", "quizgeist-live-aggregate__empty", {
          text: context.text(
            "live:wordcloud:nopublished",
            "Noch keine freigegebenen Begriffe."
          )
        }));
      }
      root.append(cloud);
      const moderation = record2.moderation;
      if (context.audience === "host" && moderation && typeof moderation === "object" && !Array.isArray(moderation)) {
        const moderationRecord = moderation;
        const panel = liveElement("section", "quizgeist-live-wordcloud-moderation", {
          "aria-label": context.text(
            "live:wordcloud:moderation",
            "Begriffe moderieren"
          ),
          "data-live-wordcloud-moderation": true
        });
        panel.append(liveElement("h2", "quizgeist-live-wordcloud-moderation__title", {
          text: context.text("live:wordcloud:moderation", "Begriffe moderieren")
        }));
        const items = objectRows(moderationRecord.items);
        items.forEach((item) => {
          const key = String(item.key || "");
          const status = ["approved", "rejected"].includes(String(item.status)) ? String(item.status) : "pending";
          const row = liveElement("article", "quizgeist-live-wordcloud-moderation__item", {
            "data-live-wordcloud-status": status,
            "data-live-wordcloud-term-key": key
          });
          const term = liveElement("span", "quizgeist-live-wordcloud-moderation__term", {
            text: String(item.text || key)
          });
          term.append(liveElement("small", "", {
            text: ` ${Math.max(1, Number(item.count || 1))}`
          }));
          const statusLabel = liveElement(
            "span",
            "quizgeist-live-wordcloud-moderation__status",
            {
              text: context.text(
                `live:wordcloud:${status}`,
                status === "approved" ? "Freigegeben" : status === "rejected" ? "Abgelehnt" : "Ausstehend"
              )
            }
          );
          row.append(term, statusLabel);
          if (context.onAggregateAction) {
            const actions = liveElement(
              "div",
              "quizgeist-live-wordcloud-moderation__actions"
            );
            const approve = liveButton(
              context.text("live:wordcloud:approve", "Freigeben"),
              "quizgeist-host-button quizgeist-host-button--secondary",
              {
                "aria-busy": context.disabled ? "true" : "false",
                "aria-pressed": status === "approved" ? "true" : "false",
                "data-live-host-interaction": true,
                "data-live-wordcloud-moderate": "approved",
                "data-live-wordcloud-term-key": key,
                disabled: context.disabled || status === "approved"
              }
            );
            const reject = liveButton(
              context.text("live:wordcloud:reject", "Ablehnen"),
              "quizgeist-host-button quizgeist-host-button--secondary",
              {
                "aria-busy": context.disabled ? "true" : "false",
                "aria-pressed": status === "rejected" ? "true" : "false",
                "data-live-host-interaction": true,
                "data-live-wordcloud-moderate": "rejected",
                "data-live-wordcloud-term-key": key,
                disabled: context.disabled || status === "rejected"
              }
            );
            approve.addEventListener("click", () => {
              var _a;
              (_a = context.onAggregateAction) == null ? void 0 : _a.call(context, {
                decision: "approved",
                interactionKind: "moderation",
                termKey: key
              });
            });
            reject.addEventListener("click", () => {
              var _a;
              (_a = context.onAggregateAction) == null ? void 0 : _a.call(context, {
                decision: "rejected",
                interactionKind: "moderation",
                termKey: key
              });
            });
            actions.append(approve, reject);
            row.append(actions);
          }
          panel.append(row);
        });
        if (items.length === 0) {
          panel.append(liveElement("p", "quizgeist-live-aggregate__empty", {
            text: context.text("live:aggregate:empty", "Noch keine Antworten.")
          }));
        }
        root.append(panel);
      }
      return root;
    }
    if (kind === "scale" || kind === "slider") {
      const buckets = objectRows(record2.histogram || record2.buckets || record2.entries);
      const chart = liveElement("div", "quizgeist-live-scale-aggregate");
      buckets.forEach((bucket) => {
        var _a, _b;
        const percent2 = Math.max(0, Math.min(100, Number(bucket.percent || 0)));
        const column = liveElement("div", "quizgeist-live-scale-aggregate__column", {
          "data-live-aggregate-value": String((_a = bucket.value) != null ? _a : "")
        });
        const bar = liveElement("span", "quizgeist-live-scale-aggregate__bar", {
          text: String(bucket.count || 0)
        });
        bar.style.height = `${Math.max(4, percent2)}%`;
        column.append(bar, liveElement("strong", "", {
          text: String((_b = bucket.value) != null ? _b : "")
        }));
        chart.append(column);
      });
      root.append(chart);
      if (Number.isFinite(Number(record2.mean))) {
        root.append(liveElement("p", "quizgeist-live-aggregate__mean", {
          text: `${context.text("live:aggregate:mean", "Mittelwert")}: ${Number(record2.mean).toFixed(1)}`
        }));
      }
      if (Number.isFinite(Number(record2.median))) {
        root.append(liveElement("p", "quizgeist-live-aggregate__median", {
          text: `${context.text("live:aggregate:median", "Median")}: ${Number(record2.median).toFixed(1)}`
        }));
      }
      if (kind === "slider" && buckets.length === 0 && Array.isArray(record2.values)) {
        const values = record2.values.filter((entry) => typeof entry === "number" && Number.isFinite(entry));
        const valuesNode = liveElement("p", "quizgeist-live-aggregate__values", {
          "data-live-slider-values": true,
          text: values.join(" \xB7 ")
        });
        root.prepend(valuesNode);
      }
      return root;
    }
    if (kind === "pin") {
      const canvas = liveElement("div", "quizgeist-live-pin quizgeist-live-pin--aggregate", {
        "data-live-pin-heatmap": true
      });
      const image = createLiveMedia(pinMedia(question), {
        allowPlayback: false,
        className: "quizgeist-live-pin__image",
        label: question.questionText
      });
      if (image) {
        canvas.append(image);
      }
      objectRows(record2.points || record2.heatmap).forEach((point) => {
        const dot = liveElement("span", "quizgeist-live-pin__heat", { "aria-hidden": "true" });
        dot.style.setProperty("--mq-pin-weight", String(Number(point.weight || point.count || 1)));
        canvas.append(dot);
        positionPinNode(canvas, image instanceof HTMLImageElement ? image : null, dot, {
          x: Number(point.x || 0),
          y: Number(point.y || 0)
        });
      });
      const target = record2.target;
      if (target && typeof target === "object") {
        const targetPoint = target;
        const marker = liveElement("span", "quizgeist-live-pin__target", {
          "aria-label": context.text("live:pin:target", "Zielbereich")
        });
        canvas.append(marker);
        positionPinNode(canvas, image instanceof HTMLImageElement ? image : null, marker, {
          x: Number(targetPoint.x || 0),
          y: Number(targetPoint.y || 0)
        });
      }
      if (image instanceof HTMLImageElement) {
        image.addEventListener("load", () => {
          objectRows(record2.points || record2.heatmap).forEach((point, index) => {
            const dot = canvas.querySelectorAll(".quizgeist-live-pin__heat")[index];
            if (dot) {
              positionPinNode(canvas, image, dot, {
                x: Number(point.x || 0),
                y: Number(point.y || 0)
              });
            }
          });
          const marker = canvas.querySelector(".quizgeist-live-pin__target");
          if (marker && target && typeof target === "object") {
            const targetPoint = target;
            positionPinNode(canvas, image, marker, {
              x: Number(targetPoint.x || 0),
              y: Number(targetPoint.y || 0)
            });
          }
        }, { once: true });
      }
      root.append(canvas);
      return root;
    }
    if (kind === "brainstorm") {
      const groups = objectRows(record2.groups);
      groups.forEach((group2) => {
        const section = liveElement("section", "quizgeist-live-brainstorm__group", {
          "data-live-brainstorm-group": String(group2.key || "")
        });
        section.append(liveElement("h3", "", {
          text: String(group2.label || "")
        }));
        if (group2.votes !== null && group2.votes !== void 0 && Number.isFinite(Number(group2.votes))) {
          section.append(liveElement("strong", "quizgeist-live-brainstorm__votes", {
            text: `\u2665 ${Number(group2.votes)}`
          }));
        }
        objectRows(group2.ideas).forEach((idea) => {
          const ideaNode = liveElement("article", "quizgeist-live-brainstorm__idea", {
            "data-live-brainstorm-idea": String(idea.id || idea.key || ""),
            text: String(idea.text || "")
          });
          if (idea.votes !== null && idea.votes !== void 0 && Number.isFinite(Number(idea.votes))) {
            ideaNode.append(liveElement("span", "quizgeist-live-brainstorm__votes", {
              text: `\u2665 ${Number(idea.votes)}`
            }));
          }
          section.append(ideaNode);
        });
        root.append(section);
      });
      root.dataset.liveBrainstormStage = String(record2.stage || "");
      const moderation = record2.moderation;
      if (context.audience === "host" && moderation && typeof moderation === "object" && !Array.isArray(moderation)) {
        const moderationRecord = moderation;
        const panel = liveElement("section", "quizgeist-live-wordcloud-moderation", {
          "aria-label": context.text(
            "live:brainstorm:moderation",
            "Ideen moderieren"
          ),
          "data-live-brainstorm-moderation": true
        });
        panel.append(liveElement("h2", "quizgeist-live-wordcloud-moderation__title", {
          text: context.text("live:brainstorm:moderation", "Ideen moderieren")
        }));
        const items = objectRows(moderationRecord.items);
        items.forEach((item) => {
          const id = String(item.id || "");
          const status = ["approved", "rejected"].includes(String(item.status)) ? String(item.status) : "pending";
          const row = liveElement("article", "quizgeist-live-wordcloud-moderation__item", {
            "data-live-brainstorm-idea-id": id,
            "data-live-brainstorm-status": status
          });
          row.append(
            liveElement("span", "quizgeist-live-wordcloud-moderation__term", {
              text: String(item.text || "")
            }),
            liveElement("span", "quizgeist-live-wordcloud-moderation__status", {
              text: context.text(
                `live:wordcloud:${status}`,
                status === "approved" ? "Freigegeben" : status === "rejected" ? "Abgelehnt" : "Ausstehend"
              )
            })
          );
          if (context.onAggregateAction) {
            const actions = liveElement(
              "div",
              "quizgeist-live-wordcloud-moderation__actions"
            );
            const approve = liveButton(
              context.text("live:wordcloud:approve", "Freigeben"),
              "quizgeist-host-button quizgeist-host-button--secondary",
              {
                "aria-busy": context.disabled ? "true" : "false",
                "aria-pressed": status === "approved" ? "true" : "false",
                "data-live-brainstorm-moderate": "approved",
                "data-live-host-interaction": true,
                disabled: context.disabled || status === "approved"
              }
            );
            const reject = liveButton(
              context.text("live:wordcloud:reject", "Ablehnen"),
              "quizgeist-host-button quizgeist-host-button--secondary",
              {
                "aria-busy": context.disabled ? "true" : "false",
                "aria-pressed": status === "rejected" ? "true" : "false",
                "data-live-brainstorm-moderate": "rejected",
                "data-live-host-interaction": true,
                disabled: context.disabled || status === "rejected"
              }
            );
            approve.addEventListener("click", () => {
              var _a;
              (_a = context.onAggregateAction) == null ? void 0 : _a.call(context, {
                decision: "approved",
                ideaId: id,
                interactionKind: "moderation"
              });
            });
            reject.addEventListener("click", () => {
              var _a;
              (_a = context.onAggregateAction) == null ? void 0 : _a.call(context, {
                decision: "rejected",
                ideaId: id,
                interactionKind: "moderation"
              });
            });
            actions.append(approve, reject);
            row.append(actions);
          }
          panel.append(row);
        });
        if (items.length === 0) {
          panel.append(liveElement("p", "quizgeist-live-aggregate__empty", {
            text: context.text("live:aggregate:empty", "Noch keine Antworten.")
          }));
        }
        root.append(panel);
      }
      return root;
    }
    if (kind === "reactions") {
      const options = new Map(
        reactionOptions(question, context).map((option) => [option.id, option])
      );
      objectRows(record2.counts || record2.entries).forEach((entry) => {
        const key = String(entry.reaction || entry.id || "");
        const option = options.get(key);
        const badge = String(
          entry.emoji || (option == null ? void 0 : option.emoji) || (option == null ? void 0 : option.label) || REACTION_LABEL_FALLBACKS[key] || "\u2728"
        );
        root.append(liveElement("span", "quizgeist-live-reaction", {
          "data-live-reaction": key,
          text: `${badge} ${Number(entry.count || 0)}`
        }));
      });
      return root;
    }
    if (kind === "puzzle") {
      const positions = objectRows(record2.positions);
      const order = Array.isArray(record2.correctOrder) ? record2.correctOrder : value(question, "correctOrderIds", []);
      const list = liveElement("ol", "quizgeist-live-puzzle");
      const ids = order.length > 0 ? order : positions.sort((left, right) => Number(left.position || 0) - Number(right.position || 0)).map((entry) => String(entry.itemId || entry.id || ""));
      ids.forEach((id) => {
        const item = value(question, "items", []).find((candidate) => candidate.id === id);
        const position = positions.find((entry) => String(entry.itemId || entry.id || "") === id);
        list.append(liveElement("li", "quizgeist-live-puzzle__item", {
          text: `${(item == null ? void 0 : item.text) || "Puzzle-Element"}${position ? ` \xB7 ${Number(position.correctPositionCount || 0)} richtig platziert` : ""}`
        }));
      });
      root.append(list);
      return root;
    }
    const responses = objectRows(record2.responses || record2.entries || record2.answers);
    responses.forEach((entry) => {
      root.append(liveElement("blockquote", "quizgeist-live-text-response", {
        "data-live-text-response": String(entry.id || ""),
        text: String(entry.text || entry.answer || "")
      }));
    });
    if (responses.length === 0) {
      root.append(liveElement("p", "quizgeist-live-aggregate__empty", {
        text: context.text("live:aggregate:empty", "Noch keine Antworten.")
      }));
    }
    return root;
  }

  // src/live/tts.ts
  function text2(config, key, fallback) {
    var _a, _b;
    const editorAlias = {
      "live:tts:error": "editor:tts:error",
      "live:tts:play": "editor:tts:play",
      "live:tts:stop": "editor:tts:loading",
      "live:tts:unavailable": "editor:tts:unavailable"
    };
    return ((_a = config.strings) == null ? void 0 : _a[key]) || ((_b = config.strings) == null ? void 0 : _b[editorAlias[key]]) || fallback;
  }
  var MAX_TTS_CHUNK_CODEPOINTS = 600;
  function chunkTtsText(content, maximum = MAX_TTS_CHUNK_CODEPOINTS) {
    if (!Number.isInteger(maximum) || maximum < 1) {
      throw new RangeError("The TTS chunk size must be a positive integer.");
    }
    const chunks = [];
    let remaining = Array.from(content.trim());
    while (remaining.length > 0) {
      if (remaining.length <= maximum) {
        const finalChunk = remaining.join("").trim();
        if (finalChunk !== "") {
          chunks.push(finalChunk);
        }
        break;
      }
      const minimumPreferredBoundary = Math.max(1, Math.floor(maximum * 0.45));
      let sentenceBoundary = 0;
      let whitespaceBoundary = 0;
      for (let index = maximum; index >= minimumPreferredBoundary; index -= 1) {
        const previous = remaining[index - 1] || "";
        const next = remaining[index] || "";
        if (sentenceBoundary === 0 && (previous === "." || previous === "!" || previous === "?" || previous === "\n") && (next === "" || /\s/u.test(next))) {
          sentenceBoundary = index;
        }
        if (whitespaceBoundary === 0 && /\s/u.test(previous)) {
          whitespaceBoundary = index - 1;
        }
        if (sentenceBoundary > 0 && whitespaceBoundary > 0) {
          break;
        }
      }
      const cut = sentenceBoundary || whitespaceBoundary || maximum;
      const chunk = remaining.slice(0, cut).join("").trim();
      if (chunk !== "") {
        chunks.push(chunk);
      }
      remaining = remaining.slice(cut);
      while (remaining.length > 0 && /\s/u.test(remaining[0])) {
        remaining.shift();
      }
    }
    return chunks;
  }
  var TtsPlayer = class {
    constructor(config) {
      this.config = config;
      __publicField(this, "audio", null);
      __publicField(this, "controller", null);
      __publicField(this, "currentPlaybackReject", null);
      __publicField(this, "objectUrl", null);
      __publicField(this, "playbackGeneration", 0);
      __publicField(this, "playing", false);
    }
    isAvailable() {
      var _a;
      return Boolean(
        ((_a = this.config.tts) == null ? void 0 : _a.available) && this.config.tts.speakUrl && this.config.tts.voices.length > 0 && this.config.sesskey
      );
    }
    isPlaying() {
      return this.playing;
    }
    stop() {
      var _a;
      this.playbackGeneration += 1;
      this.playing = false;
      (_a = this.controller) == null ? void 0 : _a.abort();
      this.controller = null;
      const reject = this.currentPlaybackReject;
      this.currentPlaybackReject = null;
      this.releaseAudio();
      reject == null ? void 0 : reject(new DOMException("Playback stopped.", "AbortError"));
    }
    releaseAudio() {
      if (this.audio) {
        this.audio.pause();
        this.audio.currentTime = 0;
        this.audio = null;
      }
      if (this.objectUrl) {
        URL.revokeObjectURL(this.objectUrl);
        this.objectUrl = null;
      }
    }
    async play(content, voiceId) {
      var _a, _b, _c;
      this.stop();
      const chunks = chunkTtsText(content);
      if (!this.isAvailable() || chunks.length === 0) {
        throw new Error(text2(
          this.config,
          "live:tts:unavailable",
          "Vorlesen ist f\xFCr dieses Nutzerkonto nicht verf\xFCgbar."
        ));
      }
      const selectedVoice = voiceId || ((_a = this.config.tts) == null ? void 0 : _a.defaultVoiceId) || ((_c = (_b = this.config.tts) == null ? void 0 : _b.voices[0]) == null ? void 0 : _c.id) || 0;
      const generation = this.playbackGeneration;
      this.playing = true;
      try {
        for (const chunk of chunks) {
          if (generation !== this.playbackGeneration) {
            throw new DOMException("Playback stopped.", "AbortError");
          }
          const blob = await this.requestAudio(chunk, selectedVoice, generation);
          await this.playAudio(blob, generation);
        }
      } finally {
        if (generation === this.playbackGeneration) {
          this.playing = false;
          this.controller = null;
          this.currentPlaybackReject = null;
          this.releaseAudio();
        }
      }
    }
    async requestAudio(value2, selectedVoice, generation) {
      var _a;
      const form = new FormData();
      form.append("action", "speak");
      form.append("sesskey", String(this.config.sesskey || ""));
      form.append("text", value2);
      form.append("voiceid", String(selectedVoice));
      form.append("speed", "0.9");
      const controller = new AbortController();
      this.controller = controller;
      const response = await fetch(String(((_a = this.config.tts) == null ? void 0 : _a.speakUrl) || ""), {
        method: "POST",
        credentials: "same-origin",
        body: form,
        signal: controller.signal
      });
      if (generation !== this.playbackGeneration) {
        throw new DOMException("Playback stopped.", "AbortError");
      }
      const contentType = (response.headers.get("Content-Type") || "").split(";", 1)[0].trim().toLowerCase();
      if (!response.ok || contentType !== "audio/mpeg" && contentType !== "audio/mp3") {
        this.controller = null;
        throw new Error(text2(
          this.config,
          "live:tts:error",
          "Der Text konnte nicht vorgelesen werden."
        ));
      }
      const blob = await response.blob();
      this.controller = null;
      if (blob.size === 0) {
        throw new Error(text2(
          this.config,
          "live:tts:error",
          "Der Text konnte nicht vorgelesen werden."
        ));
      }
      return blob;
    }
    async playAudio(blob, generation) {
      this.objectUrl = URL.createObjectURL(blob);
      this.audio = new Audio(this.objectUrl);
      await new Promise((resolve, reject) => {
        var _a, _b, _c;
        let settled = false;
        const finish = (error) => {
          if (settled) {
            return;
          }
          settled = true;
          this.currentPlaybackReject = null;
          this.releaseAudio();
          if (error) {
            reject(error);
          } else {
            resolve();
          }
        };
        this.currentPlaybackReject = (reason) => finish(reason);
        (_a = this.audio) == null ? void 0 : _a.addEventListener("ended", () => finish(), { once: true });
        (_b = this.audio) == null ? void 0 : _b.addEventListener(
          "error",
          () => finish(new Error(text2(
            this.config,
            "live:tts:error",
            "Der Text konnte nicht vorgelesen werden."
          ))),
          { once: true }
        );
        (_c = this.audio) == null ? void 0 : _c.play().catch((error) => finish(
          error instanceof Error ? error : new Error(text2(
            this.config,
            "live:tts:error",
            "Der Text konnte nicht vorgelesen werden."
          ))
        ));
      });
      if (generation !== this.playbackGeneration) {
        throw new DOMException("Playback stopped.", "AbortError");
      }
    }
  };
  function createTtsControl(player, content, config) {
    const wrapper = liveElement("div", "quizgeist-live-tts", {
      "data-live-tts": true
    });
    const status = liveElement("span", "quizgeist-live-tts__status", {
      "aria-live": "polite",
      "data-live-tts-status": true
    });
    const playLabel = text2(config, "live:tts:play", "Vorlesen");
    const stopLabel = text2(config, "live:tts:stop", "Stoppen");
    const button = liveButton(
      playLabel,
      "quizgeist-live-tts__button",
      {
        "aria-pressed": "false",
        "data-live-tts-toggle": true,
        disabled: !player.isAvailable() || content.trim() === ""
      }
    );
    if (button.disabled) {
      button.title = text2(
        config,
        "live:tts:unavailable",
        "Vorlesen ist f\xFCr dieses Nutzerkonto nicht verf\xFCgbar."
      );
    }
    button.addEventListener("click", () => {
      if (player.isPlaying()) {
        player.stop();
        button.textContent = playLabel;
        button.setAttribute("aria-pressed", "false");
        status.textContent = "";
        return;
      }
      button.textContent = stopLabel;
      button.setAttribute("aria-pressed", "true");
      status.textContent = stopLabel;
      void player.play(content).catch((error) => {
        if (!(error instanceof DOMException && error.name === "AbortError")) {
          status.textContent = error instanceof Error ? error.message : text2(config, "live:tts:error", "Der Text konnte nicht vorgelesen werden.");
        }
      }).finally(() => {
        button.textContent = playLabel;
        button.setAttribute("aria-pressed", "false");
        if (status.textContent === stopLabel) {
          status.textContent = "";
        }
      });
    });
    wrapper.append(button, status);
    return wrapper;
  }

  // src/live/api.ts
  var LiveApiError = class extends Error {
    constructor(message, code = "request_failed", status = 0, data2 = null) {
      super(message);
      __publicField(this, "code");
      __publicField(this, "data");
      __publicField(this, "status");
      this.name = "LiveApiError";
      this.code = code;
      this.status = status;
      this.data = data2;
    }
  };
  var LiveApi = class {
    constructor(config) {
      this.config = config;
    }
    async post(action, payload = {}, signal) {
      let response;
      try {
        response = await fetch(this.config.ajaxUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action,
            cmid: this.config.cmid,
            sesskey: this.config.sesskey,
            ...payload
          }),
          signal
        });
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") {
          throw error;
        }
        throw new LiveApiError(
          this.config.strings["live:connection:offline"] || "Die Verbindung ist gerade unterbrochen.",
          "network_error"
        );
      }
      let envelope;
      try {
        envelope = await response.json();
      } catch (_error) {
        throw new LiveApiError(
          this.config.strings["live:error:request"] || "Die Live-Session konnte die Anfrage nicht verarbeiten.",
          "invalid_response",
          response.status
        );
      }
      if (!response.ok || !envelope.ok || envelope.data === void 0) {
        const message = typeof envelope.message === "string" && envelope.message !== "" ? envelope.message : this.config.strings["live:error:request"] || "Die Live-Session konnte die Anfrage nicht verarbeiten.";
        const data2 = envelope.data && typeof envelope.data === "object" ? envelope.data : null;
        throw new LiveApiError(
          message,
          envelope.error || "request_failed",
          response.status,
          data2
        );
      }
      return envelope.data;
    }
  };

  // src/report/api.ts
  var ReportApi = class {
    constructor(config) {
      this.config = config;
      __publicField(this, "api");
      this.api = new LiveApi(config);
    }
    async bootstrap(signal) {
      return normaliseReportBootstrap(
        await this.api.post(this.config.bootstrapAction, {}, signal),
        this.config.viewerKind
      );
    }
    async data(selection2, signal) {
      return normaliseReportData(
        await this.api.post(
          this.config.dataAction,
          {
            groupId: selection2.groupId,
            scope: selection2.scope,
            sourceKeys: selection2.sourceKeys
          },
          signal
        ),
        selection2
      );
    }
    /**
     * Read the teacher's due-topics overview (F3).
     *
     * Deliberately unnormalised here: the panel owns the shape of its own DTO.
     */
    async schedule(signal) {
      return this.api.post("schedule_overview", {}, signal);
    }
    /**
     * Read the misconception radar (F5).
     *
     * Deliberately unnormalised here: the panel owns the shape of its own DTO.
     */
    async misconceptions(signal) {
      return this.api.post("misconception_list", {}, signal);
    }
    errorMessage(error) {
      if (error instanceof LiveApiError && error.message !== "") {
        return error.message;
      }
      return this.config.strings["report:error:request"] || this.config.strings["live:error:request"] || "Der Bericht konnte nicht geladen werden.";
    }
  };

  // src/host/misconception-panel.ts
  var HINGE_STATUSES = [
    "insufficient",
    "reteach",
    "move_on"
  ];
  function normaliseHingeStatus(raw) {
    return typeof raw === "string" && HINGE_STATUSES.includes(raw) ? raw : null;
  }
  function hingeSentence(status, text3) {
    if (status === "move_on") {
      return text3(
        "host:hinge:moveon",
        "Genug verstanden \u2014 ihr k\xF6nnt weitergehen."
      );
    }
    if (status === "reteach") {
      return text3(
        "host:hinge:reteach",
        "Noch nicht sicher \u2014 diese Stelle lohnt eine Runde mehr."
      );
    }
    return text3(
      "host:hinge:insufficient",
      "Zu wenige Antworten f\xFCr eine Aussage."
    );
  }

  // src/report/misconception-panel.ts
  var count = (value2) => typeof value2 === "number" && Number.isFinite(value2) && value2 > 0 ? Math.floor(value2) : 0;
  var percent = (value2) => typeof value2 === "number" && Number.isFinite(value2) ? Math.max(0, Math.min(100, value2)) : 0;
  var plain = (value2) => typeof value2 === "string" ? value2 : "";
  function normaliseRadar(raw) {
    const empty = {
      minSample: 0,
      questions: [],
      siteThreshold: 0
    };
    if (raw === null || typeof raw !== "object") {
      return empty;
    }
    const record2 = raw;
    return {
      minSample: count(record2.minSample),
      questions: Array.isArray(record2.questions) ? record2.questions.map(normaliseRadarQuestion).filter((entry) => entry !== null) : [],
      siteThreshold: count(record2.siteThreshold)
    };
  }
  function normaliseRadarQuestion(raw) {
    if (raw === null || typeof raw !== "object") {
      return null;
    }
    const record2 = raw;
    const rootId = count(record2.rootId);
    if (rootId === 0) {
      return null;
    }
    return {
      answers: Array.isArray(record2.answers) ? record2.answers.map(normaliseRadarAnswer).filter((entry) => entry !== null) : [],
      correctPercent: percent(record2.correctPercent),
      hingeStatus: normaliseHingeStatus(record2.hingeStatus),
      qtype: plain(record2.qtype),
      rootId,
      sample: count(record2.sample),
      threshold: count(record2.threshold),
      title: plain(record2.title)
    };
  }
  function normaliseRadarAnswer(raw) {
    if (raw === null || typeof raw !== "object") {
      return null;
    }
    const record2 = raw;
    const answerKey = plain(record2.answerKey);
    if (answerKey === "") {
      return null;
    }
    return {
      answerKey,
      correct: record2.correct === true,
      count: count(record2.count),
      hint: plain(record2.hint),
      label: plain(record2.label),
      percent: percent(record2.percent),
      text: plain(record2.text)
    };
  }
  function renderMisconceptionPanel(radar, text3) {
    if (radar.questions.length === 0) {
      return null;
    }
    const section = liveElement("section", "quizgeist-report-section", {
      "data-quizgeist-view": "report-misconception"
    });
    section.setAttribute("aria-labelledby", "quizgeist-report-misconceptions");
    const heading = liveElement("h3", "quizgeist-report-section__title", {
      text: text3("report:misconception:title", "Fehlkonzept-Radar")
    });
    heading.id = "quizgeist-report-misconceptions";
    const hint = liveElement("p", "quizgeist-report-filter-hint", {
      text: text3(
        "report:misconception:hint",
        "Die Ampel vergleicht den richtigen Anteil mit der eingestellten Schwelle. Sie ist ein Gespr\xE4ch wert, kein Urteil."
      )
    });
    const list = liveElement("ul", "quizgeist-misconception-list");
    radar.questions.forEach((question) => {
      list.append(renderQuestion(question, text3));
    });
    section.append(heading, hint, list);
    return section;
  }
  function renderQuestion(question, text3) {
    const item = liveElement("li", "quizgeist-misconception-item");
    item.append(liveElement("p", "quizgeist-misconception-question", {
      text: question.title
    }));
    item.append(liveElement("p", "quizgeist-misconception-figure", {
      text: text3(
        "report:misconception:figure",
        "{$correct} % richtig aus {$sample} Antworten \xB7 Schwelle {$threshold} %",
        {
          correct: String(question.correctPercent),
          sample: String(question.sample),
          threshold: String(question.threshold)
        }
      )
    }));
    if (question.hingeStatus !== null) {
      const badge = liveElement("p", "quizgeist-hinge-badge", {
        "data-hinge-status": question.hingeStatus,
        role: "status"
      });
      badge.append(
        liveElement("span", "quizgeist-hinge-badge__mark", {
          "aria-hidden": "true",
          text: question.hingeStatus === "move_on" ? "\u25B2" : question.hingeStatus === "reteach" ? "\u25A0" : "\xB7"
        }),
        liveElement("span", "quizgeist-hinge-badge__text", {
          text: hingeSentence(question.hingeStatus, text3)
        })
      );
      item.append(badge);
    }
    const answers = liveElement("ul", "quizgeist-misconception-answers");
    question.answers.forEach((answer) => {
      answers.append(renderAnswer(answer, question.qtype, text3));
    });
    item.append(answers);
    return item;
  }
  function renderAnswer(answer, qtype2, text3) {
    const row = liveElement("li", "quizgeist-misconception-answer", {
      "data-misconception-correct": answer.correct ? "true" : "false"
    });
    row.append(liveElement("span", "quizgeist-misconception-answer__text", {
      text: answerLabel(answer, qtype2, text3)
    }));
    row.append(liveElement("span", "quizgeist-misconception-answer__figure", {
      text: text3(
        "report:misconception:share",
        "{$percent} % ({$count})",
        { count: String(answer.count), percent: String(answer.percent) }
      )
    }));
    row.append(liveElement("span", "quizgeist-misconception-answer__role", {
      text: answer.correct ? text3("report:misconception:correct", "richtige Antwort") : text3("report:misconception:distractor", "Distraktor")
    }));
    const bar = liveElement("span", "quizgeist-misconception-bar", {
      role: "img",
      "aria-label": text3("report:misconception:baralt", "Anteil dieser Antwort")
    });
    const fill = liveElement("span", "quizgeist-misconception-fill", {
      "data-misconception-state": answer.correct ? "correct" : "distractor"
    });
    fill.style.width = `${answer.percent}%`;
    bar.append(fill);
    row.append(bar);
    if (answer.label !== "") {
      row.append(liveElement("span", "quizgeist-misconception-label__text", {
        "data-misconception-label": true,
        text: answer.label
      }));
    }
    if (answer.hint !== "") {
      row.append(liveElement("span", "quizgeist-misconception-answer__hint", {
        text: answer.hint
      }));
    }
    return row;
  }
  function answerLabel(answer, qtype2, text3) {
    if (qtype2 === "truefalse") {
      return answer.answerKey === "true" ? text3("report:misconception:true", "Wahr") : text3("report:misconception:false", "Falsch");
    }
    return answer.text !== "" ? answer.text : text3("report:misconception:unnamed", "Antwort ohne Text");
  }

  // src/report/schedule-panel.ts
  var integer2 = (value2) => typeof value2 === "number" && Number.isFinite(value2) && value2 > 0 ? Math.floor(value2) : 0;
  function normaliseScheduleOverview(raw) {
    const empty = {
      generatedAt: 0,
      groups: [],
      totals: { dueCount: 0, learnerCount: 0, trackedCount: 0 }
    };
    if (raw === null || typeof raw !== "object") {
      return empty;
    }
    const record2 = raw;
    const totals = record2.totals !== null && typeof record2.totals === "object" ? record2.totals : {};
    return {
      generatedAt: integer2(record2.generatedAt),
      groups: Array.isArray(record2.groups) ? record2.groups.map(normaliseScheduleGroup).filter((entry) => entry !== null) : [],
      totals: {
        dueCount: integer2(totals.dueCount),
        learnerCount: integer2(totals.learnerCount),
        trackedCount: integer2(totals.trackedCount)
      }
    };
  }
  function normaliseScheduleGroup(raw) {
    if (raw === null || typeof raw !== "object") {
      return null;
    }
    const record2 = raw;
    return {
      colorKey: typeof record2.colorKey === "string" && record2.colorKey !== "" ? record2.colorKey : null,
      dueCount: integer2(record2.dueCount),
      duePercent: Math.max(0, Math.min(100, integer2(record2.duePercent))),
      label: typeof record2.label === "string" ? record2.label : "",
      learnerCount: integer2(record2.learnerCount),
      tagKey: typeof record2.tagKey === "string" ? record2.tagKey : "",
      trackedCount: integer2(record2.trackedCount),
      untagged: record2.untagged === true
    };
  }
  function renderSchedulePanel(overview, text3) {
    const section = liveElement("section", "quizgeist-report-section");
    section.setAttribute("aria-labelledby", "quizgeist-report-schedule");
    section.dataset.quizgeistView = "report-schedule";
    const heading = liveElement("h3", "quizgeist-report-section__title", {
      text: text3("schedule:overview:title", "F\xE4llige Themen")
    });
    heading.id = "quizgeist-report-schedule";
    section.append(heading);
    if (overview.groups.length === 0 || overview.totals.trackedCount === 0) {
      section.append(liveElement("p", "quizgeist-report-inline-empty", {
        role: "status",
        text: text3(
          "schedule:overview:empty",
          "Noch niemand hat ge\xFCbt \u2014 sobald Antworten eintreffen, entsteht hier der Wiederholungsplan."
        )
      }));
      return section;
    }
    section.append(liveElement("p", "quizgeist-report-filter-hint", {
      text: text3(
        "schedule:overview:summary",
        "{$due} f\xE4llige Wiederholungen bei {$learners} Lernenden, {$tracked} beobachtete Fragen.",
        {
          due: overview.totals.dueCount,
          learners: overview.totals.learnerCount,
          tracked: overview.totals.trackedCount
        }
      )
    }));
    const list = liveElement("ul", "quizgeist-schedule-list");
    overview.groups.forEach((group2) => {
      list.append(renderScheduleGroup(group2, text3));
    });
    section.append(list);
    return section;
  }
  function renderScheduleGroup(group2, text3) {
    const item = liveElement("li", "quizgeist-schedule-item");
    if (group2.colorKey !== null) {
      item.dataset.competenceColor = group2.colorKey;
    }
    const label = liveElement("span", "quizgeist-schedule-label", {
      text: group2.untagged || group2.label === "" ? text3("schedule:overview:untagged", "Ohne Thema") : group2.label
    });
    const figure = liveElement("span", "quizgeist-schedule-figure", {
      text: text3(
        "schedule:overview:groupvalue",
        "{$due} von {$tracked} f\xE4llig \xB7 {$percent} % aller F\xE4lligkeiten",
        {
          due: group2.dueCount,
          percent: group2.duePercent,
          tracked: group2.trackedCount
        }
      )
    });
    const bar = liveElement("span", "quizgeist-schedule-bar");
    bar.setAttribute("role", "img");
    bar.setAttribute(
      "aria-label",
      text3(
        "schedule:overview:barlabel",
        "{$label}: {$figure}",
        { figure: figure.textContent || "", label: label.textContent || "" }
      )
    );
    const fill = liveElement("span", "quizgeist-schedule-fill");
    fill.style.width = `${group2.duePercent}%`;
    if (group2.colorKey !== null) {
      fill.dataset.competenceColor = group2.colorKey;
    }
    fill.dataset.scheduleState = group2.duePercent >= 50 ? "high" : group2.duePercent >= 20 ? "medium" : "low";
    bar.append(fill);
    const meta = liveElement("span", "quizgeist-schedule-meta", {
      text: text3(
        "schedule:overview:learners",
        "{$count} Lernende betroffen",
        { count: group2.learnerCount }
      )
    });
    item.append(label, figure, bar, meta);
    return item;
  }

  // src/report/report-app.ts
  var ReportApp = class {
    constructor(root, config) {
      this.root = root;
      this.config = config;
      __publicField(this, "api");
      __publicField(this, "tts");
      __publicField(this, "bootstrap", null);
      __publicField(this, "combinedDraft", /* @__PURE__ */ new Set());
      __publicField(this, "content", null);
      __publicField(this, "controls", null);
      __publicField(this, "currentRequest", null);
      __publicField(this, "exportActions", null);
      __publicField(this, "liveRegion");
      __publicField(this, "panel", null);
      __publicField(this, "report", null);
      __publicField(this, "schedule", null);
      __publicField(this, "radar", null);
      __publicField(this, "selection", {
        groupId: 0,
        scope: "session",
        sourceKeys: []
      });
      __publicField(this, "sortDirection", "descending");
      __publicField(this, "sortKey", "points");
      __publicField(this, "stage");
      __publicField(this, "subtitleNode", null);
      __publicField(this, "tabs", null);
      __publicField(this, "titleNode", null);
      this.api = new ReportApi(config);
      this.tts = new TtsPlayer(config);
      this.liveRegion = liveElement("div", "quizgeist-live-visually-hidden", {
        "aria-atomic": "true",
        "aria-live": "polite",
        role: "status"
      });
      this.stage = liveElement("div", "quizgeist-report-stage");
    }
    async init() {
      this.root.classList.add("quizgeist-report-root");
      this.root.dataset.quizgeistRoot = "report";
      this.root.dataset.quizgeistTheme = this.config.theme || "hell";
      this.root.dataset.quizgeistSeason = this.config.season || "herbst";
      this.root.dataset.reportViewer = this.config.viewerKind;
      this.root.replaceChildren(this.liveRegion, this.stage);
      await this.loadBootstrap();
    }
    text(key, fallback, values = {}) {
      let resolved = this.config.strings[key] || fallback;
      Object.entries(values).forEach(([name, value2]) => {
        resolved = resolved.split(`{$a->${name}}`).join(String(value2));
        resolved = resolved.split(`{$${name}}`).join(String(value2));
      });
      if ("a" in values) {
        resolved = resolved.split("{$a}").join(String(values.a));
      }
      return resolved;
    }
    announce(message) {
      this.liveRegion.textContent = "";
      window.requestAnimationFrame(() => {
        this.liveRegion.textContent = message;
      });
    }
    replaceStage(content, focus = true) {
      this.stage.replaceChildren(content);
      if (focus) {
        window.setTimeout(() => {
          var _a;
          (_a = content.querySelector("[data-report-heading]")) == null ? void 0 : _a.focus();
        }, 0);
      }
    }
    loadingState(message) {
      const state = liveElement("section", "quizgeist-report-state", {
        "aria-live": "polite",
        role: "status"
      });
      state.append(
        liveElement("span", "quizgeist-report-spinner", { "aria-hidden": "true" }),
        liveElement("p", "", { text: message })
      );
      return state;
    }
    errorState(message, retry) {
      const state = liveElement(
        "section",
        "quizgeist-report-state quizgeist-report-state--error",
        { role: "alert" }
      );
      const heading = liveElement("h3", "", {
        "data-report-heading": true,
        tabindex: -1,
        text: this.text(
          "report:error:title",
          "Der Bericht konnte nicht geladen werden"
        )
      });
      const button = this.button(
        this.text("report:action:retry", "Erneut versuchen"),
        "secondary"
      );
      button.addEventListener("click", retry);
      state.append(heading, liveElement("p", "", { text: message }), button);
      return state;
    }
    button(label, variant = "primary") {
      return liveButton(
        label,
        `quizgeist-report-button quizgeist-report-button--${variant}`
      );
    }
    async loadBootstrap() {
      var _a;
      (_a = this.currentRequest) == null ? void 0 : _a.abort();
      const controller = new AbortController();
      this.currentRequest = controller;
      this.replaceStage(this.loadingState(this.text(
        "report:loading",
        "Berichtsquellen werden geladen \u2026"
      )), false);
      try {
        const bootstrap = await this.api.bootstrap(controller.signal);
        if (controller.signal.aborted) {
          return;
        }
        this.bootstrap = bootstrap;
        this.root.dataset.reportViewer = bootstrap.viewer.kind;
        this.selection = this.initialSelection(bootstrap);
        this.combinedDraft = new Set(
          this.selection.scope === "course" ? bootstrap.defaults.sourceKeys : this.selection.sourceKeys
        );
        this.renderLayout();
        await this.loadReport();
      } catch (error) {
        if (this.isAbortError(error)) {
          return;
        }
        this.replaceStage(this.errorState(
          this.api.errorMessage(error),
          () => void this.loadBootstrap()
        ));
      }
    }
    initialSelection(bootstrap) {
      var _a, _b;
      const available = new Set(bootstrap.sources.map((source2) => source2.key));
      const sourceKeys = bootstrap.defaults.sourceKeys.filter((key) => available.has(key));
      const initialScope = bootstrap.defaults.scope;
      const groups = initialScope === "course" ? bootstrap.courseGroups : bootstrap.groups;
      const defaultGroup = initialScope === "course" ? bootstrap.courseDefaultGroupId : bootstrap.defaults.groupId;
      const groupIds = new Set(groups.map((group2) => group2.id));
      const groupId = defaultGroup === 0 || groupIds.has(defaultGroup) ? defaultGroup : ((_a = groups[0]) == null ? void 0 : _a.id) || 0;
      if (initialScope === "course") {
        return { groupId, scope: "course", sourceKeys: [] };
      }
      if (initialScope === "combined") {
        const combinedKeys = [...sourceKeys];
        bootstrap.sources.forEach((source2) => {
          if (combinedKeys.length < 2 && !combinedKeys.includes(source2.key)) {
            combinedKeys.push(source2.key);
          }
        });
        return {
          groupId,
          scope: "combined",
          sourceKeys: combinedKeys
        };
      }
      return {
        groupId,
        scope: "session",
        sourceKeys: [
          sourceKeys[0] || ((_b = bootstrap.sources[0]) == null ? void 0 : _b.key) || ""
        ].filter((key) => key !== "")
      };
    }
    renderLayout() {
      const shell = liveElement("section", "quizgeist-report-view", {
        "aria-labelledby": "quizgeist-report-title",
        "data-report-view": true
      });
      const header = liveElement("header", "quizgeist-report-header");
      const headingGroup = liveElement("div", "quizgeist-report-header__copy");
      this.titleNode = liveElement("h3", "quizgeist-report-title", {
        "data-report-heading": true,
        id: "quizgeist-report-title",
        tabindex: -1,
        text: this.text("report:title", "Berichte")
      });
      this.subtitleNode = liveElement("p", "quizgeist-report-subtitle", {
        text: this.text(
          "report:subtitle",
          "Ergebnisse nach Session, Zuweisung oder Kurs auswerten."
        )
      });
      headingGroup.append(this.titleNode, this.subtitleNode);
      this.exportActions = liveElement("div", "quizgeist-report-export-actions", {
        "aria-label": this.text("report:export:label", "Bericht exportieren")
      });
      header.append(headingGroup, this.exportActions);
      this.tabs = liveElement("div", "quizgeist-report-tabs", {
        "aria-label": this.text("report:scope:label", "Berichtsumfang"),
        role: "tablist"
      });
      this.panel = liveElement("div", "quizgeist-report-panel", {
        id: "quizgeist-report-panel",
        role: "tabpanel"
      });
      this.controls = liveElement("section", "quizgeist-report-filters", {
        "aria-label": this.text("report:filters:label", "Bericht filtern")
      });
      this.content = liveElement("div", "quizgeist-report-content");
      this.panel.append(this.controls, this.content);
      shell.append(
        header,
        this.tabs,
        this.panel
      );
      this.replaceStage(shell);
      this.renderTabs();
      this.renderControls();
      this.renderExportActions();
    }
    renderTabs() {
      var _a;
      if (!this.tabs) {
        return;
      }
      const scopes = [
        {
          scope: "session",
          label: this.text("report:tab:session", "Session")
        }
      ];
      if (this.config.reportsAddonInstalled) {
        scopes.push({
          scope: "combined",
          label: this.text("report:tab:combined", "Kombiniert")
        }, {
          scope: "course",
          label: this.text("report:tab:course", "Kurs")
        });
      }
      const buttons = scopes.map(({ scope: scope2, label }) => {
        const selected = this.selection.scope === scope2;
        const button = this.button(label, "secondary");
        button.classList.add("quizgeist-report-tab");
        button.dataset.reportScope = scope2;
        button.id = `quizgeist-report-tab-${scope2}`;
        button.setAttribute("aria-controls", "quizgeist-report-panel");
        button.setAttribute("aria-selected", selected ? "true" : "false");
        button.setAttribute("role", "tab");
        button.tabIndex = selected ? 0 : -1;
        button.addEventListener("click", () => {
          void this.changeScope(scope2);
        });
        button.addEventListener("keydown", (event) => {
          var _a2;
          if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) {
            return;
          }
          event.preventDefault();
          const currentIndex = scopes.findIndex((entry) => entry.scope === scope2);
          const targetIndex = event.key === "Home" ? 0 : event.key === "End" ? scopes.length - 1 : (currentIndex + (event.key === "ArrowRight" ? 1 : -1) + scopes.length) % scopes.length;
          const targetScope = ((_a2 = scopes[targetIndex]) == null ? void 0 : _a2.scope) || "session";
          void this.changeScope(targetScope, true);
        });
        return button;
      });
      this.tabs.replaceChildren(...buttons);
      (_a = this.panel) == null ? void 0 : _a.setAttribute(
        "aria-labelledby",
        `quizgeist-report-tab-${this.selection.scope}`
      );
    }
    async changeScope(nextScope, focusTab = false) {
      var _a, _b;
      if (!this.bootstrap) {
        return;
      }
      const nextKeys = this.sourceKeysForScope(nextScope);
      const availableGroups = nextScope === "course" ? this.bootstrap.courseGroups : this.bootstrap.groups;
      const availableGroupIds = new Set(availableGroups.map(({ id }) => id));
      const nextDefault = nextScope === "course" ? this.bootstrap.courseDefaultGroupId : this.bootstrap.defaults.groupId;
      const canKeepAll = nextScope !== "course" || this.bootstrap.courseCanSelectAllGroups;
      const nextGroupId = this.selection.groupId === 0 && canKeepAll || availableGroupIds.has(this.selection.groupId) ? this.selection.groupId : nextDefault;
      this.selection = {
        ...this.selection,
        groupId: nextGroupId,
        scope: nextScope,
        sourceKeys: nextKeys
      };
      if (nextScope === "combined") {
        this.combinedDraft = new Set(nextKeys);
      }
      this.renderTabs();
      this.renderControls();
      this.renderExportActions();
      if (focusTab) {
        (_b = (_a = this.tabs) == null ? void 0 : _a.querySelector(
          `[data-report-scope="${nextScope}"]`
        )) == null ? void 0 : _b.focus();
      }
      await this.loadReport();
    }
    sourceKeysForScope(nextScope) {
      var _a, _b;
      const sources = ((_a = this.bootstrap) == null ? void 0 : _a.sources) || [];
      if (nextScope === "course") {
        return [];
      }
      if (nextScope === "session") {
        const current = this.selection.sourceKeys.find((key) => sources.some((source2) => source2.key === key));
        return [current || ((_b = sources[0]) == null ? void 0 : _b.key) || ""].filter((key) => key !== "");
      }
      const selected = [...this.combinedDraft].filter((key) => sources.some((source2) => source2.key === key));
      if (selected.length >= 2) {
        return selected;
      }
      const fallback = [.../* @__PURE__ */ new Set([
        ...selected,
        ...this.selection.sourceKeys.filter((key) => sources.some((source2) => source2.key === key))
      ])];
      sources.forEach((source2) => {
        if (fallback.length < 2 && !fallback.includes(source2.key)) {
          fallback.push(source2.key);
        }
      });
      return fallback;
    }
    renderControls() {
      if (!this.controls || !this.bootstrap) {
        return;
      }
      const wrapper = liveElement("div", "quizgeist-report-filter-grid");
      if (this.selection.scope === "course") {
        wrapper.append(liveElement("p", "quizgeist-report-filter-copy", {
          text: this.text(
            "report:scope:course:description",
            "Der Kursbericht fasst alle f\xFCr Sie sichtbaren Quizgeist-Aktivit\xE4ten zusammen."
          )
        }));
      } else {
        wrapper.append(this.renderSourceControl());
      }
      const groupControl = this.renderGroupControl();
      if (groupControl) {
        wrapper.append(groupControl);
      }
      this.controls.replaceChildren(wrapper);
    }
    renderSourceControl() {
      var _a;
      const combined = this.selection.scope === "combined";
      const fieldset = liveElement("fieldset", "quizgeist-report-source-fieldset");
      fieldset.append(liveElement("legend", "quizgeist-report-filter-label", {
        text: this.config.selfstudyAddonInstalled ? this.text(
          "report:filter:sources",
          combined ? "Sessions und Zuweisungen kombinieren" : "Session oder Zuweisung"
        ) : this.text("report:filter:sessions", "Sessions")
      }));
      const list = liveElement("div", "quizgeist-report-source-list");
      (((_a = this.bootstrap) == null ? void 0 : _a.sources) || []).forEach((source2) => {
        list.append(this.renderSourceOption(source2, combined));
      });
      if (list.childElementCount === 0) {
        list.append(liveElement("p", "quizgeist-report-filter-copy", {
          text: this.text(
            "report:sources:empty",
            "Es stehen noch keine Berichtsquellen zur Verf\xFCgung."
          )
        }));
      }
      fieldset.append(list);
      if (combined) {
        const actions = liveElement("div", "quizgeist-report-filter-actions");
        const apply = this.button(
          this.text("report:action:apply", "Auswahl anwenden")
        );
        apply.dataset.reportApply = "";
        apply.disabled = this.combinedDraft.size < 2;
        apply.addEventListener("click", () => {
          if (this.combinedDraft.size < 2) {
            return;
          }
          this.selection = {
            ...this.selection,
            sourceKeys: [...this.combinedDraft]
          };
          this.renderExportActions();
          void this.loadReport();
        });
        actions.append(
          liveElement("p", "quizgeist-report-filter-hint", {
            "aria-live": "polite",
            "data-report-source-count": true,
            text: this.text(
              "report:sources:selected",
              "{$count} Quellen ausgew\xE4hlt",
              { count: this.combinedDraft.size }
            )
          }),
          apply
        );
        fieldset.append(actions);
      }
      return fieldset;
    }
    renderSourceOption(source2, combined) {
      const label = liveElement("label", "quizgeist-report-source-option");
      const selected = combined ? this.combinedDraft.has(source2.key) : this.selection.sourceKeys[0] === source2.key;
      const input = liveElement("input", "", {
        checked: selected,
        name: combined ? `report-source-${source2.key}` : "report-source",
        type: combined ? "checkbox" : "radio",
        value: source2.key
      });
      input.dataset.reportSourceKey = source2.key;
      input.dataset.reportSource = source2.key;
      input.dataset.reportSourceKind = source2.kind;
      input.dataset.reportSourceId = String(source2.id);
      input.addEventListener("change", () => {
        var _a, _b;
        if (combined) {
          if (input.checked) {
            this.combinedDraft.add(source2.key);
          } else {
            this.combinedDraft.delete(source2.key);
          }
          const apply = (_a = this.controls) == null ? void 0 : _a.querySelector(
            "[data-report-apply]"
          );
          if (apply) {
            apply.disabled = this.combinedDraft.size < 2;
          }
          const count2 = (_b = this.controls) == null ? void 0 : _b.querySelector(
            "[data-report-source-count]"
          );
          if (count2) {
            count2.textContent = this.text(
              "report:sources:selected",
              "{$count} Quellen ausgew\xE4hlt",
              { count: this.combinedDraft.size }
            );
          }
          this.renderExportActions();
          return;
        }
        if (input.checked) {
          this.selection = {
            ...this.selection,
            sourceKeys: [source2.key]
          };
          this.renderExportActions();
          void this.loadReport();
        }
      });
      const copy = liveElement("span", "quizgeist-report-source-option__copy");
      copy.append(
        liveElement("strong", "", { text: source2.label }),
        liveElement("small", "", {
          text: this.sourceMeta(source2)
        })
      );
      label.append(input, copy);
      return label;
    }
    sourceMeta(source2) {
      const parts = [
        source2.instanceName,
        source2.kind === "assignment" ? this.text("report:source:assignment", "Zuweisung") : this.text("report:source:session", "Live-Session"),
        this.formatDate(source2.startedAtMs)
      ].filter((part) => part !== "");
      return parts.join(" \xB7 ");
    }
    renderGroupControl() {
      var _a, _b, _c, _d, _e, _f;
      const course = this.selection.scope === "course";
      const groups = course ? ((_a = this.bootstrap) == null ? void 0 : _a.courseGroups) || [] : ((_b = this.bootstrap) == null ? void 0 : _b.groups) || [];
      if (((_c = this.bootstrap) == null ? void 0 : _c.viewer.kind) === "student" || !((_d = this.bootstrap) == null ? void 0 : _d.viewer.canViewOthers) || groups.length === 0) {
        return null;
      }
      const maySelectAll = course ? Boolean((_e = this.bootstrap) == null ? void 0 : _e.courseCanSelectAllGroups) : ((_f = this.bootstrap) == null ? void 0 : _f.defaults.groupId) === 0 || this.selection.groupId === 0 || groups.some((group2) => group2.id === 0);
      const uniqueGroups = groups.filter((group2, index, all) => all.findIndex((candidate) => candidate.id === group2.id) === index);
      if (uniqueGroups.length === 0 && !maySelectAll) {
        return null;
      }
      const control = liveElement("div", "quizgeist-report-group-filter");
      const id = "quizgeist-report-group";
      const label = liveElement("label", "quizgeist-report-filter-label", {
        for: id,
        text: this.text("report:filter:group", "Gruppe")
      });
      const select = liveElement("select", "quizgeist-report-select", {
        id,
        "data-report-group": true
      });
      if (maySelectAll && !uniqueGroups.some((group2) => group2.id === 0)) {
        select.append(liveElement("option", "", {
          selected: this.selection.groupId === 0,
          text: this.text(
            "report:group:all",
            "Alle sichtbaren Gruppen"
          ),
          value: 0
        }));
      }
      uniqueGroups.forEach((group2) => {
        select.append(liveElement("option", "", {
          selected: this.selection.groupId === group2.id,
          text: group2.name,
          value: group2.id
        }));
      });
      select.addEventListener("change", () => {
        const groupId = Number(select.value);
        if (!Number.isInteger(groupId) || groupId < 0) {
          return;
        }
        this.selection = { ...this.selection, groupId };
        this.renderExportActions();
        void this.loadReport();
      });
      control.append(label, select);
      return control;
    }
    renderExportActions() {
      if (!this.exportActions || !this.bootstrap) {
        return;
      }
      const canExport = this.bootstrap.viewer.canExport && this.config.exportUrl !== "" && this.validSelection() && (this.selection.scope !== "combined" || this.combinedDraft.size >= 2);
      if (!canExport) {
        this.exportActions.replaceChildren();
        this.exportActions.hidden = true;
        return;
      }
      this.exportActions.hidden = false;
      const csv = this.exportLink("csv");
      this.exportActions.replaceChildren(
        ...this.config.reportsAddonInstalled ? [this.exportLink("xlsx"), csv] : [csv]
      );
    }
    exportLink(format) {
      const label = format === "csv" ? this.text("report:action:csv", "CSV herunterladen") : this.text("report:action:xlsx", "XLSX herunterladen");
      const link = liveElement("a", "quizgeist-report-button quizgeist-report-button--secondary", {
        "data-report-export": format,
        download: true,
        href: this.exportHref(format),
        text: label,
        ...format === "csv" ? {
          title: this.text(
            "report:action:csv:hint",
            "CSV verwendet Semikolon und deutsches Dezimalkomma; XLSX bleibt f\xFCr Tabellenkalkulationen empfohlen."
          )
        } : {}
      });
      return link;
    }
    exportHref(format) {
      const url = new URL(this.config.exportUrl, window.location.href);
      if (!url.searchParams.has("id")) {
        url.searchParams.set("id", String(this.config.cmid));
      }
      url.searchParams.set("format", format);
      url.searchParams.set("scope", this.selection.scope);
      url.searchParams.set("groupid", String(this.selection.groupId));
      url.searchParams.set("sesskey", this.config.sesskey);
      url.searchParams.delete("sourcekeys");
      url.searchParams.delete("sourcekeys[]");
      this.selection.sourceKeys.forEach((sourceKey) => {
        url.searchParams.append("sourcekeys[]", sourceKey);
      });
      return url.toString();
    }
    async loadReport() {
      var _a, _b;
      if (!this.content) {
        return;
      }
      this.tts.stop();
      if (!this.validSelection()) {
        (_a = this.currentRequest) == null ? void 0 : _a.abort();
        this.report = null;
        this.renderExportActions();
        this.content.replaceChildren(this.emptyState(this.text(
          "report:empty:selection",
          this.selection.scope === "combined" ? "W\xE4hlen Sie mindestens zwei Berichtsquellen aus." : "W\xE4hlen Sie eine Berichtsquelle aus."
        )));
        this.announce(this.text(
          "report:empty:description",
          "W\xE4hlen Sie gen\xFCgend Berichtsquellen aus."
        ));
        return;
      }
      (_b = this.currentRequest) == null ? void 0 : _b.abort();
      const controller = new AbortController();
      this.currentRequest = controller;
      this.content.replaceChildren(this.loadingState(this.text(
        "report:loading",
        "Bericht wird berechnet \u2026"
      )));
      this.announce(this.text("report:loading", "Bericht wird berechnet \u2026"));
      try {
        const report = await this.api.data(this.selection, controller.signal);
        if (controller.signal.aborted) {
          return;
        }
        this.report = report;
        this.selection = report.selection;
        if (this.selection.scope === "combined") {
          this.combinedDraft = new Set(this.selection.sourceKeys);
        }
        this.renderTabs();
        this.renderControls();
        this.renderExportActions();
        await this.loadSchedule(controller.signal);
        await this.loadRadar(controller.signal);
        this.renderReport(report);
        this.announce(this.text(
          "report:loaded",
          "Bericht \u201E{$title}\u201C wurde geladen.",
          { title: report.title || this.text("report:title", "Berichte") }
        ));
      } catch (error) {
        if (this.isAbortError(error)) {
          return;
        }
        this.report = null;
        this.content.replaceChildren(this.errorState(
          this.api.errorMessage(error),
          () => void this.loadReport()
        ));
        this.announce(this.api.errorMessage(error));
      }
    }
    /**
     * Fetch the teacher's due-topics overview, tolerating its absence.
     *
     * The action belongs to the self-study addon and additionally requires
     * mod/quizgeist:viewschedule. A missing action or a missing permission is
     * not an error the reader has to read about — the panel simply does not
     * appear, exactly like every other capability-bound surface.
     */
    /**
     * Fetch the F5 misconception radar, tolerating its absence.
     *
     * Same rule as the due-topics panel: the action belongs to the reports
     * addon and to mod/quizgeist:viewreports. A missing action is not an error
     * a reader has to read about — the panel simply does not appear.
     */
    async loadRadar(signal) {
      this.radar = null;
      if (!this.config.reportsAddonInstalled || this.config.viewerKind !== "teacher") {
        return;
      }
      try {
        this.radar = normaliseRadar(await this.api.misconceptions(signal));
      } catch (_error) {
        this.radar = null;
      }
    }
    async loadSchedule(signal) {
      this.schedule = null;
      if (!this.config.selfstudyAddonInstalled || this.config.viewerKind !== "teacher") {
        return;
      }
      try {
        this.schedule = normaliseScheduleOverview(
          await this.api.schedule(signal)
        );
      } catch (_error) {
        this.schedule = null;
      }
    }
    renderReport(report) {
      if (!this.content) {
        return;
      }
      if (this.titleNode) {
        this.titleNode.textContent = report.title || this.text("report:title", "Berichte");
      }
      if (this.subtitleNode) {
        this.subtitleNode.textContent = report.subtitle || this.selectionSummary();
      }
      if (this.isEmpty(report)) {
        this.content.replaceChildren(this.emptyState());
        return;
      }
      const fragment = document.createDocumentFragment();
      fragment.append(this.renderKpis(report));
      fragment.append(liveElement("p", "quizgeist-report-filter-hint", {
        text: this.text(
          "report:kpis:gradingnote",
          "Diese Nutzungskennzahlen sind unabh\xE4ngig von der Moodle-Notenberechnung."
        )
      }));
      if (report.omittedSources.length > 0) {
        fragment.append(liveElement("p", "quizgeist-report-filter-hint", {
          role: "status",
          text: this.text(
            "report:sources:omitted",
            "{$count} Aktivit\xE4ten wurden ausgelassen, weil die gew\xE4hlte Gruppe dort nicht verf\xFCgbar ist.",
            { count: report.omittedSources.length }
          )
        }));
      }
      if (this.selection.scope === "course") {
        fragment.append(this.renderTimeline(report.timeline));
      }
      const competences = renderCompetencePanel(
        report.competences,
        (key, fallback, values) => this.text(key, fallback, values)
      );
      if (competences !== null) {
        fragment.append(competences);
      }
      if (this.schedule !== null) {
        fragment.append(renderSchedulePanel(
          this.schedule,
          (key, fallback, values) => this.text(key, fallback, values)
        ));
      }
      if (this.radar !== null) {
        const radar = renderMisconceptionPanel(
          this.radar,
          (key, fallback, values) => this.text(key, fallback, values)
        );
        if (radar !== null) {
          fragment.append(radar);
        }
      }
      fragment.append(
        this.renderQuestions(report.questions),
        this.renderParticipants(report),
        this.renderOpenReview(report)
      );
      this.content.replaceChildren(fragment);
    }
    selectionSummary() {
      var _a;
      const selected = this.selection.sourceKeys.length;
      if (this.selection.scope === "course") {
        return this.text(
          "report:scope:course:summary",
          "Alle sichtbaren Quizgeist-Aktivit\xE4ten des Kurses"
        );
      }
      if (this.selection.scope === "combined") {
        return this.text(
          "report:scope:combined:summary",
          "{$count} Quellen kombiniert",
          { count: selected }
        );
      }
      const source2 = (_a = this.bootstrap) == null ? void 0 : _a.sources.find(
        (entry) => entry.key === this.selection.sourceKeys[0]
      );
      return (source2 == null ? void 0 : source2.label) || this.text("report:tab:session", "Session");
    }
    isEmpty(report) {
      return report.questions.length === 0 && report.participants.length === 0 && report.openResponses.length === 0 && report.moderationTrail.length === 0 && report.timeline.length === 0;
    }
    emptyState(description = "") {
      const state = liveElement("section", "quizgeist-report-empty", {
        "data-report-empty": true
      });
      const heading = liveElement("h4", "", {
        "data-report-heading": true,
        tabindex: -1,
        text: this.text("report:empty:title", "Noch keine Berichtsdaten")
      });
      state.append(
        liveElement("span", "quizgeist-report-empty__illustration", {
          "aria-hidden": "true"
        }),
        heading,
        liveElement("p", "", {
          text: description || this.text(
            "report:empty:description",
            "Nach der ersten Session oder Zuweisung erscheinen die Ergebnisse hier."
          )
        })
      );
      return state;
    }
    renderKpis(report) {
      const summary2 = report.summary;
      const list = liveElement("dl", "quizgeist-report-kpis", {
        "aria-label": this.text("report:kpis:label", "Kennzahlen")
      });
      const pointsPercent = this.formatPercent(summary2.pointsPercent);
      list.append(this.kpi(
        "points-percent",
        this.text("report:kpi:averagepoints", "Punktequote"),
        pointsPercent,
        summary2.pointsPercent
      ));
      const averageRate = this.formatPercent(summary2.averageCorrectRate);
      list.append(this.kpi(
        "correct-rate",
        this.text("report:kpi:correctrate", "\xD8 Richtigquote"),
        averageRate,
        summary2.averageCorrectRate
      ));
      const participation = summary2.eligible > 0 ? `${this.formatInteger(summary2.participating)} / ${this.formatInteger(summary2.eligible)}` : this.formatInteger(summary2.participating);
      list.append(this.kpi(
        "participation",
        this.text("report:kpi:participation", "Teilnahme"),
        participation,
        summary2.participating
      ));
      if (this.config.reportsAddonInstalled) {
        const hardest = summary2.hardestQuestion;
        const hardestCard = this.kpi(
          "hardest-question",
          this.text("report:kpi:hardest", "Schwierigste Frage"),
          (hardest == null ? void 0 : hardest.title) || "\u2013",
          (hardest == null ? void 0 : hardest.rootId) || null,
          (hardest == null ? void 0 : hardest.correctRate) === null || (hardest == null ? void 0 : hardest.correctRate) === void 0 ? "" : this.text(
            "report:kpi:hardest:rate",
            "{$rate} richtig",
            { rate: this.formatPercent(hardest.correctRate) }
          )
        );
        if (hardest) {
          const questionIndex = report.questions.findIndex((question) => question.rootKey === hardest.rootKey || question.rootId === hardest.rootId);
          const value2 = hardestCard.querySelector(
            ".quizgeist-report-kpi__value"
          );
          if (value2 && questionIndex >= 0) {
            const link = liveElement("a", "quizgeist-report-kpi__link", {
              href: `#quizgeist-report-question-${questionIndex}`,
              text: hardest.title
            });
            value2.replaceChildren(link);
          }
        }
        list.append(hardestCard);
      }
      return list;
    }
    renderTimeline(entries) {
      const section = liveElement("section", "quizgeist-report-section", {
        "aria-labelledby": "quizgeist-report-timeline-title",
        "data-report-timeline": true
      });
      section.append(liveElement("h4", "quizgeist-report-section__title", {
        id: "quizgeist-report-timeline-title",
        text: this.text(
          "report:timeline:title",
          "Entwicklung im Kurs"
        )
      }));
      if (entries.length === 0) {
        section.append(liveElement("p", "quizgeist-report-inline-empty", {
          text: this.text(
            "report:timeline:empty",
            "F\xFCr diesen Kurs liegen noch keine Verlaufsdaten vor."
          )
        }));
        return section;
      }
      const region = liveElement("div", "quizgeist-report-table-region", {
        "aria-label": this.text(
          "report:timeline:tablelabel",
          "Entwicklung \xFCber Quizgeist-Aktivit\xE4ten"
        ),
        role: "region",
        tabindex: 0
      });
      const table = liveElement(
        "table",
        "quizgeist-report-table quizgeist-report-table--timeline",
        { "data-report-timeline-table": true }
      );
      table.append(liveElement("caption", "quizgeist-live-visually-hidden", {
        text: this.text(
          "report:timeline:caption",
          "Durchschnittswerte je Quizgeist-Quelle in zeitlicher Reihenfolge"
        )
      }));
      const head = liveElement("thead");
      const headerRow = liveElement("tr");
      [
        this.text("report:timeline:source", "Quelle"),
        this.text("report:timeline:date", "Datum"),
        this.text("report:timeline:averagepoints", "Punktequote"),
        this.text("report:timeline:correctrate", "\xD8 Richtigquote"),
        this.text("report:timeline:participants", "Teilnehmende")
      ].forEach((label) => {
        headerRow.append(liveElement("th", "", {
          scope: "col",
          text: label
        }));
      });
      head.append(headerRow);
      const body = liveElement("tbody");
      entries.forEach((entry) => {
        body.append(this.timelineRow(entry));
      });
      table.append(head, body);
      region.append(table);
      section.append(region);
      return section;
    }
    timelineRow(entry) {
      const row = liveElement("tr", "", {
        "data-report-instance-name": entry.instanceName,
        "data-report-quizgeist-id": entry.quizgeistId > 0 ? entry.quizgeistId : "",
        "data-report-source-key": entry.sourceKey,
        "data-report-source-kind": entry.kind,
        "data-report-timeline-row": true
      });
      const source2 = liveElement("th", "quizgeist-report-timeline-source", {
        "data-report-timeline-source": true,
        scope: "row"
      });
      source2.append(liveElement("strong", "", { text: entry.sourceLabel }));
      if (entry.instanceName !== "" && entry.instanceName !== entry.sourceLabel) {
        source2.append(liveElement("small", "", { text: entry.instanceName }));
      }
      row.append(
        source2,
        liveElement("td", "quizgeist-report-timeline-date", {
          "data-report-timeline-date": true,
          text: this.formatDate(entry.timestampMs) || "\u2013"
        }),
        liveElement("td", "quizgeist-report-table__number", {
          "data-report-timeline-points": true,
          text: this.formatPercent(entry.pointsPercent)
        }),
        liveElement("td", "quizgeist-report-table__number", {
          "data-report-timeline-correct": true,
          text: this.formatPercent(entry.averageCorrectRate)
        }),
        liveElement("td", "quizgeist-report-table__number", {
          "data-report-timeline-participants": true,
          text: this.formatInteger(entry.participantCount)
        })
      );
      return row;
    }
    kpi(key, label, value2, rawValue, detail = "") {
      const card = liveElement("div", "quizgeist-report-kpi", {
        "data-report-kpi": key,
        "data-report-value": rawValue === null ? "" : rawValue
      });
      card.append(
        liveElement("dt", "quizgeist-report-kpi__label", { text: label }),
        liveElement("dd", "quizgeist-report-kpi__value", { text: value2 })
      );
      if (detail !== "") {
        card.append(liveElement("span", "quizgeist-report-kpi__detail", {
          text: detail
        }));
      }
      return card;
    }
    renderQuestions(questions) {
      const section = liveElement("section", "quizgeist-report-section", {
        "aria-labelledby": "quizgeist-report-questions-title"
      });
      section.append(liveElement("h4", "quizgeist-report-section__title", {
        id: "quizgeist-report-questions-title",
        text: this.text("report:section:questions", "Fragen im Detail")
      }));
      section.append(liveElement("p", "quizgeist-report-filter-hint", {
        text: this.text(
          "report:questions:missingnote",
          "Fehlend z\xE4hlt nur Personen, die anhand von Beitritts- und Aktivit\xE4tszeiten bei der Frage anwesend waren; fehlende wertbare Antworten z\xE4hlen als falsch."
        )
      }));
      section.append(liveElement("p", "quizgeist-report-filter-hint", {
        text: this.text(
          "report:questions:medianote",
          "Bilder und andere Medien sind in Berichtsdatens\xE4tzen bewusst nicht enthalten. \xD6ffnen Sie die Frage im Editor, um den vollst\xE4ndigen Medienkontext zu sehen."
        )
      }));
      if (questions.length === 0) {
        section.append(liveElement("p", "quizgeist-report-inline-empty", {
          text: this.text(
            "report:questions:empty",
            "F\xFCr diese Auswahl liegen keine Fragen vor."
          )
        }));
        return section;
      }
      const list = liveElement("div", "quizgeist-report-question-list");
      questions.forEach((question, index) => {
        list.append(this.renderQuestion(question, index));
      });
      section.append(list);
      return section;
    }
    renderQuestion(question, index) {
      const details = liveElement("details", "quizgeist-report-question", {
        "data-report-root-id": question.rootId,
        "data-report-root-key": question.rootKey,
        id: `quizgeist-report-question-${index}`,
        open: index === 0
      });
      const summary2 = liveElement("summary", "quizgeist-report-question__summary");
      const heading = liveElement("span", "quizgeist-report-question__heading");
      heading.append(
        liveElement("strong", "", { text: question.title }),
        liveElement("small", "", {
          text: this.text(
            "report:question:root",
            "Fragenstamm {$root}",
            { root: question.rootId }
          )
        })
      );
      const badges = liveElement("span", "quizgeist-report-question__badges");
      if (this.config.reportsAddonInstalled && question.difficult) {
        badges.append(liveElement("span", "quizgeist-report-difficult", {
          text: this.text("report:question:difficult", "Schwierige Frage")
        }));
      }
      badges.append(liveElement("span", "quizgeist-report-question__rate", {
        text: this.formatPercent(question.correctRate)
      }));
      summary2.append(heading, badges);
      const body = liveElement("div", "quizgeist-report-question__body");
      body.append(
        this.renderQuestionMetrics(question),
        this.renderVersionTrail(question)
      );
      const occurrences = liveElement("div", "quizgeist-report-occurrences");
      if (question.occurrences.length === 0) {
        occurrences.append(liveElement("p", "quizgeist-report-inline-empty", {
          text: this.text(
            "report:occurrences:empty",
            "F\xFCr diese Frage liegt keine Antwortverteilung vor."
          )
        }));
      } else {
        question.occurrences.forEach((occurrence2) => {
          occurrences.append(this.renderOccurrence(occurrence2));
        });
      }
      body.append(occurrences);
      details.append(summary2, body);
      return details;
    }
    renderQuestionMetrics(question) {
      const metrics = liveElement("dl", "quizgeist-report-question-metrics");
      const entries = [
        {
          label: this.text("report:question:points", "Punkte"),
          value: question.maxPoints > 0 ? `${this.formatNumber(question.points)} / ${this.formatNumber(question.maxPoints)}` : this.formatNumber(question.points)
        },
        {
          label: this.text("report:question:correct", "Richtig"),
          value: `${this.formatInteger(question.correctCount)} / ${this.formatInteger(question.gradedCount)}`
        },
        {
          label: this.text("report:question:answers", "Antworten"),
          value: this.formatInteger(question.responseCount)
        },
        {
          label: this.text("report:question:missing", "Fehlend"),
          value: this.formatInteger(question.missingCount)
        },
        {
          label: this.text("report:question:time", "\xD8 Antwortzeit"),
          value: this.formatDuration(question.averageResponseTimeMs)
        }
      ];
      entries.forEach((entry) => {
        const item = liveElement("div", "");
        item.append(
          liveElement("dt", "", { text: entry.label }),
          liveElement("dd", "", { text: entry.value })
        );
        metrics.append(item);
      });
      return metrics;
    }
    renderVersionTrail(question) {
      const section = liveElement("section", "quizgeist-report-versions", {
        "aria-label": this.text(
          "report:versions:label",
          "Gespielte Frageversionen"
        )
      });
      section.append(liveElement("h5", "", {
        text: this.text("report:question:versions", "Gespielte Versionen")
      }));
      const list = liveElement("ul", "quizgeist-report-version-list");
      question.versions.forEach((version2) => {
        const item = liveElement("li", "quizgeist-report-version", {
          "data-report-question-id": version2.questionId,
          "data-report-version": version2.version
        });
        item.append(
          liveElement("strong", "", {
            text: this.text(
              "report:version:label",
              "Version {$version}",
              { version: version2.version }
            )
          }),
          document.createTextNode(` \xB7 ${this.qtypeLabel(version2.qtype)}`),
          liveElement("small", "", {
            text: this.text(
              "report:version:id",
              "Frage-ID {$id}",
              { id: version2.questionId }
            )
          })
        );
        list.append(item);
      });
      if (list.childElementCount === 0) {
        list.append(liveElement("li", "quizgeist-report-inline-empty", {
          text: this.text(
            "report:versions:empty",
            "Keine Versionsangaben vorhanden."
          )
        }));
      }
      section.append(list);
      return section;
    }
    renderOccurrence(occurrence2) {
      var _a;
      const source2 = (_a = this.bootstrap) == null ? void 0 : _a.sources.find(
        (entry) => entry.key === occurrence2.sourceKey
      );
      const sourceLabel = occurrence2.sourceLabel || (source2 == null ? void 0 : source2.label) || occurrence2.sourceKey;
      const article = liveElement("article", "quizgeist-report-occurrence", {
        "data-report-occurrence": true,
        "data-report-question-id": occurrence2.questionId,
        "data-report-projection-key": occurrence2.projectionKey,
        "data-report-source-key": occurrence2.sourceKey,
        "data-report-version": occurrence2.version,
        "data-report-authoritative": occurrence2.authoritative,
        "data-report-occurrence-count": occurrence2.occurrenceCount,
        "data-report-stage": occurrence2.stage
      });
      const heading = liveElement("header", "quizgeist-report-occurrence__header");
      heading.append(
        liveElement("h5", "", { text: sourceLabel }),
        liveElement("p", "", {
          text: [
            this.text(
              "report:version:label",
              "Version {$version}",
              { version: occurrence2.version }
            ),
            occurrence2.authoritative ? this.text(
              "report:visit:authoritative",
              "wertungsrelevant"
            ) : this.text(
              "report:visit:notauthoritative",
              "neutralisiert \u2013 ohne Kennzahlwirkung"
            ),
            this.text(
              "report:occurrences:count",
              "{$count} Vorkommen",
              { count: occurrence2.occurrenceCount }
            )
          ].join(" \xB7 ")
        }),
        liveElement("p", "quizgeist-report-occurrence__question", {
          text: occurrence2.question.questionText
        }),
        createTtsControl(
          this.tts,
          questionSpeechText(occurrence2.question),
          this.config
        )
      );
      const aggregate2 = liveElement("div", "quizgeist-report-occurrence__aggregate");
      try {
        aggregate2.append(renderLiveAggregate(
          occurrence2.question,
          occurrence2.aggregate,
          {
            aggregate: occurrence2.aggregate,
            answer: null,
            audience: "player",
            interactive: false,
            nowMs: Date.now(),
            text: (key, fallback, values = {}) => this.text(key, fallback, values)
          }
        ));
      } catch (_error) {
        aggregate2.append(liveElement("p", "quizgeist-report-inline-empty", {
          text: this.text(
            "report:aggregate:error",
            "Die Antwortverteilung konnte nicht dargestellt werden."
          )
        }));
      }
      article.append(heading, aggregate2);
      if (occurrence2.authoritative) {
        article.append(renderQuestionSolution(occurrence2.question, {
          text: (key, fallback, values = {}) => this.text(key, fallback, values)
        }));
      }
      return article;
    }
    renderParticipants(report) {
      var _a, _b;
      const section = liveElement("section", "quizgeist-report-section", {
        "aria-labelledby": "quizgeist-report-participants-title",
        "data-report-participants": true
      });
      const ownOnly = ((_a = this.bootstrap) == null ? void 0 : _a.viewer.kind) === "student" || !((_b = this.bootstrap) == null ? void 0 : _b.viewer.canViewOthers);
      section.append(liveElement("h4", "quizgeist-report-section__title", {
        id: "quizgeist-report-participants-title",
        text: ownOnly ? this.text("report:participants:own", "Meine Ergebnisse") : this.text("report:section:participants", "Teilnehmende")
      }));
      if (report.participants.length === 0) {
        section.append(liveElement("p", "quizgeist-report-inline-empty", {
          text: this.text(
            "report:participants:empty",
            "F\xFCr diese Auswahl liegen keine Teilnehmendendaten vor."
          )
        }));
        return section;
      }
      const region = liveElement("div", "quizgeist-report-table-region", {
        "aria-label": this.text(
          "report:participants:tablelabel",
          "Ergebnisse der Teilnehmenden"
        ),
        role: "region",
        tabindex: 0
      });
      const table = liveElement("table", "quizgeist-report-table");
      table.append(liveElement("caption", "quizgeist-live-visually-hidden", {
        text: this.text(
          "report:participants:caption",
          "Name, Nutzerkennung, Punkte, Richtigquote und Antwortzeiten"
        )
      }));
      const head = liveElement("thead");
      const headerRow = liveElement("tr");
      const definitions = [
        {
          key: "displayName",
          label: ownOnly ? this.text("report:participant:self", "Ergebnis") : this.text("report:table:name", "Name")
        },
        {
          key: "userIdentifier",
          label: this.text("report:table:useridentifier", "Nutzerkennung")
        },
        {
          key: "points",
          label: this.text("report:table:points", "Punkte")
        },
        {
          key: "correctRate",
          label: this.text("report:table:correctrate", "Richtigquote")
        },
        {
          key: "averageResponseTimeMs",
          label: this.text("report:table:averagetime", "\xD8 Antwortzeit")
        },
        {
          key: "sourceCount",
          label: this.text("report:table:sources", "Quellen")
        }
      ];
      definitions.forEach((definition) => {
        headerRow.append(this.sortHeader(definition));
      });
      head.append(headerRow);
      const body = liveElement("tbody");
      this.sortedParticipants(report.participants).forEach((participant2) => {
        body.append(this.participantRow(participant2));
      });
      table.append(head, body);
      region.append(table);
      section.append(region);
      return section;
    }
    sortHeader(definition) {
      const active = this.sortKey === definition.key;
      const header = liveElement("th", "", {
        "aria-sort": active ? this.sortDirection : "none",
        scope: "col"
      });
      const button = liveButton(
        definition.label,
        "quizgeist-report-sort",
        { "data-report-sort": definition.key }
      );
      if (active) {
        button.append(liveElement("span", "quizgeist-report-sort__direction", {
          "aria-hidden": "true",
          text: this.sortDirection === "ascending" ? "\u2191" : "\u2193"
        }));
      }
      button.addEventListener("click", () => {
        this.changeSort(definition);
      });
      header.append(button);
      return header;
    }
    changeSort(definition) {
      var _a;
      if (this.sortKey === definition.key) {
        this.sortDirection = this.sortDirection === "ascending" ? "descending" : "ascending";
      } else {
        this.sortKey = definition.key;
        this.sortDirection = ["displayName", "userIdentifier"].includes(
          definition.key
        ) ? "ascending" : "descending";
      }
      if (!this.report || !this.content) {
        return;
      }
      const previous = this.content.querySelector(
        "[data-report-participants]"
      );
      if (previous) {
        previous.replaceWith(this.renderParticipants(this.report));
        (_a = this.content.querySelector(
          `[data-report-sort="${definition.key}"]`
        )) == null ? void 0 : _a.focus();
      }
      this.announce(this.text(
        "report:participants:sorted",
        "Tabelle nach {$column} {$direction} sortiert.",
        {
          column: definition.label,
          direction: this.sortDirection === "ascending" ? this.text("report:sort:ascending", "aufsteigend") : this.text("report:sort:descending", "absteigend")
        }
      ));
    }
    sortedParticipants(participants) {
      const direction = this.sortDirection === "ascending" ? 1 : -1;
      return [...participants].sort((left, right) => {
        if (this.sortKey === "displayName" || this.sortKey === "userIdentifier") {
          const result = left[this.sortKey].localeCompare(
            right[this.sortKey],
            this.config.locale,
            { sensitivity: "base" }
          );
          return result === 0 ? left.userId - right.userId : direction * result;
        }
        const leftValue = left[this.sortKey];
        const rightValue = right[this.sortKey];
        const leftNumber = typeof leftValue === "number" ? leftValue : -1;
        const rightNumber = typeof rightValue === "number" ? rightValue : -1;
        if (leftNumber === rightNumber) {
          return left.displayName.localeCompare(
            right.displayName,
            this.config.locale,
            { sensitivity: "base" }
          ) || left.userIdentifier.localeCompare(
            right.userIdentifier,
            this.config.locale,
            { sensitivity: "base" }
          ) || left.userId - right.userId;
        }
        return direction * (leftNumber - rightNumber);
      });
    }
    participantRow(participant2) {
      const row = liveElement("tr", "", {
        "data-report-participant": true,
        "data-report-user-id": participant2.userId > 0 ? participant2.userId : "",
        "data-report-user-identifier": participant2.userIdentifier
      });
      const name = liveElement("th", "quizgeist-report-table__name", {
        scope: "row"
      });
      const profileUrl = this.profileUrl(participant2.profileUrl);
      if (profileUrl) {
        name.append(liveElement("a", "", {
          href: profileUrl,
          text: participant2.displayName
        }));
      } else {
        name.textContent = participant2.displayName;
      }
      const pointValue = participant2.maxPoints > 0 ? `${this.formatNumber(participant2.points)} / ${this.formatNumber(participant2.maxPoints)}` : this.formatNumber(participant2.points);
      row.append(
        name,
        liveElement("td", "quizgeist-report-table__identifier", {
          "data-report-user-identifier-cell": true,
          text: participant2.userIdentifier
        }),
        liveElement("td", "quizgeist-report-table__number", { text: pointValue }),
        liveElement("td", "quizgeist-report-table__number", {
          text: this.formatPercent(participant2.correctRate)
        }),
        liveElement("td", "quizgeist-report-table__number", {
          text: this.formatDuration(participant2.averageResponseTimeMs)
        }),
        liveElement("td", "quizgeist-report-table__number", {
          text: this.formatInteger(participant2.sourceCount)
        })
      );
      return row;
    }
    profileUrl(value2) {
      var _a;
      if (!value2 || ((_a = this.bootstrap) == null ? void 0 : _a.viewer.kind) !== "teacher" || !this.bootstrap.viewer.canViewOthers || !this.bootstrap.viewer.canViewProfiles) {
        return "";
      }
      try {
        const url = new URL(value2, window.location.href);
        return url.origin === window.location.origin && ["http:", "https:"].includes(url.protocol) ? url.toString() : "";
      } catch (_error) {
        return "";
      }
    }
    renderOpenReview(report) {
      const section = liveElement("section", "quizgeist-report-section", {
        "aria-labelledby": "quizgeist-report-review-title",
        "data-report-review": true
      });
      section.append(liveElement("h4", "quizgeist-report-section__title", {
        id: "quizgeist-report-review-title",
        text: this.text(
          this.config.reportsAddonInstalled ? "report:open:title" : "report:open:title:base",
          this.config.reportsAddonInstalled ? "Offene Antworten und Moderation" : "Offene Antworten"
        )
      }));
      if (report.openResponses.length === 0) {
        section.append(liveElement("p", "quizgeist-report-inline-empty", {
          text: this.text(
            "report:open:empty",
            "F\xFCr diese Auswahl liegen keine offenen Antworten vor."
          )
        }));
      } else {
        const list = liveElement("div", "quizgeist-report-review-list");
        report.openResponses.forEach((response) => {
          list.append(this.openResponseRow(response));
        });
        section.append(list);
        if (report.openResponsesTruncated) {
          section.append(liveElement("p", "quizgeist-report-filter-hint", {
            text: this.text(
              "report:review:truncated",
              "Die Ansicht ist begrenzt. Der Export enth\xE4lt alle offenen Antworten."
            )
          }));
        }
      }
      if (!this.config.reportsAddonInstalled) {
        return section;
      }
      const moderation = liveElement("section", "quizgeist-report-moderation", {
        "aria-labelledby": "quizgeist-report-moderation-title"
      });
      moderation.append(liveElement("h5", "", {
        id: "quizgeist-report-moderation-title",
        text: this.text("report:moderation:title", "Moderationsspur")
      }));
      if (report.moderationTrail.length === 0) {
        moderation.append(liveElement("p", "quizgeist-report-inline-empty", {
          text: this.text(
            "report:moderation:empty",
            "Keine Moderationsschritte vorhanden."
          )
        }));
      } else {
        const list = liveElement("ol", "quizgeist-report-moderation-list");
        report.moderationTrail.forEach((entry) => {
          list.append(this.moderationRow(entry));
        });
        moderation.append(list);
        if (report.moderationTrailTruncated) {
          moderation.append(liveElement("p", "quizgeist-report-filter-hint", {
            text: this.text(
              "report:moderation:truncated",
              "Die Ansicht ist begrenzt. Der Export enth\xE4lt die vollst\xE4ndige Moderationsspur."
            )
          }));
        }
      }
      section.append(moderation);
      return section;
    }
    openResponseRow(response) {
      const row = liveElement("article", "quizgeist-report-review-row", {
        "data-report-question-id": response.questionId,
        "data-report-open": true,
        "data-report-review-row": true,
        "data-report-root-id": response.rootId,
        "data-report-source-key": response.sourceKey,
        "data-report-user-id": response.userId > 0 ? response.userId : "",
        "data-report-user-identifier": response.userIdentifier,
        "data-report-version": response.version
      });
      const header = liveElement("header", "quizgeist-report-review-row__header");
      const title = response.questionTitle || this.text(
        "report:review:question",
        "Frage {$id}",
        { id: response.questionId }
      );
      header.append(
        liveElement("strong", "", { text: title }),
        liveElement("span", "quizgeist-report-status", {
          text: this.statusLabel(response.status)
        })
      );
      const meta = [
        response.sourceLabel || response.sourceKey,
        this.qtypeLabel(response.kind),
        `${this.text(
          "report:version:label",
          "Version {$version}",
          { version: response.version }
        )}`,
        response.displayName,
        response.userIdentifier,
        this.formatDate(response.timeCreatedMs)
      ].filter((entry) => entry !== "");
      row.append(
        header,
        liveElement("p", "quizgeist-report-review-row__meta", {
          text: meta.join(" \xB7 ")
        }),
        liveElement("blockquote", "quizgeist-report-review-row__text", {
          text: response.text
        })
      );
      if (!response.metricEligible) {
        row.append(liveElement("p", "quizgeist-report-review-row__note", {
          text: this.text(
            "report:review:notmetric",
            "Moderationszeile \u2013 nicht in Sch\xFClerkennzahlen enthalten."
          )
        }));
      }
      return row;
    }
    moderationRow(entry) {
      const row = liveElement("li", "quizgeist-report-moderation-row", {
        "data-report-moderation-row": true,
        "data-report-moderation": true,
        "data-report-question-id": entry.questionId,
        "data-report-root-id": entry.rootId,
        "data-report-source-key": entry.sourceKey,
        "data-report-version": entry.version
      });
      const action = this.moderationLabel(entry.action);
      const actor = entry.actorName || this.text(
        "report:moderation:system",
        "Moderation"
      );
      const target = [entry.targetType, entry.targetKey].filter((value2) => value2 !== "").join(" \xB7 ");
      row.append(
        liveElement("strong", "", { text: action }),
        liveElement("p", "", {
          text: [
            entry.sourceLabel || entry.sourceKey,
            actor,
            target,
            this.formatDate(entry.timeCreatedMs)
          ].filter((value2) => value2 !== "").join(" \xB7 ")
        })
      );
      return row;
    }
    statusLabel(status) {
      const fallbacks = {
        approved: "Freigegeben",
        deleted: "Gel\xF6scht",
        hidden: "Ausgeblendet",
        pending: "Ausstehend",
        recorded: "Erfasst",
        rejected: "Abgelehnt"
      };
      const normalized = status.toLowerCase();
      return this.text(
        `report:status:${normalized}`,
        fallbacks[normalized] || "Erfasst"
      );
    }
    moderationLabel(action) {
      const fallbacks = {
        approved: "Freigegeben",
        deleted: "Gel\xF6scht",
        grouped: "Gruppiert",
        hidden: "Ausgeblendet",
        moderated: "Moderiert",
        rejected: "Abgelehnt",
        restored: "Wiederhergestellt"
      };
      const normalized = action.toLowerCase();
      return this.text(
        `report:moderation:${normalized}`,
        fallbacks[normalized] || "Moderation"
      );
    }
    qtypeLabel(type) {
      const fallbacks = {
        brainstorm: "Brainstorming",
        open: "Offene Frage",
        pin: "Bildmarkierung",
        poll: "Umfrage",
        puzzle: "Sortierung",
        quiz: "Quiz",
        reveal: "Aufdecken",
        scale: "Skala",
        shortanswer: "Kurzantwort",
        slide: "Folie",
        slider: "Schieberegler",
        truefalse: "Wahr/Falsch",
        wordcloud: "Wortwolke"
      };
      return this.text(
        `live:qtype:${type}`,
        fallbacks[type] || "Frage"
      );
    }
    formatNumber(value2) {
      return new Intl.NumberFormat(this.config.locale, {
        maximumFractionDigits: 2
      }).format(value2);
    }
    formatInteger(value2) {
      return new Intl.NumberFormat(this.config.locale, {
        maximumFractionDigits: 0
      }).format(value2);
    }
    formatPercent(value2) {
      return value2 === null ? "\u2013" : `${new Intl.NumberFormat(this.config.locale, {
        maximumFractionDigits: 1
      }).format(value2)} %`;
    }
    formatDuration(value2) {
      if (value2 === null) {
        return "\u2013";
      }
      return this.text(
        "report:time:seconds",
        "{$seconds} s",
        {
          seconds: new Intl.NumberFormat(this.config.locale, {
            maximumFractionDigits: 1
          }).format(value2 / 1e3)
        }
      );
    }
    formatDate(value2) {
      if (!Number.isFinite(value2) || value2 <= 0) {
        return "";
      }
      return new Intl.DateTimeFormat(this.config.locale, {
        dateStyle: "medium",
        timeStyle: "short"
      }).format(new Date(value2));
    }
    isAbortError(error) {
      return error instanceof DOMException && error.name === "AbortError";
    }
    validSelection() {
      if (this.selection.scope === "course") {
        return true;
      }
      if (this.selection.scope === "combined") {
        return this.selection.sourceKeys.length >= 2;
      }
      return this.selection.sourceKeys.length === 1;
    }
  };

  // src/app_report.ts
  function init(raw = {}) {
    var _a;
    const config = normaliseReportConfig(raw);
    const containerId = (config == null ? void 0 : config.containerId) || (typeof raw.containerId === "string" && raw.containerId !== "" ? raw.containerId : "quizgeist-app-report");
    const root = document.getElementById(containerId);
    if (!root) {
      return;
    }
    if (!config) {
      root.replaceChildren(liveElement(
        "section",
        "quizgeist-report-state quizgeist-report-state--error",
        {
          role: "alert",
          text: ((_a = raw.strings) == null ? void 0 : _a["report:error:config"]) || "Die Berichtsansicht konnte nicht gestartet werden."
        }
      ));
      return;
    }
    const app = new ReportApp(root, config);
    void app.init();
  }
  return __toCommonJS(app_report_exports);
})();
