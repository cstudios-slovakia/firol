/**
 * Zahodiť rozrobenú prehliadku (POapp spec 7. 11. 2026, ch. 11.1).
 *
 * Discarding is the archive every other úkon uses (DELETE /api/inspections/{id}).
 * It is offered only for a draft that was never closed: a prehliadka closed
 * once and reopened with „Upraviť" issued records before (the PP record, the
 * kniha BOZP record, concepts, tasks), and discarding the draft would leave
 * them without their source. Never closed means:
 *
 *   - the server says so (`inspection.closed_before === false` on show(); it
 *     also refuses the archive of a re-opened prehliadka), and
 *   - no follow-up úkon points back at it and no task was made from its defects.
 *
 * Online-only: the decision above needs the server's answer, and a discarded
 * draft must vanish from the prevádzka at once, not after a sync.
 */
import { useEffect, useState } from 'react';
import { Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { useMemberRights } from '@/auth/useMemberRights';
import { Inspections, type InspectionDetail } from '@/api/inspections';
import { Tasks } from '@/api/tasks';
import { OfflineQueuedError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { useConfirm } from '@/lib/confirm';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { useOnlineStatus } from '@/lib/useOnlineStatus';
import { PREHLIADKA_TYPE } from '@/lib/prehliadka';
import { Button } from '@/components/ui/Button';

/** True when the prehliadka was never closed and nothing issued points back at it. */
function neverClosed(detail: InspectionDetail, taskCount: number): boolean {
  return detail.inspection.closed_before === false && (detail.follow_ups ?? []).length === 0 && taskCount === 0;
}

/**
 * Whether the draft `inspectionId` may be discarded here, and the action.
 * `detail` saves a request when the screen already loaded the prehliadka.
 */
export function useDiscardPrehliadka(
  inspectionId: number | null,
  onDiscarded: (facilityId: number) => void,
  detail?: InspectionDetail | null,
) {
  const { csrfToken } = useAuth();
  const isReadOnly = useIsReadOnly();
  const { canDeleteUkon } = useMemberRights();
  const confirm = useConfirm();
  const toast = useToast();
  const online = useOnlineStatus();

  const [checked, setChecked] = useState<{ id: number; detail: InspectionDetail; allowed: boolean } | null>(null);
  const [discarding, setDiscarding] = useState(false);

  useEffect(() => {
    // A draft made offline and not synced yet has a temporary negative id.
    if (inspectionId === null || inspectionId <= 0 || isReadOnly) {
      setChecked(null);
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        // Only a show() answer carries `closed_before`; any other detail is re-read.
        const d =
          detail && detail.inspection.id === inspectionId && typeof detail.inspection.closed_before === 'boolean'
            ? detail
            : await Inspections.show(inspectionId);
        const tasks = await Tasks.forInspection(inspectionId);
        if (cancelled) return;
        const i = d.inspection;
        const allowed =
          i.type === PREHLIADKA_TYPE &&
          i.status === 'draft' &&
          canDeleteUkon(i, i.inspector_user_id) &&
          neverClosed(d, tasks.items.length);
        setChecked({ id: inspectionId, detail: d, allowed });
      } catch {
        // Without the server's answer the draft is not offered for discarding.
        if (!cancelled) setChecked(null);
      }
    })();
    return () => {
      cancelled = true;
    };
    // canDeleteUkon is rebuilt every render; the account it reads is in useAuth.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [inspectionId, detail, isReadOnly]);

  const allowed = checked !== null && checked.id === inspectionId && checked.allowed;

  async function discard() {
    if (!checked || !allowed) return;
    const facilityId = checked.detail.inspection.facility_id;
    if (!online) {
      toast.error('Zahodiť prehliadku sa dá len s pripojením na internet.');
      return;
    }
    const ok = await confirm({
      title: 'Zahodiť rozrobenú prehliadku?',
      description: 'Prehliadka sa zahodí aj so zapísanými nedostatkami. Nevznikne z nej žiadny záznam.',
      confirmLabel: 'Zahodiť',
    });
    if (!ok) return;
    setDiscarding(true);
    try {
      await Inspections.archive(checked.id, csrfToken);
    } catch (err) {
      // The connection dropped between the check and the request: the outbox
      // took the archive and sends it on reconnect.
      if (!(err instanceof OfflineQueuedError)) {
        toast.error(offlineMessage(err, 'Prehliadku sa nepodarilo zahodiť.'));
        setDiscarding(false);
        return;
      }
    }
    setDiscarding(false);
    toast.success('Prehliadka zahodená');
    onDiscarded(facilityId);
  }

  return { allowed, discarding, online, discard };
}

/** The button itself; renders nothing when the draft may not be discarded. */
export function DiscardPrehliadkaButton({
  action,
  className,
  size = 'md',
}: {
  action: ReturnType<typeof useDiscardPrehliadka>;
  className?: string;
  size?: 'sm' | 'md';
}) {
  if (!action.allowed) return null;
  return (
    <Button
      type="button"
      id="pp-discard"
      variant="danger-outline"
      size={size}
      className={cn('animate-fade-in', className)}
      loading={action.discarding}
      leftIcon={<Trash2 className="size-4" />}
      onClick={() => void action.discard()}
    >
      Zahodiť prehliadku
    </Button>
  );
}
