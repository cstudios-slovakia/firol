import { api, type OptimisticSpec } from '@/lib/api';
import type { PeriodicityUnit } from '@/lib/periodicity';
import type { CompanyBillingMode } from '@/api/invoicing';

export type CompanyListItem = {
  id: number;
  name: string;
  ico: string | null;
  /** Combined address for read-only display; reassembled from the parts below. */
  address: string | null;
  street: string | null;
  postal_code: string | null;
  city: string | null;
  contact: string | null;
  /** Recipient of the calendar's client notice e-mail (2.5.4). Optional. */
  contact_email: string | null;
  /** Schvaľujúca osoba — name and role, printed on documents (2.3 / 2.1). */
  approver: string | null;
  /** Chapter 22 — the režim a new úkon for this firm starts with. */
  billing_mode: CompanyBillingMode;
  facilities_count: number;
  inspections_count: number;
  /** Spec 25 — set while the firm is archived (listed only with `archived: true`); absent on an offline-created row. */
  archived_at?: string | null;
  archived_reason?: string | null;
  last_inspection_at: string | null;
  /** Present only for system admins — identifies which account owns the company. */
  account_id?: number;
  account_name?: string;
};

export type Company = {
  id: number;
  name: string;
  ico: string | null;
  /** Combined address for read-only display; reassembled from the parts below. */
  address: string | null;
  street: string | null;
  postal_code: string | null;
  city: string | null;
  contact: string | null;
  /** Recipient of the calendar's client notice e-mail (2.5.4). Optional. */
  contact_email: string | null;
  /** Schvaľujúca osoba — name and role, printed on documents (2.3 / 2.1). */
  approver: string | null;
  /** Chapter 22 — the režim a new úkon for this firm starts with. */
  billing_mode: CompanyBillingMode;
  /** Spec 25 — set while the firm is archived; it then opens read-only. */
  archived_at?: string | null;
  archived_reason?: string | null;
  created_at?: string;
};

/** Spec 25 — a prevádzka archived on its own while its firm stays active. */
export type ArchivedFacilityItem = {
  id: number;
  name: string;
  address: string | null;
  archived_at: string;
  archived_reason?: string | null;
};

export type FacilityListItem = {
  id: number;
  name: string;
  /** Combined address for read-only display; reassembled from the parts below. */
  address: string | null;
  street: string | null;
  postal_code: string | null;
  city: string | null;
  contact_person: string | null;
  notes: string | null;
  /**
   * Most recent periodicity used per inspection type at this prevádzka,
   * derived from history. Step 1 prefills from it, because what was chosen
   * here last time beats the catalogue's recommendation. Carries the unit too:
   * since block 1 a period can be days or weeks, not only months.
   */
  last_periodicities: Record<string, { value: number | null; unit: PeriodicityUnit | null }>;
  /**
   * The same for trainings, keyed by term chain (`trainingChain`: Vstupné and
   * Opakované share one). Optional — a response cached before it existed lacks it.
   */
  last_training_periodicities?: Record<string, { value: number | null; unit: PeriodicityUnit | null }>;
};

/** One person at the client entitled to sign a protocol (chapter 13.2). */
export type CompanyPerson = {
  id: number;
  /** Pinned to one prevádzka, or null when valid for the whole company. */
  facility_id: number | null;
  fullname: string;
  role_title: string;
  email: string | null;
  is_default: boolean;
};

export type CompanyPersonPayload = {
  fullname?: string;
  role_title?: string;
  email?: string | null;
  facility_id?: number | null;
  is_default?: boolean;
};

export type CompanyDetail = {
  company: Company;
  /** Active prevádzky only — the ones a new úkon may be started at. */
  facilities: FacilityListItem[];
  /** Optional — a response cached before spec 25 lacks it. */
  archived_facilities?: ArchivedFacilityItem[];
  /** Last-used training periodicity per chain for trainings of the whole firm (no prevádzka). */
  company_last_training_periodicities?: Record<string, { value: number | null; unit: PeriodicityUnit | null }>;
};

export type CompanyPayload = {
  name: string;
  ico?: string;
  street?: string;
  postal_code?: string;
  city?: string;
  contact?: string;
  contact_email?: string;
  approver?: string;
  /** Chapter 22 — omitted keeps the current setting (default na faktúru). */
  billing_mode?: CompanyBillingMode;
};

export const Companies = {
  /** `archived` adds the archived firms (the „aj archivované" filter, spec 25). */
  list: (search?: string, opts?: { archived?: boolean }) => {
    const params = new URLSearchParams();
    if (search) params.set('search', search);
    if (opts?.archived) params.set('archived', '1');
    const qs = params.toString();
    return api<{ items: CompanyListItem[] }>(`/api/companies${qs ? `?${qs}` : ''}`);
  },
  show: (id: number) => api<CompanyDetail>(`/api/companies/${id}`),
  create: (body: CompanyPayload, csrfToken: string | null, optimistic?: OptimisticSpec) =>
    api<{ company: Company }>('/api/companies', { method: 'POST', body, csrfToken, optimistic }),
  update: (id: number, body: CompanyPayload, csrfToken: string | null) =>
    api<{ company: Company }>(`/api/companies/${id}`, { method: 'PATCH', body, csrfToken }),
  /**
   * Spec 25 — a firm is archived, never deleted (its protocols must be kept).
   * Needs the server: it closes the firm's open úlohy as well.
   */
  archive: (id: number, reason: string | null, csrfToken: string | null) =>
    api<void>(`/api/companies/${id}/archive`, {
      method: 'POST',
      body: { reason },
      csrfToken,
      requireOnline: true,
    }),
  restore: (id: number, csrfToken: string | null) =>
    api<void>(`/api/companies/${id}/restore`, { method: 'POST', csrfToken, requireOnline: true }),

  /**
   * People entitled to sign this company's protocols (chapter 13.2). Pass
   * `facilityId` to get the ones valid at that prevádzka plus the
   * company-wide ones — which is what the signature picker needs.
   */
  persons: (id: number, facilityId?: number) => {
    const qs = facilityId ? `?facility_id=${facilityId}` : '';
    return api<{ items: CompanyPerson[]; role_suggestions: string[] }>(
      `/api/companies/${id}/persons${qs}`,
    );
  },
  createPerson: (id: number, body: CompanyPersonPayload, csrfToken: string | null) =>
    api<{ person: CompanyPerson }>(`/api/companies/${id}/persons`, {
      method: 'POST',
      body,
      csrfToken,
    }),
  updatePerson: (
    id: number,
    personId: number,
    body: CompanyPersonPayload,
    csrfToken: string | null,
  ) =>
    api<{ person: CompanyPerson }>(`/api/companies/${id}/persons/${personId}`, {
      method: 'PATCH',
      body,
      csrfToken,
    }),
  deletePerson: (id: number, personId: number, csrfToken: string | null) =>
    api<void>(`/api/companies/${id}/persons/${personId}`, { method: 'DELETE', csrfToken }),
};
