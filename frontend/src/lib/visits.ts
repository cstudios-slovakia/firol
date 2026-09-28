import { INSPECTION_TYPE_LABELS, type InspectionType } from '@/api/inspections';
import { SKOLENIE_PO, type Visit, type VisitInspection, type VisitTraining, type VisitType } from '@/api/visits';
import type { Crumb } from '@/components/ui/Breadcrumb';

/** „1 položka", „2 položky", „5 položiek" — Slovak plural of a count. */
function plural(n: number, one: string, few: string, many: string): string {
  return `${n} ${n === 1 ? one : n < 5 ? few : many}`;
}

/**
 * The úkony of a visit in the order the technician sees them: the plan ticked
 * at the start, then anything added on the spot.
 */
export function visitUkonTypes(visit: Visit): VisitType[] {
  const types: VisitType[] = [...visit.planned_types];
  for (const ins of visit.inspections) {
    if (!types.includes(ins.type)) types.push(ins.type);
  }
  // A training recorded under the visit counts as its školenie PO even if it
  // was not ticked at the start.
  if (visit.trainings.length > 0 && !types.includes(SKOLENIE_PO)) types.push(SKOLENIE_PO);
  return types;
}

/**
 * „Úkon N z M" of a type inside its visit (chapter 29.2). A type that is not
 * in the list yet — one about to be added on the spot — counts as the next.
 */
export function visitUkonPosition(visit: Visit, type: VisitType): { n: number; total: number } {
  const types = visitUkonTypes(visit);
  const index = types.indexOf(type);
  return index === -1
    ? { n: types.length + 1, total: types.length + 1 }
    : { n: index + 1, total: types.length };
}

/** Label of an úkon type on the visit screens. */
export function visitTypeLabel(type: VisitType): string {
  if (type === SKOLENIE_PO) return 'Školenie PO';
  return INSPECTION_TYPE_LABELS[type] ?? type;
}

/** The úkon of a type recorded under the visit, if one was started. */
export function visitInspectionOfType(visit: Visit, type: InspectionType): VisitInspection | undefined {
  return visit.inspections.find((ins) => ins.type === type);
}

/**
 * The training standing for the visit's školenie PO: the one still being
 * worked on, else the first. A visit has one planned školenie PO, so there is
 * normally exactly one.
 */
export function visitTrainingOf(visit: Visit): VisitTraining | undefined {
  return visit.trainings.find((t) => t.status !== 'finalized') ?? visit.trainings[0];
}

/** How far an úkon of the visit is, as the visit screen and „Ďalší úkon" read it. */
export function visitUkonState(
  visit: Visit,
  type: VisitType,
): { started: boolean; done: boolean; text: string; documentId: number | null } {
  if (type === SKOLENIE_PO) {
    const training = visitTrainingOf(visit);
    if (!training) return { started: false, done: false, text: 'Zatiaľ nezačaté', documentId: null };
    const trainees = plural(training.trainees_count, 'účastník', 'účastníci', 'účastníkov');
    const done = visit.trainings.every((t) => t.status === 'finalized');
    return {
      started: true,
      done,
      text: done
        ? `Protokol ${training.document_number ?? '—'} · ${trainees}`
        : `Rozpracované · ${trainees}`,
      documentId: done ? training.document_id : null,
    };
  }
  const inspection = visitInspectionOfType(visit, type);
  if (!inspection) return { started: false, done: false, text: 'Zatiaľ nezačaté', documentId: null };
  const items = plural(inspection.item_count, 'položka', 'položky', 'položiek');
  const done = inspection.status === 'finalized';
  return {
    started: true,
    done,
    text: done ? `Protokol ${inspection.document_number ?? '—'} · ${items}` : `Rozpracované · ${items}`,
    documentId: done ? inspection.document_id : null,
  };
}

/**
 * Where „Začať" / „Pokračovať" of a type leads: the úkon already started, or
 * Step 1 of a new one (chapter 9). Step 1 and the new training read the
 * company, prevádzka and date from the visit itself; the query carries only
 * the visit id plus a convenience copy for a stale bookmark.
 */
export function visitUkonPath(visit: Visit, type: VisitType): string {
  if (type === SKOLENIE_PO) {
    const training = visitTrainingOf(visit);
    if (training) return `/trainings/${training.id}`;
    return `/trainings/new?${new URLSearchParams({ visit_id: String(visit.id) }).toString()}`;
  }
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
export function nextUnfinishedType(visit: Visit): VisitType | null {
  return visitUkonTypes(visit).find((type) => !visitUkonState(visit, type).done) ?? null;
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
