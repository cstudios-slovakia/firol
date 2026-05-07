import { api } from '@/lib/api';

/**
 * Inspection types — locked slugs from docs/Firol base document.
 * The frontend uses these everywhere (URL params, form values, API payloads).
 */
export type InspectionType =
  | 'rphp'
  | 'hydranty'
  | 'oprava_ts_rphp'
  | 'poziarna_kniha'
  | 'pu_akcieschopnost'
  | 'pu_udrzba'
  | 'nudzove_osvetlenie'
  | 'ts_hadic';

export const INSPECTION_TYPE_LABELS: Record<InspectionType, string> = {
  rphp: 'Hasiace prístroje (RPHP)',
  hydranty: 'Požiarne hydranty',
  oprava_ts_rphp: 'Oprava, plnenie a TS RPHP',
  poziarna_kniha: 'Požiarna kniha',
  pu_akcieschopnost: 'Požiarne uzávery — akcieschopnosť',
  pu_udrzba: 'Požiarne uzávery — údržba',
  nudzove_osvetlenie: 'Núdzové osvetlenie',
  ts_hadic: 'Tlaková skúška hadíc',
};

export const INSPECTION_TYPE_PERIODICITIES: Record<InspectionType, number[]> = {
  rphp: [12, 24],
  hydranty: [12],
  oprava_ts_rphp: [60],
  poziarna_kniha: [3, 6],
  pu_akcieschopnost: [3],
  pu_udrzba: [12],
  nudzove_osvetlenie: [12],
  ts_hadic: [60],
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
};

export type Inspection = InspectionListItem & {
  updated_at: string;
  company_ico: string | null;
};

export type RphpStatus = 'A' | 'TS' | 'O' | 'V';

export const RPHP_STATUS_LABELS: Record<RphpStatus, string> = {
  A: 'Akcieschopný',
  TS: 'Tlaková skúška',
  O: 'Vyžaduje opravu',
  V: 'Vyradený',
};

export const RPHP_STATUS_TONES: Record<RphpStatus, 'ok' | 'warn' | 'bad' | 'neutral'> = {
  A: 'ok',
  TS: 'warn',
  O: 'bad',
  V: 'neutral',
};

export type RphpItemFields = {
  manufacturer: string;
  type: string;
  serial: string;
  year: number;
  location: string;
  status: RphpStatus;
  notes: string | null;
};

export type InspectionItem = {
  id: number;
  position: number;
  fields: Record<string, unknown>;
  created_at: string;
  updated_at: string;
};

export type InspectionDetail = {
  inspection: Inspection;
  items: InspectionItem[];
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

export const Inspections = {
  list: (filters?: InspectionListFilters) =>
    api<{ items: InspectionListItem[] }>(`/api/inspections${buildQuery(filters)}`),
  show: (id: number) => api<InspectionDetail>(`/api/inspections/${id}`),
  createDraft: (body: InspectionDraftPayload, csrfToken: string | null) =>
    api<InspectionDetail>('/api/inspections', { method: 'POST', body, csrfToken }),
  update: (id: number, body: InspectionUpdatePayload, csrfToken: string | null) =>
    api<{ inspection: Inspection }>(`/api/inspections/${id}`, {
      method: 'PATCH',
      body,
      csrfToken,
    }),
  archive: (id: number, csrfToken: string | null) =>
    api<void>(`/api/inspections/${id}`, { method: 'DELETE', csrfToken }),

  addItem: (inspectionId: number, fields: RphpItemFields, csrfToken: string | null) =>
    api<{ item: InspectionItem }>(`/api/inspections/${inspectionId}/items`, {
      method: 'POST',
      body: fields,
      csrfToken,
    }),
  updateItem: (
    inspectionId: number,
    itemId: number,
    fields: RphpItemFields,
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

  generatePdf: (inspectionId: number, csrfToken: string | null) =>
    api<GeneratePdfResponse>(`/api/inspections/${inspectionId}/generate-pdf`, {
      method: 'POST',
      csrfToken,
    }),
  repeat: (inspectionId: number, csrfToken: string | null) =>
    api<InspectionDetail & { source_id: number }>(
      `/api/inspections/${inspectionId}/repeat`,
      { method: 'POST', csrfToken },
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
