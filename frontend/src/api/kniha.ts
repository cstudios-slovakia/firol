/**
 * Zápis do požiarnej knihy — what an entry needs to know about its company
 * (POapp 7. 11. 2026, spec 4 step 1 and spec 7).
 *
 * Offline handling, per endpoint:
 *   GET   /api/companies/{id}/kniha            cached by `lib/api.ts`; warmed from the facility,
 *                                              company and visit pages, so an entry can be
 *                                              started (and its records ticked) offline
 *   POST  /api/inspections                     the entry: optimistic create with its facilities
 *                                              (lib/offlineEntities.ts inspectionCreateOptimistic)
 *   PATCH /api/inspections/{id}                step 1 (facilities, details, date) and every edit
 *                                              of step 2 / 3: queued, the caches patched
 *   POST/PATCH/DELETE /api/inspections/{id}/items…  defects: queued with temp ids
 *   POST  /api/inspections/{id}/items/{item}/photos photos: queued, path remapped on sync
 *   DELETE /api/inspections/{id}               „Zahodiť zápis": queued like any archive
 *   POST  /api/inspections/{id}/generate-pdf   online-only — it takes the number
 *   POST  /api/inspections/{id}/unlock         online-only
 *   POST  /api/inspections/{id}/repeat         online-only
 */
import { api } from '@/lib/api';
import type { ClientType } from '@/lib/clientKind';
import type { PeriodicityUnit } from '@/lib/periodicity';

/** The last finalized prehliadka (or spôsob 1 entry) covering a prevádzka (BookEntry::lastTerm). */
export type KnihaLastTerm = {
  kind: 'prehliadka' | 'kniha';
  inspection_id: number;
  executed_on: string | null;
  periodicity_value: number | null;
  periodicity_unit: PeriodicityUnit | null;
  valid_until: string | null;
  defects: number;
};

export type KnihaFacility = {
  id: number;
  name: string;
  address: string | null;
  /** Newest records of each kind it has to attach (the organisation's excluded). */
  attach_count: number;
  last_prehliadka: KnihaLastTerm | null;
};

export type KnihaRecord = {
  /** `i123` / `t45` */
  key: string;
  kind: 'inspection' | 'training';
  id: number;
  /** Null — a training of the whole organisation (the `company_group_heading` group). */
  facility_id: number | null;
  druh: string;
  label: string;
  tag: 'ppp' | 'skolenie' | null;
  date: string;
  number: string;
  meta: string;
  /** The newest of its kind at its prevádzka; older ones come with „Zobraziť aj staršie". */
  is_latest: boolean;
  text_full: string;
  text_ref: string;
};

export type KnihaContext = {
  company: { id: number; name: string; ico: string | null; client_type: ClientType };
  /** „Meno, funkcia" of the client's person for the book, or null. */
  schvalil_default: string | null;
  /** The activity list of the client type, in order (spec 6). */
  activities: string[];
  /** Heading of the organisation's own trainings; null when they are not offered. */
  company_group_heading: string | null;
  /** Active prevádzky by name. */
  facilities: KnihaFacility[];
  /** Every attachable record, in print order. */
  records: KnihaRecord[];
};

export const Kniha = {
  context: (companyId: number) => api<KnihaContext>(`/api/companies/${companyId}/kniha`),
};

/** The months a new entry prefills for a prevádzka: its last term when 12 / 6 / 3 months, else none (criterion 23). */
export function prefillMonths(last: KnihaLastTerm | null): 12 | 6 | 3 | null {
  if (!last || last.periodicity_unit !== 'mesiac') return null;
  const v = last.periodicity_value;
  return v === 12 || v === 6 || v === 3 ? v : null;
}
