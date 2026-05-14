import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Building2, ChevronRight, ClipboardList, Plus, Search } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type CompanyListItem } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { SkeletonList } from '@/components/ui/Skeleton';

/**
 * "Moje firmy" — landing screen after login. Per spec: header + count of
 * facilities, search bar, company cards with status badges. Status comes
 * from the most recent inspection; until inspections exist (Phase 3) every
 * card shows the neutral "Žiadne kontroly" badge.
 */
export function DashboardPage() {
  const { user } = useAuth();
  const [items, setItems] = useState<CompanyListItem[]>([]);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [status, setStatus] = useState<'idle' | 'loading' | 'error'>('loading');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 200);
    return () => clearTimeout(t);
  }, [search]);

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

  return (
    <div className="flex flex-col gap-5">
      <header className="flex items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">
            Ahoj, {user?.fullname.split(' ')[0]}
          </h1>
          <p className="mt-0.5 text-sm text-ink-500">
            {items.length === 0
              ? 'Pridaj prvú firmu aby si mohol začať s kontrolami.'
              : `${items.length} ${plural(items.length, 'firma', 'firmy', 'firiem')} · ${totalFacilities} ${plural(totalFacilities, 'prevádzka', 'prevádzky', 'prevádzok')}`}
          </p>
        </div>
        <Link
          to="/inspections/new"
          className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-2xl bg-firol-500 px-3 text-sm font-medium text-white shadow-[var(--shadow-glow)] transition-colors hover:bg-firol-600"
        >
          <ClipboardList className="size-4" />
          Nová kontrola
        </Link>
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
        <Link
          to="/companies/new"
          className="inline-flex h-7 shrink-0 items-center gap-1 rounded-full px-2 text-xs font-medium text-ink-500 hover:bg-ink-100 hover:text-ink-700"
          title="Pridať novú firmu"
        >
          <Plus className="size-3" />
          Firma
        </Link>
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
        <ul className="flex flex-col gap-3">
          {items.map((c) => (
            <li key={c.id}>
              <CompanyCard company={c} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function CompanyCard({ company }: { company: CompanyListItem }) {
  const lastDate = company.last_inspection_at;
  const formattedLast = lastDate
    ? new Date(lastDate).toLocaleDateString('sk-SK', { day: 'numeric', month: 'numeric', year: 'numeric' })
    : null;

  return (
    <Link to={`/companies/${company.id}`} className="block group">
      <Card className="px-4 py-3.5 transition-shadow group-hover:shadow-[var(--shadow-lift)]">
        <div className="flex items-start gap-3">
          <div className="grid size-10 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
            <Building2 className="size-5" />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-start justify-between gap-2">
              <h3 className="truncate text-sm font-semibold text-ink-900">{company.name}</h3>
              <ChevronRight className="size-4 shrink-0 text-ink-300 transition-transform group-hover:translate-x-0.5 group-hover:text-ink-500" />
            </div>
            <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-500">
              {company.ico && <span>IČO {company.ico}</span>}
              <span>·</span>
              <span>{company.facilities_count} {plural(company.facilities_count, 'prevádzka', 'prevádzky', 'prevádzok')}</span>
            </div>
            <div className="mt-2">
              {company.inspections_count === 0 ? (
                <Badge tone="neutral">Žiadne kontroly</Badge>
              ) : (
                <Badge tone="brand">
                  {company.inspections_count} {plural(company.inspections_count, 'kontrola', 'kontroly', 'kontrol')}
                  {formattedLast && ` · posledná ${formattedLast}`}
                </Badge>
              )}
            </div>
          </div>
        </div>
      </Card>
    </Link>
  );
}

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one;
  if (n >= 2 && n <= 4) return few;
  return many;
}
