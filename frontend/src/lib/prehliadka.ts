/**
 * Preventívna prehliadka — the shapes and the small rules of phase 7a
 * (spec 6.1–6.3, plan Phase 7a). The PHP twins are
 * Firol\Support\PrehliadkaDetails and Firol\Support\PrehliadkaDefects.
 *
 * Storage: the prehliadka is an inspection of the type `preventivna_prehliadka`;
 * its setup is `inspections.details`, every defect is one `inspection_items` row.
 */
import { validUntil } from '@/lib/periodicity';
import type { InspectionPhoto } from '@/api/inspections';

export const PREHLIADKA_TYPE = 'preventivna_prehliadka' as const;

// Routes (all inside the app shell, see App.tsx).
export const PREHLIADKA_NEW_PATH = '/prehliadky/new';
/** Krok 2 — Obchôdzka; also where a prehliadka in progress is continued. */
export const prehliadkaPath = (id: number): string => `/prehliadky/${id}`;
/** Krok 1 of a prehliadka that already exists — „Zmeniť nastavenie". */
export const prehliadkaSetupPath = (id: number): string => `/prehliadky/${id}/nastavenie`;
export const defectNewPath = (id: number): string => `/prehliadky/${id}/nedostatok/new`;
export const defectPath = (id: number, itemId: number): string => `/prehliadky/${id}/nedostatok/${itemId}`;
/** The defect screen seeded from a defect of the last prehliadka (spec 12.1). */
export const defectFromPreviousPath = (id: number, previousKey: string): string =>
  `/prehliadky/${id}/nedostatok/new?z=${encodeURIComponent(previousKey)}`;
/** Krok 3 — Termíny a činnosti. */
export const prehliadkaTermsPath = (id: number): string => `/prehliadky/${id}/terminy`;
/** Uzavretie — the summary before the prehliadka is closed. */
export const prehliadkaClosePath = (id: number): string => `/prehliadky/${id}/uzavretie`;
/** What a closed prehliadka produced — its documents (phase 8a). */
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
  rebriky: 'Rebríky (pracovné)',
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

/**
 * One defect of the last prehliadka and the technician's answer; `status` null = čaká na overenie.
 * Written by drafts begun before spec 12.1 only — since then the answer is the
 * carried-over defect row itself (see {@see previousAnswers}).
 */
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

export type LehotaZakon = 12 | 6 | 3;

export type PrehliadkaDetails = {
  scope: PrehliadkaScope;
  routine: DeviceType[];
  lehota_zakon: LehotaZakon;
  lehota_vlastna: number | null;
  new_devices: NewDevice[];
  /** „Vykonané činnosti" ticked for the PO record / the BOZP record (spec 6.4) — never mixed. */
  activities_po: string[];
  activities_bozp: string[];
  previous: PreviousEntry[];
  /** Hand-written validity over an automatic term, by term key (spec 6.6). */
  term_overrides: Record<string, string>;
  note?: string | null;
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
    lehota_zakon: (raw?.lehota_zakon === 3 || raw?.lehota_zakon === 6 || raw?.lehota_zakon === 12) ? raw.lehota_zakon : 12,
    lehota_vlastna: (typeof raw?.lehota_vlastna === 'number' && Number.isInteger(raw.lehota_vlastna) && raw.lehota_vlastna >= 1 && raw.lehota_vlastna <= 120) ? raw.lehota_vlastna : null,
    new_devices: newDevices,
    activities_po: strings(raw?.activities_po),
    activities_bozp: strings(raw?.activities_bozp),
    previous,
    term_overrides: overrides,
    note: typeof raw?.note === 'string' ? raw.note : typeof raw?.poznamka === 'string' ? raw.poznamka : null,
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
    lehota_zakon: d.lehota_zakon,
    lehota_vlastna: d.lehota_vlastna ?? null,
    new_devices: d.new_devices,
    activities_po: d.activities_po,
    activities_bozp: d.activities_bozp,
    previous: d.previous,
    term_overrides: d.term_overrides,
    note: d.note ?? null,
    poznamka: d.note ?? null,
    present: d.present,
    handed_over: d.handed_over,
    oboznameny_meno: d.handed_over?.name ?? null,
    oboznameny_funkcia: d.handed_over?.role ?? null,
  };
}

export function effectivePeriodicityMonths(d?: {
  lehota_zakon?: LehotaZakon | number;
  lehota_vlastna?: number | null;
  periodicity_value?: number | null;
} | null): number {
  if (!d) return 12;
  if (typeof d.lehota_vlastna === 'number' && d.lehota_vlastna > 0) return d.lehota_vlastna;
  if (d.lehota_zakon === 3 || d.lehota_zakon === 6 || d.lehota_zakon === 12) return d.lehota_zakon;
  if (typeof d.periodicity_value === 'number' && d.periodicity_value > 0) return d.periodicity_value;
  return 12;
}

/**
 * Generated entrance tiles for a bytový dom per spec/prototype `vchodyPre` (lines 399-411).
 * For a regular firma, no entrances are generated.
 */
export function vchodyPre(vchody?: string | null, customEntrances: string[] = []): string[] {
  const out: string[] = [];
  const raw = (vchody ?? '').trim();
  if (raw !== '') {
    if (/^\d+$/.test(raw)) {
      const n = Math.max(1, Math.min(40, parseInt(raw, 10)));
      for (let i = 1; i <= n; i++) out.push('Vchod ' + i);
    } else {
      const parts = raw.split(/[,;]+/).map((p) => p.trim()).filter(Boolean);
      for (const p of parts) {
        if (/^\d+[a-zA-Z]?$/.test(p)) {
          out.push('Vchod ' + p);
        } else {
          out.push(p);
        }
      }
    }
  }
  for (const c of customEntrances) {
    const trimmed = (c ?? '').trim();
    if (trimmed !== '' && trimmed !== 'Celý objekt' && !out.includes(trimmed)) {
      out.push(trimmed);
    }
  }
  return out;
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
  if (d.deadline_choice === null || d.deadline_choice === 'bezodkladne') return null;
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
  /** Null only where the host makes the deadline optional (the book, spec 8). */
  deadline_choice: DeadlineChoice | null;
  deadline_date: string | null;
  responsible: string | null;
  group_key: string | null;
  /** „Stav nedostatku" (spec 12.1). Rows written before it read via {@see defectStatus}. */
  status: DefectStatus;
  /** The one sentence of „dôvod zanikol"; null otherwise. */
  reason: string | null;
  /** Set when the defect was taken over from the last prehliadka (spec 6.5, 12.1), whatever its state. */
  persists_from: PersistsFrom | null;
  /** The prevádzka of the defect — only when the host offers several (the book). */
  facility_id?: number;
};

export type PersistsFrom = {
  /** The previous defect this row answers (`PreviousDefect.key`). */
  key: string;
  inspection_id: number;
  /** The date of the record it came from. */
  executed_on: string | null;
  /** That record's document number; absent on rows carried over before spec 12.1. */
  number: string | null;
  /** The item of that record whose photos the server copies onto this row on create. */
  source_item_id: number | null;
};

/** „Stav nedostatku" — the same four choices for a new and a carried-over defect (spec 12.1). */
export type DefectStatus = 'zisteny' | 'odstraneny' | 'trva' | 'zanik';

export const DEFECT_STATUS_OPTIONS: { value: DefectStatus; label: string }[] = [
  { value: 'zisteny', label: 'zistený' },
  { value: 'odstraneny', label: 'odstránený' },
  { value: 'trva', label: 'trvá' },
  { value: 'zanik', label: 'dôvod zanikol' },
];

/** The state a row stands for; one written before the field reads as trvá when carried over, else zistený. */
export function defectStatus(fields: Record<string, unknown> | null | undefined): DefectStatus {
  const s = fields?.status;
  if (s === 'zisteny' || s === 'odstraneny' || s === 'trva' || s === 'zanik') return s;
  return fields?.persists_from && typeof fields.persists_from === 'object' ? 'trva' : 'zisteny';
}

/** Open = still to be dealt with: offered by the next prehliadka, on Dnes, in the nearest deadline. */
export function isOpenDefect(d: Pick<DefectFields, 'status'>): boolean {
  return d.status === 'zisteny' || d.status === 'trva';
}

/** A carried-over defect answered odstránený / dôvod zanikol: it lives in the previous-defect list only. */
export function isClosedCarry(d: Pick<DefectFields, 'status' | 'persists_from'>): boolean {
  return d.persists_from !== null && !isOpenDefect(d);
}

/** Reads an item's fields as a defect; null for something that is not one. */
export function parseDefect(fields: Record<string, unknown> | null | undefined): DefectFields | null {
  if (!fields || typeof fields.title !== 'string') return null;
  const where = (fields.where ?? {}) as Partial<DefectWhere>;
  const choice: DeadlineChoice | null = DEADLINE_CHOICES.some((c) => c.value === fields.deadline_choice)
    ? (fields.deadline_choice as DeadlineChoice)
    : fields.deadline_choice === null && fields.deadline_date == null && 'deadline_choice' in fields
      ? null
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
    status: defectStatus(fields),
    reason: typeof fields.reason === 'string' && fields.reason.trim() !== '' ? fields.reason : null,
    persists_from: parsePersistsFrom(fields.persists_from),
    ...(typeof fields.facility_id === 'number' ? { facility_id: fields.facility_id } : {}),
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
    number: typeof p.number === 'string' && p.number !== '' ? p.number : null,
    source_item_id: typeof p.source_item_id === 'number' ? p.source_item_id : null,
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
  /** The defect's photos in the old record (none for an old book entry). Absent on an older cached answer. */
  photos?: InspectionPhoto[];
};

/** Where the previous defects come from — `number` is its document's, absent on an older cached answer. */
export type PreviousSource = {
  kind: 'prehliadka' | 'poziarna_kniha';
  inspection_id: number;
  executed_on: string | null;
  number?: string | null;
};

/** What this prehliadka answered about one previous defect; `status` null = not decided yet. */
export type PreviousAnswer = {
  status: PreviousStatus | null;
  reason: string | null;
  /** The row that carries it over, when there is one. */
  itemId: number | null;
};

/**
 * The answers to the previous defects, keyed by the previous defect's key — the
 * client twin of PrehliadkaDefects::answers(). A carried-over row (any state)
 * wins over an entry a draft begun before spec 12.1 wrote into `details.previous`;
 * such an old „trvá" counts only while its row exists.
 */
export function previousAnswers(
  legacy: PreviousEntry[],
  rows: { itemId: number; defect: DefectFields }[],
): Map<string, PreviousAnswer> {
  const out = new Map<string, PreviousAnswer>();
  for (const e of legacy) {
    const status = e.status === 'ok' || e.status === 'zanik' ? e.status : null;
    out.set(e.key, { status, reason: status === 'zanik' ? e.reason : null, itemId: null });
  }
  for (const { itemId, defect } of rows) {
    const key = defect.persists_from?.key;
    if (!key) continue;
    const status: PreviousStatus =
      defect.status === 'odstraneny' ? 'ok' : defect.status === 'zanik' ? 'zanik' : 'trva';
    out.set(key, { status, reason: status === 'zanik' ? defect.reason : null, itemId });
  }
  return out;
}

/** The status a previous answer stands for on the defect screen. */
export function statusOfAnswer(s: PreviousStatus): DefectStatus {
  return s === 'ok' ? 'odstraneny' : s === 'zanik' ? 'zanik' : 'trva';
}

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
