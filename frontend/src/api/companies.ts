import { api, type OptimisticSpec } from '@/lib/api';
import type { PeriodicityUnit } from '@/lib/periodicity';

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
  facilities_count: number;
  inspections_count: number;
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
  created_at?: string;
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
  facilities: FacilityListItem[];
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
};

export const Companies = {
  list: (search?: string) => {
    const qs = search ? `?search=${encodeURIComponent(search)}` : '';
    return api<{ items: CompanyListItem[] }>(`/api/companies${qs}`);
  },
  show: (id: number) => api<CompanyDetail>(`/api/companies/${id}`),
  create: (body: CompanyPayload, csrfToken: string | null, optimistic?: OptimisticSpec) =>
    api<{ company: Company }>('/api/companies', { method: 'POST', body, csrfToken, optimistic }),
  update: (id: number, body: CompanyPayload, csrfToken: string | null) =>
    api<{ company: Company }>(`/api/companies/${id}`, { method: 'PATCH', body, csrfToken }),
  archive: (id: number, csrfToken: string | null) =>
    api<void>(`/api/companies/${id}`, { method: 'DELETE', csrfToken }),

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
