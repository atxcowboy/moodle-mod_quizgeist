import {
  QUESTION_TYPES,
  type EditorQuestion,
  type MediaFile,
  type QuestionOptions,
  type QuestionType,
  type StructuredValidationError,
  type ValidationErrors,
} from './types';

export function isQuestionType(value: unknown): value is QuestionType {
  return typeof value === 'string' && QUESTION_TYPES.includes(value as QuestionType);
}

function record(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};
}

function stringValue(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

function numberValue(value: unknown, fallback = 0): number {
  const parsed = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

/**
 * Clone server-owned option data without mirroring its type-specific schema.
 *
 * PHP supplies defaults, limits and field semantics. This client guard merely
 * keeps JSON objects and arrays editable without sharing response references.
 */
function cloneJsonValue(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((entry) => cloneJsonValue(entry));
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>)
        .map(([key, entry]) => [key, cloneJsonValue(entry)]),
    );
  }
  if (
    value === null
    || typeof value === 'string'
    || typeof value === 'number'
    || typeof value === 'boolean'
  ) {
    return value;
  }
  return null;
}

export function normalizeOptions(raw: unknown): QuestionOptions {
  return cloneJsonValue(record(raw)) as QuestionOptions;
}

function normalizeFiles(raw: unknown): MediaFile[] {
  if (!Array.isArray(raw)) {
    return [];
  }
  return raw
    .filter((entry) => entry && typeof entry === 'object' && !Array.isArray(entry))
    .map((entry) => {
      const file = record(entry);
      return {
        filename: stringValue(file.filename),
        filepath: stringValue(file.filepath, '/'),
        mimetype: stringValue(file.mimetype),
        path: stringValue(file.path),
        url: stringValue(file.url),
      };
    })
    .filter((file) => file.filename !== '' && file.url !== '');
}

function normalizeValidationErrors(raw: unknown): ValidationErrors {
  if (Array.isArray(raw)) {
    return raw.flatMap((entry): Array<string | StructuredValidationError> => {
      if (typeof entry === 'string') {
        return entry === '' ? [] : [entry];
      }
      const error = record(entry);
      const field = stringValue(error.field);
      const code = stringValue(error.code);
      if (field === '' || code === '') {
        return [];
      }
      return [{
        field,
        code,
        ...(typeof error.message === 'string' && error.message !== ''
          ? {message: error.message}
          : {}),
      }];
    });
  }
  if (raw && typeof raw === 'object') {
    return raw as Record<string, string | string[]>;
  }
  return [];
}

export function normalizeQuestion(raw: unknown): EditorQuestion {
  const source = record(raw);
  const qtype = isQuestionType(source.qtype) ? source.qtype : 'open';
  const pointmode = source.pointmode === 'double' || source.pointmode === 'none'
    ? source.pointmode
    : 'standard';

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
    status: stringValue(source.status, 'draft'),
    validationErrors: normalizeValidationErrors(source.validationErrors),
    files: normalizeFiles(source.files),
    timemodified: numberValue(source.timemodified),
    version: numberValue(source.version),
  };
}

export function cloneQuestionForSave(question: EditorQuestion): Record<string, unknown> {
  return {
    id: question.id,
    qtype: question.qtype,
    questiontext: question.questiontext,
    options: cloneJsonValue(question.options),
    timelimit: question.timelimit,
    pointmode: question.pointmode,
    explanation: question.explanation,
    timemodified: question.timemodified,
  };
}
