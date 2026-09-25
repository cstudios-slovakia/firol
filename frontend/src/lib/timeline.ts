import type { CalendarDeadline } from '@/api/calendar';
import { daysUntil, openDeadlines } from '@/lib/calendarGrouping';
import type { Section } from '@/lib/sections';

/**
 * Časová os termínov — chapter 19.
 *
 * Another view of the same deadlines the calendar shows (it works with no new
 * data): the open client termíny, ordered by the nearest due date and grouped
 * by month, city or firm. Whatever the grouping, everything po termíne forms
 * the first group on its own.
 *
 * The position of a row is decided by the predpripravený termín (due date),
 * not by a planned visit date — the timeline answers „which firm is due when";
 * the planned date is shown on the row and lives in the calendar.
 */

export type TimelineGroupBy = 'mesiac' | 'mesto' | 'firma';

export type TimelineGroup = {
  key: string;
  label: string;
  /** Secondary line — the cities of a firm, or the firms of a city. */
  sublabel: string | null;
  overdue: boolean;
  items: CalendarDeadline[];
};

const MONTHS = [
  'Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún',
  'Júl', 'August', 'September', 'Október', 'November', 'December',
];

/** Nearest due date first; ties by firm name so the order is stable. */
function byDue(a: CalendarDeadline, b: CalendarDeadline): number {
  return (
    a.due_date.localeCompare(b.due_date) ||
    a.company_name.localeCompare(b.company_name, 'sk') ||
    a.key.localeCompare(b.key)
  );
}

/**
 * The timeline's rows: open deadlines only (the fulfilled ones the calendar
 * greys out for a year back are history, not upcoming work), narrowed by odbor
 * and by the technician filter.
 */
export function timelineItems(
  deadlines: CalendarDeadline[],
  section: 'all' | Section,
  matchesTechnician: (id: number | null | undefined) => boolean,
): CalendarDeadline[] {
  return openDeadlines(deadlines)
    .filter((d) => section === 'all' || d.section === section)
    .filter((d) => matchesTechnician(d.technician?.id))
    .sort(byDue);
}

/**
 * Group the rows (chapter 19): „Po termíne" first whenever anything is overdue,
 * regardless of the grouping; the rest by month of the due date (default), by
 * the prevádzka's city or by firm — groups ordered by their nearest termín.
 */
export function groupTimeline(items: CalendarDeadline[], by: TimelineGroupBy): TimelineGroup[] {
  const overdue = items.filter((d) => d.state === 'po_termine');
  const rest = items.filter((d) => d.state !== 'po_termine');

  const map = new Map<string, TimelineGroup>();
  for (const d of rest) {
    let key: string;
    let label: string;
    if (by === 'mesiac') {
      key = `m${d.due_date.slice(0, 7)}`;
      label = `${MONTHS[Number(d.due_date.slice(5, 7)) - 1]} ${d.due_date.slice(0, 4)}`;
    } else if (by === 'mesto') {
      key = d.facility_city ? `o${d.facility_city.toLocaleLowerCase('sk')}` : 'o-none';
      label = d.facility_city ?? 'Bez obce';
    } else {
      key = `f${d.company_id}`;
      label = d.company_name;
    }
    let g = map.get(key);
    if (!g) {
      g = { key, label, sublabel: null, overdue: false, items: [] };
      map.set(key, g);
    }
    g.items.push(d);
  }

  const groups = [...map.values()];
  for (const g of groups) {
    g.items.sort(byDue);
    if (by !== 'mesiac') {
      const others = [
        ...new Set(
          g.items
            .map((d) => (by === 'firma' ? d.facility_city : d.company_name))
            .filter((v): v is string => !!v),
        ),
      ];
      g.sublabel = others.length > 0 ? others.join(', ') : null;
    }
  }
  groups.sort((a, b) => byDue(a.items[0], b.items[0]) || a.label.localeCompare(b.label, 'sk'));

  if (overdue.length > 0) {
    groups.unshift({
      key: 'po-termine',
      label: 'Po termíne',
      sublabel: null,
      overdue: true,
      items: [...overdue].sort(byDue),
    });
  }
  return groups;
}

/**
 * Countdown to the due date, Slovak plurals: „dnes", „o 1 deň", „o 3 dni",
 * „o 12 dní", „pred 1 dňom", „pred 5 dňami".
 */
export function countdownLabel(dueDate: string): string {
  const days = daysUntil(dueDate);
  if (days === 0) return 'dnes';
  if (days > 0) {
    if (days === 1) return 'o 1 deň';
    if (days < 5) return `o ${days} dni`;
    return `o ${days} dní`;
  }
  const n = -days;
  return n === 1 ? 'pred 1 dňom' : `pred ${n} dňami`;
}
