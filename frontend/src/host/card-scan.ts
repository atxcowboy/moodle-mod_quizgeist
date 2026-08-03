import {LiveApi} from '../live/api';
import {liveButton, liveElement} from '../live/dom';

/**
 * F11a Karten-Modus — the teacher's side of a whole class answering at once.
 *
 * Three rules shape this module, and each of them is the reason for code that
 * would otherwise look excessive:
 *
 * 1. **The picture never leaves this device except once, upwards.** The
 *    preview is a local `ObjectURL`; the server never sends a class photograph
 *    back, and there is no URL that would let it. The picture is downscaled
 *    here to a 1600-pixel long edge before the single POST, so a modern phone
 *    camera does not spend twelve megabytes of a school's uplink on a picture
 *    the model reads better small.
 * 2. **Nothing is booked before the teacher says so.** Recognition produces a
 *    LIST, and the list is a proposal. Cards that were not recognised are
 *    sorted to the TOP, because the failure mode that matters is a learner who
 *    quietly does not appear.
 * 3. **Polling, never streaming.** The upload returns a scan ID; the result
 *    arrives through short repeated reads (HOUSE_RULES).
 */

/** Longest edge of the uploaded picture, in pixels. */
const MAX_EDGE = 1600;

/** JPEG quality of the downscaled upload. */
const UPLOAD_QUALITY = 0.85;

/** Poll interval while a recognition is running, in milliseconds. */
const POLL_INTERVAL_MS = 1500;

/** Polls before the panel stops waiting on its own. */
const MAX_POLLS = 60;

export interface CardSetOption {
  id: number;
  name: string;
  layout: string;
  cardCount: number;
}

export interface CardScanEntry {
  cardCode: string;
  userId: number;
  displayName: string;
  answerKey: string;
  confidence: number;
  bookable: boolean;
  source: string;
}

export interface CardScanMissing {
  cardCode: string;
  userId: number;
  displayName: string;
  bookable: boolean;
}

export interface CardScan {
  id: number;
  state: string;
  reasonFamily: string;
  sessionId: number;
  questionId: number;
  cardSetId: number;
  recognised: number;
  expected: number;
  imageDeleted: boolean;
  entries: CardScanEntry[];
  missing: CardScanMissing[];
  rejectedCount: number;
  timeModified: number;
}

export interface CardScanTarget {
  sessionId: number;
  questionId: number;
  visit: string;
  letters: string[];
}

export interface CardScanContext {
  api: LiveApi;
  cardSets: CardSetOption[];
  cmid: number;
  printUrl: string;
  sesskey: string;
  text: (key: string, fallback: string, values?: Record<string, string | number>) => string;
  uploadUrl: string;
}

type Phase = 'idle' | 'camera' | 'busy' | 'confirm';

/**
 * Read the sentence that belongs to a scan state.
 *
 * F16: the server sends a STATE and a reason FAMILY, never a machine code, and
 * this function resolves both into language. There is no `strings[key] || key`
 * anywhere in this module — an unknown family still yields a sentence.
 */
export function scanStateSentence(
  scan: CardScan | null,
  text: CardScanContext['text'],
): string {
  if (scan === null) {
    return '';
  }
  const state = ['pending', 'recognised', 'confirmed', 'failed', 'discarded']
    .includes(scan.state)
    ? scan.state
    : 'failed';
  const sentence = text(
    `cards:state:${state}`,
    'Die Aufnahme wird verarbeitet.',
  );
  const family = ['none', 'unavailable', 'unreadable', 'gone', 'unsupported', 'failed']
    .includes(scan.reasonFamily)
    ? scan.reasonFamily
    : 'failed';
  if (family === 'none') {
    return sentence;
  }
  return `${sentence} ${text(`cards:reason:${family}`, '')}`.trim();
}

/**
 * Downscale one still frame to the upload edge.
 *
 * Returns a JPEG blob, because a photograph of a classroom compresses far
 * better as JPEG than as PNG and the recognition does not need lossless.
 */
export async function downscaleFrame(
  source: CanvasImageSource,
  width: number,
  height: number,
  maxEdge: number = MAX_EDGE,
): Promise<Blob> {
  const longest = Math.max(width, height);
  const scale = longest > maxEdge ? maxEdge / longest : 1;
  const targetWidth = Math.max(1, Math.round(width * scale));
  const targetHeight = Math.max(1, Math.round(height * scale));
  const canvas = document.createElement('canvas');
  canvas.width = targetWidth;
  canvas.height = targetHeight;
  const canvasContext = canvas.getContext('2d');
  if (canvasContext === null) {
    throw new Error('canvas_unavailable');
  }
  canvasContext.drawImage(source, 0, 0, targetWidth, targetHeight);
  return await new Promise<Blob>((resolve, reject) => {
    canvas.toBlob(
      (blob) => {
        if (blob === null) {
          reject(new Error('encode_failed'));
          return;
        }
        resolve(blob);
      },
      'image/jpeg',
      UPLOAD_QUALITY,
    );
  });
}

/**
 * The panel a host sees below the live question.
 */
export class CardScanPanel {
  private readonly root: HTMLElement;

  private phase: Phase = 'idle';

  private cardSetId = 0;

  private scan: CardScan | null = null;

  private stream: MediaStream | null = null;

  private video: HTMLVideoElement | null = null;

  private previewUrl = '';

  private busyMessage = '';

  private errorMessage = '';

  private booked: number | null = null;

  private skipped: Array<{cardCode: string; code: string}> = [];

  private corrections = new Map<string, string>();

  private pollTimer: number | null = null;

  private polls = 0;

  public constructor(
    private readonly context: CardScanContext,
    private target: CardScanTarget,
  ) {
    this.root = liveElement('section', 'quizgeist-cardscan', {
      'data-quizgeist-view': 'host-cardscan',
    });
    this.cardSetId = context.cardSets.length > 0 ? context.cardSets[0].id : 0;
    this.render();
  }

  /** The element the host app mounts. */
  public element(): HTMLElement {
    return this.root;
  }

  /** Point the panel at another question, discarding any open confirmation. */
  public retarget(target: CardScanTarget): void {
    if (target.sessionId === this.target.sessionId
        && target.questionId === this.target.questionId
        && target.visit === this.target.visit) {
      return;
    }
    this.target = target;
    this.reset();
  }

  /** Release the camera; the host app calls this when the screen goes away. */
  public destroy(): void {
    this.stopPolling();
    this.stopCamera();
    this.releasePreview();
  }

  private reset(): void {
    this.stopPolling();
    this.stopCamera();
    this.releasePreview();
    this.phase = 'idle';
    this.scan = null;
    this.booked = null;
    this.skipped = [];
    this.errorMessage = '';
    this.busyMessage = '';
    this.corrections.clear();
    this.render();
  }

  private text(key: string, fallback: string, values: Record<string, string | number> = {}): string {
    return this.context.text(key, fallback, values);
  }

  private render(): void {
    const nodes: HTMLElement[] = [this.renderHeader()];
    if (this.phase === 'camera') {
      nodes.push(this.renderCamera());
    }
    if (this.phase === 'confirm' && this.scan !== null) {
      nodes.push(this.renderConfirm(this.scan));
    }
    nodes.push(this.renderStatus());
    this.root.replaceChildren(...nodes);
  }

  private renderHeader(): HTMLElement {
    const header = liveElement('div', 'quizgeist-cardscan__intro');
    header.append(liveElement('h3', 'quizgeist-cardscan__title', {
      text: this.text('cards:scan:title', 'Karten scannen'),
    }));

    if (this.context.cardSets.length === 0) {
      header.append(liveElement('p', 'quizgeist-cardscan__empty', {
        text: this.text('cards:scan:noset', 'Es ist noch kein Kartensatz angelegt.'),
      }));
      return header;
    }

    header.append(liveElement('p', 'quizgeist-cardscan__hint', {
      text: this.text('cards:scan:description', ''),
    }));

    const selectId = `quizgeist-cardscan-set-${this.context.cmid}`;
    const label = liveElement('label', 'quizgeist-cardscan__label', {
      for: selectId,
      // A visible label, never a placeholder: DESIGN 7.
      text: this.text('cards:scan:setlabel', 'Kartensatz'),
    });
    const select = liveElement('select', 'quizgeist-cardscan__set quizgeist-touch-target', {
      id: selectId,
    });
    for (const set of this.context.cardSets) {
      select.append(liveElement('option', 'quizgeist-cardscan__option', {
        selected: set.id === this.cardSetId,
        text: `${set.name} (${set.cardCount})`,
        value: String(set.id),
      }));
    }
    select.disabled = this.phase !== 'idle';
    select.addEventListener('change', () => {
      this.cardSetId = Number(select.value) || 0;
      this.render();
    });

    // The selected set is ALSO shown as text. A <select> truncates a long
    // class name with an ellipsis and gives the layout harness nothing it can
    // measure; this line carries the teacher's own raw text, wraps, and is the
    // surface the host-cardscan layout counter-test is guarded on.
    const chosen = this.context.cardSets.find((set) => set.id === this.cardSetId)
      ?? this.context.cardSets[0];
    const setname = liveElement('p', 'quizgeist-cardscan__setname', {
      text: `${chosen.name} · ${chosen.cardCount}`,
    });

    const actions = liveElement('div', 'quizgeist-cardscan__controls');
    actions.append(label, select);
    if (this.phase === 'idle') {
      const start = liveButton(
        this.text('cards:scan:start', 'Kamera öffnen'),
        'quizgeist-cardscan__start quizgeist-touch-target',
      );
      start.addEventListener('click', () => {
        void this.startCamera();
      });
      actions.append(start);
    }
    const print = liveElement('a', 'quizgeist-cardscan__print quizgeist-touch-target', {
      href: `${this.context.printUrl}&cardset=${this.cardSetId}`,
      rel: 'noopener',
      target: '_blank',
      text: this.text('cards:scan:print', 'Kartenbogen drucken'),
    });
    actions.append(print);
    header.append(actions, setname);
    return header;
  }

  private renderCamera(): HTMLElement {
    const frame = liveElement('div', 'quizgeist-cardscan__camera');
    const video = liveElement('video', 'quizgeist-cardscan__video', {
      'aria-label': this.text('cards:scan:preview', 'Kameravorschau'),
      autoplay: true,
      muted: true,
      playsinline: true,
    });
    video.muted = true;
    this.video = video;
    if (this.stream !== null) {
      video.srcObject = this.stream;
      void video.play().catch(() => {
        // A blocked autoplay is not a failure of the scan: the frame is still
        // grabbed from the live track, not from the visible element.
      });
    }
    const controls = liveElement('div', 'quizgeist-cardscan__controls');
    const capture = liveButton(
      this.text('cards:scan:capture', 'Foto aufnehmen'),
      'quizgeist-cardscan__capture quizgeist-touch-target',
    );
    capture.addEventListener('click', () => {
      void this.capture();
    });
    const close = liveButton(
      this.text('cards:scan:close', 'Kamera schließen'),
      'quizgeist-cardscan__close quizgeist-touch-target',
    );
    close.addEventListener('click', () => {
      this.reset();
    });
    controls.append(capture, close);
    frame.append(video, controls);
    return frame;
  }

  private renderStatus(): HTMLElement {
    const status = liveElement('p', 'quizgeist-cardscan__status', {
      'aria-atomic': 'true',
      // The recognition state is announced, not only shown (8.3).
      'aria-live': 'polite',
      role: 'status',
    });
    if (this.errorMessage !== '') {
      status.textContent = this.errorMessage;
      status.classList.add('is-error');
      return status;
    }
    if (this.phase === 'busy') {
      status.textContent = this.busyMessage;
      return status;
    }
    if (this.booked !== null) {
      const parts = [this.text('cards:scan:applied', '', {a: this.booked})];
      if (this.skipped.length > 0) {
        parts.push(this.text('cards:scan:skippedheading', ''));
        for (const entry of this.skipped) {
          parts.push(this.skipSentence(entry.code));
        }
      }
      status.textContent = parts.filter((part) => part !== '').join(' ');
      return status;
    }
    status.textContent = scanStateSentence(this.scan, this.context.text);
    return status;
  }

  private skipSentence(code: string): string {
    const known = [
      'card_without_player',
      'answer_unknown',
      'question_moved_on',
      'answer_too_late',
      'question_not_open',
      'booking_refused',
    ];
    const family = known.includes(code) ? code : 'booking_refused';
    return this.text(`cards:skip:${family}`, '');
  }

  private renderConfirm(scan: CardScan): HTMLElement {
    const shell = liveElement('div', 'quizgeist-cardconfirm', {
      'data-quizgeist-view': 'host-cardconfirm',
    });
    shell.append(liveElement('p', 'quizgeist-cardconfirm__progress', {
      'aria-live': 'polite',
      text: this.text('cards:scan:progress', '', {
        expected: scan.expected,
        recognised: scan.recognised,
      }),
    }));

    // Not recognised FIRST. A learner who did not appear is the one thing a
    // teacher must not have to scroll for.
    if (scan.missing.length > 0) {
      shell.append(this.renderGroup(
        this.text('cards:scan:missingheading', 'Nicht erkannt'),
        scan.missing.map((entry) => ({
          answerKey: '',
          bookable: entry.bookable,
          cardCode: entry.cardCode,
          confidence: -1,
          displayName: entry.displayName,
          source: 'missing',
          userId: entry.userId,
        })),
        'is-missing',
      ));
    }
    if (scan.entries.length > 0) {
      shell.append(this.renderGroup(
        this.text('cards:scan:recognisedheading', 'Erkannt'),
        scan.entries,
        'is-recognised',
      ));
    }

    shell.append(liveElement('p', 'quizgeist-cardconfirm__notice', {
      text: this.text('cards:scan:imagenotice', ''),
    }));

    const actions = liveElement('div', 'quizgeist-cardconfirm__actions');
    const apply = liveButton(
      this.text('cards:scan:apply', 'Übernehmen'),
      'quizgeist-cardconfirm__apply quizgeist-touch-target',
    );
    apply.addEventListener('click', () => {
      void this.confirm(false);
    });
    const discardButton = liveButton(
      this.text('cards:scan:discard', 'Aufnahme verwerfen'),
      'quizgeist-cardconfirm__discard quizgeist-touch-target',
    );
    discardButton.addEventListener('click', () => {
      void this.confirm(true);
    });
    actions.append(apply, discardButton);
    shell.append(actions);
    return shell;
  }

  private renderGroup(
    heading: string,
    entries: CardScanEntry[],
    modifier: string,
  ): HTMLElement {
    const group = liveElement('div', `quizgeist-cardconfirm__group ${modifier}`);
    group.append(liveElement('h4', 'quizgeist-cardconfirm__heading', {text: heading}));
    for (const entry of entries) {
      group.append(this.renderRow(entry));
    }
    return group;
  }

  private renderRow(entry: CardScanEntry): HTMLElement {
    const row = liveElement('div', 'quizgeist-cardconfirm__row', {
      'data-card-code': entry.cardCode,
    });
    const name = liveElement('span', 'quizgeist-cardconfirm__name', {
      text: entry.displayName,
    });
    const selectId = `quizgeist-cardconfirm-${this.context.cmid}-${entry.cardCode}`;
    const label = liveElement('label', 'quizgeist-cardconfirm__label', {
      for: selectId,
      text: this.text('cards:scan:answerlabel', '', {a: entry.displayName}),
    });
    const select = liveElement('select', 'quizgeist-cardconfirm__select quizgeist-touch-target', {
      id: selectId,
    });
    const current = this.corrections.get(entry.cardCode) ?? entry.answerKey;
    select.append(liveElement('option', '', {
      selected: current === '',
      text: this.text('cards:scan:nochoice', 'keine Antwort'),
      value: '',
    }));
    for (const letter of this.target.letters) {
      select.append(liveElement('option', '', {
        selected: current === letter,
        text: letter,
        value: letter,
      }));
    }
    select.addEventListener('change', () => {
      this.corrections.set(entry.cardCode, select.value);
    });

    row.append(label, name, select);
    if (entry.confidence >= 0 && entry.source === 'model') {
      row.append(liveElement('span', 'quizgeist-cardconfirm__confidence', {
        text: this.text('cards:scan:confidence', '', {
          a: Math.round(entry.confidence * 100),
        }),
      }));
    } else {
      row.append(liveElement('span', 'quizgeist-cardconfirm__confidence', {
        text: this.text('cards:scan:notrecognised', 'nicht erkannt'),
      }));
    }
    if (!entry.bookable) {
      row.classList.add('is-unbookable');
      row.append(liveElement('span', 'quizgeist-cardconfirm__unbookable', {
        text: this.text('cards:scan:unbookable', ''),
      }));
    }
    return row;
  }

  private async startCamera(): Promise<void> {
    this.errorMessage = '';
    const media = navigator.mediaDevices;
    if (media === undefined || typeof media.getUserMedia !== 'function') {
      this.errorMessage = this.text('cards:scan:cameraunavailable', '');
      this.render();
      return;
    }
    try {
      this.stream = await media.getUserMedia({
        audio: false,
        video: {facingMode: 'environment'},
      });
    } catch (_error) {
      this.errorMessage = this.text('cards:scan:camerablocked', '');
      this.render();
      return;
    }
    this.phase = 'camera';
    this.render();
  }

  private stopCamera(): void {
    if (this.stream !== null) {
      for (const track of this.stream.getTracks()) {
        track.stop();
      }
      this.stream = null;
    }
    if (this.video !== null) {
      this.video.srcObject = null;
      this.video = null;
    }
  }

  private releasePreview(): void {
    if (this.previewUrl !== '') {
      URL.revokeObjectURL(this.previewUrl);
      this.previewUrl = '';
    }
  }

  private async capture(): Promise<void> {
    const video = this.video;
    if (video === null || this.cardSetId <= 0) {
      return;
    }
    const width = video.videoWidth || 0;
    const height = video.videoHeight || 0;
    if (width <= 0 || height <= 0) {
      this.errorMessage = this.text('cards:scan:cameraunavailable', '');
      this.render();
      return;
    }

    this.phase = 'busy';
    this.busyMessage = this.text('cards:scan:uploading', '');
    this.render();

    let blob: Blob;
    try {
      blob = await downscaleFrame(video, width, height);
    } catch (_error) {
      this.fail(this.text('cards:error:scanuploadfailed', ''));
      return;
    }
    // The preview is the LOCAL picture. The server never serves it back and
    // there is no plugin URL that could.
    this.releasePreview();
    this.previewUrl = URL.createObjectURL(blob);
    this.stopCamera();

    const form = new FormData();
    form.append('id', String(this.context.cmid));
    form.append('sesskey', this.context.sesskey);
    form.append('kind', 'cardscan');
    form.append('sessionId', String(this.target.sessionId));
    form.append('questionId', String(this.target.questionId));
    form.append('visit', this.target.visit);
    form.append('cardSetId', String(this.cardSetId));
    form.append('scan', blob, 'cardscan.jpg');

    let payload: {error?: {code: string; message: string}; scan?: CardScan} | null = null;
    let ok = false;
    try {
      const response = await fetch(this.context.uploadUrl, {
        body: form,
        credentials: 'same-origin',
        headers: {Accept: 'application/json'},
        method: 'POST',
      });
      ok = response.ok;
      payload = await response.json().catch(() => null);
    } catch (_error) {
      this.fail(this.text('cards:error:scanuploadfailed', ''));
      return;
    }
    if (!ok || payload === null || payload.scan === undefined) {
      // The server always ships a ready-made sentence next to its code, so the
      // browser never has to render the code itself (F16).
      this.fail(payload?.error?.message ?? this.text('cards:error:scanuploadfailed', ''));
      return;
    }

    this.scan = payload.scan;
    this.busyMessage = this.text('cards:scan:recognising', '');
    this.render();
    await this.recognise();
  }

  private async recognise(): Promise<void> {
    const scan = this.scan;
    if (scan === null) {
      return;
    }
    try {
      const result = await this.context.api.post<{scan: CardScan}>(
        'card_scan_recognise',
        {scanId: scan.id},
      );
      this.applyScan(result.scan);
    } catch (error) {
      // A recognition that did not answer in time is not lost: the scan stays
      // on the server and the ordinary poll picks up its result.
      this.startPolling();
      if (error instanceof Error && error.message !== '') {
        this.busyMessage = this.text('cards:scan:recognising', '');
      }
    }
  }

  private applyScan(scan: CardScan): void {
    this.scan = scan;
    if (scan.state === 'pending') {
      this.startPolling();
      this.phase = 'busy';
      this.busyMessage = this.text('cards:scan:recognising', '');
      this.render();
      return;
    }
    this.stopPolling();
    this.phase = scan.state === 'recognised' ? 'confirm' : 'idle';
    this.render();
  }

  private startPolling(): void {
    if (this.pollTimer !== null) {
      return;
    }
    this.polls = 0;
    this.pollTimer = window.setInterval(() => {
      void this.poll();
    }, POLL_INTERVAL_MS);
  }

  private stopPolling(): void {
    if (this.pollTimer !== null) {
      window.clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private async poll(): Promise<void> {
    const scan = this.scan;
    if (scan === null) {
      this.stopPolling();
      return;
    }
    this.polls += 1;
    if (this.polls > MAX_POLLS) {
      this.stopPolling();
      this.fail(this.text('cards:reason:failed', ''));
      return;
    }
    try {
      const result = await this.context.api.post<{scan: CardScan}>(
        'card_scan_state',
        {scanId: scan.id},
      );
      if (result.scan.state !== 'pending') {
        this.applyScan(result.scan);
      }
    } catch (_error) {
      // A single failed poll is a network hiccup, not a lost scan.
    }
  }

  private async confirm(discard: boolean): Promise<void> {
    const scan = this.scan;
    if (scan === null) {
      return;
    }
    this.phase = 'busy';
    this.busyMessage = this.text('cards:scan:uploading', '');
    this.render();

    const corrections: Array<{answerKey: string | null; cardCode: string}> = [];
    for (const [cardCode, answerKey] of this.corrections.entries()) {
      corrections.push({answerKey: answerKey === '' ? null : answerKey, cardCode});
    }

    try {
      const result = await this.context.api.post<{
        booked: number;
        scan: CardScan;
        skipped: Array<{cardCode: string; code: string}>;
      }>('card_scan_confirm', {corrections, discard, scanId: scan.id});
      this.scan = result.scan;
      this.booked = discard ? null : result.booked;
      this.skipped = Array.isArray(result.skipped) ? result.skipped : [];
      this.corrections.clear();
      this.releasePreview();
      this.phase = 'idle';
      this.render();
    } catch (error) {
      this.fail(error instanceof Error && error.message !== ''
        ? error.message
        : this.text('cards:error:scanuploadfailed', ''));
    }
  }

  private fail(message: string): void {
    this.stopPolling();
    this.stopCamera();
    this.phase = 'idle';
    this.errorMessage = message;
    this.render();
  }
}
