import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  ArrowLeft, Building2, ClipboardList, Edit2, MapPin, NotebookPen, User, Warehouse,
} from 'lucide-react';
import { Facilities, type Facility } from '@/api/facilities';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';

export function FacilityDetailPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);

  const [facility, setFacility] = useState<Facility | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Facilities.show(id)
      .then((res) => {
        if (!cancelled) setFacility(res.facility);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať prevádzku.');
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
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }

  if (!facility) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <Link
        to={`/companies/${facility.company_id}`}
        className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
      >
        <ArrowLeft className="size-4" />
        Späť na firmu
      </Link>

      <Card className="overflow-hidden">
        <div className="bg-gradient-to-br from-firol-50/60 to-transparent px-5 pt-5">
          <div className="flex items-start gap-3">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
              <Warehouse className="size-5" />
            </div>
            <div className="min-w-0 flex-1">
              <h1 className="truncate text-lg font-semibold tracking-tight text-ink-900">
                {facility.name}
              </h1>
              {facility.company_name && (
                <Link
                  to={`/companies/${facility.company_id}`}
                  className="inline-flex items-center gap-1 text-xs text-ink-500 hover:text-firol-600"
                >
                  <Building2 className="size-3" />
                  {facility.company_name}
                </Link>
              )}
            </div>
            <Link
              to={`/facilities/${facility.id}/edit`}
              aria-label="Upraviť"
              className="grid size-9 place-items-center rounded-2xl text-ink-500 transition-colors hover:bg-white hover:text-ink-700"
            >
              <Edit2 className="size-4" />
            </Link>
          </div>
        </div>

        <dl className="flex flex-col divide-y divide-ink-100 px-5 py-3 text-sm">
          {facility.address && (
            <DetailRow icon={<MapPin className="size-4" />} label="Adresa" value={facility.address} />
          )}
          {facility.contact_person && (
            <DetailRow icon={<User className="size-4" />} label="Kontaktná osoba" value={facility.contact_person} />
          )}
          {facility.notes && (
            <DetailRow icon={<NotebookPen className="size-4" />} label="Poznámky" value={facility.notes} />
          )}
          {!facility.address && !facility.contact_person && !facility.notes && (
            <div className="py-2 text-ink-400">Žiadne ďalšie údaje. Doplň ich úpravou prevádzky.</div>
          )}
        </dl>
      </Card>

      <Card className="flex flex-col items-center gap-2 px-4 py-8 text-center">
        <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
          <ClipboardList className="size-5" />
        </div>
        <p className="text-sm text-ink-700">História kontrol bude tu.</p>
        <p className="max-w-xs text-xs text-ink-500">
          Po pridaní prvej kontroly sa tu zobrazí časová os revízií a školení.
        </p>
      </Card>
    </div>
  );
}

function DetailRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="flex items-start gap-3 py-2.5">
      <span className="grid size-6 shrink-0 place-items-center text-ink-400">{icon}</span>
      <div className="flex-1">
        <dt className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">{label}</dt>
        <dd className="text-sm text-ink-800 break-words whitespace-pre-wrap">{value}</dd>
      </div>
    </div>
  );
}
