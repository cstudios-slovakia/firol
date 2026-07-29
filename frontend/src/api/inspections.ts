import { api, buildUrl, type OptimisticSpec } from '@/lib/api';

/**
 * Inspection types — locked slugs from docs/Firol base document.
 * The frontend uses these everywhere (URL params, form values, API payloads).
 */
export type InspectionType =
  | 'php'
  | 'hydranty'
  | 'oprava_ts_php'
  | 'poziarna_kniha'
  | 'pu_akcieschopnost'
  | 'pu_udrzba'
  | 'nudzove_osvetlenie'
  | 'ts_hadic'
  | 'pokyn_zatva'
  | 'vyradenie';

export const INSPECTION_TYPE_LABELS: Record<InspectionType, string> = {
  php: 'Hasiace prístroje (PHP)',
  hydranty: 'Požiarne hydranty',
  oprava_ts_php: 'Oprava, plnenie a TS PHP',
  poziarna_kniha: 'Požiarna kniha',
  pu_akcieschopnost: 'Požiarne uzávery — akcieschopnosť',
  pu_udrzba: 'Požiarne uzávery — údržba',
  nudzove_osvetlenie: 'Núdzové osvetlenie',
  ts_hadic: 'Tlaková skúška hadíc',
  pokyn_zatva: 'Pokyn — žatevné práce',
  vyradenie: 'Vyraďovací protokol PHP',
};

export const INSPECTION_TYPE_PERIODICITIES: Record<InspectionType, number[]> = {
  php: [12, 24],
  hydranty: [12],
  oprava_ts_php: [60],
  poziarna_kniha: [3, 6, 12],
  pu_akcieschopnost: [3],
  pu_udrzba: [12],
  nudzove_osvetlenie: [12],
  ts_hadic: [12],
  // Issued once a year before the harvest, so the annual cycle is real.
  pokyn_zatva: [12],
  // One-off document — a disposal doesn't recur.
  vyradenie: [0],
};

export type InspectionStatus = 'draft' | 'finalized';

export type InspectionListItem = {
  id: number;
  type: InspectionType;
  periodicity_months: number;
  executed_on: string | null;
  status: InspectionStatus;
  notes: string | null;
  created_at: string;
  company_id: number;
  company_name: string;
  facility_id: number;
  facility_name: string;
  inspector_user_id: number;
  inspector_name: string;
  // When the executor borrowed a cert (PHP / Oprava) — frozen at PDF time.
  // Display sites should prefer effective_inspector_name when present.
  effective_inspector_user_id: number | null;
  effective_inspector_name: string | null;
  effective_cert_number: string | null;
  // True when a newer inspection exists for the same facility + type. Such a
  // record is treated as "Nahradená" and never flags as overdue — a renewed
  // control (via Opakovať or a fresh manual one) supersedes the previous.
  is_superseded: boolean;
  // Požiarna kniha only: false marks a plain fire-book entry (not a preventive
  // inspection). Such an entry is outside the statutory cycle — it never flags
  // as overdue and does not supersede the previous inspection. Always true for
  // every other inspection type.
  is_preventive_inspection: boolean;
  // Set when this inspection is a follow-up draft the app pre-filled from
  // another inspection (change request 2.1); null otherwise.
  source_inspection_id: number | null;
};

export type Inspection = InspectionListItem & {
  updated_at: string;
  company_ico: string | null;
};

export type PhpStatus = 'A' | 'TS' | 'O' | 'V';

export const PHP_STATUS_LABELS: Record<PhpStatus, string> = {
  A: 'Akcieschopný',
  TS: 'Tlaková skúška',
  O: 'Vyžaduje opravu',
  V: 'Vyradený',
};

export const PHP_STATUS_TONES: Record<PhpStatus, 'ok' | 'warn' | 'bad' | 'neutral'> = {
  A: 'ok',
  TS: 'warn',
  O: 'bad',
  V: 'neutral',
};

export type PhpItemFields = {
  manufacturer: string;
  type: string;
  serial: string;
  year: number;
  location: string;
  status: PhpStatus;
  notes: string | null;
};

export type HydrantTypeKind = 'DN25' | 'DN33' | 'DN52' | 'C52' | 'other';
export const HYDRANT_TYPES: HydrantTypeKind[] = ['DN25', 'DN33', 'DN52', 'C52', 'other'];

export type PassFailResult = 'vyhovuje' | 'nevyhovuje';
export const PASS_FAIL_LABELS: Record<PassFailResult, string> = {
  vyhovuje: 'Vyhovuje',
  nevyhovuje: 'Nevyhovuje',
};

export type HydrantItemFields = {
  type: HydrantTypeKind;
  type_other: string | null;
  location: string;
  hose_count: number;
  hs: number;
  hd: number;
  q: number;
  defects: string | null;
  result: PassFailResult;
};

export type OpravaTsPhpItemFields = {
  manufacturer: string;
  type: string;
  serial: string;
  year: number;
  location: string;
  notes: string | null;
};

export type PkActivity =
  | 'visual_check'
  | 'php_check'
  | 'hydranty_check'
  | 'escape_routes_check'
  | 'pu_check'
  | 'training_initial'
  | 'training_repeated'
  | 'electrical_equipment_check'
  | 'technical_equipment_check'
  | 'electrical_appliances_check'
  | 'documentation_check'
  | 'employee_list_check'
  | 'fire_drill';

export const PK_ACTIVITIES: PkActivity[] = [
  'visual_check', 'php_check', 'hydranty_check', 'escape_routes_check',
  'pu_check', 'training_initial', 'training_repeated',
  'electrical_equipment_check', 'technical_equipment_check',
  'electrical_appliances_check', 'documentation_check',
  'employee_list_check', 'fire_drill',
];

export const PK_ACTIVITY_LABELS: Record<PkActivity, string> = {
  visual_check: 'Vizuálna kontrola priestorov spoločnosti',
  php_check: 'Kontrola stavu, označenia a dostupnosti PHP',
  hydranty_check: 'Kontrola stavu, označenia a dostupnosti požiarnych hydrantov',
  escape_routes_check: 'Kontrola stavu, označenia a voľnosti únikových ciest',
  pu_check: 'Kontrola akcieschopnosti požiarnych uzáverov',
  training_initial: 'Vykonané vstupné školenie z predpisov OPP',
  training_repeated: 'Vykonané opakované školenie vedúcich a ostatných zamestnancov',
  electrical_equipment_check: 'Kontrola stavu používaných elektrických zariadení',
  technical_equipment_check: 'Kontrola stavu používaných technických zariadení',
  electrical_appliances_check: 'Kontrola stavu používaných elektrických spotrebičov',
  documentation_check: 'Kontrola aktuálnosti dokumentácie požiarnej ochrany',
  employee_list_check: 'Kontrola aktuálneho zoznamu zamestnancov a ich školení',
  fire_drill: 'Vykonaný cvičný požiarny poplach',
};

export type PkResult = 'bez_nedostatkov' | 'zistene_nedostatky';
export const PK_RESULT_LABELS: Record<PkResult, string> = {
  bez_nedostatkov: 'Bez zistených nedostatkov',
  zistene_nedostatky: 'Zistené nedostatky',
};

export type PkDefect = {
  description: string;
  deadline: string | null;
  /** Stable identifier photo documentation attaches to. Optional for backward compat with records saved before per-nedostatok photos. */
  key?: string;
};

export type PoziarnaKnihaItemFields = {
  /**
   * True = preventive fire inspection (statutory, advances the cycle).
   * False = plain fire-book entry (e.g. a training note) — no preventive-
   * inspection wording, does not supersede or shift the next-due term.
   * Optional for backward compat with records saved before this split.
   */
  is_preventive?: boolean;
  workspaces: string;
  activities: PkActivity[];
  custom_activities: string[];
  result: PkResult;
  /** Per-defect list with its own deadline. Required when result = zistene_nedostatky. */
  defects: PkDefect[];
  /** Legacy single deadline — kept for reading older records; new writes use `defects`. */
  defect_deadline?: string | null;
  notes: string | null;
};

export type PuKind = 'dvere' | 'okno' | 'klapka';
export const PU_KINDS: PuKind[] = ['dvere', 'okno', 'klapka'];
export const PU_KIND_LABELS: Record<PuKind, string> = {
  dvere: 'Požiarne dvere',
  okno: 'Požiarne okno',
  klapka: 'Požiarna klapka',
};

export type PuAkcieschopnostItemFields = {
  kind: PuKind;
  identifier: string;
  manufacturer: string;
  location: string;
  result: PassFailResult;
  notes: string | null;
};

export type PuUdrzbaItemFields = {
  kind: PuKind;
  identifier: string;
  manufacturer: string;
  location: string;
  maintenance_work: string;
  result: PassFailResult;
  notes: string | null;
};

export type NudzoveOsvetlenieItemFields = {
  evid_number: string;
  floor: string;
  luminaire_type: string;
  manufacturer: string;
  location: string;
  duration_min: number;
  result: PassFailResult;
  notes: string | null;
};

/**
 * Vyraďovací protokol (change request 2.1) — one row per disposed
 * extinguisher. Same identification block as a PHP item plus the reason.
 */
export type VyradenieItemFields = {
  manufacturer: string;
  type: string;
  serial: string;
  year: number;
  location: string | null;
  reason: string;
};

/** One editable block of the Pokyn's instruction text. */
export type PokynSection = { title: string; text: string };

/**
 * Pokyn — žatevné práce (change request 2.3). A single-record document: the
 * harvest year it covers, an optional per-document approver override, and the
 * instruction text itself. The text is stored with the document so a issued
 * protocol keeps saying what it said, even if the default template changes.
 */
export type PokynZatvaItemFields = {
  year: number;
  approver: string | null;
  sections: PokynSection[];
};

export type TsHadicItemFields = {
  hose_type: string;
  location: string;
  manufacturer: string;
  working_pressure: number;
  test_pressure: number;
  length: number;
  year_of_manufacture: number | null;
  result: PassFailResult;
  notes: string | null;
};

/**
 * One photo attached to an inspection item (change request 2.2). `url` and
 * `thumb_url` are API paths — run them through `photoSrc()` before putting
 * them in an <img>, so the production `/api.php?path=` prefix is applied.
 */
export type InspectionPhoto = {
  id: number;
  item_id: number;
  /** Which nedostatok (Požiarna kniha) this photo documents — null for whole-item photos. */
  defect_key: string | null;
  position: number;
  byte_size: number;
  width: number;
  height: number;
  created_at: string;
  url: string;
  thumb_url: string;
  /** Set only on locally-queued photos that haven't reached the server yet. */
  pending?: boolean;
};

export type InspectionItem = {
  id: number;
  position: number;
  fields: Record<string, unknown>;
  /** Absent on older cached payloads — treat as an empty list. */
  photos?: InspectionPhoto[];
  created_at: string;
  updated_at: string;
};

/** A follow-up draft spawned from this inspection (change request 2.1). */
export type FollowUpRef = {
  id: number;
  type: InspectionType;
  status: InspectionStatus;
};

export type InspectionDetail = {
  inspection: Inspection;
  items: InspectionItem[];
  /** Present on show(); follow-up drafts created from this inspection. */
  follow_ups?: FollowUpRef[];
};

export type InspectionDocument = {
  id: number;
  type: InspectionType;
  number: string;
  generated_at: string;
  signed: boolean;
  download_url: string;
};

export type GeneratePdfResponse = {
  document: InspectionDocument & {
    parent_type: 'inspection';
    parent_id: number;
    file_path: string;
    signed_at: string | null;
  };
  stats: { A: number; TS: number; O: number; V: number; total: number };
};

export type InspectionDraftPayload = {
  type: InspectionType;
  periodicity_months: number;
  executed_on: string;
  company_id: number;
  facility_id: number;
  inspector_user_id?: number;
  notes?: string;
};

export type InspectionUpdatePayload = {
  executed_on?: string;
  periodicity_months?: number;
  notes?: string;
};

export type InspectionListFilters = {
  company_id?: number;
  facility_id?: number;
  type?: InspectionType;
};

function buildQuery(filters: InspectionListFilters = {}): string {
  const parts: string[] = [];
  if (filters.company_id) parts.push(`company_id=${filters.company_id}`);
  if (filters.facility_id) parts.push(`facility_id=${filters.facility_id}`);
  if (filters.type) parts.push(`type=${encodeURIComponent(filters.type)}`);
  return parts.length > 0 ? `?${parts.join('&')}` : '';
}

export type SuggestionField = 'manufacturer' | 'type' | 'location';

export const Inspections = {
  list: (filters?: InspectionListFilters) =>
    api<{ items: InspectionListItem[] }>(`/api/inspections${buildQuery(filters)}`),
  show: (id: number) => api<InspectionDetail>(`/api/inspections/${id}`),
  /**
   * Autocomplete values for a repetitive field (manufacturer / type /
   * location), drawn from the account's own history (change request 2.4.1).
   * Pass facilityId for `location` to float that facility's values to the top.
   */
  suggestions: (field: SuggestionField, q: string, facilityId?: number) => {
    const parts = [`field=${field}`, `q=${encodeURIComponent(q)}`];
    if (facilityId) parts.push(`facility_id=${facilityId}`);
    return api<{ suggestions: string[] }>(`/api/inspections/suggestions?${parts.join('&')}`);
  },
  createDraft: (
    body: InspectionDraftPayload,
    csrfToken: string | null,
    optimistic?: OptimisticSpec,
  ) =>
    api<InspectionDetail>('/api/inspections', { method: 'POST', body, csrfToken, optimistic }),
  update: (id: number, body: InspectionUpdatePayload, csrfToken: string | null) =>
    api<{ inspection: Inspection }>(`/api/inspections/${id}`, {
      method: 'PATCH',
      body,
      csrfToken,
    }),
  archive: (id: number, csrfToken: string | null) =>
    api<void>(`/api/inspections/${id}`, { method: 'DELETE', csrfToken }),

  /**
   * Create (or return the existing) pre-filled follow-up draft from this
   * inspection (change request 2.1). `created` is false when an earlier draft
   * from the same source was returned instead of a new one.
   */
  createFollowUp: (id: number, targetType: InspectionType, csrfToken: string | null) =>
    api<{ inspection_id: number; created: boolean }>(`/api/inspections/${id}/follow-up`, {
      method: 'POST',
      body: { target_type: targetType },
      csrfToken,
      requireOnline: true,
    }),

  addItem: (
    inspectionId: number,
    fields:
      | PhpItemFields
      | HydrantItemFields
      | OpravaTsPhpItemFields
      | PoziarnaKnihaItemFields
      | PuAkcieschopnostItemFields
      | PuUdrzbaItemFields
      | NudzoveOsvetlenieItemFields
      | TsHadicItemFields
      | VyradenieItemFields
      | PokynZatvaItemFields,
    csrfToken: string | null,
  ) =>
    api<{ item: InspectionItem }>(`/api/inspections/${inspectionId}/items`, {
      method: 'POST',
      body: fields,
      csrfToken,
    }),
  updateItem: (
    inspectionId: number,
    itemId: number,
    fields:
      | PhpItemFields
      | HydrantItemFields
      | OpravaTsPhpItemFields
      | PoziarnaKnihaItemFields
      | PuAkcieschopnostItemFields
      | PuUdrzbaItemFields
      | NudzoveOsvetlenieItemFields
      | TsHadicItemFields
      | VyradenieItemFields
      | PokynZatvaItemFields,
    csrfToken: string | null,
  ) =>
    api<{ item: InspectionItem }>(`/api/inspections/${inspectionId}/items/${itemId}`, {
      method: 'PATCH',
      body: fields,
      csrfToken,
    }),
  deleteItem: (inspectionId: number, itemId: number, csrfToken: string | null) =>
    api<void>(`/api/inspections/${inspectionId}/items/${itemId}`, {
      method: 'DELETE',
      csrfToken,
    }),

  /**
   * Attach one photo to an item (change request 2.2). One request per photo:
   * a dropped field connection then retries a single shot instead of the whole
   * batch, and each upload queues independently when offline.
   *
   * `itemId` may be a negative temp id when the item itself is still queued —
   * the outbox rewrites the path once the item create syncs.
   */
  addItemPhoto: (
    inspectionId: number,
    itemId: number,
    photo: Blob,
    csrfToken: string | null,
    defectKey?: string | null,
  ) => {
    const form = new FormData();
    form.append('photo', photo, `foto-${Date.now()}.jpg`);
    if (defectKey) form.append('defect_key', defectKey);
    return api<{ photo: InspectionPhoto }>(
      `/api/inspections/${inspectionId}/items/${itemId}/photos`,
      { method: 'POST', body: form, csrfToken, label: 'Fotka k položke' },
    );
  },
  deleteItemPhoto: (
    inspectionId: number,
    itemId: number,
    photoId: number,
    csrfToken: string | null,
  ) =>
    api<void>(`/api/inspections/${inspectionId}/items/${itemId}/photos/${photoId}`, {
      method: 'DELETE',
      csrfToken,
      label: 'Zmazať fotku',
    }),

  /**
   * `includePhotos` drives the "Priložiť fotodokumentáciu" choice (2.2). The
   * server defaults it to true, so omitting it keeps the appendix whenever
   * photos exist.
   */
  generatePdf: (inspectionId: number, csrfToken: string | null, includePhotos?: boolean) =>
    api<GeneratePdfResponse>(`/api/inspections/${inspectionId}/generate-pdf`, {
      method: 'POST',
      body: includePhotos === undefined ? undefined : { include_photos: includePhotos },
      csrfToken,
      requireOnline: true,
    }),
  repeat: (inspectionId: number, csrfToken: string | null) =>
    api<InspectionDetail & { source_id: number }>(
      `/api/inspections/${inspectionId}/repeat`,
      { method: 'POST', csrfToken, requireOnline: true },
    ),
  documents: (inspectionId: number) =>
    api<{ items: InspectionDocument[] }>(`/api/inspections/${inspectionId}/documents`),
};

/**
 * Build the URL the browser should open to fetch a generated PDF. The
 * backend serves it through the same /api proxy so the session cookie
 * comes along automatically.
 */
export function documentDownloadUrl(documentId: number): string {
  const base = import.meta.env.VITE_API_BASE_URL ?? '';
  return `${base}/api/documents/${documentId}/download`;
}

/**
 * Turn a photo's API path into something an <img src> can use. `buildUrl`
 * applies the production `/api.php?path=` prefix and folds the `?size=thumb`
 * query into it correctly.
 */
export function photoSrc(photo: InspectionPhoto, size: 'thumb' | 'full' = 'thumb'): string {
  return buildUrl(size === 'thumb' ? photo.thumb_url : photo.url);
}
