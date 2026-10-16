import {EditorApi} from './api';
import {button, closeDialog, element, openDialog} from './dom';
import {editorString, type ClientStrings} from './messages';

/**
 * U1 Tagging-Kern — the editor's label tool.
 *
 * The one load-bearing rule of this module: an assignment belongs to the
 * question ROOT, never to a version ID. Questions are versioned; every edit
 * inserts a new row and keeps the root. This module therefore only ever
 * sends the question ID it was given and lets the server resolve the root.
 *
 * Error codes never reach the DOM. Every server complaint is resolved
 * through a readable family here; the raw code stays in the browser console
 * (F16 systematics, same as messages.ts).
 */

export type TagKind = 'topic' | 'qtype' | 'competence';
export type TagScope = 'activity' | 'course' | 'site';

/** Display order of the three label families. */
const KIND_ORDER: TagKind[] = ['topic', 'qtype', 'competence'];

/** Scopes a teacher may create from the editor; site tags are admin work. */
const CREATABLE_SCOPES: TagScope[] = ['activity', 'course'];

/** Identity of the reserved error-friendly tag (tag_schema.php). */
const RESERVED_KIND = 'topic';
const RESERVED_KEY = 'neu';

const MAX_LABEL = 255;
const MAX_EXTERNALREF = 255;
const MAX_KEY_STEM = 60;

export interface EditorTag {
  colorKey: string | null;
  externalRef: string | null;
  id: number;
  kind: TagKind | string;
  label: string;
  reserved?: boolean;
  scope: TagScope | string;
  scopeId: number;
  sortOrder: number;
  tagKey: string;
}

export interface EditorTagAssignment {
  colorKey: string | null;
  kind: string;
  label: string;
  rootId: number;
  status: string;
  tagId: number;
  tagKey: string;
  weight: number;
}

export interface EditorTagValidation {
  code: string;
  field: string;
}

interface TagListResult {
  assignments: Array<{rootId: number; tags: EditorTagAssignment[]}>;
  kinds: string[];
  reserved: {kind: string; tagKey: string};
  scopes: string[];
  tags: EditorTag[];
}

interface TagDeleteResult extends TagListResult {
  validationErrors: EditorTagValidation[];
}

interface TagSaveResult {
  tag: {id?: number; label?: string};
  validationErrors: EditorTagValidation[];
}

interface QuestionTagsResult {
  rootId: number;
  tags: EditorTagAssignment[];
  validationErrors: EditorTagValidation[];
}

export interface TagActionResult {
  message: string;
  ok: boolean;
  tagId?: number;
}

/**
 * Readable families for the server's {field, code} complaints.
 *
 * A code with no family still produces a readable sentence — never the code.
 */
const ERROR_FAMILIES: Record<string, string> = {
  duplicate: 'tag:error:duplicate',
  forbidden: 'tag:error:forbidden',
  invalid: 'tag:error:invalid',
  out_of_range: 'tag:error:out_of_range',
  required: 'tag:error:required',
  reserved: 'tag:error:reserved',
  too_many: 'tag:error:too_many',
};

/**
 * Turn one machine complaint into a sentence a teacher can act on.
 */
export function tagErrorMessage(
  strings: ClientStrings,
  errors: EditorTagValidation[],
): string {
  if (!errors.length) {
    return '';
  }
  const first = errors[0];
  const familyKey = ERROR_FAMILIES[first.code] || 'tag:error:generic';
  return editorString(
    strings,
    familyKey,
    editorString(
      strings,
      'tag:error:generic',
      'Die Merkmale konnten nicht gespeichert werden.',
    ),
  );
}

/** Moodle's single `{$a}` placeholder; editorString() knows none. */
function withValue(text: string, value: string | number): string {
  return text.split('{$a}').join(String(value));
}

/**
 * Derive a stable machine key from a label.
 *
 * The server merges on (scope, scopeid, kind, tagkey): saving a key that
 * already exists RENAMES that tag. A new label therefore must never reuse a
 * taken key, and never the reserved key of "Neu".
 */
export function deriveTagKey(label: string, taken: Set<string>): string {
  const stem = label
    .toLowerCase()
    .replace(/ä/gu, 'ae')
    .replace(/ö/gu, 'oe')
    .replace(/ü/gu, 'ue')
    .replace(/ß/gu, 'ss')
    .normalize('NFKD')
    .replace(/[̀-ͯ]/gu, '')
    .replace(/[^a-z0-9]+/gu, '-')
    .replace(/^-+|-+$/gu, '')
    .slice(0, MAX_KEY_STEM)
    .replace(/-+$/u, '') || 'merkmal';
  let key = stem;
  let suffix = 2;
  while (taken.has(key)) {
    key = `${stem}-${suffix}`;
    suffix += 1;
  }
  return key;
}

/**
 * The editor's tagging state, loaded once per editor session.
 */
export class TagStore {
  private tags: EditorTag[] = [];
  private byRoot = new Map<number, EditorTagAssignment[]>();
  /** Ticked but not yet saved boxes per root; survive every redraw. */
  private drafts = new Map<number, Set<number>>();
  private loaded = false;
  /** A freshly created tag asks the next picker to take focus. */
  public focusTagId = 0;

  public constructor(
    private readonly api: EditorApi,
    private readonly strings: ClientStrings,
  ) {
  }

  public isLoaded(): boolean {
    return this.loaded;
  }

  public all(): EditorTag[] {
    return this.tags.slice();
  }

  public ofKind(kind: string): EditorTag[] {
    return this.tags.filter((tag) => tag.kind === kind);
  }

  public assignmentsFor(rootId: number): EditorTagAssignment[] {
    return (this.byRoot.get(rootId) || []).slice();
  }

  public isReserved(tag: EditorTag): boolean {
    return tag.reserved === true
      || (tag.kind === RESERVED_KIND && tag.tagKey === RESERVED_KEY);
  }

  /** Number of questions of THIS activity that carry the tag. */
  public usageCount(tagId: number): number {
    let count = 0;
    this.byRoot.forEach((assignments) => {
      if (assignments.some((entry) => entry.tagId === tagId)) {
        count += 1;
      }
    });
    return count;
  }

  /** The boxes a picker shows as ticked: the draft, else the saved state. */
  public selectionFor(rootId: number): Set<number> {
    const draft = this.drafts.get(rootId);
    if (draft !== undefined) {
      return new Set(draft);
    }
    return new Set(this.assignmentsFor(rootId).map((entry) => entry.tagId));
  }

  public setDraft(rootId: number, tagIds: Iterable<number>): void {
    const known = new Set(this.tags.map((tag) => tag.id));
    this.drafts.set(
      rootId,
      new Set(Array.from(tagIds).filter((tagId) => known.has(tagId))),
    );
  }

  public async load(signal?: AbortSignal): Promise<void> {
    this.apply(await this.api.post<TagListResult>('tag_list', {}, signal));
  }

  private apply(result: TagListResult): void {
    this.tags = Array.isArray(result.tags) ? result.tags : [];
    this.byRoot = new Map();
    (Array.isArray(result.assignments) ? result.assignments : [])
      .forEach((entry) => {
        this.byRoot.set(
          Number(entry.rootId),
          Array.isArray(entry.tags) ? entry.tags : [],
        );
      });
    // A deleted tag must not linger as an invisible draft tick.
    const known = new Set(this.tags.map((tag) => tag.id));
    this.drafts.forEach((draft, rootId) => {
      this.drafts.set(
        rootId,
        new Set(Array.from(draft).filter((tagId) => known.has(tagId))),
      );
    });
    this.loaded = true;
  }

  public genericError(): TagActionResult {
    return {
      message: editorString(
        this.strings,
        'tag:error:generic',
        'Die Merkmale konnten nicht gespeichert werden.',
      ),
      ok: false,
    };
  }

  private requiredError(): TagActionResult {
    return {
      message: editorString(this.strings, 'tag:error:required', 'Dieses Feld wird benötigt.'),
      ok: false,
    };
  }

  /**
   * Replace the complete assignment list of one question.
   *
   * The caller passes the exact question version it is editing; the server
   * resolves the root. The returned message is always readable text.
   */
  public async saveForQuestion(
    questionId: number,
    tagIds: number[],
  ): Promise<TagActionResult> {
    const result = await this.api.post<QuestionTagsResult>(
      'question_tags_save',
      {
        questionId,
        tags: tagIds.map((tagId) => ({tagId, weight: 100})),
      },
    );
    const errors = Array.isArray(result.validationErrors)
      ? result.validationErrors
      : [];
    if (errors.length) {
      return {message: tagErrorMessage(this.strings, errors), ok: false};
    }
    const rootId = Number(result.rootId);
    this.byRoot.set(rootId, Array.isArray(result.tags) ? result.tags : []);
    this.drafts.delete(rootId);
    return {
      message: editorString(
        this.strings,
        'tag:saved',
        'Die Merkmale sind gespeichert.',
      ),
      ok: true,
    };
  }

  /**
   * Create one tag. Scope and kind are fixed from then on; only the label
   * and the reference can change later (they are not part of the identity).
   */
  public async createTag(
    label: string,
    kind: TagKind,
    scope: TagScope,
    externalRef = '',
  ): Promise<TagActionResult> {
    const cleanLabel = label.trim().slice(0, MAX_LABEL);
    if (cleanLabel === '') {
      return this.requiredError();
    }
    const taken = new Set(
      this.tags
        .filter((tag) => tag.kind === kind && tag.scope === scope)
        .map((tag) => tag.tagKey),
    );
    if (kind === RESERVED_KIND) {
      taken.add(RESERVED_KEY);
    }
    const result = await this.api.post<TagSaveResult>('tag_save', {
      tag: {
        colorkey: null,
        externalref: kind === 'competence'
          ? externalRef.trim().slice(0, MAX_EXTERNALREF)
          : '',
        kind,
        label: cleanLabel,
        scope,
        sortorder: 0,
        tagkey: deriveTagKey(cleanLabel, taken),
      },
    });
    const errors = Array.isArray(result.validationErrors)
      ? result.validationErrors
      : [];
    if (errors.length) {
      return {message: tagErrorMessage(this.strings, errors), ok: false};
    }
    await this.load();
    return {
      message: withValue(
        editorString(this.strings, 'tag:created', 'Das Merkmal „{$a}“ ist angelegt.'),
        cleanLabel,
      ),
      ok: true,
      tagId: Number(result.tag?.id || 0),
    };
  }

  /** Rename one tag; the identity fields travel back unchanged. */
  public async renameTag(
    tag: EditorTag,
    label: string,
    externalRef: string,
  ): Promise<TagActionResult> {
    const cleanLabel = label.trim().slice(0, MAX_LABEL);
    if (cleanLabel === '') {
      return this.requiredError();
    }
    const result = await this.api.post<TagSaveResult>('tag_save', {
      tag: {
        colorkey: tag.colorKey,
        externalref: tag.kind === 'competence'
          ? externalRef.trim().slice(0, MAX_EXTERNALREF)
          : (tag.externalRef || ''),
        id: tag.id,
        kind: tag.kind,
        label: cleanLabel,
        scope: tag.scope,
        sortorder: tag.sortOrder,
        tagkey: tag.tagKey,
      },
    });
    const errors = Array.isArray(result.validationErrors)
      ? result.validationErrors
      : [];
    if (errors.length) {
      return {message: tagErrorMessage(this.strings, errors), ok: false};
    }
    await this.load();
    return {
      message: withValue(
        editorString(this.strings, 'tag:renamed', 'Das Merkmal heißt jetzt „{$a}“.'),
        cleanLabel,
      ),
      ok: true,
    };
  }

  /** Delete one tag; the answer is the complete new read model. */
  public async deleteTag(tag: EditorTag): Promise<TagActionResult> {
    const result = await this.api.post<TagDeleteResult>('tag_delete', {
      tagId: tag.id,
    });
    const errors = Array.isArray(result.validationErrors)
      ? result.validationErrors
      : [];
    if (errors.length) {
      return {message: tagErrorMessage(this.strings, errors), ok: false};
    }
    this.apply(result);
    return {
      message: editorString(this.strings, 'tag:deleted', 'Das Merkmal ist gelöscht.'),
      ok: true,
    };
  }
}

function kindLabel(strings: ClientStrings, kind: string): string {
  return editorString(strings, `tag:kind:${kind}`, String(kind));
}

function scopeLabel(strings: ClientStrings, scope: string): string {
  return editorString(strings, `tag:scope:${scope}`, String(scope));
}

/** Kinds in display order, followed by any kind a newer server knows. */
function orderedKinds(store: TagStore): string[] {
  const extra = store.all()
    .map((tag) => String(tag.kind))
    .filter((kind, index, list) => (
      !KIND_ORDER.includes(kind as TagKind) && list.indexOf(kind) === index
    ));
  return [...KIND_ORDER, ...extra];
}

/** Tags of one kind, the reserved "Neu" first. */
function sortedOfKind(store: TagStore, kind: string): EditorTag[] {
  return store.ofKind(kind)
    .sort((left, right) => Number(store.isReserved(right)) - Number(store.isReserved(left)));
}

function kindSelect(strings: ClientStrings, id: string): HTMLSelectElement {
  const select = element('select', 'quizgeist-select', {id});
  KIND_ORDER.forEach((kind) => {
    select.append(element('option', '', {text: kindLabel(strings, kind), value: kind}));
  });
  return select;
}

function labelled(id: string, text: string, control: HTMLElement, className: string): HTMLElement {
  const wrapper = element('div', className);
  wrapper.append(element('label', 'quizgeist-field__label', {for: id, text}), control);
  return wrapper;
}

/**
 * Render the label picker of one question.
 *
 * Shapes rather than colour alone carry the meaning (DESIGN 2.1.3): every
 * label is a labelled checkbox, grouped under the name of its kind.
 */
export function renderTagPicker(
  store: TagStore,
  strings: ClientStrings,
  questionId: number,
  rootId: number,
  onMessage: (message: string, ok: boolean) => void,
  onStoreChanged: () => void,
): HTMLElement {
  const block = element('fieldset', 'quizgeist-editor-tags');
  block.dataset.editorTags = '';
  const legend = element('legend', 'quizgeist-editor-tags__legend');
  legend.textContent = editorString(strings, 'tag', 'Merkmale');
  block.append(legend);

  const available = store.all();
  const inputs: HTMLInputElement[] = [];
  if (!available.length) {
    const empty = element('p', 'quizgeist-editor-tags__empty');
    empty.textContent = editorString(
      strings,
      'tag:empty',
      'Für diese Aktivität sind noch keine Merkmale angelegt.',
    );
    block.append(empty);
  } else {
    const selected = store.selectionFor(rootId);
    orderedKinds(store).forEach((kind) => {
      const tags = sortedOfKind(store, kind);
      if (!tags.length) {
        return;
      }
      const group = element('div', 'quizgeist-editor-tags__group');
      group.append(element('p', 'quizgeist-editor-tags__group-title', {
        text: kindLabel(strings, kind),
      }));
      const list = element('div', 'quizgeist-editor-tags__list');
      tags.forEach((tag) => {
        const id = `quizgeist-tag-${questionId}-${tag.id}`;
        const row = element('div', 'quizgeist-editor-tags__item');
        const input = element('input', 'quizgeist-editor-tags__checkbox');
        input.type = 'checkbox';
        input.id = id;
        input.value = String(tag.id);
        input.checked = selected.has(tag.id);
        input.dataset.tagId = String(tag.id);
        input.addEventListener('change', () => {
          store.setDraft(
            rootId,
            inputs.filter((entry) => entry.checked).map((entry) => Number(entry.value)),
          );
        });
        const label = element('label', 'quizgeist-editor-tags__label');
        label.htmlFor = id;
        label.textContent = tag.label;
        row.append(input, label);
        if (tag.scope !== 'activity') {
          row.append(element('span', 'quizgeist-editor-tags__kind', {
            text: scopeLabel(strings, String(tag.scope)),
          }));
        }
        if (store.isReserved(tag)) {
          row.append(element('p', 'quizgeist-editor-tags__hint', {
            text: editorString(
              strings,
              'tag:reserved:hint',
              'Fragen mit „Neu“ zeigen bei einer falschen Antwort einen ermutigenden Hinweis.',
            ),
          }));
        }
        list.append(row);
        inputs.push(input);
        if (store.focusTagId === tag.id) {
          store.focusTagId = 0;
          window.requestAnimationFrame(() => input.focus());
        }
      });
      group.append(list);
      block.append(group);
    });
  }

  // Quick creation: a new label lands in this activity and is ticked for the
  // question on screen. The assignment is still saved with the save button,
  // exactly like every other tick.
  const quickLabelId = `quizgeist-tag-new-${questionId}`;
  const quickKindId = `quizgeist-tag-new-kind-${questionId}`;
  const quickInput = element('input', 'quizgeist-input', {
    id: quickLabelId,
    maxlength: MAX_LABEL,
    type: 'text',
  });
  const quickKind = kindSelect(strings, quickKindId);
  const quickButton = button(
    editorString(strings, 'tag:action:add', 'Merkmal anlegen'),
    'quizgeist-button quizgeist-button--secondary',
  );
  quickButton.dataset.action = 'create-tag';
  const createQuick = (): void => {
    const label = quickInput.value.trim();
    if (label === '') {
      quickInput.focus();
      return;
    }
    quickButton.disabled = true;
    const ticked = inputs.filter((entry) => entry.checked).map((entry) => Number(entry.value));
    void store.createTag(label, quickKind.value as TagKind, 'activity')
      .then((result) => {
        onMessage(result.message, result.ok);
        if (result.ok && result.tagId) {
          store.setDraft(rootId, [...ticked, result.tagId]);
          store.focusTagId = result.tagId;
          onStoreChanged();
        }
      })
      .catch(() => onMessage(store.genericError().message, false))
      .finally(() => {
        quickButton.disabled = false;
      });
  };
  quickButton.addEventListener('click', createQuick);
  quickInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      createQuick();
    }
  });
  const quickFields = element('div', 'quizgeist-editor-tags__quick-fields');
  quickFields.append(
    labelled(
      quickLabelId,
      editorString(strings, 'tag:field:new', 'Neues Merkmal'),
      quickInput,
      'quizgeist-editor-tags__quick-field quizgeist-editor-tags__quick-field--wide',
    ),
    labelled(
      quickKindId,
      editorString(strings, 'tag:field:kind', 'Art'),
      quickKind,
      'quizgeist-editor-tags__quick-field',
    ),
    quickButton,
  );
  const quick = element('div', 'quizgeist-editor-tags__quick');
  quick.append(
    quickFields,
    element('p', 'quizgeist-field__hint', {
      text: editorString(
        strings,
        'tag:quick:hint',
        'Neue Merkmale gelten zunächst nur für diese Aktivität.',
      ),
    }),
  );
  block.append(quick);

  const actions = element('div', 'quizgeist-editor-tags__actions');
  if (available.length) {
    const save = button(
      editorString(strings, 'tag:action:save', 'Merkmale speichern'),
      'quizgeist-button quizgeist-button--primary',
    );
    save.dataset.action = 'save-tags';
    save.addEventListener('click', () => {
      const chosen = inputs
        .filter((input) => input.checked)
        .map((input) => Number(input.value));
      save.disabled = true;
      void store.saveForQuestion(questionId, chosen)
        .then((result) => {
          onMessage(result.message, result.ok);
        })
        .catch(() => {
          onMessage(store.genericError().message, false);
        })
        .finally(() => {
          save.disabled = false;
        });
    });
    actions.append(save);
  }
  const manage = button(
    editorString(strings, 'tag:manage:open', 'Merkmale verwalten'),
    'quizgeist-button quizgeist-button--quiet',
    () => openTagManager(store, strings, onMessage, onStoreChanged),
  );
  manage.dataset.action = 'manage-tags';
  actions.append(manage);
  block.append(actions);
  return block;
}

interface ManagerState {
  confirmId: number;
  editingId: number;
  status: string;
  statusError: boolean;
}

/**
 * Open the label manager: list, rename, delete and create.
 *
 * Every change goes through the store and redraws both this dialog and the
 * editor behind it. Deleting asks inside the dialog, never via a browser
 * confirm (one dialog idiom for the whole plugin).
 */
export function openTagManager(
  store: TagStore,
  strings: ClientStrings,
  onMessage: (message: string, ok: boolean) => void,
  onStoreChanged: () => void,
): void {
  const t = (key: string, fallback: string): string => editorString(strings, key, fallback);
  const {body, dialog, footer} = openDialog(
    t('tag:manage:title', 'Merkmale dieser Aktivität'),
    t('tag:manage:close', 'Schließen'),
    'quizgeist-tag-manager',
  );
  const state: ManagerState = {confirmId: 0, editingId: 0, status: '', statusError: false};
  const status = element('p', 'quizgeist-tag-manager__status', {
    'aria-live': 'polite',
    role: 'status',
  });
  footer.append(button(
    t('tag:manage:close', 'Schließen'),
    'quizgeist-button quizgeist-button--secondary',
    () => closeDialog(dialog),
  ));

  let render: () => void = () => undefined;

  const run = (
    work: () => Promise<TagActionResult>,
    trigger: HTMLButtonElement,
    after: () => void = () => undefined,
  ): void => {
    trigger.disabled = true;
    void work()
      .then((result) => {
        state.status = result.message;
        state.statusError = !result.ok;
        onMessage(result.message, result.ok);
        if (result.ok) {
          after();
          onStoreChanged();
        }
      })
      .catch(() => {
        const failure = store.genericError();
        state.status = failure.message;
        state.statusError = true;
        onMessage(failure.message, false);
      })
      .finally(() => {
        if (dialog.isConnected) {
          render();
        }
      });
  };

  const renderEditing = (tag: EditorTag, item: HTMLElement): void => {
    const labelId = `quizgeist-tag-rename-${tag.id}`;
    const refId = `quizgeist-tag-rename-ref-${tag.id}`;
    const labelInput = element('input', 'quizgeist-input', {
      id: labelId,
      maxlength: MAX_LABEL,
      type: 'text',
      value: tag.label,
    });
    const refInput = element('input', 'quizgeist-input', {
      id: refId,
      maxlength: MAX_EXTERNALREF,
      type: 'text',
      value: tag.externalRef || '',
    });
    const fields = element('div', 'quizgeist-tag-manager__fields');
    fields.append(labelled(
      labelId,
      t('tag:field:label', 'Bezeichnung'),
      labelInput,
      'quizgeist-tag-manager__field',
    ));
    if (tag.kind === 'competence') {
      fields.append(labelled(
        refId,
        t('tag:field:externalref', 'Fundstelle'),
        refInput,
        'quizgeist-tag-manager__field',
      ));
    }
    const save = button(
      t('tag:action:savename', 'Speichern'),
      'quizgeist-button quizgeist-button--primary',
    );
    save.addEventListener('click', () => run(
      () => store.renameTag(tag, labelInput.value, refInput.value),
      save,
      () => {
        state.editingId = 0;
      },
    ));
    labelInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        save.click();
      }
    });
    const cancel = button(
      t('tag:action:cancel', 'Abbrechen'),
      'quizgeist-button quizgeist-button--quiet',
      () => {
        state.editingId = 0;
        render();
      },
    );
    const buttons = element('div', 'quizgeist-tag-manager__buttons');
    buttons.append(save, cancel);
    item.append(fields, buttons);
    window.requestAnimationFrame(() => labelInput.focus());
  };

  const renderConfirm = (tag: EditorTag, item: HTMLElement): void => {
    const confirm = button(
      t('tag:action:confirmdelete', 'Endgültig löschen'),
      'quizgeist-button quizgeist-button--danger-quiet',
    );
    confirm.dataset.action = 'confirm-delete-tag';
    confirm.addEventListener('click', () => run(
      () => store.deleteTag(tag),
      confirm,
      () => {
        state.confirmId = 0;
      },
    ));
    const cancel = button(
      t('tag:action:cancel', 'Abbrechen'),
      'quizgeist-button quizgeist-button--quiet',
      () => {
        state.confirmId = 0;
        render();
      },
    );
    const buttons = element('div', 'quizgeist-tag-manager__buttons');
    buttons.append(confirm, cancel);
    item.append(
      element('p', 'quizgeist-tag-manager__confirm', {
        text: withValue(
          tag.scope === 'course'
            ? t('tag:delete:confirmcourse', 'Merkmal „{$a}“ für den ganzen Kurs löschen?')
            : t('tag:delete:confirm', 'Merkmal „{$a}“ löschen?'),
          tag.label,
        ),
      }),
      buttons,
    );
    window.requestAnimationFrame(() => cancel.focus());
  };

  const renderTag = (tag: EditorTag): HTMLElement => {
    const item = element('li', 'quizgeist-tag-manager__item');
    item.dataset.tagId = String(tag.id);
    const reserved = store.isReserved(tag);
    // The client does not know the site-admin right; a site tag simply
    // offers no change here (the server refuses it anyway).
    const editable = !reserved && tag.scope !== 'site';
    if (editable && state.editingId === tag.id) {
      renderEditing(tag, item);
      return item;
    }
    const head = element('div', 'quizgeist-tag-manager__head');
    const meta: string[] = [
      scopeLabel(strings, String(tag.scope)),
      store.usageCount(tag.id) === 1
        ? t('tag:usage:one', '1 Frage dieser Aktivität')
        : withValue(t('tag:usage', '{$a} Fragen dieser Aktivität'), store.usageCount(tag.id)),
    ];
    if (tag.kind === 'competence' && tag.externalRef) {
      meta.push(`${t('tag:field:externalref', 'Fundstelle')}: ${tag.externalRef}`);
    }
    head.append(
      element('span', 'quizgeist-tag-manager__label', {text: tag.label}),
      element('span', 'quizgeist-tag-manager__meta', {text: meta.join(' · ')}),
    );
    item.append(head);
    if (reserved) {
      item.append(element('p', 'quizgeist-field__hint', {
        text: t(
          'tag:reserved:hint',
          'Fragen mit „Neu“ zeigen bei einer falschen Antwort einen ermutigenden Hinweis.',
        ),
      }));
      return item;
    }
    if (!editable) {
      return item;
    }
    if (state.confirmId === tag.id) {
      renderConfirm(tag, item);
      return item;
    }
    const rename = button(
      t('tag:action:rename', 'Umbenennen'),
      'quizgeist-button quizgeist-button--quiet',
      () => {
        state.editingId = tag.id;
        state.confirmId = 0;
        render();
      },
    );
    rename.dataset.action = 'rename-tag';
    rename.setAttribute('aria-label', `${t('tag:action:rename', 'Umbenennen')}: ${tag.label}`);
    const remove = button(
      t('tag:action:delete', 'Löschen'),
      'quizgeist-button quizgeist-button--danger-quiet',
      () => {
        state.confirmId = tag.id;
        state.editingId = 0;
        render();
      },
    );
    remove.dataset.action = 'delete-tag';
    remove.setAttribute('aria-label', `${t('tag:action:delete', 'Löschen')}: ${tag.label}`);
    const buttons = element('div', 'quizgeist-tag-manager__buttons');
    buttons.append(rename, remove);
    item.append(buttons);
    return item;
  };

  const uid = Math.random().toString(36).slice(2, 8);
  const renderCreateForm = (): HTMLElement => {
    const form = element('form', 'quizgeist-tag-manager__create');
    form.noValidate = true;
    const labelId = `quizgeist-tag-create-${uid}`;
    const kindId = `quizgeist-tag-create-kind-${uid}`;
    const scopeId = `quizgeist-tag-create-scope-${uid}`;
    const refId = `quizgeist-tag-create-ref-${uid}`;
    const labelInput = element('input', 'quizgeist-input', {
      id: labelId,
      maxlength: MAX_LABEL,
      type: 'text',
    });
    const kind = kindSelect(strings, kindId);
    const scope = element('select', 'quizgeist-select', {id: scopeId});
    CREATABLE_SCOPES.forEach((value) => {
      scope.append(element('option', '', {text: scopeLabel(strings, value), value}));
    });
    const refInput = element('input', 'quizgeist-input', {
      id: refId,
      maxlength: MAX_EXTERNALREF,
      type: 'text',
    });
    const refField = labelled(
      refId,
      t('tag:field:externalref', 'Fundstelle'),
      refInput,
      'quizgeist-tag-manager__field',
    );
    refField.hidden = true;
    kind.addEventListener('change', () => {
      refField.hidden = kind.value !== 'competence';
    });
    const submit = element('button', 'quizgeist-button quizgeist-button--primary', {
      text: t('tag:action:add', 'Merkmal anlegen'),
      type: 'submit',
    });
    submit.dataset.action = 'create-tag';
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (labelInput.value.trim() === '') {
        labelInput.focus();
        return;
      }
      run(
        () => store.createTag(
          labelInput.value,
          kind.value as TagKind,
          scope.value as TagScope,
          refInput.value,
        ),
        submit,
      );
    });
    const grid = element('div', 'quizgeist-tag-manager__fields');
    grid.append(
      labelled(labelId, t('tag:field:label', 'Bezeichnung'), labelInput, 'quizgeist-tag-manager__field'),
      labelled(kindId, t('tag:field:kind', 'Art'), kind, 'quizgeist-tag-manager__field'),
      labelled(scopeId, t('tag:field:scope', 'Gilt für'), scope, 'quizgeist-tag-manager__field'),
      refField,
    );
    form.append(
      element('h3', 'quizgeist-tag-manager__kind', {text: t('tag:field:new', 'Neues Merkmal')}),
      grid,
      submit,
    );
    return form;
  };

  render = (): void => {
    const nodes: HTMLElement[] = [
      element('p', 'quizgeist-tag-manager__intro', {
        text: t(
          'tag:manage:intro',
          'Merkmale ordnen Fragen nach Thema, Aufgabenart oder Kompetenz.',
        ),
      }),
    ];
    orderedKinds(store).forEach((kind) => {
      const tags = sortedOfKind(store, kind);
      const section = element('section', 'quizgeist-tag-manager__section');
      section.append(element('h3', 'quizgeist-tag-manager__kind', {
        text: kindLabel(strings, kind),
      }));
      if (!tags.length) {
        section.append(element('p', 'quizgeist-field__hint', {
          text: t('tag:kind:empty', 'Noch keine Merkmale dieser Art.'),
        }));
      } else {
        const list = element('ul', 'quizgeist-tag-manager__list');
        tags.forEach((tag) => list.append(renderTag(tag)));
        section.append(list);
      }
      nodes.push(section);
    });
    nodes.push(renderCreateForm());
    status.textContent = state.status;
    status.dataset.error = state.statusError ? 'true' : 'false';
    nodes.push(status);
    body.replaceChildren(...nodes);
  };

  render();
}
