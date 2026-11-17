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

/** „1 položka", „2 položky", „5 položiek" — a count with its Slovak plural. */
export function plural(n: number, one: string, few: string, many: string): string {
  return `${n} ${n === 1 ? one : n >= 2 && n <= 4 ? few : many}`;
}
