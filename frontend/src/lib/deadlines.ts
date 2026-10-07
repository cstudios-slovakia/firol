import { INSPECTION_TYPE_LABELS } from '@/api/inspections';
import { TRAINING_TYPE_LABELS } from '@/api/trainings';
import { Calendar, type CalendarDeadline } from '@/api/calendar';

/**
 * A computed deadline comes from an inspection or, since change request 6,
 * from a training — the one shape serves both, so everything that names or
 * opens a deadline goes through here instead of reading `inspection_id` and
 * `INSPECTION_TYPE_LABELS[type]` directly.
 */

/** What a training term shows where a prevádzka name would be: it is the whole firma's. */
export const WHOLE_FIRM_LABEL = 'Celá firma';

/** Name of the control or training the deadline is for. */
export function deadlineLabel(d: Pick<CalendarDeadline, 'type' | 'training_type'>): string {
  if (d.training_type) return TRAINING_TYPE_LABELS[d.training_type];
  return d.type === 'skolenie_po' ? 'Školenie' : INSPECTION_TYPE_LABELS[d.type];
}

/** Page of the úkon that defines the deadline. */
export function deadlineHref(d: Pick<CalendarDeadline, 'inspection_id' | 'training_id'>): string {
  return d.training_id !== null ? `/trainings/${d.training_id}` : `/inspections/${d.inspection_id}`;
}

/** Prevádzka name, or „Celá firma" for a training without one. */
export function deadlineFacilityName(d: Pick<CalendarDeadline, 'facility_name'>): string {
  return d.facility_name ?? WHOLE_FIRM_LABEL;
}

/** Set the planned visit date on whichever úkon defines the deadline. */
export function setDeadlinePlan(
  d: Pick<CalendarDeadline, 'inspection_id' | 'training_id'>,
  plannedDate: string,
  csrfToken: string | null,
) {
  return d.training_id !== null
    ? Calendar.setTrainingPlan(d.training_id, plannedDate, csrfToken)
    : Calendar.setPlan(d.inspection_id as number, plannedDate, csrfToken);
}

/** Clear the planned visit date, falling the deadline back to its due date. */
export function clearDeadlinePlan(
  d: Pick<CalendarDeadline, 'inspection_id' | 'training_id'>,
  csrfToken: string | null,
) {
  return d.training_id !== null
    ? Calendar.clearTrainingPlan(d.training_id, csrfToken)
    : Calendar.clearPlan(d.inspection_id as number, csrfToken);
}
