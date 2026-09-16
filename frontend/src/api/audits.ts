import { api } from '@/lib/api';
import type { InspectionItem, InspectionType } from '@/api/inspections';

/**
 * Audits — block 3, chapters 15 to 17.
 *
 * An audit is an inspection of type `audit_bozp` / `audit_opp`; everything
 * about dates, periodicity, protocols and signing goes through the normal
 * inspection endpoints. What lives here is what is particular to an audit: the
 * checklists it is built from, the bulk shortcuts that make 109 items
 * workable, and the two kinds of taking over — from a separate protocol
 * (chapter 16) and from last year's audit (chapter 15.4).
 */

export type AuditKind = 'bozp' | 'opp';

/**
 * Which questions the audit asks. An entry audit asks the one-off ones (the
 * building permit, the site notification); a yearly review asks the recurring
 * ones (was last year's finding dealt with, was there a fire drill).
 */
export type AuditScope = 'vstupny' | 'rocny';

export const AUDIT_SCOPE_LABELS: Record<AuditScope, string> = {
  vstupny: 'Vstupný audit',
  rocny: 'Ročná previerka',
};

export const AUDIT_KIND_LABELS: Record<AuditKind, string> = {
  bozp: 'Audit BOZP',
  opp: 'Audit ochrany pred požiarmi',
};

export const AUDIT_KIND_FOR_TYPE: Partial<Record<InspectionType, AuditKind>> = {
  audit_bozp: 'bozp',
  audit_opp: 'opp',
};

export type AuditResult = 'vyhovuje' | 'nevyhovuje' | 'neaplikovatelne';

export const AUDIT_RESULT_LABELS: Record<AuditResult, string> = {
  vyhovuje: 'Vyhovuje',
  nevyhovuje: 'Nevyhovuje',
  neaplikovatelne: 'Neaplikovateľné',
};

/** Where a checklist item shows up: entry audit, yearly review, or both. */
export type AuditItemScope = 'V' | 'R' | 'VR';

export const AUDIT_ITEM_SCOPE_LABELS: Record<AuditItemScope, string> = {
  V: 'Len vstupný audit',
  R: 'Len ročná previerka',
  VR: 'Vstupný audit aj ročná previerka',
};

/** The fields an audit item carries — the question, then the answer. */
export type AuditItemFields = {
  section_code: string;
  section_name: string;
  section_position: number;
  /** The whole section was taken out of this audit (chapter 15.3). */
  section_excluded: boolean;
  text: string;
  legal_basis: string | null;
  scope: AuditItemScope;
  /** Type of úkon that covers the same ground, chapter 16. Null on most items. */
  linked_type: string | null;
  is_custom: boolean;
  result: AuditResult | null;
  note: string | null;
  /** Filled only when the result is `nevyhovuje`; the description is required. */
  defect_description: string | null;
  measure: string | null;
  deadline: string | null;
  /** Set when the answer was taken over from a separate protocol. */
  taken_from: { type: string; document_number: string | null; executed_on: string | null } | null;
};

export type AuditItem = Omit<InspectionItem, 'fields'> & { fields: AuditItemFields };

export type AuditSectionSummary = {
  code: string;
  name: string;
  position: number;
  excluded: boolean;
  vyhovuje: number;
  nevyhovuje: number;
  neaplikovatelne: number;
  unanswered: number;
  total: number;
  status: 'v_poriadku' | 'nedostatok';
};

export type AuditDefect = {
  number: number;
  item_id: number;
  section: string;
  text: string;
  description: string;
  measure: string | null;
  deadline: string | null;
};

export type AuditSummary = {
  sections: AuditSectionSummary[];
  defects: AuditDefect[];
  /** „Vyplnené 84 zo 109" — the two numbers the technician watches. */
  answered: number;
  total: number;
  vyhovuje: number;
  nevyhovuje: number;
  verdict: 'vyhovujuci' | 'vyhovujuci_s_vyhradami';
};

/**
 * Chapter 16 state of one linked type.
 *
 * `same_day` is the only one the app acts on by itself; `offer` waits for the
 * technician to accept, and the rest only inform.
 */
export type LinkedWorkState = 'same_day' | 'offer' | 'expired' | 'never' | 'no_period';

export type LinkedWork = {
  type: string;
  label: string;
  state: LinkedWorkState;
  found: {
    inspection_id: number | null;
    training_id?: number;
    executed_on: string | null;
    document_number: string | null;
    /** Always shown beside the date — "a month ago" means different things at different periods. */
    periodicity_label: string | null;
    valid_until: string | null;
  } | null;
};

/** What last year's audit found on the same question (chapter 15.4). */
export type PreviousFinding = {
  description: string | null;
  measure: string | null;
  deadline: string | null;
};

export type PreviousAudit = {
  audit_id: number;
  executed_on: string | null;
  /** Keyed by the item id of THIS audit. */
  findings: Record<number, PreviousFinding>;
};

export type AuditView = {
  audit: {
    id: number;
    type: InspectionType;
    kind: AuditKind;
    scope: AuditScope | null;
    template_id: number | null;
    executed_on: string | null;
    status: 'draft' | 'finalized';
    visit_id: number | null;
  };
  items: AuditItem[];
  summary: AuditSummary;
  linked: Record<string, LinkedWork>;
  previous: PreviousAudit | null;
};

export type AuditCarryOverOffer = {
  source_id: number;
  executed_on: string | null;
  not_applicable: number;
  excluded_sections: number;
  custom_items: number;
  /** Not carried over — shown so the technician knows what to re-verify. */
  previous_defects: number;
};

export type AuditBulkAction =
  | 'mark_all_ok'
  | 'mark_section_ok'
  | 'exclude_section'
  | 'include_section'
  | 'clear_section';

export type AuditMutationResult = {
  items: AuditItem[];
  summary: AuditSummary;
};

// --- checklists (chapter 17) -------------------------------------------------

export type AuditTemplateItem = {
  id: number;
  text: string;
  legal_basis: string | null;
  scope: AuditItemScope;
  linked_type: string | null;
  position: number;
  is_custom: boolean;
};

export type AuditTemplateSection = {
  id: number;
  code: string;
  name: string;
  position: number;
  items: AuditTemplateItem[];
};

export type AuditTemplate = {
  id: number;
  kind: AuditKind;
  name: string;
  /** False for the two delivered with the app — those can be restored. */
  is_custom: boolean;
  source_key: string | null;
  item_count: number;
  created_at: string;
  updated_at: string;
  sections: AuditTemplateSection[];
};

/** What a PATCH on an audit item carries: the answer, never the question. */
export type AuditAnswer = {
  result: AuditResult | null;
  note?: string | null;
  defect_description?: string | null;
  measure?: string | null;
  deadline?: string | null;
};

/** An item the technician adds to a running audit. */
export type AuditCustomItem = {
  section_code: string;
  section_name: string;
  section_position?: number;
  text: string;
  legal_basis?: string | null;
  scope?: AuditItemScope;
};

export const Audits = {
  show: (inspectionId: number) => api<AuditView>(`/api/inspections/${inspectionId}/audit`),

  /**
   * Answer one item. Goes through the ordinary item endpoint, which means it
   * rides the offline outbox like every other item edit — an audit is filled
   * in exactly where there is no signal.
   */
  answer: (inspectionId: number, itemId: number, body: AuditAnswer, csrfToken: string | null) =>
    api<{ item: AuditItem }>(`/api/inspections/${inspectionId}/items/${itemId}`, {
      method: 'PATCH',
      body,
      csrfToken,
      label: 'Hodnotenie položky auditu',
    }),

  /** Add a question of one's own to this audit (not to the checklist). */
  addItem: (inspectionId: number, body: AuditCustomItem, csrfToken: string | null) =>
    api<{ item: AuditItem }>(`/api/inspections/${inspectionId}/items`, {
      method: 'POST',
      body,
      csrfToken,
    }),

  removeItem: (inspectionId: number, itemId: number, csrfToken: string | null) =>
    api<void>(`/api/inspections/${inspectionId}/items/${itemId}`, { method: 'DELETE', csrfToken }),

  bulk: (
    inspectionId: number,
    action: AuditBulkAction,
    csrfToken: string | null,
    sectionCode?: string,
  ) =>
    api<AuditMutationResult & { changed: number }>(`/api/inspections/${inspectionId}/audit/bulk`, {
      method: 'POST',
      body: { action, section_code: sectionCode },
      csrfToken,
    }),

  /**
   * Chapter 16. With `itemIds` the technician accepted specific offers; without
   * them only the links whose úkon is from today or this visit are applied, and
   * only to items nobody has answered.
   */
  takeOver: (inspectionId: number, csrfToken: string | null, itemIds?: number[]) =>
    api<AuditMutationResult & { applied: number[] }>(
      `/api/inspections/${inspectionId}/audit/take-over`,
      { method: 'POST', body: itemIds ? { item_ids: itemIds } : {}, csrfToken },
    ),

  /** Chapter 15.4 — exclusions and own items from last year; never a result. */
  carryOver: (inspectionId: number, csrfToken: string | null, previousId?: number) =>
    api<AuditMutationResult & { carried: number; added: number; previous: PreviousAudit | null }>(
      `/api/inspections/${inspectionId}/audit/carry-over`,
      {
        method: 'POST',
        body: previousId === undefined ? {} : { previous_id: previousId },
        csrfToken,
        requireOnline: true,
      },
    ),
};

export const AuditTemplates = {
  list: (kind?: AuditKind) =>
    api<{ items: AuditTemplate[] }>(`/api/audit-templates${kind ? `?kind=${kind}` : ''}`),
  show: (id: number) => api<{ template: AuditTemplate }>(`/api/audit-templates/${id}`),
  create: (
    body: { kind: AuditKind; name: string; copy_from?: number },
    csrfToken: string | null,
  ) =>
    api<{ template: AuditTemplate }>('/api/audit-templates', {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),
  rename: (id: number, name: string, csrfToken: string | null) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}`, {
      method: 'PATCH',
      body: { name },
      csrfToken,
    }),
  remove: (id: number, csrfToken: string | null) =>
    api<void>(`/api/audit-templates/${id}`, { method: 'DELETE', csrfToken, requireOnline: true }),
  /** Put a delivered checklist back the way it shipped. */
  restore: (id: number, csrfToken: string | null) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}/restore`, {
      method: 'POST',
      csrfToken,
      requireOnline: true,
    }),

  addSection: (id: number, body: { code: string; name: string }, csrfToken: string | null) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}/sections`, {
      method: 'POST',
      body,
      csrfToken,
    }),
  updateSection: (
    id: number,
    sectionId: number,
    body: { code?: string; name?: string },
    csrfToken: string | null,
  ) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}/sections/${sectionId}`, {
      method: 'PATCH',
      body,
      csrfToken,
    }),
  removeSection: (id: number, sectionId: number, csrfToken: string | null) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}/sections/${sectionId}`, {
      method: 'DELETE',
      csrfToken,
    }),

  addItem: (
    id: number,
    sectionId: number,
    body: { text: string; legal_basis?: string | null; scope?: AuditItemScope },
    csrfToken: string | null,
  ) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}/sections/${sectionId}/items`, {
      method: 'POST',
      body,
      csrfToken,
    }),
  updateItem: (
    id: number,
    itemId: number,
    body: { text?: string; legal_basis?: string | null; scope?: AuditItemScope },
    csrfToken: string | null,
  ) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}/items/${itemId}`, {
      method: 'PATCH',
      body,
      csrfToken,
    }),
  removeItem: (id: number, itemId: number, csrfToken: string | null) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}/items/${itemId}`, {
      method: 'DELETE',
      csrfToken,
    }),

  /** Reorder sections, or the items inside one section — one request per move. */
  reorder: (
    id: number,
    body: { section_ids?: number[]; section_id?: number; item_ids?: number[] },
    csrfToken: string | null,
  ) =>
    api<{ template: AuditTemplate }>(`/api/audit-templates/${id}/order`, {
      method: 'POST',
      body,
      csrfToken,
    }),
};
