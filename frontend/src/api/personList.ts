import { api } from '@/lib/api';
import type { InspectionItem, InspectionType } from '@/api/inspections';

/**
 * The „osoby" úkony of block 2 — chapters 7, 8 and 8.1:
 *
 *   dychova_skuska   Dychová skúška na alkohol              DS-RRRR-NNN
 *   omamne_latky     Kontrola omamných a psychotropných l.  OPL-RRRR-NNN
 *   skolenie_bozp    Oboznámenie zamestnancov v oblasti BOZP SKB-RRRR-NNN
 *
 * Each person is an inspection item; the header above the list (device,
 * test kit, druh oboznámenia…) is `inspection.details`. Server counterpart:
 * backend Firol\Support\PersonList. Code lists are verbatim from
 * 05_DATA/ciselniky.json.
 */
export type PersonListType = 'dychova_skuska' | 'omamne_latky' | 'skolenie_bozp';

export const PERSON_LIST_TYPES: PersonListType[] = ['dychova_skuska', 'omamne_latky', 'skolenie_bozp'];

export function isPersonListType(type: InspectionType): type is PersonListType {
  return (PERSON_LIST_TYPES as string[]).includes(type);
}

/** The two tests that can be printed blank for handwriting (chapter 8.1). */
export function isTestType(type: InspectionType): boolean {
  return type === 'dychova_skuska' || type === 'omamne_latky';
}

/** stavy_poloziek.osoba_skuska */
export type PersonResult = 'negativny' | 'pozitivny' | 'odmietol';

export const PERSON_RESULT_LABELS: Record<PersonResult, string> = {
  negativny: 'Negatívny',
  pozitivny: 'Pozitívny',
  odmietol: 'Odmietol podrobiť sa skúške',
};

/** Short form for a button on a phone — the protocol legend spells it out. */
export const PERSON_RESULT_SHORT: Record<PersonResult, string> = {
  negativny: 'Negatívny',
  pozitivny: 'Pozitívny',
  odmietol: 'Odmietol',
};

/** tlac_formulara */
export type FormVariant = 'vyplneny' | 'prazdny';

export const FORM_VARIANT_LABELS: Record<FormVariant, string> = {
  vyplneny: 'Vyplnený',
  prazdny: 'Prázdny na ručné doplnenie',
};

/** typy_skolenia_bozp */
export type TrainingKind =
  | 'vstupne' | 'opakovane' | 'veduci' | 'zastupca'
  | 'pri_zmene' | 'stavebne_prace' | 'dodavatel' | 'vlastne';

export const TRAINING_KINDS: { value: TrainingKind; label: string }[] = [
  { value: 'vstupne', label: 'Vstupné oboznámenie zamestnanca' },
  { value: 'opakovane', label: 'Opakované oboznámenie' },
  { value: 'veduci', label: 'Oboznámenie vedúcich zamestnancov' },
  { value: 'zastupca', label: 'Oboznámenie zástupcu zamestnancov pre bezpečnosť' },
  { value: 'pri_zmene', label: 'Oboznámenie pri zmene technológie, prostriedku alebo postupu' },
  { value: 'stavebne_prace', label: 'Oboznámenie pri stavebných prácach' },
  { value: 'dodavatel', label: 'Oboznámenie zamestnancov dodávateľa' },
  { value: 'vlastne', label: 'Vlastný názov' },
];

/** odkazy_na_osnovu — the protocol never carries a tematický plán (chapter 8). */
export const CONTENT_REFERENCES = [
  'Obsah školenia podľa smernice o výchove a vzdelávaní zamestnávateľa.',
  'Obsah školenia podľa prílohy — osnova školenia.',
];

/** One person as stored on the item. */
export type PersonFields = {
  name: string;
  position: string;
  /** Tests only — HH:MM. */
  time?: string | null;
  /** Dychová skúška only — as read off the device, e.g. „0,00". */
  value?: string | null;
  /** Tests only. */
  result?: PersonResult | null;
  /** Oboznámenie only — YYYY-MM-DD, the úkon's date when empty. */
  date?: string | null;
  /** Signed on the display (PNG data URI), or null for a pen signature. */
  signature?: string | null;
  /** The shared „Zistené nedostatky" block (components/DefectsEditor). */
  defects?: unknown[];
};

export type TestDetails = {
  device_type?: string | null;
  device_serial?: string | null;
  calibration_valid_to?: string | null;
  test_type?: string | null;
  batch?: string | null;
  expiry?: string | null;
  measures?: string | null;
};

export type TrainingDetails = {
  kind?: TrainingKind | null;
  kind_custom?: string | null;
  duration_min?: number | null;
  content?: string | null;
};

export function personFields(item: InspectionItem): PersonFields {
  const f = item.fields;
  const s = (k: string): string | null => (typeof f[k] === 'string' ? (f[k] as string) : null);
  const r = s('result');
  return {
    name: s('name') ?? '',
    position: s('position') ?? '',
    time: s('time'),
    value: s('value'),
    result: r === 'negativny' || r === 'pozitivny' || r === 'odmietol' ? r : null,
    date: s('date'),
    signature: s('signature'),
    defects: Array.isArray(f.defects) ? (f.defects as unknown[]) : [],
  };
}

/**
 * What still blocks the step to the summary (chapter 7, „povinné polia sa
 * nedajú obísť"). Only the list itself — names and pracovné zaradenie — and,
 * for an oboznámenie, its druh and obsah. Results are NOT required here: the
 * blank form of chapter 8.1 is printed from exactly this state (29.3 step 4).
 * The filled protocol checks the results and the device when it is issued.
 */
export function summaryBlockers(
  type: PersonListType,
  details: Record<string, unknown> | null | undefined,
  items: InspectionItem[],
): string[] {
  const out: string[] = [];
  if (items.length === 0) {
    out.push(type === 'skolenie_bozp' ? 'Pridaj aspoň jedného účastníka.' : 'Pridaj aspoň jednu osobu.');
  }
  const incomplete = items.filter((it) => {
    const p = personFields(it);
    return p.name.trim() === '' || p.position.trim() === '';
  }).length;
  if (incomplete > 0) {
    out.push(`Pri ${incomplete} ${incomplete === 1 ? 'osobe' : 'osobách'} chýba meno alebo pracovné zaradenie.`);
  }
  if (type === 'skolenie_bozp') {
    const d = (details ?? {}) as TrainingDetails;
    if (!d.kind) out.push('Vyber druh oboznámenia.');
    else if (d.kind === 'vlastne' && !(d.kind_custom ?? '').trim()) out.push('Doplň vlastný názov oboznámenia.');
    if (!(d.content ?? '').trim()) out.push('Vyber alebo napíš obsah oboznámenia.');
  }
  return out;
}

/** An earlier person list of the same company that names can be taken from. */
export type PersonSource = {
  kind: 'inspection' | 'training';
  id: number;
  /** Inspection type, or the training type slug. */
  type: string;
  date: string | null;
  facility_name: string | null;
  people: number;
};

export const PersonList = {
  sources: (inspectionId: number) =>
    api<{ sources: PersonSource[] }>(`/api/inspections/${inspectionId}/person-sources`),
  takeOver: (inspectionId: number, source: Pick<PersonSource, 'kind' | 'id'>, csrfToken: string | null) =>
    api<{ added: number; skipped: number }>(`/api/inspections/${inspectionId}/persons/take-over`, {
      method: 'POST',
      body: { source_kind: source.kind, source_id: source.id },
      csrfToken,
      requireOnline: true,
    }),
  /** Reopen a test printed blank so the handwritten results can be typed in. */
  fillResults: (inspectionId: number, csrfToken: string | null) =>
    api<{ ok: true; number: string }>(`/api/inspections/${inspectionId}/fill-results`, {
      method: 'POST',
      csrfToken,
      requireOnline: true,
    }),
};

/** „14. 5. 2026" */
export function skDate(iso: string | null | undefined): string {
  if (!iso) return '';
  const [y, m, d] = iso.slice(0, 10).split('-');
  return `${Number(d)}. ${Number(m)}. ${y}`;
}

/** „8 osôb", „2 osoby", „1 osobu" (accusative, after „Prevziať"). */
export function peopleAccusative(n: number): string {
  if (n === 1) return '1 osobu';
  if (n >= 2 && n <= 4) return `${n} osoby`;
  return `${n} osôb`;
}
