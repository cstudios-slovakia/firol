import type { InspectionType } from '@/api/inspections';

/**
 * Which book an úkon belongs to (spec 6.6, 9.6, Decision D4). The PHP twin is
 * Firol\Support\BookMapping — the two lists must stay identical.
 *
 *   po    požiarna kniha — PHP, hydranty, požiarne uzávery, núdzové osvetlenie,
 *         TS hadíc, oprava / plnenie / TS PHP, vyradenie, the preventívna
 *         prehliadka (its PO record), the old požiarna kniha records and every
 *         training of the PO tree.
 *
 * Per 02.11 spec and Decision D4, kniha BOZP does not exist; only Požiarna kniha.
 * BOZP inspections are standalone records, never entered into a book.
 */
export type BookKey = 'po' | 'bozp';

export const BOOKS: BookKey[] = ['po'];

export const BOOK_LABELS: Record<BookKey, string> = {
  po: 'Požiarna kniha',
  bozp: 'Preventívna prehliadka BOZP',
};

/** Inspection types of the požiarna kniha. */
const PO_INSPECTION_TYPES: string[] = [
  'php', 'hydranty', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie',
  'ts_hadic', 'oprava_ts_php', 'vyradenie',
  'preventivna_prehliadka', 'poziarna_kniha',
];

export function isBook(value: string | null | undefined): value is BookKey {
  return value === 'po';
}

/** The book an inspection type is written into, or null when it has none. */
export function bookOfInspectionType(type: InspectionType | string): BookKey | null {
  if (PO_INSPECTION_TYPES.includes(type)) return 'po';
  return null;
}

/** Trainings of the trainings table are all PO (the oboznámenie BOZP is an inspection type). */
export function bookOfTraining(): BookKey {
  return 'po';
}
