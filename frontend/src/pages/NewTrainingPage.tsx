import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, Building2, CalendarDays, Clock, GraduationCap,
  NotebookPen, Plus,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type CompanyListItem, type FacilityListItem } from '@/api/companies';
import { Trainers, type Trainer } from '@/api/trainers';
import {
  TRAINING_TYPES,
  TRAINING_TYPE_LABELS,
  TRAINING_TYPE_SHORT,
  Trainings,
  type TrainingType,
} from '@/api/trainings';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { NewCompanyDialog } from '@/components/NewCompanyDialog';
import { cn } from '@/lib/cn';

export function NewTrainingPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { csrfToken } = useAuth();

  const presetCompanyId = numericParam(searchParams.get('company_id'));
  const presetFacilityId = numericParam(searchParams.get('facility_id'));

  const [type, setType] = useState<TrainingType>('vstupne');
  const [companies, setCompanies] = useState<CompanyListItem[] | null>(null);
  const [facilities, setFacilities] = useState<FacilityListItem[]>([]);
  const [trainers, setTrainers] = useState<Trainer[] | null>(null);
  const [companyId, setCompanyId] = useState<number | null>(presetCompanyId);
  const [facilityId, setFacilityId] = useState<number | null>(presetFacilityId);
  const [trainerId, setTrainerId] = useState<number | null>(null);
  const [date, setDate] = useState('');
  const [topics, setTopics] = useState('');
  const [durationMin, setDurationMin] = useState<string>('');

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [newCompanyOpen, setNewCompanyOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([Companies.list(), Trainers.list()])
      .then(([cs, trs]) => {
        if (cancelled) return;
        setCompanies(cs.items);
        setTrainers(trs.items);
        if (presetCompanyId === null && cs.items.length === 1) {
          setCompanyId(cs.items[0].id);
        }
        if (trs.items.length === 1) {
          setTrainerId(trs.items[0].id);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať dáta.');
      });
    return () => {
      cancelled = true;
    };
  }, [presetCompanyId]);

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

  const trainerWithoutSignatureWarning = useMemo(() => {
    if (trainerId === null || !trainers) return null;
    const t = trainers.find((x) => x.id === trainerId);
    if (!t || t.has_signature) return null;
    return `Školiteľ „${t.fullname}" zatiaľ nemá nahraný podpis. Bez neho sa nedá vystaviť PDF protokol — podpis nahraj v Nastaveniach.`;
  }, [trainerId, trainers]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!companyId) {
      setError('Vyber firmu.');
      return;
    }
    if (!date) {
      setError('Zadaj dátum školenia (manuálne).');
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      const res = await Trainings.create(
        {
          type,
          company_id: companyId,
          facility_id: facilityId ?? undefined,
          date,
          trainer_id: trainerId ?? undefined,
          topics: topics.trim() || undefined,
          duration_min: durationMin ? Number(durationMin) : undefined,
        },
        csrfToken,
      );
      navigate(`/trainings/${res.training.id}`, { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
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

  const noTrainers = trainers !== null && trainers.length === 0;

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

          <Field label="Spoločnosť" required>
            {(p) => (
              <Select
                id={p.id}
                value={companyId !== null ? String(companyId) : ''}
                onChange={(v) => setCompanyId(v ? Number(v) : null)}
                placeholder="— vyber firmu —"
                leftIcon={<Building2 className="size-4" />}
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
                disabled={companyId === null || facilities.length === 0}
                placeholder="— bez konkrétnej prevádzky —"
                options={[
                  { value: '', label: '— bez konkrétnej prevádzky —' },
                  ...facilities.map((f) => ({ value: String(f.id), label: f.name })),
                ]}
              />
            )}
          </Field>

          <Field label="Dátum školenia" required hint="Zadaj manuálne, nemusí byť dnešný dátum.">
            {(p) => (
              <Input {...p} required type="date"
                leftIcon={<CalendarDays className="size-4" />}
                value={date} onChange={(e) => setDate(e.target.value)} />
            )}
          </Field>

          <Field
            label="Školiteľ"
            hint={
              noTrainers
                ? 'Nemáš ešte nikoho v zozname školiteľov. Pridaj ich v Nastaveniach.'
                : trainerWithoutSignatureWarning ?? 'Vyber zo zoznamu školiteľov.'
            }
            error={trainerWithoutSignatureWarning ? trainerWithoutSignatureWarning : undefined}
          >
            {(p) => (
              <Select
                id={p.id}
                value={trainerId !== null ? String(trainerId) : ''}
                onChange={(v) => setTrainerId(v ? Number(v) : null)}
                disabled={trainers === null || noTrainers}
                placeholder="— bez školiteľa —"
                options={[
                  { value: '', label: '— bez školiteľa —' },
                  ...(trainers ?? []).map((t) => ({
                    value: String(t.id),
                    label: t.fullname,
                    description: t.certification_number ?? undefined,
                  })),
                ]}
              />
            )}
          </Field>

          <div className="grid gap-4 sm:grid-cols-3">
            <div className="sm:col-span-2">
              <Field label="Témy školenia" hint="Stručný zoznam preberaných tém — pôjde do PDF protokolu.">
                {(p) => (
                  <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                      <NotebookPen className="size-4" />
                    </span>
                    <textarea id={p.id} rows={3} value={topics}
                      onChange={(e) => setTopics(e.target.value)}
                      placeholder="OPP osnova, evakuácia, RPHP — zaobchádzanie, prvá pomoc."
                      className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
                  </div>
                )}
              </Field>
            </div>
            <Field label="Dĺžka (min)" hint="Voliteľné">
              {(p) => (
                <Input {...p} type="number" inputMode="numeric" min={0} max={1440}
                  leftIcon={<Clock className="size-4" />}
                  value={durationMin} onChange={(e) => setDurationMin(e.target.value)}
                  placeholder="120" />
              )}
            </Field>
          </div>

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
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

