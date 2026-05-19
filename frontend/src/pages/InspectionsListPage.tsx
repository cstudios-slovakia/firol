import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Building2, CalendarDays, ClipboardList, Edit2, Plus, Search, Trash2, Warehouse,
} from 'lucide-react';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  type InspectionListItem,
  type InspectionType,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { useAuth } from '@/auth/AuthContext';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { SkeletonList } from '@/components/ui/Skeleton';

const TYPE_CHIPS: [InspectionType, string][] = [
  ['rphp',              'RPHP'],
  ['hydranty',          'Hydranty'],
  ['oprava_ts_rphp',    'Oprava & TS'],
  ['poziarna_kniha',    'Pož. kniha'],
  ['pu_akcieschopnost', 'PU – akciesch.'],
  ['pu_udrzba',         'PU – údržba'],
  ['nudzove_osvetlenie','Nú. osvetlenie'],
  ['ts_hadic',          'TS hadíc'],
];

export function InspectionsListPage() {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [items, setItems] = useState<InspectionListItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState<InspectionType | ''>('');
  const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null);
  const [deleting, setDeleting] = useState(false);

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

  const filtered = useMemo(() => {
    if (!items) return null;
    const q = query.trim().toLowerCase();
    return items.filter((it) => {
      if (typeFilter && it.type !== typeFilter) return false;
      if (!q) return true;
      return (
        it.company_name.toLowerCase().includes(q) ||
        it.facility_name.toLowerCase().includes(q) ||
        it.inspector_name.toLowerCase().includes(q) ||
        INSPECTION_TYPE_LABELS[it.type].toLowerCase().includes(q)
      );
    });
  }, [items, query, typeFilter]);

  async function handleDelete() {
    if (pendingDeleteId === null) return;
    setDeleting(true);
    try {
      await Inspections.archive(pendingDeleteId, csrfToken);
      setItems((prev) => prev?.filter((i) => i.id !== pendingDeleteId) ?? null);
      setPendingDeleteId(null);
      toast.success('Kontrola odstránená');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Nepodarilo sa odstrániť kontrolu.');
    } finally {
      setDeleting(false);
    }
  }

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

      {items && items.length > 0 && (
        <div className="overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-xs transition-all focus-within:border-firol-300">
          <div className="flex items-center gap-2 px-3 py-2.5">
            <Search className="size-4 shrink-0 text-ink-400" />
            <input
              type="search"
              placeholder="Hľadať firmu, prevádzku, technika…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              className="min-w-0 flex-1 bg-transparent text-sm text-ink-900 placeholder:text-ink-400 outline-none"
            />
          </div>
          <div className="h-px bg-ink-100" />
          <div className="flex gap-1.5 overflow-x-auto px-3 py-2.5 [&::-webkit-scrollbar]:hidden">
            <button
              type="button"
              onClick={() => setTypeFilter('')}
              className={cn(
                'shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors',
                typeFilter === ''
                  ? 'bg-firol-500 text-white'
                  : 'bg-ink-100 text-ink-600 hover:bg-ink-200',
              )}
            >
              Všetky
            </button>
            {TYPE_CHIPS.map(([val, label]) => (
              <button
                key={val}
                type="button"
                onClick={() => setTypeFilter(typeFilter === val ? '' : val)}
                className={cn(
                  'shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors',
                  typeFilter === val
                    ? 'bg-firol-500 text-white'
                    : 'bg-ink-100 text-ink-600 hover:bg-ink-200',
                )}
              >
                {label}
              </button>
            ))}
          </div>
        </div>
      )}

      {error && (
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      )}

      {!items && !error && <SkeletonList count={4} />}

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

      {filtered && filtered.length === 0 && items && items.length > 0 && (
        <Card className="flex flex-col items-center gap-2 px-6 py-10 text-center">
          <Search className="size-6 text-ink-300" />
          <p className="text-sm text-ink-500">Žiadne kontroly nevyhovujú filtru.</p>
        </Card>
      )}

      {filtered && filtered.length > 0 && (
        <ul className="flex flex-col gap-2">
          {filtered.map((it) => (
            <li key={it.id}>
              <InspectionRow it={it} onDelete={setPendingDeleteId} />
            </li>
          ))}
        </ul>
      )}

      <Dialog
        open={pendingDeleteId !== null}
        onClose={() => { if (!deleting) setPendingDeleteId(null); }}
        title="Odstrániť kontrolu?"
        description="Táto akcia je nevratná. Kontrola bude trvalo odstránená spolu so všetkými položkami."
        dismissible={!deleting}
      >
        <div className="flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={() => setPendingDeleteId(null)} disabled={deleting}>
            Zrušiť
          </Button>
          <Button variant="danger" size="sm" loading={deleting} onClick={handleDelete}>
            Odstrániť
          </Button>
        </div>
      </Dialog>
    </div>
  );
}

function InspectionRow({
  it,
  onDelete,
}: {
  it: InspectionListItem;
  onDelete: (id: number) => void;
}) {
  return (
    <Card className="px-4 py-3">
      <div className="flex items-center gap-3">
        <Link
          to={`/inspections/${it.id}`}
          className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)] transition-colors hover:bg-firol-600"
        >
          <ClipboardList className="size-5" />
        </Link>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <Link
              to={`/inspections/${it.id}`}
              className="truncate text-sm font-semibold text-ink-900 transition-colors hover:text-firol-600"
            >
              {INSPECTION_TYPE_LABELS[it.type]}
            </Link>
            <Badge tone={it.status === 'draft' ? 'warn' : 'ok'} className="shrink-0">
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

        <div className="flex shrink-0 items-center gap-0.5">
          <Link
            to={`/inspections/${it.id}`}
            title="Upraviť"
            className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
          >
            <Edit2 className="size-4" />
          </Link>
          <button
            type="button"
            title="Odstrániť"
            onClick={() => onDelete(it.id)}
            className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
          >
            <Trash2 className="size-4" />
          </button>
        </div>
      </div>
    </Card>
  );
}
