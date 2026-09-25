/**
 * Field shapes of the single-record BOZP úkony of block 2 — kniha BOZP,
 * kontrola pracoviska, osamelé pracoviská and fajčenie. Each is one record
 * (one inspection item) holding the whole úkon; mirrors backend
 * Firol\Support\BozpRecords, which validates the same shapes.
 */
import type { DefectPayload } from '@/components/DefectsEditor';

export type BozpRecordType = 'kniha_bozp' | 'pracovisko' | 'osamele_pracovisko' | 'fajcenie';

/**
 * Kniha kontrol BOZP — the 11 predefined activities, verbatim from
 * `05_DATA/checklist_kniha_bozp.json`. The server validates against its own
 * copy of the same file (backend/src/Support/data).
 */
export const KNIHA_BOZP_ACTIVITIES: string[] = [
  'Kontrola stavu pracoviska a pracovného prostredia',
  'Kontrola stavu a používania pracovných prostriedkov',
  'Kontrola používania osobných ochranných pracovných prostriedkov',
  'Kontrola bezpečnostného označenia a značiek',
  'Kontrola stavu únikových ciest a voľných komunikácií',
  'Kontrola vybavenia lekárničky a prostriedkov prvej pomoci',
  'Kontrola platnosti odborných prehliadok technických zariadení',
  'Kontrola aktuálnosti dokumentácie BOZP',
  'Kontrola oboznámenia zamestnancov a platnosti školení',
  'Kontrola dodržiavania zákazu fajčenia',
  'Kontrola stavu skladovania a manipulácie s materiálom',
];

/**
 * Kontrola pracoviska — chapter 7's eight areas, worded as the binding PRAC
 * protocol mockup prints them.
 */
export const PRACOVISKO_AREAS: string[] = [
  'Čistota a poriadok na pracovisku',
  'Voľné a bezpečné komunikácie a únikové cesty',
  'Stav podláh, schodísk, rámp a zábradlí',
  'Osvetlenie — denné aj umelé',
  'Vetranie, teplota a mikroklíma',
  'Sociálne zariadenia, šatne, pitný režim',
  'Skladovanie a manipulácia s materiálom',
  'Bezpečnostné a zdravotné označenie',
];

/** ciselniky.json `vysledok_ukonu`. */
export type KnihaResult = 'bez_nedostatkov' | 'zistene_nedostatky';
export const KNIHA_RESULT_LABELS: Record<KnihaResult, string> = {
  bez_nedostatkov: 'Bez nedostatkov',
  zistene_nedostatky: 'Zistené nedostatky',
};

/** ciselniky.json `hodnotenie_auditu` — the celkové hodnotenie. */
export type OverallResult = 'vyhovujuci' | 'vyhovujuci_s_vyhradami' | 'nevyhovujuci';
export const OVERALL_RESULTS: OverallResult[] = ['vyhovujuci', 'vyhovujuci_s_vyhradami', 'nevyhovujuci'];
export const OVERALL_LABELS: Record<OverallResult, string> = {
  vyhovujuci: 'Vyhovujúci',
  vyhovujuci_s_vyhradami: 'Vyhovujúci s výhradami',
  nevyhovujuci: 'Nevyhovujúci',
};
/** ciselniky.json `hodnotenie_auditu[].popis` — shown under each choice. */
export const OVERALL_HINTS: Record<OverallResult, string> = {
  vyhovujuci: 'Neboli zistené nedostatky alebo len drobné, odstrániteľné na mieste.',
  vyhovujuci_s_vyhradami: 'Zistené nedostatky bez bezprostredného ohrozenia; určený termín odstránenia.',
  nevyhovujuci: 'Zistené závažné nedostatky alebo bezprostredné ohrozenie života a zdravia.',
};

export type RowResult = 'vyhovuje' | 'nevyhovuje';
export const ROW_RESULT_LABELS: Record<RowResult, string> = {
  vyhovuje: 'Vyhovuje',
  nevyhovuje: 'Nevyhovuje',
};

export type KnihaBozpFields = {
  workspaces: string[];
  activities: string[];
  custom_activities: string[];
  result: KnihaResult;
  defects: DefectPayload[];
};

export type PracoviskoArea = { name: string; result: RowResult; note: string | null };
export type PracoviskoFields = {
  spaces: string[];
  areas: PracoviskoArea[];
  overall: OverallResult;
  defects: DefectPayload[];
};

export type OsameleRow = {
  workplace: string;
  activity: string;
  connection: string;
  presence_check: string;
  result: RowResult;
};
export type OsameleFields = {
  rows: OsameleRow[];
  overall: OverallResult;
  defects: DefectPayload[];
};

export type FajcenieRow = { area: string; result: RowResult; note: string | null };
export type FajcenieFields = {
  scope: string;
  rows: FajcenieRow[];
  measures: string | null;
  defects: DefectPayload[];
};

/** Any of the four records, as accepted by the items API. */
export type BozpRecordFields = KnihaBozpFields | PracoviskoFields | OsameleFields | FajcenieFields;

export function isRowResult(v: unknown): v is RowResult {
  return v === 'vyhovuje' || v === 'nevyhovuje';
}
export function isOverall(v: unknown): v is OverallResult {
  return typeof v === 'string' && (OVERALL_RESULTS as string[]).includes(v);
}
export function isKnihaResult(v: unknown): v is KnihaResult {
  return v === 'bez_nedostatkov' || v === 'zistene_nedostatky';
}
/** Strings of a stored list, tolerant of anything malformed. */
export function stringList(v: unknown): string[] {
  return Array.isArray(v) ? v.filter((s): s is string => typeof s === 'string' && s.trim() !== '') : [];
}
