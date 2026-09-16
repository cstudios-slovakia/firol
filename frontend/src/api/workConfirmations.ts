import { api } from '@/lib/api';

/**
 * Potvrdenie o vykonaní práce — block 1 / chapter 10.
 *
 * Not a professional document. A protocol is for the client and for the
 * inspection; this one is for the technician's own employer, who wants to know
 * where their technician was and how much they got through — not what they
 * found. It therefore lists protocol NUMBERS and no findings at all.
 *
 * It normally comes out of a whole visit: four úkony, one sheet of paper.
 */

export type WorkConfirmation = {
  id: number;
  company_id: number;
  company_name: string;
  facility_id: number | null;
  facility_name: string | null;
  visit_id: number | null;
  confirmed_on: string;
  /** Optional — only an employer tracking hours needs it (chapter 10). */
  time_from: string | null;
  time_to: string | null;
  technician_user_id: number;
  technician_name: string;
  inspection_ids: number[];
  created_at: string;
  document_id: number | null;
  document_number: string | null;
};

export type WorkConfirmationPayload = {
  /** From a visit… */
  visit_id?: number;
  /** …or from a company and a day, which is the "spätne z histórie" case. */
  company_id?: number;
  facility_id?: number;
  confirmed_on?: string;
  /** Or from an explicit list of úkony. */
  inspection_ids?: number[];
  time_from?: string | null;
  time_to?: string | null;
  technician_user_id?: number;
};

export const WorkConfirmations = {
  list: (companyId?: number) =>
    api<{ items: WorkConfirmation[] }>(
      `/api/work-confirmations${companyId ? `?company_id=${companyId}` : ''}`,
    ),
  /** Creates the record and issues its PDF in one step — there is nothing to
   *  edit on it afterwards, it only restates work that is already recorded. */
  create: (body: WorkConfirmationPayload, csrfToken: string | null) =>
    api<{
      confirmation: WorkConfirmation;
      document: { id: number; number: string; download_url: string };
    }>('/api/work-confirmations', {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),
};
