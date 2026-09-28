import { INSPECTION_TYPE_LABELS, type InspectionType } from '@/api/inspections';
import type { Visit } from '@/api/visits';
import type { Crumb } from '@/components/ui/Breadcrumb';

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

/** The úkon of a type recorded under the visit, if one was started. */
export function visitInspectionOfType(visit: Visit, type: InspectionType) {
  return visit.inspections.find((ins) => ins.type === type);
}

/**
 * Where „Začať" / „Pokračovať" of a type leads: the úkon already started, or
 * Step 1 of a new one with the visit's context (chapter 9). Step 1 reads the
 * company, prevádzka and date from the visit itself; the query only carries
 * the visit id (the rest is a convenience for a stale bookmark).
 */
export function visitUkonPath(visit: Visit, type: InspectionType): string {
  const existing = visitInspectionOfType(visit, type);
  if (existing) return `/inspections/${existing.id}`;
  const params = new URLSearchParams({
    company_id: String(visit.company_id),
    facility_id: String(visit.facility_id),
    visit_id: String(visit.id),
    executed_on: visit.visit_date,
  });
  return `/inspections/new/${type}/step-1?${params.toString()}`;
}

/** The first úkon of the visit that has no protocol yet, or null when all are done. */
export function nextUnfinishedType(visit: Visit): InspectionType | null {
  return (
    visitUkonTypes(visit).find((type) => visitInspectionOfType(visit, type)?.status !== 'finalized') ?? null
  );
}

/**
 * The first two crumbs of every screen inside a visit: Dnes › Návšteva. The
 * label carries the firm and the date so the technician always sees which
 * visit they are in.
 */
export function visitTrail(visit: { id: number; companyName: string; date: string | null }): Crumb[] {
  const when = visit.date ? new Date(`${visit.date}T00:00:00`).toLocaleDateString('sk-SK') : null;
  return [
    { label: 'Dnes', to: '/' },
    {
      label: `Návšteva (${visit.companyName}${when ? `, ${when}` : ''})`,
      shortLabel: 'Návšteva',
      to: `/visits/${visit.id}`,
    },
  ];
}
