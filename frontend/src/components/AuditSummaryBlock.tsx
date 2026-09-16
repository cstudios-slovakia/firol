import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ClipboardList, PencilLine } from 'lucide-react';
import { Audits, AUDIT_SCOPE_LABELS, type AuditView } from '@/api/audits';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';

/**
 * The audit as it reads on the summary screen — block 3 / chapter 15.
 *
 * An audit's 109 items are not a list of devices, so the per-item rows the
 * other types show would be a page of scrolling that says nothing. What
 * matters here is the same thing that matters on the protocol: how each
 * section came out, and what was found.
 *
 * The figures come from the server rather than being recomputed here, so the
 * screen and the PDF can never disagree about how many items were evaluated.
 */
export function AuditSummaryBlock({
  inspectionId,
  canEdit,
}: {
  inspectionId: number;
  canEdit: boolean;
}) {
  const [view, setView] = useState<AuditView | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Audits.show(inspectionId)
      .then((res) => {
        if (!cancelled) setView(res);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });
    return () => {
      cancelled = true;
    };
  }, [inspectionId]);

  if (failed) {
    return (
      <Card className="px-4 py-3 text-sm text-ink-500">
        Hodnotenie auditu sa nepodarilo načítať.
      </Card>
    );
  }

  if (!view) {
    return (
      <Card className="flex justify-center px-4 py-6 text-ink-400">
        <Spinner />
      </Card>
    );
  }

  const { summary, audit } = view;
  const remaining = summary.total - summary.answered;
  const evaluated = summary.vyhovuje + summary.nevyhovuje;

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-4 py-3">
        <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">
          Hodnotenie
          {audit.scope && ` · ${AUDIT_SCOPE_LABELS[audit.scope].toLowerCase()}`}
        </span>
        <span className="text-xs text-ink-500">
          Vyplnené {summary.answered} z {summary.total}
        </span>
      </div>

      <div className="grid grid-cols-3 divide-x divide-ink-100 border-b border-ink-100">
        <Figure label="Hodnotených" value={evaluated} />
        <Figure label="Vyhovuje" value={summary.vyhovuje} tone="ok" />
        <Figure label="Nevyhovuje" value={summary.nevyhovuje} tone={summary.nevyhovuje > 0 ? 'bad' : undefined} />
      </div>

      <ul className="divide-y divide-ink-100">
        {summary.sections.map((section) => (
          <li key={section.code} className="flex items-center gap-3 px-4 py-2.5">
            <span className="w-7 shrink-0 text-xs font-semibold text-ink-400">{section.code}</span>
            <span className={cn('min-w-0 flex-1 truncate text-sm', section.excluded ? 'text-ink-400 line-through' : 'text-ink-800')}>
              {section.name}
            </span>
            <span className="shrink-0 text-xs">
              {section.excluded ? (
                <span className="text-ink-400">neaplikovateľná</span>
              ) : section.unanswered > 0 ? (
                <span className="text-status-warn">{section.unanswered} nevyplnené</span>
              ) : section.nevyhovuje > 0 ? (
                <span className="text-status-bad">{section.nevyhovuje} nedostatok</span>
              ) : (
                <span className="text-status-ok">v poriadku</span>
              )}
            </span>
          </li>
        ))}
      </ul>

      {summary.defects.length > 0 && (
        <div className="border-t border-ink-100">
          <p className="px-4 pt-3 text-xs font-semibold uppercase tracking-wider text-ink-500">
            Zistené nedostatky
          </p>
          <ol className="flex flex-col gap-2 px-4 py-3">
            {summary.defects.map((defect) => (
              <li key={defect.item_id} className="flex gap-2 text-sm">
                <span className="w-9 shrink-0 text-xs font-semibold tabular-nums text-ink-400">
                  {defect.number}. {defect.section}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-ink-800">{defect.description}</span>
                  {defect.deadline && (
                    <span className="block text-xs text-ink-500">
                      Termín: {new Date(`${defect.deadline}T00:00:00`).toLocaleDateString('sk-SK')}
                    </span>
                  )}
                </span>
              </li>
            ))}
          </ol>
        </div>
      )}

      {canEdit && (
        <Link
          to={`/inspections/${inspectionId}/audit`}
          className="flex items-center justify-center gap-1.5 border-t border-ink-100 px-4 py-3 text-sm font-medium text-firol-600 transition-colors hover:bg-firol-50"
        >
          {remaining > 0 ? <ClipboardList className="size-4" /> : <PencilLine className="size-4" />}
          {remaining > 0 ? `Doplniť ${remaining} položiek` : 'Upraviť hodnotenie'}
        </Link>
      )}
    </Card>
  );
}

function Figure({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone?: 'ok' | 'bad';
}) {
  return (
    <div className="px-3 py-3 text-center">
      <p className="text-xs text-ink-500">{label}</p>
      <p
        className={cn(
          'mt-0.5 text-lg font-semibold tabular-nums',
          tone === 'ok' && 'text-status-ok',
          tone === 'bad' && 'text-status-bad',
          !tone && 'text-ink-900',
        )}
      >
        {value}
      </p>
    </div>
  );
}
