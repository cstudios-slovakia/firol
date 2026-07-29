import { Link } from 'react-router-dom';
import { AlertTriangle, CalendarClock, ChevronRight, Clock } from 'lucide-react';
import type { CalendarDeadline } from '@/api/calendar';
import type { InspectionType } from '@/api/inspections';
import {
  bucketForDays,
  daysUntil,
  groupByFacility,
  type DeadlineBucket,
  type FacilityDeadlineGroup,
} from '@/lib/calendarGrouping';
import { Card } from '@/components/ui/Card';

/**
 * Compact per-type labels for the deadline chips — abbreviations of
 * INSPECTION_TYPE_LABELS, never a different name for the same type.
 */
export const INSPECTION_TYPE_SHORT: Record<InspectionType, string> = {
  php: 'PHP',
  hydranty: 'Hydranty',
  oprava_ts_php: 'Oprava/TS',
  poziarna_kniha: 'Požiarna kniha',
  pu_akcieschopnost: 'PU — akcieschopnosť',
  pu_udrzba: 'PU — údržba',
  nudzove_osvetlenie: 'Núdzové osvetlenie',
  ts_hadic: 'TS hadíc',
  vyradenie: 'Vyradenie PHP',
};

const BUCKET_META: Record<DeadlineBucket, { label: string; dot: string; text: string }> = {
  overdue: { label: 'Po termíne', dot: 'bg-status-bad', text: 'text-status-bad' },
  soon: { label: 'Do 30 dní', dot: 'bg-status-warn', text: 'text-status-warn' },
  upcoming: { label: 'O 31 až 60 dní', dot: 'bg-ink-400', text: 'text-ink-500' },
};

/**
 * "Termíny" block for the dashboard (change request 2.5.1). Shows facilities
 * whose nearest statutory deadline falls within 60 days, in three ordered
 * buckets. Further-out deadlines live only in the full calendar.
 */
export function DeadlinesBlock({
  deadlines,
  loaded,
}: {
  deadlines: CalendarDeadline[];
  loaded: boolean;
}) {
  // Filter before grouping: a facility's chips must list only the controls this
  // block is about. Grouping the raw list would chip every control the facility
  // has — a deadline a year out would then read as "po termíne" because the row
  // is bucketed by the facility's nearest deadline.
  const inWindow = deadlines.filter((d) => bucketForDays(daysUntil(d.statutory_date)) !== null);
  const groups = groupByFacility(inWindow);
  const buckets: Record<DeadlineBucket, FacilityDeadlineGroup[]> = {
    overdue: [],
    soon: [],
    upcoming: [],
  };
  for (const g of groups) {
    const b = bucketForDays(g.nearestDays);
    if (b) buckets[b].push(g);
  }
  const hasAny = buckets.overdue.length + buckets.soon.length + buckets.upcoming.length > 0;

  return (
    <Card className="overflow-hidden p-0">
      <div className="flex items-center justify-between gap-3 border-b border-ink-100 px-4 py-3">
        <Link to="/kalendar" className="flex items-center gap-2.5 transition-opacity hover:opacity-70">
          <span className="grid size-8 place-items-center rounded-xl bg-firol-100 text-firol-600">
            <CalendarClock className="size-4" />
          </span>
          <span className="text-sm font-semibold text-ink-900">Termíny</span>
        </Link>
        <Link to="/kalendar" className="text-xs font-medium text-firol-600 hover:text-firol-700">
          Kalendár →
        </Link>
      </div>

      {!loaded ? (
        <p className="px-4 py-4 text-sm text-ink-400">Načítavam…</p>
      ) : !hasAny ? (
        <p className="px-4 py-6 text-center text-sm text-ink-400">
          Žiadne termíny v najbližších 60 dňoch.
        </p>
      ) : (
        <div className="flex flex-col divide-y divide-ink-50">
          {(['overdue', 'soon', 'upcoming'] as const).map((b) =>
            buckets[b].length > 0 ? (
              <div key={b} className="px-4 py-3">
                <div className="mb-2 flex items-center gap-2">
                  <span className={`size-2 rounded-full ${BUCKET_META[b].dot}`} />
                  <span className={`text-xs font-semibold uppercase tracking-wider ${BUCKET_META[b].text}`}>
                    {BUCKET_META[b].label}
                  </span>
                  <span className="text-xs text-ink-400">({buckets[b].length})</span>
                </div>
                <div className="flex flex-col gap-1.5">
                  {buckets[b].map((g) => (
                    <FacilityRow key={g.facility_id} group={g} bucket={b} />
                  ))}
                </div>
              </div>
            ) : null,
          )}
        </div>
      )}
    </Card>
  );
}

function FacilityRow({ group, bucket }: { group: FacilityDeadlineGroup; bucket: DeadlineBucket }) {
  const days = group.nearestDays;
  const when =
    bucket === 'overdue'
      ? `meškanie ${Math.abs(days)} dní`
      : days === 0
        ? 'dnes'
        : `o ${days} dní`;
  return (
    <Link
      to={`/kalendar?den=${group.nearestDate}`}
      className="flex items-center gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-ink-50"
    >
      <span className="mt-0.5 shrink-0 text-ink-300">
        {bucket === 'overdue' ? (
          <AlertTriangle className="size-4 text-status-bad" />
        ) : (
          <Clock className="size-4 text-ink-400" />
        )}
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-ink-900">
          {group.company_name} — {group.facility_name}
        </p>
        <div className="mt-1 flex flex-wrap gap-1">
          {group.deadlines.map((d) => (
            <span
              key={d.inspection_id}
              className="rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] text-ink-600"
            >
              {INSPECTION_TYPE_SHORT[d.type]}
            </span>
          ))}
        </div>
      </div>
      <span className={`shrink-0 text-xs font-medium ${BUCKET_META[bucket].text}`}>{when}</span>
      <ChevronRight className="size-4 shrink-0 text-ink-300" />
    </Link>
  );
}
