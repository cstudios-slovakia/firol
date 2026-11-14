/**
 * Preventívna prehliadka — the reads its screens need beyond the generic
 * inspection API (POapp 26. 10. 2026, phase 7a).
 *
 * Offline handling, per endpoint:
 *   GET   /api/facilities/{id}/prehliadka-context   cached by `lib/api.ts`; step 1 works
 *                                                   offline for a prevádzka seen online
 *   GET   /api/facilities/{id}/devices              cached the same way (api/facilities.ts)
 *   POST  /api/inspections                          the draft: optimistic create, temp id
 *   PATCH /api/inspections/{id}                     setup / date / periodicity: queued
 *   POST/PATCH/DELETE /api/inspections/{id}/items…  defects: queued with temp ids
 *   POST  /api/inspections/{id}/items/{item}/photos photos: queued, path remapped on sync
 * Phase 7b:
 *   GET   /api/facilities/{id}/prehliadka-previous  cached; the defects of the last prehliadka
 *   GET   /api/facilities/{id}/prehliadka-terms     cached; trainings and periodicities of step 3
 *   GET   /api/companies/{id}/activities            cached; the firm's „vykonané činnosti"
 *   POST/DELETE /api/companies/{id}/activities…     online-only — a list shared with later visits
 *   PATCH /api/inspections/{id} (details)           ticks, previous-defect answers, overrides,
 *                                                   present / handed over: queued like the setup
 * Phase 8a:
 *   POST  /api/inspections/{id}/prehliadka-close    online-only — it takes the numbers
 *   GET   /api/inspections/{id}/prehliadka-result   cached; what a closed prehliadka produced
 * Phase 8b:
 *   POST  /api/inspections/{id}/confirm-concept     online-only (api/inspections.ts) — confirms a
 *                                                   concept before its protocol can be generated
 * After 8b:
 *   `prehliadka-result` also lists `task_defects` — the defects of a closed prehliadka the
 *                                                   „Overiť odstránenie" task can be offered for
 * One defect screen (POapp 7. 11. 2026, spec 12.1):
 *   GET   /api/facilities/{id}/prehliadka-previous  still cached; now only the open defects, with
 *                                                   `source.number` and each defect's `photos`
 *   POST  /api/inspections/{id}/items               a carried-over defect (`persists_from`, any
 *                                                   state) queues exactly like a new one; the
 *                                                   server copies the old photos onto it when the
 *                                                   create runs, so a replayed create gets them too
 *   POST  /api/inspections/{id}/items/{item}/photos photos added on top: queued as for a new defect
 *   PATCH /api/inspections/{id} (details.previous)  no longer written — the answer is the row itself
 *   The old photos are shown from their URLs: offline they appear only if the
 *   browser still holds them; the copy is made on the server either way.
 * Checklist after a closed prehliadka (POapp 7. 11. 2026, spec 12.2):
 *   GET   /api/inspections/{id}/prehliadka-checklist          cached; the concepts of the round and the
 *                                                             follow-ups of its closed PHP / hydranty checks
 *   POST  /api/inspections/{id}/prehliadka-checklist/prepare  online-only — creates the ticked follow-ups
 *   POST  /api/inspections/{id}/prehliadka-checklist/create   online-only — the blank draft of an entry
 *                                                             with nothing to carry
 *   GET   /api/inspections/{id}/prehliadka-checklist/review   cached; every value of the ticked protocols
 *   PATCH /api/inspections/{id}/items/{item}                  edits on the review screen: queued as anywhere
 *   POST  /api/inspections/{id}/prehliadka-checklist/generate online-only — it takes the numbers
 * Požiarna kniha entries (POapp 7. 11. 2026, the new format):
 *   GET   /api/facilities/{id}/prehliadka-context   still cached; adds `last_term` — the newest
 *                                                   prehliadka or spôsob 1 entry holding the term
 *   GET   /api/facilities/{id}/prehliadka-previous  still cached; each defect carries its own
 *                                                   `source` (a book entry's defects come along)
 * The entrance and the place a defect was written with are remembered by the
 * server inside the defect's own save — see FacilityWalkMemory — so there is no
 * endpoint of their own to queue.
 */
import { api } from '@/lib/api';
import type { KnihaLastTerm } from '@/api/kniha';
import type { PrehliadkaTaskDefect } from '@/components/DefectTaskOffer';
import type { InspectionDocument, InspectionType } from '@/api/inspections';
import type { PeriodicityUnit } from '@/lib/periodicity';
import type {
  DeviceType,
  PrehliadkaScope,
  PreviousDefect,
  PreviousSource,
  RoutinePeriodicities,
  TermTraining,
} from '@/lib/prehliadka';

/** The setup of the last finished prehliadka here — „Ako naposledy". */
export type PrehliadkaMemory = {
  inspection_id: number;
  executed_on: string | null;
  /** How many defects it wrote down. Absent on a context cached before it existed. */
  defects?: number;
  periodicity_value: number | null;
  periodicity_unit: PeriodicityUnit | null;
  scope: PrehliadkaScope;
  routine: DeviceType[];
  lehota_zakon?: number;
  lehota_vlastna?: number | null;
};

export type PrehliadkaContext = {
  /** A prehliadka in progress at this prevádzka. */
  draft_id: number | null;
  last: PrehliadkaMemory | null;
  /**
   * The newest finalized prehliadka or spôsob 1 požiarna kniha entry covering
   * this prevádzka (it holds the term). Absent on a context cached before it existed.
   */
  last_term?: KnihaLastTerm | null;
  /** Unfinished draft per riadna kontrola — that type cannot be ticked. */
  open_drafts: Partial<Record<DeviceType, number>>;
};

/** The still open defects of the last finished prehliadka here (or of an old požiarna kniha record). */
export type PreviousDefects = {
  source: PreviousSource | null;
  defects: PreviousDefect[];
};

export type TermFacts = {
  trainings: TermTraining[];
  routine_periodicities: RoutinePeriodicities;
};

export type ActivitySet = {
  defaults: string[];
  custom: { id: number; label: string }[];
};
export type CompanyActivities = { po: ActivitySet; bozp: ActivitySet };

/** What closing a prehliadka produced (prototype `scrVysledok`). */
export type PrehliadkaResult = {
  inspection: {
    id: number;
    status: 'draft' | 'finalized';
    executed_on: string | null;
    company_id: number;
    facility_id: number;
    facility_name: string;
    facility_city: string | null;
    scope: PrehliadkaScope;
  };
  /** The PP record — null when only BOZP was chosen. */
  po: { documents: InspectionDocument[]; defects: number } | null;
  /** The kniha BOZP record, which is also the BOZP record of the prehliadka. */
  bozp: { inspection_id: number | null; documents: InspectionDocument[]; defects: number } | null;
  /** One draft úkon per riadna kontrola ticked in step 1 (spec 7.3). Absent on an older answer. */
  concepts?: PrehliadkaConcept[];
  /** Defects with a termín the „Overiť odstránenie" task can be offered for — closed prehliadka only. Absent on an older answer. */
  task_defects?: PrehliadkaTaskDefect[];
};

/** A concept check a closed prehliadka prepared. */
export type PrehliadkaConcept = {
  inspection_id: number;
  type: InspectionType;
  status: 'draft' | 'finalized';
  confirmed: boolean;
  /** Devices in the concept. */
  items: number;
  /** Of those, how many „nevyhovuje" (status O for a hasiaci prístroj). */
  failing: number;
};

/** The protocol carried values come from — „prevzaté z protokolu č. … zo dňa …". */
export type ChecklistOrigin = {
  /** Null for an úkon that has no document (old data). */
  number: string | null;
  executed_on: string | null;
};

/** One line of the checklist after a prehliadka (spec 12.2). */
export type ChecklistEntry = {
  /** `i{id}` for an existing úkon, `f{source}:{type}` for a follow-up not created yet. */
  key: string;
  kind: 'concept' | 'follow_up';
  type: InspectionType;
  inspection_id: number | null;
  /** Null while a follow-up is not created yet. */
  status: 'draft' | 'finalized' | null;
  /** The closed check a follow-up arises from. */
  follow_up_of: { inspection_id: number; type: InspectionType } | null;
  items: number;
  failing: number;
  origin: ChecklistOrigin | null;
  document: { id: number; number: string } | null;
  /** Can be ticked for „vytvoriť a rovno uzavrieť". */
  selectable: boolean;
  /** What to do when it cannot be ticked. */
  note: string | null;
  /** A follow-up with nothing to carry — its blank draft comes from the ordinary follow-up offer. */
  manual_create: boolean;
};

export type ChecklistHead = {
  id: number;
  status: 'draft' | 'finalized';
  executed_on: string | null;
  company_id: number;
  company_name: string;
  facility_id: number;
  facility_name: string;
};

export type PrehliadkaChecklist = { prehliadka: ChecklistHead; entries: ChecklistEntry[] };

export type ChecklistRefusal = { key: string; type: InspectionType | null; reason: string };

export type ReviewItem = {
  id: number;
  position: number;
  fields: Record<string, unknown>;
  /** The device as the technician knows it. */
  name: string;
  /** Slovak names of the required fields still blank, as the item form labels them. */
  missing: string[];
  /** Its values were carried from `origin`. */
  carried: boolean;
};

export type ReviewBlock = {
  inspection_id: number;
  type: InspectionType;
  status: 'draft' | 'finalized';
  executed_on: string | null;
  visit_id: number | null;
  origin: ChecklistOrigin | null;
  follow_up_of: { inspection_id: number; type: InspectionType } | null;
  document: { id: number; number: string } | null;
  /** The technician cannot sign this kind of úkon — why. */
  cert_missing: string | null;
  items: ReviewItem[];
};

export type ChecklistReview = { prehliadka: ChecklistHead; blocks: ReviewBlock[] };

export type ChecklistGenerateResult = {
  generated: { inspection_id: number; type: InspectionType; document: InspectionDocument }[];
  skipped: { inspection_id: number; type: InspectionType | null; reason: string }[];
};

export const Prehliadka = {
  /** Everything the round gives rise to (spec 12.2). */
  checklist: (inspectionId: number) =>
    api<PrehliadkaChecklist>(`/api/inspections/${inspectionId}/prehliadka-checklist`),
  /** The ticked entries as drafts — follow-ups created with their carried values. Online-only. */
  prepareChecklist: (inspectionId: number, keys: string[], csrfToken: string | null) =>
    api<{ inspection_ids: number[]; refused: ChecklistRefusal[] }>(
      `/api/inspections/${inspectionId}/prehliadka-checklist/prepare`,
      { method: 'POST', body: { keys }, csrfToken, requireOnline: true },
    ),
  /** The blank draft of an entry with nothing to carry (`manual_create`). Online-only. */
  createChecklistManual: (inspectionId: number, key: string, csrfToken: string | null) =>
    api<{ inspection_id: number }>(`/api/inspections/${inspectionId}/prehliadka-checklist/create`, {
      method: 'POST',
      body: { key },
      csrfToken,
      requireOnline: true,
    }),
  reviewChecklist: (inspectionId: number, ids: number[]) =>
    api<ChecklistReview>(`/api/inspections/${inspectionId}/prehliadka-checklist/review?ids=${ids.join(',')}`),
  /** Issues each protocol on its own. Online-only — it takes the numbers. */
  generateChecklist: (inspectionId: number, ids: number[], executedOn: string | null, csrfToken: string | null) =>
    api<ChecklistGenerateResult>(`/api/inspections/${inspectionId}/prehliadka-checklist/generate`, {
      method: 'POST',
      body: executedOn ? { ids, executed_on: executedOn } : { ids },
      csrfToken,
      requireOnline: true,
    }),

  /** Closes the prehliadka — issues the records. Online-only. */
  close: (inspectionId: number, csrfToken: string | null) =>
    api<PrehliadkaResult>(`/api/inspections/${inspectionId}/prehliadka-close`, {
      method: 'POST',
      body: { include_photos: true },
      csrfToken,
      requireOnline: true,
    }),
  result: (inspectionId: number) =>
    api<PrehliadkaResult>(`/api/inspections/${inspectionId}/prehliadka-result`),
  context: (facilityId: number) =>
    api<PrehliadkaContext>(`/api/facilities/${facilityId}/prehliadka-context`),
  previous: (facilityId: number, excludeInspectionId: number) =>
    api<PreviousDefects>(
      `/api/facilities/${facilityId}/prehliadka-previous?exclude=${excludeInspectionId}`,
    ),
  terms: (facilityId: number, asOf: string) =>
    api<TermFacts>(`/api/facilities/${facilityId}/prehliadka-terms?as_of=${encodeURIComponent(asOf)}`),
  activities: (companyId: number) => api<CompanyActivities>(`/api/companies/${companyId}/activities`),
  addActivity: (companyId: number, kind: 'po' | 'bozp', label: string, csrfToken: string | null) =>
    api<{ activity: { id: number; label: string } }>(`/api/companies/${companyId}/activities`, {
      method: 'POST',
      body: { kind, label },
      csrfToken,
      requireOnline: true,
    }),
  removeActivity: (companyId: number, activityId: number, csrfToken: string | null) =>
    api<void>(`/api/companies/${companyId}/activities/${activityId}`, {
      method: 'DELETE',
      csrfToken,
      requireOnline: true,
    }),
};
