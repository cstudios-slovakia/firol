import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, Building2, CalendarDays, NotebookPen,
  Plus, Repeat, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type CompanyListItem, type FacilityListItem } from '@/api/companies';
import {
  INSPECTION_TYPE_LABELS,
  INSPECTION_TYPE_PERIODICITIES,
  Inspections,
  type InspectionType,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { inspectionCreateOptimistic } from '@/lib/offlineEntities';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { NewCompanyDialog } from '@/components/NewCompanyDialog';
import { NewFacilityDialog } from '@/components/NewFacilityDialog';
import { cn } from '@/lib/cn';

const KNOWN_TYPES: InspectionType[] = [
  'php', 'hydranty', 'oprava_ts_php', 'poziarna_kniha',
  'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie', 'ts_hadic',
];

function isInspectionType(s: string | undefined): s is InspectionType {
  return typeof s === 'string' && (KNOWN_TYPES as string[]).includes(s);
}

/**
 * Inspection — Step 1. Same screen for every type; per-type fields land
 * in Step 2. Per locked decision, the date is NEVER auto-prefilled.
 */
export function InspectionStep1Page() {
  const { user, csrfToken } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { type: typeParam } = useParams<{ type?: string }>();

  if (!isInspectionType(typeParam)) {
    return <UnknownTypeError />;
  }
  const type = typeParam;
  const allowedPeriodicities = INSPECTION_TYPE_PERIODICITIES[type];
  const periodicityFixed = allowedPeriodicities.length === 1;

  // Optional context coming from the company/facility detail screens.
  const presetCompanyId = numericParam(searchParams.get('company_id'));
  const presetFacilityId = numericParam(searchParams.get('facility_id'));

  const [companies, setCompanies] = useState<CompanyListItem[] | null>(null);
  const [facilities, setFacilities] = useState<FacilityListItem[]>([]);
  const [companyId, setCompanyId] = useState<number | null>(presetCompanyId);
  const [facilityId, setFacilityId] = useState<number | null>(presetFacilityId);
  const [executedOn, setExecutedOn] = useState('');
  const [periodicity, setPeriodicity] = useState<number>(allowedPeriodicities[0]);
  const [notes, setNotes] = useState('');
  // Tracks whether the user has manually picked a periodicity. We only
  // auto-prefill from the facility's history when they haven't, so the
  // sensible default doesn't keep overriding their choice.
  const [periodicityTouched, setPeriodicityTouched] = useState(false);

  const [loadingCompanies, setLoadingCompanies] = useState(true);
  const [loadingFacilities, setLoadingFacilities] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{ company?: string; facility?: string; date?: string }>({});
  const [newCompanyOpen, setNewCompanyOpen] = useState(false);
  const [newFacilityOpen, setNewFacilityOpen] = useState(false);

  // Initial company list. Pulls a generous page (200) — enough for the
  // typical technician's client base; pagination/search lands in Phase 5.
  useEffect(() => {
    let cancelled = false;
    Companies.list()
      .then((res) => {
        if (cancelled) return;
        setCompanies(res.items);
        if (presetCompanyId === null && res.items.length === 1) {
          setCompanyId(res.items[0].id);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať firmy.');
      })
      .finally(() => {
        if (!cancelled) setLoadingCompanies(false);
      });
    return () => {
      cancelled = true;
    };
  }, [presetCompanyId]);

  // Whenever the picked company changes, reload its facilities. Reset the
  // facility selection unless the preset already targets this company.
  useEffect(() => {
    if (companyId === null) {
      setFacilities([]);
      setFacilityId(null);
      return;
    }
    let cancelled = false;
    setLoadingFacilities(true);
    Companies.show(companyId)
      .then((res) => {
        if (cancelled) return;
        setFacilities(res.facilities);
        setFacilityId((current) => {
          if (current !== null && res.facilities.some((f) => f.id === current)) {
            return current;
          }
          if (presetFacilityId !== null && res.facilities.some((f) => f.id === presetFacilityId)) {
            return presetFacilityId;
          }
          return res.facilities.length === 1 ? res.facilities[0].id : null;
        });
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať prevádzky.');
      })
      .finally(() => {
        if (!cancelled) setLoadingFacilities(false);
      });
    return () => {
      cancelled = true;
    };
  }, [companyId, presetFacilityId]);

  // Prefill periodicity from this facility's history. Only kicks in for
  // types where the user actually has a choice (allowedPeriodicities > 1)
  // and only as long as they haven't manually picked a value yet — so
  // returning to a different facility within the same flow can still
  // refresh the suggestion, but typing then switching won't surprise
  // them. Phase 5b: "default periodicities per facility per type".
  useEffect(() => {
    if (periodicityFixed || periodicityTouched || facilityId === null) return;
    const facility = facilities.find((f) => f.id === facilityId);
    const last = facility?.last_periodicities?.[type];
    if (typeof last === 'number' && allowedPeriodicities.includes(last)) {
      setPeriodicity(last);
    }
  }, [facilityId, facilities, type, allowedPeriodicities, periodicityFixed, periodicityTouched]);

  const ctaText = useMemo(() => stepTwoCta(type), [type]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    const errs: typeof fieldErrors = {};
    if (!companyId) errs.company = 'Vyber firmu.';
    if (!facilityId) errs.facility = 'Vyber prevádzku.';
    if (!executedOn) errs.date = 'Zadaj dátum vykonania kontroly.';
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs);
      return;
    }
    setFieldErrors({});
    setError(null);
    setSubmitting(true);
    try {
      const payload = {
        type,
        periodicity_months: periodicity,
        executed_on: executedOn,
        company_id: companyId!,
        facility_id: facilityId!,
        notes: notes.trim() || undefined,
      };
      const company = (companies ?? []).find((c) => c.id === companyId);
      const facility = facilities.find((f) => f.id === facilityId);
      // Optimistic draft so the inspection (and Step 2) work offline; ignored
      // entirely when the create POST reaches the server.
      const optimistic = inspectionCreateOptimistic({
        payload,
        company: { id: companyId!, name: company?.name ?? '', ico: company?.ico ?? null },
        facility: { id: facilityId!, name: facility?.name ?? '' },
        inspector: { id: user?.id ?? 0, name: user?.fullname ?? '' },
      });
      const res = await Inspections.createDraft(payload, csrfToken, optimistic);
      // Move straight into Step 2 — adding the first prístroj. The
      // inspection itself is already persisted at this point.
      toast.success('Kontrola vytvorená');
      navigate(`/inspections/${res.inspection.id}/items/new`, { replace: true });
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  const backHref = presetFacilityId
    ? `/facilities/${presetFacilityId}`
    : presetCompanyId
      ? `/companies/${presetCompanyId}`
      : '/inspections/new';

  if (loadingCompanies) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  if (companies !== null && companies.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="flex flex-col items-center gap-3 px-6 py-10 text-center">
          <h2 className="text-base font-semibold text-ink-900">Najprv pridaj firmu</h2>
          <p className="max-w-xs text-sm text-ink-500">
            Kontrola sa viaže na konkrétnu firmu a jej prevádzku — nepôjde
            uložiť bez aspoň jednej z nich.
          </p>
          <Link
            to="/companies/new"
            className="inline-flex h-11 items-center rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
          >
            Pridať firmu
          </Link>
        </Card>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <Link to={backHref} className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť
      </Link>

      <header className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
            Krok 1 · základné údaje
          </p>
          <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
            {INSPECTION_TYPE_LABELS[type]}
          </h1>
        </div>
      </header>

      <Card className="p-5">
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field label="Spoločnosť" required error={fieldErrors.company}>
            {(p) => (
              <Select
                id={p.id}
                aria-invalid={p['aria-invalid']}
                value={companyId !== null ? String(companyId) : ''}
                onChange={(v) => { setCompanyId(v ? Number(v) : null); if (fieldErrors.company) setFieldErrors((prev) => ({ ...prev, company: undefined })); }}
                placeholder="— vyber firmu —"
                leftIcon={<Building2 className="size-4" />}
                searchable
                options={(companies ?? []).map((c) => ({
                  value: String(c.id),
                  label: c.name,
                  description: c.ico ? `IČO ${c.ico}` : undefined,
                }))}
                headerSlot={({ closeDropdown }) => (
                  <button
                    type="button"
                    onClick={() => {
                      closeDropdown();
                      setNewCompanyOpen(true);
                    }}
                    className="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium text-firol-700 transition-colors hover:bg-firol-50"
                  >
                    <span className="grid size-6 place-items-center rounded-lg bg-firol-500 text-white">
                      <Plus className="size-3.5" />
                    </span>
                    Pridať novú firmu
                  </button>
                )}
              />
            )}
          </Field>

          <Field label="Prevádzka" required error={fieldErrors.facility}>
            {(p) => (
              <Select
                id={p.id}
                aria-invalid={p['aria-invalid']}
                value={facilityId !== null ? String(facilityId) : ''}
                onChange={(v) => { setFacilityId(v ? Number(v) : null); if (fieldErrors.facility) setFieldErrors((prev) => ({ ...prev, facility: undefined })); }}
                disabled={companyId === null || loadingFacilities}
                placeholder={
                  companyId === null
                    ? '— najprv vyber firmu —'
                    : loadingFacilities
                      ? 'Načítavam…'
                      : '— vyber prevádzku —'
                }
                leftIcon={<Warehouse className="size-4" />}
                searchable
                options={facilities.map((f) => ({
                  value: String(f.id),
                  label: f.name,
                }))}
                headerSlot={companyId !== null ? ({ closeDropdown }) => (
                  <button
                    type="button"
                    onClick={() => {
                      closeDropdown();
                      setNewFacilityOpen(true);
                    }}
                    className="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium text-firol-700 transition-colors hover:bg-firol-50"
                  >
                    <span className="grid size-6 place-items-center rounded-lg bg-firol-500 text-white">
                      <Plus className="size-3.5" />
                    </span>
                    Pridať prevádzku
                  </button>
                ) : undefined}
              />
            )}
          </Field>

          <Field
            label="Dátum vykonania kontroly"
            required
            hint={fieldErrors.date ? undefined : 'Zadaj manuálne, nemusí byť dnešný dátum.'}
            error={fieldErrors.date}
          >
            {(p) => (
              <Input
                {...p}
                type="date"
                required
                leftIcon={<CalendarDays className="size-4" />}
                value={executedOn}
                onChange={(e) => { setExecutedOn(e.target.value); if (fieldErrors.date) setFieldErrors((prev) => ({ ...prev, date: undefined })); }}
              />
            )}
          </Field>

          <Field
            label="Periodicita"
            hint={periodicityFixed
              ? `Pre tento typ je periodicita pevná: ${allowedPeriodicities[0]} mesiacov.`
              : undefined}
          >
            {() => (
              <div className="flex gap-2" role="group" aria-label="Periodicita">
                {allowedPeriodicities.map((months) => {
                  const active = periodicity === months;
                  return (
                    <button
                      key={months}
                      type="button"
                      disabled={periodicityFixed && allowedPeriodicities.length === 1}
                      onClick={() => { setPeriodicity(months); setPeriodicityTouched(true); }}
                      className={cn(
                        'h-11 flex-1 rounded-xl border text-sm font-medium transition-colors',
                        active
                          ? 'border-firol-500 bg-firol-50 text-firol-700'
                          : 'border-ink-200 bg-white text-ink-700 hover:border-ink-300',
                        'disabled:opacity-70 disabled:cursor-default',
                      )}
                    >
                      <span className="flex items-center justify-center gap-1.5">
                        <Repeat className="size-4" />
                        {months} mesiacov
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
          </Field>

          <Field label="Kontrolu vykonal">
            {() => (
              <div className="flex h-11 items-center gap-3 rounded-xl border border-ink-200 bg-ink-50 px-3 text-sm text-ink-700">
                <Building2 className="size-4 text-ink-400" />
                <span className="truncate">{user?.fullname ?? 'Aktuálny používateľ'}</span>
                <span className="ml-auto text-[11px] text-ink-400">predvolené</span>
              </div>
            )}
          </Field>

          <Field label="Poznámky k prevádzke" hint="Napr. prístup, špeciálne podmienky.">
            {(p) => (
              <div className="relative">
                <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                  <NotebookPen className="size-4" />
                </span>
                <textarea
                  id={p.id}
                  rows={3}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Vchod cez recepciu, kód do skladu zmenil v 02/2026."
                  className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
                />
              </div>
            )}
          </Field>

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}
          {Object.keys(fieldErrors).length > 0 && (
            <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              Formulár obsahuje nevyplnené povinné polia.
            </p>
          )}

          <div className="flex justify-end pt-1">
            <Button
              type="submit"
              loading={submitting}
              rightIcon={<ArrowRight className="size-4" />}
            >
              {ctaText}
            </Button>
          </div>
        </form>
      </Card>

      <NewCompanyDialog
        open={newCompanyOpen}
        onClose={() => setNewCompanyOpen(false)}
        onCreated={(c) => {
          // Splice the new row into the list, fill in the
          // dashboard-only fields the create endpoint doesn't return,
          // and auto-select it.
          setCompanies((prev) => {
            const enriched: CompanyListItem = {
              id: c.id,
              name: c.name,
              ico: c.ico,
              address: c.address,
              street: c.street,
              postal_code: c.postal_code,
              city: c.city,
              contact: c.contact,
              facilities_count: 0,
              inspections_count: 0,
              last_inspection_at: null,
            };
            const next = prev ? [...prev, enriched] : [enriched];
            next.sort((a, b) => a.name.localeCompare(b.name));
            return next;
          });
          setCompanyId(c.id);
        }}
      />

      {companyId !== null && (
        <NewFacilityDialog
          open={newFacilityOpen}
          onClose={() => setNewFacilityOpen(false)}
          companyId={companyId}
          companyName={companies?.find((c) => c.id === companyId)?.name ?? ''}
          onCreated={(f) => {
            // Splice the new row into the list (last_periodicities empty —
            // history kicks in only after the first inspection) and
            // auto-select it.
            setFacilities((prev) => {
              const enriched: FacilityListItem = {
                id: f.id,
                name: f.name,
                address: f.address,
                street: f.street,
                postal_code: f.postal_code,
                city: f.city,
                contact_person: f.contact_person,
                notes: f.notes,
                last_periodicities: {},
              };
              const next = [...prev, enriched];
              next.sort((a, b) => a.name.localeCompare(b.name));
              return next;
            });
            setFacilityId(f.id);
          }}
        />
      )}
    </div>
  );
}

function UnknownTypeError() {
  return (
    <div className="flex flex-col gap-4">
      <Link to="/inspections/new" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť
      </Link>
      <Card className="flex flex-col items-center gap-2 px-6 py-10 text-center">
        <Warehouse className="size-6 text-ink-400" />
        <h2 className="text-base font-semibold text-ink-900">Neznámy typ kontroly</h2>
        <p className="max-w-xs text-sm text-ink-500">
          Tento typ ešte nie je dostupný — vyber iný z prehľadu.
        </p>
      </Card>
    </div>
  );
}

function numericParam(raw: string | null): number | null {
  if (raw === null || raw === '') return null;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function stepTwoCta(type: InspectionType): string {
  switch (type) {
    case 'php':
    case 'oprava_ts_php':
      return 'Pokračovať — zadanie prístrojov';
    case 'hydranty':
      return 'Pokračovať — zadanie hydrantov';
    case 'pu_akcieschopnost':
    case 'pu_udrzba':
      return 'Pokračovať — zadanie uzáverov';
    case 'ts_hadic':
      return 'Pokračovať — zadanie hadíc';
    case 'nudzove_osvetlenie':
      return 'Pokračovať — zadanie svietidiel';
    case 'poziarna_kniha':
    default:
      return 'Pokračovať — záznam činností';
  }
}

