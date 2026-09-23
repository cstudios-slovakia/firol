import type {
  CalendarData,
  CalendarDeadline,
  CalendarEvent,
  OwnTerm,
  TerminState,
  TerminTechnician,
  TerminZdroj,
} from '@/api/calendar';
import type { Section } from '@/lib/sections';

/**
 * Shared calendar math (change request 2.5, chapter 11). The due date decides
 * whether a deadline is po termíne; the planned date only decides which day it
 * renders on.
 */

/** Whole days from today until `iso` (negative = past). */
export function daysUntil(iso: string): number {
  const target = new Date(iso + 'T00:00:00');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((target.getTime() - today.getTime()) / 86_400_000);
}

export type DeadlineBucket = 'overdue' | 'soon' | 'upcoming';

/** Bucket by days-until due date; null = further than 60 days out. */
export function bucketForDays(days: number): DeadlineBucket | null {
  if (days < 0) return 'overdue';
  if (days <= 30) return 'soon';
  if (days <= 60) return 'upcoming';
  return null;
}

/** The day a deadline shows on — the planned date wins if set. */
export function effectiveDate(d: CalendarDeadline): string {
  return d.planned_date ?? d.due_date;
}

/** Open deadlines only (drops the fulfilled ones reported for a year back). */
export function openDeadlines(deadlines: CalendarDeadline[]): CalendarDeadline[] {
  return deadlines.filter((d) => d.state !== 'splneny');
}

export type FacilityDayGroup = {
  /** Stable React key — facility and day together identify the group. */
  key: string;
  facility_id: number;
  facility_name: string;
  company_id: number;
  company_name: string;
  /** Recipient of the group's client notice e-mail (11.3); null if unset. */
  company_email: string | null;
  /** The calendar day all deadlines in the group fall on. */
  date: string;
  deadlines: CalendarDeadline[];
  /** Nearest due date in the group (min days, may be negative). */
  nearestDays: number;
};

/**
 * Group deadlines by facility *and* calendar day: controls collapse into one
 * row only when they are due at the same prevádzka on the same day — which is
 * also the unit of one client notice e-mail.
 */
export function groupByFacilityDay(deadlines: CalendarDeadline[]): FacilityDayGroup[] {
  const map = new Map<string, FacilityDayGroup>();
  for (const d of deadlines) {
    const date = effectiveDate(d);
    const key = `${d.facility_id}@${date}`;
    let g = map.get(key);
    if (!g) {
      g = {
        key,
        facility_id: d.facility_id,
        facility_name: d.facility_name,
        company_id: d.company_id,
        company_name: d.company_name,
        company_email: d.company_email,
        date,
        deadlines: [],
        nearestDays: Infinity,
      };
      map.set(key, g);
    }
    g.deadlines.push(d);
    g.nearestDays = Math.min(g.nearestDays, daysUntil(d.due_date));
  }
  for (const g of map.values()) {
    g.deadlines.sort((a, b) => daysUntil(a.due_date) - daysUntil(b.due_date));
  }
  return [...map.values()].sort((a, b) => a.nearestDays - b.nearestDays || a.date.localeCompare(b.date));
}

// ── Chapter 11 — one list of every kind of termín ────────────────────────────

/**
 * A termín of any zdroj, normalised for display and filtering: the calendar
 * and the Časová os render the same rows from the same fields.
 */
export type Termin = {
  key: string;
  zdroj: TerminZdroj;
  /** Day it shows on (planned date for a planned deadline). */
  date: string;
  state: TerminState;
  /** Odbor — null for a vlastná udalosť. */
  section: Section | null;
  technician: TerminTechnician | null;
  /** Null for the technician's own terms and events without a firm. */
  company_id: number | null;
  company_name: string | null;
  facility_name: string | null;
  city: string | null;
  deadline?: CalendarDeadline;
  event?: CalendarEvent;
  own?: OwnTerm;
};

/**
 * Flattens the calendar payload. Own terms get the signed-in technician as
 * their technician, so „Len moje" keeps them and another member hides them.
 */
export function toTerminy(
  data: CalendarData,
  me: TerminTechnician | null,
): Termin[] {
  const out: Termin[] = [];
  for (const d of data.deadlines) {
    out.push({
      key: d.key,
      zdroj: 'kontrola',
      date: effectiveDate(d),
      state: d.state,
      section: d.section,
      technician: d.technician,
      company_id: d.company_id,
      company_name: d.company_name,
      facility_name: d.facility_name,
      city: d.facility_city,
      deadline: d,
    });
  }
  for (const e of data.events) {
    out.push({
      key: e.key,
      zdroj: 'vlastny',
      date: e.event_date,
      state: 'planovany',
      section: null,
      technician: e.technician,
      company_id: e.company_id,
      company_name: e.company_name,
      facility_name: e.facility_name,
      city: e.facility_city,
      event: e,
    });
  }
  for (const t of data.own_terms) {
    out.push({
      key: t.key,
      zdroj: 'technik',
      date: t.date,
      state: t.state,
      section: t.section,
      technician: me,
      company_id: null,
      company_name: null,
      facility_name: null,
      city: null,
      own: t,
    });
  }
  return out;
}

const STATE_ORDER: Record<TerminState, number> = { po_termine: 0, planovany: 1, splneny: 2 };

/** Po termíne first (11.1), then by day, done ones last. */
export function compareTerminy(a: Termin, b: Termin): number {
  return STATE_ORDER[a.state] - STATE_ORDER[b.state] || a.date.localeCompare(b.date);
}

export type TerminGroup = {
  key: string;
  label: string;
  /** Secondary line — the city of a firm, or the firms of a city. */
  sublabel: string | null;
  items: Termin[];
  overdue: number;
  /** Odbory present in the group, for the colour dots. */
  sections: Section[];
};

export type GroupBy = 'firma' | 'mesto';

/**
 * Group the termíny of one view (11.1): by firm — a firm with four termíny in
 * the month is ONE group with a count — or by the prevádzka's city. Groups
 * holding anything po termíne come first; items inside are ordered by
 * {@link compareTerminy}. The technician's own terms are not grouped here —
 * they are shown apart from client termíny.
 */
export function groupTerminy(items: Termin[], by: GroupBy): TerminGroup[] {
  const map = new Map<string, TerminGroup>();
  for (const t of items) {
    const key =
      by === 'firma'
        ? t.company_id !== null ? `c${t.company_id}` : 'c-none'
        : t.city ? `m${t.city.toLocaleLowerCase('sk')}` : 'm-none';
    const label =
      by === 'firma' ? t.company_name ?? 'Bez firmy' : t.city ?? 'Bez obce';
    let g = map.get(key);
    if (!g) {
      g = { key, label, sublabel: null, items: [], overdue: 0, sections: [] };
      map.set(key, g);
    }
    g.items.push(t);
    if (t.state === 'po_termine') g.overdue++;
    if (t.section && !g.sections.includes(t.section)) g.sections.push(t.section);
  }
  for (const g of map.values()) {
    g.items.sort(compareTerminy);
    const others = [
      ...new Set(
        g.items
          .map((t) => (by === 'firma' ? t.city : t.company_name))
          .filter((v): v is string => !!v),
      ),
    ];
    g.sublabel = others.length > 0 ? others.join(', ') : null;
  }
  return [...map.values()].sort(
    (a, b) =>
      (b.overdue > 0 ? 1 : 0) - (a.overdue > 0 ? 1 : 0) ||
      (a.items.every((t) => t.state === 'splneny') ? 1 : 0) -
        (b.items.every((t) => t.state === 'splneny') ? 1 : 0) ||
      a.items[0].date.localeCompare(b.items[0].date) ||
      a.label.localeCompare(b.label, 'sk'),
  );
}
