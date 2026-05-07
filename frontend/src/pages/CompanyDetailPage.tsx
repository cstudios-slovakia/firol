import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, ChevronRight, ClipboardList, Edit2, Hash, MapPin, Phone, Plus, Warehouse } from 'lucide-react';
import { Companies, type CompanyDetail } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';

export function CompanyDetailPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);

  const [data, setData] = useState<CompanyDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Companies.show(id)
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať firmu.');
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť na zoznam
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  const { company, facilities } = data;

  return (
    <div className="flex flex-col gap-5">
      <Link to="/" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť na zoznam
      </Link>

      <Card className="overflow-hidden">
        <div className="bg-gradient-to-br from-firol-50/60 to-transparent px-5 pt-5">
          <div className="flex items-start gap-3">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
              <Building2 className="size-5" />
            </div>
            <div className="min-w-0 flex-1">
              <h1 className="truncate text-lg font-semibold tracking-tight text-ink-900">
                {company.name}
              </h1>
              <p className="text-xs text-ink-500">
                {facilities.length} {plural(facilities.length, 'prevádzka', 'prevádzky', 'prevádzok')}
              </p>
            </div>
            <Link
              to={`/companies/${company.id}/edit`}
              aria-label="Upraviť"
              className="grid size-9 place-items-center rounded-2xl text-ink-500 transition-colors hover:bg-white hover:text-ink-700"
            >
              <Edit2 className="size-4" />
            </Link>
          </div>
        </div>

        <dl className="flex flex-col divide-y divide-ink-100 px-5 py-3 text-sm">
          {company.ico && (
            <DetailRow icon={<Hash className="size-4" />} label="IČO" value={company.ico} />
          )}
          {company.address && (
            <DetailRow icon={<MapPin className="size-4" />} label="Adresa" value={company.address} />
          )}
          {company.contact && (
            <DetailRow icon={<Phone className="size-4" />} label="Kontakt" value={company.contact} />
          )}
          {!company.ico && !company.address && !company.contact && (
            <div className="py-2 text-ink-400">Žiadne ďalšie údaje. Doplň ich úpravou firmy.</div>
          )}
        </dl>

        {facilities.length > 0 && (
          <div className="border-t border-ink-100 px-5 py-3">
            <Link
              to={`/inspections/new?company_id=${company.id}`}
              className="inline-flex h-10 items-center gap-1.5 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
            >
              <ClipboardList className="size-4" />
              Nová kontrola
            </Link>
          </div>
        )}
      </Card>

      <section>
        <header className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-ink-500">Prevádzky</h2>
          <Link
            to={`/companies/${company.id}/facilities/new`}
            className="inline-flex h-8 items-center gap-1 rounded-2xl bg-firol-500 px-3 text-xs font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
          >
            <Plus className="size-3.5" />
            Pridať prevádzku
          </Link>
        </header>

        {facilities.length === 0 ? (
          <Card className="flex flex-col items-center gap-2 px-4 py-8 text-center">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
              <Warehouse className="size-5" />
            </div>
            <p className="text-sm text-ink-700">Firma zatiaľ nemá prevádzky.</p>
            <p className="max-w-xs text-xs text-ink-500">
              Pridaj prvú prevádzku — napríklad sklad, výrobnú halu alebo pobočku.
            </p>
          </Card>
        ) : (
          <ul className="flex flex-col gap-2">
            {facilities.map((f) => (
              <li key={f.id}>
                <Link
                  to={`/facilities/${f.id}`}
                  className="block group"
                >
                  <Card className="flex items-center gap-3 px-4 py-3 transition-shadow group-hover:shadow-[var(--shadow-lift)]">
                    <div className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
                      <Warehouse className="size-4" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <h3 className="truncate text-sm font-semibold text-ink-900">{f.name}</h3>
                      {f.address && (
                        <p className="truncate text-xs text-ink-500">{f.address}</p>
                      )}
                    </div>
                    <ChevronRight className="size-4 shrink-0 text-ink-300 transition-transform group-hover:translate-x-0.5 group-hover:text-ink-500" />
                  </Card>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function DetailRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="flex items-start gap-3 py-2.5">
      <span className="grid size-6 shrink-0 place-items-center text-ink-400">{icon}</span>
      <div className="flex-1">
        <dt className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">{label}</dt>
        <dd className="text-sm text-ink-800 break-words">{value}</dd>
      </div>
    </div>
  );
}

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one;
  if (n >= 2 && n <= 4) return few;
  return many;
}
