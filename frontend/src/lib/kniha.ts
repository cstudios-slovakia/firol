/**
 * Zápis do požiarnej knihy — the new entry format „pk2" (POapp 7. 11. 2026).
 *
 * The client twin of backend/src/Support/BookEntry.php: the shape of
 * `inspections.details`, the few texts an entry needs before the server has
 * seen it (an entry created offline), the date rule of the server and the
 * paths of the book's screens.
 *
 * Old entries (no `details.format`) keep the generic úkon screens and the old
 * template; {@link isKnihaV2} is the one test.
 */
import type { InspectionItem, InspectionType } from '@/api/inspections';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { effectiveDeadline, parseDefect, type DefectFields } from '@/lib/prehliadka';

export const KNIHA_TYPE = 'poziarna_kniha' as const;
export const FORMAT = 'pk2' as const;

/** Spec 5 — the Úvod an entry is created with; the app never writes it again. */
export const LEGAL =
  'Vykonaná preventívna protipožiarna prehliadka v zmysle zákona č. 314/2001 Z. z. ' +
  'o ochrane pred požiarmi v znení neskorších predpisov a vyhlášky MV SR č. 121/2002 Z. z. ' +
  'o požiarnej prevencii v znení neskorších predpisov.';

/** Spec 8 — the two sentences the „Na záver" buttons append. */
export const NA_ZAVER = [
  'V čase kontroly na kontrolovaných pracoviskách neboli zistené žiadne nedostatky.',
  'Nedostatky z predchádzajúceho zápisu boli odstránené.',
] as const;

/** Spec 4 — the line above the activities on the screen. */
export const ACTIVITIES_HEADING_NOW = 'Obsahom prehliadky je kontrola:';
/** Spec 6.3 / 9 — the same line as printed (BookEntry::ACTIVITIES_HEADING_PRINT). */
export const ACTIVITIES_HEADING_PRINT = 'Obsahom prehliadky bola kontrola:';
/** Spec 6.3 — the heading of the technician's own activities in print. */
export const OWN_ACTIVITIES_HEADING = 'Ďalej vykonané:';

/** Spec 4 — the periodicities a spôsob 1 entry offers per prevádzka. */
export const PERIODICITY_MONTHS = [12, 6, 3] as const;
export type KnihaMonths = (typeof PERIODICITY_MONTHS)[number];

export type KnihaSposob = 1 | 2;

export type KnihaZaznam = { kind: 'inspection' | 'training'; id: number; forma: 'plny' | 'odkaz' };

/** What was frozen when the PDF was generated (BookEntry::issuedFrom). */
export type KnihaIssued = {
  at: string;
  facilities: { id: number; name: string }[];
  fixed_sentence: string;
  paragraphs: { kind: 'inspection' | 'training'; id: number; forma: 'plny' | 'odkaz'; number: string; text: string }[];
  schvalil: string;
};

export type KnihaDetails = {
  format: typeof FORMAT;
  sposob: KnihaSposob;
  sposob_rucne: boolean;
  /** Null only before the server prefilled it; then a plain string the technician owns. */
  uvod: string | null;
  cinnosti: string[];
  vlastne_cinnosti: string[];
  doplnujuci_text: string;
  text_zapisu: string;
  zaznamy: KnihaZaznam[];
  zaver: string;
  /** Null keeps (or, on create, prefills) the stored value; '' is a cleared name. */
  schvalil: string | null;
  issued?: KnihaIssued;
};

export function isKnihaV2(details: unknown): boolean {
  return !!details && typeof details === 'object' && (details as { format?: unknown }).format === FORMAT;
}

/** Spec 4 — one prevádzka → spôsob 1, two or more → spôsob 2. */
export function defaultSposob(count: number): KnihaSposob {
  return count === 1 ? 1 : 2;
}

/** A fresh entry's details (step 1 of a new entry). */
export function newKnihaDetails(sposob: KnihaSposob, sposobRucne: boolean, schvalil: string | null): KnihaDetails {
  return {
    format: FORMAT,
    sposob,
    sposob_rucne: sposobRucne,
    uvod: LEGAL,
    cinnosti: [],
    vlastne_cinnosti: [],
    doplnujuci_text: '',
    text_zapisu: '',
    zaznamy: [],
    zaver: '',
    schvalil,
  };
}

const str = (v: unknown): string => (typeof v === 'string' ? v : '');
const strList = (v: unknown): string[] => (Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []);

/** The stored details of a pk2 entry, every key defaulted. */
export function parseKnihaDetails(raw: unknown): KnihaDetails {
  const d = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const zaznamy: KnihaZaznam[] = [];
  for (const z of Array.isArray(d.zaznamy) ? d.zaznamy : []) {
    if (!z || typeof z !== 'object') continue;
    const r = z as Record<string, unknown>;
    if ((r.kind === 'inspection' || r.kind === 'training') && typeof r.id === 'number') {
      zaznamy.push({ kind: r.kind, id: r.id, forma: r.forma === 'odkaz' ? 'odkaz' : 'plny' });
    }
  }
  const issued = d.issued && typeof d.issued === 'object' ? (d.issued as KnihaIssued) : undefined;
  return {
    format: FORMAT,
    sposob: d.sposob === 1 ? 1 : 2,
    sposob_rucne: d.sposob_rucne === true,
    uvod: typeof d.uvod === 'string' ? d.uvod : null,
    cinnosti: strList(d.cinnosti),
    vlastne_cinnosti: strList(d.vlastne_cinnosti),
    doplnujuci_text: str(d.doplnujuci_text),
    text_zapisu: str(d.text_zapisu),
    zaznamy,
    zaver: str(d.zaver),
    schvalil: typeof d.schvalil === 'string' ? d.schvalil : null,
    ...(issued ? { issued } : {}),
  };
}

/** What a PATCH sends: the whole header, never `issued` (the server's own). */
export function knihaDetailsPayload(d: KnihaDetails): Record<string, unknown> {
  const { issued: _issued, ...rest } = d;
  return rest;
}

/** The key a record has in `zaznamy` and in the kniha context (`i123` / `t45`). */
export const zaznamKey = (z: { kind: 'inspection' | 'training'; id: number }): string =>
  `${z.kind === 'inspection' ? 'i' : 't'}${z.id}`;

/**
 * The date the next prehliadka is due, computed the way the server computes it
 * (PHP `modify('+N months')`): past the end of a month the date rolls over —
 * 30. 11. 2026 + 3 months is 2. 3. 2027, not 28. 2. The app-wide
 * `lib/periodicity.ts validUntil` clamps instead, so it is not used here.
 */
export function validUntilLikeServer(dateIso: string | null, months: number): string | null {
  if (!dateIso || !/^\d{4}-\d{2}-\d{2}$/.test(dateIso)) return null;
  const [y, m, d] = dateIso.split('-').map(Number);
  const next = new Date(y, m - 1 + months, d);
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
}

/** Slovak plural of a count: 1 / 2–4 / other. */
export function sk3(n: number, one: string, few: string, many: string): string {
  return n === 1 ? one : n >= 2 && n <= 4 ? few : many;
}

export const monthsWord = (n: number): string => sk3(n, 'mesiac', 'mesiace', 'mesiacov');

/** Spec 9 — the one sentence the app writes itself. */
export function fixedSentence(dateIso: string | null, names: string[]): string {
  const date = dateIso ? formatDateSk(dateIso) : '…';
  return names.length > 1
    ? `Dňa ${date} bola vykonaná prehliadka v prevádzkach ${names.join(', ')}.`
    : `Dňa ${date} bola vykonaná prehliadka v prevádzke ${names[0] ?? ''}.`;
}

/**
 * Appends one of the „Na záver" sentences with a single space — not when the
 * text already holds it (spec 8: the same sentence is never inserted twice).
 */
export function appendSentence(text: string, sentence: string): string {
  if (text.includes(sentence)) return text;
  const trimmed = text.replace(/\s+$/, '');
  return trimmed === '' ? sentence : `${trimmed} ${sentence}`;
}

/** One block of the composed entry, in print order (BookEntry::payload / spec 9). */
export type ComposedBlock =
  | { kind: 'band'; title: string }
  | { kind: 'p'; text: string; muted?: boolean }
  | { kind: 'list'; heading: string; items: string[] }
  | { kind: 'defects'; rows: ComposedDefect[]; multi: boolean };

export type ComposedDefect = { n: number; title: string; facility: string; deadline: string; removedOnSite: boolean };

/**
 * The whole entry as it will be printed, section by section: Zápis (spôsob 1
 * Úvod, the fixed sentence, activities, own activities; spôsob 2 the text) →
 * Priložené záznamy → Doplňujúci text (spôsob 1) → Zistené nedostatky (only
 * when there are any) → Na záver. Mirrors BookEntry::payload and the template;
 * texts are trimmed only to decide whether they are empty.
 */
export function composeText(input: {
  sposob: KnihaSposob;
  uvod: string;
  fixedSentence: string;
  cinnosti: string[];
  vlastne: string[];
  textZapisu: string;
  paragraphs: string[];
  doplnujuciText: string;
  defects: ComposedDefect[];
  multi: boolean;
  zaver: string;
}): ComposedBlock[] {
  const out: ComposedBlock[] = [];
  const own = input.vlastne.map((v) => v.trim()).filter((v) => v !== '');
  if (input.sposob === 1) {
    out.push({ kind: 'band', title: 'Zápis' });
    if (input.uvod.trim() !== '') out.push({ kind: 'p', text: input.uvod });
    out.push({ kind: 'p', text: input.fixedSentence });
    if (input.cinnosti.length > 0) out.push({ kind: 'list', heading: ACTIVITIES_HEADING_PRINT, items: input.cinnosti });
    if (own.length > 0) out.push({ kind: 'list', heading: OWN_ACTIVITIES_HEADING, items: own });
  } else if (input.textZapisu.trim() !== '') {
    out.push({ kind: 'band', title: 'Zápis' });
    out.push({ kind: 'p', text: input.textZapisu });
  }
  if (input.paragraphs.length > 0) {
    out.push({ kind: 'band', title: 'Priložené záznamy' });
    for (const p of input.paragraphs) out.push({ kind: 'p', text: p });
  }
  if (input.sposob === 1 && input.doplnujuciText.trim() !== '') {
    out.push({ kind: 'band', title: 'Doplňujúci text' });
    out.push({ kind: 'p', text: input.doplnujuciText });
  }
  if (input.defects.length > 0) {
    out.push({ kind: 'band', title: 'Zistené nedostatky' });
    out.push({ kind: 'defects', rows: input.defects, multi: input.multi });
  }
  if (input.zaver.trim() !== '') out.push({ kind: 'p', text: input.zaver });
  return out;
}

/** The entry's defects, in the order of the record (item position), as {@link composeText} prints them. */
export function knihaDefects(items: InspectionItem[]): { itemId: number; defect: DefectFields; photos: number }[] {
  return [...items]
    .sort((a, b) => a.position - b.position || a.id - b.id)
    .flatMap((it) => {
      const defect = parseDefect(it.fields);
      return defect ? [{ itemId: it.id, defect, photos: it.photos?.length ?? 0 }] : [];
    });
}

/** The deadline as the record prints it: a date, „bezodkladne", or nothing (spec 8). */
export function deadlineText(d: DefectFields, executedOn: string | null): string {
  if (d.deadline_choice === 'bezodkladne') return 'bezodkladne';
  const date = effectiveDeadline(d, executedOn);
  return date ? formatDateSk(date) : '';
}

export function composedDefects(
  rows: { defect: DefectFields }[],
  names: Map<number, string>,
  primaryId: number,
  executedOn: string | null,
): ComposedDefect[] {
  return rows.map(({ defect }, i) => ({
    n: i + 1,
    title: defect.title,
    facility: names.get(defect.facility_id ?? primaryId) ?? '',
    deadline: deadlineText(defect, executedOn),
    removedOnSite: defect.status === 'odstraneny' && defect.persists_from === null,
  }));
}

// ─── Paths ──────────────────────────────────────────────────────────────────

export const KNIHA_NEW_PATH = '/kniha/new';
/** Krok 2 while a draft; the read-only issued entry once finalized. */
export const knihaPath = (id: number): string => `/kniha/${id}`;
export const knihaStep1Path = (id: number): string => `/kniha/${id}/krok-1`;
export const knihaSummaryPath = (id: number): string => `/kniha/${id}/suhrn`;
export const knihaDefectNewPath = (id: number): string => `/kniha/${id}/nedostatok/new`;
export const knihaDefectPath = (id: number, itemId: number): string => `/kniha/${id}/nedostatok/${itemId}`;

/** Where a new entry starts; the query is passed through (company, prevádzka, visit, date). */
export function knihaNewPath(params?: URLSearchParams | Record<string, string>): string {
  const q = new URLSearchParams(params ?? {}).toString();
  return q ? `${KNIHA_NEW_PATH}?${q}` : KNIHA_NEW_PATH;
}

/**
 * Where „Opakovať" lands: a book entry (old or new — the server always makes a
 * pk2 draft) opens on step 1 with its prevádzky and periodicities prefilled
 * (design G.4); every other úkon on its generic page.
 */
export function repeatPath(type: InspectionType, newId: number): string {
  return type === KNIHA_TYPE ? knihaStep1Path(newId) : `/inspections/${newId}`;
}
