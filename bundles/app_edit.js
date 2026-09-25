"use strict";
var QuizgeistEditApp = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
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
  var __publicField = (obj, key, value) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);

  // src/app_edit.ts
  var app_edit_exports = {};
  __export(app_edit_exports, {
    init: () => init
  });

  // src/editor/api.ts
  var EditorApiError = class extends Error {
    constructor(message, code = "request_failed", status = 0) {
      super(message);
      __publicField(this, "code");
      __publicField(this, "status");
      this.name = "EditorApiError";
      this.code = code;
      this.status = status;
    }
  };
  var EditorApi = class {
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
        throw new EditorApiError(this.config.strings["editor:error:network"]);
      }
      let envelope;
      try {
        envelope = await response.json();
      } catch (_error) {
        throw new EditorApiError(
          this.config.strings["editor:error:response"],
          "invalid_response",
          response.status
        );
      }
      if (!response.ok || !envelope.ok) {
        const message = typeof envelope.message === "string" && envelope.message !== "" ? envelope.message : this.config.strings["editor:error:request"];
        throw new EditorApiError(message, envelope.error || "request_failed", response.status);
      }
      return envelope.data;
    }
  };

  // src/editor/types.ts
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
  var AI_SOURCES = [
    "topic",
    "pdf",
    "pdf_questions",
    "url",
    "wikipedia",
    "slides",
    "handwriting"
  ];
  var AI_FORMATS = [
    "quiz",
    "truefalse",
    "micro_lesson",
    "vocabulary",
    "presentation",
    "practice_test",
    "step_by_step"
  ];

  // src/editor/defaults.ts
  function isQuestionType(value) {
    return typeof value === "string" && QUESTION_TYPES.includes(value);
  }
  function record(value) {
    return value && typeof value === "object" && !Array.isArray(value) ? value : {};
  }
  function stringValue(value, fallback = "") {
    return typeof value === "string" ? value : fallback;
  }
  function numberValue(value, fallback = 0) {
    const parsed = typeof value === "number" ? value : Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
  }
  function cloneJsonValue(value) {
    if (Array.isArray(value)) {
      return value.map((entry) => cloneJsonValue(entry));
    }
    if (value && typeof value === "object") {
      return Object.fromEntries(
        Object.entries(value).map(([key, entry]) => [key, cloneJsonValue(entry)])
      );
    }
    if (value === null || typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
      return value;
    }
    return null;
  }
  function normalizeOptions(raw) {
    return cloneJsonValue(record(raw));
  }
  function normalizeFiles(raw) {
    if (!Array.isArray(raw)) {
      return [];
    }
    return raw.filter((entry) => entry && typeof entry === "object" && !Array.isArray(entry)).map((entry) => {
      const file = record(entry);
      return {
        filename: stringValue(file.filename),
        filepath: stringValue(file.filepath, "/"),
        mimetype: stringValue(file.mimetype),
        path: stringValue(file.path),
        url: stringValue(file.url)
      };
    }).filter((file) => file.filename !== "" && file.url !== "");
  }
  function normalizeValidationErrors(raw) {
    if (Array.isArray(raw)) {
      return raw.flatMap((entry) => {
        if (typeof entry === "string") {
          return entry === "" ? [] : [entry];
        }
        const error = record(entry);
        const field = stringValue(error.field);
        const code = stringValue(error.code);
        if (field === "" || code === "") {
          return [];
        }
        return [{
          field,
          code,
          ...typeof error.message === "string" && error.message !== "" ? { message: error.message } : {}
        }];
      });
    }
    if (raw && typeof raw === "object") {
      return raw;
    }
    return [];
  }
  function normalizeQuestion(raw) {
    const source = record(raw);
    const qtype = isQuestionType(source.qtype) ? source.qtype : "open";
    const pointmode = source.pointmode === "double" || source.pointmode === "none" ? source.pointmode : "standard";
    return {
      id: numberValue(source.id),
      sortorder: numberValue(source.sortorder),
      qtype,
      questiontext: stringValue(source.questiontext),
      rootid: numberValue(source.rootid),
      options: normalizeOptions(source.options),
      timelimit: numberValue(source.timelimit),
      pointmode,
      explanation: stringValue(source.explanation),
      status: stringValue(source.status, "draft"),
      validationErrors: normalizeValidationErrors(source.validationErrors),
      files: normalizeFiles(source.files),
      timemodified: numberValue(source.timemodified),
      version: numberValue(source.version)
    };
  }
  function cloneQuestionForSave(question2) {
    return {
      id: question2.id,
      qtype: question2.qtype,
      questiontext: question2.questiontext,
      options: cloneJsonValue(question2.options),
      timelimit: question2.timelimit,
      pointmode: question2.pointmode,
      explanation: question2.explanation,
      timemodified: question2.timemodified
    };
  }

  // src/editor/dom.ts
  var dialogOpeners = /* @__PURE__ */ new WeakMap();
  function appendChildren(parent, ...children) {
    for (const child of children) {
      if (child === null || child === void 0 || child === false) {
        continue;
      }
      parent.append(child instanceof Node ? child : document.createTextNode(child));
    }
  }
  function element(tagName, className = "", attributes = {}) {
    const node = document.createElement(tagName);
    if (className !== "") {
      node.className = className;
    }
    for (const [name, value] of Object.entries(attributes)) {
      if (value === void 0 || value === null || value === false) {
        continue;
      }
      if (name === "text") {
        node.textContent = String(value);
      } else if (name === "checked" && node instanceof HTMLInputElement) {
        node.checked = Boolean(value);
      } else if (name === "disabled" && "disabled" in node) {
        node.disabled = Boolean(value);
      } else if (name === "value" && "value" in node) {
        node.value = String(value);
      } else if (value === true) {
        node.setAttribute(name, "");
      } else {
        node.setAttribute(name, String(value));
      }
    }
    return node;
  }
  function button(label, className = "", onClick) {
    const node = element("button", className, { type: "button", text: label });
    if (onClick) {
      node.addEventListener("click", onClick);
    }
    return node;
  }
  function labelledField(labelText, control, hint, className = "") {
    const wrapper = element("div", `quizgeist-field ${className}`.trim());
    const label = element("label", "quizgeist-field__label", { text: labelText });
    const labelTarget = control.matches("button, input, meter, output, progress, select, textarea") ? control : control.querySelector(
      "button, input, meter, output, progress, select, textarea"
    );
    if (labelTarget && !labelTarget.id) {
      labelTarget.id = `quizgeist-field-${crypto.randomUUID()}`;
    }
    if (labelTarget) {
      label.htmlFor = labelTarget.id;
    }
    appendChildren(wrapper, label, control);
    if (hint) {
      const hintNode = element("p", "quizgeist-field__hint", { text: hint });
      hintNode.id = `${(labelTarget == null ? void 0 : labelTarget.id) || `quizgeist-field-${crypto.randomUUID()}`}-hint`;
      labelTarget == null ? void 0 : labelTarget.setAttribute("aria-describedby", hintNode.id);
      wrapper.append(hintNode);
    }
    return wrapper;
  }
  function setButtonBusy(buttonNode, busy, label) {
    buttonNode.disabled = busy;
    buttonNode.setAttribute("aria-busy", busy ? "true" : "false");
    buttonNode.textContent = label;
  }
  function closeDialog(dialog) {
    const opener = dialogOpeners.get(dialog);
    if (dialog.open) {
      dialog.close();
    }
    dialog.remove();
    dialogOpeners.delete(dialog);
    if (opener == null ? void 0 : opener.isConnected) {
      opener.focus();
    }
  }
  function openDialog(title, closeLabel, className = "") {
    const dialog = element("dialog", `quizgeist-dialog ${className}`.trim());
    const activeElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const themeRoot = (activeElement == null ? void 0 : activeElement.closest("[data-quizgeist-root]")) || document.querySelector(".quizgeist-editor-root[data-quizgeist-root]");
    dialog.dataset.quizgeistRoot = (themeRoot == null ? void 0 : themeRoot.dataset.quizgeistRoot) || "dialog";
    dialog.dataset.quizgeistTheme = (themeRoot == null ? void 0 : themeRoot.dataset.quizgeistTheme) || "hell";
    dialog.dataset.quizgeistSeason = (themeRoot == null ? void 0 : themeRoot.dataset.quizgeistSeason) || "herbst";
    if (themeRoot == null ? void 0 : themeRoot.id) {
      dialog.dataset.quizgeistOwner = themeRoot.id;
    }
    const surface = element("div", "quizgeist-dialog__surface");
    const header = element("header", "quizgeist-dialog__header");
    const heading = element("h2", "quizgeist-dialog__title", { text: title });
    heading.id = `quizgeist-dialog-${crypto.randomUUID()}`;
    dialog.setAttribute("aria-labelledby", heading.id);
    const closeButton = button(closeLabel, "quizgeist-icon-button");
    closeButton.setAttribute("aria-label", closeLabel);
    header.append(heading, closeButton);
    const body = element("div", "quizgeist-dialog__body", { tabindex: 0 });
    const footer = element("footer", "quizgeist-dialog__footer");
    surface.append(header, body, footer);
    dialog.append(surface);
    document.body.append(dialog);
    if (activeElement) {
      dialogOpeners.set(dialog, activeElement);
    }
    const close = () => closeDialog(dialog);
    closeButton.addEventListener("click", close);
    dialog.addEventListener("cancel", (event) => {
      event.preventDefault();
      close();
    });
    dialog.addEventListener("click", (event) => {
      if (event.target === dialog) {
        close();
      }
    });
    dialog.showModal();
    closeButton.focus();
    return { body, closeButton, dialog, footer };
  }

  // src/live/dom.ts
  function liveElement(tagName, className = "", attributes = {}) {
    const node = document.createElement(tagName);
    if (className !== "") {
      node.className = className;
    }
    for (const [name, value] of Object.entries(attributes)) {
      if (value === void 0 || value === null || value === false) {
        continue;
      }
      if (name === "text") {
        node.textContent = String(value);
      } else if (name === "checked" && node instanceof HTMLInputElement) {
        node.checked = Boolean(value);
      } else if (name === "disabled" && "disabled" in node) {
        node.disabled = Boolean(value);
      } else if (name === "selected" && node instanceof HTMLOptionElement) {
        node.selected = Boolean(value);
      } else if (name === "value" && "value" in node) {
        node.value = String(value);
      } else if (value === true) {
        node.setAttribute(name, "");
      } else {
        node.setAttribute(name, String(value));
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

  // src/live/recorder.ts
  var AUDIO_TYPES = [
    "audio/webm;codecs=opus",
    "audio/webm",
    "audio/ogg;codecs=opus",
    "audio/mp4"
  ];
  function recordingSupported() {
    var _a;
    return typeof navigator !== "undefined" && typeof ((_a = navigator.mediaDevices) == null ? void 0 : _a.getUserMedia) === "function" && typeof MediaRecorder !== "undefined" && typeof window !== "undefined" && typeof window.MediaStream !== "undefined";
  }
  function firstSupportedType() {
    if (typeof MediaRecorder === "undefined" || typeof MediaRecorder.isTypeSupported !== "function") {
      return void 0;
    }
    return AUDIO_TYPES.find((type) => MediaRecorder.isTypeSupported(type));
  }
  var ClipRecorder = class {
    constructor(limits, onStateChange = () => {
    }) {
      this.limits = limits;
      this.onStateChange = onStateChange;
      __publicField(this, "stream", null);
      __publicField(this, "recorder", null);
      __publicField(this, "chunks", []);
      __publicField(this, "startedAt", 0);
      __publicField(this, "autoStop", null);
      __publicField(this, "state", "idle");
    }
    getState() {
      return this.state;
    }
    setState(state) {
      this.state = state;
      this.onStateChange(state);
    }
    /**
     * Ask for the microphone and start recording.
     *
     * Rejects when the browser cannot record or the user declines. The caller is
     * expected to fall back to typed input rather than to insist.
     */
    async start() {
      if (!recordingSupported()) {
        this.setState("unavailable");
        throw new Error("recording-unsupported");
      }
      if (this.state === "recording" || this.state === "requesting") {
        return;
      }
      this.setState("requesting");
      let stream;
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: { echoCancellation: true, noiseSuppression: true },
          video: false
        });
      } catch (error) {
        this.setState("idle");
        throw error;
      }
      this.stream = stream;
      this.chunks = [];
      const mimeType = firstSupportedType();
      try {
        this.recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
      } catch (error) {
        this.release();
        this.setState("idle");
        throw error;
      }
      this.recorder.addEventListener("dataavailable", (event) => {
        if (event.data.size > 0) {
          this.chunks.push(event.data);
        }
      });
      this.startedAt = Date.now();
      this.recorder.start(1e3);
      this.setState("recording");
      const limitMs = Math.max(1, this.limits.maxSeconds) * 1e3;
      this.autoStop = setTimeout(() => {
        if (this.state === "recording") {
          void this.stop().catch(() => {
          });
        }
      }, limitMs);
    }
    /**
     * Stop and return the recording.
     */
    async stop() {
      if (this.autoStop !== null) {
        clearTimeout(this.autoStop);
        this.autoStop = null;
      }
      const recorder = this.recorder;
      if (recorder === null) {
        throw new Error("recording-not-started");
      }
      this.setState("stopping");
      const blob = await new Promise((resolve, reject) => {
        const finish = () => {
          var _a;
          const type = recorder.mimeType || ((_a = this.chunks[0]) == null ? void 0 : _a.type) || "audio/webm";
          resolve(new Blob(this.chunks, { type }));
        };
        recorder.addEventListener("stop", finish, { once: true });
        recorder.addEventListener("error", () => reject(new Error("recording-failed")), { once: true });
        if (recorder.state === "inactive") {
          finish();
          return;
        }
        recorder.stop();
      });
      const durationMs = Math.max(0, Date.now() - this.startedAt);
      this.release();
      this.setState("idle");
      return {
        blob,
        // Strip the codec parameter: the server compares the container family,
        // and `audio/webm;codecs=opus` is the same family as `audio/webm`.
        mimeType: (blob.type || "audio/webm").split(";")[0].trim(),
        durationMs
      };
    }
    /**
     * Abandon a running recording without producing a result.
     */
    cancel() {
      if (this.autoStop !== null) {
        clearTimeout(this.autoStop);
        this.autoStop = null;
      }
      if (this.recorder !== null && this.recorder.state !== "inactive") {
        try {
          this.recorder.stop();
        } catch {
        }
      }
      this.chunks = [];
      this.release();
      this.setState("idle");
    }
    /**
     * Release the microphone.
     *
     * Called on every exit path. A stream left open keeps the browser's
     * recording indicator lit, and a learner has every right to read that
     * indicator as "this page is still listening".
     */
    release() {
      if (this.stream !== null) {
        this.stream.getTracks().forEach((track) => track.stop());
        this.stream = null;
      }
      this.recorder = null;
    }
  };
  async function uploadClip(uploadUrl, sesskey, cmid, purpose, language, recording) {
    var _a, _b;
    const form = new FormData();
    form.append("id", String(cmid));
    form.append("sesskey", sesskey);
    form.append("purpose", purpose);
    form.append("language", language);
    form.append("clip", recording.blob, `clip.${extensionFor(recording.mimeType)}`);
    const response = await fetch(uploadUrl, {
      method: "POST",
      body: form,
      credentials: "same-origin",
      headers: { Accept: "application/json" }
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload === null || payload.clip === void 0) {
      const error = payload == null ? void 0 : payload.error;
      const failure = new Error((_a = error == null ? void 0 : error.message) != null ? _a : "");
      failure.code = (_b = error == null ? void 0 : error.code) != null ? _b : "clip_upload_failed";
      throw failure;
    }
    return payload.clip;
  }
  function extensionFor(mimeType) {
    switch (mimeType) {
      case "audio/ogg":
        return "ogg";
      case "audio/mp4":
      case "audio/x-m4a":
        return "m4a";
      case "audio/wav":
      case "audio/x-wav":
        return "wav";
      default:
        return "webm";
    }
  }

  // src/live/strings.ts
  var reportedKeys = /* @__PURE__ */ new Set();
  function reportMissingString(key) {
    if (key === "" || reportedKeys.has(key)) {
      return;
    }
    reportedKeys.add(key);
    if (typeof console !== "undefined" && typeof console.warn === "function") {
      console.warn(
        `[quizgeist] Fehlender Sprachschl\xFCssel: ${key}. Bitte in view.php ausliefern und in lang/de sowie lang/en erg\xE4nzen.`
      );
    }
  }

  // src/editor/messages.ts
  var MISSING_LABEL = "\u2026";
  function editorString(strings, key, fallback = "") {
    const configured = strings[key];
    if (typeof configured === "string" && configured !== "") {
      return configured;
    }
    reportMissingString(key);
    return fallback !== "" ? fallback : MISSING_LABEL;
  }
  var FIELD_LABEL_ALIASES = {
    answersindexed: "answer",
    answersmedia: "answerimage",
    answersmediaindexed: "answerimage",
    items: "puzzleitems",
    itemsindexed: "puzzleitem",
    itemsmedia: "puzzleitemmedia",
    itemsmediaindexed: "puzzleitemmedia",
    layout: "slidelayout",
    targetx: "targetx",
    targety: "targety"
  };
  function fieldParts(field) {
    let index = null;
    const named = [];
    field.split(".").forEach((segment) => {
      const trimmed = segment.trim();
      if (trimmed === "") {
        return;
      }
      if (/^[0-9]+$/.test(trimmed)) {
        index = Number(trimmed) + 1;
        return;
      }
      named.push(trimmed);
    });
    if (named[0] === "options") {
      named.shift();
    }
    return { index, named };
  }
  function normaliseSegments(segments) {
    return segments.join("").toLowerCase().replace(/[^a-z0-9]+/g, "");
  }
  function fieldLabel(strings, field) {
    const generic = editorString(strings, "editor:validation:field", "dieses Feld");
    const { index, named } = fieldParts(field);
    if (named.length === 0) {
      return generic;
    }
    const joined = normaliseSegments(named);
    const candidates = [];
    if (index !== null) {
      const aliased = FIELD_LABEL_ALIASES[`${joined}indexed`];
      if (aliased) {
        candidates.push(aliased);
      }
    }
    const alias = FIELD_LABEL_ALIASES[joined];
    if (alias) {
      candidates.push(alias);
    }
    candidates.push(joined);
    if (named.length > 1) {
      candidates.push(normaliseSegments(named.slice(-1)));
    }
    let label = "";
    for (const candidate of candidates) {
      const configured = strings[`editor:field:${candidate}`];
      if (typeof configured === "string" && configured !== "") {
        label = configured;
        break;
      }
    }
    if (label === "") {
      return generic;
    }
    if (index === null) {
      return label;
    }
    return editorString(strings, "editor:validation:fieldnumbered", "{$a} {$b}").replace("{$a}", label).replace("{$b}", String(index));
  }
  function validationMessages(strings, errors) {
    const messages = [];
    const push = (message) => {
      const trimmed = message.trim();
      if (trimmed !== "" && !messages.includes(trimmed)) {
        messages.push(trimmed);
      }
    };
    if (Array.isArray(errors)) {
      errors.forEach((entry) => {
        if (typeof entry === "string") {
          push(entry);
          return;
        }
        if (typeof (entry == null ? void 0 : entry.message) === "string" && entry.message.trim() !== "") {
          push(entry.message);
          return;
        }
        const code = typeof (entry == null ? void 0 : entry.code) === "string" ? entry.code : "";
        const label = fieldLabel(strings, typeof (entry == null ? void 0 : entry.field) === "string" ? entry.field : "");
        const configured = code === "" ? "" : strings[`editor:validation:${code}`];
        if (typeof configured === "string" && configured !== "") {
          push(configured.replace("{$a}", label));
          return;
        }
        if (code !== "") {
          reportMissingString(`editor:validation:${code}`);
        }
        push(
          editorString(strings, "editor:validation:generic", "Bitte pr\xFCfen Sie {$a}.").replace("{$a}", label)
        );
      });
      return messages;
    }
    Object.entries(errors).forEach(([, value]) => {
      const entries = Array.isArray(value) ? value : [value];
      entries.forEach((entry) => {
        if (typeof entry === "string") {
          push(entry);
        }
      });
    });
    return messages;
  }
  var WARNING_PATTERNS = [
    { key: "blankslide", test: /^blank_slide_([0-9]+)$/ },
    { key: "slideimagelimit", test: /^slide_([0-9]+)_image_count_limit$/ },
    { key: "slidenonmedia", test: /^slide_([0-9]+)_non_media_image_ignored$/ },
    { key: "slideunsupported", test: /^slide_([0-9]+)_unsupported_image_ignored$/ },
    { key: "slideimageinvalid", test: /^slide_([0-9]+)_image_validation_failed$/ },
    { key: "gatewayfallback", test: /^gateway_fallback$/ },
    { key: "gateway", test: /^gateway_/ },
    { key: "pdfpreviewunavailable", test: /^pdftoppm_unavailable_text_only_slides$/ },
    { key: "pdfpreviewtimeout", test: /^pdf_preview_timeout$/ },
    { key: "pdfpreviewlimit", test: /^pdf_preview_media_limit$/ },
    { key: "pdfpreview", test: /^pdf_preview_/ },
    { key: "pdftext", test: /^pdftotext_/ },
    { key: "pdfinfo", test: /^pdfinfo_/ },
    { key: "pdfnotext", test: /^php_fallback_no_text$/ },
    { key: "pdflayout", test: /^php_fallback_layout_unavailable$/ },
    { key: "texttruncated", test: /^text_truncated$/ },
    { key: "resulttruncated", test: /^result_truncated$/ },
    { key: "noquestions", test: /^no_question_candidates$/ },
    { key: "duplicatequestions", test: /^duplicate_question_candidates_removed$/ },
    { key: "questionstructure", test: /^question_structure_not_found_used_question_marks$/ },
    { key: "externalignored", test: /^external_relationship_ignored$/ },
    { key: "imagetotallimit", test: /^presentation_image_total_limit$/ }
  ];
  var WARNING_FALLBACKS = {
    blankslide: "Folie {$a} enthielt keinen verwertbaren Inhalt und wurde \xFCbersprungen.",
    duplicatequestions: "Mehrfach vorkommende Fragen wurden nur einmal \xFCbernommen.",
    externalignored: "Ein Verweis auf eine externe Quelle wurde aus Sicherheitsgr\xFCnden ausgelassen.",
    gateway: "Die KI war nicht erreichbar oder hat unbrauchbar geantwortet. Die Fragen stammen daher aus der regelbasierten Notl\xF6sung.",
    gatewayfallback: "Diese Fragen wurden ohne KI aus dem Quelltext abgeleitet. Bitte pr\xFCfen Sie sie besonders sorgf\xE4ltig.",
    generic: "Ein Teil der Quelle konnte nicht vollst\xE4ndig ausgewertet werden. Bitte pr\xFCfen Sie die Entw\xFCrfe vor dem \xDCbernehmen.",
    imagetotallimit: "Die Pr\xE4sentation enthielt mehr Bilder als \xFCbernommen werden k\xF6nnen.",
    noquestions: "In der Quelle waren keine Fragen erkennbar.",
    pdfinfo: "Die Seitenzahl des PDFs konnte nicht ermittelt werden.",
    pdflayout: "Das PDF wurde ohne Layout-Erkennung gelesen; die Reihenfolge kann abweichen.",
    pdfnotext: "Aus dem PDF lie\xDF sich kein Text lesen. Vermutlich ist es ein reines Bild-PDF.",
    pdfpreview: "F\xFCr einzelne Seiten konnte keine Bildvorschau erzeugt werden.",
    pdfpreviewlimit: "Es wurden nicht alle Seitenbilder \xFCbernommen, weil die Obergrenze f\xFCr Medien erreicht war.",
    pdfpreviewtimeout: "Die Bildvorschau des PDFs hat zu lange gedauert und wurde abgebrochen.",
    pdfpreviewunavailable: "Auf diesem Server k\xF6nnen keine Seitenbilder erzeugt werden; die Folien enthalten nur Text.",
    pdftext: "Der Text des PDFs konnte nicht vollst\xE4ndig gelesen werden.",
    questionstructure: "Es war keine klare Fragenstruktur erkennbar; die Fragen wurden anhand der Fragezeichen abgegrenzt.",
    resulttruncated: "Die KI hat mehr Fragen geliefert als angefordert; die \xFCberz\xE4hligen wurden verworfen.",
    slideimageinvalid: "Ein Bild auf Folie {$a} war besch\xE4digt und wurde ausgelassen.",
    slideimagelimit: "Auf Folie {$a} wurden nicht alle Bilder \xFCbernommen.",
    slidenonmedia: "Auf Folie {$a} wurde ein Element ausgelassen, das kein Bild ist.",
    slideunsupported: "Auf Folie {$a} wurde ein Bild in einem nicht unterst\xFCtzten Format ausgelassen.",
    texttruncated: "Die Quelle war sehr lang; es wurde nur der Anfang ausgewertet."
  };
  function warningMessage(strings, value) {
    var _a;
    const raw = value.trim();
    if (raw === "") {
      return "";
    }
    if (!/^[a-z][a-z0-9_]*$/.test(raw)) {
      return raw;
    }
    for (const pattern of WARNING_PATTERNS) {
      const match = pattern.test.exec(raw);
      if (!match) {
        continue;
      }
      return editorString(
        strings,
        `editor:ai:warning:${pattern.key}`,
        WARNING_FALLBACKS[pattern.key] || WARNING_FALLBACKS.generic
      ).replace("{$a}", (_a = match[1]) != null ? _a : "");
    }
    reportMissingString(`editor:ai:warning: (unbekannter Code "${raw}")`);
    return editorString(
      strings,
      "editor:ai:warning:generic",
      WARNING_FALLBACKS.generic
    );
  }
  function aiWarningMessages(strings, warnings) {
    const messages = [];
    warnings.forEach((warning) => {
      const message = warningMessage(strings, warning);
      if (message !== "" && !messages.includes(message)) {
        messages.push(message);
      }
    });
    return messages;
  }

  // src/editor/dictation.ts
  var DictationControl = class {
    constructor(strings, config, callbacks) {
      this.strings = strings;
      this.config = config;
      this.callbacks = callbacks;
      __publicField(this, "root");
      __publicField(this, "button");
      __publicField(this, "status");
      __publicField(this, "recorder");
      __publicField(this, "busy", false);
      this.root = liveElement("div", "quizgeist-dictation");
      this.button = liveButton(
        // A visible label, not just a microphone glyph.
        editorString(this.strings, "dictation:start", "Diktat starten"),
        "quizgeist-dictation__button quizgeist-touch-target"
      );
      this.status = liveElement("p", "quizgeist-dictation__status", {
        "aria-live": "polite",
        role: "status"
      });
      this.button.addEventListener("click", () => {
        void this.toggle();
      });
      this.root.append(this.button, this.status);
      this.recorder = new ClipRecorder(
        { maxBytes: config.maxBytes, maxSeconds: config.maxSeconds },
        (state) => {
          if (state === "recording") {
            this.button.textContent = editorString(
              this.strings,
              "dictation:stop",
              "Diktat beenden"
            );
            this.button.classList.add("is-recording");
            this.setStatus(editorString(this.strings, "dictation:running", "Diktat l\xE4uft."));
          } else if (state === "idle") {
            this.button.textContent = editorString(
              this.strings,
              "dictation:start",
              "Diktat starten"
            );
            this.button.classList.remove("is-recording");
          }
        }
      );
      if (!recordingSupported()) {
        this.button.disabled = true;
        this.setStatus(editorString(
          this.strings,
          "dictation:unavailable",
          "Diktat ist auf diesem Ger\xE4t nicht m\xF6glich."
        ));
      }
    }
    /**
     * Whether dictation can be offered at all.
     */
    static available() {
      return recordingSupported();
    }
    dispose() {
      this.recorder.cancel();
    }
    setStatus(message) {
      this.status.textContent = message;
    }
    async toggle() {
      if (this.busy) {
        return;
      }
      if (this.recorder.getState() === "recording") {
        await this.finish();
        return;
      }
      try {
        await this.recorder.start();
      } catch {
        this.setStatus(editorString(
          this.strings,
          "dictation:denied",
          "Ohne Mikrofonfreigabe kann nicht diktiert werden."
        ));
      }
    }
    async finish() {
      this.busy = true;
      this.button.disabled = true;
      try {
        const recording = await this.recorder.stop();
        if (recording.blob.size === 0) {
          this.setStatus(editorString(this.strings, "dictation:empty", "Es wurde nichts aufgenommen."));
          return;
        }
        this.setStatus(editorString(this.strings, "dictation:working", "Diktat wird verschriftet \u2026"));
        const clip = await uploadClip(
          this.config.uploadUrl,
          this.config.sesskey,
          this.config.cmid,
          "dictation",
          this.config.language,
          recording
        );
        const result = await this.callbacks.transcribe(clip.id);
        const text4 = typeof result.text === "string" ? result.text.trim() : "";
        if (text4 === "") {
          this.setStatus(editorString(
            this.strings,
            "dictation:nothingheard",
            "Es war nichts zu verstehen. Die Aufnahme wurde gel\xF6scht."
          ));
          return;
        }
        this.callbacks.onText(text4);
        this.setStatus(editorString(
          this.strings,
          "dictation:done",
          "Diktat \xFCbernommen. Die Aufnahme wurde gel\xF6scht."
        ));
      } catch (error) {
        const message = error instanceof Error && error.message !== "" ? error.message : editorString(this.strings, "dictation:failed", "Das Diktat hat nicht geklappt.");
        this.setStatus(message);
      } finally {
        this.busy = false;
        this.button.disabled = false;
      }
    }
  };

  // src/editor/question-form.ts
  var NON_SCORING_TYPES = [
    "poll",
    "wordcloud",
    "scale",
    "brainstorm",
    "open",
    "slide"
  ];
  var QuestionFormRenderer = class {
    constructor(config, callbacks) {
      this.config = config;
      this.callbacks = callbacks;
      __publicField(this, "draggedPuzzleId", null);
      __publicField(this, "issuedItemIds", /* @__PURE__ */ new Set());
      __publicField(this, "itemIdSequence", 0);
    }
    s(key, fallback = "") {
      return editorString(this.config.strings, key, fallback);
    }
    label(key, fallback) {
      return editorString(this.config.strings, key, fallback);
    }
    render(question2) {
      const panel = element("section", "quizgeist-question-form");
      panel.dataset.questionId = String(question2.id);
      const headingRow = element("div", "quizgeist-question-form__heading");
      const headingText = element("div");
      const eyebrow = element("p", "quizgeist-eyebrow", {
        text: this.s(`editor:qtype:${question2.qtype}`)
      });
      const heading = element("h2", "quizgeist-question-form__title", {
        text: this.questionDisplayTitle(question2)
      });
      appendChildren(headingText, eyebrow, heading);
      const previewButton = button(
        this.s("editor:action:preview"),
        "quizgeist-button quizgeist-button--secondary",
        this.callbacks.onPreview
      );
      const headingActions = element("div", "quizgeist-question-form__actions");
      if (question2.id > 0 && this.callbacks.isAiAvailable()) {
        const explanationButton = button(
          this.label(
            "editor:ai:explanation:action",
            "KI-L\xF6sungsweg vorschlagen"
          ),
          "quizgeist-button quizgeist-button--secondary",
          this.callbacks.onAiExplanation
        );
        explanationButton.dataset.action = "ai-explanation";
        headingActions.append(explanationButton);
      }
      headingActions.append(previewButton);
      headingRow.append(headingText, headingActions);
      panel.append(headingRow);
      const validationSummary = this.renderValidationSummary(question2);
      if (validationSummary) {
        panel.append(validationSummary);
      }
      const form = element("div", "quizgeist-question-form__fields");
      if (question2.qtype !== "slide") {
        const questionText = element("textarea", "quizgeist-textarea", {
          rows: 3,
          maxlength: 4e3,
          value: question2.questiontext
        });
        questionText.addEventListener("input", () => {
          question2.questiontext = questionText.value;
          this.callbacks.onChange();
        });
        form.append(labelledField(
          this.s("editor:field:questiontext"),
          questionText,
          this.s("editor:field:questiontext:hint")
        ));
      }
      form.append(this.renderMediaControl(
        question2,
        () => this.getMedia(question2),
        this.s(question2.qtype === "pin" || question2.qtype === "reveal" ? "editor:field:media:required" : "editor:field:media")
      ));
      const typeFields = element("div", "quizgeist-type-fields");
      switch (question2.qtype) {
        case "quiz":
          this.renderQuiz(question2, typeFields);
          break;
        case "truefalse":
          this.renderTrueFalse(question2, typeFields);
          break;
        case "shortanswer":
          this.renderShortAnswer(question2, typeFields);
          break;
        case "puzzle":
          this.renderPuzzle(question2, typeFields);
          break;
        case "poll":
          this.renderPoll(question2, typeFields);
          break;
        case "wordcloud":
          this.renderWordcloud(question2, typeFields);
          break;
        case "scale":
          this.renderScale(question2, typeFields);
          break;
        case "slider":
          this.renderSlider(question2, typeFields);
          break;
        case "pin":
          this.renderPin(question2, typeFields);
          break;
        case "reveal":
          this.renderReveal(question2, typeFields);
          break;
        case "brainstorm":
          this.renderBrainstorm(question2, typeFields);
          break;
        case "open":
          this.renderOpen(question2, typeFields);
          break;
        case "slide":
          this.renderSlide(question2, typeFields);
          break;
      }
      form.append(typeFields);
      const metadata = element("div", "quizgeist-field-grid quizgeist-field-grid--three");
      metadata.append(
        this.renderTimeLimit(question2),
        this.renderPointMode(question2)
      );
      const explanation = element("textarea", "quizgeist-textarea", {
        rows: 3,
        maxlength: 6e3,
        value: question2.explanation
      });
      explanation.addEventListener("input", () => {
        question2.explanation = explanation.value;
        this.callbacks.onChange();
      });
      metadata.append(labelledField(
        this.s("editor:field:explanation"),
        explanation,
        this.s("editor:field:explanation:hint")
      ));
      form.append(metadata);
      panel.append(form);
      return panel;
    }
    renderValidationSummary(question2) {
      const errors = this.flattenErrors(question2.validationErrors);
      if (errors.length === 0) {
        return null;
      }
      const errorBox = element("div", "quizgeist-validation-summary", {
        role: "alert",
        tabindex: "-1"
      });
      const errorHeading = element("h3", "quizgeist-validation-summary__title", {
        text: this.s("editor:validation:title")
      });
      const list = element("ul");
      for (const error of errors) {
        list.append(element("li", "", { text: error }));
      }
      errorBox.append(errorHeading, list);
      return errorBox;
    }
    questionDisplayTitle(question2) {
      if (question2.qtype === "slide") {
        const options = question2.options;
        return options.title.trim() || this.s("editor:question:untitled");
      }
      return question2.questiontext.trim() || this.s("editor:question:untitled");
    }
    flattenErrors(value) {
      return validationMessages(this.config.strings, value);
    }
    renderQuiz(question2, target) {
      const options = question2.options;
      target.append(this.renderSwitch(
        this.s("editor:field:multiple"),
        this.s("editor:field:multiple:hint"),
        options.multiple,
        (checked) => {
          options.multiple = checked;
          if (!checked) {
            const firstCorrect = options.answers.findIndex((answer) => answer.correct);
            options.answers.forEach((answer, index) => {
              answer.correct = index === Math.max(0, firstCorrect);
            });
          }
          this.callbacks.onChange();
          this.refreshCurrentForm(question2);
        }
      ));
      target.append(this.renderAnswers(question2, options.answers, true));
    }
    renderTrueFalse(question2, target) {
      const options = question2.options;
      const fieldset = element("fieldset", "quizgeist-choice-field");
      fieldset.append(element("legend", "quizgeist-field__label", {
        text: this.s("editor:field:correctanswer")
      }));
      const choices = element("div", "quizgeist-segmented");
      for (const [value, key] of [
        [true, "editor:true"],
        [false, "editor:false"]
      ]) {
        const label = element("label", "quizgeist-segmented__item");
        const input = element("input", "", {
          type: "radio",
          name: `truefalse-${question2.id}`,
          checked: options.correct === value
        });
        input.addEventListener("change", () => {
          if (input.checked) {
            options.correct = value;
            this.callbacks.onChange();
          }
        });
        appendChildren(label, input, element("span", "", { text: this.s(key) }));
        choices.append(label);
      }
      fieldset.append(choices);
      target.append(fieldset);
    }
    renderShortAnswer(question2, target) {
      const options = question2.options;
      target.append(
        this.renderStringList(
          question2,
          this.s("editor:field:acceptedanswers"),
          options.acceptedAnswers,
          this.s("editor:action:addacceptedanswer"),
          12
        ),
        this.renderSwitch(
          this.s("editor:field:typotolerance"),
          this.s("editor:field:typotolerance:hint"),
          options.typoTolerance,
          (checked) => {
            options.typoTolerance = checked;
            this.callbacks.onChange();
          }
        )
      );
    }
    renderPuzzle(question2, target) {
      const options = question2.options;
      const wrapper = element("fieldset", "quizgeist-repeater");
      wrapper.append(element("legend", "quizgeist-field__label", {
        text: this.s("editor:field:puzzleitems")
      }));
      const hint = element("p", "quizgeist-field__hint", {
        text: this.s("editor:field:puzzleitems:hint")
      });
      wrapper.append(hint);
      options.items.forEach((item, index) => {
        const row = element("div", "quizgeist-repeater__row");
        row.dataset.puzzleItemId = item.id;
        row.draggable = true;
        const indexLabel = element("span", "quizgeist-repeater__index", { text: String(index + 1) });
        const dragHandle = element("span", "quizgeist-drag-handle", {
          title: this.s("editor:action:drag"),
          role: "img",
          "aria-label": this.s("editor:action:drag")
        });
        const input = element("input", "quizgeist-input", {
          type: "text",
          maxlength: 500,
          value: item.text,
          "aria-label": `${this.s("editor:field:puzzleitem")} ${index + 1}`
        });
        input.addEventListener("input", () => {
          item.text = input.value;
          this.callbacks.onChange();
        });
        const media = this.renderInlineMediaControl(
          question2,
          item.media,
          item.id,
          index + 1
        );
        const up = button(
          this.s("editor:action:up"),
          "quizgeist-button quizgeist-button--quiet",
          () => this.moveArrayItem(question2, options.items, index, index - 1)
        );
        up.disabled = index === 0;
        const down = button(
          this.s("editor:action:down"),
          "quizgeist-button quizgeist-button--quiet",
          () => this.moveArrayItem(question2, options.items, index, index + 1)
        );
        down.disabled = index === options.items.length - 1;
        const remove = button(
          this.s("editor:action:remove"),
          "quizgeist-button quizgeist-button--danger-quiet",
          () => {
            if (options.items.length <= 2) {
              return;
            }
            options.items.splice(index, 1);
            this.callbacks.onChange();
            this.refreshCurrentForm(question2);
          }
        );
        remove.disabled = options.items.length <= 2;
        row.append(indexLabel, dragHandle, input, media, up, down, remove);
        row.addEventListener("dragstart", (event) => {
          var _a;
          if (event.target instanceof HTMLInputElement || event.target instanceof HTMLButtonElement || event.target instanceof HTMLSelectElement) {
            event.preventDefault();
            return;
          }
          this.draggedPuzzleId = item.id;
          row.classList.add("is-dragging");
          (_a = event.dataTransfer) == null ? void 0 : _a.setData("text/plain", item.id);
          if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = "move";
          }
        });
        row.addEventListener("dragend", () => {
          var _a;
          this.draggedPuzzleId = null;
          row.classList.remove("is-dragging");
          (_a = row.parentElement) == null ? void 0 : _a.querySelectorAll(".is-drag-target").forEach((node) => node.classList.remove("is-drag-target"));
        });
        row.addEventListener("dragover", (event) => {
          if (this.draggedPuzzleId === null || this.draggedPuzzleId === item.id) {
            return;
          }
          event.preventDefault();
          row.classList.add("is-drag-target");
        });
        row.addEventListener("dragleave", () => row.classList.remove("is-drag-target"));
        row.addEventListener("drop", (event) => {
          event.preventDefault();
          row.classList.remove("is-drag-target");
          const source = options.items.findIndex(
            (candidate) => candidate.id === this.draggedPuzzleId
          );
          const destination = options.items.findIndex(
            (candidate) => candidate.id === item.id
          );
          this.draggedPuzzleId = null;
          this.moveArrayItem(question2, options.items, source, destination);
        });
        wrapper.append(row);
      });
      const add = button(
        this.s("editor:action:addpuzzleitem"),
        "quizgeist-button quizgeist-button--secondary",
        () => {
          if (options.items.length >= 6) {
            return;
          }
          options.items.push({
            id: this.nextId(options.items),
            text: "",
            media: null
          });
          this.callbacks.onChange();
          this.refreshCurrentForm(question2);
        }
      );
      add.disabled = options.items.length >= 6;
      wrapper.append(add);
      target.append(wrapper);
    }
    renderPoll(question2, target) {
      const options = question2.options;
      const note = element("div", "quizgeist-info-box", {
        text: this.s("editor:poll:nopoints")
      });
      const multiple = this.renderSwitch(
        this.s("editor:field:multiple"),
        this.s("editor:field:multiple:hint"),
        options.multiple,
        (checked) => {
          options.multiple = checked;
          this.callbacks.onChange();
        }
      );
      target.append(note, multiple, this.renderAnswers(question2, options.answers, false));
    }
    renderWordcloud(question2, target) {
      const options = question2.options;
      const grid = element("div", "quizgeist-field-grid");
      grid.append(
        this.numberField(
          this.s("editor:field:maxchars"),
          options.maxChars,
          10,
          120,
          1,
          (value) => {
            options.maxChars = value;
            this.callbacks.onChange();
          }
        ),
        this.renderSwitch(
          this.s("editor:field:moderation"),
          this.s("editor:field:moderation:hint"),
          options.moderation,
          (checked) => {
            options.moderation = checked;
            this.callbacks.onChange();
          }
        )
      );
      target.append(grid);
    }
    renderScale(question2, target) {
      const options = question2.options;
      const grid = element("div", "quizgeist-field-grid quizgeist-field-grid--three");
      const minLabel = element("input", "quizgeist-input", {
        type: "text",
        maxlength: 120,
        value: options.minLabel
      });
      minLabel.addEventListener("input", () => {
        options.minLabel = minLabel.value;
        this.callbacks.onChange();
      });
      const maxLabel = element("input", "quizgeist-input", {
        type: "text",
        maxlength: 120,
        value: options.maxLabel
      });
      maxLabel.addEventListener("input", () => {
        options.maxLabel = maxLabel.value;
        this.callbacks.onChange();
      });
      grid.append(
        labelledField(this.s("editor:field:minlabel"), minLabel),
        labelledField(this.s("editor:field:maxlabel"), maxLabel),
        this.rangeField(
          this.s("editor:field:steps"),
          options.steps,
          3,
          10,
          1,
          (value) => {
            options.steps = value;
            this.callbacks.onChange();
          }
        )
      );
      target.append(grid);
    }
    renderSlider(question2, target) {
      const options = question2.options;
      const grid = element("div", "quizgeist-field-grid quizgeist-field-grid--five");
      const fields = [
        ["editor:field:min", "min", -1e6, 1e6],
        ["editor:field:max", "max", -1e6, 1e6],
        ["editor:field:step", "step", 1e-3, 1e6],
        ["editor:field:target", "target", -1e6, 1e6],
        ["editor:field:tolerance", "tolerance", 0, 1e6]
      ];
      for (const [label, key, min, max] of fields) {
        grid.append(this.numberField(
          this.s(label),
          options[key],
          min,
          max,
          key === "step" ? 1e-3 : 1,
          (value) => {
            options[key] = value;
            this.callbacks.onChange();
          }
        ));
      }
      target.append(grid);
    }
    renderPin(question2, target) {
      const options = question2.options;
      const media = this.selectedMedia(question2, options.media);
      if (media) {
        const canvas = element("button", "quizgeist-pin-target", {
          type: "button",
          "aria-label": this.s("editor:field:pintarget:hint")
        });
        const image = element("img", "quizgeist-pin-target__image", {
          src: media.url,
          alt: ""
        });
        canvas.append(image);
        if (options.hasTarget !== false) {
          const marker = element("span", "quizgeist-pin-target__marker", {
            "aria-hidden": "true"
          });
          marker.style.left = `${options.target.x}%`;
          marker.style.top = `${options.target.y}%`;
          canvas.append(marker);
          canvas.addEventListener("click", (event) => {
            const rectangle = canvas.getBoundingClientRect();
            options.target.x = Math.round(
              Math.max(0, Math.min(100, (event.clientX - rectangle.left) * 100 / rectangle.width))
            );
            options.target.y = Math.round(
              Math.max(0, Math.min(100, (event.clientY - rectangle.top) * 100 / rectangle.height))
            );
            this.callbacks.onChange();
            this.refreshCurrentForm(question2);
          });
        }
        target.append(canvas);
      } else {
        target.append(element("div", "quizgeist-media-empty", {
          text: this.s("editor:pin:mediarequired")
        }));
      }
      if (options.hasTarget === false) {
        target.append(element("p", "quizgeist-field-hint", {
          text: this.s("editor:pin:heatmap")
        }));
        return;
      }
      const grid = element("div", "quizgeist-field-grid quizgeist-field-grid--three");
      grid.append(
        this.numberField(
          this.s("editor:field:targetx"),
          options.target.x,
          0,
          100,
          1,
          (value) => {
            options.target.x = value;
            this.callbacks.onChange();
          }
        ),
        this.numberField(
          this.s("editor:field:targety"),
          options.target.y,
          0,
          100,
          1,
          (value) => {
            options.target.y = value;
            this.callbacks.onChange();
          }
        ),
        this.rangeField(
          this.s("editor:field:radius"),
          options.radius,
          1,
          50,
          1,
          (value) => {
            options.radius = value;
            this.callbacks.onChange();
          },
          this.s("editor:unit:percent")
        )
      );
      target.append(grid);
    }
    renderReveal(question2, target) {
      const options = question2.options;
      const grid = element("div", "quizgeist-field-grid");
      grid.append(
        this.rangeField(
          this.s("editor:field:grid"),
          options.grid,
          3,
          6,
          1,
          (value) => {
            options.grid = value;
            this.callbacks.onChange();
          },
          this.s("editor:unit:grid"),
          (value) => `${value} \xD7 ${value}`
        ),
        this.rangeField(
          this.s("editor:field:revealseconds"),
          options.revealSeconds,
          1,
          30,
          1,
          (value) => {
            options.revealSeconds = value;
            this.callbacks.onChange();
          },
          this.s("editor:unit:seconds")
        )
      );
      target.append(
        grid,
        this.renderStringList(
          question2,
          this.s("editor:field:acceptedanswers"),
          options.acceptedAnswers,
          this.s("editor:action:addacceptedanswer"),
          12
        )
      );
    }
    renderBrainstorm(question2, target) {
      const options = question2.options;
      const grid = element("div", "quizgeist-field-grid quizgeist-field-grid--three");
      grid.append(
        this.numberField(
          this.s("editor:field:collectseconds"),
          options.collectSeconds,
          15,
          600,
          5,
          (value) => {
            options.collectSeconds = value;
            this.callbacks.onChange();
          },
          this.s("editor:unit:seconds")
        ),
        this.selectField(
          this.s("editor:field:grouping"),
          options.grouping,
          [
            ["manual", this.s("editor:grouping:manual")],
            ["ai", this.s("editor:grouping:ai")]
          ],
          (value) => {
            options.grouping = value === "ai" ? "ai" : "manual";
            this.callbacks.onChange();
          },
          this.s("editor:grouping:hint")
        ),
        this.numberField(
          this.s("editor:field:voteseconds"),
          options.voteSeconds,
          15,
          600,
          5,
          (value) => {
            options.voteSeconds = value;
            this.callbacks.onChange();
          },
          this.s("editor:unit:seconds")
        )
      );
      target.append(grid);
    }
    renderOpen(question2, target) {
      const options = question2.options;
      target.append(element("div", "quizgeist-info-box", {
        text: this.s("editor:open:nopoints")
      }));
      const sample = element("textarea", "quizgeist-textarea", {
        rows: 4,
        maxlength: 6e3,
        value: options.sampleAnswer
      });
      sample.addEventListener("input", () => {
        options.sampleAnswer = sample.value;
        this.callbacks.onChange();
      });
      target.append(labelledField(
        this.s("editor:field:sampleanswer"),
        sample,
        this.s("editor:field:sampleanswer:hint")
      ));
      this.renderStageCheckSwitch(question2, target);
    }
    /**
     * F13 Bühnen-Check: the one teacher-facing switch of the sub-mode.
     *
     * Three states, in the order of P11_PLAN.md 2.6:
     *   - addon code absent      -> nothing is rendered at all (no locked bait)
     *   - licence read-only, off -> nothing is rendered either
     *   - licence read-only, on  -> rendered WITH a plain sentence: existing
     *     content stays editable and switchable-off, switching it back on needs
     *     an active licence. No raw code, no dead end (F16).
     *
     * @param question The edited question.
     * @param target Container of the type-specific fields.
     * @returns void
     */
    renderStageCheckSwitch(question2, target) {
      var _a;
      const availability = (_a = this.config.features) == null ? void 0 : _a.buehne;
      const options = question2.options;
      const current = options.stageCheck === true;
      if (availability === void 0 || availability.installed !== true) {
        return;
      }
      if (availability.canCreate !== true && !current) {
        return;
      }
      const field = this.renderSwitch(
        this.s("editor:field:stagecheck"),
        this.s("editor:field:stagecheck:hint"),
        current,
        (checked) => {
          options.stageCheck = checked;
          this.callbacks.onChange();
        }
      );
      if (availability.canCreate !== true) {
        field.append(element("p", "quizgeist-field__hint", {
          text: this.s("editor:field:stagecheck:locked")
        }));
      }
      field.dataset.quizgeistView = "stage-setup";
      target.append(field);
    }
    renderSlide(question2, target) {
      const options = question2.options;
      const layout = this.selectField(
        this.s("editor:field:slidelayout"),
        options.layout,
        [
          ["title", this.s("editor:layout:title")],
          ["text-image", this.s("editor:layout:textimage")],
          ["bullets", this.s("editor:layout:bullets")],
          ["quote", this.s("editor:layout:quote")],
          ["video", this.s("editor:layout:video")],
          ["fullscreen", this.s("editor:layout:fullscreen")]
        ],
        (value) => {
          options.layout = value;
          this.callbacks.onChange();
          this.refreshCurrentForm(question2);
        }
      );
      target.append(layout);
      const title = element("input", "quizgeist-input", {
        type: "text",
        maxlength: 500,
        value: options.title
      });
      title.addEventListener("input", () => {
        options.title = title.value;
        question2.questiontext = title.value;
        this.callbacks.onChange();
      });
      target.append(labelledField(this.s("editor:field:slidetitle"), title));
      if (options.layout === "bullets") {
        target.append(this.renderStringList(
          question2,
          this.s("editor:field:bullets"),
          options.bullets,
          this.s("editor:action:addbullet"),
          12
        ));
      } else if (options.layout === "quote") {
        const quote = element("textarea", "quizgeist-textarea", {
          rows: 4,
          maxlength: 3e3,
          value: options.quote
        });
        quote.addEventListener("input", () => {
          options.quote = quote.value;
          this.callbacks.onChange();
        });
        const attribution = element("input", "quizgeist-input", {
          type: "text",
          maxlength: 300,
          value: options.attribution
        });
        attribution.addEventListener("input", () => {
          options.attribution = attribution.value;
          this.callbacks.onChange();
        });
        target.append(
          labelledField(this.s("editor:field:quote"), quote),
          labelledField(this.s("editor:field:attribution"), attribution)
        );
      } else {
        const body = element("textarea", "quizgeist-textarea", {
          rows: 6,
          maxlength: 8e3,
          value: options.body
        });
        body.addEventListener("input", () => {
          options.body = body.value;
          this.callbacks.onChange();
        });
        target.append(labelledField(
          this.s(options.layout === "video" ? "editor:field:videocaption" : "editor:field:slidebody"),
          body
        ));
      }
      target.append(this.renderSwitch(
        this.s("editor:field:reactions"),
        this.s("editor:field:reactions:hint"),
        options.reactions,
        (checked) => {
          options.reactions = checked;
          this.callbacks.onChange();
        }
      ));
      this.renderStageCheckSwitch(question2, target);
    }
    renderAnswers(question2, answers, correctable) {
      const options = question2.options;
      const fieldset = element("fieldset", "quizgeist-repeater quizgeist-answer-editor");
      fieldset.append(element("legend", "quizgeist-field__label", {
        text: this.s("editor:field:answers")
      }));
      answers.forEach((answer, index) => {
        const row = element("div", "quizgeist-answer-row");
        row.dataset.answerSlot = String(index);
        const shape = element("span", `quizgeist-answer-shape quizgeist-answer-shape--${index + 1}`, {
          "aria-hidden": "true"
        });
        const input = element("input", "quizgeist-input", {
          type: "text",
          maxlength: 1e3,
          value: answer.text,
          "aria-label": `${this.s("editor:field:answer")} ${index + 1}`
        });
        input.addEventListener("input", () => {
          answer.text = input.value;
          this.callbacks.onChange();
        });
        const media = this.renderInlineMediaControl(
          question2,
          answer.media,
          answer.id,
          index + 1
        );
        row.append(shape, input, media);
        if (correctable) {
          const correctLabel = element("label", "quizgeist-correct-choice");
          const correct = element("input", "", {
            type: options.multiple ? "checkbox" : "radio",
            name: options.multiple ? void 0 : `correct-answer-${question2.id}`,
            checked: Boolean(answer.correct),
            "aria-label": `${this.s("editor:field:correct")} ${index + 1}`
          });
          correct.addEventListener("change", () => {
            if (options.multiple) {
              answer.correct = correct.checked;
            } else if (correct.checked) {
              answers.forEach((item) => {
                item.correct = item === answer;
              });
            }
            this.callbacks.onChange();
            this.refreshCurrentForm(question2);
          });
          appendChildren(correctLabel, correct, element("span", "", {
            text: this.s("editor:field:correct")
          }));
          row.append(correctLabel);
        }
        const remove = button(
          this.s("editor:action:remove"),
          "quizgeist-button quizgeist-button--danger-quiet",
          () => {
            if (answers.length <= 2) {
              return;
            }
            answers.splice(index, 1);
            if (correctable && !answers.some((entry) => entry.correct)) {
              answers[0].correct = true;
            }
            this.callbacks.onChange();
            this.refreshCurrentForm(question2);
          }
        );
        remove.disabled = answers.length <= 2;
        row.append(remove);
        fieldset.append(row);
      });
      const add = button(
        this.s("editor:action:addanswer"),
        "quizgeist-button quizgeist-button--secondary",
        () => {
          if (answers.length >= 6) {
            return;
          }
          answers.push({
            id: this.nextId(answers),
            text: "",
            media: null,
            ...correctable ? { correct: false } : {}
          });
          this.callbacks.onChange();
          this.refreshCurrentForm(question2);
        }
      );
      add.disabled = answers.length >= 6;
      fieldset.append(add);
      return fieldset;
    }
    renderStringList(question2, labelText, values, addLabel, maximum) {
      const fieldset = element("fieldset", "quizgeist-repeater");
      fieldset.append(element("legend", "quizgeist-field__label", { text: labelText }));
      values.forEach((value, index) => {
        const row = element("div", "quizgeist-repeater__row quizgeist-repeater__row--simple");
        const input = element("input", "quizgeist-input", {
          type: "text",
          maxlength: 1e3,
          value,
          "aria-label": `${labelText} ${index + 1}`
        });
        input.addEventListener("input", () => {
          values[index] = input.value;
          this.callbacks.onChange();
        });
        const remove = button(
          this.s("editor:action:remove"),
          "quizgeist-button quizgeist-button--danger-quiet",
          () => {
            if (values.length <= 1) {
              return;
            }
            values.splice(index, 1);
            this.callbacks.onChange();
            this.refreshCurrentForm(question2);
          }
        );
        remove.disabled = values.length <= 1;
        row.append(input, remove);
        fieldset.append(row);
      });
      const add = button(addLabel, "quizgeist-button quizgeist-button--secondary", () => {
        if (values.length >= maximum) {
          return;
        }
        values.push("");
        this.callbacks.onChange();
        this.refreshCurrentForm(question2);
      });
      add.disabled = values.length >= maximum;
      fieldset.append(add);
      return fieldset;
    }
    renderMediaControl(question2, getter, labelText) {
      const wrapper = element("div", "quizgeist-media-control");
      const heading = element("div", "quizgeist-media-control__heading");
      heading.append(
        element("span", "quizgeist-field__label", { text: labelText }),
        element("span", "quizgeist-field__hint", { text: this.s("editor:field:media:hint") })
      );
      wrapper.append(heading);
      const manage = button(
        this.s("editor:action:managemedia"),
        "quizgeist-button quizgeist-button--secondary quizgeist-media-control__button",
        () => this.callbacks.onMedia("question")
      );
      wrapper.append(manage);
      const selected = this.selectedMedia(question2, getter());
      if (selected) {
        const preview = element("figure", "quizgeist-media-preview");
        preview.append(
          this.renderMediaElement(selected),
          element("figcaption", "", { text: selected.filename })
        );
        wrapper.append(preview);
      } else {
        wrapper.append(element("p", "quizgeist-media-empty", {
          text: this.s("editor:media:none")
        }));
      }
      return wrapper;
    }
    renderInlineMediaControl(question2, current, answerId, index) {
      const wrapper = element("div", "quizgeist-answer-media");
      const selected = this.selectedMedia(question2, current);
      if (selected) {
        wrapper.append(this.renderMediaElement(selected, true));
      }
      const choose = button(
        selected ? this.s("editor:action:changemedia") : this.s("editor:action:addmedia"),
        "quizgeist-button quizgeist-button--quiet quizgeist-answer-media__button",
        () => this.callbacks.onMedia(`answers/${answerId}`)
      );
      choose.setAttribute(
        "aria-label",
        `${this.s("editor:field:answerimage")} ${index}`
      );
      wrapper.append(choose);
      return wrapper;
    }
    renderMediaElement(file, compact = false) {
      var _a;
      const mimetype = file.mimetype || "";
      const extension = ((_a = file.filename.split(".").pop()) == null ? void 0 : _a.toLowerCase()) || "";
      const className = compact ? "quizgeist-media-preview__asset quizgeist-media-preview__asset--compact" : "quizgeist-media-preview__asset";
      if (mimetype.startsWith("audio/") || ["mp3", "ogg", "wav"].includes(extension)) {
        return element("audio", className, {
          src: file.url,
          controls: true,
          preload: "metadata",
          "aria-label": file.filename
        });
      }
      if (mimetype.startsWith("video/") || ["mp4", "webm"].includes(extension)) {
        return element("video", className, {
          src: file.url,
          controls: true,
          preload: "metadata",
          playsinline: true,
          "aria-label": file.filename
        });
      }
      return element("img", className, {
        src: file.url,
        alt: file.filename,
        loading: "lazy"
      });
    }
    mediaPath(file) {
      if (file.path) {
        return file.path;
      }
      const filepath = file.filepath && file.filepath !== "/" ? file.filepath : "";
      return `${filepath}${file.filename}`;
    }
    selectedMedia(question2, value) {
      if (!value) {
        return null;
      }
      return question2.files.find((file) => {
        const path = this.mediaPath(file);
        return path === value || file.filename === value || file.url === value;
      }) || null;
    }
    getMedia(question2) {
      return question2.options.media;
    }
    renderTimeLimit(question2) {
      if (question2.qtype === "slide") {
        question2.timelimit = 0;
      }
      if (question2.qtype === "slide") {
        return this.rangeField(
          this.s("editor:field:timelimit"),
          5,
          5,
          240,
          5,
          () => void 0,
          "",
          () => this.s("editor:time:none"),
          true
        );
      }
      return this.rangeField(
        this.s("editor:field:timelimit"),
        Math.max(5, Math.min(240, question2.timelimit)),
        5,
        240,
        5,
        (value) => {
          question2.timelimit = value;
          this.callbacks.onChange();
        },
        this.s("editor:unit:seconds")
      );
    }
    renderPointMode(question2) {
      const disabled = NON_SCORING_TYPES.includes(question2.qtype);
      if (disabled) {
        question2.pointmode = "none";
      }
      const field = this.selectField(
        this.s("editor:field:pointmode"),
        question2.pointmode,
        [
          ["standard", this.s("editor:pointmode:standard")],
          ["double", this.s("editor:pointmode:double")],
          ["none", this.s("editor:pointmode:none")]
        ],
        (value) => {
          question2.pointmode = value;
          this.callbacks.onChange();
        },
        disabled ? this.s("editor:pointmode:forcednone") : void 0
      );
      const select = field.querySelector("select");
      if (select) {
        select.disabled = disabled;
      }
      return field;
    }
    renderSwitch(labelText, hint, checked, change) {
      const wrapper = element("div", "quizgeist-switch-field");
      const label = element("label", "quizgeist-switch");
      const input = element("input", "quizgeist-switch__input", {
        type: "checkbox",
        checked
      });
      input.addEventListener("change", () => change(input.checked));
      const track = element("span", "quizgeist-switch__track", { "aria-hidden": "true" });
      const text4 = element("span", "quizgeist-switch__label", { text: labelText });
      appendChildren(label, input, track, text4);
      wrapper.append(label, element("p", "quizgeist-field__hint", { text: hint }));
      return wrapper;
    }
    selectField(labelText, value, values, change, hint) {
      const select = element("select", "quizgeist-select");
      for (const [optionValue, optionLabel] of values) {
        select.append(element("option", "", {
          value: optionValue,
          text: optionLabel
        }));
      }
      select.value = value;
      select.addEventListener("change", () => change(select.value));
      return labelledField(labelText, select, hint);
    }
    numberField(labelText, value, min, max, step, change, suffix) {
      const input = element("input", "quizgeist-input", {
        type: "number",
        value,
        min,
        max,
        step
      });
      input.addEventListener("input", () => {
        const parsed = Number(input.value);
        if (Number.isFinite(parsed)) {
          change(parsed);
        }
      });
      const control = suffix ? (() => {
        const wrapper = element("div", "quizgeist-input-suffix");
        wrapper.append(input, element("span", "", { text: suffix }));
        return wrapper;
      })() : input;
      return labelledField(labelText, control);
    }
    rangeField(labelText, value, min, max, step, change, suffix = "", format, disabled = false) {
      const field = element("div", "quizgeist-field quizgeist-range-field");
      const id = `quizgeist-range-${crypto.randomUUID()}`;
      const label = element("label", "quizgeist-field__label", {
        text: labelText,
        for: id
      });
      const control = element("div", "quizgeist-range-control");
      const input = element("input", "quizgeist-range", {
        id,
        type: "range",
        value,
        min,
        max,
        step,
        disabled
      });
      const displayValue = (current) => format ? format(current) : `${current}${suffix ? ` ${suffix}` : ""}`;
      const output = element("output", "quizgeist-range-output", {
        for: id,
        text: displayValue(value)
      });
      input.setAttribute("aria-valuetext", displayValue(value));
      input.addEventListener("input", () => {
        const parsed = Number(input.value);
        if (!Number.isFinite(parsed)) {
          return;
        }
        output.value = displayValue(parsed);
        output.textContent = displayValue(parsed);
        input.setAttribute("aria-valuetext", displayValue(parsed));
        change(parsed);
      });
      control.append(input, output);
      field.append(label, control);
      return field;
    }
    nextId(items) {
      var _a;
      const used = new Set(items.map((item) => item.id));
      let id;
      do {
        this.itemIdSequence += 1;
        const randomValues = ((_a = globalThis.crypto) == null ? void 0 : _a.getRandomValues) ? globalThis.crypto.getRandomValues(new Uint32Array(2)) : null;
        const entropy = randomValues ? Array.from(randomValues, (value) => value.toString(36)).join("") : Math.random().toString(36).slice(2);
        id = `i${Date.now().toString(36)}_${this.itemIdSequence.toString(36)}_${entropy}`.slice(0, 32).toLowerCase();
      } while (used.has(id) || this.issuedItemIds.has(id));
      this.issuedItemIds.add(id);
      return id;
    }
    moveArrayItem(question2, items, from, to) {
      if (from < 0 || from >= items.length || to < 0 || to >= items.length || from === to) {
        return;
      }
      const [moved] = items.splice(from, 1);
      items.splice(to, 0, moved);
      this.callbacks.onChange();
      this.refreshCurrentForm(question2);
    }
    refreshCurrentForm(question2) {
      const current = document.querySelector(
        `.quizgeist-question-form[data-question-id="${question2.id}"]`
      );
      current == null ? void 0 : current.replaceWith(this.render(question2));
    }
  };

  // src/editor/readiness.ts
  function isPlayableQuestion(question2, liveSupportedTypes) {
    return question2.status === "ready" && liveSupportedTypes.includes(question2.qtype);
  }
  function countPlayableQuestions(questions, liveSupportedTypes) {
    return questions.filter((question2) => isPlayableQuestion(question2, liveSupportedTypes)).length;
  }

  // src/editor/tags.ts
  var ERROR_FAMILIES = {
    duplicate: "tag:error:duplicate",
    invalid: "tag:error:invalid",
    out_of_range: "tag:error:out_of_range",
    required: "tag:error:required",
    too_many: "tag:error:too_many"
  };
  function tagErrorMessage(strings, errors) {
    if (!errors.length) {
      return "";
    }
    const first = errors[0];
    const familyKey = ERROR_FAMILIES[first.code] || "tag:error:generic";
    return editorString(
      strings,
      familyKey,
      editorString(
        strings,
        "tag:error:generic",
        "Die Merkmale konnten nicht gespeichert werden."
      )
    );
  }
  var TagStore = class {
    constructor(api, strings) {
      this.api = api;
      this.strings = strings;
      __publicField(this, "tags", []);
      __publicField(this, "byRoot", /* @__PURE__ */ new Map());
      __publicField(this, "loaded", false);
    }
    isLoaded() {
      return this.loaded;
    }
    all() {
      return this.tags.slice();
    }
    ofKind(kind) {
      return this.tags.filter((tag) => tag.kind === kind);
    }
    assignmentsFor(rootId) {
      return (this.byRoot.get(rootId) || []).slice();
    }
    async load(signal) {
      const result = await this.api.post("tag_list", {}, signal);
      this.tags = Array.isArray(result.tags) ? result.tags : [];
      this.byRoot = /* @__PURE__ */ new Map();
      (Array.isArray(result.assignments) ? result.assignments : []).forEach((entry) => {
        this.byRoot.set(
          Number(entry.rootId),
          Array.isArray(entry.tags) ? entry.tags : []
        );
      });
      this.loaded = true;
    }
    /**
     * Replace the complete assignment list of one question.
     *
     * The caller passes the exact question version it is editing; the server
     * resolves the root. The returned message is always readable text.
     */
    async saveForQuestion(questionId, tagIds) {
      const result = await this.api.post(
        "question_tags_save",
        {
          questionId,
          tags: tagIds.map((tagId) => ({ tagId, weight: 100 }))
        }
      );
      const errors = Array.isArray(result.validationErrors) ? result.validationErrors : [];
      if (errors.length) {
        return { message: tagErrorMessage(this.strings, errors), ok: false };
      }
      this.byRoot.set(
        Number(result.rootId),
        Array.isArray(result.tags) ? result.tags : []
      );
      return {
        message: editorString(
          this.strings,
          "tag:saved",
          "Die Merkmale sind gespeichert."
        ),
        ok: true
      };
    }
  };
  function renderTagPicker(store, strings, questionId, rootId, onSaved) {
    const block = element("fieldset", "quizgeist-editor-tags");
    block.dataset.editorTags = "";
    const legend = element("legend", "quizgeist-editor-tags__legend");
    legend.textContent = editorString(strings, "tag", "Merkmale");
    block.append(legend);
    const available = store.all();
    if (!available.length) {
      const empty = element("p", "quizgeist-editor-tags__empty");
      empty.textContent = editorString(
        strings,
        "tag:empty",
        "F\xFCr diese Aktivit\xE4t sind noch keine Merkmale angelegt."
      );
      block.append(empty);
      return block;
    }
    const assigned = new Set(
      store.assignmentsFor(rootId).map((entry) => entry.tagId)
    );
    const list = element("div", "quizgeist-editor-tags__list");
    const inputs = [];
    available.forEach((tag) => {
      const id = `quizgeist-tag-${questionId}-${tag.id}`;
      const row = element("div", "quizgeist-editor-tags__item");
      const input = element("input", "quizgeist-editor-tags__checkbox");
      input.type = "checkbox";
      input.id = id;
      input.value = String(tag.id);
      input.checked = assigned.has(tag.id);
      input.dataset.tagId = String(tag.id);
      const label = element("label", "quizgeist-editor-tags__label");
      label.htmlFor = id;
      label.textContent = tag.label;
      if (tag.colorKey) {
        label.dataset.colorKey = tag.colorKey;
      }
      const kind = element("span", "quizgeist-editor-tags__kind");
      kind.textContent = editorString(
        strings,
        `tag:kind:${tag.kind}`,
        String(tag.kind)
      );
      row.append(input, label, kind);
      list.append(row);
      inputs.push(input);
    });
    block.append(list);
    const save = button(
      editorString(strings, "tag:action:save", "Merkmale speichern"),
      "quizgeist-editor-button"
    );
    save.dataset.action = "save-tags";
    save.addEventListener("click", () => {
      const selected = inputs.filter((input) => input.checked).map((input) => Number(input.value));
      save.disabled = true;
      void store.saveForQuestion(questionId, selected).then((result) => {
        onSaved(result.message, result.ok);
      }).catch(() => {
        onSaved(
          editorString(
            strings,
            "tag:error:generic",
            "Die Merkmale konnten nicht gespeichert werden."
          ),
          false
        );
      }).finally(() => {
        save.disabled = false;
      });
    });
    block.append(save);
    return block;
  }

  // src/workshop/student-form.ts
  var STATES = [
    "submitted",
    "revising",
    "approved",
    "rejected"
  ];
  var text = (value) => typeof value === "string" ? value : "";
  var count = (value) => typeof value === "number" && Number.isFinite(value) && value > 0 ? Math.floor(value) : 0;
  function normaliseWorkshopView(raw) {
    const empty = {
      canCurate: false,
      mine: [],
      openRatings: 0,
      peers: [],
      queue: [],
      ratingRange: { max: 5, min: 1 }
    };
    if (raw === null || typeof raw !== "object") {
      return empty;
    }
    const record3 = raw;
    const range = record3.ratingRange !== null && typeof record3.ratingRange === "object" ? record3.ratingRange : {};
    return {
      canCurate: record3.canCurate === true,
      mine: normaliseSubmissions(record3.mine),
      openRatings: count(record3.openRatings),
      peers: normaliseSubmissions(record3.peers),
      queue: normaliseSubmissions(record3.queue),
      ratingRange: {
        max: count(range.max) || 5,
        min: count(range.min) || 1
      }
    };
  }
  function normaliseSubmissions(raw) {
    return Array.isArray(raw) ? raw.map(normaliseSubmission).filter((entry) => entry !== null) : [];
  }
  function normaliseSubmission(raw) {
    if (raw === null || typeof raw !== "object") {
      return null;
    }
    const record3 = raw;
    const id = count(record3.id);
    const state = text(record3.state);
    if (id === 0 || !STATES.includes(state)) {
      return null;
    }
    const average = (value) => typeof value === "number" && Number.isFinite(value) ? value : null;
    const own = record3.ownRating !== null && typeof record3.ownRating === "object" ? record3.ownRating : null;
    return {
      aiCheck: normaliseAiCheck(record3.aiCheck),
      authorName: text(record3.authorName),
      averageDifficulty: average(record3.averageDifficulty),
      averageQuality: average(record3.averageQuality),
      curatorNote: text(record3.curatorNote),
      explanation: text(record3.explanation),
      id,
      ownRating: own === null ? null : {
        comment: text(own.comment),
        difficulty: count(own.difficulty),
        quality: count(own.quality)
      },
      qtype: text(record3.qtype),
      questionId: count(record3.questionId),
      questionStatus: text(record3.questionStatus),
      questionText: text(record3.questionText),
      ratingCount: count(record3.ratingCount),
      rootId: count(record3.rootId),
      state,
      timeDecided: count(record3.timeDecided),
      timeSubmitted: count(record3.timeSubmitted)
    };
  }
  function normaliseAiCheck(raw) {
    if (raw === null || typeof raw !== "object") {
      return null;
    }
    const record3 = raw;
    const checks = Array.isArray(record3.checks) ? record3.checks.flatMap((entry) => {
      if (entry === null || typeof entry !== "object") {
        return [];
      }
      const item = entry;
      const key = text(item.key);
      return key === "" ? [] : [{
        key,
        note: text(item.note),
        verdict: text(item.verdict) === "ok" ? "ok" : "attention"
      }];
    }) : [];
    return checks.length === 0 ? null : { checks, origin: text(record3.origin) };
  }
  function workshopErrorMessage(error, t) {
    if (error.field === "explanation" && error.code === "required") {
      return t(
        "workshop:error:explanation:required",
        "Schreibe dazu, warum die L\xF6sung richtig ist. Genau das ist der Teil, an dem man lernt."
      );
    }
    if (error.field === "explanation" && error.code === "too_short") {
      return t(
        "workshop:error:explanation:tooshort",
        "Deine Erkl\xE4rung ist noch sehr kurz \u2014 ein oder zwei S\xE4tze mehr helfen den anderen."
      );
    }
    if (error.field === "questiontext") {
      return t("workshop:error:questiontext", "Die Frage selbst fehlt noch.");
    }
    if (error.field.startsWith("options.answers")) {
      return t(
        "workshop:error:answers",
        "Jede Antwortm\xF6glichkeit braucht einen Text, und genau eine davon muss richtig sein."
      );
    }
    if (error.code === "not_submittable") {
      return t(
        "workshop:error:qtype",
        "Dieser Fragetyp l\xE4sst sich in der Werkstatt nicht einreichen."
      );
    }
    if (error.code === "self_rating") {
      return t(
        "workshop:error:selfrating",
        "Die eigene Frage bewertest du nicht \u2014 das \xFCbernehmen die anderen."
      );
    }
    if (error.code === "requires_manage") {
      return t(
        "workshop:error:requiresmanage",
        "Zum Freigeben fehlt die Berechtigung, Fragen dieser Aktivit\xE4t zu bearbeiten."
      );
    }
    if (error.field === "note" && error.code === "required") {
      return t(
        "workshop:error:note:required",
        "Schreibe dazu, was \xFCberarbeitet werden soll."
      );
    }
    return t(
      "workshop:error:generic",
      "Die Eingabe wurde nicht angenommen. Bitte sieh sie noch einmal durch."
    );
  }
  function readFieldErrors(raw) {
    if (raw === null || typeof raw !== "object") {
      return [];
    }
    const list = raw.validationErrors;
    if (!Array.isArray(list)) {
      return [];
    }
    return list.flatMap((entry) => {
      if (entry === null || typeof entry !== "object") {
        return [];
      }
      const record3 = entry;
      const field = text(record3.field);
      const code = text(record3.code);
      return field === "" || code === "" ? [] : [{ code, field }];
    });
  }
  function stateSentence(state, t) {
    if (state === "approved") {
      return t("workshop:state:approved", "Freigegeben \u2014 deine Frage ist im Spiel.");
    }
    if (state === "rejected") {
      return t("workshop:state:rejected", "Nicht \xFCbernommen.");
    }
    if (state === "revising") {
      return t("workshop:state:revising", "Zur\xFCck zur \xDCberarbeitung.");
    }
    return t("workshop:state:submitted", "Eingereicht \u2014 wartet auf die Lehrkraft.");
  }

  // src/workshop/curator-list.ts
  function renderCuratorList(view, t, onDecide, onCheck = null) {
    const section = liveElement("section", "quizgeist-workshop-curate", {
      "data-quizgeist-view": "workshop-curate",
      "aria-labelledby": "quizgeist-workshop-curate-title"
    });
    const heading = liveElement("h3", "quizgeist-workshop-curate__title", {
      text: t("workshop:curate:title", "Fragenwerkstatt")
    });
    heading.id = "quizgeist-workshop-curate-title";
    section.append(
      heading,
      liveElement("p", "quizgeist-workshop-curate__copy", {
        text: t(
          "workshop:curate:copy",
          "Eingereichte Sch\xFClerfragen bleiben Entw\xFCrfe, bis Sie sie freigeben. Erst dann sind sie spielbar."
        )
      })
    );
    const errors = liveElement("div", "quizgeist-workshop-errors", { role: "alert" });
    section.append(errors);
    if (view.queue.length === 0) {
      section.append(liveElement("p", "quizgeist-workshop-empty", {
        text: t("workshop:curate:empty", "Zurzeit liegt keine Einreichung vor.")
      }));
      return section;
    }
    const list = liveElement("ul", "quizgeist-workshop-list");
    view.queue.forEach((submission) => {
      list.append(renderQueueItem(submission, t, onDecide, onCheck));
    });
    section.append(list);
    return section;
  }
  function renderQueueItem(submission, t, onDecide, onCheck) {
    var _a, _b;
    const item = liveElement("li", "quizgeist-workshop-item", {
      "data-workshop-state": submission.state,
      "data-workshop-id": submission.id
    });
    item.append(
      liveElement("p", "quizgeist-workshop-item__author", {
        text: submission.authorName
      }),
      liveElement("p", "quizgeist-workshop-item__question", {
        text: submission.questionText
      }),
      liveElement("p", "quizgeist-workshop-item__explanation", {
        text: submission.explanation
      }),
      liveElement("p", "quizgeist-workshop-item__state", {
        text: stateSentence(submission.state, t)
      })
    );
    if (submission.ratingCount > 0) {
      item.append(liveElement("p", "quizgeist-workshop-item__figures", {
        text: t(
          "workshop:curate:figures",
          "{$count} R\xFCckmeldungen \xB7 Qualit\xE4t {$quality} \xB7 Schwierigkeit {$difficulty}",
          {
            count: submission.ratingCount,
            difficulty: String((_a = submission.averageDifficulty) != null ? _a : 0),
            quality: String((_b = submission.averageQuality) != null ? _b : 0)
          }
        )
      }));
    }
    if (submission.aiCheck !== null) {
      item.append(renderAiCheck(submission.aiCheck, t));
    }
    const note = liveElement("input", "quizgeist-workshop-input", {
      type: "text",
      maxlength: 1e3,
      value: submission.curatorNote,
      "aria-label": t("workshop:curate:note", "R\xFCckmeldung an die Lernenden")
    });
    const actions = liveElement("div", "quizgeist-workshop-actions");
    const decision = (state, label, variant) => {
      const control = liveButton(
        label,
        `quizgeist-button quizgeist-button--${variant}`,
        { "data-workshop-action": state }
      );
      control.addEventListener("click", () => onDecide(submission.id, state, note.value));
      return control;
    };
    actions.append(
      decision("approved", t("workshop:curate:approve", "Freigeben"), "primary"),
      decision("revising", t("workshop:curate:revise", "Zur\xFCck zur \xDCberarbeitung"), "secondary"),
      decision("rejected", t("workshop:curate:reject", "Nicht \xFCbernehmen"), "quiet-danger")
    );
    if (onCheck !== null) {
      const check = liveButton(
        t("workshop:curate:aicheck", "KI-Vorpr\xFCfung"),
        "quizgeist-button quizgeist-button--secondary",
        { "data-workshop-action": "aicheck" }
      );
      check.addEventListener("click", () => onCheck(submission.id));
      actions.append(check);
    }
    item.append(note, actions);
    return item;
  }
  function renderAiCheck(check, t) {
    const box = liveElement("div", "quizgeist-workshop-aicheck", {
      "data-workshop-aicheck": check.origin === "gateway" ? "gateway" : "fallback"
    });
    box.append(liveElement("p", "quizgeist-workshop-aicheck__lead", {
      text: check.origin === "gateway" ? t("workshop:aicheck:gateway", "KI-Hinweis (Vorschlag, keine Entscheidung):") : t("workshop:aicheck:fallback", "Regelpr\xFCfung ohne KI (Vorschlag, keine Entscheidung):")
    }));
    const list = liveElement("ul", "quizgeist-workshop-aicheck__list");
    check.checks.forEach((entry) => {
      const row = liveElement("li", "quizgeist-workshop-aicheck__item", {
        "data-aicheck-verdict": entry.verdict
      });
      row.append(
        liveElement("span", "quizgeist-workshop-aicheck__mark", {
          "aria-hidden": "true",
          text: entry.verdict === "ok" ? "\u25B2" : "\u25A0"
        }),
        liveElement("span", "quizgeist-workshop-aicheck__text", {
          text: checkSentence(entry.key, entry.verdict, entry.note, t)
        })
      );
      list.append(row);
    });
    box.append(list);
    return box;
  }
  function checkSentence(key, verdict, note, t) {
    const name = key === "comprehensible" ? t("workshop:aicheck:comprehensible", "Verst\xE4ndlichkeit") : key === "unique_solution" ? t("workshop:aicheck:unique", "Eindeutigkeit der L\xF6sung") : key === "explanation_complete" ? t("workshop:aicheck:explanation", "Vollst\xE4ndigkeit der Erkl\xE4rung") : t("workshop:aicheck:other", "Weitere Beobachtung");
    const state = verdict === "ok" ? t("workshop:aicheck:ok", "unauff\xE4llig") : t("workshop:aicheck:attention", "einen Blick wert");
    return note === "" ? `${name}: ${state}` : `${name}: ${state} \u2014 ${note}`;
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
    async requestAudio(value, selectedVoice, generation) {
      var _a;
      const form = new FormData();
      form.append("action", "speak");
      form.append("sesskey", String(this.config.sesskey || ""));
      form.append("text", value);
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
    const button2 = liveButton(
      playLabel,
      "quizgeist-live-tts__button",
      {
        "aria-pressed": "false",
        "data-live-tts-toggle": true,
        disabled: !player.isAvailable() || content.trim() === ""
      }
    );
    if (button2.disabled) {
      button2.title = text2(
        config,
        "live:tts:unavailable",
        "Vorlesen ist f\xFCr dieses Nutzerkonto nicht verf\xFCgbar."
      );
    }
    button2.addEventListener("click", () => {
      if (player.isPlaying()) {
        player.stop();
        button2.textContent = playLabel;
        button2.setAttribute("aria-pressed", "false");
        status.textContent = "";
        return;
      }
      button2.textContent = stopLabel;
      button2.setAttribute("aria-pressed", "true");
      status.textContent = stopLabel;
      void player.play(content).catch((error) => {
        if (!(error instanceof DOMException && error.name === "AbortError")) {
          status.textContent = error instanceof Error ? error.message : text2(config, "live:tts:error", "Der Text konnte nicht vorgelesen werden.");
        }
      }).finally(() => {
        button2.textContent = playLabel;
        button2.setAttribute("aria-pressed", "false");
        if (status.textContent === stopLabel) {
          status.textContent = "";
        }
      });
    });
    wrapper.append(button2, status);
    return wrapper;
  }

  // src/editor/editor-app.ts
  var FILE_AI_SOURCES = [
    "pdf",
    "pdf_questions",
    "slides",
    "handwriting"
  ];
  var EditorApp = class {
    constructor(root, config) {
      this.root = root;
      this.config = config;
      __publicField(this, "api");
      __publicField(this, "tts");
      __publicField(this, "activity", {
        allowbacktrack: true,
        background: [],
        id: 0,
        logo: [],
        name: "",
        season: "herbst",
        theme: "hell",
        timemodified: 0
      });
      __publicField(this, "availableThemes", [
        "hell",
        "dunkel",
        "weltraum",
        "ozean",
        "retro-arcade"
      ]);
      __publicField(this, "availableSeasons", []);
      __publicField(this, "questions", []);
      __publicField(this, "selectedQuestionId", null);
      __publicField(this, "view");
      __publicField(this, "saveState", "saved");
      __publicField(this, "saveError", "");
      __publicField(this, "saveStatusElement", null);
      __publicField(this, "statusLiveElement", null);
      __publicField(this, "toastRegion", null);
      __publicField(this, "workshop", null);
      __publicField(this, "autosaveTimer", null);
      __publicField(this, "saveLoopRunning", false);
      __publicField(this, "saveLoopWaiters", []);
      __publicField(this, "activityDirtyVersion", 0);
      __publicField(this, "activitySavedVersion", 0);
      __publicField(this, "questionOrderDirtyVersion", 0);
      __publicField(this, "questionOrderSavedVersion", 0);
      __publicField(this, "questionDirtyVersions", /* @__PURE__ */ new Map());
      __publicField(this, "questionSavedVersions", /* @__PURE__ */ new Map());
      __publicField(this, "questionIdAliases", /* @__PURE__ */ new Map());
      __publicField(this, "draggedQuestionId", null);
      __publicField(this, "bootstrapController", null);
      __publicField(this, "aiGenerateController", null);
      __publicField(this, "aiExplanationController", null);
      __publicField(this, "aiExplanationDialog", null);
      __publicField(this, "aiDraft", null);
      __publicField(this, "aiDialog", null);
      __publicField(this, "aiSourceDialog", null);
      __publicField(this, "aiSourceSelection", null);
      __publicField(this, "aiSourceRequest", null);
      __publicField(this, "aiConfig", {
        acceptedDocuments: [],
        acceptedVision: [],
        aiSourcePickerUrl: "",
        available: false,
        entitlementStatus: "not_installed",
        fallbackAvailable: false,
        formats: [...AI_FORMATS],
        gatewayAvailable: false,
        installed: false,
        managedServerInstalled: false,
        maxPdfPages: 150,
        maxQuestions: 50,
        notice: "",
        outboundFetchAllowed: false,
        visionAvailable: false
      });
      __publicField(this, "templateSearchController", null);
      __publicField(this, "templateSearchTimer", null);
      __publicField(this, "mediaDialog", null);
      __publicField(this, "mediaDialogQuestionId", null);
      __publicField(this, "conflictDialog", null);
      __publicField(this, "questionDefaults", {});
      __publicField(this, "supportedTypes", []);
      __publicField(this, "liveSupportedTypes", []);
      __publicField(this, "liveSupportedTypeSet", /* @__PURE__ */ new Set());
      __publicField(this, "releaseResult", "");
      __publicField(this, "releaseAllRunning", false);
      __publicField(this, "releasingQuestionIds", /* @__PURE__ */ new Set());
      __publicField(this, "formRenderer");
      /** U1 Tagging-Kern. Free of charge; hangs on no addon. */
      __publicField(this, "tagStore");
      this.api = new EditorApi(config);
      this.tts = new TtsPlayer(config);
      this.tagStore = new TagStore(this.api, config.strings);
      this.view = config.initialView === "templates" ? "templates" : "editor";
      this.formRenderer = new QuestionFormRenderer(config, {
        isAiAvailable: () => this.aiConfig.available,
        onAiExplanation: () => {
          const question2 = this.selectedQuestion();
          if (question2) {
            void this.openAiExplanation(question2);
          }
        },
        onChange: () => {
          const question2 = this.selectedQuestion();
          if (question2) {
            this.markQuestionDirty(question2.id);
            this.refreshQuestionTitle(question2);
          }
        },
        onMedia: (target) => {
          const question2 = this.selectedQuestion();
          if (question2) {
            void this.openMediaDialog("questionmedia", question2.id, target);
          }
        },
        onPreview: () => this.openPreview()
      });
    }
    s(key, fallback = "") {
      return editorString(this.config.strings, key, fallback);
    }
    /**
     * Editor string with the {$name} substitution the panels of P11 use.
     *
     * editorString() itself deliberately knows no placeholders; the workshop
     * list needs them, and re-implementing the lookup would create a second
     * place where a missing key could become visible.
     */
    workshopText(key, fallback, values = {}) {
      return Object.entries(values).reduce(
        (text4, [name, value]) => text4.split(`{$a->${name}}`).join(String(value)).split(`{$${name}}`).join(String(value)),
        this.s(key, fallback)
      );
    }
    async init() {
      this.root.dataset.quizgeistRoot = "edit";
      this.root.dataset.quizgeistTheme = this.config.theme || "hell";
      this.root.dataset.quizgeistSeason = this.config.season || "herbst";
      this.root.classList.add("quizgeist-editor-root");
      this.installGlobalListeners();
      this.renderLoading();
      await this.loadBootstrap();
    }
    installGlobalListeners() {
      window.addEventListener("beforeunload", (event) => {
        if (!this.hasUnsavedChanges()) {
          return;
        }
        event.preventDefault();
        event.returnValue = "";
      });
      window.addEventListener("online", () => {
        if (this.saveState === "error") {
          void this.runSaveLoop();
        }
      });
      window.addEventListener("message", (event) => {
        if (event.origin !== window.location.origin) {
          return;
        }
        const message = event.data;
        const cancelled = message && typeof message === "object" && message.type === "quizgeist-media-cancelled";
        if (cancelled) {
          if (this.mediaDialog) {
            closeDialog(this.mediaDialog);
            this.mediaDialog = null;
          }
          this.mediaDialogQuestionId = null;
          return;
        }
        const saved = message && typeof message === "object" && message.type === "quizgeist-media-saved";
        if (saved) {
          this.handleMediaSavedMessage(message);
          return;
        }
        const aiSourceSelected = message && typeof message === "object" && message.type === "quizgeist-ai-source-selected";
        if (aiSourceSelected) {
          this.handleAiSourceSelected(message, event.source);
          return;
        }
        const aiSourceReady = message && typeof message === "object" && message.type === "quizgeist-ai-source-ready";
        if (aiSourceReady) {
          this.handleAiSourceReady(message, event.source);
          return;
        }
        const aiSourceCancelled = message && typeof message === "object" && message.type === "quizgeist-ai-source-cancelled";
        if (aiSourceCancelled) {
          this.handleAiSourceCancelled(message, event.source);
        }
      });
    }
    renderLoading() {
      this.root.replaceChildren();
      const loading = element("div", "quizgeist-editor-loading", {
        role: "status",
        "aria-live": "polite"
      });
      const spinner = element("span", "quizgeist-spinner", { "aria-hidden": "true" });
      appendChildren(loading, spinner, this.s("editor:loading"));
      this.root.append(loading);
    }
    async loadBootstrap(preserveSelection = false) {
      var _a, _b, _c;
      (_a = this.bootstrapController) == null ? void 0 : _a.abort();
      this.bootstrapController = new AbortController();
      const previousSelection = preserveSelection ? this.selectedQuestionId : null;
      try {
        const data = await this.api.post(
          "editor_bootstrap",
          {},
          this.bootstrapController.signal
        );
        if (!Array.isArray(data.liveSupportedTypes)) {
          throw new Error(this.s("editor:error:response"));
        }
        this.questionDefaults = data.questionDefaults || {};
        void this.tagStore.load(this.bootstrapController.signal).then(() => this.render()).catch(() => void 0);
        void this.loadWorkshop(this.bootstrapController.signal).then(() => this.render()).catch(() => void 0);
        const defaultTypes = Object.keys(this.questionDefaults).filter(isQuestionType);
        const serverTypes = Array.isArray(data.supportedTypes) ? data.supportedTypes.filter(isQuestionType) : [];
        this.supportedTypes = serverTypes.filter((qtype) => Object.prototype.hasOwnProperty.call(this.questionDefaults, qtype));
        if (this.supportedTypes.length === 0) {
          this.supportedTypes = defaultTypes;
        }
        if (this.supportedTypes.length === 0) {
          throw new Error(this.s("editor:error:response"));
        }
        this.liveSupportedTypes = [...new Set(data.liveSupportedTypes.filter((qtype) => typeof qtype === "string" && qtype.trim() !== "").map((qtype) => qtype.trim()))];
        this.liveSupportedTypeSet = new Set(this.liveSupportedTypes);
        if (typeof data.mediaPickerUrl === "string" && data.mediaPickerUrl !== "") {
          this.config.mediaUrl = data.mediaPickerUrl;
        }
        this.aiConfig = this.normalizeAiBootstrap(data.ai);
        if (data.tts) {
          const voices2 = Array.isArray(data.tts.voices) ? data.tts.voices : [];
          const speakUrl = data.tts.speakUrl || "";
          this.config.tts = {
            available: Boolean(data.tts.available && speakUrl && voices2.length > 0),
            defaultVoiceId: Number(data.tts.defaultVoiceId || ((_b = voices2[0]) == null ? void 0 : _b.id) || 0),
            speakUrl,
            voices: voices2
          };
        }
        this.activity = this.normalizeActivity(data.activity);
        const knownThemes = [
          "hell",
          "dunkel",
          "weltraum",
          "ozean",
          "retro-arcade",
          "jahreszeiten"
        ];
        this.availableThemes = Array.isArray(data.themes) ? data.themes.filter((theme) => knownThemes.includes(theme)) : [...knownThemes.slice(0, 5)];
        if (!this.availableThemes.includes(this.activity.theme) && knownThemes.includes(this.activity.theme)) {
          this.availableThemes.push(this.activity.theme);
        }
        const knownSeasons = ["herbst", "winter", "fruehling", "sommer"];
        this.availableSeasons = Array.isArray(data.seasons) ? data.seasons.filter((season) => knownSeasons.includes(season)) : [];
        if (this.activity.theme === "jahreszeiten" && !this.availableSeasons.includes(this.activity.season) && knownSeasons.includes(this.activity.season)) {
          this.availableSeasons.push(this.activity.season);
        }
        this.questions = Array.isArray(data.questions) ? data.questions.map((question2) => normalizeQuestion(question2)).filter((question2) => question2.id > 0).sort((left, right) => left.sortorder - right.sortorder) : [];
        this.questions.forEach((question2, index) => {
          question2.sortorder = index;
        });
        this.selectedQuestionId = previousSelection && this.questions.some((question2) => question2.id === previousSelection) ? previousSelection : ((_c = this.questions[0]) == null ? void 0 : _c.id) || null;
        this.activityDirtyVersion = 0;
        this.activitySavedVersion = 0;
        this.questionOrderDirtyVersion = 0;
        this.questionOrderSavedVersion = 0;
        this.questionDirtyVersions.clear();
        this.questionSavedVersions.clear();
        this.questionIdAliases.clear();
        this.setSaveState("saved");
        this.applyAppearance();
        this.render();
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") {
          return;
        }
        this.renderLoadError(error);
      }
    }
    normalizeActivity(raw) {
      const source = this.record(raw);
      return {
        allowbacktrack: source.allowbacktrack === true,
        background: this.normalizeFiles(source.background),
        id: Number(source.id || 0),
        logo: this.normalizeFiles(source.logo),
        name: typeof source.name === "string" ? source.name : "",
        season: typeof source.season === "string" && source.season !== "" ? source.season : "herbst",
        theme: typeof source.theme === "string" && source.theme !== "" ? source.theme : "hell",
        timemodified: Number(source.timemodified || 0)
      };
    }
    normalizeFiles(raw) {
      const entries = Array.isArray(raw) ? raw : [];
      if (entries.length === 0) {
        return [];
      }
      return entries.map((entry) => {
        const file = this.record(entry);
        return {
          filename: String(file.filename || ""),
          filepath: String(file.filepath || "/"),
          mimetype: String(file.mimetype || ""),
          path: String(file.path || ""),
          url: String(file.url || "")
        };
      }).filter((file) => file.filename !== "" && file.url !== "");
    }
    renderLoadError(error) {
      this.root.replaceChildren();
      const box = element("section", "quizgeist-editor-error", { role: "alert" });
      const heading = element("h2", "", { text: this.s("editor:error:load:title") });
      const message = element("p", "", {
        text: error instanceof Error ? error.message : this.s("editor:error:request")
      });
      const retry = button(
        this.s("editor:action:retry"),
        "quizgeist-button quizgeist-button--primary",
        () => {
          this.renderLoading();
          void this.loadBootstrap();
        }
      );
      box.append(heading, message, retry);
      this.root.append(box);
    }
    render() {
      this.root.replaceChildren();
      this.saveStatusElement = null;
      this.statusLiveElement = element("div", "quizgeist-visually-hidden", {
        role: "status",
        "aria-live": "polite",
        "aria-atomic": "true"
      });
      this.toastRegion = element("div", "quizgeist-toast-region", {
        "aria-live": "polite",
        "aria-atomic": "true"
      });
      this.root.append(this.statusLiveElement, this.toastRegion);
      if (this.view === "templates") {
        this.renderTemplateLibrary();
      } else {
        this.renderEditor();
      }
    }
    renderEditor() {
      const frame = element("section", "quizgeist-editor");
      frame.dataset.view = "editor";
      const customBackground = this.activity.background[0];
      if (customBackground) {
        frame.append(element("img", "quizgeist-editor__custom-background", {
          src: customBackground.url,
          alt: "",
          "aria-hidden": "true"
        }));
        frame.dataset.hasCustomBackground = "true";
      }
      frame.append(this.renderToolbar(), this.renderBacktrackBar());
      const body = element("div", "quizgeist-editor__body");
      body.append(this.renderQuestionSidebar(), this.renderDetail());
      frame.append(body);
      if (this.workshop !== null && this.workshop.canCurate) {
        frame.append(renderCuratorList(
          this.workshop,
          (key, fallback, values) => this.workshopText(key, fallback, values),
          (submissionId, state, note) => void this.decideSubmission(
            submissionId,
            state,
            note
          ),
          null
        ));
      }
      const nextStep = this.renderNextStep();
      if (nextStep) {
        frame.append(nextStep);
      }
      this.root.append(frame);
      this.updateSaveStatusElement();
    }
    renderNextStep() {
      var _a, _b, _c, _d;
      const total = this.questions.length;
      if (total === 0) {
        return null;
      }
      const heading = element("h2", "quizgeist-editor-nextstep__title", {
        text: this.s("editor:nextstep:title", "Fertig? Dann kann es losgehen.")
      });
      heading.id = `quizgeist-editor-nextstep-${crypto.randomUUID()}`;
      const section = element("section", "quizgeist-editor-nextstep", {
        "aria-labelledby": heading.id
      });
      const actions = element("div", "quizgeist-editor-nextstep__actions");
      if (((_a = this.config.capabilities) == null ? void 0 : _a.host) === true) {
        const status = element("p", "quizgeist-editor-nextstep__status");
        const playable = countPlayableQuestions(this.questions, this.liveSupportedTypes);
        const readinessKey = playable === total ? "host:readiness:ready" : playable === 0 ? "host:readiness:none" : "host:readiness:partial";
        status.textContent = this.workshopText(
          readinessKey,
          playable === total ? "{$a} Fragen sind spielbereit." : playable === 0 ? "Es gibt noch keine spielbereite Frage. Eine Live-Runde startet erst, wenn mindestens eine Frage fertig ist." : "{$a->playable} von {$a->total} Fragen sind spielbereit.",
          { a: playable, playable, total }
        );
        actions.append(element("a", "quizgeist-button quizgeist-button--primary", {
          href: this.config.hostUrl,
          text: this.s("editor:nextstep:host", "Live-Session starten")
        }));
        if (playable < total) {
          const firstUnplayable = this.questions.find((question2) => !isPlayableQuestion(question2, this.liveSupportedTypes));
          if (!firstUnplayable) {
            section.append(heading, status, actions);
            return section;
          }
          const jump = element("a", "quizgeist-button quizgeist-button--secondary", {
            href: "#",
            text: this.s("editor:nextstep:jump", "Zur ersten unvollst\xE4ndigen Frage")
          });
          jump.addEventListener("click", (event) => {
            event.preventDefault();
            this.jumpToQuestionValidation(firstUnplayable);
          });
          actions.append(jump);
        }
        section.append(heading, status, actions);
      } else if (((_b = this.config.capabilities) == null ? void 0 : _b.manage) === true && ((_d = (_c = this.config.features) == null ? void 0 : _c.selfstudy) == null ? void 0 : _d.installed) === true) {
        const assignmentUrl = new URL(window.location.href);
        assignmentUrl.searchParams.set("view", "assignments");
        actions.append(element("a", "quizgeist-button quizgeist-button--primary", {
          href: assignmentUrl.toString(),
          text: this.s("editor:nextstep:assignment", "Zuweisung anlegen")
        }));
        section.append(heading, actions);
      } else {
        return null;
      }
      return section;
    }
    jumpToQuestionValidation(question2) {
      this.selectedQuestionId = question2.id;
      this.render();
      window.requestAnimationFrame(() => {
        const target = this.root.querySelector(
          `.quizgeist-question-form[data-question-id="${question2.id}"] .quizgeist-validation-summary`
        ) || this.root.querySelector(
          `.quizgeist-question-item[data-question-id="${question2.id}"] .quizgeist-question-item__readiness`
        );
        target == null ? void 0 : target.focus();
      });
    }
    /**
     * Load the curation queue of the question workshop.
     *
     * [P11-E2]: `workshop_list` is an action of the self-study addon. Without
     * that addon the dispatcher does not know the action, so the request can
     * only ever end as a 404 — a console error and a red line in the network
     * trace of a base installation that has done nothing wrong. The fetch
     * therefore does not START; it is not started and then forgiven. A caught
     * exception is still a failed request, and hiding it in a `catch` would
     * merely make the noise harder to find.
     *
     * The remaining `catch` covers the honest failures of an INSTALLED addon
     * (offline, missing permission): those are situations in which asking was
     * the right thing to do.
     */
    async loadWorkshop(signal) {
      var _a, _b;
      this.workshop = null;
      if (((_b = (_a = this.config.features) == null ? void 0 : _a.selfstudy) == null ? void 0 : _b.installed) !== true) {
        return;
      }
      try {
        this.workshop = normaliseWorkshopView(
          await this.api.post("workshop_list", {}, signal)
        );
      } catch (_error) {
        this.workshop = null;
      }
    }
    /**
     * Decide one submission and reload the editor.
     *
     * The refusal of the server is shown verbatim: releasing additionally
     * requires mod/quizgeist:manage, and a broken question is refused even
     * then. The client never pre-empts that judgement.
     */
    async decideSubmission(submissionId, state, note) {
      try {
        const result = await this.api.post("workshop_curate", {
          note,
          state,
          workshopId: submissionId
        });
        const errors = readFieldErrors(result);
        if (errors.length > 0) {
          this.announce(workshopErrorMessage(
            errors[0],
            (key, fallback, values) => this.workshopText(key, fallback, values)
          ));
          return;
        }
        await this.loadWorkshop();
        await this.loadBootstrap(true);
      } catch (error) {
        this.announce(error instanceof Error ? error.message : this.s("editor:error:request"));
      }
    }
    renderToolbar() {
      const toolbar = element("header", "quizgeist-editor-toolbar");
      const brand = element("div", "quizgeist-editor-brand");
      const logo = this.activity.logo[0];
      const brandImage = element("img", "quizgeist-editor-brand__icon", {
        src: (logo == null ? void 0 : logo.url) || this.config.brandIconUrl,
        alt: this.s(logo ? "editor:logo:schoolalt" : "editor:logo:alt")
      });
      brand.append(brandImage);
      const titleInput = element("input", "quizgeist-editor-title", {
        type: "text",
        value: this.activity.name,
        maxlength: 255,
        "aria-label": this.s("editor:field:quiztitle")
      });
      titleInput.addEventListener("input", () => {
        this.activity.name = titleInput.value;
        this.markActivityDirty();
      });
      brand.append(titleInput);
      toolbar.append(brand);
      const controls = element("div", "quizgeist-editor-toolbar__controls");
      const theme = this.themeSelect();
      theme.setAttribute("aria-label", this.s("editor:field:theme"));
      controls.append(theme);
      if (this.activity.theme === "jahreszeiten") {
        const season = this.seasonSelect();
        season.setAttribute("aria-label", this.s("editor:field:season"));
        controls.append(season);
      }
      this.saveStatusElement = element("div", "quizgeist-save-status", {
        role: "status",
        "aria-live": "polite"
      });
      controls.append(this.saveStatusElement);
      const appearance = button(
        this.s("editor:action:appearance"),
        "quizgeist-button quizgeist-button--secondary",
        () => this.openAppearanceDialog()
      );
      appearance.dataset.action = "appearance";
      const preview = button(
        this.s("editor:action:preview"),
        "quizgeist-button quizgeist-button--secondary",
        () => this.openPreview()
      );
      preview.dataset.action = "preview";
      if (this.aiConfig.installed) {
        const aiWorkshop = button(
          this.s("editor:action:aiworkshop"),
          "quizgeist-button quizgeist-button--secondary",
          () => void this.openAiWorkshop()
        );
        aiWorkshop.dataset.action = "ai-workshop";
        aiWorkshop.disabled = !this.aiConfig.available;
        if (!this.aiConfig.available) {
          aiWorkshop.title = this.aiLabel(
            "editor:ai:unavailable",
            "Die KI-Werkstatt ist derzeit nicht verf\xFCgbar."
          );
        }
        controls.append(aiWorkshop);
      }
      const templates = button(
        this.s("editor:action:templates"),
        "quizgeist-button quizgeist-button--secondary",
        () => {
          this.view = "templates";
          this.render();
        }
      );
      templates.dataset.action = "templates";
      const kahootImport = button(
        this.s("editor:action:kahootimport"),
        "quizgeist-button quizgeist-button--secondary",
        () => this.openKahootImport()
      );
      kahootImport.dataset.action = "kahoot-import";
      kahootImport.disabled = this.config.kahootImportUrl === "";
      const publish = button(
        this.s("editor:action:publish"),
        "quizgeist-button quizgeist-button--secondary",
        () => this.openPublishDialog()
      );
      publish.dataset.action = "publish-template";
      controls.append(appearance, preview, templates, kahootImport, publish);
      toolbar.append(controls);
      return toolbar;
    }
    renderBacktrackBar() {
      const bar = element("section", "quizgeist-backtrack-bar");
      const content = element("div");
      const title = element("h2", "quizgeist-backtrack-bar__title", {
        text: this.s("editor:backtrack:title")
      });
      const description = element("p", "quizgeist-backtrack-bar__description", {
        text: this.s("editor:backtrack:description")
      });
      content.append(title, description);
      const label = element("label", "quizgeist-switch quizgeist-switch--compact");
      const input = element("input", "quizgeist-switch__input", {
        type: "checkbox",
        checked: this.activity.allowbacktrack
      });
      input.addEventListener("change", () => {
        this.activity.allowbacktrack = input.checked;
        this.markActivityDirty();
      });
      appendChildren(
        label,
        input,
        element("span", "quizgeist-switch__track", { "aria-hidden": "true" }),
        element("span", "quizgeist-switch__label", {
          text: this.s("editor:backtrack:toggle")
        })
      );
      bar.append(content, label);
      return bar;
    }
    renderQuestionSidebar() {
      const sidebar = element("aside", "quizgeist-question-sidebar", {
        "aria-label": this.s("editor:questions:label")
      });
      const header = element("div", "quizgeist-question-sidebar__header");
      const heading = element("h2", "quizgeist-question-sidebar__title", {
        text: this.s("editor:questions:count").replace("{$a}", String(this.questions.length))
      });
      const add = button(
        this.s("editor:action:addquestion"),
        "quizgeist-button quizgeist-button--primary quizgeist-button--square",
        () => this.openQuestionPalette()
      );
      add.setAttribute("aria-label", this.s("editor:action:addquestion"));
      header.append(heading, add);
      sidebar.append(header);
      if (this.questions.length === 0) {
        sidebar.append(this.renderEmptyState());
        return sidebar;
      }
      sidebar.append(this.renderReleaseControls());
      const list = element("ol", "quizgeist-question-list", {
        role: "listbox",
        "aria-label": this.s("editor:questions:label")
      });
      this.questions.forEach((question2, index) => {
        list.append(this.renderQuestionListItem(question2, index));
      });
      sidebar.append(list);
      const addWide = button(
        this.s("editor:action:addquestion"),
        "quizgeist-add-question",
        () => this.openQuestionPalette()
      );
      sidebar.append(addWide);
      return sidebar;
    }
    renderQuestionListItem(question2, index) {
      const selected = question2.id === this.selectedQuestionId;
      const item = element("li", "quizgeist-question-item", {
        role: "option",
        "aria-selected": selected ? "true" : "false",
        "aria-posinset": index + 1,
        "aria-setsize": this.questions.length,
        tabindex: selected ? 0 : -1
      });
      item.dataset.questionId = String(question2.id);
      item.draggable = true;
      if (selected) {
        item.classList.add("is-active");
        item.setAttribute("aria-current", "true");
      }
      if (this.questionHasErrors(question2)) {
        item.classList.add("has-errors");
      }
      const select = button("", "quizgeist-question-item__select", () => {
        this.selectedQuestionId = question2.id;
        this.render();
      });
      const indexNode = element("span", "quizgeist-question-item__index", {
        text: String(index + 1)
      });
      const icon2 = this.questionTypeIcon(question2.qtype);
      const text4 = element("span", "quizgeist-question-item__text");
      const title = element("span", "quizgeist-question-item__title", {
        text: this.questionTitle(question2)
      });
      const metadata = this.renderQuestionMetadata(question2);
      text4.append(title, metadata, this.renderQuestionStatusBadge(question2));
      const readiness = this.renderQuestionReadiness(question2);
      if (readiness) {
        text4.append(readiness);
      }
      select.append(indexNode, icon2, text4);
      if (this.questionHasErrors(question2)) {
        select.append(element("span", "quizgeist-question-item__error", {
          title: this.s("editor:validation:item"),
          "aria-label": this.s("editor:validation:item")
        }));
      }
      const dragHandle = element("span", "quizgeist-question-item__drag-handle", {
        title: this.s("editor:action:drag"),
        "aria-hidden": "true"
      });
      item.append(dragHandle, select);
      const release = this.renderQuestionReleaseButton(question2);
      if (release) {
        item.append(release);
      }
      const actions = element("details", "quizgeist-question-actions");
      const summary = element("summary", "quizgeist-question-actions__summary", {
        text: this.s("editor:action:questionmenu"),
        "aria-label": this.s("editor:action:questionmenu")
      });
      const menu = element("div", "quizgeist-question-actions__menu");
      const up = button(
        this.s("editor:action:up"),
        "quizgeist-question-actions__button",
        () => this.moveQuestion(question2.id, -1)
      );
      up.disabled = index === 0;
      const down = button(
        this.s("editor:action:down"),
        "quizgeist-question-actions__button",
        () => this.moveQuestion(question2.id, 1)
      );
      down.disabled = index === this.questions.length - 1;
      menu.append(
        up,
        down,
        button(
          this.s("editor:action:duplicate"),
          "quizgeist-question-actions__button",
          () => void this.duplicateQuestion(question2.id)
        ),
        button(
          this.s("editor:action:delete"),
          "quizgeist-question-actions__button quizgeist-question-actions__button--danger",
          () => void this.deleteQuestion(question2.id)
        )
      );
      actions.append(summary, menu);
      item.append(actions);
      item.addEventListener("dragstart", (event) => {
        var _a;
        this.draggedQuestionId = question2.id;
        item.classList.add("is-dragging");
        (_a = event.dataTransfer) == null ? void 0 : _a.setData("text/plain", String(question2.id));
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = "move";
        }
      });
      item.addEventListener("dragend", () => {
        this.draggedQuestionId = null;
        item.classList.remove("is-dragging");
        this.root.querySelectorAll(".is-drag-target").forEach((node) => node.classList.remove("is-drag-target"));
      });
      item.addEventListener("dragover", (event) => {
        if (this.draggedQuestionId === null || this.draggedQuestionId === question2.id) {
          return;
        }
        event.preventDefault();
        item.classList.add("is-drag-target");
      });
      item.addEventListener("dragleave", () => item.classList.remove("is-drag-target"));
      item.addEventListener("drop", (event) => {
        event.preventDefault();
        item.classList.remove("is-drag-target");
        if (this.draggedQuestionId !== null) {
          this.reorderQuestion(this.draggedQuestionId, question2.id);
        }
      });
      item.addEventListener("keydown", (event) => {
        if (!event.altKey || event.key !== "ArrowUp" && event.key !== "ArrowDown") {
          return;
        }
        if (event.target !== item && event.target !== select) {
          return;
        }
        event.preventDefault();
        this.moveQuestion(question2.id, event.key === "ArrowUp" ? -1 : 1);
        window.requestAnimationFrame(() => {
          var _a;
          (_a = this.root.querySelector(
            `.quizgeist-question-item[data-question-id="${question2.id}"]`
          )) == null ? void 0 : _a.focus();
        });
      });
      return item;
    }
    renderQuestionStatusBadge(question2) {
      const ready = question2.status === "ready";
      const status = this.s(
        ready ? "editor:status:ready" : "editor:status:draft",
        ready ? "Fertig" : "Entwurf"
      );
      const label = this.s("editor:status:label", "Status: {$a}").replace("{$a}", status);
      return element(
        "span",
        `quizgeist-question-item__status quizgeist-question-item__status--${ready ? "ready" : "draft"}`,
        {
          "aria-label": label,
          text: status
        }
      );
    }
    questionValidationMessages(question2) {
      return validationMessages(this.config.strings, question2.validationErrors);
    }
    questionReadinessReason(question2) {
      const validation = this.questionValidationMessages(question2)[0];
      if (validation) {
        return validation;
      }
      if (question2.status === "media_pending") {
        return this.s(
          "editor:readiness:media",
          "Medien werden noch verarbeitet."
        );
      }
      if (!this.liveSupportedTypeSet.has(question2.qtype)) {
        return this.s(
          "editor:readiness:unsupported",
          "Dieser Fragetyp l\xE4uft nicht in der Live-Runde."
        );
      }
      if (question2.status !== "ready") {
        return this.s("editor:readiness:notreleased", "Noch nicht freigegeben.");
      }
      return null;
    }
    isReleaseCandidate(question2) {
      return question2.status !== "ready" && question2.status !== "media_pending" && this.questionValidationMessages(question2).length === 0;
    }
    renderQuestionReadiness(question2) {
      const reason = this.questionReadinessReason(question2);
      if (reason === null) {
        return null;
      }
      return element("span", "quizgeist-question-item__readiness", {
        tabindex: -1,
        text: reason
      });
    }
    renderQuestionReleaseButton(question2) {
      if (!this.isReleaseCandidate(question2)) {
        return null;
      }
      const release = button(
        this.s("editor:action:release", "Freigeben"),
        "quizgeist-button quizgeist-button--secondary quizgeist-question-item__release",
        (event) => {
          const target = event.currentTarget;
          if (target instanceof HTMLButtonElement) {
            void this.releaseQuestion(question2.id, target);
          }
        }
      );
      release.setAttribute("aria-label", this.s("editor:action:release", "Freigeben"));
      release.disabled = this.releaseAllRunning || this.releasingQuestionIds.has(question2.id);
      return release;
    }
    renderReleaseAllButton() {
      const releaseAll = button(
        this.s("editor:action:releaseall", "Alle vollst\xE4ndigen Fragen freigeben"),
        "quizgeist-button quizgeist-button--secondary quizgeist-question-release-all",
        (event) => {
          const target = event.currentTarget;
          if (target instanceof HTMLButtonElement) {
            void this.releaseAllQuestions(target);
          }
        }
      );
      setButtonBusy(
        releaseAll,
        this.releaseAllRunning,
        this.releaseAllRunning ? this.s("editor:release:working", "Wird freigegeben \u2026") : this.s("editor:action:releaseall", "Alle vollst\xE4ndigen Fragen freigeben")
      );
      return releaseAll;
    }
    renderReleaseControls() {
      const controls = element("section", "quizgeist-question-sidebar__release-controls");
      if (this.releaseAllRunning || this.questions.some((question2) => this.isReleaseCandidate(question2))) {
        controls.append(this.renderReleaseAllButton());
      }
      controls.append(element("div", "quizgeist-question-release-result", {
        role: "status",
        "aria-live": "polite",
        "aria-atomic": "true",
        text: this.releaseResult
      }));
      return controls;
    }
    refreshReleaseControls() {
      const controls = this.root.querySelector(
        ".quizgeist-question-sidebar__release-controls"
      );
      if (!controls) {
        return;
      }
      const shouldShowReleaseAll = this.releaseAllRunning || this.questions.some((question2) => this.isReleaseCandidate(question2));
      const existing = controls.querySelector(
        ".quizgeist-question-release-all"
      );
      const result = controls.querySelector(".quizgeist-question-release-result");
      if (shouldShowReleaseAll && !existing) {
        controls.insertBefore(this.renderReleaseAllButton(), result);
      } else if (!shouldShowReleaseAll) {
        existing == null ? void 0 : existing.remove();
      } else if (existing) {
        setButtonBusy(
          existing,
          this.releaseAllRunning,
          this.releaseAllRunning ? this.s("editor:release:working", "Wird freigegeben \u2026") : this.s("editor:action:releaseall", "Alle vollst\xE4ndigen Fragen freigeben")
        );
      }
    }
    setReleaseResult(message) {
      var _a;
      this.releaseResult = message;
      (_a = this.root.querySelector(".quizgeist-question-release-result")) == null ? void 0 : _a.replaceChildren(document.createTextNode(message));
    }
    renderEmptyState() {
      const empty = element("div", "quizgeist-editor-empty");
      const brand = element("img", "quizgeist-editor-empty__mark", {
        src: this.config.brandIconUrl,
        alt: "",
        "aria-hidden": "true"
      });
      const heading = element("h3", "", { text: this.s("editor:empty:title") });
      const text4 = element("p", "", { text: this.s("editor:empty:description") });
      const actions = element("div", "quizgeist-editor-empty__actions");
      const manual = button(
        this.s("editor:empty:manual"),
        "quizgeist-empty-action",
        () => this.openQuestionPalette()
      );
      if (this.aiConfig.installed) {
        const ai = button(
          this.s("editor:empty:ai"),
          "quizgeist-empty-action",
          () => void this.openAiWorkshop()
        );
        ai.disabled = !this.aiConfig.available;
        if (!this.aiConfig.available) {
          ai.title = this.aiLabel(
            "editor:ai:unavailable",
            "Die KI-Werkstatt ist derzeit nicht verf\xFCgbar."
          );
        }
        actions.append(ai);
      }
      const templates = button(
        this.s("editor:empty:templates"),
        "quizgeist-empty-action",
        () => {
          this.view = "templates";
          this.render();
        }
      );
      const kahoot = button(
        this.s("editor:empty:kahoot"),
        "quizgeist-empty-action",
        () => this.openKahootImport()
      );
      kahoot.disabled = this.config.kahootImportUrl === "";
      actions.prepend(manual);
      actions.append(templates, kahoot);
      empty.append(brand, heading, text4, actions);
      return empty;
    }
    openKahootImport() {
      if (this.config.kahootImportUrl === "") {
        return;
      }
      try {
        const url = new URL(this.config.kahootImportUrl, window.location.href);
        if (url.origin === window.location.origin) {
          window.location.assign(url.toString());
        }
      } catch (_error) {
        this.showToast(this.s("editor:error:config"), true);
      }
    }
    renderDetail() {
      const detail = element("section", "quizgeist-question-detail");
      const validationOverview = this.renderValidationOverview();
      if (validationOverview) {
        detail.append(validationOverview);
      }
      const question2 = this.selectedQuestion();
      if (!question2) {
        detail.append(this.renderEmptyState());
        return detail;
      }
      detail.append(
        createTtsControl(this.tts, this.ttsText(question2), this.config),
        this.formRenderer.render(question2)
      );
      if (this.tagStore.isLoaded()) {
        detail.append(renderTagPicker(
          this.tagStore,
          this.config.strings,
          question2.id,
          question2.rootid || question2.id,
          (message) => this.announce(message)
        ));
      }
      return detail;
    }
    /**
     * Build the dictation control, or nothing at all (F9).
     *
     * Null whenever this browser cannot record or the clip channel is closed.
     * The recording is transcribed and DELETED in the same server request, so
     * this control never offers playback: by the time it has the text, there is
     * nothing left to play.
     */
    dictationControl(onText) {
      const clips = this.config.clips;
      if (clips === void 0 || !clips.canRecord || !clips.canTranscribe || !DictationControl.available()) {
        return null;
      }
      const control = new DictationControl(
        this.config.strings,
        {
          uploadUrl: clips.uploadUrl,
          sesskey: this.config.sesskey,
          cmid: this.config.cmid,
          language: clips.language,
          maxBytes: clips.maxBytes,
          maxSeconds: clips.maxSeconds
        },
        {
          transcribe: (clipId) => this.api.post("dictation", { clipId }),
          onText
        }
      );
      return control.root;
    }
    aiLabel(key, fallback) {
      return editorString(this.config.strings, key, fallback);
    }
    /**
     * Read the AI report of the editor bootstrap.
     *
     * [P11-E2]: the addon is reported to the editor twice and independently —
     * once by view.php in `features.ai`, once by `editor_bootstrap` in its own
     * block. Both have to say yes. Every AI action of this file (`ai_generate`,
     * `ai_apply`, `ai_discard`, `ai_explanation_*`, `dictation`) hangs on
     * `installed`/`available`, and one report alone must not be able to open a
     * path to an action the dispatcher does not know.
     */
    normalizeAiBootstrap(raw) {
      var _a, _b;
      const source = this.record(raw);
      const addonReported = ((_b = (_a = this.config.features) == null ? void 0 : _a.ai) == null ? void 0 : _b.installed) === true;
      const booleanValue = (value) => value === true || value === 1 || value === "1" || value === "true";
      const stringArray = (value) => Array.isArray(value) ? value.filter((entry) => typeof entry === "string" && entry.trim() !== "").map((entry) => entry.trim()) : [];
      const configuredFormats = stringArray(source.formats).filter((format) => AI_FORMATS.includes(format));
      const maximumQuestions = Number(source.maxQuestions || 50);
      const maximumPdfPages = Number(source.maxPdfPages || 150);
      const gatewayAvailable = booleanValue(source.gatewayAvailable);
      const fallbackAvailable = booleanValue(source.fallbackAvailable);
      const sourcePickerUrl = typeof source.aiSourcePickerUrl === "string" ? source.aiSourcePickerUrl : typeof source.sourcePickerUrl === "string" ? source.sourcePickerUrl : "";
      return {
        acceptedDocuments: stringArray(source.acceptedDocuments),
        acceptedVision: stringArray(source.acceptedVision),
        aiSourcePickerUrl: sourcePickerUrl,
        available: addonReported && booleanValue(source.available),
        entitlementStatus: typeof source.entitlementStatus === "string" ? source.entitlementStatus : "read_only",
        fallbackAvailable,
        formats: configuredFormats.length > 0 ? configuredFormats : [...AI_FORMATS],
        gatewayAvailable,
        installed: addonReported && booleanValue(source.installed),
        managedServerInstalled: booleanValue(source.managedServerInstalled),
        maxPdfPages: Number.isInteger(maximumPdfPages) && maximumPdfPages > 0 ? Math.min(maximumPdfPages, 150) : 150,
        maxQuestions: Number.isInteger(maximumQuestions) && maximumQuestions > 0 ? Math.min(maximumQuestions, 100) : 50,
        notice: typeof source.notice === "string" ? source.notice : "",
        outboundFetchAllowed: booleanValue(source.outboundFetchAllowed),
        visionAvailable: booleanValue(source.visionAvailable)
      };
    }
    async openAiWorkshop() {
      var _a;
      if (!this.aiConfig.available) {
        this.showToast(this.aiLabel(
          "editor:ai:unavailable",
          "Die KI-Werkstatt ist derzeit nicht verf\xFCgbar."
        ), true);
        return;
      }
      if ((_a = this.aiDialog) == null ? void 0 : _a.open) {
        this.aiDialog.focus();
        return;
      }
      this.aiDraft = null;
      this.aiSourceSelection = null;
      const parts = openDialog(
        this.s("editor:action:aiworkshop"),
        this.s("editor:action:close"),
        "quizgeist-ai-dialog"
      );
      this.aiDialog = parts.dialog;
      let applied = false;
      let applying = false;
      let generating = false;
      let submittedSourceDraftId = "";
      const shell = element("div", "quizgeist-ai-workshop");
      const intro = element("p", "quizgeist-dialog__intro", {
        text: this.aiLabel(
          "editor:ai:intro",
          "Erzeugen Sie einen pr\xFCfbaren Entwurf. Erst Ihre Best\xE4tigung \xFCbernimmt ausgew\xE4hlte Inhalte in den Editor."
        )
      });
      const availability = element("div", "quizgeist-ai-availability", {
        role: "status"
      });
      availability.append(
        element("span", "quizgeist-ai-chip quizgeist-ai-chip--draft", {
          text: this.aiLabel("editor:ai:draft", "ENTWURF")
        }),
        element("span", `quizgeist-ai-chip ${this.aiConfig.gatewayAvailable ? "quizgeist-ai-chip--gateway" : "quizgeist-ai-chip--fallback"}`, {
          text: this.aiConfig.gatewayAvailable ? this.aiLabel("editor:ai:gatewayavailable", "Lokales KI-Gateway konfiguriert") : this.aiLabel(
            "editor:ai:fallbackavailable",
            "Regelbasierter Fallback aktiv"
          )
        })
      );
      shell.append(intro, availability);
      if (this.aiConfig.notice !== "") {
        shell.append(element("p", "quizgeist-ai-warning", {
          role: "note",
          text: this.aiConfig.notice
        }));
      }
      const form = element("form", "quizgeist-ai-form");
      form.addEventListener("submit", (event) => event.preventDefault());
      const sourceFieldset = element("fieldset", "quizgeist-ai-source-fieldset");
      sourceFieldset.append(element("legend", "quizgeist-ai-form__legend", {
        text: this.aiLabel("editor:ai:source", "Quelle")
      }));
      const sourceGrid = element("div", "quizgeist-ai-source-grid");
      const sources = AI_SOURCES.filter((source) => (source !== "handwriting" || this.aiConfig.visionAvailable) && (this.aiConfig.outboundFetchAllowed || source !== "url" && source !== "wikipedia"));
      sources.forEach((source, index) => {
        const input = element("input", "quizgeist-ai-source-card__input", {
          type: "radio",
          name: "quizgeist-ai-source",
          value: source,
          checked: index === 0
        });
        const card = element("label", "quizgeist-ai-source-card");
        card.append(
          input,
          element("span", "quizgeist-ai-source-card__title", {
            text: this.aiSourceLabel(source)
          }),
          element("span", "quizgeist-ai-source-card__description", {
            text: this.aiSourceDescription(source)
          })
        );
        sourceGrid.append(card);
      });
      sourceFieldset.append(sourceGrid);
      if (!this.aiConfig.outboundFetchAllowed) {
        sourceFieldset.append(element("p", "quizgeist-ai-warning", {
          role: "note",
          text: this.aiLabel(
            "editor:ai:outbounddisabled",
            "Der Import einer frei eingegebenen URL und von de.wikipedia.org ist durch die Website-Administration deaktiviert."
          )
        }));
      }
      form.append(sourceFieldset);
      const fields = element("div", "quizgeist-ai-form__fields");
      const topicInput = element("input", "quizgeist-input", {
        type: "text",
        maxlength: 2e3,
        required: true
      });
      const topicField = labelledField(
        this.aiLabel("editor:ai:topic", "Thema"),
        topicInput,
        this.aiLabel(
          "editor:ai:topic:hint",
          "Formulieren Sie Thema, Lernziel oder gew\xFCnschten Schwerpunkt."
        )
      );
      const topicLabel = topicField.querySelector("label");
      const dictationHost = this.dictationControl((dictated) => {
        const field = topicInput;
        const current = field.value.trim();
        field.value = current === "" ? dictated : `${current} ${dictated}`;
        field.dispatchEvent(new Event("input", { bubbles: true }));
      });
      if (dictationHost !== null) {
        topicField.append(dictationHost);
      }
      const gradeInput = element("input", "quizgeist-input", {
        type: "text",
        maxlength: 80,
        required: true,
        value: "10"
      });
      const countInput = element("input", "quizgeist-input", {
        type: "number",
        min: 1,
        max: this.aiConfig.maxQuestions,
        step: 1,
        value: Math.min(10, this.aiConfig.maxQuestions),
        required: true
      });
      const formatSelect = element("select", "quizgeist-select");
      this.aiConfig.formats.forEach((format) => {
        formatSelect.append(element("option", "", {
          value: format,
          text: this.aiFormatLabel(format)
        }));
      });
      const gradeField = labelledField(
        this.aiLabel("editor:ai:grade", "Jahrgangsstufe"),
        gradeInput
      );
      const countField = labelledField(
        this.aiLabel("editor:ai:count", "Anzahl"),
        countInput,
        this.aiLabel(
          "editor:ai:count:hint",
          `Maximal ${this.aiConfig.maxQuestions} Inhalte pro Entwurf.`
        )
      );
      const formatField = labelledField(
        this.aiLabel("editor:ai:format", "Format"),
        formatSelect
      );
      fields.append(topicField, gradeField, countField, formatField);
      form.append(fields);
      const filePanel = element("section", "quizgeist-ai-file-panel", { hidden: true });
      const fileCopy = element("div", "quizgeist-ai-file-panel__copy");
      fileCopy.append(
        element("h3", "", {
          text: this.aiLabel("editor:ai:file:title", "Quelldatei")
        }),
        element("p", "", {
          text: this.aiLabel(
            "editor:ai:file:hint",
            `PDFs werden serverseitig bis ${this.aiConfig.maxPdfPages} Seiten verarbeitet.`
          )
        })
      );
      const selectedFile = element("p", "quizgeist-ai-file-panel__selection", {
        role: "status",
        "aria-live": "polite",
        text: this.aiLabel("editor:ai:file:none", "Noch keine Datei gew\xE4hlt.")
      });
      const chooseFile = button(
        this.aiLabel("editor:ai:file:choose", "Datei ausw\xE4hlen"),
        "quizgeist-button quizgeist-button--secondary"
      );
      chooseFile.disabled = this.aiConfig.aiSourcePickerUrl === "";
      if (chooseFile.disabled) {
        chooseFile.title = this.s("editor:media:unavailable");
      }
      filePanel.append(fileCopy, selectedFile, chooseFile);
      form.append(filePanel);
      shell.append(form);
      const resultRegion = element("div", "quizgeist-ai-results", {
        "aria-live": "polite"
      });
      shell.append(resultRegion);
      parts.body.append(shell);
      const cancel = button(
        this.s("editor:action:cancel"),
        "quizgeist-button quizgeist-button--secondary",
        () => closeDialog(parts.dialog)
      );
      const generate = button(
        this.aiLabel("editor:ai:generate", "Entwurf erzeugen"),
        "quizgeist-button quizgeist-button--secondary"
      );
      const apply = button(
        this.aiLabel(
          "editor:ai:apply",
          "Auswahl als Entwurf in den Editor \xFCbernehmen"
        ),
        "quizgeist-button quizgeist-button--primary"
      );
      apply.disabled = true;
      parts.footer.append(cancel, generate, apply);
      const setGenerationControls = (busy) => {
        sourceFieldset.disabled = busy;
        topicInput.disabled = busy;
        gradeInput.disabled = busy;
        countInput.disabled = busy;
        formatSelect.disabled = busy;
        chooseFile.disabled = busy || this.aiConfig.aiSourcePickerUrl === "";
      };
      const preventCloseWhileApplying = (event) => {
        if (!applying) {
          return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
      };
      parts.dialog.addEventListener("cancel", preventCloseWhileApplying, {
        capture: true
      });
      parts.dialog.addEventListener("click", (event) => {
        if (event.target === parts.dialog) {
          preventCloseWhileApplying(event);
        }
      }, { capture: true });
      let selectedItemIds = /* @__PURE__ */ new Set();
      const selectedSource = () => {
        const input = sourceGrid.querySelector(
          'input[name="quizgeist-ai-source"]:checked'
        );
        return AI_SOURCES.includes(input == null ? void 0 : input.value) ? input == null ? void 0 : input.value : "topic";
      };
      const updateSelection = (selection, discardPrevious = true) => {
        const previous = this.aiSourceSelection;
        if (discardPrevious && previous && previous.draftItemId !== (selection == null ? void 0 : selection.draftItemId)) {
          void this.discardAiSource(previous);
        }
        this.aiSourceSelection = selection;
        selectedFile.setAttribute("role", "status");
        selectedFile.textContent = selection ? [
          selection.file.filename,
          selection.file.mimetype || "",
          selection.file.filesize ? this.formatFileSize(selection.file.filesize) : ""
        ].filter(Boolean).join(" \xB7 ") : this.aiLabel("editor:ai:file:none", "Noch keine Datei gew\xE4hlt.");
      };
      const updateSourceUi = () => {
        var _a2;
        const source = selectedSource();
        const needsFile = FILE_AI_SOURCES.includes(source);
        const isSlideImport = source === "slides";
        filePanel.hidden = !needsFile;
        gradeField.hidden = isSlideImport;
        countField.hidden = isSlideImport;
        formatField.hidden = isSlideImport;
        topicInput.required = !needsFile;
        topicInput.type = source === "url" ? "url" : "text";
        topicInput.maxLength = source === "url" ? 2048 : 255;
        if (topicLabel) {
          topicLabel.textContent = source === "url" ? this.aiLabel("editor:ai:url", "Webadresse") : source === "wikipedia" ? this.aiLabel("editor:ai:wikipedia", "Wikipedia-Thema oder Artikeltitel") : needsFile ? this.aiLabel("editor:ai:focus", "Optionaler Schwerpunkt") : this.aiLabel("editor:ai:topic", "Thema");
        }
        topicInput.placeholder = source === "url" ? "https://\u2026" : source === "wikipedia" ? this.aiLabel("editor:ai:wikipedia:placeholder", "z. B. Gewaltenteilung") : "";
        if (((_a2 = this.aiSourceSelection) == null ? void 0 : _a2.purpose) !== source) {
          updateSelection(null);
        }
      };
      sourceGrid.addEventListener("change", updateSourceUi);
      updateSourceUi();
      chooseFile.addEventListener("click", () => {
        this.openAiSourcePicker(selectedSource(), updateSelection);
      });
      generate.addEventListener("click", async () => {
        var _a2, _b, _c, _d, _e;
        const source = selectedSource();
        if (!topicInput.reportValidity() || !gradeInput.reportValidity() || !countInput.reportValidity()) {
          return;
        }
        if (FILE_AI_SOURCES.includes(source) && ((_a2 = this.aiSourceSelection) == null ? void 0 : _a2.purpose) !== source) {
          selectedFile.setAttribute("role", "alert");
          selectedFile.textContent = this.aiLabel(
            "editor:ai:file:required",
            "Bitte w\xE4hlen Sie zuerst eine passende Quelldatei."
          );
          chooseFile.focus();
          return;
        }
        const count2 = Number(countInput.value);
        if (!Number.isInteger(count2) || count2 < 1 || count2 > this.aiConfig.maxQuestions) {
          countInput.setCustomValidity(this.aiLabel(
            "editor:ai:count:invalid",
            `W\xE4hlen Sie eine ganze Zahl zwischen 1 und ${this.aiConfig.maxQuestions}.`
          ));
          countInput.reportValidity();
          countInput.setCustomValidity("");
          return;
        }
        const sourceSelection = ((_b = this.aiSourceSelection) == null ? void 0 : _b.purpose) === source ? this.aiSourceSelection : null;
        (_c = this.aiGenerateController) == null ? void 0 : _c.abort();
        const controller = new AbortController();
        this.aiGenerateController = controller;
        generating = true;
        setGenerationControls(true);
        setButtonBusy(
          generate,
          true,
          this.aiLabel("editor:ai:generating", "Entwurf wird erzeugt \u2026")
        );
        apply.disabled = true;
        resultRegion.replaceChildren(element("div", "quizgeist-ai-loading", {
          role: "status",
          text: this.aiLabel(
            "editor:ai:generating:detail",
            "Quelle wird verarbeitet und anschlie\xDFend sicher validiert."
          )
        }));
        try {
          await this.flushPendingSaves();
          if ((_d = this.aiDraft) == null ? void 0 : _d.token) {
            await this.discardAiDraft(this.aiDraft.token);
            this.aiDraft = null;
          }
          const format = AI_FORMATS.includes(formatSelect.value) ? formatSelect.value : "quiz";
          const topic = topicInput.value.trim();
          const payload = {
            source,
            format,
            topic,
            grade: gradeInput.value.trim(),
            questionCount: count2
          };
          if (source === "url" || source === "wikipedia") {
            payload.url = topic;
          }
          if (sourceSelection) {
            payload.draftItemId = sourceSelection.draftItemId;
            payload.sourceToken = sourceSelection.sourceToken;
          }
          submittedSourceDraftId = (sourceSelection == null ? void 0 : sourceSelection.draftItemId) || "";
          const response = await this.api.post(
            "ai_generate",
            payload,
            controller.signal
          );
          const draft = this.normalizeAiDraft(response);
          this.aiDraft = draft;
          selectedItemIds = new Set(draft.items.map((item) => item.itemId));
          const results = this.renderAiDraft(
            draft,
            selectedItemIds,
            () => {
              apply.disabled = selectedItemIds.size === 0;
            }
          );
          resultRegion.replaceChildren(results);
          apply.disabled = selectedItemIds.size === 0;
          results.focus();
        } catch (error) {
          if (!(error instanceof DOMException && error.name === "AbortError")) {
            resultRegion.replaceChildren(element("div", "quizgeist-ai-error", {
              role: "alert",
              text: error instanceof Error ? error.message : this.s("editor:error:request")
            }));
          }
        } finally {
          generating = false;
          if (sourceSelection && ((_e = this.aiSourceSelection) == null ? void 0 : _e.draftItemId) === sourceSelection.draftItemId) {
            updateSelection(null, false);
          }
          if (sourceSelection) {
            await this.discardAiSource(sourceSelection);
          }
          submittedSourceDraftId = "";
          if (this.aiGenerateController === controller) {
            this.aiGenerateController = null;
          }
          if (parts.dialog.open) {
            setGenerationControls(false);
            setButtonBusy(
              generate,
              false,
              this.aiLabel("editor:ai:generate", "Entwurf erzeugen")
            );
          }
        }
      });
      apply.addEventListener("click", async () => {
        const draft = this.aiDraft;
        if (!draft || selectedItemIds.size === 0) {
          return;
        }
        applying = true;
        parts.closeButton.disabled = true;
        cancel.disabled = true;
        setButtonBusy(
          apply,
          true,
          this.aiLabel("editor:ai:applying", "Entwurf wird \xFCbernommen \u2026")
        );
        generate.disabled = true;
        try {
          await this.flushPendingSaves();
          await this.api.post("ai_apply", {
            token: draft.token,
            itemIds: [...selectedItemIds]
          });
          applied = true;
          this.aiDraft = null;
          applying = false;
          closeDialog(parts.dialog);
          this.renderLoading();
          await this.loadBootstrap(true);
          this.showToast(this.aiLabel(
            "editor:ai:applied",
            "Die Auswahl wurde als Entwurf in den Editor \xFCbernommen."
          ));
        } catch (error) {
          applying = false;
          this.showToast(
            error instanceof Error ? error.message : this.s("editor:error:request"),
            true
          );
          if (parts.dialog.open) {
            parts.closeButton.disabled = false;
            cancel.disabled = false;
            setButtonBusy(
              apply,
              false,
              this.aiLabel(
                "editor:ai:apply",
                "Auswahl als Entwurf in den Editor \xFCbernehmen"
              )
            );
            generate.disabled = false;
          }
        }
      });
      parts.dialog.addEventListener("close", () => {
        var _a2, _b, _c;
        (_a2 = this.aiGenerateController) == null ? void 0 : _a2.abort();
        this.aiGenerateController = null;
        this.tts.stop();
        if ((_b = this.aiSourceDialog) == null ? void 0 : _b.open) {
          closeDialog(this.aiSourceDialog);
        }
        const token = !applied ? (_c = this.aiDraft) == null ? void 0 : _c.token : null;
        const sourceSelection = this.aiSourceSelection;
        this.aiDraft = null;
        this.aiDialog = null;
        this.aiSourceSelection = null;
        this.aiSourceRequest = null;
        if (token) {
          void this.discardAiDraft(token);
        }
        if (sourceSelection && (!generating || sourceSelection.draftItemId !== submittedSourceDraftId)) {
          void this.discardAiSource(sourceSelection);
        }
      }, { once: true });
    }
    aiSourceLabel(source) {
      const labels = {
        topic: "Thema",
        pdf: "PDF zu Quiz",
        pdf_questions: "PDF-Fragen extrahieren",
        url: "URL zu Quiz",
        wikipedia: "Wikipedia",
        slides: "PPTX/PDF zu Inhaltsfolien",
        handwriting: "Handschrift-Scan"
      };
      return this.aiLabel(`editor:ai:source:${source}`, labels[source]);
    }
    aiSourceDescription(source) {
      const descriptions = {
        topic: "Thema, Jahrgang und Anzahl vorgeben.",
        pdf: "Aus einem PDF einen Quiz-Entwurf entwickeln.",
        pdf_questions: "Vorhandene Fragen aus einem PDF erkennen.",
        url: "Eine freigegebene Webseite als Quelle verwenden.",
        wikipedia: "Ein Thema \xFCber Wikipedia erschlie\xDFen.",
        slides: "PDF- oder PPTX-Inhalte als Folien \xFCbernehmen.",
        handwriting: "Handschrift nur mit erreichbarem Vision-Modell auswerten."
      };
      return this.aiLabel(
        `editor:ai:source:${source}:description`,
        descriptions[source]
      );
    }
    aiFormatLabel(format) {
      const labels = {
        quiz: "Quiz",
        truefalse: "Richtig/Falsch",
        micro_lesson: "Mikro-Lektion",
        vocabulary: "Wortschatz\xFCberpr\xFCfung",
        presentation: "Pr\xE4sentation",
        practice_test: "\xDCbungstest",
        step_by_step: "Schritt-f\xFCr-Schritt-L\xF6sungsweg"
      };
      return this.aiLabel(`editor:ai:format:${format}`, labels[format]);
    }
    openAiSourcePicker(purpose, onSelected) {
      var _a;
      if (!FILE_AI_SOURCES.includes(purpose) || purpose === "handwriting" && !this.aiConfig.visionAvailable) {
        return;
      }
      let sourceUrl;
      try {
        sourceUrl = new URL(this.aiConfig.aiSourcePickerUrl, window.location.href);
      } catch (_error) {
        this.showToast(this.s("editor:media:unavailable"), true);
        return;
      }
      if (sourceUrl.origin !== window.location.origin) {
        this.showToast(this.s("editor:media:unavailable"), true);
        return;
      }
      if ((_a = this.aiSourceDialog) == null ? void 0 : _a.open) {
        closeDialog(this.aiSourceDialog);
      }
      sourceUrl.searchParams.set("id", String(this.config.cmid));
      sourceUrl.searchParams.set("purpose", purpose);
      const parts = openDialog(
        this.aiLabel("editor:ai:file:title", "Quelldatei"),
        this.s("editor:action:close"),
        "quizgeist-ai-source-dialog"
      );
      this.aiSourceDialog = parts.dialog;
      const iframe = element("iframe", "quizgeist-ai-source-dialog__frame", {
        src: sourceUrl.toString(),
        title: this.aiLabel(
          "editor:ai:file:iframe",
          "Quelldatei f\xFCr die KI-Werkstatt ausw\xE4hlen"
        ),
        loading: "eager"
      });
      const request = {
        draftItemId: "",
        purpose,
        sourceToken: "",
        onSelected: (selection) => onSelected(selection),
        sourceWindow: iframe.contentWindow
      };
      this.aiSourceRequest = request;
      parts.body.append(iframe);
      request.sourceWindow = iframe.contentWindow;
      parts.dialog.addEventListener("close", () => {
        const draftItemId = request.draftItemId;
        const sourceToken = request.sourceToken;
        request.draftItemId = "";
        request.sourceToken = "";
        if (draftItemId !== "" && sourceToken !== "") {
          void this.discardAiSource({
            purpose,
            sourceToken
          });
        }
        if (this.aiSourceDialog === parts.dialog) {
          this.aiSourceDialog = null;
        }
        if (this.aiSourceRequest === request) {
          this.aiSourceRequest = null;
        }
      }, { once: true });
    }
    handleAiSourceReady(message, messageSource) {
      var _a, _b;
      const pending = this.aiSourceRequest;
      const payload = this.record(message);
      const draftItemId = String((_b = (_a = payload.draftItemId) != null ? _a : payload.draftitemid) != null ? _b : "");
      const sourceToken = typeof payload.sourceToken === "string" ? payload.sourceToken : "";
      if (!pending || messageSource !== pending.sourceWindow || payload.purpose !== pending.purpose || !/^[1-9][0-9]*$/.test(draftItemId) || !/^[a-f0-9]{64}$/.test(sourceToken)) {
        return;
      }
      pending.draftItemId = draftItemId;
      pending.sourceToken = sourceToken;
    }
    handleAiSourceSelected(message, messageSource) {
      var _a, _b, _c;
      const pending = this.aiSourceRequest;
      if (!pending || messageSource !== pending.sourceWindow) {
        return;
      }
      const payload = this.record(message);
      const draftItemId = String((_b = (_a = payload.draftItemId) != null ? _a : payload.draftitemid) != null ? _b : "");
      const sourceToken = typeof payload.sourceToken === "string" ? payload.sourceToken : "";
      const purpose = typeof payload.purpose === "string" && AI_SOURCES.includes(payload.purpose) ? payload.purpose : null;
      const file = this.record(payload.file);
      const filename = typeof file.filename === "string" ? file.filename.trim() : "";
      if (!/^[1-9][0-9]*$/.test(draftItemId) || !/^[a-f0-9]{64}$/.test(sourceToken) || purpose !== pending.purpose || pending.sourceToken !== "" && pending.sourceToken !== sourceToken || filename === "") {
        return;
      }
      const filesize = Number(file.filesize || 0);
      const selection = {
        draftItemId,
        purpose,
        file: {
          filename,
          ...typeof file.mimetype === "string" && file.mimetype !== "" ? { mimetype: file.mimetype } : {},
          ...Number.isFinite(filesize) && filesize > 0 ? { filesize } : {}
        },
        sourceToken
      };
      pending.draftItemId = "";
      pending.sourceToken = "";
      pending.onSelected(selection);
      if ((_c = this.aiSourceDialog) == null ? void 0 : _c.open) {
        closeDialog(this.aiSourceDialog);
      }
    }
    handleAiSourceCancelled(message, messageSource) {
      var _a;
      const pending = this.aiSourceRequest;
      const payload = this.record(message);
      const sourceToken = typeof payload.sourceToken === "string" ? payload.sourceToken : "";
      if (!pending || messageSource !== pending.sourceWindow || payload.purpose !== pending.purpose || !/^[a-f0-9]{64}$/.test(sourceToken) || pending.sourceToken !== "" && pending.sourceToken !== sourceToken) {
        return;
      }
      pending.draftItemId = "";
      pending.sourceToken = "";
      if ((_a = this.aiSourceDialog) == null ? void 0 : _a.open) {
        closeDialog(this.aiSourceDialog);
      }
    }
    normalizeAiDraft(raw) {
      const envelope = this.record(raw);
      const source = this.record(envelope.draft || raw);
      const token = typeof source.token === "string" ? source.token.trim() : "";
      const status = typeof source.status === "string" ? source.status : "";
      const kind = typeof source.kind === "string" && AI_SOURCES.includes(source.kind) ? source.kind : null;
      const format = typeof source.format === "string" && AI_FORMATS.includes(source.format) ? source.format : null;
      if (!/^[a-f0-9]{64}$/.test(token) || status !== "draft" || !kind || !format || !Array.isArray(source.items)) {
        throw new Error(this.s("editor:error:response"));
      }
      const items = source.items.map((entry, index) => {
        var _a, _b, _c;
        const item = this.record(entry);
        const questionSource = this.record(item.question);
        if (!isQuestionType(questionSource.qtype)) {
          throw new Error(this.s("editor:error:response"));
        }
        const files = Array.isArray(item.files) ? item.files.map((file) => this.normalizeAiDraftFile(file)).filter((file) => file !== null) : [];
        const question2 = normalizeQuestion({
          ...questionSource,
          id: 0,
          rootid: 0,
          sortorder: index,
          status: "draft",
          timemodified: 0,
          version: 0,
          validationErrors: (_b = (_a = item.validationErrors) != null ? _a : questionSource.validationErrors) != null ? _b : [],
          files: (_c = questionSource.files) != null ? _c : files
        });
        question2.id = 0;
        question2.rootid = 0;
        question2.status = "draft";
        question2.version = 0;
        question2.timemodified = 0;
        const itemId = typeof item.itemId === "string" ? item.itemId.trim() : typeof item.itemId === "number" ? String(item.itemId) : "";
        if (itemId === "") {
          throw new Error(this.s("editor:error:response"));
        }
        return {
          files,
          itemId,
          question: question2,
          status: "draft",
          validationErrors: question2.validationErrors
        };
      });
      if (items.length === 0 || new Set(items.map((item) => item.itemId)).size !== items.length) {
        throw new Error(this.s("editor:error:response"));
      }
      const warnings = Array.isArray(source.warnings) ? source.warnings.flatMap((warning) => {
        if (typeof warning === "string" && warning.trim() !== "") {
          return [warning.trim()];
        }
        const structured = this.record(warning);
        const message = typeof structured.message === "string" ? structured.message.trim() : typeof structured.code === "string" ? structured.code.trim() : "";
        return message === "" ? [] : [message];
      }) : [];
      const expiresAt = source.expiresAt;
      return {
        expiresAt: typeof expiresAt === "number" || typeof expiresAt === "string" ? expiresAt : null,
        format,
        items,
        kind,
        origin: typeof source.origin === "string" && source.origin.trim() !== "" ? source.origin.trim() : "unknown",
        status: "draft",
        title: typeof source.title === "string" && source.title.trim() !== "" ? source.title.trim() : this.aiSourceLabel(kind),
        token,
        warnings
      };
    }
    normalizeAiDraftFile(raw) {
      const source = this.record(raw);
      const filename = typeof source.filename === "string" ? source.filename.trim() : "";
      if (filename === "") {
        return null;
      }
      const filesize = Number(source.filesize || 0);
      return {
        filename,
        ...Number.isFinite(filesize) && filesize > 0 ? { filesize } : {},
        ...typeof source.mimetype === "string" && source.mimetype !== "" ? { mimetype: source.mimetype } : {},
        ...typeof source.path === "string" && source.path !== "" ? { path: source.path } : {},
        ...typeof source.url === "string" && source.url !== "" ? { url: source.url } : {}
      };
    }
    renderAiDraft(draft, selectedItemIds, onSelectionChange) {
      const section = element("section", "quizgeist-ai-draft", { tabindex: -1 });
      const header = element("header", "quizgeist-ai-draft__header");
      const headingGroup = element("div");
      headingGroup.append(
        element("span", "quizgeist-ai-chip quizgeist-ai-chip--draft", {
          text: this.aiLabel("editor:ai:draft", "ENTWURF")
        }),
        element("h3", "", { text: draft.title }),
        element("p", "", {
          text: [
            this.aiSourceLabel(draft.kind),
            this.aiFormatLabel(draft.format),
            this.formatAiExpiry(draft.expiresAt)
          ].filter(Boolean).join(" \xB7 ")
        })
      );
      const fallback = draft.origin === "fallback";
      const gateway = draft.origin === "gateway";
      header.append(
        headingGroup,
        element("span", `quizgeist-ai-chip ${fallback ? "quizgeist-ai-chip--fallback" : gateway ? "quizgeist-ai-chip--gateway" : ""}`, {
          text: fallback ? this.aiLabel("editor:ai:fallback", "Regelbasierter Fallback") : gateway ? this.aiLabel("editor:ai:gateway", "Lokales KI-Gateway") : draft.origin === "import" ? this.aiLabel("editor:ai:import", "Serverseitiger Folienimport") : this.aiLabel("editor:ai:server", "Serverseitig erzeugt")
        })
      );
      section.append(header);
      const draftWarnings = aiWarningMessages(this.config.strings, draft.warnings);
      if (draftWarnings.length > 0) {
        const warning = element("div", "quizgeist-ai-warning", { role: "status" });
        warning.append(element("strong", "", {
          text: this.aiLabel("editor:ai:warnings", "Hinweise")
        }));
        const list = element("ul");
        draftWarnings.forEach((message) => {
          list.append(element("li", "", { text: message }));
        });
        warning.append(list);
        section.append(warning);
      }
      const selectionBar = element("div", "quizgeist-ai-selection-bar");
      const selectionStatus = element("span", "", {
        role: "status",
        "aria-live": "polite"
      });
      const updateSelectionStatus = () => {
        selectionStatus.textContent = this.aiLabel(
          "editor:ai:selected",
          "{$a} von {$b} ausgew\xE4hlt"
        ).replace("{$a}", String(selectedItemIds.size)).replace("{$b}", String(draft.items.length));
        onSelectionChange();
      };
      const selectAll = button(
        this.aiLabel("editor:ai:selectall", "Alle ausw\xE4hlen"),
        "quizgeist-button quizgeist-button--quiet"
      );
      const selectNone = button(
        this.aiLabel("editor:ai:selectnone", "Auswahl aufheben"),
        "quizgeist-button quizgeist-button--quiet"
      );
      selectionBar.append(selectionStatus, selectAll, selectNone);
      section.append(selectionBar);
      const cards = element("div", "quizgeist-ai-draft__items");
      const checkboxes = /* @__PURE__ */ new Map();
      draft.items.forEach((item, index) => {
        const question2 = item.question;
        const card = element("article", "quizgeist-ai-draft-item");
        const cardHeader = element("header", "quizgeist-ai-draft-item__header");
        const choice = element("label", "quizgeist-ai-draft-item__choice");
        const checkbox = element("input", "", {
          type: "checkbox",
          checked: selectedItemIds.has(item.itemId)
        });
        checkboxes.set(item.itemId, checkbox);
        checkbox.addEventListener("change", () => {
          if (checkbox.checked) {
            selectedItemIds.add(item.itemId);
          } else {
            selectedItemIds.delete(item.itemId);
          }
          updateSelectionStatus();
        });
        choice.append(
          checkbox,
          element("span", "", {
            text: `${index + 1}. ${this.questionTitle(question2)}`
          })
        );
        cardHeader.append(
          choice,
          element("span", "quizgeist-ai-chip", {
            text: this.s(`editor:qtype:${question2.qtype}`)
          })
        );
        card.append(cardHeader);
        const prompt = element("p", "quizgeist-ai-draft-item__prompt", {
          text: question2.questiontext || this.questionTitle(question2)
        });
        card.append(
          prompt,
          createTtsControl(this.tts, this.ttsText(question2), this.config),
          this.renderPreviewResponse(question2)
        );
        if (question2.explanation.trim() !== "") {
          const explanation = element("details", "quizgeist-ai-draft-item__explanation");
          explanation.append(
            element("summary", "", { text: this.s("editor:field:explanation") }),
            element("p", "", { text: question2.explanation })
          );
          card.append(explanation);
        }
        if (item.files.length > 0) {
          const files = element("ul", "quizgeist-ai-draft-item__files");
          item.files.forEach((file) => {
            files.append(element("li", "", {
              text: [
                file.filename,
                file.mimetype || "",
                file.filesize ? this.formatFileSize(file.filesize) : ""
              ].filter(Boolean).join(" \xB7 ")
            }));
          });
          card.append(files);
        }
        const errors = this.aiValidationMessages(item.validationErrors);
        if (errors.length > 0) {
          const validation = element("div", "quizgeist-ai-draft-item__validation", {
            role: "status"
          });
          validation.append(element("strong", "", {
            text: this.aiLabel(
              "editor:ai:validation",
              "Im Editor noch zu vervollst\xE4ndigen"
            )
          }));
          const list = element("ul");
          errors.forEach((error) => list.append(element("li", "", { text: error })));
          validation.append(list);
          card.append(validation);
        }
        cards.append(card);
      });
      section.append(cards);
      selectAll.addEventListener("click", () => {
        draft.items.forEach((item) => {
          selectedItemIds.add(item.itemId);
          const checkbox = checkboxes.get(item.itemId);
          if (checkbox) {
            checkbox.checked = true;
          }
        });
        updateSelectionStatus();
      });
      selectNone.addEventListener("click", () => {
        selectedItemIds.clear();
        checkboxes.forEach((checkbox) => {
          checkbox.checked = false;
        });
        updateSelectionStatus();
      });
      updateSelectionStatus();
      return section;
    }
    /**
     * Render the workshop card's findings exactly like the editor's own summary.
     *
     * The draft transport keeps the server's stable `field`/`code` pair; only the
     * presentation is resolved here, so the acceptance boundary stays intact.
     */
    aiValidationMessages(errors) {
      return validationMessages(this.config.strings, errors);
    }
    formatAiExpiry(value) {
      const numeric = typeof value === "number" ? value : typeof value === "string" && value.trim() !== "" ? Number(value) : Number.NaN;
      if (!Number.isFinite(numeric) || numeric <= 0) {
        return "";
      }
      const milliseconds2 = numeric < 1e11 ? numeric * 1e3 : numeric;
      const date = new Date(milliseconds2);
      if (Number.isNaN(date.getTime())) {
        return "";
      }
      return this.aiLabel(
        "editor:ai:expires",
        "G\xFCltig bis {$a}"
      ).replace("{$a}", date.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit"
      }));
    }
    formatFileSize(bytes) {
      if (!Number.isFinite(bytes) || bytes <= 0) {
        return "";
      }
      if (bytes < 1024) {
        return `${Math.round(bytes)} B`;
      }
      if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
      }
      return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }
    async discardAiDraft(token) {
      if (token === "") {
        return;
      }
      try {
        await this.api.post("ai_discard", { token });
      } catch (_error) {
      }
    }
    async discardAiSource(selection) {
      try {
        await this.api.post("ai_discard", {
          source: selection.purpose,
          sourceToken: selection.sourceToken
        });
      } catch (_error) {
      }
    }
    async openAiExplanation(question2) {
      var _a;
      if (!this.aiConfig.available || question2.id <= 0) {
        this.showToast(this.aiLabel(
          "editor:ai:unavailable",
          "Die KI-Werkstatt ist derzeit nicht verf\xFCgbar."
        ), true);
        return;
      }
      if ((_a = this.aiExplanationDialog) == null ? void 0 : _a.open) {
        this.aiExplanationDialog.focus();
        return;
      }
      const parts = openDialog(
        this.aiLabel(
          "editor:ai:explanation:title",
          "KI-L\xF6sungsweg als Entwurf"
        ),
        this.s("editor:action:close"),
        "quizgeist-ai-explanation-dialog"
      );
      this.aiExplanationDialog = parts.dialog;
      const shell = element("section", "quizgeist-ai-explanation");
      const intro = element("p", "quizgeist-dialog__intro", {
        text: this.aiLabel(
          "editor:ai:explanation:intro",
          "Der Vorschlag wird aus der kanonischen Frage und ihrer gepr\xFCften L\xF6sung erstellt. Er wird erst nach Ihrer Best\xE4tigung gespeichert."
        )
      });
      const content = element("div", "quizgeist-ai-explanation__content", {
        "aria-live": "polite"
      });
      shell.append(intro, content);
      parts.body.append(shell);
      const cancel = button(
        this.s("editor:action:cancel"),
        "quizgeist-button quizgeist-button--secondary",
        () => closeDialog(parts.dialog)
      );
      const confirm = button(
        this.aiLabel(
          "editor:ai:explanation:confirm",
          "L\xF6sungsweg \xFCbernehmen"
        ),
        "quizgeist-button quizgeist-button--primary"
      );
      confirm.dataset.action = "ai-explanation-apply";
      confirm.disabled = true;
      parts.footer.append(cancel, confirm);
      let draft = null;
      let committed = false;
      let applying = false;
      const preventCloseWhileApplying = (event) => {
        if (!applying) {
          return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
      };
      parts.dialog.addEventListener("cancel", preventCloseWhileApplying, {
        capture: true
      });
      parts.dialog.addEventListener("click", (event) => {
        if (event.target === parts.dialog) {
          preventCloseWhileApplying(event);
        }
      }, { capture: true });
      const renderLoading = () => {
        content.replaceChildren(element("div", "quizgeist-ai-loading", {
          role: "status",
          text: this.aiLabel(
            "editor:ai:explanation:loading",
            "L\xF6sungsweg wird vorgeschlagen \u2026"
          )
        }));
      };
      const generate = async () => {
        var _a2, _b;
        (_a2 = this.aiExplanationController) == null ? void 0 : _a2.abort();
        const controller = new AbortController();
        this.aiExplanationController = controller;
        confirm.disabled = true;
        renderLoading();
        try {
          await this.flushPendingSaves();
          if (!parts.dialog.open || controller.signal.aborted) {
            return;
          }
          if (draft == null ? void 0 : draft.token) {
            await this.discardAiDraft(draft.token);
            draft = null;
          }
          const questionId = this.resolveQuestionId(question2.id);
          const response = await this.api.post(
            "ai_explanation_generate",
            { questionId },
            controller.signal
          );
          const proposal = this.normalizeAiExplanationDraft(response, questionId);
          draft = proposal;
          content.replaceChildren(this.renderAiExplanationDraft(question2, proposal));
          confirm.disabled = false;
          (_b = content.querySelector("[data-ai-explanation-preview]")) == null ? void 0 : _b.focus();
        } catch (error) {
          if (!(error instanceof DOMException && error.name === "AbortError")) {
            const errorBox = element("div", "quizgeist-ai-error", {
              role: "alert"
            });
            errorBox.append(
              element("p", "", {
                text: error instanceof Error ? error.message : this.s("editor:error:request")
              }),
              button(
                this.s("editor:action:retry"),
                "quizgeist-button quizgeist-button--secondary",
                () => void generate()
              )
            );
            content.replaceChildren(errorBox);
          }
        } finally {
          if (this.aiExplanationController === controller) {
            this.aiExplanationController = null;
          }
        }
      };
      confirm.addEventListener("click", async () => {
        const proposal = draft;
        if (!proposal) {
          return;
        }
        applying = true;
        parts.closeButton.disabled = true;
        cancel.disabled = true;
        setButtonBusy(
          confirm,
          true,
          this.aiLabel(
            "editor:ai:explanation:confirming",
            "L\xF6sungsweg wird \xFCbernommen \u2026"
          )
        );
        try {
          const response = await this.api.post(
            "ai_explanation_apply",
            { token: proposal.token }
          );
          const result = this.record(response);
          if (result.committed !== true) {
            throw new Error(this.s("editor:error:response"));
          }
          const canonical = this.record(result.question);
          const canonicalId = Number(canonical.id || 0);
          if (Number.isInteger(canonicalId) && canonicalId > 0) {
            this.selectedQuestionId = canonicalId;
          }
          committed = true;
          draft = null;
          applying = false;
          closeDialog(parts.dialog);
          this.renderLoading();
          await this.loadBootstrap(true);
          this.showToast(this.aiLabel(
            "editor:ai:explanation:applied",
            "Der L\xF6sungsweg wurde als best\xE4tigter Editor-Entwurf \xFCbernommen."
          ));
        } catch (error) {
          applying = false;
          if (!parts.dialog.open) {
            void this.discardAiDraft(proposal.token);
            return;
          }
          parts.closeButton.disabled = false;
          cancel.disabled = false;
          setButtonBusy(
            confirm,
            false,
            this.aiLabel(
              "editor:ai:explanation:confirm",
              "L\xF6sungsweg \xFCbernehmen"
            )
          );
          this.showToast(
            error instanceof Error ? error.message : this.s("editor:error:request"),
            true
          );
        }
      });
      parts.dialog.addEventListener("close", () => {
        var _a2;
        (_a2 = this.aiExplanationController) == null ? void 0 : _a2.abort();
        this.aiExplanationController = null;
        this.tts.stop();
        const token = !committed ? draft == null ? void 0 : draft.token : null;
        draft = null;
        this.aiExplanationDialog = null;
        if (token) {
          void this.discardAiDraft(token);
        }
      }, { once: true });
      void generate();
    }
    normalizeAiExplanationDraft(raw, expectedQuestionId) {
      const envelope = this.record(raw);
      const source = this.record(envelope.draft || raw);
      const token = typeof source.token === "string" ? source.token.trim() : "";
      const questionId = Number(source.questionId || 0);
      const explanation = typeof source.explanation === "string" ? source.explanation.trim() : "";
      if (!/^[a-f0-9]{64}$/.test(token) || source.status !== "draft" || !Number.isInteger(questionId) || questionId !== expectedQuestionId || explanation === "" || Array.from(explanation).length > 6e3) {
        throw new Error(this.s("editor:error:response"));
      }
      const warnings = Array.isArray(source.warnings) ? source.warnings.flatMap((warning) => {
        if (typeof warning === "string" && warning.trim() !== "") {
          return [warning.trim()];
        }
        const structured = this.record(warning);
        const message = typeof structured.message === "string" ? structured.message.trim() : typeof structured.code === "string" ? structured.code.trim() : "";
        return message === "" ? [] : [message];
      }) : [];
      const expiresAt = source.expiresAt;
      return {
        expiresAt: typeof expiresAt === "number" || typeof expiresAt === "string" ? expiresAt : null,
        explanation,
        origin: typeof source.origin === "string" && source.origin.trim() !== "" ? source.origin.trim() : "unknown",
        questionId,
        status: "draft",
        token,
        warnings
      };
    }
    renderAiExplanationDraft(question2, draft) {
      const preview = element("article", "quizgeist-ai-explanation__preview", {
        "data-ai-explanation-preview": true,
        "data-ai-explanation-status": "draft",
        tabindex: -1
      });
      const fallback = draft.origin === "fallback";
      const gateway = draft.origin === "gateway";
      const header = element("header", "quizgeist-ai-explanation__header");
      header.append(
        element("span", "quizgeist-ai-chip quizgeist-ai-chip--draft", {
          text: this.aiLabel("editor:ai:draft", "ENTWURF")
        }),
        element("span", `quizgeist-ai-chip ${fallback ? "quizgeist-ai-chip--fallback" : gateway ? "quizgeist-ai-chip--gateway" : ""}`, {
          text: fallback ? this.aiLabel("editor:ai:fallback", "Regelbasierter Fallback") : gateway ? this.aiLabel("editor:ai:gateway", "Lokales KI-Gateway") : this.aiLabel("editor:ai:server", "Serverseitig erzeugt")
        }),
        element("span", "quizgeist-ai-explanation__expiry", {
          text: this.formatAiExpiry(draft.expiresAt)
        })
      );
      preview.append(
        header,
        element("h3", "", {
          text: this.aiLabel(
            "editor:ai:explanation:preview",
            "Vorgeschlagener L\xF6sungsweg"
          )
        }),
        element("p", "quizgeist-ai-explanation__question", {
          text: this.questionTitle(question2)
        }),
        createTtsControl(
          this.tts,
          [this.questionTitle(question2), draft.explanation].filter(Boolean).join(". "),
          this.config
        ),
        element("div", "quizgeist-ai-explanation__text", {
          text: draft.explanation
        }),
        element("p", "quizgeist-ai-explanation__notice", {
          text: this.aiLabel(
            "editor:ai:explanation:draftnotice",
            "Noch nicht gespeichert: Pr\xFCfen Sie den Vorschlag und best\xE4tigen Sie ihn ausdr\xFCcklich."
          )
        })
      );
      const explanationWarnings = aiWarningMessages(this.config.strings, draft.warnings);
      if (explanationWarnings.length > 0) {
        const warning = element("div", "quizgeist-ai-warning", { role: "status" });
        warning.append(element("strong", "", {
          text: this.aiLabel("editor:ai:warnings", "Hinweise")
        }));
        const list = element("ul");
        explanationWarnings.forEach((message) => {
          list.append(element("li", "", { text: message }));
        });
        warning.append(list);
        preview.append(warning);
      }
      return preview;
    }
    questionTypeIcon(qtype) {
      const icon2 = element("span", `quizgeist-qtype-icon quizgeist-qtype-icon--${qtype}`, {
        "aria-hidden": "true"
      });
      icon2.append(
        element("span", "quizgeist-qtype-icon__shape quizgeist-qtype-icon__shape--one"),
        element("span", "quizgeist-qtype-icon__shape quizgeist-qtype-icon__shape--two")
      );
      return icon2;
    }
    questionTitle(question2) {
      if (question2.qtype === "slide") {
        return question2.options.title.trim() || this.s("editor:question:untitled");
      }
      return question2.questiontext.trim() || this.s("editor:question:untitled");
    }
    renderQuestionMetadata(question2) {
      const metadata = element("span", "quizgeist-question-item__meta");
      const type = this.s(`editor:qtype:${question2.qtype}`);
      metadata.append(element("span", "quizgeist-question-item__type", { text: type }));
      const chips = element("span", "quizgeist-question-item__chips");
      chips.append(
        element("span", "quizgeist-question-item__chip quizgeist-question-item__chip--time", {
          text: `${question2.timelimit} ${this.s("editor:unit:seconds")}`
        }),
        element("span", "quizgeist-question-item__chip quizgeist-question-item__chip--points", {
          text: this.s(`editor:pointmode:${question2.pointmode}`)
        })
      );
      if (question2.qtype === "slide") {
        const layout = question2.options.layout;
        chips.prepend(element("span", "quizgeist-question-item__chip", {
          text: this.s(`editor:layout:${layout.replace("-", "")}`)
        }));
      }
      metadata.append(chips);
      return metadata;
    }
    questionHasErrors(question2) {
      if (Array.isArray(question2.validationErrors)) {
        return question2.validationErrors.length > 0;
      }
      return Object.keys(question2.validationErrors).length > 0;
    }
    renderValidationOverview() {
      const invalidQuestions = this.questions.map((question2, index) => ({ index, question: question2 })).filter(({ question: question2 }) => this.questionHasErrors(question2));
      if (invalidQuestions.length === 0) {
        return null;
      }
      const overview = element("section", "quizgeist-validation-overview");
      const heading = element("h2", "quizgeist-validation-overview__title", {
        text: this.s(invalidQuestions.length === 1 ? "editor:validation:overview:one" : "editor:validation:overview:many").replace("{$a}", String(invalidQuestions.length))
      });
      heading.id = `quizgeist-validation-overview-${crypto.randomUUID()}`;
      overview.setAttribute("aria-labelledby", heading.id);
      const list = element("ul", "quizgeist-validation-overview__list");
      for (const { index, question: question2 } of invalidQuestions) {
        const item = element("li");
        const jumpLabel = this.s("editor:validation:jump").replace("{$a}", String(index + 1)).replace("{$b}", this.questionTitle(question2));
        const jump = button(
          jumpLabel,
          "quizgeist-validation-overview__jump",
          () => this.jumpToQuestionValidation(question2)
        );
        item.append(jump);
        list.append(item);
      }
      overview.append(heading, list);
      return overview;
    }
    refreshValidationOverview() {
      const detail = this.root.querySelector(".quizgeist-question-detail");
      if (!detail) {
        return;
      }
      const existing = detail.querySelector(".quizgeist-validation-overview");
      const replacement = this.renderValidationOverview();
      if (existing && replacement) {
        existing.replaceWith(replacement);
      } else if (existing) {
        existing.remove();
      } else if (replacement) {
        detail.prepend(replacement);
      }
    }
    refreshQuestionTitle(question2) {
      const item = this.root.querySelector(
        `.quizgeist-question-item[data-question-id="${question2.id}"]`
      );
      const title = item == null ? void 0 : item.querySelector(".quizgeist-question-item__title");
      if (title) {
        title.textContent = this.questionTitle(question2);
      }
      const metadata = item == null ? void 0 : item.querySelector(".quizgeist-question-item__meta");
      metadata == null ? void 0 : metadata.replaceWith(this.renderQuestionMetadata(question2));
    }
    refreshQuestionStatus(question2) {
      var _a;
      const item = this.root.querySelector(
        `.quizgeist-question-item[data-question-id="${question2.id}"]`
      );
      if (item) {
        item.classList.toggle("has-errors", this.questionHasErrors(question2));
        const existing = item.querySelector(".quizgeist-question-item__error");
        if (this.questionHasErrors(question2) && !existing) {
          (_a = item.querySelector(".quizgeist-question-item__select")) == null ? void 0 : _a.append(
            element("span", "quizgeist-question-item__error", {
              title: this.s("editor:validation:item"),
              "aria-label": this.s("editor:validation:item")
            })
          );
        } else if (!this.questionHasErrors(question2)) {
          existing == null ? void 0 : existing.remove();
        }
        const text4 = item.querySelector(".quizgeist-question-item__text");
        const status = text4 == null ? void 0 : text4.querySelector(".quizgeist-question-item__status");
        if (status) {
          status.replaceWith(this.renderQuestionStatusBadge(question2));
        } else {
          text4 == null ? void 0 : text4.append(this.renderQuestionStatusBadge(question2));
        }
        const readiness = this.renderQuestionReadiness(question2);
        const existingReadiness = text4 == null ? void 0 : text4.querySelector(
          ".quizgeist-question-item__readiness"
        );
        if (existingReadiness && readiness) {
          existingReadiness.replaceWith(readiness);
        } else if (existingReadiness) {
          existingReadiness.remove();
        } else if (readiness) {
          text4 == null ? void 0 : text4.append(readiness);
        }
        const release = item.querySelector(
          ".quizgeist-question-item__release"
        );
        const replacementRelease = this.renderQuestionReleaseButton(question2);
        if (release && replacementRelease) {
          release.replaceWith(replacementRelease);
        } else if (release) {
          release.remove();
        } else if (replacementRelease) {
          const actions = item.querySelector(".quizgeist-question-actions");
          item.insertBefore(replacementRelease, actions);
        }
      }
      if (question2.id === this.selectedQuestionId) {
        const form = this.root.querySelector(
          `.quizgeist-question-form[data-question-id="${question2.id}"]`
        );
        if (form) {
          const existing = form.querySelector(".quizgeist-validation-summary");
          const replacement = this.formRenderer.renderValidationSummary(question2);
          const summaryHadFocus = Boolean(
            existing && document.activeElement && existing.contains(document.activeElement)
          );
          if (existing && replacement) {
            existing.replaceWith(replacement);
          } else if (existing) {
            existing.remove();
          } else if (replacement) {
            const heading = form.querySelector(".quizgeist-question-form__heading");
            heading == null ? void 0 : heading.insertAdjacentElement("afterend", replacement);
          }
          if (summaryHadFocus) {
            replacement == null ? void 0 : replacement.focus();
          }
        }
      }
      this.refreshValidationOverview();
      this.refreshReleaseControls();
      this.refreshReadinessPanel();
    }
    refreshReadinessPanel() {
      const existing = this.root.querySelector(".quizgeist-editor-nextstep");
      if (!existing) {
        return;
      }
      const replacement = this.renderNextStep();
      if (replacement) {
        existing.replaceWith(replacement);
      } else {
        existing.remove();
      }
    }
    releaseReasonForFailure(question2, error) {
      if (error instanceof Error && error.message !== "") {
        return error.message;
      }
      const readiness = question2 ? this.questionReadinessReason(question2) : null;
      if (readiness) {
        return readiness;
      }
      return this.s("editor:error:request", "Speichern fehlgeschlagen.");
    }
    async releaseQuestion(questionId, releaseButton) {
      if (this.releaseAllRunning) {
        return;
      }
      const resolvedId = this.resolveQuestionId(questionId);
      const question2 = this.questions.find((candidate) => candidate.id === resolvedId);
      if (!question2 || !this.isReleaseCandidate(question2) || this.releasingQuestionIds.has(resolvedId)) {
        return;
      }
      this.releasingQuestionIds.add(resolvedId);
      setButtonBusy(
        releaseButton,
        true,
        this.s("editor:release:working", "Wird freigegeben \u2026")
      );
      try {
        this.markQuestionDirty(question2.id);
        await this.flushPendingSaves();
        const canonical = this.questions.find((candidate) => candidate.id === this.resolveQuestionId(resolvedId));
        if ((canonical == null ? void 0 : canonical.status) === "ready") {
          this.setReleaseResult(this.s(
            "editor:release:success",
            "Die Frage wurde freigegeben."
          ));
        } else {
          const reason = this.releaseReasonForFailure(canonical);
          this.setReleaseResult(this.workshopText(
            "editor:release:draft",
            "Die Frage bleibt Entwurf: {$a}",
            { a: reason }
          ));
        }
      } catch (error) {
        const canonical = this.questions.find((candidate) => candidate.id === this.resolveQuestionId(resolvedId));
        const reason = this.releaseReasonForFailure(canonical, error);
        this.setReleaseResult(this.workshopText(
          "editor:release:draft",
          "Die Frage bleibt Entwurf: {$a}",
          { a: reason }
        ));
      } finally {
        this.releasingQuestionIds.delete(resolvedId);
        setButtonBusy(
          releaseButton,
          false,
          this.s("editor:action:release", "Freigeben")
        );
        this.refreshReleaseControls();
      }
    }
    async releaseAllQuestions(releaseButton) {
      if (this.releaseAllRunning) {
        return;
      }
      this.releaseAllRunning = true;
      setButtonBusy(
        releaseButton,
        true,
        this.s("editor:release:working", "Wird freigegeben \u2026")
      );
      const candidateIds = this.questions.filter((question2) => this.isReleaseCandidate(question2)).map((question2) => question2.id);
      const operationErrors = /* @__PURE__ */ new Map();
      let abortError = null;
      try {
        try {
          await this.flushPendingSaves();
        } catch (error) {
          abortError = error;
          candidateIds.forEach((candidateId) => {
            const resolvedId = this.resolveQuestionId(candidateId);
            const question2 = this.questions.find((item) => item.id === resolvedId);
            if (question2 && question2.status !== "ready") {
              operationErrors.set(candidateId, error);
            }
          });
        }
        if (abortError === null) {
          for (let index = 0; index < candidateIds.length; index += 1) {
            const originalId = candidateIds[index];
            const resolvedId = this.resolveQuestionId(originalId);
            const question2 = this.questions.find((item) => item.id === resolvedId);
            if (!question2) {
              abortError = new Error(this.s("editor:error:response"));
              for (let rest = index; rest < candidateIds.length; rest += 1) {
                operationErrors.set(candidateIds[rest], abortError);
              }
              break;
            }
            this.markQuestionDirty(question2.id);
            try {
              await this.flushPendingSaves();
            } catch (error) {
              abortError = error;
              for (let rest = index; rest < candidateIds.length; rest += 1) {
                operationErrors.set(candidateIds[rest], error);
              }
              break;
            }
          }
        }
        const released = candidateIds.filter((candidateId) => {
          const resolvedId = this.resolveQuestionId(candidateId);
          return this.questions.some((question2) => question2.id === resolvedId && question2.status === "ready");
        }).length;
        const draftQuestions = this.questions.filter((question2) => question2.status !== "ready");
        const reasons = draftQuestions.map((question2, index) => {
          const candidateId = candidateIds.find((id) => this.resolveQuestionId(id) === question2.id);
          const error = candidateId === void 0 ? void 0 : operationErrors.get(candidateId);
          return `${index + 1}. ${this.questionTitle(question2)}: ` + this.releaseReasonForFailure(question2, error);
        });
        const drafts = draftQuestions.length;
        let message = this.workshopText(
          "editor:release:bulk:result",
          "Freigegeben: {$a->released}. Im Entwurf geblieben: {$a->draft}.",
          { released, draft: drafts }
        );
        if (reasons.length > 0) {
          message += ` ${this.workshopText(
            "editor:release:bulk:reasons",
            "Gr\xFCnde: {$a}",
            { a: reasons.join(" \xB7 ") }
          )}`;
        }
        this.setReleaseResult(message);
      } finally {
        this.releaseAllRunning = false;
        setButtonBusy(
          releaseButton,
          false,
          this.s("editor:action:releaseall", "Alle vollst\xE4ndigen Fragen freigeben")
        );
        this.refreshReleaseControls();
      }
    }
    selectedQuestion() {
      return this.questions.find((question2) => question2.id === this.selectedQuestionId) || null;
    }
    openQuestionPalette() {
      const dialogParts = openDialog(
        this.s("editor:addpalette:title"),
        this.s("editor:action:close"),
        "quizgeist-question-palette-dialog"
      );
      const intro = element("p", "quizgeist-dialog__intro", {
        text: this.s("editor:addpalette:description")
      });
      const grid = element("div", "quizgeist-question-palette");
      for (const qtype of this.supportedTypes) {
        const typeButton = button("", "quizgeist-question-type-card", () => {
          closeDialog(dialogParts.dialog);
          void this.createQuestion(qtype);
        });
        typeButton.append(
          this.questionTypeIcon(qtype),
          element("span", "quizgeist-question-type-card__title", {
            text: this.s(`editor:qtype:${qtype}`)
          }),
          element("span", "quizgeist-question-type-card__description", {
            text: this.s(`editor:qtype:${qtype}:description`)
          })
        );
        grid.append(typeButton);
      }
      dialogParts.body.append(intro, grid);
    }
    async createQuestion(qtype) {
      try {
        await this.flushPendingSaves();
        this.setSaveState("saving");
        const data = await this.api.post("question_create", { qtype });
        const question2 = normalizeQuestion(this.extractQuestion(data));
        if (question2.id <= 0) {
          throw new Error(this.s("editor:error:response"));
        }
        this.questions.push(question2);
        this.questions.sort((left, right) => left.sortorder - right.sortorder);
        this.renumberQuestionSortorders();
        this.selectedQuestionId = question2.id;
        this.finishEditorOperation();
        this.render();
        window.requestAnimationFrame(() => {
          var _a;
          (_a = this.root.querySelector(".quizgeist-question-form textarea, .quizgeist-question-form input")) == null ? void 0 : _a.focus();
        });
      } catch (error) {
        this.setSaveError(error);
      }
    }
    async duplicateQuestion(questionId) {
      try {
        await this.flushPendingSaves();
        questionId = this.resolveQuestionId(questionId);
        this.setSaveState("saving");
        const data = await this.api.post("question_duplicate", {
          questionid: questionId
        });
        const question2 = normalizeQuestion(this.extractQuestion(data));
        if (question2.id <= 0) {
          await this.loadBootstrap(true);
          return;
        }
        const canonical = this.extractQuestions(data);
        const questionIdMap = this.extractQuestionIdMap(data);
        const sourceIndex = this.questions.findIndex((item) => item.id === questionId);
        const insertionIndex = sourceIndex >= 0 ? sourceIndex + 1 : this.questions.length;
        this.questions.splice(insertionIndex, 0, question2);
        const expectedIds = this.questions.map((item) => item.id);
        if (!await this.reconcileCanonicalQuestions(canonical, expectedIds, questionIdMap, true)) {
          return;
        }
        this.selectedQuestionId = this.resolveQuestionId(question2.id);
        this.finishEditorOperation();
        this.render();
        this.announce(this.s("editor:announcement:duplicated"));
      } catch (error) {
        this.setSaveError(error);
      }
    }
    async deleteQuestion(questionId) {
      var _a;
      if (!window.confirm(this.s("editor:confirm:deletequestion"))) {
        return;
      }
      try {
        await this.flushPendingSaves();
        questionId = this.resolveQuestionId(questionId);
        this.setSaveState("saving");
        const data = await this.api.post("question_delete", { questionid: questionId });
        const canonical = this.extractQuestions(data);
        const questionIdMap = this.extractQuestionIdMap(data);
        const index = this.questions.findIndex((question2) => question2.id === questionId);
        if (index < 0) {
          throw new Error(this.s("editor:error:response"));
        }
        const deletingSelection = this.selectedQuestionId === questionId;
        this.questions.splice(index, 1);
        this.questionDirtyVersions.delete(questionId);
        this.questionSavedVersions.delete(questionId);
        this.questionIdAliases.forEach((target, alias) => {
          if (alias === questionId || target === questionId) {
            this.questionIdAliases.delete(alias);
          }
        });
        const expectedIds = this.questions.map((question2) => question2.id);
        if (!await this.reconcileCanonicalQuestions(canonical, expectedIds, questionIdMap, true)) {
          return;
        }
        if (deletingSelection) {
          this.selectedQuestionId = ((_a = this.questions[Math.min(Math.max(index, 0), this.questions.length - 1)]) == null ? void 0 : _a.id) || null;
        }
        this.finishEditorOperation();
        this.render();
        this.announce(this.s("editor:announcement:deleted"));
      } catch (error) {
        this.setSaveError(error);
      }
    }
    moveQuestion(questionId, delta) {
      const index = this.questions.findIndex((question2) => question2.id === questionId);
      const target = index + delta;
      if (index < 0 || target < 0 || target >= this.questions.length) {
        return;
      }
      const [moved] = this.questions.splice(index, 1);
      this.questions.splice(target, 0, moved);
      this.finishOptimisticReorder(moved.id);
    }
    reorderQuestion(sourceId, targetId) {
      const sourceIndex = this.questions.findIndex((question2) => question2.id === sourceId);
      const targetIndex = this.questions.findIndex((question2) => question2.id === targetId);
      if (sourceIndex < 0 || targetIndex < 0 || sourceIndex === targetIndex) {
        return;
      }
      const [moved] = this.questions.splice(sourceIndex, 1);
      this.questions.splice(targetIndex, 0, moved);
      this.finishOptimisticReorder(moved.id);
    }
    finishOptimisticReorder(movedQuestionId) {
      this.renumberQuestionSortorders();
      this.questionOrderDirtyVersion += 1;
      this.render();
      const position = this.questions.findIndex((question2) => question2.id === movedQuestionId) + 1;
      this.announce(
        this.s("editor:announcement:moved").replace("{$a}", String(position))
      );
      this.setSaveState("dirty");
      this.scheduleAutosave();
    }
    renumberQuestionSortorders() {
      this.questions.forEach((question2, index) => {
        question2.sortorder = index;
      });
    }
    markQuestionDirty(questionId) {
      const version = (this.questionDirtyVersions.get(questionId) || 0) + 1;
      this.questionDirtyVersions.set(questionId, version);
      this.setSaveState("dirty");
      this.scheduleAutosave();
    }
    markActivityDirty() {
      this.activityDirtyVersion += 1;
      this.applyAppearance();
      this.setSaveState("dirty");
      this.scheduleAutosave();
    }
    scheduleAutosave() {
      if (this.autosaveTimer !== null) {
        window.clearTimeout(this.autosaveTimer);
      }
      this.autosaveTimer = window.setTimeout(() => {
        this.autosaveTimer = null;
        void this.runSaveLoop();
      }, 800);
    }
    async runSaveLoop() {
      if (this.saveLoopRunning) {
        await new Promise((resolve) => this.saveLoopWaiters.push(resolve));
        if (this.hasUnsavedChanges() && this.saveState !== "error" && this.saveState !== "conflict") {
          await this.runSaveLoop();
        }
        return;
      }
      this.saveLoopRunning = true;
      this.setSaveState("saving");
      try {
        while (this.hasUnsavedChanges()) {
          if (this.activityDirtyVersion > this.activitySavedVersion) {
            const version = this.activityDirtyVersion;
            const data = await this.api.post("quiz_save", {
              activity: {
                name: this.activity.name,
                theme: this.activity.theme,
                season: this.activity.season,
                allowbacktrack: this.activity.allowbacktrack,
                timemodified: this.activity.timemodified
              }
            });
            const result = this.extractActivity(data);
            if (!result) {
              throw new Error(this.s("editor:error:response"));
            }
            const canonicalActivity = this.normalizeActivity({
              ...this.activity,
              ...result
            });
            if (version === this.activityDirtyVersion) {
              Object.assign(this.activity, canonicalActivity);
              this.applyAppearance();
            } else {
              this.activity.id = canonicalActivity.id;
              this.activity.timemodified = canonicalActivity.timemodified;
            }
            this.activitySavedVersion = Math.max(this.activitySavedVersion, version);
            continue;
          }
          const pending = this.questions.find((question2) => (this.questionDirtyVersions.get(question2.id) || 0) > (this.questionSavedVersions.get(question2.id) || 0));
          if (pending) {
            const requestQuestionId = pending.id;
            const version = this.questionDirtyVersions.get(requestQuestionId) || 0;
            const data = await this.api.post("question_save", {
              question: cloneQuestionForSave(pending)
            });
            const normalized = normalizeQuestion(this.extractQuestion(data));
            if (normalized.id <= 0) {
              throw new Error(this.s("editor:error:response"));
            }
            const latestVersion = this.questionDirtyVersions.get(requestQuestionId) || 0;
            this.questionSavedVersions.set(
              requestQuestionId,
              Math.max(this.questionSavedVersions.get(requestQuestionId) || 0, version)
            );
            this.migrateQuestionId(pending, requestQuestionId, normalized.id);
            if (version === latestVersion) {
              this.mergeSavedQuestion(pending, normalized);
              this.renumberQuestionSortorders();
              this.refreshQuestionStatus(pending);
            } else {
              pending.rootid = normalized.rootid;
              pending.version = normalized.version;
              pending.timemodified = normalized.timemodified;
            }
            continue;
          }
          if (this.questionOrderDirtyVersion > this.questionOrderSavedVersion) {
            const version = this.questionOrderDirtyVersion;
            const questionids = this.questions.map((question2) => question2.id);
            const data = await this.api.post("question_reorder", { questionids });
            const questions = this.extractQuestions(data);
            const questionIdMap = this.extractQuestionIdMap(data);
            const reconciled = await this.reconcileCanonicalQuestions(
              questions,
              questionids,
              questionIdMap,
              version === this.questionOrderDirtyVersion
            );
            if (!reconciled) {
              return;
            }
            this.questionOrderSavedVersion = Math.max(this.questionOrderSavedVersion, version);
            continue;
          }
          break;
        }
        this.setSaveState("saved");
      } catch (error) {
        this.setSaveError(error);
      } finally {
        this.saveLoopRunning = false;
        this.saveLoopWaiters.splice(0).forEach((resolve) => resolve());
        if (this.hasUnsavedChanges() && this.saveState !== "error" && this.saveState !== "conflict") {
          this.scheduleAutosave();
        }
      }
    }
    async flushPendingSaves() {
      if (this.autosaveTimer !== null) {
        window.clearTimeout(this.autosaveTimer);
        this.autosaveTimer = null;
      }
      await this.runSaveLoop();
      if (this.saveState === "error" || this.saveState === "conflict" || this.hasUnsavedChanges()) {
        throw new Error(this.saveError || this.s("editor:error:request"));
      }
    }
    hasUnsavedChanges() {
      if (this.activityDirtyVersion > this.activitySavedVersion) {
        return true;
      }
      if (this.questionOrderDirtyVersion > this.questionOrderSavedVersion) {
        return true;
      }
      return this.questions.some((question2) => (this.questionDirtyVersions.get(question2.id) || 0) > (this.questionSavedVersions.get(question2.id) || 0));
    }
    setSaveState(state) {
      this.saveState = state;
      if (state !== "error" && state !== "conflict") {
        this.saveError = "";
      }
      this.updateSaveStatusElement();
    }
    setSaveError(error) {
      if (this.saveState === "conflict") {
        return;
      }
      if (error instanceof EditorApiError && (error.status === 409 || error.code === "conflict")) {
        this.handleSaveConflict();
        return;
      }
      this.saveError = error instanceof Error ? error.message : this.s("editor:error:request");
      this.setSaveState("error");
      this.showToast(this.saveError, true);
    }
    handleSaveConflict() {
      var _a;
      this.saveError = this.s("editor:conflict:message");
      this.setSaveState("conflict");
      if (this.autosaveTimer !== null) {
        window.clearTimeout(this.autosaveTimer);
        this.autosaveTimer = null;
      }
      if ((_a = this.conflictDialog) == null ? void 0 : _a.open) {
        return;
      }
      const parts = openDialog(
        this.s("editor:conflict:title"),
        this.s("editor:conflict:stay"),
        "quizgeist-conflict-dialog"
      );
      this.conflictDialog = parts.dialog;
      parts.body.append(element("p", "quizgeist-dialog__intro", {
        text: this.s("editor:conflict:message")
      }));
      const stay = button(
        this.s("editor:conflict:stay"),
        "quizgeist-button quizgeist-button--secondary",
        () => closeDialog(parts.dialog)
      );
      const reload = button(
        this.s("editor:conflict:reload"),
        "quizgeist-button quizgeist-button--primary",
        () => void this.reloadAfterConflict(parts.dialog)
      );
      parts.footer.append(stay, reload);
      parts.dialog.addEventListener("close", () => {
        if (this.conflictDialog === parts.dialog) {
          this.conflictDialog = null;
        }
      }, { once: true });
    }
    async reloadAfterConflict(dialog = this.conflictDialog) {
      if (dialog) {
        closeDialog(dialog);
      }
      this.conflictDialog = null;
      this.setSaveState("saving");
      this.renderLoading();
      await this.loadBootstrap(true);
    }
    finishEditorOperation() {
      if (this.saveState === "error" || this.saveState === "conflict") {
        return;
      }
      if (this.hasUnsavedChanges()) {
        this.setSaveState("dirty");
        this.scheduleAutosave();
        return;
      }
      this.setSaveState("saved");
    }
    updateSaveStatusElement() {
      if (!this.saveStatusElement) {
        return;
      }
      this.saveStatusElement.replaceChildren();
      this.saveStatusElement.dataset.state = this.saveState;
      const dot = element("span", "quizgeist-save-status__dot", { "aria-hidden": "true" });
      const text4 = element("span", "", {
        text: this.s(`editor:save:${this.saveState}`)
      });
      this.saveStatusElement.append(dot, text4);
      if (this.saveState === "error" || this.saveState === "conflict") {
        const retry = button(
          this.s(this.saveState === "conflict" ? "editor:conflict:reload" : "editor:action:retry"),
          "quizgeist-save-status__retry",
          () => {
            if (this.saveState === "conflict") {
              void this.reloadAfterConflict();
            } else {
              this.setSaveState("dirty");
              void this.runSaveLoop();
            }
          }
        );
        this.saveStatusElement.append(retry);
        this.saveStatusElement.title = this.saveError;
      } else {
        this.saveStatusElement.removeAttribute("title");
      }
    }
    applyAppearance() {
      this.root.dataset.quizgeistTheme = this.activity.theme || "hell";
      this.root.dataset.quizgeistSeason = this.activity.season || "herbst";
      document.querySelectorAll("dialog.quizgeist-dialog[data-quizgeist-root]").forEach((dialog) => {
        if (dialog.dataset.quizgeistOwner && dialog.dataset.quizgeistOwner !== this.root.id) {
          return;
        }
        dialog.dataset.quizgeistTheme = this.root.dataset.quizgeistTheme;
        dialog.dataset.quizgeistSeason = this.root.dataset.quizgeistSeason;
      });
    }
    themeSelect() {
      const select = element("select", "quizgeist-select quizgeist-theme-select");
      for (const theme of this.availableThemes) {
        select.append(element("option", "", {
          value: theme,
          text: this.s(`theme:${theme === "retro-arcade" ? "retroarcade" : theme}`)
        }));
      }
      select.value = this.activity.theme;
      select.addEventListener("change", () => {
        this.activity.theme = select.value;
        this.markActivityDirty();
        this.render();
      });
      return select;
    }
    seasonSelect() {
      const select = element("select", "quizgeist-select quizgeist-season-select");
      for (const season of this.availableSeasons) {
        select.append(element("option", "", {
          value: season,
          text: this.s(`editor:season:${season}`)
        }));
      }
      select.value = this.activity.season;
      select.addEventListener("change", () => {
        this.activity.season = select.value;
        this.markActivityDirty();
      });
      return select;
    }
    openAppearanceDialog() {
      const parts = openDialog(
        this.s("editor:appearance:title"),
        this.s("editor:action:close"),
        "quizgeist-appearance-dialog"
      );
      const theme = this.themeSelect();
      const season = this.seasonSelect();
      const fields = element("div", "quizgeist-field-grid");
      fields.append(labelledField(this.s("editor:field:theme"), theme));
      if (this.availableThemes.includes("jahreszeiten")) {
        fields.append(labelledField(this.s("editor:field:season"), season));
      }
      parts.body.append(fields);
      const mediaGrid = element("div", "quizgeist-appearance-media");
      mediaGrid.append(
        this.appearanceMediaCard(
          "background",
          this.s("editor:appearance:background"),
          this.activity.background[0]
        ),
        this.appearanceMediaCard(
          "logo",
          this.s("editor:appearance:logo"),
          this.activity.logo[0]
        )
      );
      parts.body.append(mediaGrid);
      parts.footer.append(button(
        this.s("editor:action:done"),
        "quizgeist-button quizgeist-button--primary",
        () => closeDialog(parts.dialog)
      ));
    }
    appearanceMediaCard(area, title, file) {
      const card = element("section", "quizgeist-appearance-media__card");
      card.append(element("h3", "", { text: title }));
      if (file) {
        card.append(
          element("img", "quizgeist-appearance-media__preview", {
            src: file.url,
            alt: file.filename
          }),
          element("p", "quizgeist-appearance-media__filename", { text: file.filename })
        );
      } else {
        card.append(element("div", "quizgeist-media-empty", {
          text: this.s("editor:appearance:nofile")
        }));
      }
      card.append(button(
        this.s("editor:action:choosefile"),
        "quizgeist-button quizgeist-button--secondary",
        () => void this.openMediaDialog(area, 0, "appearance")
      ));
      return card;
    }
    handleMediaSavedMessage(message) {
      const dialogQuestionId = this.mediaDialogQuestionId;
      if (this.mediaDialog) {
        closeDialog(this.mediaDialog);
        this.mediaDialog = null;
      }
      this.mediaDialogQuestionId = null;
      const payload = this.record(message);
      if (payload.area !== "questionmedia" || !this.isRecord(payload.question)) {
        void this.loadBootstrap(true);
        return;
      }
      const normalized = normalizeQuestion(payload.question);
      if (normalized.id <= 0) {
        void this.loadBootstrap(true);
        return;
      }
      const explicitPreviousId = Number(payload.oldquestionid || 0);
      const messageItemId = Number(payload.itemid || 0);
      const previousId = Number.isInteger(explicitPreviousId) && explicitPreviousId > 0 ? explicitPreviousId : dialogQuestionId || messageItemId;
      const resolvedPreviousId = this.resolveQuestionId(previousId);
      const localQuestion = this.questions.find((question2) => question2.id === resolvedPreviousId || question2.id === normalized.id);
      if (!localQuestion) {
        void this.loadBootstrap(true);
        return;
      }
      const dirty = (this.questionDirtyVersions.get(localQuestion.id) || 0) > (this.questionSavedVersions.get(localQuestion.id) || 0);
      this.migrateQuestionId(localQuestion, localQuestion.id, normalized.id);
      if (!dirty) {
        this.mergeSavedQuestion(localQuestion, normalized);
      } else {
        this.mergeAuthoritativeMedia(localQuestion, normalized);
      }
      this.renumberQuestionSortorders();
      this.render();
    }
    mergeAuthoritativeMedia(target, source) {
      target.rootid = source.rootid;
      target.version = source.version;
      target.timemodified = source.timemodified;
      target.files = source.files;
      const targetOptions = this.record(target.options);
      const sourceOptions = this.record(source.options);
      targetOptions.media = typeof sourceOptions.media === "string" ? sourceOptions.media : null;
      for (const collection of ["answers", "items"]) {
        const targetRows = Array.isArray(targetOptions[collection]) ? targetOptions[collection] : [];
        const sourceRows = Array.isArray(sourceOptions[collection]) ? sourceOptions[collection] : [];
        const sourceById = new Map(sourceRows.map((entry) => {
          const row = this.record(entry);
          return [String(row.id || ""), row];
        }));
        targetRows.forEach((entry) => {
          const row = this.record(entry);
          const canonical = sourceById.get(String(row.id || ""));
          row.media = canonical && typeof canonical.media === "string" ? canonical.media : null;
        });
      }
    }
    async openMediaDialog(area, itemId, target) {
      if (!this.config.mediaUrl) {
        this.showToast(this.s("editor:media:unavailable"), true);
        return;
      }
      try {
        await this.flushPendingSaves();
      } catch (error) {
        this.showToast(
          error instanceof Error ? error.message : this.s("editor:error:request"),
          true
        );
        return;
      }
      const resolvedItemId = area === "questionmedia" ? this.resolveQuestionId(itemId) : itemId;
      const parts = openDialog(
        this.s(`editor:media:title:${area === "questionmedia" ? "question" : area}`),
        this.s("editor:action:close"),
        "quizgeist-media-dialog"
      );
      this.mediaDialog = parts.dialog;
      this.mediaDialogQuestionId = area === "questionmedia" ? resolvedItemId : null;
      const url = new URL(this.config.mediaUrl, window.location.href);
      url.searchParams.set("id", String(this.config.cmid));
      url.searchParams.set("area", area);
      url.searchParams.set("itemid", String(resolvedItemId));
      url.searchParams.set("target", target);
      const iframe = element("iframe", "quizgeist-media-dialog__frame", {
        src: url.toString(),
        title: this.s("editor:media:iframe:title"),
        loading: "eager"
      });
      parts.body.append(iframe);
      parts.dialog.addEventListener("close", () => {
        if (this.mediaDialog === parts.dialog) {
          this.mediaDialog = null;
        }
        this.mediaDialogQuestionId = null;
      }, { once: true });
    }
    openPreview() {
      const question2 = this.selectedQuestion();
      if (!question2) {
        this.showToast(this.s("editor:preview:noquestion"), true);
        return;
      }
      const parts = openDialog(
        this.s("editor:preview:title"),
        this.s("editor:action:close"),
        "quizgeist-preview-dialog"
      );
      const preview = element("article", "quizgeist-question-preview");
      const background = this.activity.background[0];
      if (background) {
        preview.append(element("img", "quizgeist-question-preview__background", {
          src: background.url,
          alt: "",
          "aria-hidden": "true"
        }));
      }
      const header = element("header", "quizgeist-question-preview__header");
      const logo = this.activity.logo[0];
      header.append(element("img", "quizgeist-question-preview__logo", {
        src: (logo == null ? void 0 : logo.url) || this.config.brandIconUrl,
        alt: this.s(logo ? "editor:logo:schoolalt" : "editor:logo:alt")
      }));
      header.append(element("span", "quizgeist-question-preview__type", {
        text: this.s(`editor:qtype:${question2.qtype}`)
      }));
      preview.append(header);
      const content = element("div", "quizgeist-question-preview__content");
      const questionHeading = element("h3", "quizgeist-question-preview__question", {
        text: this.questionTitle(question2)
      });
      content.append(questionHeading);
      content.append(this.renderTtsControls(question2, parts.dialog));
      if (question2.qtype !== "pin" && question2.qtype !== "slide") {
        const mainMedia = this.questionMedia(question2);
        if (mainMedia) {
          content.append(this.renderMediaElement(
            mainMedia,
            "quizgeist-question-preview__media"
          ));
        }
      }
      content.append(this.renderPreviewResponse(question2));
      if (question2.explanation.trim() !== "") {
        const details = element("details", "quizgeist-question-preview__explanation");
        details.append(
          element("summary", "", { text: this.s("editor:field:explanation") }),
          element("p", "", { text: question2.explanation })
        );
        content.append(details);
      }
      preview.append(content);
      parts.body.append(preview);
      parts.footer.append(button(
        this.s("editor:action:closepreview"),
        "quizgeist-button quizgeist-button--primary",
        () => closeDialog(parts.dialog)
      ));
      parts.dialog.addEventListener("close", () => this.tts.stop(), { once: true });
    }
    renderTtsControls(question2, dialog) {
      var _a;
      const wrapper = element("div", "quizgeist-tts-controls");
      const play = button(
        this.s("editor:tts:play"),
        "quizgeist-button quizgeist-button--secondary quizgeist-tts-button"
      );
      play.setAttribute("aria-pressed", "false");
      play.disabled = !this.tts.isAvailable();
      if (!this.tts.isAvailable()) {
        play.title = this.s("editor:tts:unavailable");
      }
      const voices2 = element("select", "quizgeist-select quizgeist-tts-voice", {
        "aria-label": this.s("editor:tts:voice"),
        disabled: !this.tts.isAvailable()
      });
      this.config.tts.voices.forEach((voice) => {
        voices2.append(element("option", "", {
          value: voice.id,
          text: voice.label
        }));
      });
      voices2.value = String(this.config.tts.defaultVoiceId || ((_a = this.config.tts.voices[0]) == null ? void 0 : _a.id) || "");
      play.addEventListener("click", async () => {
        const active = play.getAttribute("aria-pressed") === "true";
        if (active) {
          this.tts.stop();
          play.setAttribute("aria-pressed", "false");
          play.textContent = this.s("editor:tts:play");
          return;
        }
        play.setAttribute("aria-pressed", "true");
        play.setAttribute("aria-busy", "true");
        play.textContent = this.s("editor:tts:loading");
        try {
          await this.tts.play(this.ttsText(question2), Number(voices2.value));
        } catch (error) {
          if (!(error instanceof DOMException && error.name === "AbortError")) {
            this.showToast(
              error instanceof Error ? error.message : this.s("editor:tts:error"),
              true
            );
          }
        } finally {
          if (dialog.open) {
            play.setAttribute("aria-pressed", "false");
            play.setAttribute("aria-busy", "false");
            play.textContent = this.s("editor:tts:play");
          }
        }
      });
      wrapper.append(play, voices2);
      return wrapper;
    }
    ttsText(question2) {
      if (question2.qtype !== "slide") {
        return question2.questiontext;
      }
      const options = question2.options;
      return [
        options.title,
        options.body,
        options.quote,
        options.attribution,
        ...options.bullets
      ].filter(Boolean).join(". ");
    }
    questionMedia(question2) {
      const media = question2.options.media;
      if (!media) {
        return null;
      }
      return question2.files.find((file) => file.path === media || file.filename === media || `${file.filepath === "/" ? "" : file.filepath || ""}${file.filename}` === media || file.url === media) || null;
    }
    renderPreviewResponse(question2) {
      const response = element("div", `quizgeist-preview-response quizgeist-preview-response--${question2.qtype}`);
      const options = question2.options;
      if (question2.qtype === "quiz" || question2.qtype === "poll") {
        const answers = Array.isArray(options.answers) ? options.answers : [];
        const grid = element("div", "quizgeist-preview-answers");
        answers.forEach((raw, index) => {
          const answer = this.record(raw);
          const tile = element("div", `quizgeist-preview-answer quizgeist-preview-answer--${index + 1}`);
          tile.append(
            element("span", `quizgeist-answer-shape quizgeist-answer-shape--${index + 1}`, {
              "aria-hidden": "true"
            }),
            element("span", "", {
              text: String(answer.text || this.s("editor:preview:emptyanswer"))
            })
          );
          const answerMedia = this.mediaForPath(question2, String(answer.media || ""));
          if (answerMedia) {
            tile.append(this.renderMediaElement(
              answerMedia,
              "quizgeist-preview-answer__media"
            ));
          }
          grid.append(tile);
        });
        response.append(grid);
      } else if (question2.qtype === "truefalse") {
        const grid = element("div", "quizgeist-preview-answers");
        [this.s("editor:true"), this.s("editor:false")].forEach((label, index) => {
          const tile = element("div", `quizgeist-preview-answer quizgeist-preview-answer--${index === 0 ? 1 : 4}`);
          tile.append(
            element("span", `quizgeist-answer-shape quizgeist-answer-shape--${index === 0 ? 1 : 4}`, {
              "aria-hidden": "true"
            }),
            element("span", "", { text: label })
          );
          grid.append(tile);
        });
        response.append(grid);
      } else if (question2.qtype === "shortanswer" || question2.qtype === "wordcloud" || question2.qtype === "open" || question2.qtype === "reveal") {
        response.append(element("textarea", "quizgeist-textarea", {
          rows: question2.qtype === "open" ? 5 : 2,
          disabled: true,
          placeholder: this.s("editor:preview:typeanswer")
        }));
      } else if (question2.qtype === "puzzle") {
        const list = element("ol", "quizgeist-preview-puzzle");
        const items = Array.isArray(options.items) ? options.items : [];
        items.forEach((raw, index) => {
          const item = this.record(raw);
          const listItem = element("li");
          listItem.append(element("span", "", {
            text: `${index + 1}. ${String(item.text || this.s("editor:preview:emptyanswer"))}`
          }));
          const itemMedia = this.mediaForPath(question2, String(item.media || ""));
          if (itemMedia) {
            listItem.append(this.renderMediaElement(
              itemMedia,
              "quizgeist-preview-puzzle__media"
            ));
          }
          list.append(listItem);
        });
        response.append(list);
      } else if (question2.qtype === "scale") {
        const scale = element("div", "quizgeist-preview-scale");
        const steps = Number(options.steps || 5);
        for (let value = 1; value <= steps; value++) {
          scale.append(element("span", "", { text: String(value) }));
        }
        response.append(scale);
      } else if (question2.qtype === "slider") {
        response.append(
          element("output", "quizgeist-preview-slider__value", {
            text: String(options.target || 0)
          }),
          element("input", "quizgeist-preview-slider", {
            type: "range",
            min: Number(options.min || 0),
            max: Number(options.max || 100),
            step: Number(options.step || 1),
            value: Number(options.target || 0),
            disabled: true
          })
        );
      } else if (question2.qtype === "pin") {
        const media = this.questionMedia(question2);
        const target = this.record(options.target);
        if (media) {
          const pin = element("div", "quizgeist-preview-pin");
          pin.append(
            element("img", "", { src: media.url, alt: media.filename }),
            element("span", "quizgeist-preview-pin__marker", { "aria-hidden": "true" })
          );
          const marker = pin.lastElementChild;
          marker.style.left = `${Number(target.x || 50)}%`;
          marker.style.top = `${Number(target.y || 50)}%`;
          response.append(pin);
        }
      } else if (question2.qtype === "brainstorm") {
        response.append(element("div", "quizgeist-info-box", {
          text: this.s("editor:preview:brainstorm")
        }));
      } else if (question2.qtype === "slide") {
        const slide = question2.options;
        const slideCard = element("section", `quizgeist-preview-slide quizgeist-preview-slide--${slide.layout}`);
        if (slide.layout === "quote") {
          slideCard.append(
            element("blockquote", "", { text: slide.quote }),
            element("cite", "", { text: slide.attribution })
          );
        } else if (slide.layout === "bullets") {
          const list = element("ul");
          slide.bullets.forEach((bullet) => list.append(element("li", "", { text: bullet })));
          slideCard.append(list);
        } else {
          slideCard.append(element("p", "", { text: slide.body }));
        }
        const slideMedia = this.questionMedia(question2);
        if (slideMedia) {
          slideCard.append(this.renderMediaElement(
            slideMedia,
            "quizgeist-preview-slide__media"
          ));
        }
        response.append(slideCard);
      }
      return response;
    }
    renderTemplateLibrary() {
      const shell = element("section", "quizgeist-template-library");
      const header = element("header", "quizgeist-template-library__header");
      const headingBlock = element("div");
      headingBlock.append(
        element("p", "quizgeist-eyebrow", { text: this.s("editor:templates:eyebrow") }),
        element("h2", "", { text: this.s("editor:templates:title") }),
        element("p", "", { text: this.s("editor:templates:description") })
      );
      const actions = element("div", "quizgeist-template-library__actions");
      actions.append(
        button(
          this.s("editor:action:backtoeditor"),
          "quizgeist-button quizgeist-button--secondary",
          () => {
            this.view = "editor";
            this.render();
          }
        ),
        button(
          this.s("editor:action:publish"),
          "quizgeist-button quizgeist-button--primary",
          () => this.openPublishDialog()
        )
      );
      header.append(headingBlock, actions);
      shell.append(header);
      const searchForm = element("form", "quizgeist-template-search", {
        role: "search"
      });
      const search = element("input", "quizgeist-input", {
        type: "search",
        placeholder: this.s("editor:templates:searchplaceholder"),
        "aria-label": this.s("editor:templates:searchlabel")
      });
      const submit = button(
        this.s("editor:action:search"),
        "quizgeist-button quizgeist-button--primary"
      );
      submit.type = "submit";
      searchForm.append(search, submit);
      searchForm.addEventListener("submit", (event) => {
        event.preventDefault();
        void this.searchTemplates(search.value);
      });
      search.addEventListener("input", () => {
        if (this.templateSearchTimer !== null) {
          window.clearTimeout(this.templateSearchTimer);
        }
        this.templateSearchTimer = window.setTimeout(() => {
          this.templateSearchTimer = null;
          void this.searchTemplates(search.value);
        }, 300);
      });
      shell.append(searchForm);
      const results = element("div", "quizgeist-template-results", {
        "aria-live": "polite"
      });
      results.dataset.region = "template-results";
      shell.append(results);
      this.root.append(shell);
      void this.searchTemplates("");
    }
    async searchTemplates(query) {
      var _a;
      const results = this.root.querySelector('[data-region="template-results"]');
      if (!results) {
        return;
      }
      (_a = this.templateSearchController) == null ? void 0 : _a.abort();
      this.templateSearchController = new AbortController();
      results.replaceChildren(element("div", "quizgeist-editor-loading", {
        text: this.s("editor:templates:loading"),
        role: "status"
      }));
      try {
        const data = await this.api.post(
          "template_search",
          { query },
          this.templateSearchController.signal
        );
        const templates = this.extractTemplates(data);
        results.replaceChildren();
        if (templates.length === 0) {
          results.append(element("div", "quizgeist-template-empty", {
            text: this.s("editor:templates:empty")
          }));
          return;
        }
        const grid = element("div", "quizgeist-template-grid");
        templates.forEach((template) => grid.append(this.renderTemplateCard(template)));
        results.append(grid);
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") {
          return;
        }
        results.replaceChildren(element("div", "quizgeist-editor-error", {
          role: "alert",
          text: error instanceof Error ? error.message : this.s("editor:error:request")
        }));
      }
    }
    extractTemplates(data) {
      const source = this.record(data);
      const raw = Array.isArray(source.templates) ? source.templates : [];
      return raw.map((entry) => {
        const template = this.record(entry);
        return {
          id: Number(template.id || 0),
          name: String(template.name || ""),
          description: String(template.description || ""),
          tags: Array.isArray(template.tags) ? template.tags.map((tag) => String(tag)) : [],
          theme: String(template.theme || "hell"),
          questionCount: Number(template.questionCount || 0),
          timemodified: Number(template.timemodified || 0),
          canDelete: template.canDelete === true
        };
      }).filter((template) => template.id > 0 && template.name !== "");
    }
    renderTemplateCard(template) {
      const card = element("article", "quizgeist-template-card");
      card.dataset.theme = template.theme;
      const visual = element("div", "quizgeist-template-card__visual", { "aria-hidden": "true" });
      visual.append(this.questionTypeIcon("quiz"));
      const content = element("div", "quizgeist-template-card__content");
      content.append(
        element("h3", "", { text: template.name }),
        element("p", "", {
          text: template.description || this.s("editor:templates:nodescription")
        })
      );
      const metadataItems = [];
      if (template.questionCount) {
        metadataItems.push(
          this.s("editor:templates:questioncount").replace(
            "{$a}",
            String(template.questionCount)
          )
        );
      }
      content.append(element("p", "quizgeist-template-card__meta", {
        text: metadataItems.join(" \xB7 ")
      }));
      if (template.tags.length > 0) {
        const tagList = element("div", "quizgeist-template-card__tags");
        template.tags.slice(0, 5).forEach((tag) => tagList.append(element("span", "", { text: tag })));
        content.append(tagList);
      }
      const actions = element("div", "quizgeist-template-card__actions");
      actions.append(
        button(
          this.s("editor:templates:append"),
          "quizgeist-button quizgeist-button--secondary",
          () => this.openImportDialog(template, "append")
        ),
        button(
          this.s("editor:templates:replace"),
          "quizgeist-button quizgeist-button--primary",
          () => this.openImportDialog(template, "replace")
        )
      );
      if (template.canDelete) {
        actions.append(button(
          this.s("editor:action:delete"),
          "quizgeist-button quizgeist-button--danger-quiet",
          () => void this.deleteTemplate(template)
        ));
      }
      content.append(actions);
      card.append(visual, content);
      return card;
    }
    openPublishDialog() {
      const parts = openDialog(
        this.s("editor:publish:title"),
        this.s("editor:action:close"),
        "quizgeist-publish-dialog"
      );
      const name = element("input", "quizgeist-input", {
        type: "text",
        value: this.activity.name,
        maxlength: 255,
        required: true
      });
      const description = element("textarea", "quizgeist-textarea", {
        rows: 4,
        maxlength: 4e3
      });
      const tags = element("input", "quizgeist-input", {
        type: "text",
        placeholder: this.s("editor:publish:tagsplaceholder")
      });
      parts.body.append(
        labelledField(this.s("editor:publish:name"), name),
        labelledField(this.s("editor:publish:description"), description),
        labelledField(
          this.s("editor:publish:tags"),
          tags,
          this.s("editor:publish:tagshint")
        )
      );
      const cancel = button(
        this.s("editor:action:cancel"),
        "quizgeist-button quizgeist-button--secondary",
        () => closeDialog(parts.dialog)
      );
      const publish = button(
        this.s("editor:action:publish"),
        "quizgeist-button quizgeist-button--primary"
      );
      publish.addEventListener("click", async () => {
        if (name.value.trim() === "") {
          name.setAttribute("aria-invalid", "true");
          name.focus();
          return;
        }
        setButtonBusy(publish, true, this.s("editor:publish:publishing"));
        try {
          await this.flushPendingSaves();
          await this.api.post("template_publish", {
            name: name.value.trim(),
            description: description.value.trim(),
            tags: tags.value.split(",").map((tag) => tag.trim()).filter(Boolean)
          });
          closeDialog(parts.dialog);
          this.showToast(this.s("editor:publish:success"));
          if (this.view === "templates") {
            void this.searchTemplates("");
          }
        } catch (error) {
          this.showToast(
            error instanceof Error ? error.message : this.s("editor:error:request"),
            true
          );
          setButtonBusy(publish, false, this.s("editor:action:publish"));
        }
      });
      parts.footer.append(cancel, publish);
    }
    openImportDialog(template, mode2) {
      const parts = openDialog(
        this.s("editor:import:title"),
        this.s("editor:action:close"),
        "quizgeist-import-dialog"
      );
      parts.body.append(
        element("p", "quizgeist-dialog__intro", {
          text: this.s(`editor:import:${mode2}:description`).replace("{$a}", template.name)
        })
      );
      const includeLabel = element("label", "quizgeist-check-field");
      const include = element("input", "", { type: "checkbox", checked: true });
      appendChildren(
        includeLabel,
        include,
        element("span", "", { text: this.s("editor:import:appearance") })
      );
      parts.body.append(includeLabel);
      const cancel = button(
        this.s("editor:action:cancel"),
        "quizgeist-button quizgeist-button--secondary",
        () => closeDialog(parts.dialog)
      );
      const importButton = button(
        this.s("editor:action:import"),
        "quizgeist-button quizgeist-button--primary"
      );
      importButton.addEventListener("click", async () => {
        setButtonBusy(importButton, true, this.s("editor:import:working"));
        try {
          await this.flushPendingSaves();
          await this.api.post("template_import", {
            templateid: template.id,
            mode: mode2,
            includeappearance: include.checked
          });
          closeDialog(parts.dialog);
          this.view = "editor";
          this.renderLoading();
          await this.loadBootstrap();
          this.showToast(this.s("editor:import:success"));
        } catch (error) {
          this.showToast(
            error instanceof Error ? error.message : this.s("editor:error:request"),
            true
          );
          setButtonBusy(importButton, false, this.s("editor:action:import"));
        }
      });
      parts.footer.append(cancel, importButton);
    }
    async deleteTemplate(template) {
      if (!window.confirm(
        this.s("editor:confirm:deletetemplate").replace("{$a}", template.name)
      )) {
        return;
      }
      try {
        await this.api.post("template_delete", { templateid: template.id });
        this.showToast(this.s("editor:templates:deletedsuccess"));
        await this.searchTemplates("");
      } catch (error) {
        this.showToast(
          error instanceof Error ? error.message : this.s("editor:error:request"),
          true
        );
      }
    }
    extractQuestion(data) {
      const source = this.record(data);
      return source.question || data;
    }
    extractQuestions(data) {
      const source = this.record(data);
      if (!Array.isArray(source.questions)) {
        throw new Error(this.s("editor:error:response"));
      }
      const questions = source.questions.map((question2) => normalizeQuestion(question2)).filter((question2) => question2.id > 0).sort((left, right) => left.sortorder - right.sortorder);
      if (questions.length !== source.questions.length || new Set(questions.map((question2) => question2.id)).size !== questions.length) {
        throw new Error(this.s("editor:error:response"));
      }
      return questions;
    }
    extractQuestionIdMap(data) {
      const source = this.record(data);
      if (source.questionIdMap === void 0) {
        return /* @__PURE__ */ new Map();
      }
      if (!this.isRecord(source.questionIdMap)) {
        throw new Error(this.s("editor:error:response"));
      }
      const result = /* @__PURE__ */ new Map();
      Object.entries(source.questionIdMap).forEach(([rawPreviousId, rawNextId]) => {
        const previousId = Number(rawPreviousId);
        const nextId = Number(rawNextId);
        if (!Number.isInteger(previousId) || previousId <= 0 || !Number.isInteger(nextId) || nextId <= 0) {
          throw new Error(this.s("editor:error:response"));
        }
        result.set(previousId, nextId);
      });
      return result;
    }
    mergeSavedQuestion(target, source) {
      const options = target.options;
      this.mergeValueInPlace(options, source.options);
      Object.assign(target, source, { options });
    }
    mergeValueInPlace(target, source) {
      if (Array.isArray(target) && Array.isArray(source)) {
        const available = [...target];
        const merged = source.map((sourceValue, index) => {
          let targetValue = available[index];
          if (this.isRecord(sourceValue) && (typeof sourceValue.id === "string" || typeof sourceValue.id === "number")) {
            const matched = available.find((candidate) => this.isRecord(candidate) && candidate.id === sourceValue.id);
            if (matched !== void 0) {
              targetValue = matched;
            }
          }
          return this.mergeValueInPlace(targetValue, sourceValue);
        });
        target.splice(0, target.length, ...merged);
        return target;
      }
      if (this.isRecord(target) && this.isRecord(source)) {
        Object.keys(target).forEach((key) => {
          if (!Object.prototype.hasOwnProperty.call(source, key)) {
            delete target[key];
          }
        });
        Object.entries(source).forEach(([key, value]) => {
          target[key] = this.mergeValueInPlace(target[key], value);
        });
        return target;
      }
      return source;
    }
    migrateQuestionId(question2, previousId, nextId) {
      if (nextId <= 0) {
        throw new Error(this.s("editor:error:response"));
      }
      if (previousId === nextId) {
        question2.id = nextId;
        return;
      }
      if (this.questions.some((candidate) => candidate !== question2 && candidate.id === nextId)) {
        throw new Error(this.s("editor:error:response"));
      }
      this.questionIdAliases.forEach((target, alias) => {
        if (target === previousId) {
          this.questionIdAliases.set(alias, nextId);
        }
      });
      this.questionIdAliases.set(previousId, nextId);
      const dirtyVersion = Math.max(
        this.questionDirtyVersions.get(previousId) || 0,
        this.questionDirtyVersions.get(nextId) || 0
      );
      const savedVersion = Math.max(
        this.questionSavedVersions.get(previousId) || 0,
        this.questionSavedVersions.get(nextId) || 0
      );
      this.questionDirtyVersions.delete(previousId);
      this.questionSavedVersions.delete(previousId);
      if (dirtyVersion > 0) {
        this.questionDirtyVersions.set(nextId, dirtyVersion);
      }
      if (savedVersion > 0) {
        this.questionSavedVersions.set(nextId, savedVersion);
      }
      question2.id = nextId;
      if (this.selectedQuestionId === previousId) {
        this.selectedQuestionId = nextId;
      }
      if (this.draggedQuestionId === previousId) {
        this.draggedQuestionId = nextId;
      }
      this.root.querySelectorAll(`[data-question-id="${previousId}"]`).forEach((node) => {
        node.dataset.questionId = String(nextId);
      });
    }
    resolveQuestionId(questionId) {
      let resolved = questionId;
      const visited = /* @__PURE__ */ new Set();
      while (this.questionIdAliases.has(resolved) && !visited.has(resolved)) {
        visited.add(resolved);
        resolved = this.questionIdAliases.get(resolved);
      }
      return resolved;
    }
    async reconcileCanonicalQuestions(canonical, requestedIds, questionIdMap, applyCanonicalOrder) {
      if (canonical.length !== requestedIds.length) {
        await this.loadBootstrap(true);
        return false;
      }
      questionIdMap.forEach((nextId, previousId) => {
        const resolvedPreviousId = this.resolveQuestionId(previousId);
        const localQuestion = this.questions.find((question2) => question2.id === resolvedPreviousId);
        if (!localQuestion) {
          throw new Error(this.s("editor:error:response"));
        }
        this.migrateQuestionId(localQuestion, resolvedPreviousId, nextId);
      });
      const requestedQuestions = [];
      canonical.forEach((serverQuestion, index) => {
        const requestedId = this.resolveQuestionId(requestedIds[index]);
        const localQuestion = this.questions.find((question2) => question2.id === requestedId || question2.id === serverQuestion.id);
        if (!localQuestion) {
          throw new Error(this.s("editor:error:response"));
        }
        const dirty = (this.questionDirtyVersions.get(localQuestion.id) || 0) > (this.questionSavedVersions.get(localQuestion.id) || 0);
        const previousId = localQuestion.id;
        this.migrateQuestionId(localQuestion, previousId, serverQuestion.id);
        if (!dirty) {
          this.mergeSavedQuestion(localQuestion, serverQuestion);
        }
        requestedQuestions.push(localQuestion);
      });
      if (applyCanonicalOrder) {
        this.questions.splice(0, this.questions.length, ...requestedQuestions);
      }
      this.renumberQuestionSortorders();
      this.questions.forEach((question2) => {
        this.refreshQuestionTitle(question2);
        this.refreshQuestionStatus(question2);
      });
      return true;
    }
    extractActivity(data) {
      const source = this.record(data);
      return source.activity || null;
    }
    mediaForPath(question2, path) {
      if (path === "") {
        return null;
      }
      return question2.files.find((file) => file.path === path || file.filename === path || `${file.filepath === "/" ? "" : file.filepath || ""}${file.filename}` === path || file.url === path) || null;
    }
    renderMediaElement(file, className) {
      var _a;
      const mimetype = file.mimetype || "";
      const extension = ((_a = file.filename.split(".").pop()) == null ? void 0 : _a.toLowerCase()) || "";
      if (mimetype.startsWith("audio/") || ["mp3", "ogg", "wav"].includes(extension)) {
        return element("audio", className, {
          src: file.url,
          controls: true,
          preload: "metadata",
          "aria-label": file.filename
        });
      }
      if (mimetype.startsWith("video/") || ["mp4", "webm"].includes(extension)) {
        return element("video", className, {
          src: file.url,
          controls: true,
          preload: "metadata",
          playsinline: true,
          "aria-label": file.filename
        });
      }
      return element("img", className, {
        src: file.url,
        alt: file.filename,
        loading: "lazy"
      });
    }
    record(value) {
      return this.isRecord(value) ? value : {};
    }
    isRecord(value) {
      return Boolean(value) && typeof value === "object" && !Array.isArray(value);
    }
    showToast(message, error = false) {
      if (!this.toastRegion) {
        return;
      }
      const toast = element("div", `quizgeist-toast${error ? " quizgeist-toast--error" : ""}`, {
        role: error ? "alert" : "status"
      });
      const text4 = element("span", "", { text: message });
      const close = button(
        this.s("editor:action:close"),
        "quizgeist-toast__close",
        () => toast.remove()
      );
      close.setAttribute("aria-label", this.s("editor:action:close"));
      toast.append(text4, close);
      this.toastRegion.append(toast);
      window.setTimeout(() => toast.remove(), 6e3);
    }
    announce(message) {
      if (!this.statusLiveElement) {
        return;
      }
      this.statusLiveElement.textContent = "";
      window.setTimeout(() => {
        if (this.statusLiveElement) {
          this.statusLiveElement.textContent = message;
        }
      }, 20);
    }
  };

  // src/selfstudy/config.ts
  function positiveId(value) {
    const candidate = Number(value || 0);
    return Number.isInteger(candidate) && candidate > 0 ? candidate : 0;
  }
  function normalizeSelfStudyConfig(raw, defaultContainerId) {
    var _a, _b;
    const ajaxUrl = typeof raw.ajaxUrl === "string" ? raw.ajaxUrl : "";
    const cmid = positiveId(raw.cmid);
    const sesskey = typeof raw.sesskey === "string" ? raw.sesskey : "";
    if (ajaxUrl === "" || cmid <= 0 || sesskey === "") {
      return null;
    }
    const initialView = raw.initialView === "assignments" || raw.initialView === "attempt" || raw.initialView === "live" ? raw.initialView : "overview";
    return {
      ...raw,
      ajaxUrl,
      assignmentId: positiveId(raw.assignmentId),
      attemptId: positiveId(raw.attemptId),
      brandIconUrl: typeof raw.brandIconUrl === "string" ? raw.brandIconUrl : "",
      canCreate: ((_b = (_a = raw.features) == null ? void 0 : _a.selfstudy) == null ? void 0 : _b.canCreate) === true,
      cmid,
      containerId: typeof raw.containerId === "string" && raw.containerId !== "" ? raw.containerId : defaultContainerId,
      initialView,
      overviewUrl: typeof raw.overviewUrl === "string" && raw.overviewUrl !== "" ? raw.overviewUrl : window.location.pathname,
      playerUrlBase: typeof raw.playerUrlBase === "string" ? raw.playerUrlBase : "",
      season: typeof raw.season === "string" ? raw.season : "herbst",
      sesskey,
      strings: raw.strings || {},
      theme: typeof raw.theme === "string" ? raw.theme : "hell"
    };
  }

  // src/live/api.ts
  var LiveApiError = class extends Error {
    constructor(message, code = "request_failed", status = 0, data = null) {
      super(message);
      __publicField(this, "code");
      __publicField(this, "data");
      __publicField(this, "status");
      this.name = "LiveApiError";
      this.code = code;
      this.status = status;
      this.data = data;
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
        const data = envelope.data && typeof envelope.data === "object" ? envelope.data : null;
        throw new LiveApiError(
          message,
          envelope.error || "request_failed",
          response.status,
          data
        );
      }
      return envelope.data;
    }
  };

  // src/live/answer.ts
  function liveAnswerFromPayload(raw) {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      return null;
    }
    const value = raw;
    if (Array.isArray(value.choiceIds) && value.choiceIds.length > 0 && value.choiceIds.every((id) => typeof id === "string")) {
      return { kind: "choices", choiceIds: value.choiceIds };
    }
    if (Array.isArray(value.orderIds) && value.orderIds.length > 0 && value.orderIds.every((id) => typeof id === "string")) {
      return { kind: "order", orderIds: value.orderIds };
    }
    if (typeof value.text === "string") {
      return { kind: "text", text: value.text };
    }
    if (typeof value.value === "number" && Number.isFinite(value.value)) {
      return { kind: "number", value: value.value };
    }
    if (typeof value.x === "number" && Number.isFinite(value.x) && typeof value.y === "number" && Number.isFinite(value.y)) {
      return { kind: "pin", x: value.x, y: value.y };
    }
    if (typeof value.reaction === "string") {
      return { kind: "reaction", reaction: value.reaction };
    }
    if (typeof value.groupKey === "string") {
      return { kind: "brainstormVote", groupKey: value.groupKey };
    }
    return null;
  }

  // src/selfstudy/types.ts
  var STUDY_MODES = [
    "solo",
    "practice",
    "test",
    "flashcards",
    "speaking"
  ];
  var AI_STUDY_MODES = ["speaking"];
  var STRATEGIES = [
    "sequential",
    "shuffled",
    "interleaved"
  ];
  function isStudyMode(value) {
    return typeof value === "string" && STUDY_MODES.includes(value);
  }

  // src/selfstudy/normalise.ts
  function record2(value) {
    return value && typeof value === "object" && !Array.isArray(value) ? value : {};
  }
  function records(value) {
    return Array.isArray(value) ? value.map(record2).filter((entry) => Object.keys(entry).length > 0) : [];
  }
  function finiteNumber(value, fallback = 0) {
    const candidate = typeof value === "number" ? value : typeof value === "string" && value.trim() !== "" ? Number(value) : Number.NaN;
    return Number.isFinite(candidate) ? candidate : fallback;
  }
  function reviewSummary(value) {
    const source = record2(value);
    if (source.available !== true) {
      return null;
    }
    return {
      available: true,
      deferred: source.deferred === true,
      entries: records(source.entries).map((entry) => ({
        explanation: text3(entry.explanation),
        index: integer(entry.index),
        questionText: text3(entry.questionText)
      })).filter((entry) => entry.explanation !== ""),
      policy: text3(source.policy, "immediate"),
      total: Math.max(0, integer(source.total)),
      withExplanation: Math.max(0, integer(source.withExplanation))
    };
  }
  function integer(value, fallback = 0) {
    const candidate = finiteNumber(value, fallback);
    return Number.isInteger(candidate) ? candidate : fallback;
  }
  function positiveInteger(value) {
    const candidate = integer(value);
    return candidate > 0 ? candidate : null;
  }
  function text3(value, fallback = "") {
    return typeof value === "string" ? value : fallback;
  }
  function bool(value, fallback = false) {
    return typeof value === "boolean" ? value : fallback;
  }
  function milliseconds(value) {
    const candidate = finiteNumber(value);
    if (candidate <= 0) {
      return 0;
    }
    return candidate < 1e11 ? candidate * 1e3 : candidate;
  }
  function mode(value, fallback = "practice") {
    return isStudyMode(value) ? value : fallback;
  }
  function assignmentStatus(value) {
    return value === "draft" || value === "open" || value === "closed" || value === "archived" ? value : "closed";
  }
  function attemptStatus(value) {
    return value === "completed" || value === "abandoned" ? value : "inprogress";
  }
  function assignmentSettings(value, timeDueMs) {
    const source = record2(value);
    const maxAttempts = integer(source.maxAttempts, 1);
    const maxQuestions = integer(source.maxQuestions, 0);
    return {
      allowLate: bool(source.allowLate),
      countsTowardsGrade: bool(source.countsTowardsGrade, true),
      maxAttempts: maxAttempts >= 1 && maxAttempts <= 10 ? maxAttempts : 1,
      maxQuestions: maxQuestions >= 0 && maxQuestions <= 200 ? maxQuestions : 0,
      reminderEnabled: bool(source.reminderEnabled, timeDueMs > 0),
      // An unknown strategy is read as the authored order, never as a mixed run
      // the server did not actually draw.
      selectionStrategy: source.selectionStrategy === "interleaved" ? "interleaved" : source.selectionStrategy === "shuffled" ? "shuffled" : "sequential"
    };
  }
  function gradeSummary(value) {
    const source = record2(value);
    const method = source.method === "last" || source.method === "average" ? source.method : "best";
    const rawPercent = source.percent === null || source.percent === void 0 ? null : finiteNumber(source.percent, Number.NaN);
    return {
      attemptCount: Math.max(0, integer(source.attemptCount)),
      method,
      percent: rawPercent === null || !Number.isFinite(rawPercent) ? null : Math.max(0, Math.min(100, rawPercent))
    };
  }
  function attemptReference(source) {
    const candidates = [
      source.activeAttempt,
      source.completedAttempt,
      source.attempt
    ];
    for (const candidate of candidates) {
      const parsed = record2(candidate);
      if (positiveInteger(parsed.id)) {
        return parsed;
      }
    }
    return {};
  }
  function assignmentSummary(value, completedHint = false, attemptOverride = {}) {
    var _a, _b;
    const source = record2(value);
    const attempt = Object.keys(attemptOverride).length > 0 ? attemptOverride : attemptReference(source);
    const questionCount = Math.max(0, integer(
      source.questionCount,
      integer(record2(source.progress).total)
    ));
    const completed = completedHint || bool(source.completed) || text3(attempt.status) === "completed";
    const answered = Math.max(0, Math.min(questionCount, integer(
      record2(source.progress).answered,
      integer(
        attempt.answeredCount,
        completed ? questionCount : 0
      )
    )));
    const status = assignmentStatus(source.status);
    const timeOpenMs = milliseconds((_a = source.timeOpenMs) != null ? _a : source.timeOpen);
    const timeDueMs = milliseconds((_b = source.timeDueMs) != null ? _b : source.timeDue);
    const now = Date.now();
    const available = bool(
      source.available,
      status === "open" && (timeOpenMs <= 0 || timeOpenMs <= now) && (timeDueMs <= 0 || timeDueMs >= now)
    );
    const attemptNumber = Math.max(0, integer(
      source.attemptNumber,
      integer(attempt.attemptNumber, positiveInteger(attempt.id) ? 1 : 0)
    ));
    const attemptsUsed = source.attemptsUsed === void 0 || source.attemptsUsed === null ? attemptNumber : Math.max(0, integer(source.attemptsUsed));
    const maxAttempts = Math.max(1, Math.min(
      10,
      integer(source.maxAttempts, 1)
    ));
    return {
      attemptNumber,
      attemptsUsed,
      available,
      attemptId: positiveInteger(source.attemptId) || positiveInteger(attempt.id),
      canRetry: bool(
        source.canRetry,
        completed && available && attemptsUsed < maxAttempts
      ),
      completed,
      countsTowardsGrade: bool(source.countsTowardsGrade, true),
      id: positiveInteger(source.id) || 0,
      maxAttempts,
      mode: mode(source.mode),
      name: text3(source.name),
      overdue: bool(source.overdue, timeDueMs > 0 && timeDueMs < now),
      progress: {
        answered,
        total: questionCount
      },
      selection: source.selection === "due" ? "due" : "fixed",
      // An unknown strategy is read as the authored order — the safe direction:
      // it never claims a mixed run that the server did not draw.
      selectionStrategy: source.selectionStrategy === "interleaved" ? "interleaved" : source.selectionStrategy === "shuffled" ? "shuffled" : "sequential",
      status,
      timeDueMs,
      timeOpenMs
    };
  }
  function weeklyGoal(value) {
    var _a, _b, _c, _d;
    const source = record2(value);
    return {
      configured: bool(source.configured),
      progress: Math.max(0, integer(source.progress)),
      target: Math.max(1, integer(source.target, 20)),
      ...milliseconds((_a = source.weekEndMs) != null ? _a : source.weekEnd) > 0 ? { weekEndMs: milliseconds((_b = source.weekEndMs) != null ? _b : source.weekEnd) } : {},
      ...milliseconds((_c = source.weekStartMs) != null ? _c : source.weekStart) > 0 ? { weekStartMs: milliseconds((_d = source.weekStartMs) != null ? _d : source.weekStart) } : {}
    };
  }
  function normaliseOverview(value) {
    const source = record2(value);
    return {
      canConfigureWeeklyGoal: bool(source.canConfigureWeeklyGoal),
      completedAssignments: records(source.completedAssignments).map(
        (assignment) => assignmentSummary(assignment, true)
      ),
      gradeSummary: gradeSummary(source.gradeSummary),
      openAssignments: records(source.openAssignments).map(
        (assignment) => assignmentSummary(assignment)
      ),
      serverTimeMs: milliseconds(source.serverTimeMs) || Date.now(),
      weeklyGoal: weeklyGoal(source.weeklyGoal)
    };
  }
  function question(value) {
    const source = record2(value);
    if (!positiveInteger(source.id) || text3(source.questionToken) === "" || text3(source.qtype) === "") {
      return null;
    }
    return source;
  }
  function projectedResult(value) {
    const source = record2(value);
    const presented = question(source.question);
    if (!presented) {
      return null;
    }
    return {
      answer: liveAnswerFromPayload(source.answer),
      correct: typeof source.correct === "boolean" ? source.correct : null,
      explanation: text3(source.explanation),
      maxPoints: Math.max(0, integer(source.maxPoints)),
      points: Math.max(0, integer(source.points)),
      question: presented
    };
  }
  function legacyResult(value, forcedDisclosure = false) {
    const source = record2(value);
    const presented = question(source);
    if (!presented) {
      return null;
    }
    const submission = record2(source.submission);
    const rowStatus = text3(source.attemptQuestionStatus);
    const disclosed = forcedDisclosure || rowStatus === "revealed" || rowStatus === "submitted";
    if (!disclosed) {
      return null;
    }
    return {
      answer: liveAnswerFromPayload(submission.answer),
      correct: typeof submission.isCorrect === "boolean" ? submission.isCorrect : null,
      explanation: text3(source.explanation),
      maxPoints: Math.max(0, integer(submission.maxPoints)),
      points: Math.max(0, integer(submission.points)),
      question: presented
    };
  }
  function normaliseAttemptState(value) {
    var _a, _b, _c;
    const source = record2(value);
    const rawAttempt = record2(source.attempt);
    const rawAssignment = record2(source.assignment);
    const rawNavigation = record2(source.navigation);
    const presentedQuestion = question(source.question);
    const currentIndex = Math.max(0, integer(
      source.currentIndex,
      integer(rawAttempt.currentIndex, integer(rawNavigation.currentIndex))
    ));
    const total = Math.max(0, integer(
      source.total,
      integer(rawAttempt.total, (presentedQuestion == null ? void 0 : presentedQuestion.total) || 0)
    ));
    const rawAnsweredIndices = Array.isArray(source.answeredIndices) ? source.answeredIndices : rawNavigation.answeredIndexes;
    const answeredIndices = Array.isArray(rawAnsweredIndices) ? rawAnsweredIndices.map((entry) => integer(entry, -1)).filter((entry) => entry >= 0 && entry < total) : [];
    const status = attemptStatus((_a = source.status) != null ? _a : rawAttempt.status);
    const studyMode = mode((_c = (_b = source.mode) != null ? _b : rawAttempt.mode) != null ? _c : rawAssignment.mode);
    const legacySubmission = record2(record2(source.question).submission);
    const flatResult = projectedResult(source.result);
    const currentResult = flatResult || legacyResult(
      source.question,
      status === "completed"
    );
    const flatResults = records(source.results).map(projectedResult).filter((entry) => entry !== null);
    const legacyReviews = records(source.reviews).map((entry) => legacyResult(entry, true)).filter((entry) => entry !== null);
    const rawFlashcards = record2(source.flashcards);
    const flatFlashcards = Object.keys(rawFlashcards).length > 0 && Object.prototype.hasOwnProperty.call(rawFlashcards, "known");
    const flashcardRound = flatFlashcards ? Math.max(0, integer(rawFlashcards.round)) : Math.max(1, integer(rawFlashcards.round, 1));
    const flashcardRemaining = Math.max(0, integer(rawFlashcards.remaining));
    const attemptId = positiveInteger(source.attemptId) || positiveInteger(rawAttempt.id) || 0;
    const assignment = assignmentSummary(
      rawAssignment,
      status === "completed",
      {
        answeredCount: integer(rawAttempt.answeredCount, answeredIndices.length),
        id: attemptId,
        status
      }
    );
    assignment.progress = {
      answered: Math.max(
        assignment.progress.answered,
        Math.min(total, integer(rawAttempt.answeredCount, answeredIndices.length))
      ),
      total
    };
    return {
      answeredIndices,
      assignment,
      attemptId,
      canAnswer: bool(
        source.canAnswer,
        status === "inprogress" && assignment.available
      ),
      canFinish: bool(source.canFinish, status === "inprogress"),
      currentIndex,
      flashcards: studyMode === "flashcards" ? {
        known: Math.max(0, integer(
          rawFlashcards.known,
          integer(rawFlashcards.knownCount)
        )),
        repeat: flatFlashcards ? Math.max(0, integer(rawFlashcards.repeat)) : flashcardRound > 1 ? flashcardRemaining : Math.max(0, integer(rawFlashcards.repeatPending)),
        revealed: bool(
          rawFlashcards.revealed,
          text3(record2(source.question).attemptQuestionStatus) === "revealed"
        ),
        round: flashcardRound,
        total: Math.max(0, integer(rawFlashcards.total, total))
      } : null,
      gradeSummary: gradeSummary(source.gradeSummary),
      maxScore: Math.max(0, integer(source.maxScore, integer(rawAttempt.maxScore))),
      mode: studyMode,
      ownAnswer: liveAnswerFromPayload(
        Object.prototype.hasOwnProperty.call(source, "ownAnswer") ? source.ownAnswer : legacySubmission.answer
      ),
      question: presentedQuestion,
      result: flatResult ? flatResult : status === "completed" ? null : currentResult,
      results: flatResults.length > 0 ? flatResults : legacyReviews.length > 0 ? legacyReviews : status === "completed" && currentResult ? [currentResult] : [],
      reviewSummary: reviewSummary(source.reviewSummary),
      score: Math.max(0, integer(source.score, integer(rawAttempt.score))),
      serverTimeMs: milliseconds(source.serverTimeMs) || Date.now(),
      stateVersion: Math.max(0, integer(
        source.stateVersion,
        integer(rawAttempt.stateVersion)
      )),
      status,
      total
    };
  }
  function teacherAssignment(value) {
    var _a;
    const source = record2(value);
    const timeDueMs = milliseconds((_a = source.timeDueMs) != null ? _a : source.timeDue);
    const settings = assignmentSettings(source.settings, timeDueMs);
    return {
      ...assignmentSummary(source),
      countsTowardsGrade: settings.countsTowardsGrade,
      maxAttempts: settings.maxAttempts,
      multistageQuestionCount: Math.max(
        0,
        integer(source.multistageQuestionCount)
      ),
      participantCount: Math.max(0, integer(
        source.participantCount,
        integer(source.attemptCount)
      )),
      settings,
      timeModified: Math.max(0, integer(
        source.timeModified,
        milliseconds(source.timeModifiedMs) > 0 ? Math.floor(milliseconds(source.timeModifiedMs) / 1e3) : 0
      ))
    };
  }
  function normaliseTeacherAssignmentList(value) {
    const source = record2(value);
    return {
      assignments: records(source.assignments).map(teacherAssignment),
      readyMultistageQuestionCount: Math.max(
        0,
        integer(source.readyMultistageQuestionCount)
      ),
      readyQuestionCount: Math.max(0, integer(source.readyQuestionCount)),
      serverTimeMs: milliseconds(source.serverTimeMs) || Date.now()
    };
  }

  // src/selfstudy/api.ts
  var SelfStudyApi = class {
    constructor(config) {
      this.config = config;
      __publicField(this, "api");
      this.api = new LiveApi(config);
    }
    /**
     * Pass one action straight through (P11/C4).
     *
     * The clip and speaking endpoints have no normalisation of their own: their
     * payloads are already the shape the caller wants, and inventing a second
     * one would only add a place for the two to drift apart.
     *
     * @param action Action name.
     * @param payload Request payload.
     * @param signal Optional abort signal.
     */
    async post(action, payload = {}, signal) {
      return this.api.post(action, payload, signal);
    }
    async overview(signal) {
      return normaliseOverview(
        await this.api.post("selfstudy_overview", {}, signal)
      );
    }
    /**
     * Read the learner's own due repetitions (F3).
     *
     * The panel owns the shape of its DTO, so the raw payload is handed on.
     */
    async dueCard(signal) {
      return this.api.post("schedule_due", {}, signal);
    }
    /**
     * Read the question workshop (F7).
     *
     * The panel owns the shape of its DTO, so the raw payload is handed on.
     */
    async workshop(signal) {
      return this.api.post("workshop_list", {}, signal);
    }
    /**
     * Submit one learner-written question.
     *
     * The payload carries content only; the lifecycle belongs to the server.
     */
    async workshopSubmit(payload) {
      return this.api.post("workshop_submit", payload);
    }
    async workshopRate(workshopId, rating) {
      return this.api.post("workshop_rate", { workshopId, ...rating });
    }
    /**
     * Decide one submission (teacher surface, same addon action).
     */
    async workshopCurate(workshopId, state, note) {
      return this.api.post("workshop_curate", { note, state, workshopId });
    }
    async start(assignmentId, newAttempt = false) {
      const result = await this.api.post(
        "selfstudy_start",
        { assignmentId, newAttempt }
      );
      return { state: normaliseAttemptState(result.state) };
    }
    async state(attemptId, signal) {
      const result = await this.api.post(
        "selfstudy_state",
        { attemptId },
        signal
      );
      return { state: normaliseAttemptState(result.state) };
    }
    async navigate(attemptId, index, stateVersion) {
      const result = await this.api.post("selfstudy_navigate", {
        attemptId,
        index,
        stateVersion
      });
      return { state: normaliseAttemptState(result.state) };
    }
    async submit(state, answer) {
      var _a, _b;
      const result = await this.api.post("selfstudy_submit", {
        answer,
        attemptId: state.attemptId,
        questionId: ((_a = state.question) == null ? void 0 : _a.id) || 0,
        questionToken: ((_b = state.question) == null ? void 0 : _b.questionToken) || "",
        stateVersion: state.stateVersion
      });
      return { state: normaliseAttemptState(result.state) };
    }
    async finish(state) {
      const result = await this.api.post("selfstudy_finish", {
        attemptId: state.attemptId,
        stateVersion: state.stateVersion
      });
      return { state: normaliseAttemptState(result.state) };
    }
    async revealFlashcard(state) {
      var _a, _b;
      const result = await this.api.post("flashcard_reveal", {
        attemptId: state.attemptId,
        questionId: ((_a = state.question) == null ? void 0 : _a.id) || 0,
        questionToken: ((_b = state.question) == null ? void 0 : _b.questionToken) || "",
        stateVersion: state.stateVersion
      });
      return { state: normaliseAttemptState(result.state) };
    }
    async markFlashcard(state, known) {
      var _a, _b;
      const result = await this.api.post("flashcard_mark", {
        attemptId: state.attemptId,
        known,
        questionId: ((_a = state.question) == null ? void 0 : _a.id) || 0,
        questionToken: ((_b = state.question) == null ? void 0 : _b.questionToken) || "",
        stateVersion: state.stateVersion
      });
      return { state: normaliseAttemptState(result.state) };
    }
    async saveWeeklyGoal(target) {
      return normaliseOverview(
        await this.api.post("weekly_goal_save", { target })
      );
    }
    async assignmentList(signal) {
      return normaliseTeacherAssignmentList(
        await this.api.post("assignment_list", {}, signal)
      );
    }
    async assignmentCreate(assignment) {
      const action = assignment.selection === "due" ? "review_assignment_create" : "assignment_create";
      return normaliseTeacherAssignmentList(
        await this.api.post(action, { assignment })
      );
    }
    async assignmentUpdate(assignment) {
      return normaliseTeacherAssignmentList(
        await this.api.post("assignment_update", { assignment })
      );
    }
    async assignmentClose(assignmentId, timeModified) {
      return normaliseTeacherAssignmentList(
        await this.api.post("assignment_close", {
          assignmentId,
          timeModified
        })
      );
    }
    /**
     * Optimistic-state conflicts include the authoritative self-study state.
     * Applying it lets the learner continue immediately without retry loops.
     */
    conflictState(error) {
      if (!(error instanceof LiveApiError) || error.status !== 409) {
        return null;
      }
      const data = error.data;
      if (!data || typeof data !== "object" || Array.isArray(data)) {
        return null;
      }
      const rawState = data.state;
      if (!rawState || typeof rawState !== "object" || Array.isArray(rawState)) {
        return null;
      }
      const state = normaliseAttemptState(rawState);
      return state.attemptId > 0 ? state : null;
    }
    errorMessage(error) {
      if (error instanceof LiveApiError && error.message !== "") {
        return error.message;
      }
      return this.config.strings["selfstudy:error:request"] || this.config.strings["live:error:request"] || "Die Anfrage konnte nicht verarbeitet werden.";
    }
  };

  // src/selfstudy/ui.ts
  function studyText(config, key, fallback, values = {}) {
    let text4 = config.strings[key] || fallback;
    Object.entries(values).forEach(([name, value]) => {
      text4 = text4.split(`{$a->${name}}`).join(String(value));
      text4 = text4.split(`{$${name}}`).join(String(value));
    });
    if ("a" in values) {
      text4 = text4.split("{$a}").join(String(values.a));
    }
    return text4;
  }
  function studyButton(label, variant = "primary") {
    return liveButton(
      label,
      `quizgeist-study-button quizgeist-study-button--${variant}`
    );
  }
  function icon(name) {
    const fontAwesome = {
      calendar: "fa-calendar",
      check: "fa-check",
      clock: "fa-clock-o",
      goal: "fa-bullseye",
      repeat: "fa-repeat"
    };
    return liveElement(
      "span",
      `quizgeist-study-icon icon fa ${fontAwesome[name]}`,
      { "aria-hidden": "true" }
    );
  }
  function modeLabel(config, mode2) {
    const fallbacks = {
      flashcards: "Lernkarten",
      practice: "\xDCbung",
      solo: "Solo-Tempo",
      test: "\xDCbungstest",
      speaking: "Sprech-Trainer"
    };
    return studyText(config, `selfstudy:mode:${mode2}`, fallbacks[mode2]);
  }
  function formatDate(timestampMs) {
    if (!Number.isFinite(timestampMs) || timestampMs <= 0) {
      return "";
    }
    return new Intl.DateTimeFormat(document.documentElement.lang || "de-DE", {
      dateStyle: "medium",
      timeStyle: "short"
    }).format(new Date(timestampMs));
  }
  function deadlineBadge(config, timeDueMs, serverTimeMs) {
    const badge = liveElement("span", "quizgeist-study-deadline");
    badge.append(icon(timeDueMs > 0 ? "clock" : "calendar"));
    if (timeDueMs <= 0) {
      badge.classList.add("quizgeist-study-deadline--none");
      badge.append(document.createTextNode(
        studyText(config, "selfstudy:deadline:none", "Ohne Frist")
      ));
      return badge;
    }
    const remaining = timeDueMs - serverTimeMs;
    let label;
    if (remaining < 0) {
      badge.classList.add("quizgeist-study-deadline--urgent");
      label = studyText(config, "selfstudy:deadline:overdue", "\xDCberf\xE4llig seit {$date}", {
        date: formatDate(timeDueMs)
      });
    } else if (remaining < 24 * 60 * 60 * 1e3) {
      badge.classList.add("quizgeist-study-deadline--urgent");
      label = studyText(config, "selfstudy:deadline:today", "F\xE4llig bis {$date}", {
        date: formatDate(timeDueMs)
      });
    } else if (remaining <= 72 * 60 * 60 * 1e3) {
      badge.classList.add("quizgeist-study-deadline--soon");
      label = studyText(config, "selfstudy:deadline:soon", "F\xE4llig am {$date}", {
        date: formatDate(timeDueMs)
      });
    } else {
      badge.classList.add("quizgeist-study-deadline--comfortable");
      label = studyText(config, "selfstudy:deadline:comfortable", "F\xE4llig am {$date}", {
        date: formatDate(timeDueMs)
      });
    }
    badge.append(document.createTextNode(label));
    return badge;
  }
  function loadingCard(config, labelKey) {
    const card = liveElement("div", "quizgeist-study-state", {
      "aria-live": "polite",
      role: "status"
    });
    card.append(
      liveElement("span", "quizgeist-study-spinner", { "aria-hidden": "true" }),
      liveElement("p", "", {
        text: studyText(config, labelKey, "Inhalte werden geladen \u2026")
      })
    );
    return card;
  }
  function errorCard(config, message, retry) {
    const card = liveElement("section", "quizgeist-study-state quizgeist-study-state--error", {
      role: "alert"
    });
    const button2 = studyButton(
      studyText(config, "selfstudy:action:retry", "Erneut versuchen"),
      "secondary"
    );
    button2.addEventListener("click", retry);
    card.append(
      liveElement("h2", "", {
        text: studyText(config, "selfstudy:error:title", "Das hat noch nicht geklappt")
      }),
      liveElement("p", "", { text: message }),
      button2
    );
    return card;
  }

  // src/selfstudy/teacher-app.ts
  function localDateTime(timestampMs) {
    if (!Number.isFinite(timestampMs) || timestampMs <= 0) {
      return "";
    }
    const date = new Date(timestampMs);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 6e4);
    return local.toISOString().slice(0, 16);
  }
  function timestampSeconds(input, enabled) {
    if (!enabled || input.value === "") {
      return 0;
    }
    const value = new Date(input.value).getTime();
    return Number.isFinite(value) ? Math.floor(value / 1e3) : 0;
  }
  var AssignmentTeacherApp = class {
    constructor(root, config) {
      this.root = root;
      this.config = config;
      __publicField(this, "api");
      __publicField(this, "busy", false);
      __publicField(this, "editing", null);
      __publicField(this, "formOpen", false);
      __publicField(this, "liveRegion");
      __publicField(this, "list", null);
      __publicField(this, "stage");
      this.api = new SelfStudyApi(config);
      this.liveRegion = liveElement("div", "quizgeist-live-visually-hidden", {
        "aria-atomic": "true",
        "aria-live": "polite",
        role: "status"
      });
      this.stage = liveElement("div", "quizgeist-assignment-stage");
    }
    async init() {
      this.root.classList.add("quizgeist-assignment-root");
      this.root.dataset.quizgeistRoot = "selfstudy-teacher";
      this.root.dataset.quizgeistTheme = this.config.theme || "hell";
      this.root.dataset.quizgeistSeason = this.config.season || "herbst";
      this.root.replaceChildren(this.liveRegion, this.stage);
      await this.load();
    }
    text(key, fallback, values = {}) {
      return studyText(this.config, key, fallback, values);
    }
    announce(message) {
      this.liveRegion.textContent = "";
      window.requestAnimationFrame(() => {
        this.liveRegion.textContent = message;
      });
    }
    async load() {
      this.stage.replaceChildren(loadingCard(
        this.config,
        "selfstudy:teacher:loading"
      ));
      try {
        this.list = await this.api.assignmentList();
        this.render();
      } catch (error) {
        this.stage.replaceChildren(errorCard(
          this.config,
          this.api.errorMessage(error),
          () => void this.load()
        ));
      }
    }
    render() {
      const data = this.list;
      if (!data) {
        return;
      }
      const main = liveElement("main", "quizgeist-assignment-manager");
      const header = liveElement("header", "quizgeist-assignment-manager__header");
      const copy = liveElement("div", "");
      const heading = liveElement("h2", "quizgeist-study-title", {
        text: this.text("selfstudy:teacher:title", "Zuweisungen")
      });
      heading.dataset.studyHeading = "";
      heading.tabIndex = -1;
      copy.append(
        heading,
        liveElement("p", "quizgeist-study-copy", {
          text: this.text(
            "selfstudy:teacher:description",
            "Stellen Sie selbstst\xE4ndige Lernwege mit oder ohne Frist bereit."
          )
        })
      );
      const create = studyButton(
        this.text("selfstudy:teacher:create", "Neue Zuweisung"),
        "primary"
      );
      create.disabled = !this.config.canCreate || data.readyQuestionCount <= 0;
      create.addEventListener("click", () => {
        this.editing = null;
        this.formOpen = true;
        this.render();
        window.setTimeout(() => {
          var _a;
          (_a = this.stage.querySelector('[name="assignment-name"]')) == null ? void 0 : _a.focus();
        }, 0);
      });
      header.append(copy, create);
      main.append(header);
      if (!this.config.canCreate) {
        main.append(liveElement("p", "quizgeist-assignment-readiness", {
          role: "status",
          text: this.text(
            "selfstudy:teacher:locked",
            "Vorhandene Zuweisungen bleiben bearbeitbar. F\xFCr eine neue Zuweisung wird eine aktive Selfstudy-Lizenz ben\xF6tigt."
          )
        }));
      } else if (data.readyQuestionCount <= 0) {
        main.append(liveElement("p", "quizgeist-assignment-readiness", {
          role: "status",
          text: this.text(
            "selfstudy:teacher:noquestions",
            "Es gibt noch keine spielbereite Frage. Stellen Sie zuerst mindestens eine Frage bereit, bevor Sie eine Zuweisung anlegen."
          )
        }));
      } else {
        main.append(liveElement("p", "quizgeist-assignment-readiness", {
          text: this.text(
            "selfstudy:teacher:questioncount",
            "{$count} spielbereite Fragen werden beim \xD6ffnen versionsfest zugeordnet.",
            { count: data.readyQuestionCount }
          )
        }));
      }
      if (this.formOpen && (this.editing !== null || this.config.canCreate)) {
        main.append(this.renderForm(this.editing));
      }
      main.append(this.renderAssignmentList(data));
      this.stage.replaceChildren(main);
    }
    renderAssignmentList(data) {
      const section = liveElement("section", "quizgeist-assignment-list", {
        "aria-labelledby": "quizgeist-assignment-list-heading"
      });
      section.append(liveElement("h3", "quizgeist-study-section__title", {
        id: "quizgeist-assignment-list-heading",
        text: this.text("selfstudy:teacher:list", "Vorhandene Zuweisungen")
      }));
      if (data.assignments.length === 0) {
        section.append(liveElement("div", "quizgeist-study-empty", {
          text: this.text(
            "selfstudy:teacher:empty",
            "Noch keine Zuweisung angelegt. Mit der ersten k\xF6nnen Sie sofort einen Lernweg \xF6ffnen."
          )
        }));
        return section;
      }
      const list = liveElement("div", "quizgeist-assignment-list__items");
      data.assignments.forEach((assignment) => {
        list.append(this.renderAssignment(assignment, data.serverTimeMs));
      });
      section.append(list);
      return section;
    }
    renderAssignment(assignment, serverTimeMs) {
      const card = liveElement("article", "quizgeist-assignment-row");
      const body = liveElement("div", "quizgeist-assignment-row__body");
      const meta = liveElement("div", "quizgeist-assignment-row__meta");
      meta.append(
        liveElement("span", "quizgeist-study-mode", {
          text: modeLabel(this.config, assignment.mode)
        }),
        liveElement("span", `quizgeist-assignment-status quizgeist-assignment-status--${assignment.status}`, {
          text: this.text(
            `selfstudy:status:${assignment.status}`,
            {
              archived: "Archiviert",
              closed: "Geschlossen",
              draft: "Entwurf",
              open: "Offen"
            }[assignment.status]
          )
        }),
        deadlineBadge(this.config, assignment.timeDueMs, serverTimeMs)
      );
      body.append(
        meta,
        liveElement("h4", "quizgeist-assignment-row__title", { text: assignment.name }),
        liveElement("p", "quizgeist-assignment-row__participants", {
          text: this.text(
            "selfstudy:teacher:participants",
            "{$count} gestartete Versuche",
            { count: assignment.participantCount }
          )
        }),
        liveElement("p", "quizgeist-assignment-row__participants", {
          text: this.text(
            "selfstudy:teacher:attemptlimit",
            "Bis zu {$count} Versuche je Lernendem",
            { count: assignment.settings.maxAttempts }
          )
        }),
        liveElement("p", "quizgeist-assignment-row__participants", {
          text: assignment.settings.countsTowardsGrade && (assignment.mode === "solo" || assignment.mode === "test") ? this.text(
            "selfstudy:teacher:graded",
            "Z\xE4hlt zum Bewertungsstand"
          ) : this.text(
            "selfstudy:teacher:ungraded",
            "Ohne Notenwertung"
          )
        })
      );
      if (assignment.multistageQuestionCount > 0) {
        body.append(liveElement("p", "quizgeist-assignment-readiness", {
          text: this.text(
            "selfstudy:teacher:multistagewarning",
            "{$count} mehrstufige Fragen werden im Selbstlernen jeweils auf ihre erste Eingabestufe vereinfacht.",
            { count: assignment.multistageQuestionCount }
          )
        }));
      }
      if (assignment.timeOpenMs > serverTimeMs) {
        body.append(liveElement("p", "quizgeist-assignment-row__availability", {
          text: this.text(
            "selfstudy:teacher:opens",
            "\xD6ffnet am {$date}",
            { date: formatDate(assignment.timeOpenMs) }
          )
        }));
      }
      const actions = liveElement("div", "quizgeist-assignment-row__actions");
      if (assignment.status === "draft" || assignment.status === "open") {
        const edit = studyButton(
          this.text("selfstudy:teacher:edit", "Bearbeiten"),
          "secondary"
        );
        edit.addEventListener("click", () => {
          this.editing = assignment;
          this.formOpen = true;
          this.render();
          window.setTimeout(() => {
            var _a;
            (_a = this.stage.querySelector('[name="assignment-name"]')) == null ? void 0 : _a.focus();
          }, 0);
        });
        actions.append(edit);
      }
      if (assignment.status === "open") {
        const close = studyButton(
          this.text("selfstudy:teacher:close", "Schlie\xDFen"),
          "danger"
        );
        close.addEventListener("click", () => void this.closeAssignment(
          assignment,
          close
        ));
        actions.append(close);
      }
      card.append(body, actions);
      return card;
    }
    renderForm(assignment) {
      var _a, _b, _c, _d;
      const form = liveElement("form", "quizgeist-assignment-form");
      form.setAttribute("aria-labelledby", "quizgeist-assignment-form-heading");
      const title = liveElement("h3", "quizgeist-assignment-form__title", {
        id: "quizgeist-assignment-form-heading",
        text: assignment ? this.text("selfstudy:teacher:editheading", "Zuweisung bearbeiten") : this.text("selfstudy:teacher:createheading", "Neue Zuweisung")
      });
      const nameLabel = liveElement("label", "quizgeist-study-field");
      const name = liveElement("input", "quizgeist-study-input", {
        maxlength: 255,
        name: "assignment-name",
        required: true,
        type: "text",
        value: (assignment == null ? void 0 : assignment.name) || ""
      });
      nameLabel.append(
        liveElement("span", "quizgeist-study-field__label", {
          text: this.text("selfstudy:teacher:name", "Name")
        }),
        name
      );
      const modes = liveElement("fieldset", "quizgeist-study-fieldset");
      modes.append(liveElement("legend", "quizgeist-study-field__label", {
        text: this.text("selfstudy:teacher:mode", "Lernmodus")
      }));
      const modeGrid = liveElement("div", "quizgeist-assignment-mode-grid");
      const modeInputs = [];
      const aiInstalled = ((_b = (_a = this.config.features) == null ? void 0 : _a.ai) == null ? void 0 : _b.installed) === true;
      const offeredModes = STUDY_MODES.filter(
        (mode2) => aiInstalled || !AI_STUDY_MODES.includes(mode2)
      );
      offeredModes.forEach((mode2) => {
        const label = liveElement("label", "quizgeist-assignment-mode-option");
        const radio = liveElement("input", "", {
          checked: ((assignment == null ? void 0 : assignment.mode) || "practice") === mode2,
          name: "assignment-mode",
          type: "radio",
          value: mode2
        });
        modeInputs.push(radio);
        label.append(
          radio,
          liveElement("strong", "", { text: modeLabel(this.config, mode2) }),
          liveElement("span", "", {
            text: this.modeDescription(mode2)
          })
        );
        modeGrid.append(label);
      });
      modes.append(modeGrid);
      const multistageQuestionCount = assignment ? assignment.multistageQuestionCount : ((_c = this.list) == null ? void 0 : _c.readyMultistageQuestionCount) || 0;
      const multistageWarning = multistageQuestionCount > 0 ? liveElement("p", "quizgeist-assignment-readiness", {
        role: "note",
        text: this.text(
          "selfstudy:teacher:multistagewarning",
          "{$count} mehrstufige Fragen werden im Selbstlernen jeweils auf ihre erste Eingabestufe vereinfacht.",
          { count: multistageQuestionCount }
        )
      }) : null;
      const timing = liveElement("fieldset", "quizgeist-study-fieldset");
      timing.append(liveElement("legend", "quizgeist-study-field__label", {
        text: this.text("selfstudy:teacher:timing", "Zeitraum")
      }));
      const hasOpen = Boolean(assignment && assignment.timeOpenMs > 0);
      const openToggle = liveElement("input", "", {
        checked: hasOpen,
        id: "quizgeist-assignment-has-open",
        type: "checkbox"
      });
      const openInput = liveElement("input", "quizgeist-study-input", {
        disabled: !hasOpen,
        id: "quizgeist-assignment-open",
        type: "datetime-local",
        value: localDateTime((assignment == null ? void 0 : assignment.timeOpenMs) || 0)
      });
      openToggle.addEventListener("change", () => {
        openInput.disabled = !openToggle.checked;
        if (openToggle.checked && openInput.value === "") {
          openInput.value = localDateTime(Date.now() + 60 * 60 * 1e3);
        }
      });
      const dueToggle = liveElement("input", "", {
        checked: Boolean(assignment && assignment.timeDueMs > 0),
        id: "quizgeist-assignment-has-due",
        type: "checkbox"
      });
      const dueInput = liveElement("input", "quizgeist-study-input", {
        disabled: !dueToggle.checked,
        id: "quizgeist-assignment-due",
        type: "datetime-local",
        value: localDateTime((assignment == null ? void 0 : assignment.timeDueMs) || 0)
      });
      dueToggle.addEventListener("change", () => {
        dueInput.disabled = !dueToggle.checked;
        if (dueToggle.checked && dueInput.value === "") {
          dueInput.value = localDateTime(Date.now() + 7 * 24 * 60 * 60 * 1e3);
        }
      });
      timing.append(
        this.toggleDateField(
          openToggle,
          openInput,
          this.text("selfstudy:teacher:openlater", "Erst sp\xE4ter \xF6ffnen"),
          this.text("selfstudy:teacher:openat", "\xD6ffnen am")
        ),
        this.toggleDateField(
          dueToggle,
          dueInput,
          this.text("selfstudy:teacher:deadline", "Frist setzen"),
          this.text("selfstudy:teacher:dueat", "F\xE4llig am")
        )
      );
      const assignmentSettings2 = (assignment == null ? void 0 : assignment.settings) || {
        allowLate: false,
        countsTowardsGrade: true,
        maxAttempts: 3,
        maxQuestions: 0,
        reminderEnabled: true,
        selectionStrategy: "sequential"
      };
      const settings = liveElement("fieldset", "quizgeist-study-fieldset");
      settings.append(liveElement("legend", "quizgeist-study-field__label", {
        text: this.text("selfstudy:teacher:settings", "Versuche und Bewertung")
      }));
      const maxAttempts = liveElement(
        "input",
        "quizgeist-study-input quizgeist-study-input--number",
        {
          "aria-describedby": "quizgeist-assignment-max-attempts-help",
          id: "quizgeist-assignment-max-attempts",
          inputmode: "numeric",
          max: 10,
          min: 1,
          required: true,
          type: "number",
          value: assignmentSettings2.maxAttempts
        }
      );
      const maxAttemptsField = liveElement("label", "quizgeist-study-field");
      maxAttemptsField.append(
        liveElement("span", "quizgeist-study-field__label", {
          text: this.text(
            "selfstudy:teacher:maxattempts",
            "Maximale Versuche je Lernendem"
          )
        }),
        maxAttempts,
        liveElement("span", "quizgeist-study-copy", {
          id: "quizgeist-assignment-max-attempts-help",
          text: this.text(
            "selfstudy:teacher:maxattemptshelp",
            "Ein weiterer Versuch wird nur \xFCber \u201EErneut versuchen\u201C gestartet."
          )
        })
      );
      const countsTowardsGrade = liveElement("input", "", {
        checked: assignmentSettings2.countsTowardsGrade,
        type: "checkbox"
      });
      const countsTowardsGradeField = liveElement(
        "label",
        "quizgeist-study-toggle"
      );
      countsTowardsGradeField.append(
        countsTowardsGrade,
        liveElement("span", "", {
          text: this.text(
            "selfstudy:teacher:countstowardsgrade",
            "Solo- und Testversuche in die Bewertung einbeziehen"
          )
        })
      );
      const allowLate = liveElement("input", "", {
        checked: assignmentSettings2.allowLate,
        type: "checkbox"
      });
      const allowLateField = liveElement("label", "quizgeist-study-toggle");
      allowLateField.append(
        allowLate,
        liveElement("span", "", {
          text: this.text(
            "selfstudy:teacher:allowlate",
            "Antworten nach der Frist erlauben"
          )
        })
      );
      const reminderEnabled = liveElement("input", "", {
        checked: assignmentSettings2.reminderEnabled,
        type: "checkbox"
      });
      const reminderField = liveElement("label", "quizgeist-study-toggle");
      reminderField.append(
        reminderEnabled,
        liveElement("span", "", {
          text: this.text(
            "selfstudy:teacher:reminderenabled",
            "24 Stunden vor der Frist erinnern"
          )
        })
      );
      const isreview = ((_d = assignment == null ? void 0 : assignment.selection) != null ? _d : "fixed") === "due";
      const reviewToggle = liveElement("input", "", {
        checked: isreview,
        disabled: Boolean(assignment),
        type: "checkbox"
      });
      const reviewField = liveElement("label", "quizgeist-study-toggle");
      reviewField.append(
        reviewToggle,
        liveElement("span", "", {
          text: this.text(
            "selfstudy:teacher:review",
            "Wiederholung: f\xE4llige Fragen statt fester Liste"
          )
        })
      );
      const strategySelect = liveElement(
        "select",
        "quizgeist-study-select",
        { id: "quizgeist-assignment-strategy" }
      );
      [
        ["sequential", this.text(
          "selfstudy:teacher:strategy:sequential",
          "In der vorgegebenen Reihenfolge"
        )],
        ["shuffled", this.text(
          "selfstudy:teacher:strategy:shuffled",
          "Zuf\xE4llig gemischt"
        )],
        ["interleaved", this.text(
          "selfstudy:teacher:strategy:interleaved",
          "Themen bewusst durchmischt (Interleaving)"
        )]
      ].forEach(([value, label]) => {
        strategySelect.append(liveElement("option", "", {
          selected: assignmentSettings2.selectionStrategy === value,
          text: label,
          value
        }));
      });
      const strategyField = liveElement("label", "quizgeist-study-field");
      strategyField.append(
        liveElement("span", "quizgeist-study-field__label", {
          text: this.text(
            "selfstudy:teacher:strategy",
            "Reihenfolge der Fragen"
          )
        }),
        strategySelect,
        liveElement("span", "quizgeist-study-copy", {
          text: this.text(
            "selfstudy:teacher:strategyhelp",
            "Gemischtes \xDCben f\xFChlt sich schwerer an und bleibt l\xE4nger im Ged\xE4chtnis."
          )
        })
      );
      settings.append(
        maxAttemptsField,
        reviewField,
        strategyField,
        countsTowardsGradeField,
        allowLateField,
        reminderField
      );
      const updateSettingsAvailability = () => {
        var _a2;
        const selectedMode = (_a2 = modeInputs.find((input) => input.checked)) == null ? void 0 : _a2.value;
        countsTowardsGrade.disabled = selectedMode !== "solo" && selectedMode !== "test";
        allowLate.disabled = !dueToggle.checked;
        reminderEnabled.disabled = !dueToggle.checked;
      };
      modeInputs.forEach((input) => {
        input.addEventListener("change", updateSettingsAvailability);
      });
      dueToggle.addEventListener("change", updateSettingsAvailability);
      updateSettingsAvailability();
      const status = liveElement("label", "quizgeist-study-field");
      const statusSelect = liveElement("select", "quizgeist-study-input", {
        name: "assignment-status"
      });
      statusSelect.append(
        liveElement("option", "", {
          selected: (assignment == null ? void 0 : assignment.status) === "draft",
          text: this.text("selfstudy:status:draft", "Entwurf"),
          value: "draft"
        }),
        liveElement("option", "", {
          selected: (assignment == null ? void 0 : assignment.status) !== "draft",
          text: this.text("selfstudy:status:open", "Offen"),
          value: "open"
        })
      );
      status.append(
        liveElement("span", "quizgeist-study-field__label", {
          text: this.text("selfstudy:teacher:status", "Status")
        }),
        statusSelect
      );
      const error = liveElement("p", "quizgeist-study-form-error", {
        "aria-live": "polite",
        role: "alert"
      });
      const actions = liveElement("div", "quizgeist-assignment-form__actions");
      const cancel = studyButton(
        this.text("selfstudy:teacher:cancel", "Abbrechen"),
        "quiet"
      );
      cancel.addEventListener("click", () => {
        this.editing = null;
        this.formOpen = false;
        this.render();
      });
      const save = studyButton(
        this.text("selfstudy:teacher:save", "Zuweisung speichern"),
        "primary"
      );
      save.type = "submit";
      actions.append(cancel, save);
      form.append(title, nameLabel, modes);
      if (multistageWarning) {
        form.append(multistageWarning);
      }
      form.append(timing, settings, status, error, actions);
      form.addEventListener("submit", (event) => {
        var _a2;
        event.preventDefault();
        const selectedMode = (_a2 = form.querySelector(
          '[name="assignment-mode"]:checked'
        )) == null ? void 0 : _a2.value;
        const mode2 = STUDY_MODES.includes(selectedMode) ? selectedMode : null;
        const timeOpen = timestampSeconds(openInput, openToggle.checked);
        const timeDue = timestampSeconds(dueInput, dueToggle.checked);
        const maxAttemptCount = Number(maxAttempts.value);
        if (name.value.trim() === "" || !mode2) {
          error.textContent = this.text(
            "selfstudy:teacher:invalid",
            "Bitte f\xFCllen Sie alle Pflichtfelder aus."
          );
          name.focus();
          return;
        }
        if (openToggle.checked && timeOpen <= 0) {
          openInput.focus();
          openInput.reportValidity();
          return;
        }
        if (dueToggle.checked && timeDue <= 0) {
          dueInput.focus();
          dueInput.reportValidity();
          return;
        }
        if (timeDue > 0 && timeOpen > 0 && timeDue <= timeOpen) {
          error.textContent = this.text(
            "selfstudy:teacher:invaliddeadline",
            "Die Frist muss nach dem \xD6ffnungszeitpunkt liegen."
          );
          dueInput.focus();
          return;
        }
        if (!Number.isInteger(maxAttemptCount) || maxAttemptCount < 1 || maxAttemptCount > 10) {
          maxAttempts.focus();
          maxAttempts.reportValidity();
          return;
        }
        const strategy = STRATEGIES.includes(
          strategySelect.value
        ) ? strategySelect.value : "sequential";
        const input = {
          ...assignment ? {
            id: assignment.id,
            timeModified: assignment.timeModified
          } : {},
          mode: mode2,
          name: name.value.trim(),
          ...assignment ? {} : { selection: reviewToggle.checked ? "due" : "fixed" },
          settings: {
            allowLate: dueToggle.checked && allowLate.checked,
            countsTowardsGrade: (mode2 === "solo" || mode2 === "test") && countsTowardsGrade.checked,
            maxAttempts: maxAttemptCount,
            maxQuestions: assignmentSettings2.maxQuestions,
            reminderEnabled: dueToggle.checked && reminderEnabled.checked,
            selectionStrategy: strategy
          },
          status: statusSelect.value === "draft" ? "draft" : "open",
          timeDue,
          timeOpen
        };
        void this.saveAssignment(input, save, error);
      });
      return form;
    }
    toggleDateField(toggle, input, toggleLabel, inputLabel) {
      const group = liveElement("div", "quizgeist-assignment-date-field");
      const toggleWrapper = liveElement("label", "quizgeist-study-toggle");
      toggleWrapper.append(toggle, liveElement("span", "", { text: toggleLabel }));
      const inputWrapper = liveElement("label", "quizgeist-study-field");
      inputWrapper.append(
        liveElement("span", "quizgeist-study-field__label", { text: inputLabel }),
        input
      );
      group.append(toggleWrapper, inputWrapper);
      return group;
    }
    modeDescription(mode2) {
      const descriptions = {
        flashcards: "Gewusst-/Nicht-gewusst-Stapel mit Wiederholrunde",
        practice: "Direkte R\xFCckmeldung ohne Zeitdruck-Punkte",
        solo: "Tempo-Punkte; richtige Antworten behalten mindestens 50 % der Basispunkte",
        test: "R\xFCcknavigation, gesamte Auswertung erst am Ende",
        speaking: "Rundenweise sprechen \xFCben: vorlesen lassen, antworten, R\xFCckmeldung bekommen"
      };
      return this.text(`selfstudy:mode:${mode2}:description`, descriptions[mode2]);
    }
    async saveAssignment(assignment, button2, errorNode) {
      if (this.busy) {
        return;
      }
      this.setBusy(true, button2);
      errorNode.textContent = "";
      try {
        this.list = assignment.id ? await this.api.assignmentUpdate(assignment) : await this.api.assignmentCreate(assignment);
        this.editing = null;
        this.formOpen = false;
        this.busy = false;
        this.render();
        this.announce(this.text(
          "selfstudy:teacher:saved",
          "Zuweisung gespeichert."
        ));
      } catch (error) {
        this.setBusy(false, button2);
        errorNode.textContent = error instanceof LiveApiError && error.status === 409 ? this.text(
          "selfstudy:teacher:conflict",
          "Die Zuweisung wurde inzwischen ge\xE4ndert. Laden Sie die Liste neu und versuchen Sie es erneut."
        ) : this.api.errorMessage(error);
      }
    }
    async closeAssignment(assignment, button2) {
      if (this.busy || !window.confirm(this.text(
        "selfstudy:teacher:closeconfirm",
        "Diese Zuweisung schlie\xDFen? Bereits gespeicherte Ergebnisse bleiben erhalten."
      ))) {
        return;
      }
      this.setBusy(true, button2);
      try {
        this.list = await this.api.assignmentClose(
          assignment.id,
          assignment.timeModified
        );
        this.busy = false;
        this.render();
        this.announce(this.text(
          "selfstudy:teacher:closed",
          "Zuweisung geschlossen."
        ));
      } catch (error) {
        this.setBusy(false, button2);
        this.announce(this.api.errorMessage(error));
      }
    }
    setBusy(busy, button2) {
      this.busy = busy;
      button2.disabled = busy;
      button2.setAttribute("aria-busy", busy ? "true" : "false");
    }
  };
  function mountAssignmentTeacherApp(root, config) {
    const app = new AssignmentTeacherApp(root, config);
    void app.init();
  }

  // src/app_edit.ts
  function voices(value) {
    if (!Array.isArray(value)) {
      return [];
    }
    return value.flatMap((entry) => {
      if (!entry || typeof entry !== "object" || Array.isArray(entry)) {
        return [];
      }
      const voice = entry;
      const id = Number(voice.id || 0);
      const label = typeof voice.label === "string" ? voice.label : "";
      if (!Number.isInteger(id) || id <= 0 || label === "") {
        return [];
      }
      return [{
        id,
        label,
        ...typeof voice.lang === "string" ? { lang: voice.lang } : {},
        ...typeof voice.region === "string" ? { region: voice.region } : {},
        ...typeof voice.gender === "string" ? { gender: voice.gender } : {}
      }];
    });
  }
  function normalizeConfig(raw) {
    var _a, _b, _c, _d, _e;
    const cmid = Number(raw.cmid || 0);
    const ajaxUrl = typeof raw.ajaxUrl === "string" ? raw.ajaxUrl : "";
    const sesskey = typeof raw.sesskey === "string" ? raw.sesskey : "";
    if (!Number.isInteger(cmid) || cmid <= 0 || ajaxUrl === "" || sesskey === "") {
      return null;
    }
    const configuredVoices = voices((_a = raw.tts) == null ? void 0 : _a.voices);
    const speakUrl = typeof ((_b = raw.tts) == null ? void 0 : _b.speakUrl) === "string" ? raw.tts.speakUrl : "";
    return {
      ...raw,
      ajaxUrl,
      brandIconUrl: typeof raw.brandIconUrl === "string" ? raw.brandIconUrl : "",
      cmid,
      containerId: typeof raw.containerId === "string" && raw.containerId !== "" ? raw.containerId : "quizgeist-app-edit",
      hostUrl: typeof raw.hostUrl === "string" ? raw.hostUrl : "",
      initialView: raw.initialView === "templates" ? "templates" : "editor",
      kahootImportUrl: typeof raw.kahootImportUrl === "string" ? raw.kahootImportUrl : "",
      mediaUrl: typeof raw.mediaUrl === "string" ? raw.mediaUrl : "",
      season: typeof raw.season === "string" ? raw.season : "herbst",
      sesskey,
      strings: raw.strings || {},
      theme: typeof raw.theme === "string" ? raw.theme : "hell",
      tts: {
        available: Boolean(((_c = raw.tts) == null ? void 0 : _c.available) && speakUrl && configuredVoices.length > 0),
        defaultVoiceId: Number(((_d = raw.tts) == null ? void 0 : _d.defaultVoiceId) || ((_e = configuredVoices[0]) == null ? void 0 : _e.id) || 0),
        speakUrl,
        voices: configuredVoices
      }
    };
  }
  function init(raw = {}) {
    var _a, _b, _c, _d, _e;
    if (raw.initialView === "assignments" && ((_b = (_a = raw.features) == null ? void 0 : _a.selfstudy) == null ? void 0 : _b.installed) === true) {
      const assignmentConfig = normalizeSelfStudyConfig(raw, "quizgeist-app-edit");
      const assignmentContainerId = (assignmentConfig == null ? void 0 : assignmentConfig.containerId) || (typeof raw.containerId === "string" ? raw.containerId : "quizgeist-app-edit");
      const assignmentRoot = document.getElementById(assignmentContainerId);
      if (!assignmentRoot) {
        return;
      }
      if (!assignmentConfig) {
        const error = document.createElement("div");
        error.className = "quizgeist-study-state quizgeist-study-state--error";
        error.setAttribute("role", "alert");
        error.textContent = ((_c = raw.strings) == null ? void 0 : _c["selfstudy:error:config"]) || ((_d = raw.strings) == null ? void 0 : _d["editor:error:config"]) || "Die Zuweisungsverwaltung konnte nicht gestartet werden.";
        assignmentRoot.replaceChildren(error);
        return;
      }
      mountAssignmentTeacherApp(assignmentRoot, assignmentConfig);
      return;
    }
    const config = normalizeConfig(raw);
    const containerId = (config == null ? void 0 : config.containerId) || (typeof raw.containerId === "string" ? raw.containerId : "quizgeist-app-edit");
    const root = document.getElementById(containerId);
    if (!root) {
      return;
    }
    if (!config) {
      root.replaceChildren();
      const error = document.createElement("div");
      error.className = "quizgeist-editor-error";
      error.setAttribute("role", "alert");
      error.textContent = ((_e = raw.strings) == null ? void 0 : _e["editor:error:config"]) || "Der Editor konnte nicht gestartet werden.";
      root.append(error);
      return;
    }
    const app = new EditorApp(root, config);
    void app.init();
  }
  return __toCommonJS(app_edit_exports);
})();
