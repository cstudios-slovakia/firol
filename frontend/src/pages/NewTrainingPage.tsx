import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, Building2, CalendarDays, GraduationCap, Plus,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type CompanyListItem, type FacilityListItem } from '@/api/companies';
import { Team, type TeamMember } from '@/api/team';
import {
  TRAINING_TYPES,
  TRAINING_TYPE_LABELS,
  TRAINING_TYPE_SHORT,
  Trainings,
  type TrainingType,
} from '@/api/trainings';
import { ApiError } from '@/lib/api';
import { trainingCreateOptimistic } from '@/lib/offlineEntities';
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

export function NewTrainingPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { csrfToken } = useAuth();
  const toast = useToast();

  const presetCompanyId = numericParam(searchParams.get('company_id'));
  const presetFacilityId = numericParam(searchParams.get('facility_id'));

  const { user } = useAuth();
  const [type, setType] = useState<TrainingType>('vstupne');
  const [companies, setCompanies] = useState<CompanyListItem[] | null>(null);
  const [facilities, setFacilities] = useState<FacilityListItem[]>([]);
  const [members, setMembers] = useState<TeamMember[] | null>(null);
  const [companyId, setCompanyId] = useState<number | null>(presetCompanyId);
  const [facilityId, setFacilityId] = useState<number | null>(presetFacilityId);
  const [trainerId, setTrainerId] = useState<number | null>(null);
  const [date, setDate] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<{ company?: string; date?: string }>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const [newCompanyOpen, setNewCompanyOpen] = useState(false);
  const [newFacilityOpen, setNewFacilityOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([Companies.list(), Team.list()])
      .then(([cs, tm]) => {
        if (cancelled) return;
        setCompanies(cs.items);
        const active = tm.items.filter((m) => m.is_active);
        setMembers(active);
        if (presetCompanyId === null && cs.items.length === 1) {
          setCompanyId(cs.items[0].id);
        }
        // Pre-select the logged-in user as trainer when possible.
        if (user && active.some((m) => m.id === user.id)) {
          setTrainerId(user.id);
        } else if (active.length === 1) {
          setTrainerId(active[0].id);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setApiError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať dáta.');
      });
    return () => {
      cancelled = true;
    };
  }, [presetCompanyId, user]);

  useEffect(() => {
    if (companyId === null) {
      setFacilities([]);
      setFacilityId(null);
      return;
    }
    let cancelled = false;
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
          return null;
        });
      })
      .catch(() => {
        // Soft-fail facility loading; the page can still submit without a facility.
      });
    return () => {
      cancelled = true;
    };
  }, [companyId, presetFacilityId]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    const errs: typeof fieldErrors = {};
    if (!companyId) errs.company = 'Vyber firmu.';
    if (!date) errs.date = 'Zadaj dátum školenia.';
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs);
      return;
    }
    setFieldErrors({});
    setApiError(null);
    setSubmitting(true);
    try {
      const payload = {
        type,
        company_id: companyId!,
        facility_id: facilityId ?? undefined,
        date,
        trainer_id: trainerId ?? undefined,
      };
      const company = (companies ?? []).find((c) => c.id === companyId);
      const facility = facilities.find((f) => f.id === facilityId);
      const trainer = (members ?? []).find((m) => m.id === trainerId);
      const optimistic = trainingCreateOptimistic({
        payload,
        company: { id: companyId!, name: company?.name ?? '', ico: company?.ico ?? null },
        facility: facility ? { id: facility.id, name: facility.name } : null,
        trainer: trainer
          ? { id: trainer.id, name: trainer.fullname, certification_number: trainer.cert_general }
          : null,
      });
      const res = await Trainings.create(payload, csrfToken, optimistic);
      toast.success('Školenie vytvorené');
      navigate(`/trainings/${res.training.id}`, { replace: true });
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setApiError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  const backHref = presetFacilityId
    ? `/facilities/${presetFacilityId}`
    : presetCompanyId
      ? `/companies/${presetCompanyId}`
      : '/trainings';

  if (companies === null) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  if (companies.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/trainings" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="flex flex-col items-center gap-3 px-6 py-10 text-center">
          <h2 className="text-base font-semibold text-ink-900">Najprv pridaj firmu</h2>
          <p className="max-w-xs text-sm text-ink-500">
            Školenie sa viaže na konkrétnu firmu — potrebuješ aspoň jednu vytvorenú.
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

      <header>
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
          Nové školenie
        </p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
          {TRAINING_TYPE_LABELS[type]}
        </h1>
      </header>

      <Card className="p-5">
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field label="Typ školenia" required>
            {() => (
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Typ školenia">
                {TRAINING_TYPES.map((t) => (
                  <TypeButton key={t} value={t} active={type === t} onClick={() => setType(t)} />
                ))}
              </div>
            )}
          </Field>

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
                options={companies.map((c) => ({
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

          <Field label="Prevádzka" hint="Voliteľné — niektoré školenia sú pre celú firmu.">
            {(p) => (
              <Select
                id={p.id}
                value={facilityId !== null ? String(facilityId) : ''}
                onChange={(v) => setFacilityId(v ? Number(v) : null)}
                disabled={companyId === null}
                placeholder="— bez konkrétnej prevádzky —"
                searchable
                options={[
                  { value: '', label: '— bez konkrétnej prevádzky —' },
                  ...facilities.map((f) => ({ value: String(f.id), label: f.name })),
                ]}
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
            label="Dátum školenia"
            required
            hint={fieldErrors.date ? undefined : 'Zadaj manuálne, nemusí byť dnešný dátum.'}
            error={fieldErrors.date}
          >
            {(p) => (
              <Input {...p} required type="date"
                leftIcon={<CalendarDays className="size-4" />}
                value={date} onChange={(e) => { setDate(e.target.value); if (fieldErrors.date) setFieldErrors((prev) => ({ ...prev, date: undefined })); }} />
            )}
          </Field>

          <Field
            label="Školiteľ"
            hint="Vyber z členov tímu. Podpis a číslo oprávnenia sa berú z jeho profilu."
          >
            {(p) => (
              <Select
                id={p.id}
                value={trainerId !== null ? String(trainerId) : ''}
                onChange={(v) => setTrainerId(v ? Number(v) : null)}
                disabled={members === null}
                placeholder="— bez školiteľa —"
                options={[
                  { value: '', label: '— bez školiteľa —' },
                  ...(members ?? []).map((m) => ({
                    value: String(m.id),
                    label: m.fullname,
                    description: m.email,
                  })),
                ]}
              />
            )}
          </Field>


          {apiError && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {apiError}
            </div>
          )}
          {Object.keys(fieldErrors).length > 0 && (
            <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              Formulár obsahuje nevyplnené povinné polia.
            </p>
          )}

          <div className="flex justify-end pt-1">
            <Button type="submit" loading={submitting} rightIcon={<ArrowRight className="size-4" />}>
              Vytvoriť školenie
            </Button>
          </div>
        </form>
      </Card>

      <Card className="flex items-start gap-3 bg-firol-50/50 px-3 py-3 text-xs text-ink-600">
        <GraduationCap className="size-4 shrink-0 text-firol-500" />
        <span>
          Po vytvorení doplníš účastníkov s ich podpismi. PDF protokol so zoznamom účastníkov vznikne v ďalšom kroku.
        </span>
      </Card>

      <NewCompanyDialog
        open={newCompanyOpen}
        onClose={() => setNewCompanyOpen(false)}
        onCreated={(c) => {
          setCompanies((prev) => {
            const enriched: CompanyListItem = {
              id: c.id,
              name: c.name,
              ico: c.ico,
              address: c.address,
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
            setFacilities((prev) => {
              const enriched: FacilityListItem = {
                id: f.id,
                name: f.name,
                address: f.address,
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

function TypeButton({
  value,
  active,
  onClick,
}: {
  value: TrainingType;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button type="button" role="radio" aria-checked={active} onClick={onClick}
      className={cn('rounded-xl border px-3 py-2.5 text-sm transition-colors text-left',
        active
          ? 'border-firol-500 bg-firol-50 text-firol-700'
          : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300')}>
      <div className="flex items-start gap-2">
        <Building2 className={cn('mt-0.5 size-4 shrink-0', active ? 'text-firol-500' : 'text-ink-400')} />
        <div className="min-w-0">
          <p className="font-semibold">{TRAINING_TYPE_SHORT[value]}</p>
          <p className={cn('text-[11px] line-clamp-2', active ? 'text-firol-600' : 'text-ink-500')}>
            {TRAINING_TYPE_LABELS[value]}
          </p>
        </div>
      </div>
    </button>
  );
}

function numericParam(raw: string | null): number | null {
  if (raw === null || raw === '') return null;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

