import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Building2, CalendarDays, ClipboardList, Plus, Warehouse,
} from 'lucide-react';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  type InspectionListItem,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';

export function InspectionsListPage() {
  const [items, setItems] = useState<InspectionListItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Inspections.list()
      .then((res) => {
        if (!cancelled) setItems(res.items);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať kontroly.');
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="flex flex-col gap-4">
      <header className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">Kontroly</h1>
          <p className="mt-0.5 text-sm text-ink-500">
            Všetky vykonané kontroly a rozpracované drafty.
          </p>
        </div>
        <Link
          to="/inspections/new"
          className="inline-flex h-10 items-center gap-1.5 rounded-2xl bg-firol-500 px-3 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
        >
          <Plus className="size-4" />
          Nová
        </Link>
      </header>

      {error && (
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      )}

      {!items && !error && (
        <div className="flex justify-center py-10 text-ink-400">
          <Spinner />
        </div>
      )}

      {items && items.length === 0 && (
        <Card className="flex flex-col items-center gap-3 px-6 py-12 text-center">
          <div className="grid size-14 place-items-center rounded-2xl bg-firol-50 text-firol-500">
            <ClipboardList className="size-6" />
          </div>
          <h2 className="text-base font-semibold text-ink-900">Zatiaľ žiadne kontroly</h2>
          <p className="max-w-xs text-sm text-ink-500">
            Začni výberom firmy a typu kontroly. Drafty zostávajú uložené,
            kým nevygeneruješ PDF protokol.
          </p>
          <Link
            to="/inspections/new"
            className="inline-flex h-11 items-center gap-1.5 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
          >
            <Plus className="size-4" />
            Nová kontrola
          </Link>
        </Card>
      )}

      {items && items.length > 0 && (
        <ul className="flex flex-col gap-2">
          {items.map((it) => (
            <li key={it.id}>
              <Link to={`/inspections/${it.id}`} className="block rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-firol-300">
                <Card className="px-4 py-3 transition-shadow hover:shadow-md">
                  <div className="flex items-start gap-3">
                    <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
                      <ClipboardList className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <h3 className="truncate text-sm font-semibold text-ink-900">
                          {INSPECTION_TYPE_LABELS[it.type]}
                        </h3>
                        <Badge tone={it.status === 'draft' ? 'warn' : 'ok'}>
                          {it.status === 'draft' ? 'Draft' : 'Hotová'}
                        </Badge>
                      </div>
                      <p className="mt-0.5 truncate text-xs text-ink-500">
                        <Building2 className="-mt-0.5 mr-1 inline size-3" />
                        {it.company_name}
                        <span className="mx-1.5 text-ink-300">·</span>
                        <Warehouse className="-mt-0.5 mr-1 inline size-3" />
                        {it.facility_name}
                      </p>
                      <p className="mt-0.5 text-[11px] text-ink-400">
                        <CalendarDays className="-mt-0.5 mr-1 inline size-3" />
                        {it.executed_on ?? '—'}
                        <span className="mx-1.5 text-ink-300">·</span>
                        {it.periodicity_months} mes.
                        <span className="mx-1.5 text-ink-300">·</span>
                        {it.inspector_name}
                      </p>
                    </div>
                  </div>
                </Card>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
