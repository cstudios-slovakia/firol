import { INSPECTION_TYPE_LABELS, type InspectionType } from '@/api/inspections';
import type { Visit } from '@/api/visits';

/**
 * The úkony of a visit in the order the technician sees them: the plan ticked
 * at the start, then anything added on the spot.
 */
export function visitUkonTypes(visit: Visit): InspectionType[] {
  return [
    ...visit.planned_types,
    ...visit.inspections.map((ins) => ins.type).filter((t) => !visit.planned_types.includes(t)),
  ];
}

/**
 * „Úkon N z M" of a type inside its visit (chapter 29.2). A type that is not
 * in the list yet — one about to be added on the spot — counts as the next.
 */
export function visitUkonPosition(visit: Visit, type: InspectionType): { n: number; total: number } {
  const types = visitUkonTypes(visit);
  const index = types.indexOf(type);
  return index === -1
    ? { n: types.length + 1, total: types.length + 1 }
    : { n: index + 1, total: types.length };
}

/** Label of an úkon type on the visit screens. */
export function visitTypeLabel(type: InspectionType): string {
  return INSPECTION_TYPE_LABELS[type] ?? type;
}
