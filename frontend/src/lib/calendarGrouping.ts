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

export type FacilityDeadlineGroup = {
  facility_id: number;
  facility_name: string;
  company_id: number;
  company_name: string;
  deadlines: CalendarDeadline[];
  /** Nearest statutory deadline in the group (min days, may be negative). */
  nearestDays: number;
  /** Calendar day the nearest deadline renders on (planned date wins). */
  nearestDate: string;
};

/**
 * Group deadlines by facility (one row per prevádzka, listing all its
 * controls) — the grouping the spec asks for in both the Prehľad block and the
 * monthly view. Groups are sorted by their nearest statutory deadline.
 */
export function groupByFacility(deadlines: CalendarDeadline[]): FacilityDeadlineGroup[] {
  const map = new Map<number, FacilityDeadlineGroup>();
  for (const d of deadlines) {
    let g = map.get(d.facility_id);
    if (!g) {
      g = {
        facility_id: d.facility_id,
        facility_name: d.facility_name,
        company_id: d.company_id,
        company_name: d.company_name,
        deadlines: [],
        nearestDays: Infinity,
        nearestDate: effectiveDate(d),
      };
      map.set(d.facility_id, g);
    }
    g.deadlines.push(d);
    const days = daysUntil(d.statutory_date);
    if (days < g.nearestDays) {
      g.nearestDays = days;
      g.nearestDate = effectiveDate(d);
    }
  }
  for (const g of map.values()) {
    g.deadlines.sort((a, b) => daysUntil(a.statutory_date) - daysUntil(b.statutory_date));
  }
  return [...map.values()].sort((a, b) => a.nearestDays - b.nearestDays);
}
