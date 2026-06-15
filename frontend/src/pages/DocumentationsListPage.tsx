import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { FileStack, Plus, Building2, CalendarDays } from 'lucide-react';
import { Documentations, type DocumentationListItem } from '@/api/documentations';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';

export function DocumentationsListPage() {
  const [items, setItems] = useState<DocumentationListItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Documentations.list()
      .then((res) => { if (!cancelled) setItems(res.items); })
      .catch((err) => { if (!cancelled) { setItems([]); setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať dokumentáciu.'); } });
    return () => { cancelled = true; };
  }, []);

  return (
    <div className="flex flex-col gap-5">
      <header className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">Dokumentácia PO</h1>
          <p className="mt-0.5 text-sm text-ink-500">Kompletná dokumentácia ochrany pred požiarmi pre firmu.</p>
        </div>
        <Link to="/documentation/new" className="inline-flex h-11 shrink-0 items-center gap-2 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] transition-colors hover:bg-firol-600">
          <Plus className="size-4" /> Nová
        </Link>
      </header>

      {items === null && (
        <div className="flex justify-center py-10 text-ink-400"><Spinner /></div>
      )}

      {items !== null && items.length === 0 && (
        <Card className="flex flex-col items-center gap-3 px-6 py-12 text-center">
          <span className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
            <FileStack className="size-6" />
          </span>
          <h2 className="text-base font-semibold text-ink-900">Zatiaľ žiadna dokumentácia</h2>
          <p className="max-w-xs text-sm text-ink-500">{error ?? 'Vytvor prvú dokumentáciu PO — vyber firmu a doplň pár údajov.'}</p>
          <Link to="/documentation/new" className="inline-flex h-11 items-center gap-2 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600">
            <Plus className="size-4" /> Nová dokumentácia
          </Link>
        </Card>
      )}

      {items !== null && items.length > 0 && (
        <div className="flex flex-col gap-2">
          {items.map((d) => (
            <Link key={d.id} to={`/documentation/${d.id}`}
              className="group flex items-center gap-3 rounded-2xl border border-ink-100 bg-white px-4 py-3.5 transition-[background-color,transform] duration-150 hover:bg-ink-50 hover:-translate-y-px">
              <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-500">
                <FileStack className="size-5" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium text-ink-900">{d.company_name}{d.facility_name ? ` — ${d.facility_name}` : ''}</p>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-ink-500">
                  <span className="inline-flex items-center gap-1"><Building2 className="size-3.5" /> {d.author_name ?? '—'}</span>
                  {d.issued_on && <span className="inline-flex items-center gap-1"><CalendarDays className="size-3.5" /> {new Date(d.issued_on).toLocaleDateString('sk-SK')}</span>}
                </div>
              </div>
              <div className="flex shrink-0 items-center gap-2">
                {d.documents_count > 0 && <Badge tone="neutral">{d.documents_count}× PDF</Badge>}
                {d.status === 'finalized'
                  ? <Badge tone="ok">Hotová</Badge>
                  : <Badge tone="warn">Koncept</Badge>}
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
