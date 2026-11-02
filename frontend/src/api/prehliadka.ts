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
 * The entrance and the place a defect was written with are remembered by the
 * server inside the defect's own save — see FacilityWalkMemory — so there is no
 * endpoint of their own to queue.
 */
import { api } from '@/lib/api';
import type { InspectionDocument, InspectionType } from '@/api/inspections';
import type { PeriodicityUnit } from '@/lib/periodicity';
import type {
  DeviceType,
  PrehliadkaScope,
  PreviousDefect,
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
  book_entry: boolean;
};

export type PrehliadkaContext = {
  /** A prehliadka in progress at this prevádzka. */
  draft_id: number | null;
  last: PrehliadkaMemory | null;
  /** Unfinished draft per riadna kontrola — that type cannot be ticked. */
  open_drafts: Partial<Record<DeviceType, number>>;
};

/** The defects of the last finished prehliadka here (or of an old požiarna kniha record). */
export type PreviousDefects = {
  source: { kind: 'prehliadka' | 'poziarna_kniha'; inspection_id: number; executed_on: string | null } | null;
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

/** An entry the closed prehliadka wrote into a book. */
export type ResultEntry = {
  id: number;
  /** Poradové číslo in its ledger. */
  seq: number;
  signed_manager: boolean;
  /** Set only for an entry written in a per-prevádzka book. */
  facility_id: number | null;
};

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
  entries: { po: ResultEntry | null; bozp: ResultEntry | null };
  /** Set while the požiarna kniha has no entry for this prehliadka. */
  offer: { text: string; protocol_numbers: string[]; has_defects: boolean; legal_sentence: string } | null;
  /** One draft úkon per riadna kontrola ticked in step 1 (spec 7.3). Absent on an older answer. */
  concepts?: PrehliadkaConcept[];
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

export const Prehliadka = {
  /** Closes the prehliadka — issues the records and writes the book entries. Online-only. */
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
