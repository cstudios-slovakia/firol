import { api, buildUrl } from '@/lib/api';

// ─── Form payload (stored as JSON on the record; shape owned by the wizard) ──

export type DocOsoba = { meno: string; funkcia: string };
export type DocKontakt = { meno: string; funkcia: string; telefon: string };
export type DocObjekt = {
  nazov_objektu: string;
  vztah: string;
  ma_zvysene: boolean;
  jednoducha_evakuacia: boolean;
};
export type DocMiesto = { subjekt_miesta: string; objekt_miesta: string; nazov_miesta: string };
export type DocCustomItem = { nazov: string };

export type DocumentationData = {
  // Predvyplnené (§5.1) — editable snapshot
  firma_nazov?: string;
  firma_ico?: string;
  firma_sidlo?: string;
  prevadzka_nazov?: string;
  prevadzka_adresa?: string;
  mesto?: string;
  // Konateľ a kontakty (§5.2)
  konatel_meno?: string;
  konatel_priezvisko?: string;
  konatel_funkcia?: string;
  konatel_tel?: string;
  ohlasovna_same_as_konatel?: boolean;
  ohlasovna_tel?: string;
  vodarne_tel?: string;
  mimopracovny_sposob?: string;
  zodpovedna_osoba?: string;
  kniha_objekty?: string;
  kniha_ulozena_osoba?: string;
  // Opakovacie zoznamy (§5.3)
  osoby_zapisy?: DocOsoba[];
  dalsie_kontakty?: DocKontakt[];
  objekty?: DocObjekt[];
  miesta?: DocMiesto[];
  // Prepínače (§5.4) — ma_zvysene_nebezpecenstvo is derived from miesta
  ma_evakuacny_plan?: boolean;
  // Vlastné položky zoznamu na titulke (§4.1)
  custom_zoznam?: DocCustomItem[];
};

export type DocumentationStatus = 'draft' | 'finalized';

export type DocumentationListItem = {
  id: number;
  title: string | null;
  issued_on: string | null;
  status: DocumentationStatus;
  created_at: string;
  updated_at: string;
  company_id: number;
  company_name: string;
  facility_id: number | null;
  facility_name: string | null;
  author_user_id: number | null;
  author_name: string | null;
  documents_count: number;
};

export type Documentation = {
  id: number;
  company_id: number;
  company_name: string;
  company_ico: string | null;
  facility_id: number | null;
  facility_name: string | null;
  author_user_id: number | null;
  author_name: string | null;
  title: string | null;
  issued_on: string | null;
  status: DocumentationStatus;
  data: DocumentationData;
  created_at: string;
  updated_at: string;
};

export type DocumentationDocument = {
  id: number;
  type: string;
  number: string;
  generated_at: string;
  signed: boolean;
  download_url: string;
  docx_download_url: string | null;
};

export type DocumentationCreatePayload = {
  company_id: number;
  facility_id?: number | null;
  issued_on?: string | null;
  title?: string | null;
  data?: DocumentationData;
};

export type DocumentationUpdatePayload = {
  facility_id?: number | null;
  issued_on?: string | null;
  title?: string | null;
  data?: DocumentationData;
};

export type DocumentationListFilters = {
  company_id?: number;
  facility_id?: number;
};

function buildQuery(filters: DocumentationListFilters = {}): string {
  const parts: string[] = [];
  if (filters.company_id) parts.push(`company_id=${filters.company_id}`);
  if (filters.facility_id) parts.push(`facility_id=${filters.facility_id}`);
  return parts.length > 0 ? `?${parts.join('&')}` : '';
}

export const Documentations = {
  list: (filters?: DocumentationListFilters) =>
    api<{ items: DocumentationListItem[] }>(`/api/documentations${buildQuery(filters)}`),
  show: (id: number) => api<{ documentation: Documentation }>(`/api/documentations/${id}`),
  create: (body: DocumentationCreatePayload, csrfToken: string | null) =>
    api<{ documentation: Documentation }>('/api/documentations', {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),
  update: (id: number, body: DocumentationUpdatePayload, csrfToken: string | null) =>
    api<{ documentation: Documentation }>(`/api/documentations/${id}`, {
      method: 'PATCH',
      body,
      csrfToken,
    }),
  archive: (id: number, csrfToken: string | null) =>
    api<void>(`/api/documentations/${id}`, { method: 'DELETE', csrfToken }),
  generate: (id: number, csrfToken: string | null) =>
    api<{ document: DocumentationDocument }>(`/api/documentations/${id}/generate`, {
      method: 'POST',
      csrfToken,
      requireOnline: true,
    }),
  documents: (id: number) =>
    api<{ items: DocumentationDocument[] }>(`/api/documentations/${id}/documents`),
};

// ─── Module settings (§9) ────────────────────────────────────────────────────

export type WaterUtility = { region: string; phone: string };

export type DocumentationSettingsData = {
  signer_functions: string[];
  water_utilities: WaterUtility[];
};

export const DocumentationSettings = {
  show: () => api<DocumentationSettingsData>('/api/documentation-settings'),
  update: (body: Partial<DocumentationSettingsData>, csrfToken: string | null) =>
    api<DocumentationSettingsData>('/api/documentation-settings', {
      method: 'PATCH',
      body,
      csrfToken,
    }),
};

export function documentationDocDownloadUrl(documentId: number, format?: 'pdf' | 'docx'): string {
  const suffix = format === 'docx' ? '?format=docx' : '';
  return buildUrl(`/api/documents/${documentId}/download${suffix}`);
}
