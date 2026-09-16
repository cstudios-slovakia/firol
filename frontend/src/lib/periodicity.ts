/**
 * Periodicity — block 1 / chapter 5.
 *
 * The rule the whole feature rests on: the app SUGGESTS a period, the
 * technician decides it. Lehoty depend on the type of building, its
 * environment and the operator's own decision, so the app never presents one
 * as statutory — the phrase „zákonný termín" appears nowhere in the UI or on a
 * protocol, and the responsibility for the number stays with the person who
 * signs it.
 *
 * `null` value + `null` unit means „bez opakovania": the úkon happened once and
 * no next term follows from it. Every type supports it, and every type also
 * supports a free value in days, weeks or months — some firms breath-test
 * weekly, others once a year, and both are their own call.
 */

export type PeriodicityUnit = 'den' | 'tyzden' | 'mesiac';

export type Periodicity = {
  value: number | null;
  unit: PeriodicityUnit | null;
};

export const PERIODICITY_NONE: Periodicity = { value: null, unit: null };

export const PERIODICITY_UNIT_LABELS: Record<PeriodicityUnit, string> = {
  den: 'dní',
  tyzden: 'týždňov',
  mesiac: 'mesiacov',
};

/**
 * Values offered as chips, per inspection type, in the order they appear; the
 * first is preselected. An empty list means the type starts at „bez
 * opakovania" — never that it is forbidden a period.
 *
 * Mirrors `Periodicity::RECOMMENDED_MONTHS` on the server. The word
 * "recommended" is load-bearing: it is what the app offers, not what the law
 * requires.
 */
export const RECOMMENDED_MONTHS: Record<string, number[]> = {
  php: [24, 12],
  oprava_ts_php: [],
  vyradenie: [],
  hydranty: [12],
  ts_hadic: [12],
  poziarna_kniha: [12, 6, 3],
  pu_akcieschopnost: [3, 6, 12],
  pu_udrzba: [12],
  nudzove_osvetlenie: [12],
};

/** The period the app offers first for a type, before any history is known. */
export function defaultPeriodicity(type: string): Periodicity {
  const months = RECOMMENDED_MONTHS[type] ?? [];
  return months.length > 0 ? { value: months[0], unit: 'mesiac' } : PERIODICITY_NONE;
}

/** Slovak plural of the unit for a given count. */
export function unitWord(n: number, unit: PeriodicityUnit): string {
  switch (unit) {
    case 'den':
      return n === 1 ? 'deň' : n < 5 ? 'dni' : 'dní';
    case 'tyzden':
      return n === 1 ? 'týždeň' : n < 5 ? 'týždne' : 'týždňov';
    default:
      return n === 1 ? 'mesiac' : n < 5 ? 'mesiace' : 'mesiacov';
  }
}

/**
 * Label for a list row or a summary — „12 mesiacov", „2 týždne". Without
 * recurrence the app says „podľa potreby"; it never qualifies the period as
 * statutory, or says who decided it.
 */
export function periodicityLabel(p: Periodicity): string {
  if (p.value === null || p.unit === null) return 'podľa potreby';
  return `${p.value} ${unitWord(p.value, p.unit)}`;
}

/** Compact form for a dense list — „12 mes.", „2 týž.", „—". */
export function periodicityShort(p: Periodicity): string {
  if (p.value === null || p.unit === null) return '—';
  const suffix = p.unit === 'den' ? 'dní' : p.unit === 'tyzden' ? 'týž.' : 'mes.';
  return `${p.value} ${suffix}`;
}

/** True when the pair is not one of the values the app offered for this type. */
export function isCustomPeriodicity(type: string, p: Periodicity): boolean {
  const recommended = RECOMMENDED_MONTHS[type] ?? [];
  if (p.value === null) return recommended.length > 0;
  return p.unit !== 'mesiac' || !recommended.includes(p.value);
}

/**
 * Date the úkon stays valid until — `platnosť do`. Null when there is no
 * recurrence, or when no execution date has been entered yet.
 *
 * Month arithmetic clamps rather than overflowing: 31. 1. plus one month is
 * 28. 2., not 3. 3. A deadline that jumps into the next month would put the
 * control in the wrong bucket in the calendar.
 */
export function validUntil(executedOn: string | null, p: Periodicity): string | null {
  if (!executedOn || p.value === null || p.unit === null) return null;
  const base = new Date(`${executedOn}T00:00:00`);
  if (Number.isNaN(base.getTime())) return null;

  const next = new Date(base);
  if (p.unit === 'den') {
    next.setDate(next.getDate() + p.value);
  } else if (p.unit === 'tyzden') {
    next.setDate(next.getDate() + p.value * 7);
  } else {
    const day = next.getDate();
    next.setDate(1);
    next.setMonth(next.getMonth() + p.value);
    const lastDay = new Date(next.getFullYear(), next.getMonth() + 1, 0).getDate();
    next.setDate(Math.min(day, lastDay));
  }
  const y = next.getFullYear();
  const m = String(next.getMonth() + 1).padStart(2, '0');
  const d = String(next.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/**
 * Days from today until the úkon runs out. Negative when already past due,
 * null when there is no next term to count down to.
 */
export function daysUntilNext(executedOn: string | null, p: Periodicity): number | null {
  const due = validUntil(executedOn, p);
  if (due === null) return null;
  const next = new Date(`${due}T00:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((next.getTime() - today.getTime()) / 86_400_000);
}
