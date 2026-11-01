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
 * The entrance and the place a defect was written with are remembered by the
 * server inside the defect's own save — see FacilityWalkMemory — so there is no
 * endpoint of their own to queue.
 */
import { api } from '@/lib/api';
import type { PeriodicityUnit } from '@/lib/periodicity';
import type { DeviceType, PrehliadkaScope } from '@/lib/prehliadka';

/** The setup of the last finished prehliadka here — „Ako naposledy". */
export type PrehliadkaMemory = {
  inspection_id: number;
  executed_on: string | null;
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

export const Prehliadka = {
  context: (facilityId: number) =>
    api<PrehliadkaContext>(`/api/facilities/${facilityId}/prehliadka-context`),
};
