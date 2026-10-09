import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMemberRights } from '@/auth/useMemberRights';
import { Archive, ArchiveRestore, Building2, Edit2, Plus, Search, ShieldCheck } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { Companies, type CompanyListItem } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';
import { Pagination } from '@/components/ui/Pagination';
import { SkeletonList } from '@/components/ui/Skeleton';
import { ArchiveDialog, formatDay, useRestoreArchived } from '@/components/ClientArchive';

const PAGE_SIZE = 10;

export function CompaniesPage() {
  const { isAdmin } = useAuth();
  const isReadOnly = useIsReadOnly();
  const restoreArchived = useRestoreArchived();
  const [items, setItems] = useState<CompanyListItem[]>([]);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [status, setStatus] = useState<'idle' | 'loading' | 'error'>('loading');
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  // Spec 25 — archived firms show only behind the „aj archivované" filter.
  const [showArchived, setShowArchived] = useState(false);
  const [archiving, setArchiving] = useState<CompanyListItem | null>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 200);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => {
    setPage(1);
  }, [debounced, showArchived]);

  useEffect(() => {
    let cancelled = false;
    setStatus((s) => (s === 'idle' ? s : 'loading'));
    Companies.list(debounced || undefined, { archived: showArchived })
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
  }, [debounced, showArchived]);

  const activeItems = useMemo(() => items.filter((c) => !c.archived_at), [items]);
  const archivedCount = items.length - activeItems.length;
  const totalFacilities = useMemo(
    () => activeItems.reduce((acc, c) => acc + c.facilities_count, 0),
    [activeItems],
  );

  const totalPages = Math.ceil(items.length / PAGE_SIZE);
  const paged = items.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  /** With the filter on an archived row stays (greyed out), else it leaves the list. */
  function onArchived(id: number) {
    setArchiving(null);
    if (showArchived) {
      const today = new Date().toISOString().slice(0, 10);
      setItems((prev) => prev.map((c) => (c.id === id ? { ...c, archived_at: today } : c)));
    } else {
      setItems((prev) => prev.filter((c) => c.id !== id));
    }
  }

  async function onRestore(company: CompanyListItem) {
    if (!(await restoreArchived('company', company.id, company.name))) return;
    setItems((prev) =>
      prev.map((c) => (c.id === company.id ? { ...c, archived_at: null, archived_reason: null } : c)),
    );
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
            {activeItems.length === 0
              ? 'Žiadne aktívne firmy.'
              : `${activeItems.length} ${plural(activeItems.length, 'firma', 'firmy', 'firiem')} · ${totalFacilities} ${plural(totalFacilities, 'prevádzka', 'prevádzky', 'prevádzok')}`}
            {archivedCount > 0 &&
              ` · ${archivedCount} ${plural(archivedCount, 'archivovaná', 'archivované', 'archivovaných')}`}
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

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Card className="flex flex-1 items-center gap-2 px-3 py-2">
          <Search className="size-4 shrink-0 text-ink-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Vyhľadať firmu alebo IČO"
            className="w-full bg-transparent text-sm text-ink-800 placeholder:text-ink-400 focus:outline-none"
            aria-label="Vyhľadávanie"
          />
        </Card>
        <button
          type="button"
          id="companies-show-archived"
          role="switch"
          aria-checked={showArchived}
          onClick={() => setShowArchived((v) => !v)}
          className={cn(
            'inline-flex h-10 shrink-0 items-center gap-2 self-start rounded-2xl border px-3 text-sm font-medium transition-all duration-200 active:scale-[0.98] sm:self-auto',
            showArchived
              ? 'border-ink-300 bg-ink-100 text-ink-800'
              : 'border-ink-200 bg-white text-ink-600 hover:border-ink-300 hover:bg-ink-50',
          )}
        >
          <span
            aria-hidden
            className={cn(
              'relative h-5 w-9 rounded-full transition-colors duration-200',
              showArchived ? 'bg-ink-600' : 'bg-ink-200',
            )}
          >
            <span
              className={cn(
                'absolute top-0.5 size-4 rounded-full bg-white shadow transition-transform duration-200',
                showArchived ? 'translate-x-[18px]' : 'translate-x-0.5',
              )}
            />
          </span>
          Aj archivované
        </button>
      </div>

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
                  onArchive={setArchiving}
                  onRestore={onRestore}
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

      <ArchiveDialog
        open={archiving !== null}
        kind="company"
        id={archiving?.id ?? 0}
        name={archiving?.name ?? ''}
        onClose={() => setArchiving(null)}
        onArchived={() => archiving && onArchived(archiving.id)}
      />
    </div>
  );
}

function CompanyRow({
  company,
  showAccount,
  isReadOnly,
  onArchive,
  onRestore,
}: {
  company: CompanyListItem;
  showAccount?: boolean;
  isReadOnly: boolean;
  onArchive: (company: CompanyListItem) => void;
  onRestore: (company: CompanyListItem) => void;
}) {
  const { canDelete } = useMemberRights();
  const archived = Boolean(company.archived_at);
  const lastDate = company.last_inspection_at;
  const formattedLast = lastDate
    ? new Date(lastDate).toLocaleDateString('sk-SK', { day: 'numeric', month: 'numeric', year: 'numeric' })
    : null;

  return (
    <Card className={cn('px-4 py-3 transition-colors', archived && 'border-dashed bg-ink-50/70 shadow-none')}>
      <div className="flex items-center gap-3">
        <Link
          to={`/companies/${company.id}`}
          className={cn(
            'grid size-11 shrink-0 place-items-center rounded-2xl transition-colors',
            archived
              ? 'bg-ink-200 text-ink-500 hover:bg-ink-300'
              : 'bg-firol-500 text-white shadow-[var(--shadow-glow)] hover:bg-firol-600',
          )}
        >
          {archived ? <Archive className="size-5" /> : <Building2 className="size-5" />}
        </Link>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <Link
              to={`/companies/${company.id}`}
              className={cn(
                'truncate text-sm font-semibold transition-colors hover:text-firol-600',
                archived ? 'text-ink-500' : 'text-ink-900',
              )}
            >
              {company.name}
            </Link>
            {archived && (
              <span className="inline-flex shrink-0 items-center rounded-full bg-ink-200/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-600">
                Archivovaná
              </span>
            )}
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
            {company.archived_at && `archivovaná ${formatDay(company.archived_at)} · `}
            {company.inspections_count === 0
              ? 'Žiadne kontroly'
              : `${company.inspections_count} ${plural(company.inspections_count, 'kontrola', 'kontroly', 'kontrol')}${formattedLast ? ` · posledná ${formattedLast}` : ''}`}
          </p>
        </div>

        {!isReadOnly && (
          <div className="flex shrink-0 items-center gap-3">
            {!archived && (
              <Link
                to={`/companies/${company.id}/edit`}
                title="Upraviť"
                aria-label="Upraviť"
                className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
              >
                <Edit2 className="size-4" />
              </Link>
            )}
            {/* Spec 25 — a firm is archived, never deleted: its protocols stay. */}
            {canDelete &&
              (archived ? (
                <button
                  type="button"
                  title="Obnoviť"
                  aria-label="Obnoviť"
                  onClick={() => onRestore(company)}
                  className="grid size-8 place-items-center rounded-xl text-firol-600 transition-all duration-200 hover:bg-firol-50 active:scale-95"
                >
                  <ArchiveRestore className="size-4" />
                </button>
              ) : (
                <button
                  type="button"
                  title="Archivovať"
                  aria-label="Archivovať"
                  onClick={() => onArchive(company)}
                  className="grid size-8 place-items-center rounded-xl text-ink-500 transition-all duration-200 hover:bg-ink-100 hover:text-ink-700 active:scale-95"
                >
                  <Archive className="size-4" />
                </button>
              ))}
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
