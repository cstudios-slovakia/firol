import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Building2, Edit2, Plus, Search, ShieldCheck, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { useToast } from '@/lib/toast';
import { Companies, type CompanyListItem } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Pagination } from '@/components/ui/Pagination';
import { SkeletonList } from '@/components/ui/Skeleton';

const PAGE_SIZE = 10;

export function CompaniesPage() {
  const { isAdmin, csrfToken } = useAuth();
  const isReadOnly = useIsReadOnly();
  const toast = useToast();
  const [items, setItems] = useState<CompanyListItem[]>([]);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [status, setStatus] = useState<'idle' | 'loading' | 'error'>('loading');
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 200);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => {
    setPage(1);
  }, [debounced]);

  useEffect(() => {
    let cancelled = false;
    setStatus((s) => (s === 'idle' ? s : 'loading'));
    Companies.list(debounced || undefined)
      .then((res) => {
        if (cancelled) return;
        setItems(res.items);
        setStatus('idle');
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať firmy.');
        setStatus('error');
      });
    return () => {
      cancelled = true;
    };
  }, [debounced]);

  const totalFacilities = useMemo(
    () => items.reduce((acc, c) => acc + c.facilities_count, 0),
    [items],
  );

  const totalPages = Math.ceil(items.length / PAGE_SIZE);
  const paged = items.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  async function handleDelete() {
    if (pendingDeleteId === null) return;
    setDeleting(true);
    try {
      await Companies.archive(pendingDeleteId, csrfToken);
      setItems((prev) => prev.filter((c) => c.id !== pendingDeleteId));
      setPendingDeleteId(null);
      toast.success('Firma odstránená');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Nepodarilo sa odstrániť firmu.');
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">
            {isAdmin ? (
              <span className="flex items-center gap-2">
                Všetky firmy
                <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                  <ShieldCheck className="size-3" />
                  Admin
                </span>
              </span>
            ) : (
              'Firmy'
            )}
          </h1>
          <p className="mt-0.5 text-sm text-ink-500">
            {items.length === 0
              ? 'Žiadne firmy.'
              : `${items.length} ${plural(items.length, 'firma', 'firmy', 'firiem')} · ${totalFacilities} ${plural(totalFacilities, 'prevádzka', 'prevádzky', 'prevádzok')}`}
          </p>
        </div>
        {!isReadOnly && (
          <Link
            to="/companies/new"
            className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-2xl bg-firol-500 px-3 text-sm font-medium text-white shadow-[var(--shadow-glow)] transition-colors hover:bg-firol-600"
          >
            <Plus className="size-4" />
            Nová firma
          </Link>
        )}
      </header>

      <Card className="flex items-center gap-2 px-3 py-2">
        <Search className="size-4 shrink-0 text-ink-400" />
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Vyhľadať firmu alebo IČO"
          className="w-full bg-transparent text-sm text-ink-800 placeholder:text-ink-400 focus:outline-none"
          aria-label="Vyhľadávanie"
        />
      </Card>

      {status === 'loading' && items.length === 0 && (
        <SkeletonList variant="company" count={3} />
      )}

      {status === 'error' && error && (
        <Card className="border-status-bad/30 bg-[var(--color-status-bad-bg)]/50 px-4 py-3 text-sm text-[var(--color-status-bad)]">
          {error}
        </Card>
      )}

      {status !== 'loading' && items.length === 0 && !debounced && (
        <Card className="flex flex-col items-center gap-3 px-6 py-12 text-center">
          <div className="grid size-14 place-items-center rounded-2xl bg-firol-50 text-firol-500">
            <Building2 className="size-6" />
          </div>
          <h2 className="text-base font-semibold text-ink-900">Zatiaľ žiadne firmy</h2>
          <p className="max-w-xs text-sm text-ink-500">
            Pridaj prvú firmu, ku ktorej budeš zaznamenávať revízie a kontroly.
          </p>
          <Link
            to="/companies/new"
            className="mt-2 inline-flex h-11 items-center gap-2 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
          >
            <Plus className="size-4" />
            Pridať firmu
          </Link>
        </Card>
      )}

      {debounced && items.length === 0 && status !== 'loading' && (
        <Card className="px-4 py-6 text-center text-sm text-ink-500">
          Pre „{debounced}" nič nenájdené.
        </Card>
      )}

      {items.length > 0 && (
        <>
          <ul className="flex flex-col gap-2">
            {paged.map((c) => (
              <li key={c.id}>
                <CompanyRow
                  company={c}
                  showAccount={isAdmin}
                  isReadOnly={isReadOnly}
                  onDelete={setPendingDeleteId}
                />
              </li>
            ))}
          </ul>
          <Pagination
            page={page}
            totalPages={totalPages}
            totalItems={items.length}
            pageSize={PAGE_SIZE}
            onChange={setPage}
          />
        </>
      )}

      <Dialog
        open={pendingDeleteId !== null}
        onClose={() => {
          if (!deleting) setPendingDeleteId(null);
        }}
        title="Odstrániť firmu?"
        description="Táto akcia je nevratná. Firma bude odstránená spolu so všetkými prevádzkami."
        dismissible={!deleting}
      >
        <div className="flex justify-end gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setPendingDeleteId(null)}
            disabled={deleting}
          >
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

function CompanyRow({
  company,
  showAccount,
  isReadOnly,
  onDelete,
}: {
  company: CompanyListItem;
  showAccount?: boolean;
  isReadOnly: boolean;
  onDelete: (id: number) => void;
}) {
  const lastDate = company.last_inspection_at;
  const formattedLast = lastDate
    ? new Date(lastDate).toLocaleDateString('sk-SK', { day: 'numeric', month: 'numeric', year: 'numeric' })
    : null;

  return (
    <Card className="px-4 py-3">
      <div className="flex items-center gap-3">
        <Link
          to={`/companies/${company.id}`}
          className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)] transition-colors hover:bg-firol-600"
        >
          <Building2 className="size-5" />
        </Link>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <Link
              to={`/companies/${company.id}`}
              className="truncate text-sm font-semibold text-ink-900 transition-colors hover:text-firol-600"
            >
              {company.name}
            </Link>
            {showAccount && company.account_name && (
              <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-ink-200 bg-ink-50 px-2 py-0.5 text-[10px] text-ink-500">
                <ShieldCheck className="size-3 text-amber-500" />
                {company.account_name}
              </span>
            )}
          </div>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            {company.ico && (
              <>
                IČO {company.ico}
                <span className="mx-1.5 text-ink-300">·</span>
              </>
            )}
            {company.facilities_count}{' '}
            {plural(company.facilities_count, 'prevádzka', 'prevádzky', 'prevádzok')}
          </p>
          <p className="mt-0.5 truncate text-[11px] text-ink-400">
            {company.inspections_count === 0
              ? 'Žiadne kontroly'
              : `${company.inspections_count} ${plural(company.inspections_count, 'kontrola', 'kontroly', 'kontrol')}${formattedLast ? ` · posledná ${formattedLast}` : ''}`}
          </p>
        </div>

        {!isReadOnly && (
          <div className="flex shrink-0 items-center gap-3">
            <Link
              to={`/companies/${company.id}/edit`}
              title="Upraviť"
              aria-label="Upraviť"
              className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
            >
              <Edit2 className="size-4" />
            </Link>
            <button
              type="button"
              title="Odstrániť"
              aria-label="Odstrániť"
              onClick={() => onDelete(company.id)}
              className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
            >
              <Trash2 className="size-4" />
            </button>
          </div>
        )}
      </div>
    </Card>
  );
}

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one;
  if (n >= 2 && n <= 4) return few;
  return many;
}
