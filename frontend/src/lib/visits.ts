import { INSPECTION_TYPE_LABELS, type InspectionType } from '@/api/inspections';
import { SKOLENIE_PO, type Visit, type VisitInspection, type VisitTraining, type VisitType } from '@/api/visits';
import type { Crumb } from '@/components/ui/Breadcrumb';
import { PREHLIADKA_NEW_PATH, PREHLIADKA_TYPE, prehliadkaPath } from '@/lib/prehliadka';

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
    // A prehliadka begun for a planned „Kniha kontrol BOZP" is that úkon, not a second row.
    if (ins.type === PREHLIADKA_TYPE && types.includes('kniha_bozp')) continue;
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

/** Text of an úkon whose protocol is held until the end of the visit. */
export const HELD_TEXT = 'Hotové · protokol sa vygeneruje na konci návštevy';

/** How far an úkon of the visit is, as the visit screen and „Ďalší úkon" read it. */
export function visitUkonState(
  visit: Visit,
  type: VisitType,
): { started: boolean; done: boolean; text: string; documentId: number | null } {
  if (type === SKOLENIE_PO) {
    const training = visitTrainingOf(visit);
    if (!training) return { started: false, done: false, text: 'Zatiaľ nezačaté', documentId: null };
    const trainees = plural(training.trainees_count, 'účastník', 'účastníci', 'účastníkov');
    const issued = visit.trainings.every((t) => t.status === 'finalized');
    if (!issued && visit.trainings.every((t) => t.status === 'finalized' || t.deferred)) {
      return { started: true, done: true, text: HELD_TEXT, documentId: null };
    }
    return {
      started: true,
      done: issued,
      text: issued
        ? `Protokol ${training.document_number ?? '—'} · ${trainees}`
        : `Rozpracované · ${trainees}`,
      documentId: issued ? training.document_id : null,
    };
  }
  // „Kniha kontrol BOZP" is carried out as a prehliadka preset to BOZP (D2): a
  // prehliadka begun under the visit stands for it until its own record exists.
  const inspection =
    visitInspectionOfType(visit, type) ??
    (type === 'kniha_bozp' ? visitInspectionOfType(visit, PREHLIADKA_TYPE) : undefined);
  if (!inspection) return { started: false, done: false, text: 'Zatiaľ nezačaté', documentId: null };
  if (inspection.type === PREHLIADKA_TYPE) {
    const defects = plural(inspection.item_count, 'nedostatok', 'nedostatky', 'nedostatkov');
    // A closed prehliadka is done; its records (and concepts) are listed as úkony of their own.
    if (inspection.status === 'finalized') {
      return {
        started: true,
        done: true,
        text: `Uzavretá · ${defects}`,
        documentId: inspection.document_id,
      };
    }
    return { started: true, done: false, text: `Rozpracované · ${defects}`, documentId: null };
  }
  const items = plural(inspection.item_count, 'položka', 'položky', 'položiek');
  const done = inspection.status === 'finalized';
  // A concept from a prehliadka is not a protocol: it waits for the technician, and
  // holding it for the end of the visit would only make „generovať všetky" skip it.
  if (!done && inspection.concept_state === 'pending') {
    return { started: true, done: false, text: `Koncept z prehliadky · ${items} · čaká na potvrdenie`, documentId: null };
  }
  if (!done && inspection.concept_state === 'confirmed') {
    return {
      started: true,
      done: inspection.deferred,
      text: inspection.deferred ? HELD_TEXT : `Koncept z prehliadky · ${items} · potvrdený`,
      documentId: null,
    };
  }
  if (!done && inspection.deferred) return { started: true, done: true, text: HELD_TEXT, documentId: null };
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
  const params = new URLSearchParams({
    company_id: String(visit.company_id),
    facility_id: String(visit.facility_id),
    visit_id: String(visit.id),
    executed_on: visit.visit_date,
  });
  // POapp 26. 10. 2026 (D2) — „Kniha kontrol BOZP" starts a preventívna
  // prehliadka preset to BOZP; its record is the kniha BOZP entry. A prehliadka
  // already begun under the visit is continued, whichever of the two was ticked.
  if (type === PREHLIADKA_TYPE || type === 'kniha_bozp') {
    const own = visitInspectionOfType(visit, type);
    if (own) return type === PREHLIADKA_TYPE ? prehliadkaPath(own.id) : `/inspections/${own.id}`;
    const begun = visitInspectionOfType(visit, PREHLIADKA_TYPE);
    if (begun) return prehliadkaPath(begun.id);
    if (type === 'kniha_bozp') params.set('preset', 'bozp');
    return `${PREHLIADKA_NEW_PATH}?${params.toString()}`;
  }
  const existing = visitInspectionOfType(visit, type);
  if (existing) return `/inspections/${existing.id}`;
  return `/inspections/new/${type}/step-1?${params.toString()}`;
}

/** The first úkon of the visit that has no protocol yet, or null when all are done. */
export function nextUnfinishedType(visit: Visit): VisitType | null {
  return visitUkonTypes(visit).find((type) => !visitUkonState(visit, type).done) ?? null;
}

/** Where to go after finishing an úkon: the next unfinished úkon, else null. */
export function nextUkonPath(visit: Visit): string | null {
  const next = nextUnfinishedType(visit);
  return next ? visitUkonPath(visit, next) : null;
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
