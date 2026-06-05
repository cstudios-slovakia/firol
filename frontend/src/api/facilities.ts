import { api, type OptimisticSpec } from '@/lib/api';

export type Facility = {
  id: number;
  name: string;
  /** Combined address for read-only display; reassembled from the parts below. */
  address: string | null;
  street: string | null;
  postal_code: string | null;
  city: string | null;
  contact_person: string | null;
  notes: string | null;
  company_id: number;
  company_name?: string;
};

export type FacilityPayload = {
  name: string;
  street?: string;
  postal_code?: string;
  city?: string;
  contact_person?: string;
  notes?: string;
};

export const Facilities = {
  show: (id: number) => api<{ facility: Facility }>(`/api/facilities/${id}`),
  createUnderCompany: (
    companyId: number,
    body: FacilityPayload,
    csrfToken: string | null,
    optimistic?: OptimisticSpec,
  ) =>
    api<{ facility: Facility }>(`/api/companies/${companyId}/facilities`, {
      method: 'POST',
      body,
      csrfToken,
      optimistic,
    }),
  update: (id: number, body: FacilityPayload, csrfToken: string | null) =>
    api<{ facility: Facility }>(`/api/facilities/${id}`, { method: 'PATCH', body, csrfToken }),
  archive: (id: number, csrfToken: string | null) =>
    api<void>(`/api/facilities/${id}`, { method: 'DELETE', csrfToken }),
};
