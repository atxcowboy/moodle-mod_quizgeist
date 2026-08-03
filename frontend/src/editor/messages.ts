import {reportMissingString} from '../live/strings';
import type {ValidationErrors} from './types';

export type ClientStrings = Record<string, string>;

/**
 * Neutral stand-in for an editor string that never reached the client.
 */
const MISSING_LABEL = '…';

/**
 * Resolve one editor client string without ever leaking the key into the DOM.
 *
 * A missing key is reported to the browser console — where it stays useful for
 * diagnosis — and replaced by the caller's readable fallback.
 */
export function editorString(
  strings: ClientStrings,
  key: string,
  fallback = '',
): string {
  const configured = strings[key];
  if (typeof configured === 'string' && configured !== '') {
    return configured;
  }
  reportMissingString(key);
  return fallback !== '' ? fallback : MISSING_LABEL;
}

/**
 * Field paths whose readable label lives under a differently named key.
 *
 * The left-hand side is the normalised path with `options.` and any numeric
 * index already removed.
 */
const FIELD_LABEL_ALIASES: Record<string, string> = {
  answersindexed: 'answer',
  answersmedia: 'answerimage',
  answersmediaindexed: 'answerimage',
  items: 'puzzleitems',
  itemsindexed: 'puzzleitem',
  itemsmedia: 'puzzleitemmedia',
  itemsmediaindexed: 'puzzleitemmedia',
  layout: 'slidelayout',
  targetx: 'targetx',
  targety: 'targety',
};

/**
 * Split one server field path into its named segments and 1-based index.
 */
function fieldParts(field: string): {index: number | null; named: string[]} {
  let index: number | null = null;
  const named: string[] = [];
  field.split('.').forEach((segment) => {
    const trimmed = segment.trim();
    if (trimmed === '') {
      return;
    }
    if (/^[0-9]+$/.test(trimmed)) {
      index = Number(trimmed) + 1;
      return;
    }
    named.push(trimmed);
  });
  if (named[0] === 'options') {
    named.shift();
  }
  return {index, named};
}

function normaliseSegments(segments: string[]): string {
  return segments.join('').toLowerCase().replace(/[^a-z0-9]+/g, '');
}

/**
 * Turn one machine field path into the teacher-facing field name.
 *
 * `options.answers.2.media` becomes "Antwortmedium 3", never the path.
 */
export function fieldLabel(strings: ClientStrings, field: string): string {
  const generic = editorString(strings, 'editor:validation:field', 'dieses Feld');
  const {index, named} = fieldParts(field);
  if (named.length === 0) {
    return generic;
  }
  const joined = normaliseSegments(named);
  const candidates: string[] = [];
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

  let label = '';
  for (const candidate of candidates) {
    const configured = strings[`editor:field:${candidate}`];
    if (typeof configured === 'string' && configured !== '') {
      label = configured;
      break;
    }
  }
  if (label === '') {
    return generic;
  }
  if (index === null) {
    return label;
  }
  return editorString(strings, 'editor:validation:fieldnumbered', '{$a} {$b}')
    .replace('{$a}', label)
    .replace('{$b}', String(index));
}

/**
 * Turn the server's field-addressable validation errors into readable German.
 *
 * The transport keeps its stable `field`/`code` pair — the acceptance boundary
 * depends on it — and only the presentation is translated here.
 */
export function validationMessages(
  strings: ClientStrings,
  errors: ValidationErrors,
): string[] {
  const messages: string[] = [];
  const push = (message: string): void => {
    const trimmed = message.trim();
    if (trimmed !== '' && !messages.includes(trimmed)) {
      messages.push(trimmed);
    }
  };
  if (Array.isArray(errors)) {
    errors.forEach((entry) => {
      if (typeof entry === 'string') {
        push(entry);
        return;
      }
      if (typeof entry?.message === 'string' && entry.message.trim() !== '') {
        push(entry.message);
        return;
      }
      const code = typeof entry?.code === 'string' ? entry.code : '';
      const label = fieldLabel(strings, typeof entry?.field === 'string' ? entry.field : '');
      const configured = code === '' ? '' : strings[`editor:validation:${code}`];
      if (typeof configured === 'string' && configured !== '') {
        push(configured.replace('{$a}', label));
        return;
      }
      if (code !== '') {
        reportMissingString(`editor:validation:${code}`);
      }
      push(
        editorString(strings, 'editor:validation:generic', 'Bitte prüfen Sie {$a}.')
          .replace('{$a}', label),
      );
    });
    return messages;
  }
  Object.entries(errors).forEach(([, value]) => {
    const entries = Array.isArray(value) ? value : [value];
    entries.forEach((entry) => {
      if (typeof entry === 'string') {
        push(entry);
      }
    });
  });
  return messages;
}

/**
 * Stable AI-workshop warning codes grouped into the sentence a teacher needs.
 *
 * The gateway and the document importers emit a deliberately machine-readable
 * snake-case vocabulary that grows with every new extractor. Mapping families
 * instead of single codes keeps the wording maintainable, and anything not
 * listed here still lands in the collective message rather than on screen.
 */
const WARNING_PATTERNS: Array<{
  key: string;
  test: RegExp;
}> = [
  {key: 'blankslide', test: /^blank_slide_([0-9]+)$/},
  {key: 'slideimagelimit', test: /^slide_([0-9]+)_image_count_limit$/},
  {key: 'slidenonmedia', test: /^slide_([0-9]+)_non_media_image_ignored$/},
  {key: 'slideunsupported', test: /^slide_([0-9]+)_unsupported_image_ignored$/},
  {key: 'slideimageinvalid', test: /^slide_([0-9]+)_image_validation_failed$/},
  {key: 'gatewayfallback', test: /^gateway_fallback$/},
  {key: 'gateway', test: /^gateway_/},
  {key: 'pdfpreviewunavailable', test: /^pdftoppm_unavailable_text_only_slides$/},
  {key: 'pdfpreviewtimeout', test: /^pdf_preview_timeout$/},
  {key: 'pdfpreviewlimit', test: /^pdf_preview_media_limit$/},
  {key: 'pdfpreview', test: /^pdf_preview_/},
  {key: 'pdftext', test: /^pdftotext_/},
  {key: 'pdfinfo', test: /^pdfinfo_/},
  {key: 'pdfnotext', test: /^php_fallback_no_text$/},
  {key: 'pdflayout', test: /^php_fallback_layout_unavailable$/},
  {key: 'texttruncated', test: /^text_truncated$/},
  {key: 'resulttruncated', test: /^result_truncated$/},
  {key: 'noquestions', test: /^no_question_candidates$/},
  {key: 'duplicatequestions', test: /^duplicate_question_candidates_removed$/},
  {key: 'questionstructure', test: /^question_structure_not_found_used_question_marks$/},
  {key: 'externalignored', test: /^external_relationship_ignored$/},
  {key: 'imagetotallimit', test: /^presentation_image_total_limit$/},
];

const WARNING_FALLBACKS: Record<string, string> = {
  blankslide: 'Folie {$a} enthielt keinen verwertbaren Inhalt und wurde übersprungen.',
  duplicatequestions: 'Mehrfach vorkommende Fragen wurden nur einmal übernommen.',
  externalignored: 'Ein Verweis auf eine externe Quelle wurde aus Sicherheitsgründen ausgelassen.',
  gateway: 'Die KI war nicht erreichbar oder hat unbrauchbar geantwortet. '
    + 'Die Fragen stammen daher aus der regelbasierten Notlösung.',
  gatewayfallback: 'Diese Fragen wurden ohne KI aus dem Quelltext abgeleitet. '
    + 'Bitte prüfen Sie sie besonders sorgfältig.',
  generic: 'Ein Teil der Quelle konnte nicht vollständig ausgewertet werden. '
    + 'Bitte prüfen Sie die Entwürfe vor dem Übernehmen.',
  imagetotallimit: 'Die Präsentation enthielt mehr Bilder als übernommen werden können.',
  noquestions: 'In der Quelle waren keine Fragen erkennbar.',
  pdfinfo: 'Die Seitenzahl des PDFs konnte nicht ermittelt werden.',
  pdflayout: 'Das PDF wurde ohne Layout-Erkennung gelesen; die Reihenfolge kann abweichen.',
  pdfnotext: 'Aus dem PDF ließ sich kein Text lesen. Vermutlich ist es ein reines Bild-PDF.',
  pdfpreview: 'Für einzelne Seiten konnte keine Bildvorschau erzeugt werden.',
  pdfpreviewlimit: 'Es wurden nicht alle Seitenbilder übernommen, '
    + 'weil die Obergrenze für Medien erreicht war.',
  pdfpreviewtimeout: 'Die Bildvorschau des PDFs hat zu lange gedauert und wurde abgebrochen.',
  pdfpreviewunavailable: 'Auf diesem Server können keine Seitenbilder erzeugt werden; '
    + 'die Folien enthalten nur Text.',
  pdftext: 'Der Text des PDFs konnte nicht vollständig gelesen werden.',
  questionstructure: 'Es war keine klare Fragenstruktur erkennbar; '
    + 'die Fragen wurden anhand der Fragezeichen abgegrenzt.',
  resulttruncated: 'Die KI hat mehr Fragen geliefert als angefordert; die überzähligen wurden verworfen.',
  slideimageinvalid: 'Ein Bild auf Folie {$a} war beschädigt und wurde ausgelassen.',
  slideimagelimit: 'Auf Folie {$a} wurden nicht alle Bilder übernommen.',
  slidenonmedia: 'Auf Folie {$a} wurde ein Element ausgelassen, das kein Bild ist.',
  slideunsupported: 'Auf Folie {$a} wurde ein Bild in einem nicht unterstützten Format ausgelassen.',
  texttruncated: 'Die Quelle war sehr lang; es wurde nur der Anfang ausgewertet.',
};

/**
 * Translate one AI-workshop warning value.
 *
 * Values that are not machine codes are already teacher-facing text and pass
 * through untouched.
 */
function warningMessage(strings: ClientStrings, value: string): string {
  const raw = value.trim();
  if (raw === '') {
    return '';
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
      WARNING_FALLBACKS[pattern.key] || WARNING_FALLBACKS.generic,
    ).replace('{$a}', match[1] ?? '');
  }
  reportMissingString(`editor:ai:warning: (unbekannter Code "${raw}")`);
  return editorString(
    strings,
    'editor:ai:warning:generic',
    WARNING_FALLBACKS.generic,
  );
}

/**
 * Turn the AI workshop's warning codes into a deduplicated, readable list.
 */
export function aiWarningMessages(
  strings: ClientStrings,
  warnings: string[],
): string[] {
  const messages: string[] = [];
  warnings.forEach((warning) => {
    const message = warningMessage(strings, warning);
    if (message !== '' && !messages.includes(message)) {
      messages.push(message);
    }
  });
  return messages;
}
