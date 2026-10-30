import { api, type OptimisticSpec } from '@/lib/api';
import type { ClientType } from '@/lib/clientKind';
import type { InspectionType } from '@/api/inspections';

export type Facility = {
  id: number;
  name: string;
  /** Combined address for read-only display; reassembled from the parts below. */
  address: string | null;
  street: string | null;
  postal_code: string | null;
  city: string | null;
  /** E-mail of this prevádzka. Wins over the firm's when sending (spec 3.2). */
  email?: string | null;
  contact_person: string | null;
  notes: string | null;
  main_power_location?: string | null;
  main_gas_location?: string | null;
  main_water_location?: string | null;
  description?: string | null;
  floors_above?: number | null;
  floors_below?: number | null;
  /** Remembered by the walk-through; not edited on the form. */
  entrances?: string[];
  places?: string[];
  company_id: number;
  company_name?: string;
  company_client_type?: ClientType;
  company_contact_email?: string | null;
  company_contact?: string | null;
  /** Spec 3.2 — the address a send from here is prefilled with. */
  recipient_email?: string | null;
  /** Spec 25 — set while the prevádzka is archived; it then opens read-only. */
  archived_at?: string | null;
  archived_reason?: string | null;
  /** Set while the whole firm is archived. */
  company_archived_at?: string | null;
};

export type FacilityPayload = {
  name: string;
  street?: string;
  postal_code?: string;
  city?: string;
  email?: string;
  contact_person?: string;
  notes?: string;
  main_power_location?: string;
  main_gas_location?: string;
  main_water_location?: string;
  description?: string;
  floors_above?: number | null;
  floors_below?: number | null;
};

/** One device derived from the latest finalized úkon of its type (spec 3.3). */
export type FacilityDevice = {
  source_item_id: number;
  label: string;
  serial: string | null;
  description: string | null;
  location: string | null;
};

export type FacilityDeviceGroup = {
  type: Extract<
    InspectionType,
    'php' | 'hydranty' | 'pu_akcieschopnost' | 'pu_udrzba' | 'nudzove_osvetlenie' | 'rebriky' | 'regale'
  >;
  items: FacilityDevice[];
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
  /** Spec 25 — archived, never deleted; closes the prevádzka's open úlohy. */
  archive: (id: number, reason: string | null, csrfToken: string | null) =>
    api<void>(`/api/facilities/${id}/archive`, {
      method: 'POST',
      body: { reason },
      csrfToken,
      requireOnline: true,
    }),
  restore: (id: number, csrfToken: string | null) =>
    api<void>(`/api/facilities/${id}/restore`, { method: 'POST', csrfToken, requireOnline: true }),
  /** Read-only list derived from the last finalized controls. Cached like other reads. */
  devices: (id: number) => api<{ groups: FacilityDeviceGroup[] }>(`/api/facilities/${id}/devices`),
};
