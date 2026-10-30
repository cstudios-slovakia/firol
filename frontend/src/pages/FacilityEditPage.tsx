import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { Companies } from '@/api/companies';
import { Facilities, type Facility } from '@/api/facilities';
import { ApiError } from '@/lib/api';
import { facilityNouns, isBytoveDruzstvo, type ClientType } from '@/lib/clientKind';
import { Card } from '@/components/ui/Card';
import { FacilityForm } from '@/components/FacilityForm';
import { Spinner } from '@/components/ui/Spinner';

type Mode = { kind: 'create'; companyId: number } | { kind: 'edit'; facilityId: number };

function modeFromParams(params: { id?: string; companyId?: string }): Mode {
  if (params.companyId !== undefined) {
    return { kind: 'create', companyId: Number(params.companyId) };
  }
  return { kind: 'edit', facilityId: Number(params.id) };
}

/**
 * Standalone create and edit. The fields themselves live in FacilityForm,
 * shared with the quick-add dialog, so a bytový dom and a prevádzka stay
 * one form.
 */
export function FacilityEditPage() {
  const params = useParams<{ id?: string; companyId?: string }>();
  const mode = modeFromParams(params);
  const navigate = useNavigate();

  const [facility, setFacility] = useState<Facility | null>(null);
  const [clientType, setClientType] = useState<ClientType>('firma');
  const [companyAddress, setCompanyAddress] = useState<{
    street: string | null;
    postal_code: string | null;
    city: string | null;
  } | null>(null);
  const [companyId, setCompanyId] = useState<number | null>(
    mode.kind === 'create' ? mode.companyId : null,
  );
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const load = mode.kind === 'edit'
      ? Facilities.show(mode.facilityId).then((res) => {
          if (cancelled) return;
          setFacility(res.facility);
          setCompanyId(res.facility.company_id);
          setClientType(res.facility.company_client_type ?? 'firma');
        })
      : Companies.show(mode.companyId).then((res) => {
          if (cancelled) return;
          setClientType(res.company.client_type ?? 'firma');
          setCompanyAddress(res.company);
        });
    load
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať údaje.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [mode.kind, mode.kind === 'edit' ? mode.facilityId : mode.kind === 'create' ? mode.companyId : null]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (loading || window.location.hash !== '#popis-objektu') return;
    document.getElementById('popis-objektu')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, [loading]);

  const nouns = facilityNouns(clientType);
  const backHref = mode.kind === 'create'
    ? `/companies/${mode.companyId}`
    : `/facilities/${mode.facilityId}`;

  return (
    <div className="flex flex-col gap-4">
      <Link
        to={backHref}
        className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700"
      >
        <ArrowLeft className="size-4" />
        Späť
      </Link>

      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">
          {mode.kind === 'edit' ? nouns.editTitle : nouns.createTitle}
        </h1>
        <p className="mt-0.5 text-sm text-ink-500">
          {isBytoveDruzstvo(clientType)
            ? 'Konkrétny bytový dom.'
            : 'Konkrétna pobočka, sklad alebo objekt firmy.'}
        </p>
      </header>

      {loading ? (
        <div className="flex justify-center py-10 text-ink-400">
          <Spinner />
        </div>
      ) : error ? (
        <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">{error}</p>
      ) : (
        <Card className="p-5">
          <FacilityForm
            mode={mode.kind}
            initial={facility ?? undefined}
            companyId={companyId ?? undefined}
            companyAddress={companyAddress}
            clientType={clientType}
            onSaved={(saved) => navigate(`/facilities/${saved.id}`, { replace: true })}
            onCancel={() => navigate(backHref)}
          />
        </Card>
      )}
    </div>
  );
}
