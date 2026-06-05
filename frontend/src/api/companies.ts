import { api, type OptimisticSpec } from '@/lib/api';

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
   * Most recent periodicity used per inspection type for this facility,
   * derived from history. Step 1 uses this to prefill the periodicity
   * dropdown for types where it's selectable (PHP, požiarna kniha).
   */
  last_periodicities: Record<string, number>;
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
};
