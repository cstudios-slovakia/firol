import type { CalendarDeadline } from '@/api/calendar';

/**
 * Shared calendar math (change request 2.5). Statutory deadlines drive the
 * "po termíne / do 30 dní / 31–60 dní" buckets (a legal concept); the planned
 * date only decides which calendar day an event renders on.
 */

/** Whole days from today until `iso` (negative = past). */
export function daysUntil(iso: string): number {
  const target = new Date(iso + 'T00:00:00');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((target.getTime() - today.getTime()) / 86_400_000);
}

export type DeadlineBucket = 'overdue' | 'soon' | 'upcoming';

/** Bucket by days-until statutory date; null = further than 60 days out. */
export function bucketForDays(days: number): DeadlineBucket | null {
  if (days < 0) return 'overdue';
  if (days <= 30) return 'soon';
  if (days <= 60) return 'upcoming';
  return null;
}

/** The day an event shows on the calendar grid — planned date wins if set. */
export function effectiveDate(d: CalendarDeadline): string {
  return d.planned_date ?? d.statutory_date;
}

export type DayCompany = {
  company_id: number;
  company_name: string;
  /** True when at least one of the company's deadlines that day is past due. */
  overdue: boolean;
};

/**
 * Distinct companies with a deadline on a single day, for the monthly grid
 * cells (the grid names the companies rather than counting them).
 */
export function companiesForDay(deadlines: CalendarDeadline[]): DayCompany[] {
  const map = new Map<number, DayCompany>();
  for (const d of deadlines) {
    const overdue = daysUntil(d.statutory_date) < 0;
    const existing = map.get(d.company_id);
    if (existing) existing.overdue = existing.overdue || overdue;
    else map.set(d.company_id, { company_id: d.company_id, company_name: d.company_name, overdue });
  }
  return [...map.values()].sort((a, b) => a.company_name.localeCompare(b.company_name, 'sk'));
}

export type FacilityDayGroup = {
  /** Stable React key — facility and day together identify the group. */
  key: string;
  facility_id: number;
  facility_name: string;
  company_id: number;
  company_name: string;
  /** Recipient of the group's client notice e-mail (2.5.4); null if unset. */
  company_email: string | null;
  /** The calendar day all deadlines in the group fall on. */
  date: string;
  deadlines: CalendarDeadline[];
  /** Nearest statutory deadline in the group (min days, may be negative). */
  nearestDays: number;
};

/**
 * Group deadlines by facility *and* calendar day: controls collapse into one
 * row only when they are due at the same prevádzka on the same day. Deadlines
 * of the same facility falling on different days stay separate rows, so every
 * date the technician has to show up is visible on its own. Groups are sorted
 * by their nearest statutory deadline.
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
    g.nearestDays = Math.min(g.nearestDays, daysUntil(d.statutory_date));
  }
  for (const g of map.values()) {
    g.deadlines.sort((a, b) => daysUntil(a.statutory_date) - daysUntil(b.statutory_date));
  }
  return [...map.values()].sort((a, b) => a.nearestDays - b.nearestDays || a.date.localeCompare(b.date));
}
