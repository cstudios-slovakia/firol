import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Camera, ChevronRight, History } from 'lucide-react';
import { Prehliadka, type PreviousDefects as PreviousDefectsData } from '@/api/prehliadka';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import {
  PREVIOUS_STATUS_LABELS,
  defectFromPreviousPath,
  defectPath,
  previousAnswers,
  scopeApplies,
  type DefectFields,
  type PrehliadkaDetails,
  type PreviousStatus,
} from '@/lib/prehliadka';
import { Badge } from '@/components/ui/Badge';
import { DefectScopeBadge } from '@/components/prehliadka/ui';

type Props = {
  inspectionId: number;
  facilityId: number;
  details: PrehliadkaDetails;
  /** Defects of this prehliadka; the ones with `persists_from` answer a previous defect. */
  rows: { itemId: number; defect: DefectFields }[];
  canEdit: boolean;
};

const STATUS_TONE: Record<PreviousStatus | 'cakaa', 'ok' | 'bad' | 'neutral' | 'warn'> = {
  ok: 'ok',
  trva: 'bad',
  zanik: 'neutral',
  cakaa: 'warn',
};

/**
 * „Nedostatky z minulej prehliadky" (spec 6.5, 12.1) at the top of Krok 2: the
 * still open defects of the last prehliadka. Each row opens the same defect
 * screen as a new defect, prefilled from that defect — or the row that already
 * carries it over. A row stays „Čaká na overenie" until it is saved there with
 * a state; the prehliadka cannot be closed before every one has one.
 */
export function PreviousDefectsSection({ inspectionId, facilityId, details, rows, canEdit }: Props) {
  const [data, setData] = useState<PreviousDefectsData | null>(null);

  useEffect(() => {
    let cancelled = false;
    Prehliadka.previous(facilityId, inspectionId)
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch(() => {
        // Nothing to show is a valid state; the walk-through does not depend on it.
        if (!cancelled) setData({ source: null, defects: [] });
      });
    return () => {
      cancelled = true;
    };
  }, [facilityId, inspectionId]);

  // Only the defects of a record being done today: a BOZP-only defect has no
  // record to be written into in a PO-only prehliadka.
  const defects = useMemo(
    () => (data?.defects ?? []).filter((d) => scopeApplies(d.scope, details.scope)),
    [data, details.scope],
  );
  const answers = useMemo(() => previousAnswers(details.previous, rows), [details.previous, rows]);

  if (!data || !data.source || defects.length === 0) return null;
  const source = data.source;
  const waiting = defects.filter((d) => (answers.get(d.key)?.status ?? null) === null).length;

  return (
    <section className="flex flex-col gap-2 animate-fade-up" aria-label="Nedostatky z minulej prehliadky">
      <h2 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-500">
        <History className="size-3.5" />
        {source.kind === 'prehliadka'
          ? `Z prehliadky${source.executed_on ? ` ${formatDateSk(source.executed_on)}` : ''}`
          : `Zo záznamu v požiarnej knihe${source.executed_on ? ` ${formatDateSk(source.executed_on)}` : ''}`}
        <span className="font-normal normal-case tracking-normal text-ink-400">
          · {waiting === 0 ? 'všetko overené' : `${waiting} čaká na overenie`}
        </span>
      </h2>
      <ul className="flex flex-col gap-2">
        {defects.map((d) => {
          const answer = answers.get(d.key);
          const status = answer?.status ?? null;
          const tone = STATUS_TONE[status ?? 'cakaa'];
          const border =
            status === 'ok' ? 'border-l-status-ok' : status === 'trva' ? 'border-l-status-bad' : status === 'zanik' ? 'border-l-ink-300' : 'border-l-status-warn';
          const photos = d.photos?.length ?? 0;
          const body = (
            <div className="flex items-start gap-2">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-1.5">
                  <DefectScopeBadge scope={d.scope} />
                  <Badge tone={tone}>{status ? PREVIOUS_STATUS_LABELS[status] : 'Čaká na overenie'}</Badge>
                  {photos > 0 && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-600">
                      <Camera className="size-3" />
                      {photos}
                    </span>
                  )}
                </div>
                <p className="mt-1.5 text-sm font-semibold text-ink-900">{d.title}</p>
                {(d.where_text || d.deadline_date) && (
                  <p className="mt-0.5 text-xs text-ink-500">
                    {[d.where_text, d.deadline_date ? `termín bol ${formatDateSk(d.deadline_date)}` : null]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                )}
                {status === 'zanik' && answer?.reason && (
                  <p className="mt-1 text-xs text-ink-600">Dôvod: {answer.reason}</p>
                )}
              </div>
              {canEdit && <ChevronRight className="mt-1 size-4 shrink-0 text-ink-300" />}
            </div>
          );
          const cls = `block w-full rounded-2xl border border-l-4 border-ink-200 ${border} bg-white px-4 py-3 text-left`;
          return (
            <li key={d.key}>
              {canEdit ? (
                <Link
                  to={answer?.itemId != null ? defectPath(inspectionId, answer.itemId) : defectFromPreviousPath(inspectionId, d.key)}
                  className={`${cls} animate-fade-in transition-[border-color,background-color,transform,box-shadow] duration-200 hover:bg-firol-50/40 hover:shadow-[var(--shadow-soft)] active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300`}
                >
                  {body}
                </Link>
              ) : (
                <div className={cls}>{body}</div>
              )}
            </li>
          );
        })}
      </ul>
    </section>
  );
}
