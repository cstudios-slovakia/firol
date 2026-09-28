import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowRight, CheckCircle2, Route } from 'lucide-react';
import { Visits, type Visit } from '@/api/visits';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { nextUnfinishedType, visitTypeLabel, visitUkonPath, visitUkonPosition } from '@/lib/visits';

/**
 * „Ďalší úkon" — shown on the summary of a finished úkon that belongs to a
 * návšteva (chapter 29.2, step 5). It opens the next planned úkon that has no
 * protocol yet, exactly as „Začať" / „Pokračovať" on the visit screen would;
 * when every úkon is done it leads back to the visit instead, where the
 * protocols are generated together and sent.
 */
export function VisitNextUkon({ visitId }: { visitId: number }) {
  const navigate = useNavigate();
  const [visit, setVisit] = useState<Visit | null>(null);

  useEffect(() => {
    let cancelled = false;
    Visits.show(visitId)
      .then((res) => {
        if (!cancelled) setVisit(res.visit);
      })
      .catch(() => {
        // Best-effort: without the visit the card simply offers the way back.
      });
    return () => {
      cancelled = true;
    };
  }, [visitId]);

  const next = visit ? nextUnfinishedType(visit) : null;

  if (visit && next) {
    const { n, total } = visitUkonPosition(visit, next);
    return (
      <Card className="flex flex-col gap-3 border-firol-200 bg-firol-50/60 p-4">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-white text-firol-600">
            <Route className="size-4" />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-semibold text-ink-900">Úkon je hotový</p>
            <p className="mt-0.5 text-xs text-ink-600">
              Ďalej v návšteve: {visitTypeLabel(next)} (úkon {n} z {total}).
            </p>
          </div>
        </div>
        <Button
          type="button"
          onClick={() => navigate(visitUkonPath(visit, next))}
          rightIcon={<ArrowRight className="size-4" />}
        >
          Ďalší úkon
        </Button>
      </Card>
    );
  }

  return (
    <Card className="flex flex-col gap-3 border-firol-200 bg-firol-50/60 p-4">
      <div className="flex items-start gap-3">
        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-white text-status-ok">
          <CheckCircle2 className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-ink-900">Úkon je hotový</p>
          {visit && (
            <p className="mt-0.5 text-xs text-ink-600">
              Všetky úkony návštevy majú protokol — na návšteve ich pošleš klientovi.
            </p>
          )}
        </div>
      </div>
      <Link
        to={`/visits/${visitId}`}
        className="inline-flex h-11 items-center justify-center gap-1.5 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] transition-colors duration-200 hover:bg-firol-600"
      >
        Späť na návštevu
      </Link>
    </Card>
  );
}
