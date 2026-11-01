import type { InspectionType } from '@/api/inspections';
import { sectionForInspectionType } from '@/lib/sections';

/**
 * Which of the two books an úkon belongs to (spec 6.6, 9.6). The PHP twin is
 * Firol\Support\BookMapping — the two lists must stay identical.
 *
 *   po    požiarna kniha — PHP, hydranty, požiarne uzávery, núdzové osvetlenie,
 *         TS hadíc, oprava / plnenie / TS PHP, vyradenie, the preventívna
 *         prehliadka (its PO record), the old požiarna kniha records and every
 *         training of the PO tree.
 *   bozp  kniha BOZP — every úkon of the BOZP section, rebríky and regále
 *         included (they are not ochrana pred požiarmi), and the oboznámenie BOZP.
 */
export type BookKey = 'po' | 'bozp';

export const BOOKS: BookKey[] = ['po', 'bozp'];

export const BOOK_LABELS: Record<BookKey, string> = {
  po: 'Požiarna kniha',
  bozp: 'Kniha BOZP',
};

/** Inspection types of the požiarna kniha. `preventivna_prehliadka` arrives with phase 7. */
const PO_INSPECTION_TYPES: string[] = [
  'php', 'hydranty', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie',
  'ts_hadic', 'oprava_ts_php', 'vyradenie',
  'preventivna_prehliadka', 'poziarna_kniha',
];

export function isBook(value: string | null | undefined): value is BookKey {
  return value === 'po' || value === 'bozp';
}

/** The book an inspection type is written into, or null when it has none. */
export function bookOfInspectionType(type: InspectionType | string): BookKey | null {
  if (PO_INSPECTION_TYPES.includes(type)) return 'po';
  return sectionForInspectionType(type as InspectionType) === 'bozp' ? 'bozp' : null;
}

/** Trainings of the trainings table are all PO (the oboznámenie BOZP is an inspection type). */
export function bookOfTraining(): BookKey {
  return 'po';
}
