"use strict";
var QuizgeistPlayApp = (() => {
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

  // src/app_play.ts
  var app_play_exports = {};
  __export(app_play_exports, {
    init: () => init
  });

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

  // src/live/answer.ts
  function answerPayload(answer) {
    switch (answer.kind) {
      case "text":
        return { text: answer.text };
      case "clip":
        return { clipId: answer.clipId };
      case "order":
        return { orderIds: answer.orderIds };
      case "number":
        return { value: answer.value };
      case "pin":
        return { x: answer.x, y: answer.y };
      case "brainstormIdea":
        return { text: answer.text };
      case "brainstormVote":
        return { groupKey: answer.groupKey };
      case "reaction":
        return { reaction: answer.reaction };
      case "stage":
        return { reportId: answer.reportId };
      default:
        return { choiceIds: answer.choiceIds };
    }
  }
  function liveAnswerFromPayload(raw) {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      return null;
    }
    const value2 = raw;
    if (Array.isArray(value2.choiceIds) && value2.choiceIds.length > 0 && value2.choiceIds.every((id) => typeof id === "string")) {
      return { kind: "choices", choiceIds: value2.choiceIds };
    }
    if (Array.isArray(value2.orderIds) && value2.orderIds.length > 0 && value2.orderIds.every((id) => typeof id === "string")) {
      return { kind: "order", orderIds: value2.orderIds };
    }
    if (typeof value2.text === "string") {
      return { kind: "text", text: value2.text };
    }
    if (typeof value2.value === "number" && Number.isFinite(value2.value)) {
      return { kind: "number", value: value2.value };
    }
    if (typeof value2.x === "number" && Number.isFinite(value2.x) && typeof value2.y === "number" && Number.isFinite(value2.y)) {
      return { kind: "pin", x: value2.x, y: value2.y };
    }
    if (typeof value2.reaction === "string") {
      return { kind: "reaction", reaction: value2.reaction };
    }
    if (typeof value2.groupKey === "string") {
      return { kind: "brainstormVote", groupKey: value2.groupKey };
    }
    return null;
  }

  // src/live/avatar.ts
  var SVG_NS = "http://www.w3.org/2000/svg";
  var AVATAR_KEYS = [
    "kiesel",
    "zweig",
    "federchen",
    "klecks",
    "kubus",
    "wirbel",
    "stern",
    "mondchen"
  ];
  var BODY_COLORS = {
    federchen: "var(--mq-color-honig-500)",
    kiesel: "var(--mq-color-tiefsee-500)",
    klecks: "var(--mq-color-beere-500)",
    kubus: "var(--mq-color-funke-500)",
    mondchen: "var(--mq-color-mitternacht-600)",
    stern: "var(--mq-color-honig-500)",
    wirbel: "var(--mq-color-tiefsee-400)",
    zweig: "var(--mq-color-trieb-600)"
  };
  var COLOR_TOKENS = {
    beere: "var(--mq-color-beere-500)",
    funke: "var(--mq-color-funke-500)",
    honig: "var(--mq-color-honig-500)",
    ink: "var(--mq-color-ink-300)",
    mitternacht: "var(--mq-color-mitternacht-600)",
    tiefsee: "var(--mq-color-tiefsee-500)",
    trieb: "var(--mq-color-trieb-600)"
  };
  function svgElement(name, attributes) {
    const element3 = document.createElementNS(SVG_NS, name);
    Object.entries(attributes).forEach(([attribute, value2]) => {
      element3.setAttribute(attribute, value2);
    });
    return element3;
  }
  function body(key, requestedColor) {
    const fill = requestedColor && COLOR_TOKENS[requestedColor] ? COLOR_TOKENS[requestedColor] : BODY_COLORS[key];
    switch (key) {
      case "zweig": {
        const group = svgElement("g", {});
        group.append(
          svgElement("ellipse", { cx: "60", cy: "66", fill, rx: "31", ry: "42" }),
          svgElement("polygon", {
            fill: "var(--mq-color-trieb-700)",
            points: "60,8 70,29 50,29"
          })
        );
        return group;
      }
      case "federchen":
        return svgElement("path", {
          d: "M60 15C91 39 97 78 60 106C23 78 29 39 60 15Z",
          fill,
          transform: "rotate(8 60 60)"
        });
      case "klecks":
        return svgElement("path", {
          d: "M60 14c22-4 46 10 44 34s6 46-18 54-52 2-50-26 2-58 24-62z",
          fill
        });
      case "kubus":
        return svgElement("path", {
          d: "M60 12c34 0 46 12 46 46s-12 46-46 46-46-12-46-46 12-46 46-46z",
          fill
        });
      case "wirbel":
        return svgElement("path", {
          d: "M60 14a46 46 0 1 1-32 78 34 34 0 1 0 22-56 18 18 0 1 1 10 34c-10 0-17-7-17-16 0-8 6-14 14-14 5 0 9 2 12 6",
          fill: "none",
          stroke: fill,
          "stroke-linecap": "round",
          "stroke-width": "22"
        });
      case "stern":
        return svgElement("polygon", {
          fill,
          points: "60,8 72,42 108,43 79,65 89,101 60,80 31,101 41,65 12,43 48,42",
          stroke: fill,
          "stroke-linejoin": "round",
          "stroke-width": "7"
        });
      case "mondchen":
        return svgElement("path", {
          d: "M79 15a46 46 0 1 0 26 72A39 39 0 0 1 79 15Z",
          fill
        });
      default:
        return svgElement("circle", { cx: "60", cy: "62", fill, r: "46" });
    }
  }
  function accessory(keyValue) {
    const key = typeof keyValue === "string" ? keyValue : "";
    if (key === "partyhut" || key === "party-hat") {
      const group = svgElement("g", { "data-avatar-accessory": key });
      group.append(
        svgElement("polygon", {
          fill: "var(--mq-color-honig-300)",
          points: "60,0 75,27 45,27"
        }),
        svgElement("circle", {
          cx: "60",
          cy: "2",
          fill: "var(--mq-color-beere-600)",
          r: "5"
        })
      );
      return group;
    }
    if (key === "brille" || key === "round-glasses") {
      const group = svgElement("g", {
        "data-avatar-accessory": key,
        fill: "none",
        stroke: "var(--mq-color-ink-900)",
        "stroke-width": "3"
      });
      group.append(
        svgElement("circle", { cx: "44", cy: "51", r: "11" }),
        svgElement("circle", { cx: "76", cy: "51", r: "11" }),
        svgElement("line", { x1: "55", x2: "65", y1: "51", y2: "51" })
      );
      return group;
    }
    if (key === "krone" || key === "crown") {
      return svgElement("polygon", {
        "data-avatar-accessory": key,
        fill: "var(--mq-color-honig-300)",
        points: "39,24 43,5 59,18 74,4 81,25",
        stroke: "var(--mq-color-honig-700)",
        "stroke-linejoin": "round",
        "stroke-width": "2"
      });
    }
    if (key === "medaille" || key === "medal") {
      const group = svgElement("g", { "data-avatar-accessory": key });
      group.append(
        svgElement("path", {
          d: "M82 82L90 105L98 82",
          fill: "var(--mq-color-beere-500)"
        }),
        svgElement("circle", {
          cx: "90",
          cy: "86",
          fill: "var(--mq-color-honig-300)",
          r: "11",
          stroke: "var(--mq-color-honig-700)",
          "stroke-width": "2"
        })
      );
      return group;
    }
    if (key === "funkenbadge") {
      const group = svgElement("g", { "data-avatar-accessory": key });
      group.append(
        svgElement("circle", {
          cx: "91",
          cy: "84",
          fill: "var(--mq-color-honig-100)",
          r: "12",
          stroke: "var(--mq-color-honig-700)",
          "stroke-width": "2"
        }),
        svgElement("path", {
          d: "M91 75l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z",
          fill: "var(--mq-color-funke-500)"
        })
      );
      return group;
    }
    if (key === "flamme") {
      return svgElement("path", {
        "data-avatar-accessory": key,
        d: "M94 91c-9-7-6-17 1-25 0 7 6 8 6 15 4-3 5-6 5-10 7 8 8 20-1 25-5 3-9 1-11-5z",
        fill: "var(--mq-color-funke-500)",
        stroke: "var(--mq-color-honig-700)",
        "stroke-width": "2"
      });
    }
    return null;
  }
  function appearance(value2) {
    if (value2 && typeof value2 === "object") {
      const parsed = appearance(value2.avatarKey || null);
      return {
        accessoryKey: value2.accessoryKey || parsed.accessoryKey,
        avatarKey: parsed.avatarKey,
        colorKey: value2.colorKey || parsed.colorKey
      };
    }
    const parts = typeof value2 === "string" ? value2.split(":") : [];
    const compactAccessory = parts.length === 2 && ["flamme", "funkenbadge", "partyhut"].includes(parts[1]) ? parts[1] : null;
    return {
      accessoryKey: parts[2] || compactAccessory,
      avatarKey: parts[0] || null,
      colorKey: compactAccessory ? null : parts[1] || null
    };
  }
  function createAvatar(appearanceValue) {
    const selected = appearance(appearanceValue);
    const key = AVATAR_KEYS.includes(selected.avatarKey) ? selected.avatarKey : "kiesel";
    const svg = svgElement("svg", {
      "aria-hidden": "true",
      class: `quizgeist-avatar quizgeist-avatar--${key}`,
      focusable: "false",
      viewBox: "0 0 120 120"
    });
    svg.dataset.avatarKey = key;
    if (selected.colorKey) {
      svg.dataset.avatarColor = selected.colorKey;
    }
    if (selected.accessoryKey) {
      svg.dataset.avatarAccessory = selected.accessoryKey;
    }
    const faceColor = "var(--mq-color-ink-900)";
    svg.append(
      body(key, selected.colorKey),
      svgElement("circle", {
        cx: "44",
        cy: "59",
        fill: "var(--mq-color-honig-200)",
        opacity: ".45",
        r: "8"
      }),
      svgElement("circle", {
        cx: "76",
        cy: "59",
        fill: "var(--mq-color-honig-200)",
        opacity: ".45",
        r: "8"
      }),
      svgElement("circle", { cx: "44", cy: "52", fill: faceColor, r: "5" }),
      svgElement("circle", { cx: "76", cy: "52", fill: faceColor, r: "5" }),
      svgElement("path", {
        d: "M47 69Q60 79 73 69",
        fill: "none",
        stroke: faceColor,
        "stroke-linecap": "round",
        "stroke-width": "4"
      })
    );
    const accessoryLayer = accessory(selected.accessoryKey);
    if (accessoryLayer) {
      svg.append(accessoryLayer);
    }
    return svg;
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
  function createLiveMedia(source, options) {
    const rawUrl = typeof source.mediaUrl === "string" ? source.mediaUrl : "";
    const mimetype = source.mediaMimeType;
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

  // src/live/poller.ts
  var MIN_POLL_DELAY_MS = 1100;
  var MAX_ACTIVE_POLL_DELAY_MS = 4e3;
  var MAX_ERROR_DELAY_MS = 8e3;
  var AdaptivePoller = class {
    constructor(request, onError) {
      this.request = request;
      this.onError = onError;
      __publicField(this, "controller", null);
      __publicField(this, "consecutiveFailures", 0);
      __publicField(this, "inFlight", false);
      __publicField(this, "kickPending", false);
      __publicField(this, "running", false);
      __publicField(this, "timeoutId", null);
    }
    start(immediate = true) {
      if (this.running) {
        return;
      }
      this.running = true;
      this.consecutiveFailures = 0;
      this.schedule(immediate ? 0 : MIN_POLL_DELAY_MS);
    }
    stop() {
      var _a;
      this.running = false;
      this.kickPending = false;
      if (this.timeoutId !== null) {
        window.clearTimeout(this.timeoutId);
        this.timeoutId = null;
      }
      (_a = this.controller) == null ? void 0 : _a.abort();
      this.controller = null;
    }
    kick() {
      if (!this.running) {
        this.start(true);
        return;
      }
      if (this.inFlight) {
        this.kickPending = true;
        return;
      }
      if (this.timeoutId !== null) {
        window.clearTimeout(this.timeoutId);
        this.timeoutId = null;
      }
      this.schedule(0);
    }
    isRunning() {
      return this.running;
    }
    schedule(delayMs) {
      if (!this.running) {
        return;
      }
      this.timeoutId = window.setTimeout(() => {
        this.timeoutId = null;
        void this.tick();
      }, Math.max(0, delayMs));
    }
    async tick() {
      var _a;
      if (!this.running || this.inFlight) {
        return;
      }
      this.inFlight = true;
      this.controller = new AbortController();
      let nextDelay = MIN_POLL_DELAY_MS;
      try {
        const result = await this.request(this.controller.signal);
        this.consecutiveFailures = 0;
        nextDelay = this.successDelay(result == null ? void 0 : result.pollAfterMs);
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") {
          return;
        }
        this.consecutiveFailures += 1;
        const decision = this.onError(error, this.consecutiveFailures) || {};
        if (decision.stop) {
          this.running = false;
          return;
        }
        nextDelay = (_a = decision.delayMs) != null ? _a : Math.min(
          MAX_ERROR_DELAY_MS,
          MIN_POLL_DELAY_MS * 2 ** Math.min(3, this.consecutiveFailures - 1)
        );
      } finally {
        this.inFlight = false;
        this.controller = null;
      }
      if (!this.running) {
        return;
      }
      if (this.kickPending) {
        this.kickPending = false;
        this.schedule(0);
        return;
      }
      this.schedule(nextDelay);
    }
    successDelay(requestedDelay) {
      if (document.visibilityState === "hidden") {
        return MAX_ACTIVE_POLL_DELAY_MS;
      }
      const numericDelay = Number.isFinite(requestedDelay) ? Number(requestedDelay) : MIN_POLL_DELAY_MS;
      return Math.max(
        MIN_POLL_DELAY_MS,
        Math.min(MAX_ACTIVE_POLL_DELAY_MS, numericDelay)
      );
    }
  };

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

  // src/live/choices.ts
  var LIVE_CHOICE_SLOTS = ["a", "b", "c", "d", "e", "f"];
  function choiceSlot(qtype, index) {
    if (qtype === "truefalse" && index === 1) {
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
  function choiceShapeStringKey(slot) {
    return `live:shape:${slot}`;
  }

  // src/live/qtype/registry.ts
  function data(question2) {
    return question2.typeData && typeof question2.typeData === "object" ? question2.typeData : {};
  }
  function value(question2, key, fallback) {
    var _a;
    const source = data(question2);
    const direct = question2[key];
    const candidate = (_a = source[key]) != null ? _a : direct;
    return candidate === void 0 || candidate === null ? fallback : candidate;
  }
  function responseType(question2) {
    if (typeof question2.responseType === "string" && question2.responseType !== "") {
      return question2.responseType;
    }
    return {
      quiz: "choices",
      truefalse: "choices",
      poll: "choices",
      shortanswer: "text",
      puzzle: "order",
      wordcloud: "text",
      scale: "scale",
      slider: "slider",
      pin: "pin",
      reveal: "reveal",
      brainstorm: "brainstorm",
      open: "text",
      slide: "slide"
    }[question2.qtype] || "text";
  }
  function responseRoot(question2, kind, context) {
    return liveElement(
      "section",
      `quizgeist-live-response quizgeist-live-response--${kind} quizgeist-live-response--${context.audience}`,
      {
        "data-live-answer-kind": kind,
        "data-live-qtype": question2.qtype
      }
    );
  }
  function submitButton(context, answer) {
    const submit = liveButton(
      context.text("play:answer:send", "Antwort senden"),
      context.audience === "player" ? "quizgeist-player-primary quizgeist-answer-send" : "quizgeist-host-button quizgeist-host-button--primary",
      {
        "data-action": "answer",
        "data-live-submit": true,
        disabled: context.disabled || answer() === null
      }
    );
    submit.addEventListener("click", () => {
      var _a, _b;
      const current = answer();
      if (current) {
        (_a = context.onTap) == null ? void 0 : _a.call(context);
        (_b = context.onSubmit) == null ? void 0 : _b.call(context, current);
      }
    });
    return submit;
  }
  function currentChoiceIds(answer) {
    return answer && "choiceIds" in answer && Array.isArray(answer.choiceIds) ? answer.choiceIds : [];
  }
  var renderChoices = (question2, context) => {
    const root = responseRoot(question2, "choices", context);
    const choices = Array.isArray(question2.choices) ? question2.choices : [];
    const multiple = Boolean(question2.multiple);
    let selected = new Set(currentChoiceIds(context.answer));
    const grid = liveElement(
      "div",
      context.audience === "host" ? `quizgeist-host-answer-grid quizgeist-host-answer-grid--${choices.length <= 2 ? "two" : choices.length <= 4 ? "four" : "six"}` : "quizgeist-player-choices",
      {
        role: context.interactive ? multiple ? "group" : "radiogroup" : "list",
        "data-live-choice-grid": true
      }
    );
    const patch = () => {
      var _a;
      grid.querySelectorAll("[data-choice-id]").forEach((node) => {
        const checked = selected.has(node.dataset.choiceId || "");
        node.classList.toggle("is-selected", checked);
        if (multiple) {
          node.setAttribute("aria-pressed", checked ? "true" : "false");
        } else if (context.interactive) {
          node.setAttribute("aria-checked", checked ? "true" : "false");
          node.tabIndex = checked || selected.size === 0 && node === grid.querySelector("[data-choice-id]") ? 0 : -1;
        }
      });
      const answer = selected.size > 0 ? { kind: "choices", choiceIds: Array.from(selected) } : null;
      (_a = context.onChange) == null ? void 0 : _a.call(context, answer);
      const send = root.querySelector("[data-live-submit]");
      if (send) {
        send.disabled = Boolean(context.disabled || !answer);
      }
    };
    choices.forEach((choice, index) => {
      const slot = choiceSlot(question2.qtype, index);
      const className = context.audience === "host" ? `quizgeist-host-answer quizgeist-host-answer--${slot}` : `quizgeist-choice quizgeist-choice--${slot}`;
      const tile = context.interactive ? liveButton(choice.text, className, {
        "data-action": "select-answer",
        "data-choice-id": choice.id,
        "data-choice-slot": slot,
        "data-live-choice-id": choice.id,
        disabled: context.disabled
      }) : liveElement("div", className, {
        "data-choice-id": choice.id,
        "data-choice-slot": slot,
        "data-live-choice-id": choice.id,
        role: "listitem"
      });
      tile.setAttribute(
        "aria-label",
        `${context.text(choiceShapeStringKey(slot), slot.toUpperCase())}: ${choice.text || context.text("editor:field:answerimage", "Antwortmedium")}`
      );
      if (context.interactive) {
        if (multiple) {
          tile.setAttribute("aria-pressed", selected.has(choice.id) ? "true" : "false");
        } else {
          tile.setAttribute("role", "radio");
          tile.setAttribute("aria-checked", selected.has(choice.id) ? "true" : "false");
        }
        tile.addEventListener("click", () => {
          var _a;
          (_a = context.onTap) == null ? void 0 : _a.call(context);
          if (multiple) {
            if (selected.has(choice.id)) {
              selected.delete(choice.id);
            } else {
              selected.add(choice.id);
            }
          } else {
            selected = /* @__PURE__ */ new Set([choice.id]);
          }
          patch();
        });
      }
      tile.replaceChildren(choiceShape(slot));
      const media = createLiveMedia(choice, {
        allowPlayback: false,
        className: context.audience === "host" ? "quizgeist-host-answer__media" : "quizgeist-choice__media",
        label: ""
      });
      if (media) {
        tile.append(media);
      }
      tile.append(liveElement("span", context.audience === "host" ? "quizgeist-host-answer__text" : "quizgeist-choice__text", { text: choice.text }));
      grid.append(tile);
    });
    if (context.interactive) {
      grid.addEventListener("keydown", (event) => {
        if (/^[1-6]$/.test(event.key)) {
          const choice = choices[Number(event.key) - 1];
          if (choice) {
            event.preventDefault();
            const control = grid.querySelector(
              `[data-choice-id="${CSS.escape(choice.id)}"]`
            );
            control == null ? void 0 : control.click();
            control == null ? void 0 : control.focus();
          }
        }
      });
    }
    root.append(grid);
    if (context.interactive) {
      root.append(submitButton(context, () => selected.size > 0 ? { kind: "choices", choiceIds: Array.from(selected) } : null));
    }
    patch();
    return root;
  };
  function textAnswer(answer) {
    return (answer == null ? void 0 : answer.kind) === "text" || (answer == null ? void 0 : answer.kind) === "brainstormIdea" ? answer.text : "";
  }
  function renderText(question2, context, kind = "text") {
    const root = responseRoot(question2, kind, context);
    const maxChars = Math.max(1, Number(value(question2, "maxChars", question2.qtype === "open" ? 2e3 : 160)));
    const multiline = question2.qtype === "open" || question2.qtype === "wordcloud" || question2.qtype === "brainstorm";
    if (!context.interactive) {
      root.append(liveElement("p", "quizgeist-live-response__prompt", {
        text: question2.qtype === "open" ? context.text("live:response:open", "Freie Antwort auf dem eigenen Ger\xE4t") : context.text("live:response:text", "Antwort auf dem eigenen Ger\xE4t eingeben")
      }));
      return root;
    }
    let current = textAnswer(context.answer);
    const label = liveElement("label", "quizgeist-live-response__label", {
      text: context.text("play:answer:text", "Deine Antwort")
    });
    const input = multiline ? liveElement("textarea", "quizgeist-live-text-answer", {
      "data-live-text-answer": true,
      maxlength: maxChars,
      rows: question2.qtype === "open" ? 5 : 3,
      value: current
    }) : liveElement("input", "quizgeist-live-text-answer", {
      "data-live-text-answer": true,
      maxlength: maxChars,
      type: "text",
      value: current
    });
    const counter = liveElement("span", "quizgeist-live-character-count", {
      "aria-live": "polite",
      "data-live-character-count": true,
      text: `${current.length} / ${maxChars}`
    });
    label.append(input);
    const currentAnswer = () => current.trim() === "" ? null : { kind: "text", text: current.trim() };
    const submit = submitButton(context, currentAnswer);
    input.addEventListener("input", () => {
      var _a;
      current = input.value.slice(0, maxChars);
      counter.textContent = `${current.length} / ${maxChars}`;
      const answer = currentAnswer();
      (_a = context.onChange) == null ? void 0 : _a.call(context, answer);
      submit.disabled = Boolean(context.disabled || !answer);
    });
    root.append(label, counter, submit);
    return root;
  }
  var renderPuzzle = (question2, context) => {
    var _a, _b;
    const root = responseRoot(question2, "order", context);
    const items = value(question2, "items", []);
    let order = ((_a = context.answer) == null ? void 0 : _a.kind) === "order" ? context.answer.orderIds.filter((id) => items.some((item) => item.id === id)) : items.map((item) => item.id);
    items.forEach((item) => {
      if (!order.includes(item.id)) {
        order.push(item.id);
      }
    });
    const list = liveElement("ol", "quizgeist-live-puzzle", {
      "data-live-puzzle": true
    });
    let draggedId = "";
    const answer = () => ({ kind: "order", orderIds: [...order] });
    const render = () => {
      list.replaceChildren();
      order.forEach((id, index) => {
        const item = items.find((candidate) => candidate.id === id);
        if (!item) {
          return;
        }
        const row = liveElement("li", "quizgeist-live-puzzle__item", {
          "data-live-puzzle-item": item.id,
          draggable: context.interactive && !context.disabled
        });
        const content = liveElement("span", "quizgeist-live-puzzle__text", {
          text: item.text
        });
        row.append(
          liveElement("span", "quizgeist-live-puzzle__handle", {
            "aria-hidden": "true",
            text: "\u283F"
          }),
          liveElement("span", "quizgeist-live-puzzle__index", { text: String(index + 1) }),
          content
        );
        const media = createLiveMedia(item, {
          allowPlayback: false,
          className: "quizgeist-live-puzzle__media",
          label: ""
        });
        if (media) {
          content.append(media);
        }
        if (context.interactive) {
          const up = liveButton("\u2191", "quizgeist-live-order-button", {
            "aria-label": context.text("live:puzzle:up", "Nach oben"),
            "data-live-order-up": item.id,
            disabled: context.disabled || index === 0
          });
          const down = liveButton("\u2193", "quizgeist-live-order-button", {
            "aria-label": context.text("live:puzzle:down", "Nach unten"),
            "data-live-order-down": item.id,
            disabled: context.disabled || index >= order.length - 1
          });
          const move = (offset) => {
            var _a2, _b2;
            const next = index + offset;
            if (next < 0 || next >= order.length) {
              return;
            }
            [order[index], order[next]] = [order[next], order[index]];
            (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
            (_b2 = context.onChange) == null ? void 0 : _b2.call(context, answer());
            render();
          };
          up.addEventListener("click", () => move(-1));
          down.addEventListener("click", () => move(1));
          row.addEventListener("dragstart", () => {
            draggedId = item.id;
          });
          row.addEventListener("dragover", (event) => event.preventDefault());
          row.addEventListener("drop", (event) => {
            var _a2;
            event.preventDefault();
            const from = order.indexOf(draggedId);
            const to = order.indexOf(item.id);
            if (from >= 0 && to >= 0 && from !== to) {
              order.splice(to, 0, order.splice(from, 1)[0]);
              (_a2 = context.onChange) == null ? void 0 : _a2.call(context, answer());
              render();
            }
          });
          const controls = liveElement("span", "quizgeist-live-puzzle__controls");
          controls.append(up, down);
          row.append(controls);
        }
        list.append(row);
      });
    };
    render();
    root.append(list);
    if (context.interactive) {
      (_b = context.onChange) == null ? void 0 : _b.call(context, answer());
      root.append(submitButton(context, answer));
    }
    return root;
  };
  var renderScale = (question2, context) => {
    var _a;
    const root = responseRoot(question2, "number", context);
    const steps = Math.max(3, Math.min(10, Number(value(question2, "steps", 5))));
    const minLabel = String(value(question2, "minLabel", "1"));
    const maxLabel = String(value(question2, "maxLabel", String(steps)));
    let selected = ((_a = context.answer) == null ? void 0 : _a.kind) === "number" ? context.answer.value : 0;
    const labels = liveElement("div", "quizgeist-live-scale__labels", {
      "aria-hidden": "true",
      "data-live-scale-labels": true
    });
    labels.style.display = "flex";
    labels.style.gap = "var(--mq-space-3)";
    labels.style.justifyContent = "space-between";
    labels.style.width = "100%";
    labels.append(
      liveElement("span", "", { text: minLabel }),
      liveElement("span", "", { text: maxLabel })
    );
    const controls = liveElement("div", "quizgeist-live-scale", {
      role: context.interactive ? "radiogroup" : "list"
    });
    const patch = () => {
      var _a2;
      controls.querySelectorAll("[data-live-scale-value]").forEach((button2) => {
        const checked = Number(button2.dataset.liveScaleValue) === selected;
        button2.classList.toggle("is-selected", checked);
        button2.setAttribute("aria-checked", checked ? "true" : "false");
        button2.tabIndex = checked || selected === 0 && button2.dataset.liveScaleValue === "1" ? 0 : -1;
      });
      (_a2 = context.onChange) == null ? void 0 : _a2.call(context, selected > 0 ? { kind: "number", value: selected } : null);
      const submit = root.querySelector("[data-live-submit]");
      if (submit) {
        submit.disabled = Boolean(context.disabled || selected <= 0);
      }
    };
    for (let number = 1; number <= steps; number += 1) {
      const chip = context.interactive ? liveButton(String(number), "quizgeist-live-scale__value", {
        "aria-label": number === 1 ? `${number}: ${minLabel}` : number === steps ? `${number}: ${maxLabel}` : String(number),
        "data-live-scale-value": number,
        disabled: context.disabled,
        role: "radio"
      }) : liveElement("span", "quizgeist-live-scale__value", {
        "data-live-scale-value": number,
        role: "listitem",
        text: String(number)
      });
      if (context.interactive) {
        chip.addEventListener("click", () => {
          var _a2;
          selected = number;
          (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
          patch();
        });
      }
      controls.append(chip);
    }
    if (context.interactive) {
      controls.addEventListener("keydown", (event) => {
        var _a2, _b;
        if (!["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) {
          return;
        }
        const focused = event.target instanceof HTMLElement ? Number(event.target.dataset.liveScaleValue || 0) : 0;
        const origin = selected > 0 ? selected : Math.max(1, focused);
        let next = origin;
        if (event.key === "Home") {
          next = 1;
        } else if (event.key === "End") {
          next = steps;
        } else if (event.key === "ArrowLeft" || event.key === "ArrowDown") {
          next = Math.max(1, origin - 1);
        } else {
          next = Math.min(steps, origin + 1);
        }
        event.preventDefault();
        selected = next;
        (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
        patch();
        (_b = controls.querySelector(
          `[data-live-scale-value="${next}"]`
        )) == null ? void 0 : _b.focus();
      });
    }
    root.append(labels, controls);
    if (context.interactive) {
      root.append(submitButton(context, () => selected > 0 ? { kind: "number", value: selected } : null));
      patch();
    }
    return root;
  };
  var renderSlider = (question2, context) => {
    var _a;
    const root = responseRoot(question2, "number", context);
    const min = Number(value(question2, "min", 0));
    const max = Number(value(question2, "max", 100));
    const step = Math.max(1e-6, Number(value(question2, "step", 1)));
    const maximumStep = Math.max(0, Math.floor((max - min) / step + 1e-7));
    const snap = (next) => {
      const stepIndex = Math.max(
        0,
        Math.min(maximumStep, Math.round((next - min) / step))
      );
      return Number((min + stepIndex * step).toFixed(6));
    };
    let current = ((_a = context.answer) == null ? void 0 : _a.kind) === "number" ? snap(context.answer.value) : snap((min + max) / 2);
    const output = liveElement("output", "quizgeist-live-slider__value", {
      "data-live-slider-value": true,
      text: String(current)
    });
    const slider = liveElement("input", "quizgeist-live-slider", {
      "data-live-slider": true,
      disabled: context.disabled || !context.interactive,
      max,
      min,
      step,
      type: "range",
      value: current
    });
    const set = (next) => {
      var _a2;
      current = snap(next);
      slider.value = String(current);
      output.value = String(current);
      output.textContent = String(current);
      (_a2 = context.onChange) == null ? void 0 : _a2.call(context, { kind: "number", value: current });
    };
    slider.addEventListener("input", () => set(Number(slider.value)));
    const minus = liveButton("\u2212", "quizgeist-live-slider__adjust", {
      "aria-label": context.text("live:slider:minus", "Wert verkleinern"),
      "data-live-number-minus": true,
      disabled: context.disabled || !context.interactive
    });
    const plus = liveButton("+", "quizgeist-live-slider__adjust", {
      "aria-label": context.text("live:slider:plus", "Wert vergr\xF6\xDFern"),
      "data-live-number-plus": true,
      disabled: context.disabled || !context.interactive
    });
    minus.addEventListener("click", () => set(current - step));
    plus.addEventListener("click", () => set(current + step));
    const row = liveElement("div", "quizgeist-live-slider__controls");
    row.append(minus, slider, plus);
    root.append(output, row);
    if (context.interactive) {
      set(current);
      root.append(submitButton(context, () => ({ kind: "number", value: current })));
    }
    return root;
  };
  function pinMedia(question2) {
    const typeData = data(question2);
    return {
      mediaMimeType: typeData.mediaMimeType || question2.mediaMimeType,
      mediaUrl: typeData.mediaUrl || question2.mediaUrl
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
  var renderPin = (question2, context) => {
    var _a;
    const root = responseRoot(question2, "pin", context);
    let point = ((_a = context.answer) == null ? void 0 : _a.kind) === "pin" ? { x: context.answer.x, y: context.answer.y } : null;
    const canvas = liveElement("div", "quizgeist-live-pin", {
      "aria-label": context.text("live:pin:place", "Pin auf dem Bild platzieren"),
      "aria-disabled": context.interactive && context.disabled ? "true" : void 0,
      "data-live-pin-canvas": true,
      role: context.interactive ? "button" : "img",
      tabindex: context.interactive && !context.disabled ? 0 : void 0
    });
    const image = createLiveMedia(pinMedia(question2), {
      allowPlayback: false,
      className: "quizgeist-live-pin__image",
      label: question2.questionText
    });
    const pinImage = image instanceof HTMLImageElement ? image : null;
    if (image) {
      canvas.append(image);
    }
    const marker = liveElement("span", "quizgeist-live-pin__marker", {
      "aria-hidden": "true",
      "data-live-pin-marker": true,
      hidden: !point
    });
    canvas.append(marker);
    const patch = () => {
      var _a2;
      marker.hidden = !point;
      if (point) {
        positionPinNode(canvas, pinImage, marker, point);
        canvas.setAttribute(
          "aria-label",
          `${context.text("live:pin:place", "Pin auf dem Bild platzieren")}: ${Math.round(point.x)} %, ${Math.round(point.y)} %`
        );
        (_a2 = context.onChange) == null ? void 0 : _a2.call(context, { kind: "pin", x: point.x, y: point.y });
      }
      const submit = root.querySelector("[data-live-submit]");
      if (submit) {
        submit.disabled = Boolean(context.disabled || !point);
      }
    };
    if (context.interactive) {
      canvas.addEventListener("pointerdown", (event) => {
        var _a2;
        if (context.disabled) {
          return;
        }
        const bounds = canvas.getBoundingClientRect();
        const geometry = pinImageGeometry(canvas, pinImage);
        if (!geometry) {
          return;
        }
        const x = event.clientX - bounds.left - geometry.left;
        const y = event.clientY - bounds.top - geometry.top;
        if (x < 0 || x > geometry.width || y < 0 || y > geometry.height) {
          return;
        }
        point = {
          x: Math.max(0, Math.min(100, x * 100 / geometry.width)),
          y: Math.max(0, Math.min(100, y * 100 / geometry.height))
        };
        (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
        patch();
      });
      canvas.addEventListener("keydown", (event) => {
        var _a2;
        if (context.disabled) {
          return;
        }
        const distance = event.shiftKey ? 5 : 1;
        const next = point ? { ...point } : { x: 50, y: 50 };
        if (event.key === "ArrowLeft") {
          next.x -= distance;
        } else if (event.key === "ArrowRight") {
          next.x += distance;
        } else if (event.key === "ArrowUp") {
          next.y -= distance;
        } else if (event.key === "ArrowDown") {
          next.y += distance;
        } else if ((event.key === " " || event.key === "Enter") && !point) {
        } else {
          return;
        }
        event.preventDefault();
        point = {
          x: Math.max(0, Math.min(100, next.x)),
          y: Math.max(0, Math.min(100, next.y))
        };
        (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
        patch();
      });
    }
    pinImage == null ? void 0 : pinImage.addEventListener("load", patch, { once: true });
    patch();
    root.append(canvas);
    if (context.interactive) {
      root.append(submitButton(context, () => point ? { kind: "pin", x: point.x, y: point.y } : null));
    }
    return root;
  };
  function renderRevealImage(question2, context) {
    const frame = liveElement("div", "quizgeist-live-image-reveal", {
      "data-live-reveal-grid": true
    });
    const media = createLiveMedia(pinMedia(question2), {
      allowPlayback: false,
      className: "quizgeist-live-image-reveal__image",
      label: question2.questionText
    });
    if (media) {
      frame.append(media);
    }
    const gridSize = Math.max(3, Math.min(6, Number(value(question2, "grid", 3))));
    const count2 = gridSize * gridSize;
    const order = value(question2, "tileOrder", Array.from({ length: count2 }, (_, i) => i));
    const seconds = Math.max(1, Number(value(question2, "revealSeconds", 12)));
    const stepMs = Math.max(80, Number(value(question2, "stepMs", seconds * 1e3 / count2)));
    const phaseStartedAtMs = Number(context.phaseStartedAtMs);
    const elapsedMs = Number.isFinite(phaseStartedAtMs) && phaseStartedAtMs > 0 ? Math.max(0, context.nowMs - phaseStartedAtMs) : 0;
    const bonus = liveElement("span", "quizgeist-live-image-reveal__bonus", {
      "aria-live": "off",
      "data-live-reveal-bonus": true,
      "data-live-reveal-label": context.text("live:reveal:bonus", "Fr\xFChbonus"),
      "data-live-reveal-start-ms": phaseStartedAtMs,
      "data-live-reveal-step-ms": stepMs,
      "data-live-reveal-total": count2
    });
    const overlay = liveElement("div", "quizgeist-live-image-reveal__tiles", {
      "aria-hidden": "true"
    });
    overlay.style.gridTemplateColumns = `repeat(${gridSize}, 1fr)`;
    for (let index = 0; index < count2; index += 1) {
      const orderIndex = Math.max(0, order.indexOf(index));
      const tile = liveElement("span", "quizgeist-live-image-reveal__tile");
      tile.style.animationDelay = `${orderIndex * stepMs - elapsedMs}ms`;
      overlay.append(tile);
    }
    frame.append(overlay, bonus);
    patchLiveResponseClock(frame, context.nowMs);
    return frame;
  }
  function patchLiveResponseClock(root, nowMs) {
    root.querySelectorAll("[data-live-reveal-bonus]").forEach((node) => {
      const start = Number(node.dataset.liveRevealStartMs);
      const step = Number(node.dataset.liveRevealStepMs);
      const total = Math.max(1, Number(node.dataset.liveRevealTotal));
      if (!Number.isFinite(start) || !Number.isFinite(step) || step <= 0) {
        return;
      }
      const revealed = Math.max(0, Math.floor(Math.max(0, nowMs - start) / step));
      const remaining = Math.max(0, total - revealed);
      node.textContent = `${node.dataset.liveRevealLabel || "Fr\xFChbonus"}: ${remaining} / ${total}`;
    });
  }
  var renderImageReveal = (question2, context) => {
    var _a;
    const root = responseRoot(question2, "reveal", context);
    root.append(renderRevealImage(question2, context));
    if (context.interactive) {
      const form = renderText(question2, context, "text");
      (_a = form.querySelector("[data-live-qtype]")) == null ? void 0 : _a.removeAttribute("data-live-qtype");
      root.append(...Array.from(form.childNodes));
    }
    return root;
  };
  function brainstormStage(question2) {
    return String(
      question2.interactionStage || value(question2, "interactionStage", value(question2, "stage", "collect"))
    );
  }
  function brainstormGroups(question2, aggregate) {
    const aggregateGroups = objectRows(aggregateRecord(aggregate).groups).map((group) => ({
      ideas: objectRows(group.ideas).map((idea) => ({
        groupKey: String(group.key || ""),
        id: String(idea.id || idea.key || ""),
        own: idea.own === true,
        text: String(idea.text || ""),
        votes: idea.votes === null || idea.votes === void 0 ? void 0 : Number(idea.votes)
      })),
      key: String(group.key || ""),
      label: String(group.label || ""),
      votes: group.votes === null || group.votes === void 0 ? void 0 : Number(group.votes)
    }));
    if (aggregateGroups.length > 0) {
      return aggregateGroups;
    }
    const groups = value(question2, "groups", []);
    if (Array.isArray(groups) && groups.length > 0) {
      return groups;
    }
    const ideas = value(question2, "ideas", []);
    return ideas.length > 0 ? [{
      key: "all",
      label: "",
      ideas,
      votes: ideas.reduce((sum, idea) => sum + Number(idea.votes || 0), 0)
    }] : [];
  }
  var renderBrainstorm = (question2, context) => {
    var _a;
    const root = responseRoot(question2, "brainstorm", context);
    const stage = brainstormStage(question2);
    root.dataset.liveBrainstormStage = stage;
    root.append(liveElement("p", "quizgeist-live-brainstorm__stage", {
      "data-live-brainstorm-stage": stage,
      text: context.text(`live:brainstorm:${stage}`, {
        collect: "Ideen sammeln",
        group: "Ideen gruppieren",
        vote: "Ideen abstimmen",
        done: "Ergebnis"
      }[stage] || stage)
    }));
    if (stage === "collect" && context.interactive) {
      let current = "";
      const input = liveElement("textarea", "quizgeist-live-text-answer", {
        "data-live-brainstorm-idea": true,
        "data-live-text-answer": true,
        maxlength: Math.max(
          1,
          Number(value(question2, "maxChars", value(question2, "maxIdeaChars", 280)))
        ),
        rows: 3
      });
      const submit = liveButton(
        context.text("live:brainstorm:submit", "Idee einreichen"),
        "quizgeist-player-primary",
        {
          "data-live-submit": true,
          disabled: true
        }
      );
      input.addEventListener("input", () => {
        var _a2;
        current = input.value.trim();
        submit.disabled = Boolean(context.disabled || current === "");
        (_a2 = context.onChange) == null ? void 0 : _a2.call(context, current === "" ? null : { kind: "brainstormIdea", text: current });
      });
      submit.addEventListener("click", () => {
        var _a2;
        if (current !== "") {
          const answer = { kind: "brainstormIdea", text: current };
          (_a2 = context.onSubmit) == null ? void 0 : _a2.call(context, answer);
        }
      });
      root.append(input, submit);
      return root;
    }
    const groups = brainstormGroups(question2, context.aggregate);
    const list = liveElement("div", "quizgeist-live-brainstorm__groups");
    let selectedGroup = ((_a = context.answer) == null ? void 0 : _a.kind) === "brainstormVote" ? context.answer.groupKey : String(value(question2, "ownGroupKey", ""));
    groups.forEach((group) => {
      const section = stage === "vote" && context.interactive ? liveButton("", "quizgeist-live-brainstorm__group", {
        "aria-pressed": selectedGroup === group.key ? "true" : "false",
        "data-live-brainstorm-vote": group.key,
        disabled: context.disabled
      }) : liveElement("section", "quizgeist-live-brainstorm__group");
      section.dataset.liveBrainstormGroup = group.key;
      if (group.label) {
        section.append(liveElement("h3", "", { text: group.label }));
      }
      group.ideas.forEach((idea) => {
        const ideaNode = liveElement("article", "quizgeist-live-brainstorm__idea", {
          "data-live-brainstorm-idea": idea.id,
          text: idea.text
        });
        if (typeof idea.votes === "number") {
          ideaNode.append(liveElement("span", "quizgeist-live-brainstorm__votes", {
            text: `\u2665 ${idea.votes}`
          }));
        }
        section.append(ideaNode);
      });
      if (stage === "vote" && context.interactive) {
        section.addEventListener("click", () => {
          var _a2, _b;
          selectedGroup = group.key;
          (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
          list.querySelectorAll("[data-live-brainstorm-vote]").forEach((node) => {
            const checked = node.dataset.liveBrainstormVote === selectedGroup;
            node.classList.toggle("is-selected", checked);
            node.setAttribute("aria-pressed", checked ? "true" : "false");
          });
          (_b = context.onChange) == null ? void 0 : _b.call(context, {
            groupKey: selectedGroup,
            kind: "brainstormVote"
          });
          const submit = root.querySelector("[data-live-submit]");
          if (submit) {
            submit.disabled = Boolean(context.disabled || selectedGroup === "");
          }
        });
      }
      list.append(section);
    });
    root.append(list);
    if (stage === "vote" && context.interactive) {
      root.append(submitButton(context, () => selectedGroup !== "" ? { groupKey: selectedGroup, kind: "brainstormVote" } : null));
    }
    return root;
  };
  var REACTION_LABEL_FALLBACKS = {
    clap: "Applaus",
    heart: "Herz",
    idea: "Idee",
    laugh: "Lachen",
    wow: "Wow"
  };
  function reactionOptions(question2, context) {
    const source = value(question2, "reactionOptions", value(question2, "reactions", []));
    if (Array.isArray(source)) {
      return source.flatMap((entry, index) => {
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
  var renderSlide = (question2, context) => {
    const root = responseRoot(question2, "slide", context);
    const layout = String(value(question2, "layout", "title"));
    const card = liveElement("article", `quizgeist-live-slide quizgeist-live-slide--${layout}`, {
      "data-live-slide": layout
    });
    const title = String(value(question2, "title", question2.questionText));
    const body2 = String(value(question2, "body", ""));
    if (title) {
      card.append(liveElement("h2", "quizgeist-live-slide__title", { text: title }));
    }
    if (layout === "quote") {
      card.append(
        liveElement("blockquote", "quizgeist-live-slide__quote", {
          text: String(value(question2, "quote", body2))
        }),
        liveElement("cite", "quizgeist-live-slide__attribution", {
          text: String(value(question2, "attribution", ""))
        })
      );
    } else if (layout === "bullets") {
      const list = liveElement("ul", "quizgeist-live-slide__bullets");
      value(question2, "bullets", []).forEach((bullet) => {
        list.append(liveElement("li", "", { text: bullet }));
      });
      card.append(list);
    } else if (body2) {
      card.append(liveElement("p", "quizgeist-live-slide__body", { text: body2 }));
    }
    const media = createLiveMedia(pinMedia(question2), {
      className: "quizgeist-live-slide__media",
      label: title
    });
    if (media) {
      card.append(media);
    }
    root.append(card);
    if (value(question2, "reactions", false) !== false) {
      const reactions = liveElement("div", "quizgeist-live-reactions", {
        "aria-label": context.text("live:reactions:title", "Live-Reaktionen"),
        "data-live-reactions": true,
        role: context.interactive ? "radiogroup" : "list"
      });
      const ownReaction = String(value(question2, "ownReaction", ""));
      reactionOptions(question2, context).forEach((reaction) => {
        const node = context.interactive ? liveButton(reaction.emoji, "quizgeist-live-reaction", {
          "aria-label": reaction.label,
          "aria-checked": ownReaction === reaction.id ? "true" : "false",
          "data-live-reaction": reaction.id,
          disabled: context.disabled,
          role: "radio"
        }) : liveElement("span", "quizgeist-live-reaction", {
          "data-live-reaction": reaction.id,
          role: "listitem",
          text: reaction.emoji
        });
        if (context.interactive) {
          node.addEventListener("click", () => {
            var _a, _b, _c;
            (_a = context.onTap) == null ? void 0 : _a.call(context);
            const answer = {
              kind: "reaction",
              reaction: reaction.id
            };
            (_b = context.onChange) == null ? void 0 : _b.call(context, answer);
            (_c = context.onSubmit) == null ? void 0 : _c.call(context, answer);
            reactions.querySelectorAll("[data-live-reaction]").forEach((candidate) => {
              candidate.setAttribute(
                "aria-checked",
                candidate === node ? "true" : "false"
              );
            });
          });
        }
        if (!context.interactive && typeof reaction.count === "number") {
          node.append(liveElement("strong", "", { text: String(reaction.count) }));
        }
        reactions.append(node);
      });
      root.append(reactions);
    }
    return root;
  };
  var RENDERERS = {
    brainstorm: renderBrainstorm,
    choices: renderChoices,
    choice: renderChoices,
    order: renderPuzzle,
    puzzle: renderPuzzle,
    reaction: renderSlide,
    pin: renderPin,
    reveal: renderImageReveal,
    scale: renderScale,
    slide: renderSlide,
    slider: renderSlider,
    text: renderText
  };
  function renderLiveResponse(question2, context) {
    const renderer = RENDERERS[responseType(question2)] || RENDERERS[question2.qtype] || renderText;
    return renderer(question2, context);
  }
  function questionAllowsRepeatedSubmissions(question2) {
    var _a, _b, _c;
    return Boolean(
      (_c = (_b = (_a = question2.policyDescriptor) == null ? void 0 : _a.allowsMultipleSubmissions) != null ? _b : question2.allowsMultipleSubmissions) != null ? _c : false
    );
  }
  function questionSpeechText(question2) {
    if (typeof question2.speechText === "string" && question2.speechText.trim() !== "") {
      return question2.speechText;
    }
    const chunks = [question2.questionText];
    if (question2.qtype === "slide") {
      chunks.push(
        String(value(question2, "title", "")),
        String(value(question2, "body", "")),
        ...value(question2, "bullets", []),
        String(value(question2, "quote", "")),
        String(value(question2, "attribution", ""))
      );
    }
    return chunks.filter(Boolean).join(". ");
  }
  function answerLabels(question2, ids) {
    const candidates = [
      ...Array.isArray(question2.choices) ? question2.choices : [],
      ...value(question2, "items", [])
    ];
    return ids.flatMap((id) => {
      const match = candidates.find((candidate) => candidate.id === id);
      return match ? [match.text] : [];
    });
  }
  function renderOwnAnswer(question2, answer, context) {
    const section = liveElement("section", "quizgeist-study-solution__own");
    section.append(liveElement("h4", "", {
      text: context.text("selfstudy:review:ownanswer", "Deine Antwort")
    }));
    let lines = [];
    if ("choiceIds" in answer) {
      lines = answerLabels(question2, answer.choiceIds);
    } else if (answer.kind === "order") {
      lines = answerLabels(question2, answer.orderIds);
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
  function renderQuestionSolution(question2, context) {
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
      root.append(renderOwnAnswer(question2, context.answer, context));
    }
    const solution = liveElement("section", "quizgeist-study-solution__canonical");
    solution.append(liveElement("h4", "", {
      text: context.text("selfstudy:review:correctanswer", "Richtige Antwort")
    }));
    let hasSolution = false;
    const correctChoices = question2.choices.filter((choice) => choice.correct === true);
    if (correctChoices.length > 0) {
      const list = liveElement("ul", "quizgeist-study-solution__list");
      correctChoices.forEach((choice) => {
        const item = liveElement("li", "", { text: choice.text });
        const media = createLiveMedia(choice, {
          allowPlayback: false,
          className: "quizgeist-study-solution__media",
          label: choice.text
        });
        if (media) {
          item.append(media);
        }
        list.append(item);
      });
      solution.append(list);
      hasSolution = true;
    }
    const acceptedAnswers = value(question2, "acceptedAnswers", []).filter((entry) => typeof entry === "string" && entry.trim() !== "");
    if (acceptedAnswers.length > 0) {
      const list = liveElement("ul", "quizgeist-study-solution__list");
      acceptedAnswers.forEach((entry) => {
        list.append(liveElement("li", "", { text: entry }));
      });
      solution.append(list);
      hasSolution = true;
    }
    const correctOrder = value(question2, "correctOrderIds", []);
    if (correctOrder.length > 0) {
      const labels = answerLabels(question2, correctOrder);
      if (labels.length === correctOrder.length) {
        const list = liveElement("ol", "quizgeist-study-solution__list");
        labels.forEach((entry) => list.append(liveElement("li", "", { text: entry })));
        solution.append(list);
        hasSolution = true;
      }
    }
    const target = value(question2, "target", null);
    if (typeof target === "number" && Number.isFinite(target)) {
      const tolerance = Number(value(question2, "tolerance", 0));
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
        const radius = Number(value(question2, "radius", 0));
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
    const sampleAnswer = value(question2, "sampleAnswer", "").trim();
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
  function aggregateRecord(aggregate) {
    return aggregate && !Array.isArray(aggregate) && typeof aggregate === "object" ? aggregate : {};
  }
  function objectRows(raw) {
    return Array.isArray(raw) ? raw.filter((entry) => Boolean(entry && typeof entry === "object" && !Array.isArray(entry))) : [];
  }

  // src/live/strings.ts
  var MISSING_STRING_PLACEHOLDER = "\u2026";
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
  function liveString(strings, key, values = {}, fallback = "") {
    const configured = strings[key];
    let template;
    if (typeof configured === "string" && configured !== "") {
      template = configured;
    } else {
      reportMissingString(key);
      template = fallback !== "" ? fallback : MISSING_STRING_PLACEHOLDER;
    }
    return template.replace(/\{\$a->([a-zA-Z0-9_]+)\}/g, (_match, name) => {
      const value2 = values[name];
      return value2 === void 0 ? "" : String(value2);
    }).replace(/\{\$a\}/g, () => {
      const value2 = values.a;
      return value2 === void 0 ? "" : String(value2);
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

  // src/live/spoken-answer.ts
  var POLL_INTERVAL_MS = 1500;
  var POLL_ATTEMPTS = 40;
  var SpokenAnswerControl = class {
    constructor(strings, config, callbacks) {
      this.strings = strings;
      this.config = config;
      this.callbacks = callbacks;
      __publicField(this, "root");
      __publicField(this, "button");
      __publicField(this, "status");
      __publicField(this, "hint");
      __publicField(this, "recorder");
      __publicField(this, "clip", null);
      __publicField(this, "polling", false);
      __publicField(this, "disposed", false);
      this.root = liveElement("div", "quizgeist-spoken");
      this.button = liveButton(
        this.text("clip:record:start", "Antwort aufnehmen"),
        "quizgeist-spoken__button quizgeist-touch-target",
        { "aria-describedby": "" }
      );
      this.status = liveElement("p", "quizgeist-spoken__status", {
        // The one place the whole feature reports itself to assistive
        // technology. `polite` and not `assertive`: a transcript arriving must
        // not cut into what a learner is currently reading.
        "aria-live": "polite",
        role: "status"
      });
      this.hint = liveElement("p", "quizgeist-spoken__hint", {
        text: this.text(
          "clip:languagehint",
          "Spracherkennung derzeit nur Deutsch."
        )
      });
      this.recorder = new ClipRecorder(
        { maxBytes: config.maxBytes, maxSeconds: config.maxSeconds },
        (state) => this.renderState(state)
      );
      this.button.addEventListener("click", () => {
        void this.toggle();
      });
      this.root.append(this.button, this.status, this.hint);
      if (!recordingSupported()) {
        this.button.disabled = true;
        this.button.textContent = this.text(
          "clip:record:unavailable",
          "Aufnahme auf diesem Ger\xE4t nicht m\xF6glich"
        );
        this.setStatus(this.text(
          "clip:record:usetext",
          "Bitte tippe deine Antwort ein."
        ));
      }
    }
    /**
     * Whether this browser could record at all.
     */
    static available() {
      return recordingSupported();
    }
    /**
     * The clip this control produced, if any.
     */
    getClip() {
      return this.clip;
    }
    /**
     * Stop everything and release the microphone.
     */
    dispose() {
      this.disposed = true;
      this.recorder.cancel();
    }
    text(key, fallback) {
      return liveString(this.strings, key, {}, fallback);
    }
    setStatus(message) {
      this.status.textContent = message;
    }
    renderState(state) {
      switch (state) {
        case "requesting":
          this.button.disabled = true;
          this.setStatus(this.text("clip:record:requesting", "Mikrofon wird angefragt \u2026"));
          break;
        case "recording":
          this.button.disabled = false;
          this.button.textContent = this.text("clip:record:stop", "Aufnahme beenden");
          this.button.classList.add("is-recording");
          this.setStatus(this.text("clip:record:running", "Aufnahme l\xE4uft."));
          break;
        case "stopping":
          this.button.disabled = true;
          this.button.classList.remove("is-recording");
          this.setStatus(this.text("clip:record:stopping", "Aufnahme wird abgeschlossen \u2026"));
          break;
        case "unavailable":
          this.button.disabled = true;
          this.setStatus(this.text(
            "clip:record:usetext",
            "Bitte tippe deine Antwort ein."
          ));
          break;
        default:
          this.button.disabled = false;
          this.button.classList.remove("is-recording");
          this.button.textContent = this.text("clip:record:start", "Antwort aufnehmen");
          break;
      }
    }
    async toggle() {
      if (this.recorder.getState() === "recording") {
        await this.finish();
        return;
      }
      try {
        await this.recorder.start();
      } catch {
        this.setStatus(this.text(
          "clip:record:denied",
          "Ohne Mikrofon geht es auch: tippe deine Antwort ein."
        ));
      }
    }
    async finish() {
      let recording;
      try {
        recording = await this.recorder.stop();
      } catch {
        this.setStatus(this.text("clip:record:failed", "Die Aufnahme hat nicht geklappt."));
        return;
      }
      if (recording.blob.size === 0) {
        this.setStatus(this.text("clip:error:empty", "Es wurde nichts aufgenommen."));
        return;
      }
      if (recording.blob.size > this.config.maxBytes) {
        this.setStatus(this.text("clip:error:toolarge", "Die Aufnahme ist zu gro\xDF."));
        return;
      }
      this.setStatus(this.text("clip:upload:running", "Aufnahme wird gesendet \u2026"));
      let clip;
      try {
        clip = await uploadClip(
          this.config.uploadUrl,
          this.config.sesskey,
          this.config.cmid,
          this.config.purpose,
          this.config.language,
          recording
        );
      } catch (error) {
        const message = error instanceof Error && error.message !== "" ? error.message : this.text("clip:error:uploadfailed", "Die Aufnahme konnte nicht gesendet werden.");
        this.setStatus(message);
        return;
      }
      this.clip = clip;
      this.callbacks.onClip(clip);
      if (!this.config.transcriptionAvailable || this.callbacks.transcribe === void 0) {
        this.setStatus(this.text(
          "clip:transcript:unavailable",
          "Aufnahme gespeichert. Eine Verschriftung ist hier nicht verf\xFCgbar."
        ));
        return;
      }
      this.setStatus(this.text("clip:transcript:pending", "Wird verschriftet \u2026"));
      try {
        const updated = await this.callbacks.transcribe(clip.id);
        this.applyClip(updated);
        if (updated.transcriptState === "pending") {
          await this.pollUntilSettled(updated.id);
        }
      } catch {
        this.setStatus(this.text(
          "clip:transcript:failed",
          "Die Verschriftung hat nicht geklappt. Deine Aufnahme ist gespeichert."
        ));
      }
    }
    /**
     * Ask the server for the state until it settles.
     *
     * Bounded repetition of a cheap read — the polling rule of HOUSE_RULES taken
     * literally. No socket, no server-sent event, no long poll.
     */
    async pollUntilSettled(clipId) {
      if (this.callbacks.pollState === void 0 || this.polling) {
        return;
      }
      this.polling = true;
      try {
        for (let attempt = 0; attempt < POLL_ATTEMPTS; attempt += 1) {
          if (this.disposed) {
            return;
          }
          await new Promise((resolve) => {
            setTimeout(resolve, POLL_INTERVAL_MS);
          });
          const clips = await this.callbacks.pollState([clipId]);
          const found = clips.find((candidate) => candidate.id === clipId);
          if (found === void 0) {
            continue;
          }
          this.applyClip(found);
          if (found.transcriptState !== "pending") {
            return;
          }
        }
        this.setStatus(this.text(
          "clip:transcript:slow",
          "Die Verschriftung dauert noch. Deine Antwort ist trotzdem abgegeben."
        ));
      } finally {
        this.polling = false;
      }
    }
    applyClip(clip) {
      var _a, _b, _c;
      this.clip = clip;
      switch (clip.transcriptState) {
        case "done":
          this.setStatus(this.text("clip:transcript:done", "Verschriftet."));
          (_c = (_b = this.callbacks).onTranscript) == null ? void 0 : _c.call(_b, (_a = clip.transcript) != null ? _a : "", clip);
          break;
        case "failed":
          this.setStatus(this.text(
            "clip:transcript:failed",
            "Die Verschriftung hat nicht geklappt. Deine Aufnahme ist gespeichert."
          ));
          break;
        case "pending":
          this.setStatus(this.text("clip:transcript:pending", "Wird verschriftet \u2026"));
          break;
        default:
          this.setStatus(this.text("clip:upload:done", "Aufnahme gespeichert."));
          break;
      }
    }
  };

  // src/live/stage-check.ts
  var BUNDLE_CONTRACT = 1;
  var METRIC_KEYS = [
    "eyecontactpct",
    "gesturescore",
    "gestureactivpct",
    "posturescore",
    "movementscore",
    "visibilitypct"
  ];
  var bundlePromise = null;
  function element(tag, className = "", text4) {
    const node = document.createElement(tag);
    if (className !== "") {
      node.className = className;
    }
    if (text4 !== void 0) {
      node.textContent = text4;
    }
    return node;
  }
  function loadStageBundle(url) {
    var _a;
    if (((_a = window.QuizgeistStageApp) == null ? void 0 : _a.contract) === BUNDLE_CONTRACT) {
      return Promise.resolve(window.QuizgeistStageApp);
    }
    if (bundlePromise !== null) {
      return bundlePromise;
    }
    bundlePromise = new Promise((resolve) => {
      const script = document.createElement("script");
      script.src = url;
      script.async = true;
      script.dataset.quizgeistBundle = "app_stage";
      script.addEventListener("load", () => {
        const bundle = window.QuizgeistStageApp;
        resolve(bundle && bundle.contract === BUNDLE_CONTRACT ? bundle : null);
      });
      script.addEventListener("error", () => {
        bundlePromise = null;
        resolve(null);
      });
      document.head.append(script);
    });
    return bundlePromise;
  }
  function clock(seconds) {
    const total = Math.max(0, Math.round(seconds));
    const minutes = Math.floor(total / 60);
    return `${minutes}:${String(total % 60).padStart(2, "0")}`;
  }
  var StageCheckControl = class {
    constructor(config, questionId, answerId, onSubmitted) {
      this.config = config;
      this.questionId = questionId;
      this.answerId = answerId;
      this.onSubmitted = onSubmitted;
      __publicField(this, "root");
      __publicField(this, "api");
      __publicField(this, "video");
      __publicField(this, "hintLine");
      __publicField(this, "statusLine");
      __publicField(this, "clockLine");
      __publicField(this, "startButton");
      __publicField(this, "stopButton");
      __publicField(this, "modeSelect");
      __publicField(this, "reportBox");
      __publicField(this, "noticeLine");
      __publicField(this, "engine", null);
      __publicField(this, "session", null);
      __publicField(this, "recording", false);
      __publicField(this, "busy", false);
      __publicField(this, "lastHint", "");
      this.api = new LiveApi(config);
      this.root = element("section", "quizgeist-stage");
      this.root.dataset.quizgeistView = "stage-setup";
      const heading = element("h3", "quizgeist-stage__title", this.s("stage:title"));
      const intro = element("p", "quizgeist-stage__intro", this.s("stage:intro"));
      this.noticeLine = element("p", "quizgeist-stage__notice", this.s("stage:privacy"));
      this.video = document.createElement("video");
      this.video.className = "quizgeist-stage__video";
      this.video.setAttribute("playsinline", "");
      this.video.setAttribute("muted", "");
      this.video.setAttribute("aria-label", this.s("stage:videolabel"));
      const frame = element("div", "quizgeist-stage__frame");
      frame.append(this.video);
      this.hintLine = element("p", "quizgeist-stage__hint", this.s("stage:hint:idle"));
      this.hintLine.setAttribute("aria-live", "polite");
      this.hintLine.setAttribute("role", "status");
      this.clockLine = element("p", "quizgeist-stage__clock", "");
      this.clockLine.setAttribute("aria-live", "off");
      this.statusLine = element("p", "quizgeist-stage__status", "");
      this.statusLine.setAttribute("aria-live", "polite");
      this.statusLine.setAttribute("role", "status");
      const modeLabel2 = element(
        "label",
        "quizgeist-stage__mode-label",
        this.s("stage:mode")
      );
      this.modeSelect = document.createElement("select");
      this.modeSelect.className = "quizgeist-stage__mode quizgeist-touch-target";
      this.modeSelect.id = `quizgeist-stage-mode-${this.questionId}`;
      for (const mode2 of ["stehen", "sitzen"]) {
        const option = document.createElement("option");
        option.value = mode2;
        option.textContent = this.s(`stage:mode:${mode2}`);
        this.modeSelect.append(option);
      }
      modeLabel2.setAttribute("for", this.modeSelect.id);
      this.startButton = document.createElement("button");
      this.startButton.type = "button";
      this.startButton.className = "quizgeist-stage__start quizgeist-player-button quizgeist-touch-target";
      this.startButton.textContent = this.s("stage:action:start");
      this.startButton.addEventListener("click", () => {
        void this.start();
      });
      this.stopButton = document.createElement("button");
      this.stopButton.type = "button";
      this.stopButton.className = "quizgeist-stage__stop quizgeist-player-button quizgeist-touch-target";
      this.stopButton.textContent = this.s("stage:action:stop");
      this.stopButton.hidden = true;
      this.stopButton.addEventListener("click", () => {
        void this.finish();
      });
      const controls = element("div", "quizgeist-stage__controls");
      controls.append(modeLabel2, this.modeSelect, this.startButton, this.stopButton);
      this.reportBox = element("div", "quizgeist-stage__report");
      const skip = document.createElement("button");
      skip.type = "button";
      skip.className = "quizgeist-stage__skip quizgeist-player-button quizgeist-touch-target";
      skip.textContent = this.s("stage:action:skip");
      skip.addEventListener("click", () => {
        this.close();
        this.onSubmitted({
          id: 0,
          questionId: this.questionId,
          answerId: this.answerId,
          durationSecs: 0,
          aiUsed: false,
          metrics: null,
          feedback: [],
          timeCreated: 0
        });
      });
      this.root.append(
        heading,
        intro,
        this.noticeLine,
        frame,
        this.hintLine,
        this.clockLine,
        controls,
        this.statusLine,
        this.reportBox,
        skip
      );
    }
    /**
     * Ask the server for the run parameters and render the setup surface.
     *
     * [P11-E2]: `stage_start` belongs to the buehne addon. Whether the addon is
     * there is something the SERVER says — in the bootstrap flag and in the
     * stage configuration, which view.php only emits for an installed addon.
     * Without that report nothing is asked at all. The same condition already
     * guarded start(); asking first and only then noticing would produce a 404
     * that no browser console forgets.
     */
    async init() {
      var _a, _b;
      if (((_b = (_a = this.config.features) == null ? void 0 : _a.buehne) == null ? void 0 : _b.installed) !== true || this.config.stage === void 0) {
        this.statusLine.textContent = this.s("stage:error:notavailable");
        this.startButton.disabled = true;
        return;
      }
      try {
        this.session = await this.api.post("stage_start", {
          questionId: this.questionId
        });
      } catch (error) {
        this.fail(error);
        return;
      }
      if (!this.session.canSave) {
        this.noticeLine.textContent = this.s("stage:notice:readonly");
        this.noticeLine.classList.add("quizgeist-stage__notice--warning");
      }
      this.renderReports(this.session.reports);
    }
    /** Release camera and engine. Safe to call more than once. */
    close() {
      var _a;
      this.recording = false;
      (_a = this.engine) == null ? void 0 : _a.close();
      this.engine = null;
    }
    s(key, values = {}, fallback = "") {
      return liveString(this.config.strings, key, values, fallback);
    }
    async start() {
      if (this.busy || this.session === null) {
        return;
      }
      this.busy = true;
      this.startButton.disabled = true;
      this.statusLine.textContent = this.s("stage:status:starting");
      const stage = this.config.stage;
      if (stage === void 0) {
        this.statusLine.textContent = this.s("stage:error:notavailable");
        this.busy = false;
        return;
      }
      const bundle = await loadStageBundle(stage.bundleUrl);
      if (bundle === null) {
        this.statusLine.textContent = this.s("stage:error:enginemissing");
        this.startButton.disabled = false;
        this.busy = false;
        return;
      }
      this.engine = bundle.createStageEngine({
        assetsUrl: stage.assetsUrl,
        video: this.video,
        mode: this.modeSelect.value === "sitzen" ? "sitzen" : "stehen",
        allowGpu: this.session.allowGpu,
        maxSeconds: this.session.maxSeconds,
        onHint: (check) => this.showHint(check),
        onTick: (elapsed) => this.showClock(elapsed),
        onFailure: (code) => this.showFailure(code)
      });
      const delegate = await this.engine.start();
      this.root.dataset.quizgeistView = "stage-live";
      this.root.dataset.stageDelegate = delegate;
      this.engine.beginRecording();
      this.recording = true;
      this.startButton.hidden = true;
      this.stopButton.hidden = false;
      this.modeSelect.disabled = true;
      this.statusLine.textContent = this.s("stage:status:running");
      this.stopButton.focus();
      this.busy = false;
    }
    async finish() {
      var _a;
      if (this.busy || !this.recording || this.engine === null) {
        return;
      }
      this.busy = true;
      this.recording = false;
      this.stopButton.disabled = true;
      const result = this.engine.finishRecording();
      this.close();
      this.statusLine.textContent = this.s("stage:status:saving");
      if (this.session !== null && !this.session.canSave) {
        this.root.dataset.quizgeistView = "stage-report";
        this.statusLine.textContent = this.s("stage:notice:readonly");
        this.busy = false;
        return;
      }
      try {
        const response = await this.api.post("stage_finish", {
          questionId: this.questionId,
          answerId: (_a = this.answerId) != null ? _a : 0,
          durationSecs: result.durationSecs,
          metrics: result.metrics
        });
        this.root.dataset.quizgeistView = "stage-report";
        this.statusLine.textContent = this.s("stage:status:saved");
        this.renderReports(response.reports);
        this.onSubmitted(response.report);
      } catch (error) {
        this.fail(error);
      }
      this.busy = false;
    }
    showHint(check) {
      const key = `stage:hint:${check.hint}`;
      if (key === this.lastHint) {
        return;
      }
      this.lastHint = key;
      this.hintLine.textContent = this.s(key);
      this.hintLine.dataset.stageHint = check.hint;
    }
    showClock(elapsed) {
      var _a, _b;
      const maximum = (_b = (_a = this.session) == null ? void 0 : _a.maxSeconds) != null ? _b : 180;
      this.clockLine.textContent = this.s("stage:clock", {
        elapsed: clock(elapsed),
        total: clock(maximum)
      });
      if (elapsed >= maximum) {
        void this.finish();
      }
    }
    showFailure(code) {
      const known = ["camera_denied", "camera_missing", "engine_unavailable"];
      const key = known.includes(code) ? `stage:failure:${code}` : "stage:failure:engine_unavailable";
      this.statusLine.textContent = this.s(key);
      this.startButton.hidden = false;
      this.startButton.disabled = false;
      this.stopButton.hidden = true;
    }
    fail(error) {
      const message = error instanceof LiveApiError && error.message !== "" ? error.message : this.s("stage:error:generic");
      this.statusLine.textContent = message;
      this.statusLine.setAttribute("role", "alert");
      this.startButton.disabled = false;
    }
    renderReports(reports) {
      var _a;
      this.reportBox.replaceChildren();
      if (reports.length === 0) {
        return;
      }
      const latest = reports[0];
      const heading = element(
        "h4",
        "quizgeist-stage__report-title",
        this.s("stage:report:title")
      );
      const list = element("ul", "quizgeist-stage__metrics");
      for (const key of METRIC_KEYS) {
        const value2 = latest.metrics === null ? null : Number((_a = latest.metrics[key]) != null ? _a : 0);
        const item = element("li", "quizgeist-stage__metric");
        item.append(
          element("span", "quizgeist-stage__metric-label", this.s(`stage:metric:${key}`)),
          element(
            "span",
            "quizgeist-stage__metric-value",
            value2 === null ? "\u2014" : `${Math.round(value2)} %`
          )
        );
        item.dataset.stageMetric = key;
        list.append(item);
      }
      const coach = element("ul", "quizgeist-stage__coach");
      for (const code of latest.feedback) {
        coach.append(element("li", "quizgeist-stage__coach-item", this.s(code)));
      }
      this.reportBox.append(heading, list);
      if (latest.feedback.length > 0) {
        this.reportBox.append(coach);
      }
    }
  };

  // src/live/sound-engine.ts
  var STORAGE_KEY = "mod_quizgeist:live-sound";
  var SoundEngine = class {
    constructor() {
      __publicField(this, "context", null);
      __publicField(this, "master", null);
      __publicField(this, "lobbyTimer", null);
      __publicField(this, "lobbyBeat", 0);
      __publicField(this, "muted", false);
      __publicField(this, "volume", 0.55);
      // F1: the activity-wide off switch. It is decided on the server and can
      // never be re-enabled from the client — unlike the personal mute button.
      __publicField(this, "allowed", true);
      try {
        const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}");
        this.muted = stored.muted === true;
        if (Number.isFinite(stored.volume)) {
          this.volume = Math.max(0, Math.min(1, Number(stored.volume)));
        }
      } catch (_error) {
      }
    }
    isMuted() {
      return this.muted || !this.allowed;
    }
    /**
     * Apply the activity-wide sound switch (F1 stress-free standard).
     *
     * With sounds disallowed the engine stays silent regardless of the
     * learner's personal setting, and the controls disappear.
     */
    setAllowed(allowed) {
      this.allowed = allowed;
      if (!allowed) {
        this.stopLobby();
      }
      this.applyGain();
    }
    isAllowed() {
      return this.allowed;
    }
    getVolume() {
      return this.volume;
    }
    async unlock() {
      if (!this.context) {
        const Constructor = window.AudioContext || window.webkitAudioContext;
        if (!Constructor) {
          return false;
        }
        this.context = new Constructor();
        this.master = this.context.createGain();
        this.master.connect(this.context.destination);
        this.applyGain();
      }
      if (this.context.state === "suspended") {
        try {
          await this.context.resume();
        } catch (_error) {
          return false;
        }
      }
      return this.context.state === "running";
    }
    setMuted(muted) {
      this.muted = muted;
      this.applyGain();
      this.persist();
    }
    setVolume(volume) {
      this.volume = Math.max(0, Math.min(1, volume));
      this.applyGain();
      this.persist();
    }
    startLobby() {
      if (this.lobbyTimer !== null || !this.context || !this.allowed) {
        return;
      }
      const beat = () => {
        if (!this.context || this.context.state !== "running") {
          return;
        }
        const chord = [261.63, 329.63, 392];
        chord.forEach((frequency, index) => {
          this.tone(frequency, 2.35, "triangle", 0.018, index * 0.025, 1.1);
        });
        if (this.lobbyBeat % 2 === 0) {
          const melody = [523.25, 587.33, 659.25, 783.99, 659.25, 587.33];
          this.tone(
            melody[this.lobbyBeat / 2 % melody.length],
            0.18,
            "sine",
            0.035,
            0.05,
            0.12
          );
        }
        this.lobbyBeat += 1;
      };
      beat();
      this.lobbyTimer = window.setInterval(beat, 2600);
    }
    stopLobby() {
      if (this.lobbyTimer !== null) {
        window.clearInterval(this.lobbyTimer);
        this.lobbyTimer = null;
      }
    }
    play(preset) {
      if (!this.allowed || !this.context || !this.master || this.context.state !== "running") {
        return;
      }
      switch (preset) {
        case "tap":
          this.tone(660, 0.035, "sine", 0.04, 0, 0.018);
          break;
        case "countdown":
          this.tone(220, 0.09, "square", 0.035, 0, 0.06, 1200);
          break;
        case "go":
          this.sweep(440, 660, 0.18, "square", 0.075, 1200);
          break;
        case "correct":
          [523.25, 659.25, 783.99, 1046.5].forEach((frequency, index) => {
            this.tone(frequency, 0.22, index % 2 ? "triangle" : "sine", 0.065, index * 0.09, 0.12);
          });
          break;
        case "incorrect":
          this.tone(329.63, 0.22, "sine", 0.045, 0, 0.15, 900);
          this.tone(261.63, 0.25, "sine", 0.04, 0.14, 0.15, 900);
          break;
        case "podium":
          [349.23, 392, 440, 523.25].forEach((root, index) => {
            [1, 1.25, 1.5].forEach((ratio) => {
              this.tone(root * ratio, index === 3 ? 0.7 : 0.36, "sawtooth", 0.03, index * 0.3, 0.32, 2500);
            });
          });
          break;
        case "streak":
          this.sweep(440, 880, 0.35, "triangle", 0.06);
          break;
        case "warning":
          this.tone(220, 0.06, "sine", 0.045, 0, 0.04);
          this.tone(220, 0.06, "sine", 0.045, 0.14, 0.04);
          break;
      }
    }
    destroy() {
      var _a;
      this.stopLobby();
      (_a = this.context) == null ? void 0 : _a.close().catch(() => void 0);
      this.context = null;
      this.master = null;
    }
    tone(frequency, duration, waveform, level, delay = 0, release = 0.1, lowpass = 0) {
      if (!this.context || !this.master) {
        return;
      }
      const start = this.context.currentTime + delay;
      const oscillator = this.context.createOscillator();
      const gain = this.context.createGain();
      oscillator.type = waveform;
      oscillator.frequency.setValueAtTime(frequency, start);
      gain.gain.setValueAtTime(1e-4, start);
      gain.gain.exponentialRampToValueAtTime(Math.max(1e-4, level), start + 8e-3);
      gain.gain.exponentialRampToValueAtTime(1e-4, start + duration + release);
      if (lowpass > 0) {
        const filter = this.context.createBiquadFilter();
        filter.type = "lowpass";
        filter.frequency.setValueAtTime(lowpass, start);
        oscillator.connect(filter);
        filter.connect(gain);
      } else {
        oscillator.connect(gain);
      }
      gain.connect(this.master);
      oscillator.start(start);
      oscillator.stop(start + duration + release + 0.02);
    }
    sweep(from, to, duration, waveform, level, lowpass = 0) {
      if (!this.context || !this.master) {
        return;
      }
      const start = this.context.currentTime;
      const oscillator = this.context.createOscillator();
      const gain = this.context.createGain();
      oscillator.type = waveform;
      oscillator.frequency.setValueAtTime(from, start);
      oscillator.frequency.exponentialRampToValueAtTime(to, start + duration);
      gain.gain.setValueAtTime(1e-4, start);
      gain.gain.exponentialRampToValueAtTime(level, start + 8e-3);
      gain.gain.exponentialRampToValueAtTime(1e-4, start + duration + 0.1);
      if (lowpass > 0) {
        const filter = this.context.createBiquadFilter();
        filter.type = "lowpass";
        filter.frequency.value = lowpass;
        oscillator.connect(filter);
        filter.connect(gain);
      } else {
        oscillator.connect(gain);
      }
      gain.connect(this.master);
      oscillator.start(start);
      oscillator.stop(start + duration + 0.12);
    }
    applyGain() {
      if (this.master && this.context) {
        this.master.gain.setTargetAtTime(
          this.isMuted() ? 0 : this.volume,
          this.context.currentTime,
          0.015
        );
      }
    }
    persist() {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
          muted: this.muted,
          volume: this.volume
        }));
      } catch (_error) {
      }
    }
  };
  function createSoundControls(engine, strings) {
    const wrapper = liveElement("div", "quizgeist-live-sound", {
      "data-live-sound-controls": true
    });
    if (!engine.isAllowed()) {
      wrapper.hidden = true;
      return wrapper;
    }
    const muteLabel = strings["live:sound:mute"] || "Ton aus";
    const unmuteLabel = strings["live:sound:unmute"] || "Ton an";
    const mute = liveButton(
      engine.isMuted() ? unmuteLabel : muteLabel,
      "quizgeist-live-sound__mute",
      {
        "aria-pressed": engine.isMuted() ? "true" : "false",
        "data-live-sound-mute": true
      }
    );
    const volume = liveElement("input", "quizgeist-live-sound__volume", {
      "aria-label": strings["live:sound:label"] || "Lautst\xE4rke",
      "data-live-sound-volume": true,
      max: "100",
      min: "0",
      step: "5",
      type: "range",
      value: String(Math.round(engine.getVolume() * 100))
    });
    mute.addEventListener("click", () => {
      void engine.unlock();
      engine.setMuted(!engine.isMuted());
      mute.textContent = engine.isMuted() ? unmuteLabel : muteLabel;
      mute.setAttribute("aria-pressed", engine.isMuted() ? "true" : "false");
    });
    volume.addEventListener("input", () => {
      void engine.unlock();
      engine.setVolume(Number(volume.value) / 100);
      if (engine.getVolume() > 0 && engine.isMuted()) {
        engine.setMuted(false);
        mute.textContent = muteLabel;
        mute.setAttribute("aria-pressed", "false");
      }
    });
    wrapper.append(mute, volume);
    return wrapper;
  }

  // src/live/stage-mode.ts
  var StageModeController = class {
    constructor(root, hooks, options = {}) {
      this.root = root;
      this.hooks = hooks;
      __publicField(this, "document");
      __publicField(this, "available");
      __publicField(this, "options");
      __publicField(this, "exactRoot");
      __publicField(this, "focusOnlyOwnChange");
      __publicField(this, "avoidInputFocus");
      __publicField(this, "toggleButton", null);
      __publicField(this, "attached", false);
      __publicField(this, "_mode", "off");
      __publicField(this, "rootSessionActive", false);
      __publicField(this, "pendingEnter", false);
      __publicField(this, "pendingLeave", false);
      __publicField(this, "ownEnterPending", false);
      __publicField(this, "ownLeavePending", false);
      __publicField(this, "leaveAfterEnter", false);
      __publicField(this, "handleFullscreenChange", () => {
        this.syncFromDocument();
      });
      __publicField(this, "handleFullscreenError", () => {
        if (this.pendingEnter && !this.isActive()) {
          this.failPendingEnter();
          return;
        }
        if (this.pendingLeave) {
          this.pendingLeave = false;
          this.ownLeavePending = false;
        }
      });
      var _a, _b, _c;
      this.options = options;
      this.exactRoot = (_a = options.exactRoot) != null ? _a : false;
      this.focusOnlyOwnChange = (_b = options.focusOnlyOwnChange) != null ? _b : false;
      this.avoidInputFocus = (_c = options.avoidInputFocus) != null ? _c : false;
      this.document = root.ownerDocument;
      const fullscreenRoot = root;
      this.available = (this.document.fullscreenEnabled === true || this.document.webkitFullscreenEnabled === true) && (typeof root.requestFullscreen === "function" || typeof fullscreenRoot.webkitRequestFullscreen === "function");
    }
    mode() {
      return this._mode;
    }
    isAvailable() {
      return this.available;
    }
    createToggle() {
      var _a, _b, _c, _d, _e;
      const label = this.hooks.text(
        (_a = this.options.toggleKey) != null ? _a : "host:stagemode:toggle",
        (_b = this.options.toggleFallback) != null ? _b : "Vollbild"
      );
      const button2 = this.document.createElement("button");
      button2.type = "button";
      button2.className = (_c = this.options.buttonClassName) != null ? _c : "quizgeist-host-button quizgeist-host-button--secondary quizgeist-host-toolbar__stagemode";
      button2.textContent = label;
      button2.title = this.options.titleKey !== void 0 || this.options.titleFallback !== void 0 ? this.hooks.text(
        (_d = this.options.titleKey) != null ? _d : "host:stagemode:title",
        (_e = this.options.titleFallback) != null ? _e : "Vollbild ein- und ausschalten"
      ) : label === "Vollbild" ? "Vollbild ein- und ausschalten" : label;
      button2.setAttribute("aria-pressed", this._mode === "fullscreen" ? "true" : "false");
      button2.hidden = !this.available;
      button2.addEventListener("click", () => {
        void this.toggle();
      });
      this.toggleButton = button2;
      return button2;
    }
    attach() {
      if (this.attached) {
        return;
      }
      this.attached = true;
      this.document.addEventListener("fullscreenchange", this.handleFullscreenChange);
      this.document.addEventListener("webkitfullscreenchange", this.handleFullscreenChange);
      this.document.addEventListener("fullscreenerror", this.handleFullscreenError);
    }
    detach() {
      if (!this.attached) {
        return;
      }
      this.document.removeEventListener("fullscreenchange", this.handleFullscreenChange);
      this.document.removeEventListener("webkitfullscreenchange", this.handleFullscreenChange);
      this.document.removeEventListener("fullscreenerror", this.handleFullscreenError);
      this.attached = false;
    }
    async enter() {
      var _a;
      if (!this.available || this.isActive()) {
        return;
      }
      this.pendingEnter = true;
      this.ownEnterPending = true;
      this.leaveAfterEnter = false;
      const fullscreenRoot = this.root;
      try {
        const request = typeof this.root.requestFullscreen === "function" ? this.root.requestFullscreen() : (_a = fullscreenRoot.webkitRequestFullscreen) == null ? void 0 : _a.call(fullscreenRoot);
        if (request && typeof request.then === "function") {
          await Promise.resolve(request);
          this.pendingEnter = false;
          if (this.leaveAfterEnter && this.isActive()) {
            void this.leave();
          }
        } else {
          await this.nextFrame();
          if (!this.isActive()) {
            this.failPendingEnter();
          } else {
            this.pendingEnter = false;
            if (this.leaveAfterEnter) {
              void this.leave();
            }
          }
        }
      } catch (_error) {
        this.failPendingEnter();
      }
    }
    async leave() {
      if (!this.isActive()) {
        if (this.pendingEnter || this.ownEnterPending) {
          this.leaveAfterEnter = true;
          this.ownLeavePending = true;
        }
        return;
      }
      this.pendingLeave = true;
      this.ownLeavePending = true;
      this.leaveAfterEnter = false;
      try {
        const exit = this.document.exitFullscreen || this.document.webkitExitFullscreen;
        if (!exit) {
          this.pendingLeave = false;
          this.ownLeavePending = false;
          return;
        }
        const result = exit.call(this.document);
        if (result && typeof result.then === "function") {
          await Promise.resolve(result);
          this.pendingLeave = false;
        } else {
          await this.nextFrame();
          this.pendingLeave = false;
        }
      } catch (_error) {
        this.pendingLeave = false;
        this.ownLeavePending = false;
      }
    }
    async toggle() {
      if (this.isActive()) {
        await this.leave();
      } else {
        await this.enter();
      }
    }
    fullscreenElement() {
      var _a, _b;
      return (_b = (_a = this.document.fullscreenElement) != null ? _a : this.document.webkitFullscreenElement) != null ? _b : null;
    }
    isActive() {
      const fullscreen = this.fullscreenElement();
      if (fullscreen === this.root) {
        return true;
      }
      return !this.exactRoot && fullscreen instanceof Node && this.root.contains(fullscreen);
    }
    async nextFrame() {
      await new Promise((resolve) => {
        var _a;
        const requestAnimationFrame = (_a = this.document.defaultView) == null ? void 0 : _a.requestAnimationFrame;
        if (requestAnimationFrame) {
          requestAnimationFrame(() => resolve());
        } else {
          resolve();
        }
      });
    }
    failPendingEnter() {
      var _a, _b;
      if (!this.pendingEnter) {
        return;
      }
      this.pendingEnter = false;
      this.ownEnterPending = false;
      this.ownLeavePending = false;
      this.leaveAfterEnter = false;
      if (this.isActive()) {
        return;
      }
      this.rootSessionActive = false;
      this.setMode("off");
      this.hooks.announce(this.hooks.text(
        (_a = this.options.offKey) != null ? _a : "host:stagemode:off",
        (_b = this.options.offFallback) != null ? _b : "Vollbild ausgeschaltet."
      ));
    }
    syncFromDocument() {
      var _a, _b, _c, _d;
      const fullscreen = this.fullscreenElement();
      const rootFullscreen = fullscreen === this.root;
      const active = this.isActive();
      const previousMode = this._mode;
      const nextMode = active ? "fullscreen" : "off";
      const ownEnter = this.ownEnterPending;
      const ownLeave = this.ownLeavePending;
      this.setMode(nextMode);
      if (rootFullscreen && active) {
        const enteredRoot = !this.rootSessionActive;
        this.rootSessionActive = true;
        this.pendingEnter = false;
        this.pendingLeave = false;
        this.ownEnterPending = false;
        this.ownLeavePending = false;
        if (this.leaveAfterEnter) {
          this.leaveAfterEnter = false;
          void this.leave();
          return;
        }
        if (enteredRoot && previousMode === "off") {
          this.announceAndFocus(
            (_a = this.options.onKey) != null ? _a : "host:stagemode:on",
            (_b = this.options.onFallback) != null ? _b : "Vollbild eingeschaltet.",
            ownEnter
          );
        }
        return;
      }
      if (active) {
        this.pendingEnter = false;
        this.ownEnterPending = false;
        return;
      }
      this.pendingEnter = false;
      this.pendingLeave = false;
      this.ownEnterPending = false;
      this.leaveAfterEnter = false;
      if (previousMode === "fullscreen" && this.rootSessionActive) {
        this.rootSessionActive = false;
        this.ownLeavePending = false;
        this.announceAndFocus(
          (_c = this.options.offKey) != null ? _c : "host:stagemode:off",
          (_d = this.options.offFallback) != null ? _d : "Vollbild ausgeschaltet.",
          ownLeave
        );
      } else {
        this.rootSessionActive = false;
        this.ownLeavePending = false;
      }
    }
    announceAndFocus(key, fallback, ownChange) {
      var _a;
      this.hooks.announce(this.hooks.text(key, fallback));
      if (this.focusOnlyOwnChange && !ownChange) {
        return;
      }
      if (this.avoidInputFocus && this.isEditableFocusTarget()) {
        return;
      }
      if (((_a = this.toggleButton) == null ? void 0 : _a.isConnected) && !this.toggleButton.hidden) {
        this.toggleButton.focus();
        return;
      }
      if (ownChange) {
        this.focusFallbackTarget();
      }
    }
    updateToggle() {
      if (!this.toggleButton) {
        return;
      }
      this.toggleButton.setAttribute("aria-pressed", this._mode === "fullscreen" ? "true" : "false");
    }
    setMode(mode2) {
      var _a, _b;
      const changed = this._mode !== mode2;
      this._mode = mode2;
      this.updateToggle();
      if (changed) {
        (_b = (_a = this.options).onModeChange) == null ? void 0 : _b.call(_a, mode2);
      }
    }
    isEditableFocusTarget() {
      const active = this.document.activeElement;
      if (!(active instanceof HTMLElement)) {
        return false;
      }
      if (active.isContentEditable) {
        return true;
      }
      const tagName = active.tagName.toLowerCase();
      return tagName === "input" || tagName === "textarea" || tagName === "select" || active.closest("[contenteditable]") !== null;
    }
    focusFallbackTarget() {
      const fallback = this.options.focusFallback;
      if (!fallback) {
        return;
      }
      fallback();
    }
  };

  // src/live/tts.ts
  function text(config, key, fallback) {
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
        throw new Error(text(
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
        throw new Error(text(
          this.config,
          "live:tts:error",
          "Der Text konnte nicht vorgelesen werden."
        ));
      }
      const blob = await response.blob();
      this.controller = null;
      if (blob.size === 0) {
        throw new Error(text(
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
          () => finish(new Error(text(
            this.config,
            "live:tts:error",
            "Der Text konnte nicht vorgelesen werden."
          ))),
          { once: true }
        );
        (_c = this.audio) == null ? void 0 : _c.play().catch((error) => finish(
          error instanceof Error ? error : new Error(text(
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
    const playLabel = text(config, "live:tts:play", "Vorlesen");
    const stopLabel = text(config, "live:tts:stop", "Stoppen");
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
      button2.title = text(
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
          status.textContent = error instanceof Error ? error.message : text(config, "live:tts:error", "Der Text konnte nicht vorgelesen werden.");
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

  // src/play/player-app.ts
  var AVATAR_NAMES = {
    federchen: "Federchen",
    kiesel: "Kiesel",
    klecks: "Klecks",
    kubus: "Kubus",
    mondchen: "Mondchen",
    stern: "Stern",
    wirbel: "Wirbel",
    zweig: "Zweig"
  };
  function element2(tag, className = "", text4) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text4 !== void 0) {
      node.textContent = text4;
    }
    return node;
  }
  function button(className, text4, action) {
    const node = element2("button", className, text4);
    node.type = "button";
    node.dataset.action = action;
    return node;
  }
  function normaliseConfig(raw) {
    const cmid = Number(raw.cmid || 0);
    const ajaxUrl = typeof raw.ajaxUrl === "string" ? raw.ajaxUrl : "";
    const sesskey = typeof raw.sesskey === "string" ? raw.sesskey : "";
    if (!Number.isInteger(cmid) || cmid <= 0 || ajaxUrl === "" || sesskey === "") {
      return null;
    }
    const initialJoinCode = typeof raw.initialJoinCode === "string" && /^[0-9]{6}$/.test(raw.initialJoinCode) ? raw.initialJoinCode : "";
    return {
      ...raw,
      ajaxUrl,
      brandIconUrl: typeof raw.brandIconUrl === "string" ? raw.brandIconUrl : "",
      cmid,
      containerId: typeof raw.containerId === "string" && raw.containerId ? raw.containerId : "quizgeist-app-play",
      initialJoinCode,
      overviewUrl: typeof raw.overviewUrl === "string" ? raw.overviewUrl : "",
      playerUrlBase: typeof raw.playerUrlBase === "string" ? raw.playerUrlBase : "",
      sesskey,
      strings: raw.strings || {}
    };
  }
  var PlayerApp = class {
    constructor(root, config) {
      this.root = root;
      this.config = config;
      __publicField(this, "reconnectKey");
      __publicField(this, "api");
      __publicField(this, "answerMessage", "");
      __publicField(this, "clockId", null);
      __publicField(this, "connectionMessage", "");
      __publicField(this, "currentLookup", null);
      __publicField(this, "currentState", null);
      __publicField(this, "joinCode", "");
      __publicField(this, "liveRegion");
      __publicField(this, "poller");
      __publicField(this, "requestedQuestionDelivery", "");
      __publicField(this, "answerDraft", null);
      __publicField(this, "lastCountdownSecond", null);
      __publicField(this, "lastWarningSecond", null);
      __publicField(this, "scoreBurst", false);
      __publicField(this, "selectedAvatar", "kiesel");
      __publicField(this, "selectedAccessory", "");
      __publicField(this, "selectedTeamKey", "");
      __publicField(this, "serverClockOffsetMs", 0);
      __publicField(this, "submissionKeys", /* @__PURE__ */ new Map());
      /** Aktive Aufnahmesteuerungen; beim Neuzeichnen wird das Mikrofon frei. */
      __publicField(this, "spokenControls", []);
      __publicField(this, "sound", new SoundEngine());
      /** F13: the running stage check, so a screen change can release the camera. */
      __publicField(this, "stageCheck", null);
      __publicField(this, "stage");
      __publicField(this, "fullscreenBar");
      __publicField(this, "fullscreenToggle");
      __publicField(this, "stageMode");
      __publicField(this, "stageModeScreen", "loading");
      __publicField(this, "submitting", false);
      __publicField(this, "streakBurst", false);
      __publicField(this, "tts");
      __publicField(this, "unlockSound", () => {
        void this.sound.unlock().then(() => {
          var _a;
          if (((_a = this.currentState) == null ? void 0 : _a.phase) === "lobby") {
            this.sound.startLobby();
          }
        });
      });
      this.api = new LiveApi(config);
      this.tts = new TtsPlayer(config);
      this.reconnectKey = `mod_quizgeist:live-player:${config.cmid}`;
      this.liveRegion = element2("div", "quizgeist-live-visually-hidden");
      this.liveRegion.setAttribute("aria-atomic", "true");
      this.liveRegion.setAttribute("aria-live", "polite");
      this.liveRegion.setAttribute("role", "status");
      this.stage = element2("div", "quizgeist-player-stage");
      this.fullscreenBar = element2("div", "quizgeist-player-toolbar");
      this.fullscreenBar.dataset.playerToolbar = "";
      this.fullscreenBar.hidden = true;
      this.stageMode = new StageModeController(this.root, {
        announce: (message) => this.announceStageMode(message),
        text: (key, fallback) => this.s(key, {}, fallback)
      }, {
        exactRoot: true,
        buttonClassName: "quizgeist-player-toolbar__stagemode",
        toggleKey: "play:stagemode:toggle",
        toggleFallback: "Vollbild",
        titleKey: "play:stagemode:title",
        titleFallback: "Vollbild ein- und ausschalten",
        onKey: "play:stagemode:on",
        onFallback: "Vollbild eingeschaltet.",
        offKey: "play:stagemode:off",
        offFallback: "Vollbild ausgeschaltet.",
        focusOnlyOwnChange: true,
        avoidInputFocus: true,
        focusFallback: () => this.focusStageModeFallback(),
        onModeChange: (mode2) => this.syncStageModeVisibility(mode2)
      });
      this.fullscreenToggle = this.stageMode.createToggle();
      this.fullscreenBar.append(this.fullscreenToggle);
      this.poller = new AdaptivePoller(
        (signal) => this.poll(signal),
        (error, failures) => this.pollError(error, failures)
      );
    }
    async init() {
      this.root.classList.add("quizgeist-player-root");
      this.root.dataset.quizgeistRoot = "play";
      this.root.dataset.quizgeistTheme = this.config.theme || "hell";
      this.root.dataset.quizgeistSeason = this.config.season || "herbst";
      this.root.replaceChildren(this.liveRegion, this.fullscreenBar, this.stage);
      this.stageMode.attach();
      this.root.addEventListener("pointerdown", this.unlockSound, { once: true });
      this.renderLoading();
      try {
        const sessionId = this.storedSessionId();
        const result = await this.api.post(
          "live_player_bootstrap",
          sessionId ? { sessionId } : {}
        );
        if (result.state) {
          if (this.isTerminalPhase(result.state.phase)) {
            this.clearStoredSession();
            if (this.config.initialJoinCode) {
              this.currentState = null;
              await this.lookup(this.config.initialJoinCode);
              return;
            }
          }
          this.applyState(result.state, true);
          return;
        }
        if (this.config.initialJoinCode) {
          await this.lookup(this.config.initialJoinCode);
        } else {
          this.renderJoin();
        }
      } catch (error) {
        if (error instanceof LiveApiError && error.status === 404 && this.storedSessionId()) {
          this.clearStoredSession();
          if (this.config.initialJoinCode) {
            await this.lookup(this.config.initialJoinCode);
          } else {
            this.renderJoin();
          }
          return;
        }
        this.handleActionError(error, () => {
          void this.init();
        });
      }
    }
    s(key, values = {}, fallback = "") {
      return liveString(this.config.strings, key, values, fallback);
    }
    renderLoading() {
      const section = element2("section", "quizgeist-player-card quizgeist-player-loading");
      section.setAttribute("role", "status");
      section.append(
        this.brand(),
        element2("p", "quizgeist-player-loading__text", this.s("live:connection:loading"))
      );
      this.stage.replaceChildren(section);
      this.setStageModeScreen("loading");
    }
    brand() {
      const brand = element2("div", "quizgeist-live-brand");
      if (this.config.brandIconUrl) {
        const icon2 = element2("img", "quizgeist-live-brand__icon");
        icon2.src = this.config.brandIconUrl;
        icon2.alt = "";
        brand.append(icon2);
      }
      const wordmark = element2("span", "quizgeist-live-brand__wordmark");
      wordmark.append(
        element2("span", "quizgeist-live-brand__quiz", "Quiz"),
        element2("span", "quizgeist-live-brand__geist", "geist")
      );
      brand.append(wordmark);
      return brand;
    }
    renderJoin(message = "") {
      this.stopClock();
      this.setRootState("join", null);
      const card = element2("section", "quizgeist-player-card quizgeist-player-join");
      const heading = element2("h2", "quizgeist-player-title", this.s("play:join:title"));
      const description = element2("p", "quizgeist-player-copy", this.s("play:join:description"));
      const form = element2("form", "quizgeist-join-form");
      form.dataset.action = "lookup";
      const label = element2("label", "quizgeist-field-label", this.s("play:join:pin"));
      label.htmlFor = "quizgeist-join-code";
      const input = element2("input", "quizgeist-pin-input");
      input.id = "quizgeist-join-code";
      input.name = "joinCode";
      input.autocomplete = "one-time-code";
      input.inputMode = "numeric";
      input.maxLength = 6;
      input.pattern = "[0-9]{6}";
      input.required = true;
      input.value = this.joinCode;
      input.setAttribute("aria-describedby", "quizgeist-join-help quizgeist-join-error");
      input.addEventListener("input", () => {
        input.value = input.value.replace(/\D/g, "").slice(0, 6);
        this.joinCode = input.value;
        this.patchPinSlots(slots, input.value);
      });
      const slots = element2("div", "quizgeist-pin-slots");
      slots.id = "quizgeist-join-help";
      slots.setAttribute("aria-hidden", "true");
      this.patchPinSlots(slots, input.value);
      const error = element2("p", "quizgeist-form-error", message);
      error.id = "quizgeist-join-error";
      error.setAttribute("role", "alert");
      const submit = element2("button", "quizgeist-player-primary", this.s("play:join:submit"));
      submit.type = "submit";
      submit.dataset.action = "lookup-submit";
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        if (/^[0-9]{6}$/.test(input.value)) {
          void this.lookup(input.value);
        } else {
          error.textContent = this.s("play:join:notfound");
          input.focus();
        }
      });
      form.append(label, input, slots, this.numberPad(input, slots), error, submit);
      card.append(this.brand(), heading, description, form);
      this.stage.replaceChildren(card);
      this.setStageModeScreen("join");
      input.focus();
    }
    patchPinSlots(container, value2) {
      const slots = Array.from({ length: 6 }, (_unused, index) => {
        const slot = element2("span", "quizgeist-pin-slot", value2[index] || "\u2022");
        slot.classList.toggle("is-filled", index < value2.length);
        return slot;
      });
      container.replaceChildren(...slots);
    }
    numberPad(input, slots) {
      const pad = element2("div", "quizgeist-number-pad");
      ["1", "2", "3", "4", "5", "6", "7", "8", "9", "clear", "0", "back"].forEach((key) => {
        const label = key === "clear" ? "C" : key === "back" ? "\u232B" : key;
        const keyButton = button("quizgeist-number-key", label, `pin-${key}`);
        keyButton.setAttribute("aria-label", key === "clear" ? this.s("play:pin:clear") : key === "back" ? this.s("play:pin:back") : key);
        keyButton.addEventListener("click", () => {
          if (key === "clear") {
            input.value = "";
          } else if (key === "back") {
            input.value = input.value.slice(0, -1);
          } else if (input.value.length < 6) {
            input.value += key;
          }
          this.joinCode = input.value;
          this.patchPinSlots(slots, input.value);
          input.focus();
        });
        pad.append(keyButton);
      });
      return pad;
    }
    async lookup(code) {
      this.joinCode = code;
      this.renderLoading();
      try {
        const result = await this.api.post("live_session_lookup", {
          joinCode: code
        });
        if (result.resume) {
          this.storeSession(result.resume.sessionId);
          this.applyState(result.resume, true);
          return;
        }
        this.currentLookup = result.state;
        this.renderProfile();
      } catch (error) {
        if (this.isAuthenticationError(error)) {
          this.renderExpiredSession();
          return;
        }
        const message = error instanceof LiveApiError && (error.status === 404 || error.code === "not_found") ? this.s("play:join:notfound") : error instanceof LiveApiError ? error.message : this.s("live:error:request");
        this.renderJoin(message);
      }
    }
    renderProfile(message = "") {
      var _a, _b, _c, _d, _e, _f, _g, _h, _i;
      const lookup = this.currentLookup;
      if (!lookup) {
        this.renderJoin(message);
        return;
      }
      this.setRootState("profile", null);
      const card = element2("section", "quizgeist-player-card quizgeist-player-profile");
      const heading = element2("h2", "quizgeist-player-title", this.s("play:profile:title"));
      const info = element2("p", "quizgeist-player-copy");
      if (lookup.nameMode === "real") {
        info.textContent = this.s("host:namemode:real");
      } else if (lookup.nameMode === "generated") {
        info.textContent = this.s("host:namemode:generated");
      } else {
        info.textContent = this.s("play:profile:namehint");
      }
      const form = element2("form", "quizgeist-profile-form");
      form.dataset.action = "join";
      let nameInput = null;
      if (lookup.nameMode === "custom") {
        const label = element2("label", "quizgeist-field-label", this.s("play:profile:name"));
        label.htmlFor = "quizgeist-display-name";
        nameInput = element2("input", "quizgeist-text-input");
        nameInput.id = "quizgeist-display-name";
        nameInput.name = "displayName";
        nameInput.maxLength = 40;
        nameInput.required = true;
        nameInput.autocomplete = "off";
        label.append(nameInput);
        form.append(label);
      }
      const avatarLabel = element2("p", "quizgeist-field-label", this.s("play:profile:avatar"));
      const avatars = element2("div", "quizgeist-avatar-picker");
      avatars.setAttribute("role", "radiogroup");
      const unlockedAvatars = new Set(
        ((_b = (_a = lookup.rewards) == null ? void 0 : _a.avatars) == null ? void 0 : _b.length) ? lookup.rewards.avatars : AVATAR_KEYS
      );
      if (!unlockedAvatars.has(this.selectedAvatar)) {
        this.selectedAvatar = AVATAR_KEYS.find((key) => unlockedAvatars.has(key)) || "kiesel";
      }
      AVATAR_KEYS.forEach((key) => {
        const avatarButton = button("quizgeist-avatar-choice", AVATAR_NAMES[key], `avatar-${key}`);
        avatarButton.dataset.avatarKey = key;
        avatarButton.dataset.liveAvatarKey = key;
        avatarButton.setAttribute("role", "radio");
        avatarButton.setAttribute("aria-checked", key === this.selectedAvatar ? "true" : "false");
        avatarButton.classList.toggle("is-selected", key === this.selectedAvatar);
        const unlocked = unlockedAvatars.has(key);
        avatarButton.classList.toggle("is-locked", !unlocked);
        avatarButton.disabled = !unlocked;
        avatarButton.setAttribute("aria-disabled", unlocked ? "false" : "true");
        avatarButton.prepend(createAvatar({
          accessoryKey: this.selectedAccessory,
          avatarKey: key
        }));
        avatarButton.addEventListener("click", () => {
          this.selectedAvatar = key;
          avatars.querySelectorAll("[data-avatar-key]").forEach((candidate) => {
            const selected = candidate.dataset.avatarKey === key;
            candidate.classList.toggle("is-selected", selected);
            candidate.setAttribute("aria-checked", selected ? "true" : "false");
          });
        });
        avatars.append(avatarButton);
      });
      form.append(avatarLabel, avatars);
      const accessories = ((_c = lookup.rewards) == null ? void 0 : _c.accessories) || [];
      if (accessories.length > 0) {
        const accessoryLabel = element2(
          "p",
          "quizgeist-field-label",
          this.s("play:profile:accessory") === "play:profile:accessory" ? "Accessoire" : this.s("play:profile:accessory")
        );
        const picker = element2("div", "quizgeist-accessory-picker");
        picker.setAttribute("role", "radiogroup");
        const options = [{ key: "", label: "Ohne", unlocked: true }, ...accessories];
        if (!options.some((entry) => entry.key === this.selectedAccessory && entry.unlocked)) {
          this.selectedAccessory = "";
        }
        options.forEach((accessory2) => {
          const accessoryButton = button(
            "quizgeist-accessory-choice",
            accessory2.label,
            `accessory-${accessory2.key || "none"}`
          );
          accessoryButton.dataset.liveAccessoryKey = accessory2.key;
          accessoryButton.disabled = !accessory2.unlocked;
          accessoryButton.classList.toggle("is-locked", !accessory2.unlocked);
          accessoryButton.classList.toggle(
            "is-selected",
            accessory2.key === this.selectedAccessory
          );
          accessoryButton.setAttribute("role", "radio");
          accessoryButton.setAttribute(
            "aria-checked",
            accessory2.key === this.selectedAccessory ? "true" : "false"
          );
          accessoryButton.addEventListener("click", () => {
            this.selectedAccessory = accessory2.key;
            picker.querySelectorAll("[data-live-accessory-key]").forEach((node) => {
              const selected = node.dataset.liveAccessoryKey === accessory2.key;
              node.classList.toggle("is-selected", selected);
              node.setAttribute("aria-checked", selected ? "true" : "false");
            });
            avatars.querySelectorAll("[data-live-avatar-key]").forEach((node) => {
              var _a2;
              const avatarKey = node.dataset.liveAvatarKey || "kiesel";
              (_a2 = node.querySelector(".quizgeist-avatar")) == null ? void 0 : _a2.replaceWith(createAvatar({
                accessoryKey: this.selectedAccessory,
                avatarKey
              }));
            });
          });
          picker.append(accessoryButton);
        });
        form.append(accessoryLabel, picker);
      }
      const teams = ((_d = lookup.teamConfig) == null ? void 0 : _d.teams) || lookup.teams || [];
      if (lookup.mode === "team" && ((_e = lookup.teamConfig) == null ? void 0 : _e.source) === "groups") {
        form.append(element2(
          "p",
          "quizgeist-player-team-hint",
          this.s("play:profile:teamgroups")
        ));
        this.selectedTeamKey = "";
      } else if (lookup.mode === "team" && teams.length > 0) {
        const teamLabel = element2(
          "label",
          "quizgeist-field-label",
          this.s("play:profile:team")
        );
        const teamSelect = element2("select", "quizgeist-text-input");
        teamSelect.dataset.liveTeamKey = "";
        teamSelect.name = "teamKey";
        teams.forEach((team) => {
          var _a2;
          const key = String((_a2 = team.key) != null ? _a2 : team.id);
          teamSelect.append(element2("option", "", team.name));
          const option = teamSelect.lastElementChild;
          option.value = key;
        });
        if (!teams.some((team) => {
          var _a2;
          return String((_a2 = team.key) != null ? _a2 : team.id) === this.selectedTeamKey;
        })) {
          this.selectedTeamKey = String((_i = (_h = (_f = teams[0]) == null ? void 0 : _f.key) != null ? _h : (_g = teams[0]) == null ? void 0 : _g.id) != null ? _i : "");
        }
        teamSelect.value = this.selectedTeamKey;
        teamSelect.addEventListener("change", () => {
          this.selectedTeamKey = teamSelect.value;
        });
        teamLabel.append(teamSelect);
        form.append(teamLabel);
      }
      const error = element2("p", "quizgeist-form-error", message);
      error.setAttribute("role", "alert");
      const submit = element2("button", "quizgeist-player-primary", this.s("play:join:submit"));
      submit.type = "submit";
      submit.dataset.action = "join-submit";
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        const displayName = (nameInput == null ? void 0 : nameInput.value.trim()) || "";
        if (lookup.nameMode === "custom" && displayName === "") {
          error.textContent = this.s("play:profile:namehint");
          nameInput == null ? void 0 : nameInput.focus();
          return;
        }
        void this.join(displayName, error, submit);
      });
      form.append(error, submit);
      card.append(this.brand(), heading, info, form);
      this.stage.replaceChildren(card);
      this.setStageModeScreen("profile");
      nameInput == null ? void 0 : nameInput.focus();
    }
    async join(displayName, errorNode, submit) {
      var _a, _b, _c;
      if (this.submitting) {
        return;
      }
      this.submitting = true;
      submit.disabled = true;
      errorNode.textContent = "";
      try {
        const result = await this.api.post("live_session_join", {
          accessoryKey: this.selectedAccessory,
          avatarKey: this.selectedAvatar,
          displayName,
          joinCode: this.joinCode,
          sessionId: (_a = this.currentLookup) == null ? void 0 : _a.sessionId,
          ...((_c = (_b = this.currentLookup) == null ? void 0 : _b.teamConfig) == null ? void 0 : _c.source) === "free" ? { teamKey: this.selectedTeamKey } : {}
        });
        if (!result.state) {
          throw new LiveApiError(this.s("live:error:request"), "invalid_response");
        }
        this.storeSession(result.state.sessionId);
        this.applyState(result.state, true);
      } catch (error) {
        if (this.isAuthenticationError(error)) {
          this.renderExpiredSession();
          return;
        }
        errorNode.textContent = error instanceof LiveApiError ? error.message : this.s("live:error:request");
      } finally {
        this.submitting = false;
        submit.disabled = false;
      }
    }
    async poll(signal) {
      var _a;
      if (!this.currentState) {
        return { pollAfterMs: 1800 };
      }
      const result = await this.api.post("live_player_poll", {
        knownAggregateRevision: this.currentState.aggregateRevision || 0,
        knownQuestionToken: ((_a = this.currentState.question) == null ? void 0 : _a.questionToken) || "",
        knownStateVersion: this.currentState.stateVersion,
        sessionId: this.currentState.sessionId
      }, signal);
      this.serverClockOffsetMs = result.serverTimeMs - Date.now();
      if (result.changed && result.state) {
        this.applyState(result.state);
      } else if (result.hasAnswered !== this.currentState.hasAnswered || result.aggregate !== void 0 || result.aggregateRevision !== void 0) {
        this.applyState({
          ...this.currentState,
          aggregate: result.aggregate === void 0 ? this.currentState.aggregate : result.aggregate,
          aggregateRevision: result.aggregateRevision === void 0 ? this.currentState.aggregateRevision : Number(result.aggregateRevision || 0),
          hasAnswered: result.hasAnswered,
          serverTimeMs: result.serverTimeMs
        });
      }
      if (!result.changed && this.currentState.phase === "question" && !this.currentState.question && result.serverTimeMs < this.currentState.phaseStartedAtMs) {
        this.requestedQuestionDelivery = "";
      }
      this.connectionMessage = "";
      this.patchConnection();
      return { pollAfterMs: result.pollAfterMs };
    }
    pollError(error, failures) {
      if (this.isAuthenticationError(error)) {
        this.renderExpiredSession();
        return { stop: true };
      }
      this.connectionMessage = failures > 1 ? this.s("live:connection:offline") : this.s("live:connection:reconnecting");
      this.patchConnection();
      return {};
    }
    applyState(state, force = false) {
      var _a, _b, _c, _d;
      const previous = this.currentState;
      if (previous && state.sessionId === previous.sessionId && state.stateVersion < previous.stateVersion) {
        return;
      }
      state = {
        ...state,
        aggregateRevision: Math.max(0, Number(state.aggregateRevision || 0)),
        podium: Array.isArray(state.podium) ? state.podium : [],
        question: state.question ? {
          ...state.question,
          choices: Array.isArray(state.question.choices) ? state.question.choices : [],
          typeData: state.question.typeData && typeof state.question.typeData === "object" ? state.question.typeData : {}
        } : null,
        ranking: Array.isArray(state.ranking) ? state.ranking : [],
        teamPodium: Array.isArray(state.teamPodium) ? state.teamPodium : [],
        teamRanking: Array.isArray(state.teamRanking) ? state.teamRanking : []
      };
      this.scoreBurst = Boolean(previous && state.player.score > previous.player.score);
      this.streakBurst = Boolean(
        previous && state.player.streak > previous.player.streak && state.player.streak >= 2
      );
      this.serverClockOffsetMs = state.serverTimeMs - Date.now();
      this.sound.setAllowed(state.soundEnabled !== false);
      this.currentState = state;
      const changedQuestion = ((_a = previous == null ? void 0 : previous.question) == null ? void 0 : _a.id) !== ((_b = state.question) == null ? void 0 : _b.id) || ((_c = previous == null ? void 0 : previous.question) == null ? void 0 : _c.questionToken) !== ((_d = state.question) == null ? void 0 : _d.questionToken);
      const changedPhase = (previous == null ? void 0 : previous.phase) !== state.phase;
      const changedAnswer = (previous == null ? void 0 : previous.hasAnswered) !== state.hasAnswered;
      const changedAggregate = (previous == null ? void 0 : previous.aggregateRevision) !== state.aggregateRevision;
      if (changedQuestion) {
        this.answerDraft = null;
        this.answerMessage = "";
        this.tts.stop();
        this.lastCountdownSecond = null;
        this.lastWarningSecond = null;
      }
      if (state.question) {
        this.requestedQuestionDelivery = "";
      }
      if (force || changedQuestion || changedPhase || changedAnswer || changedAggregate || (previous == null ? void 0 : previous.stateVersion) !== state.stateVersion) {
        this.renderState();
      } else {
        this.patchClock();
      }
      if (state.phase === "ended" || state.phase === "aborted") {
        this.clearStoredSession();
        this.poller.stop();
      } else if (!this.poller.isRunning()) {
        this.poller.start(false);
      }
      if (!previous || changedPhase) {
        this.announcePhase(state.phase);
      }
      this.handleSoundTransition(previous, state);
    }
    handleSoundTransition(previous, state) {
      var _a, _b;
      if (state.phase === "lobby") {
        this.sound.startLobby();
      } else {
        this.sound.stopLobby();
      }
      if (!previous || previous.phase === state.phase) {
        return;
      }
      this.tts.stop();
      if (state.phase === "question" && state.phaseStartedAtMs <= this.serverNow(state)) {
        this.sound.play("go");
      } else if (state.phase === "reveal") {
        if (((_a = state.feedback) == null ? void 0 : _a.correct) === true) {
          this.sound.play("correct");
        } else if (((_b = state.feedback) == null ? void 0 : _b.correct) === false) {
          this.sound.play("incorrect");
        }
        if (state.player.streak > (previous.player.streak || 0) && state.player.streak >= 2) {
          this.sound.play("streak");
        }
      } else if (state.phase === "podium") {
        this.sound.play("podium");
      }
    }
    renderState() {
      var _a;
      const state = this.currentState;
      if (!state) {
        this.renderJoin();
        return;
      }
      this.setRootState(state.phase, state);
      if (state.phase !== "question" || !state.question || state.question.interactionStage !== "stage" || state.hasAnswered) {
        (_a = this.stageCheck) == null ? void 0 : _a.close();
        this.stageCheck = null;
      }
      let content;
      if (state.phase === "lobby") {
        content = this.renderLobby(state);
      } else if (state.phase === "question") {
        content = this.renderQuestion(state);
      } else if (state.phase === "reveal") {
        content = this.renderFeedback(state);
      } else if (state.phase === "scoreboard") {
        content = this.renderScoreboard(state);
      } else if (state.phase === "podium") {
        content = this.renderPodium(state);
      } else {
        content = this.renderEnded(state);
      }
      const shell = element2("section", `quizgeist-player-screen quizgeist-player-screen--${state.phase}`);
      const header = element2("header", "quizgeist-player-header");
      const connection = element2("p", "quizgeist-live-connection", this.connectionMessage);
      connection.dataset.liveConnection = "";
      connection.setAttribute("aria-live", "polite");
      header.append(
        this.brand(),
        this.scoreChip(state.player),
        createSoundControls(this.sound, this.config.strings || {})
      );
      shell.append(header, connection, content);
      this.stage.replaceChildren(shell);
      this.setStageModeScreen(state.phase);
      if (state.phase === "ended" || state.phase === "aborted") {
        void this.stageMode.leave();
      }
      this.startClock();
      const focusSelector = state.phase === "question" && state.question && !state.hasAnswered ? "[data-question-heading]" : ["reveal", "scoreboard", "podium", "ended", "aborted"].includes(state.phase) ? "[data-phase-heading]" : "";
      if (focusSelector !== "") {
        window.setTimeout(() => {
          var _a2;
          (_a2 = this.stage.querySelector(focusSelector)) == null ? void 0 : _a2.focus();
        }, 0);
      }
    }
    renderLobby(state) {
      var _a;
      const main = element2("main", "quizgeist-player-main quizgeist-player-lobby");
      const avatar = createAvatar({
        accessoryKey: state.player.accessoryKey,
        avatarKey: state.player.avatarKey
      });
      avatar.classList.add("quizgeist-player-avatar--large");
      const title = element2("h2", "quizgeist-player-title", state.player.displayName);
      const status = element2("p", "quizgeist-player-waiting", this.s("play:lobby:ready"));
      const count2 = element2(
        "p",
        "quizgeist-player-count",
        this.s("play:lobby:count", { a: state.playerCount })
      );
      count2.setAttribute("aria-live", "polite");
      main.append(avatar, title, status, count2);
      const teamName = ((_a = state.player.team) == null ? void 0 : _a.teamName) || state.player.teamName;
      if (teamName) {
        main.append(element2("p", "quizgeist-player-team", `Team ${teamName}`));
      }
      return main;
    }
    renderQuestion(state) {
      const now = this.serverNow(state);
      if (state.phaseStartedAtMs > now) {
        const countdown = element2("main", "quizgeist-player-main quizgeist-player-countdown");
        countdown.append(
          element2("p", "quizgeist-player-kicker", this.s("play:countdown:ready")),
          element2("strong", "quizgeist-countdown-number")
        );
        return countdown;
      }
      const question2 = state.question;
      if (!question2) {
        const waiting = element2("main", "quizgeist-player-main quizgeist-player-countdown");
        waiting.append(
          element2("p", "quizgeist-player-kicker", this.s("play:countdown:ready")),
          element2("strong", "quizgeist-countdown-number", "\u2026")
        );
        return waiting;
      }
      const main = element2("main", "quizgeist-player-main quizgeist-player-question");
      const meta = element2(
        "p",
        "quizgeist-player-kicker",
        this.s("play:question:progress", {
          current: question2.index + 1,
          total: question2.total
        })
      );
      const title = element2("h2", "quizgeist-question-title", question2.questionText);
      title.id = "quizgeist-live-question-heading";
      title.dataset.questionHeading = "";
      title.tabIndex = -1;
      const showtimer = state.timerVisible !== false;
      main.append(meta, title);
      if (showtimer) {
        const timer = element2("div", "quizgeist-player-timer");
        timer.dataset.liveTimer = "";
        timer.setAttribute("role", "timer");
        timer.setAttribute(
          "aria-label",
          this.s("host:question:remaining", { a: question2.timeLimit })
        );
        main.append(timer);
      }
      main.append(
        createTtsControl(this.tts, questionSpeechText(question2), this.config)
      );
      if (!["pin", "reveal", "slide"].includes(question2.qtype)) {
        const questionMedia = createLiveMedia(question2, {
          className: "quizgeist-question-media",
          label: this.s("editor:field:media")
        });
        if (questionMedia) {
          questionMedia.dataset.questionMedia = "";
          main.append(questionMedia);
        }
      }
      if (question2.interactionStage === "reason") {
        main.append(this.renderReason(state));
        return main;
      }
      if (question2.interactionStage === "stage") {
        main.append(this.renderStageCheck(state));
        return main;
      }
      const repeated = questionAllowsRepeatedSubmissions(question2);
      if (state.hasAnswered && !repeated) {
        const waiting = element2("div", "quizgeist-answer-waiting");
        waiting.setAttribute("role", "status");
        waiting.append(
          element2("strong", "", this.s("play:answer:submitted")),
          element2("p", "", this.s("play:answer:waiting"))
        );
        main.append(waiting);
        return main;
      }
      if (this.answerMessage !== "") {
        const message = element2("p", "quizgeist-form-error", this.answerMessage);
        message.setAttribute("role", "alert");
        main.append(message);
      }
      let response;
      response = renderLiveResponse(question2, {
        aggregate: state.aggregate,
        answer: this.answerDraft,
        audience: "player",
        disabled: state.phaseEndsAtMs > 0 && state.phaseEndsAtMs <= now,
        interactive: true,
        nowMs: now,
        phaseStartedAtMs: state.phaseStartedAtMs,
        onChange: (answer) => {
          this.answerDraft = answer;
        },
        onSubmit: (answer) => {
          if (answer) {
            void this.submitAnswer(
              answer,
              response.querySelector("[data-live-submit]") || void 0
            );
          }
        },
        onTap: () => {
          void this.sound.unlock();
          this.sound.play("tap");
        },
        text: (key, fallback, values = {}) => this.s(key, values, fallback)
      });
      response.setAttribute("aria-labelledby", title.id);
      main.append(response);
      window.setTimeout(() => {
        var _a;
        if (!state.hasAnswered || repeated) {
          (_a = response.querySelector(
            "[data-live-text-answer]"
          )) == null ? void 0 : _a.focus();
        }
      }, 0);
      return main;
    }
    /**
     * F13 Bühnen-Check: the presentation stage of an open question or a slide.
     *
     * The screen is built here, the camera lives in the addon bundle. When the
     * addon code package is absent the server never sends this stage at all, so
     * there is nothing to hide and nothing to lock.
     *
     * @param state Current player state.
     * @returns The stage surface.
     */
    renderStageCheck(state) {
      const block = element2("div", "quizgeist-player-stagecheck");
      block.dataset.liveStage = "";
      if (state.hasAnswered) {
        const done = element2("div", "quizgeist-answer-waiting");
        done.setAttribute("role", "status");
        done.append(
          element2("strong", "", this.s("stage:sent")),
          element2("p", "", this.s("play:answer:waiting"))
        );
        block.append(done);
        return block;
      }
      if (this.stageCheck !== null) {
        this.stageCheck.close();
      }
      const control = new StageCheckControl(
        this.config,
        state.question ? state.question.id : 0,
        null,
        (report) => {
          void this.submitAnswer(
            { kind: "stage", reportId: report.id }
          );
        }
      );
      this.stageCheck = control;
      void control.init();
      block.append(control.root);
      return block;
    }
    /**
     * F2 Denk-Moment: one short justification between answer and reveal.
     *
     * The stage is server-owned; this screen only collects the text. A reason
     * carries no points and no correctness, and the copy says so.
     */
    renderReason(state) {
      const block = element2("div", "quizgeist-player-reason");
      block.dataset.liveReason = "";
      if (state.hasAnswered) {
        const done = element2("div", "quizgeist-answer-waiting");
        done.setAttribute("role", "status");
        done.append(
          element2("strong", "", this.s("play:reason:sent")),
          element2("p", "", this.s("play:answer:waiting"))
        );
        block.append(done);
        return block;
      }
      const heading = element2(
        "h3",
        "quizgeist-player-reason__title",
        this.s("play:reason:title")
      );
      const hint = element2(
        "p",
        "quizgeist-player-reason__hint",
        this.s("play:reason:hint")
      );
      const field = element2("textarea", "quizgeist-player-reason__input");
      field.dataset.liveTextAnswer = "";
      field.setAttribute("rows", "4");
      field.setAttribute("maxlength", "2000");
      field.setAttribute("placeholder", this.s("play:reason:placeholder"));
      field.setAttribute("aria-label", this.s("play:reason:title"));
      const send = element2(
        "button",
        "quizgeist-player-button quizgeist-player-button--primary",
        this.s("play:reason:send")
      );
      send.setAttribute("type", "button");
      send.dataset.liveSubmit = "";
      send.addEventListener("click", () => {
        const text4 = field.value.trim();
        if (text4 === "") {
          field.focus();
          return;
        }
        void this.sound.unlock();
        this.sound.play("tap");
        void this.submitAnswer({ kind: "text", text: text4 }, send);
      });
      if (this.answerMessage !== "") {
        const message = element2("p", "quizgeist-form-error", this.answerMessage);
        message.setAttribute("role", "alert");
        block.append(message);
      }
      block.append(heading, hint, field, send);
      const spoken = this.spokenControl("reason", (clip) => {
        void this.submitAnswer({ kind: "clip", clipId: clip.id }, send);
      });
      if (spoken !== null) {
        block.append(spoken.root);
      }
      return block;
    }
    /**
     * Build the recording control, or nothing at all.
     *
     * Returns null when the AI addon is absent or the person may not record.
     * That is the "no locked bait" rule of 2.6 taken literally: an unusable
     * button is worse than no button.
     *
     * [P11-E2]: the addon report is asked FIRST and by name. `canRecord` already
     * carries it today, but it is a derived permission, and a later change to
     * its meaning must not silently re-open a path to `clip_transcribe` and
     * `clip_state` — two actions the dispatcher does not know without the AI
     * addon.
     */
    spokenControl(purpose, onClip) {
      var _a, _b;
      const clips = this.config.clips;
      if (((_b = (_a = this.config.features) == null ? void 0 : _a.ai) == null ? void 0 : _b.installed) !== true || clips === void 0 || !clips.canRecord || !SpokenAnswerControl.available()) {
        return null;
      }
      const control = new SpokenAnswerControl(
        this.config.strings,
        {
          uploadUrl: clips.uploadUrl,
          sesskey: this.config.sesskey,
          cmid: this.config.cmid,
          purpose,
          language: clips.language,
          maxBytes: clips.maxBytes,
          maxSeconds: clips.maxSeconds,
          transcriptionAvailable: clips.canTranscribe
        },
        {
          onClip,
          transcribe: (clipId) => this.api.post(
            "clip_transcribe",
            { clipId }
          ).then((result) => result.clip),
          pollState: (clipIds) => this.api.post(
            "clip_state",
            { clipIds }
          ).then((result) => result.clips)
        }
      );
      this.spokenControls.push(control);
      return control;
    }
    submissionKey(question2, canonical) {
      var _a;
      const cacheKey = `${question2.questionToken}:${JSON.stringify(canonical)}`;
      const existing = this.submissionKeys.get(cacheKey);
      if (existing) {
        return { cacheKey, value: existing };
      }
      const value2 = typeof ((_a = window.crypto) == null ? void 0 : _a.randomUUID) === "function" ? window.crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
      this.submissionKeys.set(cacheKey, value2);
      return { cacheKey, value: value2 };
    }
    async submitAnswer(answer, buttonNode) {
      var _a, _b, _c;
      const state = this.currentState;
      const question2 = state == null ? void 0 : state.question;
      if (!state || !question2 || this.submitting) {
        return;
      }
      this.submitting = true;
      if (buttonNode) {
        buttonNode.disabled = true;
      }
      const canonical = answerPayload(answer);
      const repeated = questionAllowsRepeatedSubmissions(question2);
      const submission = repeated ? this.submissionKey(question2, canonical) : null;
      try {
        const result = await this.api.post("live_answer", {
          answer: canonical,
          ..."choiceIds" in canonical ? { choiceIds: canonical.choiceIds } : {},
          questionId: question2.id,
          questionToken: question2.questionToken,
          sessionId: state.sessionId,
          ...submission ? { submissionKey: submission.value } : {}
        });
        if (result.accepted !== true || !Number.isInteger(result.stateVersion)) {
          throw new LiveApiError(this.s("live:error:request"), "invalid_response");
        }
        if (((_a = this.currentState) == null ? void 0 : _a.sessionId) === state.sessionId && ((_b = this.currentState.question) == null ? void 0 : _b.questionToken) === question2.questionToken) {
          this.answerMessage = "";
          if (questionAllowsRepeatedSubmissions(question2)) {
            this.answerDraft = null;
          }
          this.applyState({
            ...this.currentState,
            hasAnswered: result.hasAnswered === true,
            stateVersion: Math.max(
              this.currentState.stateVersion,
              result.stateVersion
            )
          }, true);
        }
        if (submission) {
          this.submissionKeys.delete(submission.cacheKey);
        }
        this.poller.kick();
      } catch (error) {
        if (error instanceof LiveApiError && error.status === 409 && ((_c = error.data) == null ? void 0 : _c.state)) {
          this.applyState(error.data.state, true);
        } else if (error instanceof LiveApiError && error.code === "answer_too_late") {
          this.answerMessage = this.s("play:answer:toolate");
          this.renderState();
          this.poller.kick();
        } else if (error instanceof LiveApiError && error.code === "question_not_open") {
          this.answerMessage = error.message;
          this.renderState();
          this.poller.kick();
        } else if (this.isAuthenticationError(error)) {
          this.renderExpiredSession();
        } else {
          this.connectionMessage = error instanceof LiveApiError ? error.message : this.s("live:error:request");
          this.patchConnection();
          if (buttonNode) {
            buttonNode.disabled = false;
          }
        }
      } finally {
        this.submitting = false;
      }
    }
    renderFeedback(state) {
      var _a, _b, _c;
      const main = element2("main", "quizgeist-player-main quizgeist-player-feedback");
      const feedback = state.feedback;
      let title = this.s("play:answer:waiting");
      let modifier = "neutral";
      if ((feedback == null ? void 0 : feedback.correct) === true) {
        title = this.s("play:feedback:correct");
        modifier = "correct";
      } else if ((feedback == null ? void 0 : feedback.correct) === false) {
        title = ((_a = state.question) == null ? void 0 : _a.friendlyNew) === true ? this.s("play:friendlynew") : this.s("play:feedback:incorrect");
        modifier = ((_b = state.question) == null ? void 0 : _b.friendlyNew) === true ? "friendlynew" : "incorrect";
      } else if (feedback) {
        title = this.s("play:feedback:poll");
        modifier = "poll";
      }
      main.classList.add(`quizgeist-player-feedback--${modifier}`);
      const heading = element2("h2", "quizgeist-player-title", title);
      heading.dataset.phaseHeading = "";
      heading.tabIndex = -1;
      main.append(heading);
      if (feedback) {
        main.append(element2(
          "strong",
          `quizgeist-feedback-points${this.scoreBurst ? " is-score-pop" : ""}`,
          this.s("play:feedback:points", { a: feedback.points })
        ));
      }
      main.append(
        element2(
          "p",
          "quizgeist-feedback-rank",
          this.s("play:scoreboard:rank", { a: (_c = state.ownRank) != null ? _c : "\u2013" })
        ),
        this.streak(state.player.streak)
      );
      return main;
    }
    renderScoreboard(state) {
      const main = element2("main", "quizgeist-player-main quizgeist-player-scoreboard");
      const rank = element2("h2", "quizgeist-player-rank", `#${state.ownRank}`);
      rank.dataset.phaseHeading = "";
      rank.tabIndex = -1;
      rank.setAttribute(
        "aria-label",
        `${this.s("live:phase:scoreboard")}: #${state.ownRank}`
      );
      main.append(
        element2("p", "quizgeist-player-kicker", this.s("live:phase:scoreboard")),
        rank,
        element2("p", "quizgeist-player-title", state.player.displayName),
        element2("strong", "quizgeist-player-total", `${state.player.score} ${this.s("live:points")}`),
        this.streak(state.player.streak)
      );
      return main;
    }
    renderPodium(state) {
      var _a;
      const main = element2("main", "quizgeist-player-main quizgeist-player-podium");
      const isTopThree = state.ownRank !== null && state.ownRank > 0 && state.ownRank <= 3;
      const rank = element2(
        "h2",
        "quizgeist-player-rank",
        state.ownRank === null ? "\u2013" : `#${state.ownRank}`
      );
      rank.dataset.phaseHeading = "";
      rank.tabIndex = -1;
      rank.setAttribute(
        "aria-label",
        `${this.s("live:phase:podium")}: ${state.ownRank === null ? "\u2013" : `#${state.ownRank}`}`
      );
      main.append(
        element2("p", "quizgeist-player-kicker", this.s("play:podium:title")),
        createAvatar({
          accessoryKey: state.player.accessoryKey,
          avatarKey: state.player.avatarKey
        }),
        rank,
        element2(
          "p",
          "quizgeist-player-title",
          isTopThree ? this.s("play:podium:topthree") : this.s("play:podium:encouragement")
        ),
        element2("strong", "quizgeist-player-total", `${state.player.score} ${this.s("live:points")}`)
      );
      const teamPodium = state.mode === "team" && (((_a = state.teamPodium) == null ? void 0 : _a.length) || 0) > 0;
      const list = element2(
        "ol",
        `quizgeist-player-podium-list${teamPodium ? " quizgeist-team-podium" : ""}`
      );
      if (teamPodium) {
        list.dataset.liveTeamPodium = "";
      }
      const entries = teamPodium ? state.teamPodium || [] : state.podium;
      entries.forEach((standing) => {
        const item = element2("li", "quizgeist-player-podium-entry");
        if ("playerId" in standing) {
          item.dataset.playerId = String(standing.playerId);
        } else {
          item.dataset.teamId = String(standing.teamKey || standing.id || "");
        }
        item.dataset.rank = String(standing.rank);
        item.append(
          element2("span", "quizgeist-player-podium-rank", String(standing.rank)),
          "playerId" in standing ? createAvatar({
            accessoryKey: standing.accessoryKey,
            avatarKey: standing.avatarKey
          }) : element2("span", "quizgeist-team-podium__spark", "\u2726"),
          element2(
            "span",
            "quizgeist-player-podium-name",
            "playerId" in standing ? standing.displayName : standing.teamName || standing.name || ""
          ),
          element2("strong", "", String(standing.score))
        );
        list.append(item);
      });
      main.append(list);
      return main;
    }
    renderEnded(state) {
      const main = this.renderPodium(state);
      main.classList.add("quizgeist-player-ended");
      const overview = element2("a", "quizgeist-player-primary", this.s("play:ended:overview"));
      overview.href = this.config.overviewUrl || this.config.playerUrlBase || window.location.pathname;
      overview.dataset.action = "overview";
      overview.addEventListener("click", () => this.clearStoredSession());
      main.append(overview);
      return main;
    }
    scoreChip(player) {
      const chip = element2(
        "div",
        `quizgeist-player-score-chip${this.scoreBurst ? " is-score-pop" : ""}`
      );
      chip.dataset.liveScore = String(player.score);
      chip.append(
        element2("strong", "", String(player.score)),
        element2("span", "", this.s("live:points"))
      );
      return chip;
    }
    streak(value2) {
      const node = element2(
        "p",
        `quizgeist-player-streak${this.streakBurst ? " is-streak-burst" : ""}`
      );
      node.dataset.streak = String(value2);
      node.append(
        element2("span", "quizgeist-player-streak__flame", "\u25C6"),
        document.createTextNode(` ${this.s("live:streak")}: ${value2}`)
      );
      return node;
    }
    startClock() {
      this.stopClock();
      this.patchClock();
      this.clockId = window.setInterval(() => this.patchClock(), 250);
    }
    stopClock() {
      if (this.clockId !== null) {
        window.clearInterval(this.clockId);
        this.clockId = null;
      }
    }
    patchClock() {
      const state = this.currentState;
      if (!state) {
        return;
      }
      const now = this.serverNow(state);
      patchLiveResponseClock(this.root, now);
      const countdown = this.root.querySelector(".quizgeist-countdown-number");
      if (countdown) {
        const remaining = state.phaseStartedAtMs - now;
        if (remaining <= 0) {
          if (!state.question) {
            const deliveryKey = `${state.sessionId}:${state.stateVersion}`;
            if (this.requestedQuestionDelivery !== deliveryKey) {
              this.requestedQuestionDelivery = deliveryKey;
              this.poller.kick();
            }
            countdown.textContent = "\u2026";
          } else {
            this.sound.play("go");
            this.renderState();
          }
          return;
        }
        const second = Math.max(1, Math.ceil(remaining / 1e3));
        countdown.textContent = String(second);
        if (second !== this.lastCountdownSecond) {
          this.lastCountdownSecond = second;
          this.sound.play("countdown");
        }
      }
      const timer = this.root.querySelector("[data-live-timer]");
      if (timer) {
        if (state.phaseEndsAtMs <= 0) {
          timer.textContent = "\u221E";
          timer.classList.remove("is-warning");
          timer.setAttribute("aria-label", this.s("editor:time:none"));
          return;
        }
        const remaining = Math.max(0, state.phaseEndsAtMs - now);
        const second = Math.ceil(remaining / 1e3);
        timer.textContent = String(second);
        timer.classList.toggle("is-warning", remaining > 0 && remaining <= 5e3);
        if (second > 0 && second <= 5 && second !== this.lastWarningSecond) {
          this.lastWarningSecond = second;
          this.sound.play("warning");
        }
        if (remaining === 0) {
          this.root.querySelectorAll(
            "[data-live-answer-kind] button, [data-live-answer-kind] input, [data-live-answer-kind] textarea, [data-live-answer-kind] select"
          ).forEach((control) => {
            control.disabled = true;
          });
        }
      }
    }
    serverNow(_state) {
      return Date.now() + this.serverClockOffsetMs;
    }
    patchConnection() {
      const node = this.root.querySelector("[data-live-connection]");
      if (node) {
        node.textContent = this.connectionMessage;
        node.hidden = this.connectionMessage === "";
      }
    }
    setRootState(phase, state) {
      this.root.dataset.livePhase = phase;
      this.root.dataset.stateReceivedAt = String(Date.now());
      if (!state) {
        delete this.root.dataset.sessionId;
        delete this.root.dataset.stateVersion;
        delete this.root.dataset.questionId;
        delete this.root.dataset.questionRootId;
        delete this.root.dataset.questionVersion;
        return;
      }
      this.root.dataset.sessionId = String(state.sessionId);
      this.root.dataset.stateVersion = String(state.stateVersion);
      if (state.question) {
        this.root.dataset.questionId = String(state.question.id);
        this.root.dataset.questionRootId = String(state.question.rootId);
        this.root.dataset.questionVersion = String(state.question.version);
      } else {
        delete this.root.dataset.questionId;
        delete this.root.dataset.questionRootId;
        delete this.root.dataset.questionVersion;
      }
    }
    setStageModeScreen(screen) {
      this.stageModeScreen = screen;
      this.syncStageModeVisibility(this.stageMode.mode());
    }
    syncStageModeVisibility(mode2) {
      const terminal = this.stageModeScreen === "ended" || this.stageModeScreen === "aborted" || this.stageModeScreen === "fatal";
      const visible = this.stageMode.isAvailable() && (this.stageModeScreen === "lobby" || this.stageModeScreen === "reveal" || this.stageModeScreen === "scoreboard" || this.stageModeScreen === "podium" || this.stageModeScreen === "question" && mode2 === "fullscreen" || terminal && mode2 === "fullscreen");
      this.fullscreenBar.hidden = !visible;
      this.fullscreenToggle.hidden = !visible;
    }
    announceStageMode(message) {
      this.liveRegion.textContent = "";
      window.requestAnimationFrame(() => {
        this.liveRegion.textContent = message;
      });
    }
    focusStageModeFallback() {
      const target = this.stage.querySelector("[data-phase-heading]") || this.stage.querySelector('[data-action="recover"]') || this.stage;
      if (target === this.stage) {
        this.stage.tabIndex = -1;
      }
      target.focus();
    }
    renderFatal(message, actionLabel, action) {
      this.poller.stop();
      this.stopClock();
      const card = element2("section", "quizgeist-player-card quizgeist-player-fatal");
      card.setAttribute("role", "alert");
      const retry = button("quizgeist-player-primary", actionLabel, "recover");
      retry.addEventListener("click", action);
      card.append(this.brand(), element2("h2", "quizgeist-player-title", message), retry);
      this.stage.replaceChildren(card);
      this.setStageModeScreen("fatal");
      void this.stageMode.leave();
    }
    handleActionError(error, retry) {
      if (this.isAuthenticationError(error)) {
        this.renderExpiredSession();
        return;
      }
      const message = error instanceof LiveApiError ? error.message : this.s("live:error:request");
      this.renderFatal(message, this.s("host:action:retry"), retry);
    }
    isAuthenticationError(error) {
      return error instanceof LiveApiError && (error.status === 401 || error.code === "session_expired" || error.status === 403 && error.code === "invalid_sesskey");
    }
    isTerminalPhase(phase) {
      return phase === "ended" || phase === "aborted";
    }
    renderExpiredSession() {
      this.renderFatal(
        this.s("live:connection:expired"),
        this.s("live:connection:reload"),
        () => window.location.reload()
      );
    }
    announcePhase(phase) {
      const key = phase === "aborted" ? "live:phase:ended" : `live:phase:${phase}`;
      const message = this.s(key);
      this.liveRegion.textContent = "";
      window.requestAnimationFrame(() => {
        var _a;
        if (((_a = this.currentState) == null ? void 0 : _a.phase) === phase) {
          this.liveRegion.textContent = message;
        }
      });
    }
    storedSessionId() {
      try {
        const value2 = window.sessionStorage.getItem(this.reconnectKey);
        return value2 && /^[1-9][0-9]*$/.test(value2) ? Number(value2) : null;
      } catch (_error) {
        return null;
      }
    }
    storeSession(sessionId) {
      try {
        window.sessionStorage.setItem(this.reconnectKey, String(sessionId));
      } catch (_error) {
      }
    }
    clearStoredSession() {
      try {
        window.sessionStorage.removeItem(this.reconnectKey);
      } catch (_error) {
      }
      try {
        window.localStorage.removeItem(this.reconnectKey);
      } catch (_error) {
      }
    }
  };
  function mountPlayerApp(raw) {
    var _a;
    const config = normaliseConfig(raw);
    const containerId = typeof raw.containerId === "string" && raw.containerId ? raw.containerId : "quizgeist-app-play";
    const root = document.getElementById(containerId);
    if (!root) {
      return;
    }
    if (!config) {
      const alert = element2("div", "quizgeist-player-card", ((_a = raw.strings) == null ? void 0 : _a["live:error:config"]) || "live:error:config");
      alert.setAttribute("role", "alert");
      root.replaceChildren(alert);
      return;
    }
    const app = new PlayerApp(root, config);
    void app.init();
  }

  // src/selfstudy/config.ts
  function positiveId(value2) {
    const candidate = Number(value2 || 0);
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

  // src/selfstudy/speaking.ts
  var SpeakingTrainer = class {
    constructor(strings, clipConfig, callbacks, tts) {
      this.strings = strings;
      this.callbacks = callbacks;
      this.tts = tts;
      __publicField(this, "root");
      __publicField(this, "promptBox");
      __publicField(this, "feedbackBox");
      __publicField(this, "textInput");
      __publicField(this, "submitButton");
      __publicField(this, "readButton");
      __publicField(this, "control", null);
      __publicField(this, "question", { task: "", accepted: [] });
      __publicField(this, "busy", false);
      this.root = liveElement("div", "quizgeist-speaking");
      this.promptBox = liveElement("p", "quizgeist-speaking__prompt", {
        "aria-live": "polite"
      });
      this.readButton = liveButton(
        this.text("speaking:read", "Aufgabe vorlesen"),
        "quizgeist-speaking__read quizgeist-touch-target"
      );
      this.readButton.addEventListener("click", () => {
        void this.speak(this.question.task);
      });
      this.feedbackBox = liveElement("div", "quizgeist-speaking__feedback", {
        "aria-live": "polite",
        role: "status"
      });
      this.textInput = liveElement("textarea", "quizgeist-speaking__text", {
        rows: 3,
        "aria-label": this.text("speaking:textlabel", "Antwort eintippen"),
        placeholder: this.text("speaking:textplaceholder", "Oder tippe deine Antwort.")
      });
      this.submitButton = liveButton(
        this.text("speaking:submittext", "Getippte Antwort abgeben"),
        "quizgeist-speaking__submit quizgeist-touch-target"
      );
      this.submitButton.addEventListener("click", () => {
        void this.submitTyped();
      });
      const controls = liveElement("div", "quizgeist-speaking__controls");
      controls.append(this.readButton);
      this.root.append(this.promptBox, controls, this.feedbackBox);
      if (SpokenAnswerControl.available()) {
        this.control = new SpokenAnswerControl(strings, { ...clipConfig, purpose: "speaking" }, {
          onClip: (clip) => {
            void this.submitSpoken(clip);
          },
          transcribe: callbacks.transcribe,
          pollState: callbacks.pollState
        });
        controls.append(this.control.root);
      } else {
        const notice = liveElement("p", "quizgeist-speaking__notice", {
          text: this.text(
            "speaking:nomic",
            "Ohne Mikrofon geht es auch: tippe deine Antwort ein \u2014 sie wird genauso bewertet."
          )
        });
        this.root.append(notice);
      }
      const typed = liveElement("div", "quizgeist-speaking__typed");
      typed.append(this.textInput, this.submitButton);
      this.root.append(typed);
    }
    /**
     * Start a new round.
     */
    setQuestion(question2, autoRead = true) {
      this.question = {
        task: question2.task,
        accepted: Array.isArray(question2.accepted) ? question2.accepted : []
      };
      this.promptBox.textContent = this.question.task;
      this.feedbackBox.replaceChildren();
      this.textInput.value = "";
      if (autoRead) {
        void this.speak(this.question.task);
      }
    }
    dispose() {
      var _a;
      (_a = this.control) == null ? void 0 : _a.dispose();
    }
    text(key, fallback) {
      return liveString(this.strings, key, {}, fallback);
    }
    async speak(text4) {
      if (this.tts === null || text4.trim() === "") {
        return;
      }
      try {
        await this.tts.play(text4);
      } catch {
      }
    }
    async submitSpoken(clip) {
      await this.submit({ clipId: clip.id });
    }
    async submitTyped() {
      const text4 = this.textInput.value.trim();
      if (text4 === "") {
        this.showMessage(this.text("speaking:empty", "Bitte sag oder schreib etwas."));
        return;
      }
      await this.submit({ text: text4 });
    }
    async submit(payload) {
      if (this.busy) {
        return;
      }
      this.busy = true;
      this.submitButton.disabled = true;
      this.showMessage(this.text("speaking:working", "R\xFCckmeldung wird erstellt \u2026"));
      try {
        const result = await this.callbacks.submitTurn({
          ...payload,
          task: this.question.task,
          accepted: this.question.accepted
        });
        this.renderResult(result);
        await this.speak(result.feedback);
      } catch {
        this.showMessage(this.text(
          "speaking:failed",
          "Die R\xFCckmeldung konnte nicht erstellt werden. Versuch es noch einmal."
        ));
      } finally {
        this.busy = false;
        this.submitButton.disabled = false;
      }
    }
    showMessage(message) {
      this.feedbackBox.replaceChildren(
        liveElement("p", "quizgeist-speaking__message", { text: message })
      );
    }
    renderResult(result) {
      const score = liveElement("p", "quizgeist-speaking__score", {
        // Never only a colour: the pass/fail state is also a word.
        text: liveString(
          this.strings,
          result.passed ? "speaking:score:passed" : "speaking:score:open",
          { score: result.score },
          result.passed ? `Erreicht: ${result.score} von 100 \u2014 das reicht.` : `Erreicht: ${result.score} von 100 \u2014 da geht noch was.`
        )
      });
      const feedback = liveElement("p", "quizgeist-speaking__text-feedback", {
        text: result.feedback
      });
      const children = [score, feedback];
      if (result.transcript !== "") {
        children.push(liveElement("p", "quizgeist-speaking__transcript", {
          text: liveString(
            this.strings,
            "speaking:transcript",
            { text: result.transcript },
            `Verstanden: ${result.transcript}`
          )
        }));
      }
      if (result.origin === "fallback" && result.warnings.includes("gateway_unavailable")) {
        children.push(liveElement("p", "quizgeist-speaking__origin", {
          text: this.text(
            "speaking:origin:fallback",
            "Diese R\xFCckmeldung kommt aus dem Regelabgleich, nicht aus der KI."
          )
        }));
      }
      if (result.nextPrompt !== "") {
        children.push(liveElement("p", "quizgeist-speaking__next", {
          text: result.nextPrompt
        }));
      }
      this.feedbackBox.replaceChildren(...children);
    }
  };

  // src/selfstudy/types.ts
  var STUDY_MODES = [
    "solo",
    "practice",
    "test",
    "flashcards",
    "speaking"
  ];
  function isStudyMode(value2) {
    return typeof value2 === "string" && STUDY_MODES.includes(value2);
  }

  // src/selfstudy/normalise.ts
  function record(value2) {
    return value2 && typeof value2 === "object" && !Array.isArray(value2) ? value2 : {};
  }
  function records(value2) {
    return Array.isArray(value2) ? value2.map(record).filter((entry) => Object.keys(entry).length > 0) : [];
  }
  function finiteNumber(value2, fallback = 0) {
    const candidate = typeof value2 === "number" ? value2 : typeof value2 === "string" && value2.trim() !== "" ? Number(value2) : Number.NaN;
    return Number.isFinite(candidate) ? candidate : fallback;
  }
  function reviewSummary(value2) {
    const source = record(value2);
    if (source.available !== true) {
      return null;
    }
    return {
      available: true,
      deferred: source.deferred === true,
      entries: records(source.entries).map((entry) => ({
        explanation: text2(entry.explanation),
        index: integer(entry.index),
        questionText: text2(entry.questionText)
      })).filter((entry) => entry.explanation !== ""),
      policy: text2(source.policy, "immediate"),
      total: Math.max(0, integer(source.total)),
      withExplanation: Math.max(0, integer(source.withExplanation))
    };
  }
  function integer(value2, fallback = 0) {
    const candidate = finiteNumber(value2, fallback);
    return Number.isInteger(candidate) ? candidate : fallback;
  }
  function positiveInteger(value2) {
    const candidate = integer(value2);
    return candidate > 0 ? candidate : null;
  }
  function text2(value2, fallback = "") {
    return typeof value2 === "string" ? value2 : fallback;
  }
  function bool(value2, fallback = false) {
    return typeof value2 === "boolean" ? value2 : fallback;
  }
  function milliseconds(value2) {
    const candidate = finiteNumber(value2);
    if (candidate <= 0) {
      return 0;
    }
    return candidate < 1e11 ? candidate * 1e3 : candidate;
  }
  function mode(value2, fallback = "practice") {
    return isStudyMode(value2) ? value2 : fallback;
  }
  function assignmentStatus(value2) {
    return value2 === "draft" || value2 === "open" || value2 === "closed" || value2 === "archived" ? value2 : "closed";
  }
  function attemptStatus(value2) {
    return value2 === "completed" || value2 === "abandoned" ? value2 : "inprogress";
  }
  function assignmentSettings(value2, timeDueMs) {
    const source = record(value2);
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
  function gradeSummary(value2) {
    const source = record(value2);
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
      const parsed = record(candidate);
      if (positiveInteger(parsed.id)) {
        return parsed;
      }
    }
    return {};
  }
  function assignmentSummary(value2, completedHint = false, attemptOverride = {}) {
    var _a, _b;
    const source = record(value2);
    const attempt = Object.keys(attemptOverride).length > 0 ? attemptOverride : attemptReference(source);
    const questionCount = Math.max(0, integer(
      source.questionCount,
      integer(record(source.progress).total)
    ));
    const completed = completedHint || bool(source.completed) || text2(attempt.status) === "completed";
    const answered = Math.max(0, Math.min(questionCount, integer(
      record(source.progress).answered,
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
      name: text2(source.name),
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
  function weeklyGoal(value2) {
    var _a, _b, _c, _d;
    const source = record(value2);
    return {
      configured: bool(source.configured),
      progress: Math.max(0, integer(source.progress)),
      target: Math.max(1, integer(source.target, 20)),
      ...milliseconds((_a = source.weekEndMs) != null ? _a : source.weekEnd) > 0 ? { weekEndMs: milliseconds((_b = source.weekEndMs) != null ? _b : source.weekEnd) } : {},
      ...milliseconds((_c = source.weekStartMs) != null ? _c : source.weekStart) > 0 ? { weekStartMs: milliseconds((_d = source.weekStartMs) != null ? _d : source.weekStart) } : {}
    };
  }
  function normaliseOverview(value2) {
    const source = record(value2);
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
  function question(value2) {
    const source = record(value2);
    if (!positiveInteger(source.id) || text2(source.questionToken) === "" || text2(source.qtype) === "") {
      return null;
    }
    return source;
  }
  function projectedResult(value2) {
    const source = record(value2);
    const presented = question(source.question);
    if (!presented) {
      return null;
    }
    return {
      answer: liveAnswerFromPayload(source.answer),
      correct: typeof source.correct === "boolean" ? source.correct : null,
      explanation: text2(source.explanation),
      maxPoints: Math.max(0, integer(source.maxPoints)),
      points: Math.max(0, integer(source.points)),
      question: presented
    };
  }
  function legacyResult(value2, forcedDisclosure = false) {
    const source = record(value2);
    const presented = question(source);
    if (!presented) {
      return null;
    }
    const submission = record(source.submission);
    const rowStatus = text2(source.attemptQuestionStatus);
    const disclosed = forcedDisclosure || rowStatus === "revealed" || rowStatus === "submitted";
    if (!disclosed) {
      return null;
    }
    return {
      answer: liveAnswerFromPayload(submission.answer),
      correct: typeof submission.isCorrect === "boolean" ? submission.isCorrect : null,
      explanation: text2(source.explanation),
      maxPoints: Math.max(0, integer(submission.maxPoints)),
      points: Math.max(0, integer(submission.points)),
      question: presented
    };
  }
  function normaliseAttemptState(value2) {
    var _a, _b, _c;
    const source = record(value2);
    const rawAttempt = record(source.attempt);
    const rawAssignment = record(source.assignment);
    const rawNavigation = record(source.navigation);
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
    const legacySubmission = record(record(source.question).submission);
    const flatResult = projectedResult(source.result);
    const currentResult = flatResult || legacyResult(
      source.question,
      status === "completed"
    );
    const flatResults = records(source.results).map(projectedResult).filter((entry) => entry !== null);
    const legacyReviews = records(source.reviews).map((entry) => legacyResult(entry, true)).filter((entry) => entry !== null);
    const rawFlashcards = record(source.flashcards);
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
          text2(record(source.question).attemptQuestionStatus) === "revealed"
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
  function teacherAssignment(value2) {
    var _a;
    const source = record(value2);
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
  function normaliseTeacherAssignmentList(value2) {
    const source = record(value2);
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
      const data2 = error.data;
      if (!data2 || typeof data2 !== "object" || Array.isArray(data2)) {
        return null;
      }
      const rawState = data2.state;
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

  // src/selfstudy/schedule.ts
  var integer2 = (value2) => typeof value2 === "number" && Number.isFinite(value2) && value2 > 0 ? Math.floor(value2) : 0;
  function normaliseDueCard(raw) {
    const empty = {
      dueCount: 0,
      generatedAt: 0,
      nextDue: null,
      openCount: 0,
      topics: [],
      trackedCount: 0
    };
    if (raw === null || typeof raw !== "object") {
      return empty;
    }
    const record2 = raw;
    const nextdue = integer2(record2.nextDue);
    return {
      dueCount: integer2(record2.dueCount),
      generatedAt: integer2(record2.generatedAt),
      nextDue: nextdue > 0 ? nextdue : null,
      openCount: integer2(record2.openCount),
      topics: Array.isArray(record2.topics) ? record2.topics.map(normaliseDueTopic).filter((entry) => entry !== null) : [],
      trackedCount: integer2(record2.trackedCount)
    };
  }
  function normaliseDueTopic(raw) {
    if (raw === null || typeof raw !== "object") {
      return null;
    }
    const record2 = raw;
    return {
      colorKey: typeof record2.colorKey === "string" && record2.colorKey !== "" ? record2.colorKey : null,
      dueCount: integer2(record2.dueCount),
      label: typeof record2.label === "string" ? record2.label : "",
      tagKey: typeof record2.tagKey === "string" ? record2.tagKey : "",
      untagged: record2.untagged === true
    };
  }
  function renderDueCard(card, text4, formatDate2) {
    const section = liveElement("section", "quizgeist-study-goal quizgeist-study-due");
    section.setAttribute("aria-labelledby", "quizgeist-study-due-title");
    section.dataset.quizgeistView = "study-due";
    const heading = liveElement("h3", "quizgeist-study-goal__title", {
      text: text4("schedule:due:title", "Deine Wiederholungen")
    });
    heading.id = "quizgeist-study-due-title";
    section.append(heading);
    if (card.openCount === 0) {
      section.append(liveElement("p", "quizgeist-study-goal__copy", {
        role: "status",
        text: card.nextDue !== null ? text4(
          "schedule:due:none:next",
          "Gerade ist nichts f\xE4llig. Weiter geht es am {$date}.",
          { date: formatDate2(card.nextDue * 1e3) }
        ) : text4(
          "schedule:due:none",
          "Gerade ist nichts f\xE4llig. Sobald du \xFCbst, plant Quizgeist die n\xE4chste Wiederholung."
        )
      }));
      return section;
    }
    section.append(liveElement("p", "quizgeist-study-goal__copy", {
      role: "status",
      text: text4(
        "schedule:due:summary",
        "{$count} Fragen warten auf eine Wiederholung.",
        { count: card.openCount }
      )
    }));
    if (card.topics.length > 0) {
      const list = liveElement("ul", "quizgeist-study-due-topics");
      card.topics.forEach((topic) => {
        const item = liveElement("li", "quizgeist-study-due-topic");
        if (topic.colorKey !== null) {
          item.dataset.competenceColor = topic.colorKey;
        }
        item.append(
          liveElement("span", "quizgeist-study-due-topic-label", {
            text: topic.untagged || topic.label === "" ? text4("schedule:due:untagged", "Ohne Thema") : topic.label
          }),
          liveElement("span", "quizgeist-study-due-topic-count", {
            text: text4(
              "schedule:due:topiccount",
              "{$count} f\xE4llig",
              { count: topic.dueCount }
            )
          })
        );
        list.append(item);
      });
      section.append(list);
    }
    return section;
  }
  function renderInterleavingHint(text4) {
    return liveElement("p", "quizgeist-study-interleaving-hint", {
      role: "note",
      text: text4(
        "schedule:interleaving:hint",
        "Das Thema wechselt bewusst: Gemischtes \xDCben bleibt l\xE4nger im Ged\xE4chtnis als \xDCben in Bl\xF6cken."
      )
    });
  }

  // src/workshop/student-form.ts
  var STATES = [
    "submitted",
    "revising",
    "approved",
    "rejected"
  ];
  var text3 = (value2) => typeof value2 === "string" ? value2 : "";
  var count = (value2) => typeof value2 === "number" && Number.isFinite(value2) && value2 > 0 ? Math.floor(value2) : 0;
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
    const record2 = raw;
    const range = record2.ratingRange !== null && typeof record2.ratingRange === "object" ? record2.ratingRange : {};
    return {
      canCurate: record2.canCurate === true,
      mine: normaliseSubmissions(record2.mine),
      openRatings: count(record2.openRatings),
      peers: normaliseSubmissions(record2.peers),
      queue: normaliseSubmissions(record2.queue),
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
    const record2 = raw;
    const id = count(record2.id);
    const state = text3(record2.state);
    if (id === 0 || !STATES.includes(state)) {
      return null;
    }
    const average = (value2) => typeof value2 === "number" && Number.isFinite(value2) ? value2 : null;
    const own = record2.ownRating !== null && typeof record2.ownRating === "object" ? record2.ownRating : null;
    return {
      aiCheck: normaliseAiCheck(record2.aiCheck),
      authorName: text3(record2.authorName),
      averageDifficulty: average(record2.averageDifficulty),
      averageQuality: average(record2.averageQuality),
      curatorNote: text3(record2.curatorNote),
      explanation: text3(record2.explanation),
      id,
      ownRating: own === null ? null : {
        comment: text3(own.comment),
        difficulty: count(own.difficulty),
        quality: count(own.quality)
      },
      qtype: text3(record2.qtype),
      questionId: count(record2.questionId),
      questionStatus: text3(record2.questionStatus),
      questionText: text3(record2.questionText),
      ratingCount: count(record2.ratingCount),
      rootId: count(record2.rootId),
      state,
      timeDecided: count(record2.timeDecided),
      timeSubmitted: count(record2.timeSubmitted)
    };
  }
  function normaliseAiCheck(raw) {
    if (raw === null || typeof raw !== "object") {
      return null;
    }
    const record2 = raw;
    const checks = Array.isArray(record2.checks) ? record2.checks.flatMap((entry) => {
      if (entry === null || typeof entry !== "object") {
        return [];
      }
      const item = entry;
      const key = text3(item.key);
      return key === "" ? [] : [{
        key,
        note: text3(item.note),
        verdict: text3(item.verdict) === "ok" ? "ok" : "attention"
      }];
    }) : [];
    return checks.length === 0 ? null : { checks, origin: text3(record2.origin) };
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
  function renderStudentForm(t, onSubmit) {
    const section = liveElement("section", "quizgeist-workshop-form", {
      "data-quizgeist-view": "workshop-form",
      "aria-labelledby": "quizgeist-workshop-form-title"
    });
    const heading = liveElement("h3", "quizgeist-workshop-form__title", {
      text: t("workshop:form:title", "Eigene Frage einreichen")
    });
    heading.id = "quizgeist-workshop-form-title";
    section.append(
      heading,
      liveElement("p", "quizgeist-workshop-form__copy", {
        text: t(
          "workshop:form:copy",
          "Schreibe eine Frage, markiere die richtige Antwort und erkl\xE4re, warum sie richtig ist. Deine Lehrkraft gibt sie danach frei."
        )
      })
    );
    const errors = liveElement("div", "quizgeist-workshop-errors", {
      role: "alert"
    });
    const questionField = liveElement("label", "quizgeist-workshop-field");
    questionField.append(
      liveElement("span", "quizgeist-workshop-field__label", {
        text: t("workshop:form:question", "Deine Frage")
      })
    );
    const questionInput = liveElement("textarea", "quizgeist-workshop-input", {
      rows: 3,
      maxlength: 400,
      required: true
    });
    questionField.append(questionInput);
    const answersField = liveElement("fieldset", "quizgeist-workshop-answers");
    answersField.append(liveElement("legend", "quizgeist-workshop-field__label", {
      text: t("workshop:form:answers", "Antwortm\xF6glichkeiten")
    }));
    const answerInputs = [];
    const correctInputs = [];
    ["a", "b", "c", "d"].forEach((key, index) => {
      const row = liveElement("div", "quizgeist-workshop-answer");
      const correct = liveElement("input", "quizgeist-workshop-correct", {
        type: "radio",
        name: "quizgeist-workshop-correct",
        value: key,
        checked: index === 0,
        "aria-label": t(
          "workshop:form:correct",
          "Antwort {$index} ist richtig",
          { index: index + 1 }
        )
      });
      const answer = liveElement("input", "quizgeist-workshop-input", {
        type: "text",
        maxlength: 255,
        "aria-label": t(
          "workshop:form:answer",
          "Antwort {$index}",
          { index: index + 1 }
        )
      });
      row.append(correct, answer);
      answersField.append(row);
      answerInputs.push(answer);
      correctInputs.push(correct);
    });
    const explanationField = liveElement("label", "quizgeist-workshop-field");
    explanationField.append(
      liveElement("span", "quizgeist-workshop-field__label", {
        text: t("workshop:form:explanation", "Warum ist das richtig? (Pflicht)")
      })
    );
    const explanationInput = liveElement("textarea", "quizgeist-workshop-input", {
      rows: 4,
      maxlength: 2e3,
      required: true,
      "aria-describedby": "quizgeist-workshop-explanation-hint"
    });
    const explanationHint = liveElement("span", "quizgeist-workshop-field__hint", {
      text: t(
        "workshop:form:explanation:hint",
        "Ohne Erkl\xE4rung wird die Frage nicht angenommen."
      )
    });
    explanationHint.id = "quizgeist-workshop-explanation-hint";
    explanationField.append(explanationInput, explanationHint);
    const submit = liveButton(
      t("workshop:form:submit", "Frage einreichen"),
      "quizgeist-button quizgeist-button--primary quizgeist-workshop-submit",
      { "data-workshop-action": "submit" }
    );
    submit.addEventListener("click", () => {
      onSubmit({
        answers: answerInputs.map((input, index) => {
          var _a;
          return {
            correct: ((_a = correctInputs[index]) == null ? void 0 : _a.checked) === true,
            text: input.value
          };
        }),
        explanation: explanationInput.value,
        questiontext: questionInput.value
      });
    });
    section.append(errors, questionField, answersField, explanationField, submit);
    return section;
  }
  function showFormErrors(form, fieldErrors, t) {
    const region = form.querySelector(".quizgeist-workshop-errors");
    if (!region) {
      return;
    }
    region.replaceChildren();
    if (fieldErrors.length === 0) {
      return;
    }
    const list = liveElement("ul", "quizgeist-workshop-errors__list");
    const seen = /* @__PURE__ */ new Set();
    fieldErrors.forEach((error) => {
      const message = workshopErrorMessage(error, t);
      if (seen.has(message)) {
        return;
      }
      seen.add(message);
      list.append(liveElement("li", "quizgeist-workshop-errors__item", {
        text: message
      }));
    });
    region.append(list);
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
      const record2 = entry;
      const field = text3(record2.field);
      const code = text3(record2.code);
      return field === "" || code === "" ? [] : [{ code, field }];
    });
  }
  function submitPayload(draft) {
    const keys = ["a", "b", "c", "d"];
    const answers = draft.answers.map((answer, index) => ({
      correct: answer.correct,
      id: keys[index] || `x${index}`,
      media: null,
      text: answer.text.trim()
    })).filter((answer, index) => answer.text !== "" || index < 2);
    return {
      question: {
        explanation: draft.explanation.trim(),
        options: {
          answers,
          media: null,
          multiple: false
        },
        pointmode: "standard",
        qtype: "quiz",
        questiontext: draft.questiontext.trim(),
        timelimit: 20
      }
    };
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

  // src/workshop/peer-list.ts
  function renderPeerList(view, t, onRate) {
    const section = liveElement("section", "quizgeist-workshop-peers", {
      "data-quizgeist-view": "workshop-peer",
      "aria-labelledby": "quizgeist-workshop-peers-title"
    });
    const heading = liveElement("h3", "quizgeist-workshop-peers__title", {
      text: t("workshop:peers:title", "Fragen deiner Klasse")
    });
    heading.id = "quizgeist-workshop-peers-title";
    section.append(
      heading,
      liveElement("p", "quizgeist-workshop-peers__copy", {
        role: "status",
        text: view.openRatings > 0 ? t(
          "workshop:peers:open",
          "F\xFCr {$count} Fragen fehlt noch deine R\xFCckmeldung.",
          { count: view.openRatings }
        ) : t("workshop:peers:done", "Du hast allen Fragen eine R\xFCckmeldung gegeben.")
      })
    );
    if (view.mine.length > 0) {
      section.append(renderOwnList(view.mine, t));
    }
    if (view.peers.length === 0) {
      section.append(liveElement("p", "quizgeist-workshop-empty", {
        text: t(
          "workshop:peers:empty",
          "Noch hat niemand eine Frage eingereicht. Du kannst die erste schreiben."
        )
      }));
      return section;
    }
    const list = liveElement("ul", "quizgeist-workshop-list");
    view.peers.forEach((submission) => {
      list.append(renderPeerItem(submission, view, t, onRate));
    });
    section.append(list);
    return section;
  }
  function renderOwnList(submissions, t) {
    const wrapper = liveElement("div", "quizgeist-workshop-own");
    wrapper.append(liveElement("h4", "quizgeist-workshop-own__title", {
      text: t("workshop:mine:title", "Deine Fragen")
    }));
    const list = liveElement("ul", "quizgeist-workshop-list");
    submissions.forEach((submission) => {
      const item = liveElement("li", "quizgeist-workshop-item", {
        "data-workshop-state": submission.state
      });
      item.append(
        liveElement("p", "quizgeist-workshop-item__question", {
          text: submission.questionText
        }),
        liveElement("p", "quizgeist-workshop-item__state", {
          text: stateSentence(submission.state, t)
        })
      );
      if (submission.curatorNote !== "") {
        item.append(liveElement("p", "quizgeist-workshop-item__note", {
          text: submission.curatorNote
        }));
      }
      list.append(item);
    });
    wrapper.append(list);
    return wrapper;
  }
  function renderPeerItem(submission, view, t, onRate) {
    var _a, _b, _c, _d, _e, _f, _g, _h;
    const item = liveElement("li", "quizgeist-workshop-item", {
      "data-workshop-state": submission.state,
      "data-workshop-id": submission.id
    });
    item.append(
      liveElement("p", "quizgeist-workshop-item__question", {
        text: submission.questionText
      }),
      liveElement("p", "quizgeist-workshop-item__explanation", {
        text: submission.explanation
      }),
      liveElement("p", "quizgeist-workshop-item__figures", {
        text: submission.ratingCount > 0 ? t(
          "workshop:peers:figures",
          "{$count} R\xFCckmeldungen \xB7 Qualit\xE4t {$quality} \xB7 Schwierigkeit {$difficulty}",
          {
            count: submission.ratingCount,
            difficulty: String((_a = submission.averageDifficulty) != null ? _a : 0),
            quality: String((_b = submission.averageQuality) != null ? _b : 0)
          }
        ) : t("workshop:peers:norating", "Noch keine R\xFCckmeldung.")
      })
    );
    const form = liveElement("div", "quizgeist-workshop-rating");
    const quality = renderScale2(
      "quality",
      t("workshop:rating:quality", "Wie gut ist die Frage?"),
      view.ratingRange,
      (_d = (_c = submission.ownRating) == null ? void 0 : _c.quality) != null ? _d : 0
    );
    const difficulty = renderScale2(
      "difficulty",
      t("workshop:rating:difficulty", "Wie schwer war sie?"),
      view.ratingRange,
      (_f = (_e = submission.ownRating) == null ? void 0 : _e.difficulty) != null ? _f : 0
    );
    const comment = liveElement("input", "quizgeist-workshop-input", {
      type: "text",
      maxlength: 500,
      value: (_h = (_g = submission.ownRating) == null ? void 0 : _g.comment) != null ? _h : "",
      "aria-label": t("workshop:rating:comment", "Kurze R\xFCckmeldung")
    });
    const save = liveButton(
      t("workshop:rating:save", "R\xFCckmeldung speichern"),
      "quizgeist-button quizgeist-button--secondary",
      { "data-workshop-action": "rate" }
    );
    save.addEventListener("click", () => {
      onRate(submission.id, {
        comment: comment.value,
        difficulty: Number(difficulty.select.value || 0),
        quality: Number(quality.select.value || 0)
      });
    });
    form.append(quality.field, difficulty.field, comment, save);
    item.append(form);
    return item;
  }
  function renderScale2(name, label, range, current) {
    const field = liveElement("label", "quizgeist-workshop-field");
    field.append(liveElement("span", "quizgeist-workshop-field__label", { text: label }));
    const select = liveElement("select", "quizgeist-workshop-select", {
      "data-workshop-scale": name
    });
    for (let step = range.min; step <= range.max; step += 1) {
      select.append(liveElement("option", "", {
        value: String(step),
        text: String(step),
        selected: step === current
      }));
    }
    field.append(select);
    return { field, select };
  }

  // src/selfstudy/ui.ts
  function studyText(config, key, fallback, values = {}) {
    let text4 = config.strings[key] || fallback;
    Object.entries(values).forEach(([name, value2]) => {
      text4 = text4.split(`{$a->${name}}`).join(String(value2));
      text4 = text4.split(`{$${name}}`).join(String(value2));
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

  // src/selfstudy/student-app.ts
  var StudentSelfStudyApp = class {
    constructor(root, config) {
      this.root = root;
      this.config = config;
      __publicField(this, "api");
      __publicField(this, "answerDraft", null);
      __publicField(this, "busy", false);
      __publicField(this, "liveRegion");
      __publicField(this, "dueCard", null);
      __publicField(this, "workshop", null);
      __publicField(this, "overview", null);
      __publicField(this, "stage");
      __publicField(this, "state", null);
      __publicField(this, "tts");
      /** F12: einmal gebaut und wiederverwendet, damit ein Neuzeichnen keine
          laufende Aufnahme abbricht und das Mikrofon nicht erneut erfragt. */
      __publicField(this, "speaking", null);
      this.api = new SelfStudyApi(config);
      this.tts = new TtsPlayer(config);
      this.liveRegion = liveElement("div", "quizgeist-live-visually-hidden", {
        "aria-atomic": "true",
        "aria-live": "polite",
        role: "status"
      });
      this.stage = liveElement("div", "quizgeist-study-stage");
    }
    async init() {
      this.root.classList.add("quizgeist-study-root");
      this.root.dataset.quizgeistRoot = "selfstudy";
      this.root.dataset.quizgeistTheme = this.config.theme || "hell";
      this.root.dataset.quizgeistSeason = this.config.season || "herbst";
      this.root.replaceChildren(this.liveRegion, this.stage);
      if (this.config.attemptId > 0) {
        await this.loadAttempt(this.config.attemptId);
      } else if (this.config.assignmentId > 0) {
        await this.startAssignment(this.config.assignmentId);
      } else {
        await this.loadOverview();
      }
    }
    text(key, fallback, values = {}) {
      return studyText(this.config, key, fallback, values);
    }
    replaceStage(content, focus = true) {
      this.stage.replaceChildren(content);
      if (focus) {
        window.setTimeout(() => {
          var _a;
          (_a = content.querySelector("[data-study-heading]")) == null ? void 0 : _a.focus();
        }, 0);
      }
    }
    announce(message) {
      this.liveRegion.textContent = "";
      window.requestAnimationFrame(() => {
        this.liveRegion.textContent = message;
      });
    }
    async loadOverview() {
      this.state = null;
      this.answerDraft = null;
      this.busy = true;
      this.replaceStage(loadingCard(this.config, "selfstudy:loading:overview"), false);
      try {
        this.overview = await this.api.overview();
        try {
          this.dueCard = normaliseDueCard(await this.api.dueCard());
        } catch (_error) {
          this.dueCard = null;
        }
        try {
          this.workshop = normaliseWorkshopView(await this.api.workshop());
        } catch (_error) {
          this.workshop = null;
        }
        this.busy = false;
        this.renderOverview();
        this.updateLocation();
      } catch (error) {
        this.busy = false;
        this.replaceStage(errorCard(
          this.config,
          this.api.errorMessage(error),
          () => void this.loadOverview()
        ));
      }
    }
    renderOverview() {
      const overview = this.overview;
      if (!overview) {
        return;
      }
      const main = liveElement("main", "quizgeist-study-overview");
      const header = liveElement("header", "quizgeist-study-overview__header");
      const heading = liveElement("h2", "quizgeist-study-title", {
        text: this.text("selfstudy:overview:title", "Dein Lernbereich")
      });
      heading.dataset.studyHeading = "";
      heading.tabIndex = -1;
      header.append(
        heading,
        liveElement("p", "quizgeist-study-copy", {
          text: this.text(
            "selfstudy:overview:description",
            "Lerne in deinem Tempo und behalte deine Ziele im Blick."
          )
        })
      );
      main.append(
        header,
        this.renderWeeklyGoal(overview),
        this.renderGradeSummary(overview.gradeSummary)
      );
      if (this.dueCard !== null) {
        main.append(renderDueCard(
          this.dueCard,
          (key, fallback, values) => this.text(key, fallback, values),
          formatDate
        ));
      }
      const openSection = liveElement("section", "quizgeist-study-section", {
        "aria-labelledby": "quizgeist-study-open-heading"
      });
      openSection.append(liveElement("h3", "quizgeist-study-section__title", {
        id: "quizgeist-study-open-heading",
        text: this.text("selfstudy:overview:open", "Offene Zuweisungen")
      }));
      if (overview.openAssignments.length === 0) {
        openSection.append(this.renderEmptyState());
      } else {
        const grid = liveElement("div", "quizgeist-study-assignment-grid");
        overview.openAssignments.forEach((assignment) => {
          grid.append(this.renderAssignmentCard(
            assignment,
            overview.serverTimeMs,
            false
          ));
        });
        openSection.append(grid);
      }
      main.append(openSection);
      if (this.workshop !== null) {
        main.append(this.renderWorkshop(this.workshop));
      }
      if (overview.completedAssignments.length > 0) {
        const completed = liveElement("details", "quizgeist-study-completed");
        const summary = liveElement("summary", "quizgeist-study-completed__summary");
        summary.append(
          icon("check"),
          document.createTextNode(this.text(
            "selfstudy:overview:completed",
            "Erledigt ({$count})",
            { count: overview.completedAssignments.length }
          ))
        );
        const grid = liveElement("div", "quizgeist-study-assignment-grid");
        overview.completedAssignments.forEach((assignment) => {
          grid.append(this.renderAssignmentCard(
            assignment,
            overview.serverTimeMs,
            true
          ));
        });
        completed.append(summary, grid);
        main.append(completed);
      }
      this.replaceStage(main);
    }
    /**
     * F7 Fragenwerkstatt: eigene Frage schreiben, fremde Fragen bewerten.
     *
     * Die Maske schickt Inhalt, nie Lebenszyklus. `status` gibt es in der
     * Nutzlast nicht, und der Server wuerde ihn ohnehin ueberschreiben
     * (peer_service::submit()).
     */
    renderWorkshop(view) {
      const wrapper = liveElement("section", "quizgeist-study-section", {
        "aria-labelledby": "quizgeist-workshop-form-title"
      });
      const form = renderStudentForm(
        (key, fallback, values) => this.text(key, fallback, values),
        (draft) => void this.submitWorkshopQuestion(form, draft)
      );
      wrapper.append(form);
      wrapper.append(renderPeerList(
        view,
        (key, fallback, values) => this.text(key, fallback, values),
        (submissionId, rating) => void this.rateWorkshopQuestion(
          submissionId,
          rating
        )
      ));
      return wrapper;
    }
    async submitWorkshopQuestion(form, draft) {
      try {
        const result = await this.api.workshopSubmit(submitPayload(draft));
        const errors = readFieldErrors(result);
        showFormErrors(
          form,
          errors,
          (key, fallback, values) => this.text(key, fallback, values)
        );
        if (errors.length === 0) {
          await this.loadOverview();
        }
      } catch (error) {
        this.announce(this.api.errorMessage(error));
      }
    }
    async rateWorkshopQuestion(submissionId, rating) {
      try {
        await this.api.workshopRate(submissionId, rating);
        await this.loadOverview();
      } catch (error) {
        this.announce(this.api.errorMessage(error));
      }
    }
    renderWeeklyGoal(overview) {
      const goal = overview.weeklyGoal;
      const section = liveElement("section", "quizgeist-study-goal", {
        "aria-labelledby": "quizgeist-study-goal-heading"
      });
      const copy = goal.progress >= goal.target ? this.text(
        "selfstudy:goal:reached",
        "Geschafft! Alles Weitere ist dein pers\xF6nlicher Bonus."
      ) : this.text(
        "selfstudy:goal:encouragement",
        "Jede bearbeitete Frage bringt dich deinem Ziel n\xE4her."
      );
      const title = liveElement("div", "quizgeist-study-goal__title");
      title.append(
        icon("goal"),
        liveElement("h3", "", {
          id: "quizgeist-study-goal-heading",
          text: this.text("selfstudy:goal:title", "Mein Wochenziel")
        })
      );
      const progress = liveElement("progress", "quizgeist-study-goal__progress", {
        max: Math.max(1, goal.target),
        value: Math.min(goal.progress, Math.max(1, goal.target))
      });
      progress.textContent = `${goal.progress} / ${goal.target}`;
      const progressText = liveElement("strong", "quizgeist-study-goal__value", {
        text: this.text(
          "selfstudy:goal:progress",
          "{$progress} / {$target} Fragen",
          { progress: goal.progress, target: goal.target }
        )
      });
      const form = liveElement("form", "quizgeist-study-goal__form");
      const label = liveElement("label", "", {
        for: "quizgeist-weekly-goal",
        text: this.text("selfstudy:goal:edit", "Ziel bearbeiten")
      });
      const input = liveElement("input", "quizgeist-study-input quizgeist-study-input--number", {
        id: "quizgeist-weekly-goal",
        inputmode: "numeric",
        max: 500,
        min: 1,
        name: "target",
        required: true,
        type: "number",
        value: goal.target
      });
      const save = studyButton(
        this.text("selfstudy:goal:save", "Speichern"),
        "secondary"
      );
      save.type = "submit";
      if (goal.configured || overview.canConfigureWeeklyGoal) {
        form.addEventListener("submit", (event) => {
          event.preventDefault();
          const target = Number(input.value);
          if (Number.isInteger(target) && target >= 1 && target <= 500) {
            void this.saveGoal(target, save);
          } else {
            input.focus();
            input.reportValidity();
          }
        });
        form.append(label, input, save);
      }
      section.append(
        title,
        progressText,
        progress,
        liveElement("p", "quizgeist-study-goal__copy", { text: copy })
      );
      if (form.childElementCount > 0) {
        section.append(form);
      } else {
        section.append(liveElement("p", "quizgeist-study-goal__copy", {
          role: "status",
          text: this.text(
            "selfstudy:goal:locked",
            "Ein neues pers\xF6nliches Wochenziel ist derzeit nicht verf\xFCgbar."
          )
        }));
      }
      return section;
    }
    renderGradeSummary(grade) {
      const method = this.text(
        `selfstudy:grade:${grade.method}`,
        {
          average: "Durchschnitt aller Wertungsversuche",
          best: "Bester Wertungsversuch",
          last: "Letzter Wertungsversuch"
        }[grade.method]
      );
      const section = liveElement(
        "section",
        "quizgeist-study-goal quizgeist-study-grade",
        { "aria-labelledby": "quizgeist-study-grade-heading" }
      );
      section.append(liveElement("h3", "", {
        id: "quizgeist-study-grade-heading",
        text: this.text("selfstudy:grade:title", "Dein Bewertungsstand")
      }));
      if (grade.percent === null) {
        section.append(liveElement("p", "quizgeist-study-goal__copy", {
          text: this.text(
            "selfstudy:grade:empty",
            "Noch kein gewerteter Versuch \xB7 Berechnung: {$method}",
            { method }
          )
        }));
        return section;
      }
      const percent = Math.round(grade.percent * 10) / 10;
      section.append(liveElement("strong", "quizgeist-study-goal__value", {
        text: this.text(
          "selfstudy:grade:value",
          "{$percent} % aus {$count} Wertungsversuchen \xB7 {$method}",
          { count: grade.attemptCount, method, percent }
        )
      }));
      return section;
    }
    async saveGoal(target, button2) {
      if (this.busy) {
        return;
      }
      this.setBusy(true, button2);
      try {
        this.overview = await this.api.saveWeeklyGoal(target);
        this.busy = false;
        this.renderOverview();
        this.announce(this.text("selfstudy:goal:saved", "Wochenziel gespeichert."));
      } catch (error) {
        this.announce(this.api.errorMessage(error));
        this.setBusy(false, button2);
      }
    }
    renderEmptyState() {
      const empty = liveElement("div", "quizgeist-study-empty");
      if (this.config.brandIconUrl !== "") {
        empty.append(liveElement("img", "quizgeist-study-empty__illustration", {
          alt: "",
          src: this.config.brandIconUrl
        }));
      }
      empty.append(
        liveElement("h4", "", {
          text: this.text("selfstudy:empty:title", "Aktuell nichts offen")
        }),
        liveElement("p", "", {
          text: this.text(
            "selfstudy:empty:description",
            "Schau sp\xE4ter wieder vorbei \u2013 neue Lernaufgaben erscheinen hier."
          )
        })
      );
      return empty;
    }
    renderAssignmentCard(assignment, serverTimeMs, completed) {
      const card = liveElement("article", `quizgeist-study-assignment quizgeist-study-assignment--${assignment.mode}`);
      const header = liveElement("header", "quizgeist-study-assignment__header");
      header.append(
        liveElement("span", "quizgeist-study-mode", {
          text: modeLabel(this.config, assignment.mode)
        }),
        deadlineBadge(this.config, assignment.timeDueMs, serverTimeMs)
      );
      const heading = liveElement("h4", "quizgeist-study-assignment__title", {
        text: assignment.name
      });
      const progress = assignment.progress;
      const progressNode = liveElement("div", "quizgeist-study-assignment__progress");
      if (assignment.mode === "flashcards") {
        const ratio = progress.total > 0 ? Math.min(100, Math.round(progress.answered * 100 / progress.total)) : 0;
        const ring = liveElement("div", "quizgeist-study-progress-ring", {
          "aria-label": this.text(
            "selfstudy:flashcards:knownprogress",
            "{$known} von {$total} gewusst",
            { known: progress.answered, total: progress.total }
          ),
          role: "img"
        });
        ring.style.setProperty("--mq-study-progress", `${ratio}%`);
        ring.append(liveElement("strong", "", { text: `${ratio} %` }));
        progressNode.append(ring);
      } else if (progress.total > 0) {
        const bar = liveElement("progress", "", {
          max: progress.total,
          value: Math.min(progress.answered, progress.total)
        });
        bar.textContent = `${progress.answered} / ${progress.total}`;
        progressNode.append(
          bar,
          liveElement("span", "", {
            text: this.text(
              "selfstudy:assignment:progress",
              "{$answered} / {$total} bearbeitet",
              { answered: progress.answered, total: progress.total }
            )
          })
        );
      }
      const attempts = this.text(
        "selfstudy:assignment:attempts",
        "Versuche: {$used} / {$max}",
        { max: assignment.maxAttempts, used: assignment.attemptsUsed }
      );
      const grading = assignment.countsTowardsGrade && (assignment.mode === "solo" || assignment.mode === "test") ? this.text(
        "selfstudy:assignment:graded",
        "Z\xE4hlt zum Bewertungsstand"
      ) : this.text(
        "selfstudy:assignment:ungraded",
        "Z\xE4hlt nicht zum Bewertungsstand"
      );
      const assignmentMeta = liveElement(
        "p",
        "quizgeist-study-assignment__availability",
        { text: `${attempts} \xB7 ${grading}` }
      );
      const action = studyButton(
        completed ? this.text("selfstudy:assignment:review", "Ergebnis ansehen") : assignment.attemptId ? this.text("selfstudy:assignment:resume", "Weiterlernen") : assignment.available ? this.text("selfstudy:assignment:start", "Starten") : this.text(
          "selfstudy:assignment:unavailable",
          "Noch nicht verf\xFCgbar"
        ),
        completed ? "secondary" : "primary"
      );
      action.disabled = !assignment.available && !assignment.attemptId || completed && !assignment.attemptId;
      action.addEventListener("click", () => {
        if (assignment.attemptId) {
          void this.loadAttempt(assignment.attemptId);
        } else {
          void this.startAssignment(assignment.id, action);
        }
      });
      card.append(header, heading, progressNode, assignmentMeta);
      if (!completed && !assignment.available && assignment.timeOpenMs > serverTimeMs) {
        card.append(liveElement("p", "quizgeist-study-assignment__availability", {
          text: this.text(
            "selfstudy:assignment:opens",
            "Verf\xFCgbar ab {$date}",
            { date: formatDate(assignment.timeOpenMs) }
          )
        }));
      }
      const actions = liveElement("div", "quizgeist-study-navigation__actions");
      actions.append(action);
      if (completed && assignment.canRetry) {
        const retry = studyButton(
          this.text("selfstudy:action:retry", "Erneut versuchen"),
          "primary"
        );
        retry.addEventListener("click", () => {
          void this.startAssignment(assignment.id, retry, true);
        });
        actions.append(retry);
      }
      card.append(actions);
      return card;
    }
    async startAssignment(assignmentId, button2, newAttempt = false) {
      if (this.busy) {
        return;
      }
      this.setBusy(true, button2);
      if (!button2) {
        this.replaceStage(loadingCard(this.config, "selfstudy:loading:attempt"), false);
      }
      try {
        const result = await this.api.start(assignmentId, newAttempt);
        this.applyState(result.state);
      } catch (error) {
        this.setBusy(false, button2);
        this.replaceStage(errorCard(
          this.config,
          this.api.errorMessage(error),
          () => void this.startAssignment(assignmentId, void 0, newAttempt)
        ));
      }
    }
    async loadAttempt(attemptId) {
      if (this.busy) {
        return;
      }
      this.busy = true;
      this.replaceStage(loadingCard(this.config, "selfstudy:loading:attempt"), false);
      try {
        const result = await this.api.state(attemptId);
        this.applyState(result.state);
      } catch (error) {
        this.busy = false;
        this.replaceStage(errorCard(
          this.config,
          this.api.errorMessage(error),
          () => void this.loadAttempt(attemptId)
        ));
      }
    }
    applyState(next) {
      var _a, _b, _c;
      const previousQuestion = (_a = this.state) == null ? void 0 : _a.question;
      const changedQuestion = (previousQuestion == null ? void 0 : previousQuestion.id) !== ((_b = next.question) == null ? void 0 : _b.id) || (previousQuestion == null ? void 0 : previousQuestion.questionToken) !== ((_c = next.question) == null ? void 0 : _c.questionToken);
      this.state = next;
      this.busy = false;
      if (changedQuestion) {
        this.answerDraft = next.ownAnswer;
        this.tts.stop();
      } else if (next.ownAnswer) {
        this.answerDraft = next.ownAnswer;
      }
      this.renderAttempt();
      this.updateLocation(next);
    }
    renderAttempt() {
      const state = this.state;
      if (!state) {
        return;
      }
      if (state.status === "completed") {
        this.renderCompleted(state);
        return;
      }
      const main = liveElement("main", "quizgeist-study-attempt");
      const header = liveElement("header", "quizgeist-study-attempt__header");
      const back = studyButton(
        this.text("selfstudy:action:overview", "Zur \xDCbersicht"),
        "quiet"
      );
      back.addEventListener("click", () => void this.loadOverview());
      const identity = liveElement("div", "quizgeist-study-attempt__identity");
      identity.append(
        liveElement("span", "quizgeist-study-mode", {
          text: modeLabel(this.config, state.mode)
        }),
        liveElement("h2", "quizgeist-study-title", {
          text: state.assignment.name
        })
      );
      header.append(back, identity);
      main.append(header);
      const progress = liveElement("div", "quizgeist-study-attempt__progress");
      progress.append(
        liveElement("span", "", {
          text: this.text(
            "selfstudy:attempt:progress",
            "Frage {$current} von {$total}",
            { current: state.currentIndex + 1, total: state.total }
          )
        }),
        liveElement("progress", "", {
          max: Math.max(1, state.total),
          value: Math.min(state.currentIndex + 1, Math.max(1, state.total))
        })
      );
      main.append(progress);
      if (state.assignment.selectionStrategy === "interleaved") {
        main.append(renderInterleavingHint(
          (key, fallback, values) => this.text(key, fallback, values)
        ));
      }
      if (!state.canAnswer) {
        main.append(liveElement("p", "quizgeist-study-test-note", {
          role: "status",
          text: this.text(
            "selfstudy:attempt:readonly",
            "Weitere Antworten sind nicht mehr m\xF6glich. Bereits gespeicherte Antworten bleiben erhalten."
          )
        }));
      }
      if (state.mode === "speaking") {
        main.append(this.renderSpeaking(state));
        main.append(this.renderAttemptNavigation(state));
      } else if (state.mode === "flashcards") {
        main.append(this.renderFlashcard(state));
        if (state.canFinish) {
          main.append(this.renderAttemptNavigation(state));
        }
      } else {
        main.append(this.renderQuestion(state));
        main.append(this.renderAttemptNavigation(state));
      }
      this.replaceStage(main);
    }
    /**
     * One round of the speaking trainer (F12).
     *
     * The trainer is created once and re-used, so a redraw does not drop a
     * running recording and does not ask for the microphone again.
     *
     * [P11-E2]: the trainer's three actions (`speaking_turn`, `clip_transcribe`,
     * `clip_state`) all belong to the AI addon. An assignment created while the
     * addon was there keeps its mode after an uninstall, so the mode alone is no
     * permission to ask. Without the addon report the trainer is not built at
     * all — and therefore asks nothing.
     */
    renderSpeaking(state) {
      var _a, _b, _c;
      const question2 = ((_a = state.result) == null ? void 0 : _a.question) || state.question;
      const clips = this.config.clips;
      if (!question2 || clips === void 0 || ((_c = (_b = this.config.features) == null ? void 0 : _b.ai) == null ? void 0 : _c.installed) !== true) {
        return liveElement("p", "quizgeist-study-test-note", {
          text: this.text("selfstudy:question:unavailable", "Die Frage ist nicht verf\xFCgbar.")
        });
      }
      if (this.speaking === null) {
        this.speaking = new SpeakingTrainer(
          this.config.strings,
          {
            uploadUrl: clips.uploadUrl,
            sesskey: this.config.sesskey,
            cmid: this.config.cmid,
            purpose: "speaking",
            language: clips.language,
            maxBytes: clips.maxBytes,
            maxSeconds: clips.maxSeconds,
            transcriptionAvailable: clips.canTranscribe
          },
          {
            submitTurn: (payload) => this.api.post("speaking_turn", payload).then(async (result) => {
              await this.recordSpeakingAnswer(payload.clipId, payload.text);
              return result.turn;
            }),
            transcribe: (clipId) => this.api.post("clip_transcribe", { clipId }).then((result) => result.clip),
            pollState: (clipIds) => this.api.post("clip_state", { clipIds }).then((result) => result.clips)
          },
          this.tts
        );
      }
      this.speaking.setQuestion({
        task: question2.questionText,
        accepted: []
      }, false);
      const wrapper = liveElement("section", "quizgeist-study-question-card");
      wrapper.append(this.speaking.root);
      return wrapper;
    }
    /**
     * Persist one speaking round as the answer of the current question.
     *
     * Deliberately fault tolerant: a refused submission (question already
     * answered, attempt finished) must never swallow the round's feedback. The
     * learner has spoken and gets their answer either way; only the ledger entry
     * is skipped, and the next round retries it.
     */
    async recordSpeakingAnswer(clipId, text4) {
      const state = this.state;
      if (state === null || !state.canAnswer || !state.question) {
        return;
      }
      const answer = typeof clipId === "number" && clipId > 0 ? { kind: "clip", clipId } : { kind: "text", text: typeof text4 === "string" ? text4 : "" };
      try {
        const result = await this.api.submit(state, answer);
        if (result.state) {
          this.applyState(result.state);
        }
      } catch {
      }
    }
    questionHeader(question2) {
      const section = liveElement("section", "quizgeist-study-question");
      const heading = liveElement("h3", "quizgeist-study-question__title", {
        text: question2.questionText
      });
      heading.dataset.studyHeading = "";
      heading.tabIndex = -1;
      section.append(
        heading,
        createTtsControl(this.tts, questionSpeechText(question2), this.config)
      );
      if (!["pin", "reveal", "slide"].includes(question2.qtype)) {
        const media = createLiveMedia(question2, {
          className: "quizgeist-study-question__media",
          label: question2.questionText
        });
        if (media) {
          section.append(media);
        }
      }
      return section;
    }
    renderQuestion(state) {
      var _a, _b, _c;
      const wrapper = liveElement("section", "quizgeist-study-question-card");
      const question2 = ((_a = state.result) == null ? void 0 : _a.question) || state.question;
      if (!question2) {
        wrapper.append(liveElement("p", "", {
          text: this.text("selfstudy:question:unavailable", "Die Frage ist nicht verf\xFCgbar.")
        }));
        return wrapper;
      }
      wrapper.append(this.questionHeader(question2));
      if (state.mode === "solo") {
        const hasPoints = question2.pointMode !== "none";
        wrapper.append(liveElement("p", "quizgeist-study-test-note", {
          text: !hasPoints ? this.text(
            "selfstudy:solo:nopoints",
            "Diese Aufgabe wird ohne Punktewertung bearbeitet."
          ) : question2.timeLimit > 0 ? this.text(
            "selfstudy:solo:timerule",
            "Die Zeitwertung beginnt beim ersten \xD6ffnen. Auch nach {$seconds} Sekunden erh\xE4lt eine richtige Antwort mindestens 50 % der Basispunkte.",
            { seconds: question2.timeLimit }
          ) : this.text(
            "selfstudy:solo:notimed",
            "F\xFCr diese Solo-Aufgabe gibt es keinen zeitbedingten Punkteabzug."
          )
        }));
      }
      if ((((_c = (_b = question2.policyDescriptor) == null ? void 0 : _b.stages) == null ? void 0 : _c.length) || 0) > 1) {
        wrapper.append(liveElement("p", "quizgeist-study-test-note", {
          text: this.text(
            "selfstudy:multistage:warning",
            "Diese mehrstufige Frage wird im Selbstlernen als einzelne Aufgabe in ihrer ersten Eingabestufe bearbeitet."
          )
        }));
      }
      if (state.result) {
        wrapper.append(this.renderResult(state.result));
        return wrapper;
      }
      const response = renderLiveResponse(question2, {
        answer: this.answerDraft || state.ownAnswer,
        audience: "player",
        disabled: this.busy || !state.canAnswer,
        interactive: state.canAnswer,
        nowMs: state.serverTimeMs,
        onChange: (answer) => {
          this.answerDraft = answer;
        },
        onSubmit: (answer) => {
          if (answer) {
            void this.submitAnswer(answer, response.querySelector(
              "[data-live-submit]"
            ) || void 0);
          }
        },
        text: (key, fallback, values = {}) => this.text(key, fallback, values)
      });
      response.setAttribute("aria-label", question2.questionText);
      wrapper.append(response);
      if (state.mode === "test") {
        wrapper.append(liveElement("p", "quizgeist-study-test-note", {
          text: this.text(
            "selfstudy:test:feedbacklater",
            "Deine Antwort wird gespeichert. Die Auswertung erscheint erst nach der Abgabe."
          )
        }));
      }
      return wrapper;
    }
    renderResult(result) {
      const wrapper = liveElement("div", "quizgeist-study-review");
      wrapper.append(renderQuestionSolution(result.question, {
        answer: result.answer,
        correct: result.correct,
        text: (key, fallback, values = {}) => this.text(key, fallback, values)
      }));
      if (result.explanation.trim() !== "") {
        const explanation = liveElement("section", "quizgeist-study-explanation");
        explanation.append(
          liveElement("h4", "", {
            text: this.text("selfstudy:review:explanation", "Erkl\xE4rung")
          }),
          liveElement("p", "", { text: result.explanation })
        );
        wrapper.append(explanation);
      }
      return wrapper;
    }
    renderAttemptNavigation(state) {
      const nav = liveElement("nav", "quizgeist-study-navigation", {
        "aria-label": this.text("selfstudy:navigation:label", "Testnavigation")
      });
      if (state.mode === "test") {
        const positions = liveElement("div", "quizgeist-study-navigation__positions");
        for (let index = 0; index < state.total; index += 1) {
          const position = studyButton(String(index + 1), "quiet");
          position.classList.add("quizgeist-study-position");
          position.classList.toggle("is-current", index === state.currentIndex);
          position.classList.toggle("is-answered", state.answeredIndices.includes(index));
          position.setAttribute(
            "aria-current",
            index === state.currentIndex ? "step" : "false"
          );
          position.setAttribute("aria-label", this.text(
            state.answeredIndices.includes(index) ? "selfstudy:navigation:answered" : "selfstudy:navigation:unanswered",
            state.answeredIndices.includes(index) ? "Frage {$number}, beantwortet" : "Frage {$number}, noch offen",
            { number: index + 1 }
          ));
          position.disabled = this.busy || !state.canAnswer;
          position.addEventListener("click", () => void this.navigate(index, position));
          positions.append(position);
        }
        nav.append(positions);
      }
      const actions = liveElement("div", "quizgeist-study-navigation__actions");
      if (state.mode !== "flashcards") {
        const previous = studyButton(
          this.text("selfstudy:navigation:previous", "Zur\xFCck"),
          "secondary"
        );
        previous.disabled = state.currentIndex <= 0 || this.busy || !state.canAnswer;
        previous.addEventListener("click", () => void this.navigate(
          state.currentIndex - 1,
          previous
        ));
        actions.append(previous);
        if (state.currentIndex < state.total - 1) {
          const next = studyButton(
            this.text("selfstudy:navigation:next", "Weiter"),
            "secondary"
          );
          next.disabled = this.busy || !state.canAnswer || state.mode !== "test" && !state.answeredIndices.includes(state.currentIndex);
          next.addEventListener("click", () => void this.navigate(
            state.currentIndex + 1,
            next
          ));
          actions.append(next);
        }
      }
      if (state.canFinish) {
        const finish = studyButton(
          state.mode === "test" ? this.text("selfstudy:test:finish", "Test abgeben und auswerten") : this.text("selfstudy:attempt:finish", "Zuweisung abschlie\xDFen"),
          "primary"
        );
        finish.disabled = this.busy;
        finish.addEventListener("click", () => void this.finish(finish));
        actions.append(finish);
      }
      nav.append(actions);
      return nav;
    }
    renderFlashcard(state) {
      var _a, _b;
      const card = liveElement("section", "quizgeist-study-flashcard");
      const progress = state.flashcards;
      if (progress && progress.round > 0) {
        const repeat = liveElement("p", "quizgeist-study-repeat-banner", {
          role: "status"
        });
        repeat.append(
          icon("repeat"),
          document.createTextNode(this.text(
            "selfstudy:flashcards:repeatround",
            "Wiederholrunde: Jetzt kommen die Karten zur\xFCck, die noch unsicher waren."
          ))
        );
        card.append(repeat);
      }
      if (progress) {
        const summary = liveElement("div", "quizgeist-study-flashcard__progress");
        const ring = liveElement("div", "quizgeist-study-progress-ring", {
          "aria-label": this.text(
            "selfstudy:flashcards:knownprogress",
            "{$known} von {$total} gewusst",
            { known: progress.known, total: progress.total }
          ),
          role: "img"
        });
        const percentage = progress.total > 0 ? Math.round(progress.known * 100 / progress.total) : 0;
        ring.style.setProperty("--mq-study-progress", `${percentage}%`);
        ring.append(liveElement("strong", "", { text: `${percentage} %` }));
        summary.append(
          ring,
          liveElement("p", "", {
            text: this.text(
              "selfstudy:flashcards:stacks",
              "{$known} gewusst \xB7 {$repeat} zum Wiederholen",
              { known: progress.known, repeat: progress.repeat }
            )
          })
        );
        card.append(summary);
      }
      const question2 = ((_a = state.result) == null ? void 0 : _a.question) || state.question;
      if (!question2) {
        return card;
      }
      card.append(this.questionHeader(question2));
      if ((progress == null ? void 0 : progress.revealed) || state.result) {
        card.append(renderQuestionSolution(question2, {
          text: (key, fallback, values = {}) => this.text(key, fallback, values)
        }));
        if ((_b = state.result) == null ? void 0 : _b.explanation.trim()) {
          card.append(liveElement("p", "quizgeist-study-explanation", {
            text: state.result.explanation
          }));
        }
        const actions = liveElement("div", "quizgeist-study-flashcard__actions");
        const unknown = studyButton(
          this.text("selfstudy:flashcards:notknown", "Noch nicht gewusst"),
          "secondary"
        );
        const known = studyButton(
          this.text("selfstudy:flashcards:known", "Gewusst"),
          "primary"
        );
        unknown.disabled = this.busy || !state.canAnswer;
        known.disabled = this.busy || !state.canAnswer;
        unknown.addEventListener("click", () => void this.markFlashcard(false, unknown));
        known.addEventListener("click", () => void this.markFlashcard(true, known));
        actions.append(unknown, known);
        card.append(actions);
      } else {
        card.append(liveElement("p", "quizgeist-study-flashcard__prompt", {
          text: this.text(
            "selfstudy:flashcards:think",
            "\xDCberlege zuerst selbst. Drehe die Karte erst um, wenn deine Antwort steht."
          )
        }));
        const reveal = studyButton(
          this.text("selfstudy:flashcards:reveal", "Antwort anzeigen"),
          "primary"
        );
        reveal.disabled = this.busy || !state.canAnswer;
        reveal.addEventListener("click", () => void this.revealFlashcard(reveal));
        card.append(reveal);
      }
      return card;
    }
    async submitAnswer(answer, button2) {
      const state = this.state;
      if (!state || !state.question || this.busy || !state.canAnswer) {
        return;
      }
      this.setBusy(true, button2);
      try {
        const result = await this.api.submit(state, answerPayload(answer));
        this.applyState(result.state);
        this.announce(state.mode === "test" ? this.text("selfstudy:test:saved", "Antwort gespeichert.") : this.text("selfstudy:answer:submitted", "Antwort abgegeben."));
      } catch (error) {
        if (!this.recoverConflict(error)) {
          this.setBusy(false, button2);
          this.announce(this.api.errorMessage(error));
        }
      }
    }
    async navigate(index, button2) {
      const state = this.state;
      if (!state || this.busy || !state.canAnswer || index < 0 || index >= state.total) {
        return;
      }
      this.setBusy(true, button2);
      try {
        const result = await this.api.navigate(
          state.attemptId,
          index,
          state.stateVersion
        );
        this.applyState(result.state);
      } catch (error) {
        if (!this.recoverConflict(error)) {
          this.setBusy(false, button2);
          this.announce(this.api.errorMessage(error));
        }
      }
    }
    async finish(button2) {
      const state = this.state;
      if (!state || this.busy || !state.canFinish) {
        return;
      }
      const answered = new Set(state.answeredIndices).size;
      const remaining = Math.max(0, state.total - answered);
      if (remaining > 0 && !window.confirm(this.text(
        "selfstudy:attempt:finishconfirm",
        "{$count} Aufgaben sind noch offen. Trotzdem mit dem bisherigen Stand abschlie\xDFen?",
        { count: remaining }
      ))) {
        return;
      }
      this.setBusy(true, button2);
      try {
        const result = await this.api.finish(state);
        this.applyState(result.state);
        this.announce(this.text("selfstudy:attempt:completed", "Zuweisung abgeschlossen."));
      } catch (error) {
        if (!this.recoverConflict(error)) {
          this.setBusy(false, button2);
          this.announce(this.api.errorMessage(error));
        }
      }
    }
    async revealFlashcard(button2) {
      const state = this.state;
      if (!state || this.busy || !state.canAnswer) {
        return;
      }
      this.setBusy(true, button2);
      try {
        const result = await this.api.revealFlashcard(state);
        this.applyState(result.state);
        this.announce(this.text("selfstudy:flashcards:revealed", "Antwort aufgedeckt."));
      } catch (error) {
        if (!this.recoverConflict(error)) {
          this.setBusy(false, button2);
          this.announce(this.api.errorMessage(error));
        }
      }
    }
    async markFlashcard(known, button2) {
      const state = this.state;
      if (!state || this.busy || !state.canAnswer) {
        return;
      }
      this.setBusy(true, button2);
      try {
        const result = await this.api.markFlashcard(state, known);
        this.applyState(result.state);
        this.announce(known ? this.text("selfstudy:flashcards:markedknown", "Als gewusst markiert.") : this.text(
          "selfstudy:flashcards:markedrepeat",
          "F\xFCr die Wiederholrunde vorgemerkt."
        ));
      } catch (error) {
        if (!this.recoverConflict(error)) {
          this.setBusy(false, button2);
          this.announce(this.api.errorMessage(error));
        }
      }
    }
    renderCompleted(state) {
      const main = liveElement("main", "quizgeist-study-finished");
      const heading = liveElement("h2", "quizgeist-study-title", {
        text: this.text("selfstudy:completed:title", "Geschafft!")
      });
      heading.dataset.studyHeading = "";
      heading.tabIndex = -1;
      const summary = liveElement("section", "quizgeist-study-finished__summary");
      summary.append(
        heading,
        liveElement("p", "", {
          text: this.text(
            "selfstudy:completed:description",
            "Dein Versuch ist abgeschlossen. Hier kannst du deine Antworten in Ruhe pr\xFCfen."
          )
        })
      );
      if (state.maxScore > 0) {
        const percentage = Math.round(state.score * 1e3 / state.maxScore) / 10;
        summary.append(
          liveElement("strong", "quizgeist-study-finished__score", {
            text: this.text(
              "selfstudy:completed:score",
              "{$score} / {$max} Punkte \xB7 {$percent} %",
              { max: state.maxScore, percent: percentage, score: state.score }
            )
          })
        );
      } else if (state.flashcards) {
        summary.append(liveElement("strong", "quizgeist-study-finished__score", {
          text: this.text(
            "selfstudy:completed:cards",
            "{$known} von {$total} Karten gewusst",
            { known: state.flashcards.known, total: state.flashcards.total }
          )
        }));
      }
      main.append(summary);
      main.append(this.renderGradeSummary(state.gradeSummary));
      const reviewSummary2 = this.renderReviewSummary(state);
      if (reviewSummary2) {
        main.append(reviewSummary2);
      }
      if (state.results.length > 0) {
        const reviews = liveElement("section", "quizgeist-study-finished__reviews", {
          "aria-labelledby": "quizgeist-study-review-heading"
        });
        reviews.append(liveElement("h3", "", {
          id: "quizgeist-study-review-heading",
          text: this.text("selfstudy:completed:review", "Auswertung")
        }));
        state.results.forEach((result, index) => {
          reviews.append(this.renderCompletedResult(result, index));
        });
        main.append(reviews);
      }
      const actions = liveElement("div", "quizgeist-study-navigation__actions");
      const overview = studyButton(
        this.text("selfstudy:action:overview", "Zur \xDCbersicht"),
        state.assignment.canRetry ? "secondary" : "primary"
      );
      overview.addEventListener("click", () => void this.loadOverview());
      actions.append(overview);
      if (state.assignment.canRetry) {
        const retry = studyButton(
          this.text("selfstudy:action:retry", "Erneut versuchen"),
          "primary"
        );
        retry.addEventListener("click", () => {
          void this.startAssignment(state.assignment.id, retry, true);
        });
        actions.append(retry);
      }
      main.append(actions);
      this.replaceStage(main);
    }
    /**
     * F8: collect every worked solution of a finished run.
     *
     * The server already decided what may be here. With policy `never` the
     * payload carries no summary at all and this returns null.
     */
    renderReviewSummary(state) {
      const summary = state.reviewSummary;
      if (!summary || !summary.available) {
        return null;
      }
      const section = liveElement("section", "quizgeist-study-solutions");
      section.dataset.studyReviewSummary = "";
      section.append(liveElement("h3", "quizgeist-study-solutions__title", {
        text: this.text(
          "selfstudy:review:summary:title",
          "Alle L\xF6sungswege"
        )
      }));
      if (summary.deferred) {
        section.append(liveElement("p", "quizgeist-study-solutions__note", {
          text: this.text(
            "selfstudy:review:summary:deferred",
            "W\xE4hrend des Durchgangs waren die L\xF6sungswege zur\xFCckgehalten."
          )
        }));
      }
      if (summary.entries.length === 0) {
        section.append(liveElement("p", "quizgeist-study-solutions__empty", {
          text: this.text(
            "selfstudy:review:summary:empty",
            "Zu diesem Durchgang ist noch kein L\xF6sungsweg hinterlegt."
          )
        }));
        return section;
      }
      section.append(liveElement("p", "quizgeist-study-solutions__count", {
        text: this.text(
          "selfstudy:review:summary:count",
          "{$count} von {$total} Fragen haben einen L\xF6sungsweg.",
          { count: summary.withExplanation, total: summary.total }
        )
      }));
      const list = liveElement("ol", "quizgeist-study-solutions__list");
      summary.entries.forEach((entry) => {
        const item = liveElement("li", "quizgeist-study-solutions__item");
        item.append(
          liveElement("h4", "quizgeist-study-solutions__question", {
            text: entry.questionText
          }),
          liveElement("p", "quizgeist-study-solutions__text", {
            text: entry.explanation
          })
        );
        list.append(item);
      });
      section.append(list);
      return section;
    }
    renderCompletedResult(result, index) {
      const details = liveElement("details", "quizgeist-study-result-card");
      if (index === 0) {
        details.open = true;
      }
      const summary = liveElement("summary", "quizgeist-study-result-card__summary");
      const status = typeof result.correct !== "boolean" ? this.text("selfstudy:review:ungraded", "Selbstkontrolle") : result.correct ? this.text("selfstudy:review:correctshort", "Richtig") : this.text("selfstudy:review:incorrectshort", "Noch \xFCben");
      summary.append(
        liveElement("span", "quizgeist-study-result-card__number", {
          text: String(index + 1)
        }),
        liveElement("span", "quizgeist-study-result-card__question", {
          text: result.question.questionText
        }),
        liveElement("span", `quizgeist-study-result-card__status${result.correct === true ? " is-correct" : result.correct === false ? " is-incorrect" : ""}`, { text: status })
      );
      const content = liveElement("div", "quizgeist-study-result-card__content");
      content.append(this.renderResult(result));
      details.append(summary, content);
      return details;
    }
    setBusy(busy, button2) {
      this.busy = busy;
      if (button2) {
        button2.disabled = busy;
        button2.setAttribute("aria-busy", busy ? "true" : "false");
      }
    }
    recoverConflict(error) {
      const state = this.api.conflictState(error);
      if (!state) {
        return false;
      }
      this.applyState(state);
      this.announce(this.text(
        "selfstudy:error:conflict",
        "Der Lernstand wurde auf den neuesten Stand gebracht."
      ));
      return true;
    }
    updateLocation(state) {
      try {
        const url = new URL(window.location.href);
        if (state) {
          url.searchParams.set("view", "attempt");
          url.searchParams.set("attemptid", String(state.attemptId));
          url.searchParams.set("assignmentid", String(state.assignment.id));
        } else {
          url.searchParams.set("view", "overview");
          url.searchParams.delete("attemptid");
          url.searchParams.delete("assignmentid");
        }
        window.history.replaceState({}, "", url);
      } catch (_error) {
      }
    }
  };
  function mountStudentSelfStudyApp(root, config) {
    const app = new StudentSelfStudyApp(root, config);
    void app.init();
  }

  // src/app_play.ts
  function init(config = {}) {
    var _a, _b, _c, _d;
    const liveRequested = ((_b = (_a = config.features) == null ? void 0 : _a.selfstudy) == null ? void 0 : _b.installed) !== true || config.initialView === "live" || typeof config.initialJoinCode === "string" && /^[0-9]{6}$/.test(config.initialJoinCode) || new URL(window.location.href).searchParams.get("view") === "play";
    if (liveRequested) {
      mountPlayerApp(config);
      return;
    }
    const normalized = normalizeSelfStudyConfig(config, "quizgeist-app-play");
    const containerId = (normalized == null ? void 0 : normalized.containerId) || (typeof config.containerId === "string" ? config.containerId : "quizgeist-app-play");
    const root = document.getElementById(containerId);
    if (!root) {
      return;
    }
    if (!normalized) {
      const error = document.createElement("div");
      error.className = "quizgeist-study-state quizgeist-study-state--error";
      error.setAttribute("role", "alert");
      error.textContent = ((_c = config.strings) == null ? void 0 : _c["selfstudy:error:config"]) || ((_d = config.strings) == null ? void 0 : _d["live:error:config"]) || "Die Lern\xFCbersicht konnte nicht gestartet werden.";
      root.replaceChildren(error);
      return;
    }
    mountStudentSelfStudyApp(root, normalized);
  }
  return __toCommonJS(app_play_exports);
})();
