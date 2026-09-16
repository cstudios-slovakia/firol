import { api } from '@/lib/api';
import type { InspectionType } from '@/api/inspections';

/**
 * Návšteva — one trip to a client, several úkony (block 1 / chapter 9).
 *
 * The technician picks the company, the prevádzka and the date once, ticks the
 * úkony they are going to do, and the app walks them through one after another.
 * Each úkon stays a full inspection with its own protocol number and its own
 * next term; the visit is only the thread between them.
 */

export type VisitStatus = 'prebieha' | 'dokoncena';

/** One úkon recorded under a visit, as the visit screen needs it. */
export type VisitInspection = {
  id: number;
  type: InspectionType;
  status: 'draft' | 'finalized';
  executed_on: string | null;
  item_count: number;
  valid_until: string | null;
  document_id: number | null;
  document_number: string | null;
};

export type Visit = {
  id: number;
  company_id: number;
  company_name: string;
  facility_id: number;
  facility_name: string;
  visit_date: string;
  technician_user_id: number;
  technician_name: string;
  /** The types ticked at the start. A plan, not a contract — it can change. */
  planned_types: InspectionType[];
  status: VisitStatus;
  created_at: string;
  inspections: VisitInspection[];
};

export type VisitPayload = {
  company_id: number;
  facility_id: number;
  visit_date: string;
  planned_types: InspectionType[];
  technician_user_id?: number;
};

/** A protocol issued by "Generovať všetky protokoly". */
export type GeneratedDocument = {
  id: number;
  number: string;
  download_url: string;
};

/** An úkon the bulk generation had to skip, and why. */
export type SkippedInspection = {
  inspection_id: number;
  type: InspectionType;
  reason: string;
};

export const Visits = {
  list: (companyId?: number) =>
    api<{ items: Visit[] }>(
      `/api/visits${companyId ? `?company_id=${companyId}` : ''}`,
    ),
  show: (id: number) => api<{ visit: Visit }>(`/api/visits/${id}`),
  create: (body: VisitPayload, csrfToken: string | null) =>
    api<{ visit: Visit }>('/api/visits', { method: 'POST', body, csrfToken }),
  update: (
    id: number,
    body: Partial<Pick<VisitPayload, 'planned_types' | 'visit_date'>> & { status?: VisitStatus },
    csrfToken: string | null,
  ) => api<{ visit: Visit }>(`/api/visits/${id}`, { method: 'PATCH', body, csrfToken }),
  archive: (id: number, csrfToken: string | null) =>
    api<void>(`/api/visits/${id}`, { method: 'DELETE', csrfToken }),

  /**
   * Issue the protocol of every finished úkon that has none yet. One úkon that
   * is not ready (no date, no items) is reported in `skipped` and does not stop
   * the others — four protocols should not hinge on the fourth being perfect.
   */
  generateDocuments: (id: number, csrfToken: string | null) =>
    api<{ generated: GeneratedDocument[]; skipped: SkippedInspection[]; visit: Visit }>(
      `/api/visits/${id}/generate-documents`,
      { method: 'POST', csrfToken, requireOnline: true },
    ),
};
