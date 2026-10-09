import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { Visits, type Visit } from '@/api/visits';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/auth/AuthContext';
import { offlineMessage } from '@/lib/offline';
import { nextUkonPath } from '@/lib/visits';

type Ukon = { inspection_id: number } | { training_id: number };

/**
 * „Pokračovať bez protokolu" — hold the protocol of this úkon until the end of
 * the visit and move on to the next unfinished úkon (or back to the visit when
 * none is left). The protocol is issued later by „Generovať všetky protokoly".
 */
export function VisitContinueButton({
  visitId,
  ukon,
  disabled,
}: {
  visitId: number;
  ukon: Ukon;
  disabled?: boolean;
}) {
  const navigate = useNavigate();
  const { csrfToken } = useAuth();
  const [visit, setVisit] = useState<Visit | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Visits.show(visitId)
      .then((res) => {
        if (!cancelled) setVisit(res.visit);
      })
      .catch(() => {
        // Best-effort: without the visit the button still leads back to it.
      });
    return () => {
      cancelled = true;
    };
  }, [visitId]);

  // Nothing left besides this úkon → the label promises the way back.
  const isLast = visit !== null && nextUkonPath(withHeld(visit, ukon)) === null;

  async function onClick() {
    setBusy(true);
    setError(null);
    try {
      const res = await Visits.deferProtocol(visitId, { ...ukon, deferred: true }, csrfToken);
      navigate(nextUkonPath(res.visit) ?? `/visits/${visitId}`);
    } catch (e) {
      setError(offlineMessage(e, 'Protokol sa nepodarilo odložiť.'));
      setBusy(false);
    }
  }

  const held = visit !== null && isHeld(visit, ukon);

  return (
    <div className="flex flex-col gap-1.5">
      {held && (
        <p className="text-xs font-medium text-status-ok">Hotové — protokol čaká na koniec návštevy</p>
      )}
      <Button
        type="button"
        variant="secondary"
        onClick={onClick}
        loading={busy}
        disabled={disabled || busy}
        rightIcon={<ArrowRight className="size-4" />}
      >
        {isLast ? 'Späť na návštevu' : 'Pokračovať bez protokolu'}
      </Button>
      {error && <p className="text-xs text-status-bad">{error}</p>}
    </div>
  );
}

function isHeld(visit: Visit, ukon: Ukon): boolean {
  return 'inspection_id' in ukon
    ? visit.inspections.some((i) => i.id === ukon.inspection_id && i.deferred)
    : visit.trainings.some((t) => t.id === ukon.training_id && t.deferred);
}

/** The visit as it will look once this úkon is held, to tell whether it was the last one. */
function withHeld(visit: Visit, ukon: Ukon): Visit {
  return {
    ...visit,
    inspections: visit.inspections.map((i) =>
      'inspection_id' in ukon && i.id === ukon.inspection_id ? { ...i, deferred: true } : i,
    ),
    trainings: visit.trainings.map((t) =>
      'training_id' in ukon && t.id === ukon.training_id ? { ...t, deferred: true } : t,
    ),
  };
}
