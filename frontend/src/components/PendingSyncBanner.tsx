import { useState } from 'react';
import { CloudOff } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { useToast } from '@/lib/toast';
import { usePendingMutations } from '@/lib/usePendingMutations';
import { entityPendingMutations, type EntityRef } from '@/lib/pendingEntity';
import { isTempId } from '@/lib/tempId';
import { probeConnectivity } from '@/lib/connectivity';
import { drainQueue } from '@/lib/queue';

/**
 * Banner shown on a detail screen whose record (or its children) hasn't synced
 * yet — e.g. an inspection created while offline. The data itself is rendered
 * from the local cache; this just makes the "waiting to save" state explicit
 * and offers a manual "Uložiť" that re-checks the connection before syncing.
 *
 * Hidden entirely once there's nothing pending for the entity.
 */
export function PendingSyncBanner({ resource, id }: EntityRef) {
  const pending = usePendingMutations();
  const toast = useToast();
  const [saving, setSaving] = useState(false);

  const matches = entityPendingMutations(pending, { resource, id });
  // A temp id always implies an unsynced create, even if the outbox query
  // hasn't caught up yet on first paint.
  if (matches.length === 0 && !isTempId(id)) return null;

  async function handleSave() {
    setSaving(true);
    try {
      const online = await probeConnectivity();
      if (!online) {
        toast.error('Stále nie si pripojený na internet. Skús to znova, keď budeš online.');
        return;
      }
      const res = await drainQueue();
      if (res.networkError) {
        toast.error('Údaje sa nepodarilo synchronizovať — skontroluj pripojenie na internet.');
      } else if (res.failed > 0) {
        toast.error('Niektoré zmeny sa nepodarilo uložiť. Pozri zoznam čakajúcich zmien v hlavičke.');
      } else {
        toast.success('Údaje boli uložené.');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card className="flex flex-col gap-3 border-status-warn/30 bg-[var(--color-status-warn-bg)]/40 p-4 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-start gap-3">
        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-white text-status-warn">
          <CloudOff className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-ink-900">
            Čaká na uloženie pri opätovnom pripojení k internetu
          </p>
          <p className="text-xs text-ink-600">
            Zmeny sú bezpečne uložené v zariadení a odošlú sa automaticky, keď budeš online.
          </p>
        </div>
      </div>
      <Button type="button" loading={saving} onClick={handleSave} className="shrink-0">
        Uložiť
      </Button>
    </Card>
  );
}
