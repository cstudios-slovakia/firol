import { useEffect, useState } from 'react';
import { FileText } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Stock, formatStockDate, type StockMovement } from '@/api/stock';
import { documentDownloadUrl } from '@/api/inspections';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Spinner } from '@/components/ui/Spinner';

/**
 * „Vystaviť výdajku" — block 4 / chapter 21.
 *
 * A výdajka lists the material handed to one client on one day, so the
 * použitie that opened this dialog is offered together with the others of the
 * same client and day that are not on a výdajka yet (the mockup's výdajka has
 * several lines). All are ticked; the technician can untick what belongs on a
 * separate one. The date printed is the date of those movements.
 */
export function StockIssueDialog({
  open,
  onClose,
  movementId,
  onIssued,
}: {
  open: boolean;
  onClose: () => void;
  movementId: number | null;
  onIssued?: () => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [items, setItems] = useState<StockMovement[] | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open || movementId === null) return;
    let cancelled = false;
    setItems(null);
    setError(null);
    Stock.issuable(movementId)
      .then((res) => {
        if (cancelled) return;
        setItems(res.items);
        setSelected(new Set(res.items.map((m) => m.id)));
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(offlineMessage(err, 'Materiál na výdajku sa nepodarilo načítať.'));
      });
    return () => {
      cancelled = true;
    };
  }, [open, movementId]);

  function toggle(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  async function submit() {
    if (selected.size === 0) {
      setError('Vyber materiál, ktorý má byť na výdajke.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await Stock.issue([...selected], csrfToken);
      toast.success(`Výdajka ${res.document.number} vystavená.`);
      window.open(documentDownloadUrl(res.document.id), '_blank', 'noopener');
      onIssued?.();
      onClose();
    } catch (err) {
      setError(offlineMessage(err, 'Výdajku sa nepodarilo vystaviť.'));
    } finally {
      setSaving(false);
    }
  }

  const first = items?.[0];

  return (
    <Dialog
      open={open}
      onClose={() => {
        if (!saving) onClose();
      }}
      title="Vystaviť výdajku"
      description="Tlačiteľný doklad so zoznamom vydaného materiálu a podpismi vydal / prevzal."
      dismissible={!saving}
    >
      <div className="flex flex-col gap-4">
        {items === null && !error && (
          <div className="flex justify-center py-6">
            <Spinner />
          </div>
        )}

        {first && (
          <div className="rounded-2xl bg-ink-50 px-3 py-2.5 text-xs text-ink-600">
            <span className="font-medium text-ink-800">{first.company_name}</span> ·{' '}
            {formatStockDate(first.created_at)}
          </div>
        )}

        {items && items.length > 0 && (
          <ul className="flex flex-col divide-y divide-ink-100 overflow-hidden rounded-2xl border border-ink-100">
            {items.map((m) => (
              <li key={m.id}>
                <label className="flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors hover:bg-ink-50">
                  <input
                    type="checkbox"
                    checked={selected.has(m.id)}
                    onChange={() => toggle(m.id)}
                    className="size-4 accent-firol-500"
                  />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-ink-900">{m.item_name}</p>
                    {m.note && <p className="truncate text-xs text-ink-500">{m.note}</p>}
                  </div>
                  <span className="shrink-0 text-sm tabular-nums text-ink-700">
                    {m.qty} {m.unit}
                  </span>
                </label>
              </li>
            ))}
          </ul>
        )}

        {error && <p className="text-sm text-status-bad">{error}</p>}

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Zrušiť
          </Button>
          <Button
            onClick={submit}
            loading={saving}
            disabled={!items || items.length === 0}
            leftIcon={<FileText className="size-4" />}
          >
            Vystaviť výdajku
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
