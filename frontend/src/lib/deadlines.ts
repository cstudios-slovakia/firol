import { INSPECTION_TYPE_LABELS } from '@/api/inspections';
import { TRAINING_TYPE_LABELS } from '@/api/trainings';
import { Calendar, type CalendarDeadline } from '@/api/calendar';
import { CLIENT_NEUTRAL, cap, clientNouns } from '@/lib/clientKind';

/**
 * A computed deadline comes from an inspection or, since change request 6,
 * from a training — the one shape serves both, so everything that names or
 * opens a deadline goes through here instead of reading `inspection_id` and
 * `INSPECTION_TYPE_LABELS[type]` directly.
 */

/** Name of the control or training the deadline is for. */
export function deadlineLabel(d: Pick<CalendarDeadline, 'type' | 'training_type'>): string {
  if (d.training_type) return TRAINING_TYPE_LABELS[d.training_type];
  return d.type === 'skolenie_po' ? 'Školenie' : INSPECTION_TYPE_LABELS[d.type];
}

/** Page of the úkon that defines the deadline. */
export function deadlineHref(d: Pick<CalendarDeadline, 'inspection_id' | 'training_id'>): string {
  return d.training_id !== null ? `/trainings/${d.training_id}` : `/inspections/${d.inspection_id}`;
}

/**
 * Prevádzka / bytový dom name, or — for a training without one, which is the
 * whole client's — „Celá firma" / „Celé bytové družstvo". Where the client
 * type is not on the row it stays neutral („Celý klient").
 */
export function deadlineFacilityName(
  d: Pick<CalendarDeadline, 'facility_name'> & { company_client_type?: string | null },
): string {
  if (d.facility_name) return d.facility_name;
  return cap(d.company_client_type ? clientNouns(d.company_client_type).whole.nom : CLIENT_NEUTRAL.whole.nom);
}

/**
 * „prevádzka · mesto" — how a row names its place, so three prevádzky of one
 * firma in one town read apart. The name first; the city after it when there
 * is one and the name does not already contain it; the city alone when there
 * is no name. A training of the whole client passes the whole-client label
 * ({@link deadlineFacilityName}) as its name. Null when there is neither.
 */
export function facilityPlace(name: string | null | undefined, city: string | null | undefined): string | null {
  const n = (name ?? '').trim();
  const c = (city ?? '').trim();
  if (n === '') return c === '' ? null : c;
  if (c === '' || containsWord(n, c)) return n;
  return `${n} · ${c}`;
}

/**
 * Whether `word` occurs in `text` as a whole word (or phrase), case-insensitive:
 * the city „Lom" is in „Sklad Lom" but not in „Lomnica sklad".
 */
function containsWord(text: string, word: string): boolean {
  const t = text.toLocaleLowerCase('sk');
  const w = word.toLocaleLowerCase('sk').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`(?<![\\p{L}\\p{N}])${w}(?![\\p{L}\\p{N}])`, 'u').test(t);
}

/** Set the planned visit date on whichever úkon defines the deadline. */
export function setDeadlinePlan(
  d: Pick<CalendarDeadline, 'inspection_id' | 'training_id' | 'facility_id'>,
  plannedDate: string,
  csrfToken: string | null,
) {
  // A požiarna kniha entry has one term per prevádzka; the plan goes to this one.
  return d.training_id !== null
    ? Calendar.setTrainingPlan(d.training_id, plannedDate, csrfToken)
    : Calendar.setPlan(d.inspection_id as number, plannedDate, csrfToken, d.facility_id);
}

/** Clear the planned visit date, falling the deadline back to its due date. */
export function clearDeadlinePlan(
  d: Pick<CalendarDeadline, 'inspection_id' | 'training_id' | 'facility_id'>,
  csrfToken: string | null,
) {
  return d.training_id !== null
    ? Calendar.clearTrainingPlan(d.training_id, csrfToken)
    : Calendar.clearPlan(d.inspection_id as number, csrfToken, d.facility_id);
}
