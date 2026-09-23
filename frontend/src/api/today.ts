import { api } from '@/lib/api';
import type { CalendarDeadline, OwnTerm, TerminTechnician } from '@/api/calendar';
import type { InspectionType } from '@/api/inspections';

/**
 * Obrazovka „Dnes" — block 4 / chapter 18.
 *
 * GET /api/today?scope=mine|team returns, for the active account, the rows of
 * every card except „Úlohy do 7 dní" (that one reads `Tasks.upcoming`). Each
 * list is exactly what its card shows, so a card's header count is the list's
 * length. Archived firms never appear. Like every GET, the answer is cached
 * for offline use by the api layer.
 */

export type TodayScope = 'mine' | 'team';

/** One row of „Dnes v teréne". */
export type FieldRow = {
  /** navsteva = visit dated today, plan = deadline planned for today, udalost = vlastná udalosť. */
  kind: 'navsteva' | 'plan' | 'udalost';
  key: string;
  /** Visit id, the deadline's inspection id, or the event id. */
  id: number;
  company_id: number | null;
  company_name: string | null;
  facility_name: string | null;
  city: string | null;
  /** Úkony planned on the visit; the deadline's type for a plan; empty for an event. */
  types: InspectionType[];
  /** Event title; null otherwise. */
  title: string | null;
  /** Visit: prebieha | dokoncena; plan: planovany | po_termine; event: null. */
  status: string | null;
  technician: TerminTechnician | null;
};

/** An úkon row — a draft, or one waiting to be invoiced. */
export type UkonRow = {
  kind: 'inspection' | 'training';
  key: string;
  id: number;
  /** InspectionType or TrainingType, depending on `kind`. */
  type: string;
  company_name: string;
  facility_name: string | null;
  /** executed_on / training date; null while not filled in. */
  done_on: string | null;
  created_at: string;
  technician: TerminTechnician | null;
};

export type OpenDefect = {
  key: string;
  inspection_id: number;
  defect_key: string | null;
  type: InspectionType;
  executed_on: string | null;
  description: string;
  /** Termín odstránenia, YYYY-MM-DD. */
  deadline: string;
  company_id: number;
  company_name: string;
  facility_name: string;
  technician: TerminTechnician | null;
};

export type TodayData = {
  date: string;
  scope: TodayScope;
  field: FieldRow[];
  overdue: CalendarDeadline[];
  drafts: UkonRow[];
  defects: OpenDefect[];
  uninvoiced: UkonRow[];
  own_terms: OwnTerm[];
};

export const Today = {
  get: (scope: TodayScope) => api<TodayData>(`/api/today?scope=${scope}`),
};
