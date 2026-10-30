/**
 * Splitting a pasted attendee list into rows — spec 8.3 „Hromadné pridanie":
 * one person per line, fields `meno; zaradenie; osobné číslo` separated by a
 * semicolon or a TAB (pasting a range out of Excel gives TABs).
 *
 * Shared by the training attendee list and the oboznámenie BOZP person list,
 * so a paste reads the same in both and the preview count is the count the
 * server will save.
 */
export type ParsedAttendee = {
  name: string;
  position: string | null;
  personalNumber: string | null;
};

export type ParsedAttendees = {
  rows: ParsedAttendee[];
  /** Non-blank lines that have no name (e.g. only a number) and are left out. */
  skipped: number;
};

export function parseAttendeeLines(text: string): ParsedAttendees {
  const rows: ParsedAttendee[] = [];
  let skipped = 0;
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    // A line of nothing but separators (an empty Excel row) is blank, not a mistake.
    if (line === '' || /^[;\t\s]*$/.test(line)) continue;
    const cells = line.split(/[;\t]/).map((c) => c.trim());
    if (!cells[0]) {
      skipped += 1;
      continue;
    }
    rows.push({
      name: cells[0],
      position: cells[1] || null,
      personalNumber: cells[2] || null,
    });
  }
  return { rows, skipped };
}

/** „1 človek", „3 ľudia", „20 ľudí" — the count in the preview and the toast. */
export function peopleCount(n: number): string {
  if (n === 1) return '1 človek';
  if (n >= 2 && n <= 4) return `${n} ľudia`;
  return `${n} ľudí`;
}
