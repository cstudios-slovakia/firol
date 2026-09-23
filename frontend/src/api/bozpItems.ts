/**
 * Block 2 — the BOZP úkony whose rows are items (chapters 5.3, 5.4 and 7):
 * Kontrola OOPP, pracovných prostriedkov, rebríkov, regálov and
 * bezpečnostného označenia.
 *
 * Mirrors `Firol\Support\BozpItems` on the server: every row is an inspection
 * item (so it can carry photos and nedostatky and carry over to next year),
 * and whatever belongs to the úkon as a whole — the záver, the opatrenia —
 * lives in `inspection.details`.
 */
import type { InspectionType } from '@/api/inspections';

export type BozpItemType = 'oopp' | 'pracovne_prostriedky' | 'rebriky' | 'regale' | 'oznacenie';

export const BOZP_ITEM_TYPES: BozpItemType[] = [
  'oopp', 'pracovne_prostriedky', 'rebriky', 'regale', 'oznacenie',
];

export function isBozpItemType(type: InspectionType): type is BozpItemType {
  return (BOZP_ITEM_TYPES as string[]).includes(type);
}

/** Chapter 5.4 — rebrík / regál. */
export type DisposalResult = 'vyhovuje' | 'nevyhovuje' | 'vyradene';
/** Chapter 7 — pracovné prostriedky and označenie. */
export type PassFail = 'vyhovuje' | 'nevyhovuje';
/** Chapter 5.4 — oopp. */
export type OoppCondition = 'vyhovujuci' | 'opotrebeny' | 'chyba';

/** Labels as `ciselniky.json` → `stavy_poloziek` spells them. */
export const BOZP_RESULT_LABELS: Record<DisposalResult, string> = {
  vyhovuje: 'Vyhovuje',
  nevyhovuje: 'Nevyhovuje',
  vyradene: 'Vyradené',
};

export const OOPP_CONDITION_LABELS: Record<OoppCondition, string> = {
  vyhovujuci: 'Vyhovujúci',
  opotrebeny: 'Opotrebený',
  chyba: 'Chýba',
};

/**
 * The nine predefined kinds of bezpečnostné a zdravotné označenie
 * (chapter 7, „9 predpripravených druhov, dá sa pridať vlastný"). The data
 * package carries them only in the delivered protocol mockup
 * (`02_NAHLADY/bozp_protokoly.html`, OZN-2026-002), so they are taken from
 * there word for word. Stored on the row as the text itself, which is what
 * lets a custom kind sit beside them without a second field.
 */
export const OZNACENIE_KINDS: string[] = [
  'Označenie únikových ciest a východov',
  'Označenie hasiacich prístrojov a hydrantov',
  'Zákazové značky (zákaz fajčenia, vstup nepovolaným)',
  'Príkazové značky (používanie OOPP)',
  'Výstražné značky (nebezpečenstvo, VZV)',
  'Označenie hlavných vypínačov a uzáverov médií',
  'Označenie nosnosti podláh a regálov',
  'Vodorovné značenie komunikácií',
  'Umiestnenie a viditeľnosť lekárničiek',
];

export type RebrikItemFields = {
  inventory_number: string;
  type: string;
  manufacturer: string | null;
  year: number | null;
  location: string;
  faults: string | null;
  result: DisposalResult;
  defects?: unknown[];
};

export type RegalItemFields = {
  label: string;
  type: string;
  capacity: string;
  location: string;
  capacity_marked: boolean;
  faults: string | null;
  result: DisposalResult;
  defects?: unknown[];
};

export type OoppItemFields = {
  position: string;
  equipment: string;
  provided: boolean;
  used: boolean | null;
  condition: OoppCondition | null;
  notes: string | null;
  defects?: unknown[];
};

export type PracovnyProstriedokItemFields = {
  name: string;
  manufacturer_type: string | null;
  inventory_number: string | null;
  location: string;
  faults: string | null;
  result: PassFail;
  defects?: unknown[];
};

export type OznacenieItemFields = {
  kind: string;
  location: string;
  result: PassFail;
  notes: string | null;
  defects?: unknown[];
};

export type BozpItemFields =
  | RebrikItemFields
  | RegalItemFields
  | OoppItemFields
  | PracovnyProstriedokItemFields
  | OznacenieItemFields;

/** One row of the repeatable opatrenia block (pracovné prostriedky, označenie). */
export type MeasureRow = {
  measure: string;
  /** ISO date, or null when there is none or the opatrenie is immediate. */
  deadline: string | null;
  /** „ihneď" — how the delivered protocol writes an immediate opatrenie. */
  immediately: boolean;
};

/** `inspection.details` per type. */
export type BozpDetails =
  | { conclusion: string | null }
  | { measures_text: string | null }
  | { measures: MeasureRow[] };

/**
 * A row whose required assessment is still blank — only a row carried over
 * from last time (chapter 12); fresh rows are refused without it. Mirrors
 * `BozpItems::unassessedCount()`, which blocks the PDF until it is filled.
 */
export function isUnassessed(type: BozpItemType, fields: Record<string, unknown>): boolean {
  const r = fields.result;
  switch (type) {
    case 'oopp':
      return typeof fields.provided !== 'boolean';
    case 'regale':
      return typeof fields.capacity_marked !== 'boolean'
        || !(r === 'vyhovuje' || r === 'nevyhovuje' || r === 'vyradene');
    case 'rebriky':
      return !(r === 'vyhovuje' || r === 'nevyhovuje' || r === 'vyradene');
    default:
      return !(r === 'vyhovuje' || r === 'nevyhovuje');
  }
}
