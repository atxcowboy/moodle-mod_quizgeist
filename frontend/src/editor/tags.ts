import {EditorApi} from './api';
import {button, element} from './dom';
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

interface QuestionTagsResult {
  rootId: number;
  tags: EditorTagAssignment[];
  validationErrors: EditorTagValidation[];
}

/**
 * Readable families for the server's {field, code} complaints.
 *
 * A code with no family still produces a readable sentence — never the code.
 */
const ERROR_FAMILIES: Record<string, string> = {
  duplicate: 'tag:error:duplicate',
  invalid: 'tag:error:invalid',
  out_of_range: 'tag:error:out_of_range',
  required: 'tag:error:required',
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

/**
 * The editor's tagging state, loaded once per editor session.
 */
export class TagStore {
  private tags: EditorTag[] = [];
  private byRoot = new Map<number, EditorTagAssignment[]>();
  private loaded = false;

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

  public async load(signal?: AbortSignal): Promise<void> {
    const result = await this.api.post<TagListResult>('tag_list', {}, signal);
    this.tags = Array.isArray(result.tags) ? result.tags : [];
    this.byRoot = new Map();
    (Array.isArray(result.assignments) ? result.assignments : [])
      .forEach((entry) => {
        this.byRoot.set(
          Number(entry.rootId),
          Array.isArray(entry.tags) ? entry.tags : [],
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
  public async saveForQuestion(
    questionId: number,
    tagIds: number[],
  ): Promise<{message: string; ok: boolean}> {
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
    this.byRoot.set(
      Number(result.rootId),
      Array.isArray(result.tags) ? result.tags : [],
    );
    return {
      message: editorString(
        this.strings,
        'tag:saved',
        'Die Merkmale sind gespeichert.',
      ),
      ok: true,
    };
  }
}

/**
 * Render the label picker of one question.
 *
 * Shapes rather than colour alone carry the meaning (DESIGN 2.1.3): every
 * label is a labelled checkbox, and the colour code is only an accent.
 */
export function renderTagPicker(
  store: TagStore,
  strings: ClientStrings,
  questionId: number,
  rootId: number,
  onSaved: (message: string, ok: boolean) => void,
): HTMLElement {
  const block = element('fieldset', 'quizgeist-editor-tags');
  block.dataset.editorTags = '';
  const legend = element('legend', 'quizgeist-editor-tags__legend');
  legend.textContent = editorString(strings, 'tag', 'Merkmale');
  block.append(legend);

  const available = store.all();
  if (!available.length) {
    const empty = element('p', 'quizgeist-editor-tags__empty');
    empty.textContent = editorString(
      strings,
      'tag:empty',
      'Für diese Aktivität sind noch keine Merkmale angelegt.',
    );
    block.append(empty);
    return block;
  }

  const assigned = new Set(
    store.assignmentsFor(rootId).map((entry) => entry.tagId),
  );
  const list = element('div', 'quizgeist-editor-tags__list');
  const inputs: HTMLInputElement[] = [];
  available.forEach((tag) => {
    const id = `quizgeist-tag-${questionId}-${tag.id}`;
    const row = element('div', 'quizgeist-editor-tags__item');
    const input = element('input', 'quizgeist-editor-tags__checkbox');
    input.type = 'checkbox';
    input.id = id;
    input.value = String(tag.id);
    input.checked = assigned.has(tag.id);
    input.dataset.tagId = String(tag.id);
    const label = element('label', 'quizgeist-editor-tags__label');
    label.htmlFor = id;
    label.textContent = tag.label;
    if (tag.colorKey) {
      label.dataset.colorKey = tag.colorKey;
    }
    const kind = element('span', 'quizgeist-editor-tags__kind');
    kind.textContent = editorString(
      strings,
      `tag:kind:${tag.kind}`,
      String(tag.kind),
    );
    row.append(input, label, kind);
    list.append(row);
    inputs.push(input);
  });
  block.append(list);

  const save = button(
    editorString(strings, 'tag:action:save', 'Merkmale speichern'),
    'quizgeist-editor-button',
  );
  save.dataset.action = 'save-tags';
  save.addEventListener('click', () => {
    const selected = inputs
      .filter((input) => input.checked)
      .map((input) => Number(input.value));
    save.disabled = true;
    void store.saveForQuestion(questionId, selected)
      .then((result) => {
        onSaved(result.message, result.ok);
      })
      .catch(() => {
        onSaved(
          editorString(
            strings,
            'tag:error:generic',
            'Die Merkmale konnten nicht gespeichert werden.',
          ),
          false,
        );
      })
      .finally(() => {
        save.disabled = false;
      });
  });
  block.append(save);
  return block;
}
