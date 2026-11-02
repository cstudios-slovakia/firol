/**
 * Preventívna prehliadka — the shapes and the small rules of phase 7a
 * (spec 6.1–6.3, plan Phase 7a). The PHP twins are
 * Firol\Support\PrehliadkaDetails and Firol\Support\PrehliadkaDefects.
 *
 * Storage: the prehliadka is an inspection of the type `preventivna_prehliadka`;
 * its setup is `inspections.details`, every defect is one `inspection_items` row.
 */
import { validUntil } from '@/lib/periodicity';

export const PREHLIADKA_TYPE = 'preventivna_prehliadka' as const;

// Routes (all inside the app shell, see App.tsx).
export const PREHLIADKA_NEW_PATH = '/prehliadky/new';
/** Krok 2 — Obchôdzka; also where a prehliadka in progress is continued. */
export const prehliadkaPath = (id: number): string => `/prehliadky/${id}`;
/** Krok 1 of a prehliadka that already exists — „Zmeniť nastavenie". */
export const prehliadkaSetupPath = (id: number): string => `/prehliadky/${id}/nastavenie`;
export const defectNewPath = (id: number): string => `/prehliadky/${id}/nedostatok/new`;
export const defectPath = (id: number, itemId: number): string => `/prehliadky/${id}/nedostatok/${itemId}`;
/** Krok 3 — Termíny a činnosti. */
export const prehliadkaTermsPath = (id: number): string => `/prehliadky/${id}/terminy`;
/** Uzavretie — the summary before the prehliadka is closed. */
export const prehliadkaClosePath = (id: number): string => `/prehliadky/${id}/uzavretie`;
/** What a closed prehliadka produced — documents, book entries (phase 8a). */
export const prehliadkaResultPath = (id: number): string => `/prehliadky/${id}/vysledok`;

/** Start of a prehliadka at a prevádzka; `preset=bozp` is „Kniha kontrol BOZP" (D2). */
export function startPrehliadkaPath(args: {
  companyId: number;
  facilityId: number;
  visitId?: number | null;
  executedOn?: string | null;
  preset?: 'bozp';
}): string {
  const q = new URLSearchParams({
    company_id: String(args.companyId),
    facility_id: String(args.facilityId),
  });
  if (args.visitId) q.set('visit_id', String(args.visitId));
  if (args.visitId && args.executedOn) q.set('executed_on', args.executedOn);
  if (args.preset) q.set('preset', args.preset);
  return `${PREHLIADKA_NEW_PATH}?${q.toString()}`;
}

/** Types a walk-through can name a device of (FacilityDevices::TYPES). Spec order. */
export type DeviceType =
  | 'php'
  | 'hydranty'
  | 'pu_akcieschopnost'
  | 'pu_udrzba'
  | 'nudzove_osvetlenie'
  | 'rebriky'
  | 'regale';

export const DEVICE_TYPES: DeviceType[] = [
  'php',
  'hydranty',
  'pu_akcieschopnost',
  'pu_udrzba',
  'nudzove_osvetlenie',
  'rebriky',
  'regale',
];

/** The „Robím pri tom aj riadne kontroly?" list (spec 6.1.2, prototype `RIADNE`). */
export const ROUTINE_LABELS: Record<DeviceType, string> = {
  php: 'Kontrola hasiacich prístrojov',
  hydranty: 'Kontrola hydrantov',
  pu_akcieschopnost: 'Požiarne uzávery — akcieschopnosť',
  pu_udrzba: 'Požiarne uzávery — údržba',
  nudzove_osvetlenie: 'Núdzové osvetlenie',
  rebriky: 'Rebríky',
  regale: 'Regále',
};

/** Group headings of the device list (prototype `TYPY`). */
export const DEVICE_GROUP_LABELS: Record<DeviceType, string> = {
  php: 'Hasiace prístroje',
  hydranty: 'Hydranty',
  pu_akcieschopnost: 'Požiarne uzávery',
  pu_udrzba: 'Požiarne uzávery — údržba',
  nudzove_osvetlenie: 'Núdzové osvetlenie',
  rebriky: 'Rebríky',
  regale: 'Regále',
};

export type PrehliadkaScope = { po: boolean; bozp: boolean };

/** A device written during the walk that the derived list does not know yet. */
export type NewDevice = {
  tmp_id: string;
  type: DeviceType;
  /** Evidenčné číslo. */
  label: string;
  serial: string | null;
  description: string | null;
  location: string | null;
};

/** What became of a defect of the last prehliadka (spec 6.5). */
export type PreviousStatus = 'ok' | 'trva' | 'zanik';

export const PREVIOUS_STATUS_LABELS: Record<PreviousStatus, string> = {
  ok: 'Odstránené',
  trva: 'Trvá',
  zanik: 'Dôvod zanikol',
};

/** One defect of the last prehliadka and the technician's answer; `status` null = čaká na overenie. */
export type PreviousEntry = {
  key: string;
  kind: 'prehliadka' | 'poziarna_kniha';
  source_inspection_id: number;
  source_item_id: number | null;
  status: PreviousStatus | null;
  reason: string | null;
};

/** A person written on the record: who was present, who took it over. */
export type PrehliadkaPerson = { name: string; role: string | null };

export type PrehliadkaDetails = {
  scope: PrehliadkaScope;
  routine: DeviceType[];
  book_entry: boolean;
  new_devices: NewDevice[];
  /** „Vykonané činnosti" ticked for the PO record / the BOZP record (spec 6.4) — never mixed. */
  activities_po: string[];
  activities_bozp: string[];
  previous: PreviousEntry[];
  /** Hand-written validity over an automatic term, by term key (spec 6.6). */
  term_overrides: Record<string, string>;
  present: PrehliadkaPerson[];
  handed_over: PrehliadkaPerson | null;
};

/** Reads `inspection.details` defensively — it is JSON of whichever version wrote it. */
export function parseDetails(raw: Record<string, unknown> | null | undefined): PrehliadkaDetails {
  const scope = (raw?.scope ?? {}) as Partial<PrehliadkaScope>;
  const routine = Array.isArray(raw?.routine)
    ? (raw!.routine as unknown[]).filter((t): t is DeviceType => DEVICE_TYPES.includes(t as DeviceType))
    : [];
  const newDevices = Array.isArray(raw?.new_devices) ? (raw!.new_devices as NewDevice[]) : [];
  const strings = (v: unknown): string[] =>
    Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string' && x.trim() !== '') : [];
  const person = (v: unknown): PrehliadkaPerson | null => {
    if (!v || typeof v !== 'object') return null;
    const p = v as { name?: unknown; role?: unknown };
    return typeof p.name === 'string' && p.name.trim() !== ''
      ? { name: p.name, role: typeof p.role === 'string' && p.role.trim() !== '' ? p.role : null }
      : null;
  };
  const previous = Array.isArray(raw?.previous)
    ? (raw!.previous as Partial<PreviousEntry>[]).flatMap((e): PreviousEntry[] =>
        typeof e?.key === 'string' && typeof e.source_inspection_id === 'number'
          ? [
              {
                key: e.key,
                kind: e.kind === 'poziarna_kniha' ? 'poziarna_kniha' : 'prehliadka',
                source_inspection_id: e.source_inspection_id,
                source_item_id: typeof e.source_item_id === 'number' ? e.source_item_id : null,
                status: e.status === 'ok' || e.status === 'trva' || e.status === 'zanik' ? e.status : null,
                reason: typeof e.reason === 'string' ? e.reason : null,
              },
            ]
          : [],
      )
    : [];
  // An empty map arrives as `[]` from PHP; anything but a plain object reads as none.
  const rawOverrides = raw?.term_overrides;
  const overrides: Record<string, string> = {};
  if (rawOverrides && typeof rawOverrides === 'object' && !Array.isArray(rawOverrides)) {
    for (const [k, v] of Object.entries(rawOverrides)) if (typeof v === 'string') overrides[k] = v;
  }
  return {
    scope: { po: scope.po === true, bozp: scope.bozp === true },
    routine,
    book_entry: raw?.book_entry === true,
    new_devices: newDevices,
    activities_po: strings(raw?.activities_po),
    activities_bozp: strings(raw?.activities_bozp),
    previous,
    term_overrides: overrides,
    present: Array.isArray(raw?.present)
      ? (raw!.present as unknown[]).flatMap((p) => {
          const parsed = person(p);
          return parsed ? [parsed] : [];
        })
      : [],
    handed_over: person(raw?.handed_over),
  };
}

/** The `details` object as sent to the server. */
export function detailsPayload(d: PrehliadkaDetails): Record<string, unknown> {
  return {
    scope: d.scope,
    routine: d.routine,
    // The checkbox is about the požiarna kniha only (spec 9.5a).
    book_entry: d.scope.po && d.book_entry,
    new_devices: d.new_devices,
    activities_po: d.activities_po,
    activities_bozp: d.activities_bozp,
    previous: d.previous,
    term_overrides: d.term_overrides,
    present: d.present,
    handed_over: d.handed_over,
  };
}

export function scopeLabel(scope: PrehliadkaScope): string {
  if (scope.po && scope.bozp) return 'PO + BOZP';
  return scope.bozp ? 'BOZP' : 'PO';
}

/** Section the defect belongs to: PO hides `bo`, BOZP hides `po` (spec 6.3.2). */
export type DefectScope = 'po' | 'bo' | 'ob';

/** The scope a new defect starts with, before a catalogue item is picked. */
export function defaultDefectScope(scope: PrehliadkaScope): DefectScope {
  return scope.po ? 'po' : 'bo';
}

/** Which defect scopes the „Do ktorého záznamu" switch offers (only the chosen records). */
export function offeredDefectScopes(scope: PrehliadkaScope): { value: DefectScope; label: string }[] {
  const out: { value: DefectScope; label: string }[] = [];
  if (scope.po) out.push({ value: 'po', label: 'Požiarny' });
  if (scope.bozp) out.push({ value: 'bo', label: 'BOZP' });
  if (scope.po && scope.bozp) out.push({ value: 'ob', label: 'Oboje' });
  return out;
}

// ─── Kde ────────────────────────────────────────────────────────────────────

/** Default place tiles of a bytový dom (prototype `MIESTA`). A firma has none of its own. */
export const DEFAULT_PLACES = [
  'chodba',
  'schodisko',
  'medziposchodie',
  'garáž',
  'pivnica',
  'kočikáreň',
  'kotolňa',
  'práčovňa',
  'výťahová šachta',
  'spoločné priestory',
  'pred vchodom',
  'dvor',
];

/**
 * Floor tiles of a bytový dom from what is set on it — not a fixed list
 * (spec 5): `{pp}. PP … 1. PP, 1. NP … {np}. NP, strecha`.
 */
export function floorTiles(below: number | null | undefined, above: number | null | undefined): string[] {
  const np = Math.max(0, Math.min(40, above ?? 0));
  const pp = Math.max(0, Math.min(5, below ?? 0));
  const out: string[] = [];
  for (let i = pp; i >= 1; i -= 1) out.push(`${i}. PP`);
  for (let i = 1; i <= np; i += 1) out.push(`${i}. NP`);
  out.push('strecha');
  return out;
}

export type DefectWhere = {
  entrance: string | null;
  floor: string | null;
  place: string | null;
  detail: string | null;
  /** Composed „Vchod 2 · 3. NP · chodba". */
  text: string;
};

/** The composed place line (prototype `skladajKde`). */
export function composeWhere(w: Pick<DefectWhere, 'entrance' | 'floor' | 'place' | 'detail'>): string {
  return [w.entrance, w.floor, w.place, w.detail]
    .map((p) => (p ?? '').trim())
    .filter((p) => p !== '')
    .join(' · ');
}

/** Case-insensitive union of the tiles on offer with what the technician typed. */
export function withoutDuplicates(values: string[]): string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const v of values) {
    const t = v.trim();
    const key = t.toLowerCase();
    if (t === '' || seen.has(key)) continue;
    seen.add(key);
    out.push(t);
  }
  return out;
}

// ─── Na čom ─────────────────────────────────────────────────────────────────

/** A device as the defect stores it: a snapshot plus the reference it was picked by. */
export type DefectDevice = {
  type: DeviceType;
  source_item_id?: number;
  new_device_tmp_id?: string;
  label: string;
  serial: string | null;
  location: string | null;
};

/** One selectable device of the list in the form (a derived one or a new one). */
export type DeviceOption = {
  /** Stable selection key, `s<source_item_id>` or `n<tmp_id>`. */
  key: string;
  type: DeviceType;
  label: string;
  serial: string | null;
  description: string | null;
  location: string | null;
  source_item_id?: number;
  new_device_tmp_id?: string;
};

export function deviceKeyOf(d: { source_item_id?: number; new_device_tmp_id?: string; type: string; label: string }): string {
  if (d.source_item_id !== undefined) return `s${d.source_item_id}`;
  if (d.new_device_tmp_id !== undefined) return `n${d.new_device_tmp_id}`;
  // A snapshot whose list entry is gone (see Restorer::relinkPrehliadkaDevices).
  return `l${d.type}|${d.label.toLowerCase()}`;
}

export function deviceToRef(o: DeviceOption): DefectDevice {
  const ref: DefectDevice = { type: o.type, label: o.label, serial: o.serial, location: o.location };
  if (o.source_item_id !== undefined) ref.source_item_id = o.source_item_id;
  if (o.new_device_tmp_id !== undefined) ref.new_device_tmp_id = o.new_device_tmp_id;
  return ref;
}

/** Search over serial, label, description and location (spec 6.3.5). */
export function deviceMatches(o: DeviceOption, foldedQuery: string, fold: (s: string) => string): boolean {
  const hay = fold([o.label, o.serial ?? '', o.description ?? '', o.location ?? ''].join(' '));
  return foldedQuery
    .split(/\s+/)
    .filter(Boolean)
    .every((t) => hay.includes(t));
}

/** More than this many devices and the list gets a search field (spec 6.3.5). */
export const DEVICE_SEARCH_THRESHOLD = 8;

/** Minted for a device that does not exist on the server yet. */
export function newTmpId(prefix: string): string {
  return `${prefix}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;
}

// ─── Dokedy ─────────────────────────────────────────────────────────────────

export type DeadlineChoice = 'bezodkladne' | '30d' | '3m' | '6m' | 'custom';

export const DEADLINE_CHOICES: { value: DeadlineChoice; label: string }[] = [
  { value: 'bezodkladne', label: 'bezodkladne' },
  { value: '30d', label: 'do 30 dní' },
  { value: '3m', label: 'do 3 mesiacov' },
  { value: '6m', label: 'do 6 mesiacov' },
  { value: 'custom', label: 'vlastný dátum' },
];

/**
 * The concrete date of a computed choice, counted from the prehliadka date
 * (spec 6.3.6). Months clamp to the end of a shorter month, as on the server.
 */
export function deadlineFor(choice: DeadlineChoice, executedOn: string | null): string | null {
  if (!executedOn) return null;
  switch (choice) {
    case '30d':
      return validUntil(executedOn, { value: 30, unit: 'den' });
    case '3m':
      return validUntil(executedOn, { value: 3, unit: 'mesiac' });
    case '6m':
      return validUntil(executedOn, { value: 6, unit: 'mesiac' });
    default:
      return null;
  }
}

/** The catalogue's lehota text („do 30 dní") as a chip; anything unknown reads as 30 dní. */
export function choiceFromCatalog(text: string | null | undefined): DeadlineChoice {
  const t = (text ?? '').trim().toLowerCase();
  if (t === 'bezodkladne') return 'bezodkladne';
  if (t === 'do 3 mesiacov') return '3m';
  if (t === 'do 6 mesiacov') return '6m';
  return '30d';
}

/**
 * The date a defect's deadline currently resolves to: computed choices follow
 * the prehliadka date (so a queued or cached defect stays right when the date
 * changes), `custom` keeps the technician's own.
 */
export function effectiveDeadline(
  d: Pick<DefectFields, 'deadline_choice' | 'deadline_date'>,
  executedOn: string | null,
): string | null {
  if (d.deadline_choice === 'bezodkladne') return null;
  if (d.deadline_choice === 'custom') return d.deadline_date;
  return deadlineFor(d.deadline_choice, executedOn) ?? d.deadline_date;
}

// ─── The defect row ─────────────────────────────────────────────────────────

/** `inspection_items.fields` of a defect — also the payload of the items API. */
export type DefectFields = {
  catalog_id: number | null;
  title: string;
  scope: DefectScope;
  where: DefectWhere;
  devices: DefectDevice[];
  device_manual: string | null;
  description: string;
  regulation: string;
  justification: string;
  measure: string;
  deadline_choice: DeadlineChoice;
  deadline_date: string | null;
  responsible: string | null;
  group_key: string | null;
  /** Set when the defect was carried over from the last prehliadka with „Trvá" (spec 6.5). */
  persists_from: PersistsFrom | null;
};

export type PersistsFrom = {
  /** The `details.previous` entry this defect answers. */
  key: string;
  inspection_id: number;
  /** The date of the record it came from. */
  executed_on: string | null;
};

/** Reads an item's fields as a defect; null for something that is not one. */
export function parseDefect(fields: Record<string, unknown> | null | undefined): DefectFields | null {
  if (!fields || typeof fields.title !== 'string') return null;
  const where = (fields.where ?? {}) as Partial<DefectWhere>;
  const choice = DEADLINE_CHOICES.some((c) => c.value === fields.deadline_choice)
    ? (fields.deadline_choice as DeadlineChoice)
    : '30d';
  const w = {
    entrance: where.entrance ?? null,
    floor: where.floor ?? null,
    place: where.place ?? null,
    detail: where.detail ?? null,
  };
  return {
    catalog_id: typeof fields.catalog_id === 'number' ? fields.catalog_id : null,
    title: fields.title,
    scope: (['po', 'bo', 'ob'] as const).includes(fields.scope as DefectScope)
      ? (fields.scope as DefectScope)
      : 'po',
    where: { ...w, text: where.text ?? composeWhere(w) },
    devices: Array.isArray(fields.devices) ? (fields.devices as DefectDevice[]) : [],
    device_manual: typeof fields.device_manual === 'string' ? fields.device_manual : null,
    description: typeof fields.description === 'string' ? fields.description : '',
    regulation: typeof fields.regulation === 'string' ? fields.regulation : '',
    justification: typeof fields.justification === 'string' ? fields.justification : '',
    measure: typeof fields.measure === 'string' ? fields.measure : '',
    deadline_choice: choice,
    deadline_date: typeof fields.deadline_date === 'string' ? fields.deadline_date : null,
    responsible: typeof fields.responsible === 'string' ? fields.responsible : null,
    group_key: typeof fields.group_key === 'string' ? fields.group_key : null,
    persists_from: parsePersistsFrom(fields.persists_from),
  };
}

function parsePersistsFrom(raw: unknown): PersistsFrom | null {
  if (!raw || typeof raw !== 'object') return null;
  const p = raw as Partial<PersistsFrom>;
  if (typeof p.key !== 'string' || typeof p.inspection_id !== 'number') return null;
  return {
    key: p.key,
    inspection_id: p.inspection_id,
    executed_on: typeof p.executed_on === 'string' ? p.executed_on : null,
  };
}

/** Names of the devices of a defect as a short line: „PU-04, PU-07 · PHP č. 25-08014". */
export function deviceLine(d: Pick<DefectFields, 'devices' | 'device_manual'>): string {
  const parts = d.devices.map((x) => x.label).filter((l) => l !== '');
  if (d.device_manual) parts.push(d.device_manual);
  return parts.join(', ');
}

// ─── Nedostatky z minulej prehliadky (spec 6.5) ─────────────────────────────

/** A defect of the last prehliadka, as `GET /api/facilities/{id}/prehliadka-previous` returns it. */
export type PreviousDefect = {
  key: string;
  /** From an old požiarna kniha record: no title, place or devices of its own. */
  legacy: boolean;
  title: string;
  scope: DefectScope;
  where: Omit<DefectWhere, 'text'> | null;
  where_text: string;
  devices: DefectDevice[];
  device_manual: string | null;
  description: string;
  regulation: string;
  justification: string;
  measure: string;
  catalog_id: number | null;
  deadline_date: string | null;
  responsible: string | null;
  source_item_id: number;
};

/** Is a defect of this scope part of a prehliadka with this setup? `ob` fits either record. */
export function scopeApplies(defectScope: DefectScope, scope: PrehliadkaScope): boolean {
  if (defectScope === 'ob') return scope.po || scope.bozp;
  return defectScope === 'po' ? scope.po : scope.bozp;
}

/** The scope a carried-over defect gets today: `ob` narrows to the one record being done. */
export function carriedScope(defectScope: DefectScope, scope: PrehliadkaScope): DefectScope {
  if (defectScope !== 'ob') return defectScope;
  return scope.po && scope.bozp ? 'ob' : scope.po ? 'po' : 'bo';
}

// ─── Termíny (spec 6.6) ─────────────────────────────────────────────────────

/** `GET /api/facilities/{id}/prehliadka-terms` — the facts the automatic terms are computed from. */
export type TermTraining = {
  key: string;
  label: string;
  book: 'po' | 'bozp';
  done_on: string;
  valid_until: string | null;
  overridden: boolean;
};

export type RoutinePeriodicities = Record<string, { value: number | null; unit: 'den' | 'tyzden' | 'mesiac' | null }>;

/** One row of „Doplnené samy". `valid_until` null = no recurrence; the override can still give a date. */
export type AutoTerm = {
  key: string;
  label: string;
  book: 'po' | 'bozp';
  /** Where the date comes from, as the prototype words it. */
  source: string;
  valid_until: string | null;
};

export const TERM_KEY_PREHLIADKA_PO = 'prehliadka_po';
export const TERM_KEY_PREHLIADKA_BOZP = 'prehliadka_bozp';
export const routineTermKey = (type: DeviceType): string => `routine:${type}`;
