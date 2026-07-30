/**
 * Folds a string into a comparison key: lowercased and stripped of
 * diacritics (NFD decomposition splits off the combining marks, which the
 * replace then drops). Used wherever the user filters or searches — "poziar"
 * has to find "Požiar" and "PETER" has to find "Peter", because Slovak input
 * is routinely typed without accents on mobile keyboards.
 */
export function fold(s: string): string {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
