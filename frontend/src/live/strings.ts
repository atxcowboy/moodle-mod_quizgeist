export type StringValues = Record<string, string | number>;

/**
 * Neutral stand-in for a string that never reached the client.
 *
 * A missing key must never be readable as a key: teachers and learners are
 * shown a harmless placeholder while the key itself stays available for
 * diagnosis in the browser console.
 */
export const MISSING_STRING_PLACEHOLDER = '…';

const reportedKeys = new Set<string>();

/**
 * Record one missing client string without ever routing it into the DOM.
 */
export function reportMissingString(key: string): void {
  if (key === '' || reportedKeys.has(key)) {
    return;
  }
  reportedKeys.add(key);
  if (typeof console !== 'undefined' && typeof console.warn === 'function') {
    console.warn(
      `[quizgeist] Fehlender Sprachschlüssel: ${key}. `
        + 'Bitte in view.php ausliefern und in lang/de sowie lang/en ergänzen.',
    );
  }
}

/**
 * Resolve one curated Moodle client string and safely substitute the small
 * placeholder subset used by the live UI.
 *
 * An unknown key resolves to the caller's own readable fallback, never to the
 * key. Callers without a fallback receive a neutral placeholder.
 */
export function liveString(
  strings: Record<string, string>,
  key: string,
  values: StringValues = {},
  fallback = '',
): string {
  const configured = strings[key];
  let template: string;
  if (typeof configured === 'string' && configured !== '') {
    template = configured;
  } else {
    reportMissingString(key);
    template = fallback !== '' ? fallback : MISSING_STRING_PLACEHOLDER;
  }
  return template
    .replace(/\{\$a->([a-zA-Z0-9_]+)\}/g, (_match, name: string) => {
      const value = values[name];
      return value === undefined ? '' : String(value);
    })
    .replace(/\{\$a\}/g, () => {
      const value = values.a;
      return value === undefined ? '' : String(value);
    });
}
