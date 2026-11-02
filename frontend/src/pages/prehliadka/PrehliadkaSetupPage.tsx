import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Building2, CalendarDays, CloudOff, Play, Warehouse } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type Company, type CompanyListItem, type FacilityListItem } from '@/api/companies';
import { Facilities, type Facility, type FacilityDeviceGroup } from '@/api/facilities';
import { Inspections, type InspectionDetail } from '@/api/inspections';
import { Prehliadka, type PrehliadkaContext } from '@/api/prehliadka';
import { Visits, type Visit } from '@/api/visits';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { todayIso } from '@/lib/dates';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { inspectionCreateOptimistic } from '@/lib/offlineEntities';
import { defaultPeriodicity, periodicityLabel, type Periodicity, type PeriodicityUnit } from '@/lib/periodicity';
import { isBytoveDruzstvo } from '@/lib/clientKind';
import { visitTrail } from '@/lib/visits';
import {
  DEVICE_TYPES,
  PREHLIADKA_TYPE,
  ROUTINE_LABELS,
  detailsPayload,
  parseDetails,
  prehliadkaPath,
  scopeLabel,
  type DeviceType,
  type PrehliadkaDetails,
  type PrehliadkaScope,
} from '@/lib/prehliadka';
import { useToast } from '@/lib/toast';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { PeriodicityPicker } from '@/components/PeriodicityPicker';
import { CheckRow, Segmented, StepBars } from '@/components/prehliadka/ui';

type ScopeChoice = 'po' | 'bozp' | 'both';

const SCOPE_OPTIONS: { value: ScopeChoice; label: string }[] = [
  { value: 'po', label: 'Protipožiarnu' },
  { value: 'bozp', label: 'BOZP' },
  { value: 'both', label: 'Obidve' },
];

function scopeToChoice(s: PrehliadkaScope): ScopeChoice | null {
  if (s.po && s.bozp) return 'both';
  if (s.po) return 'po';
  if (s.bozp) return 'bozp';
  return null;
}

function choiceToScope(c: ScopeChoice): PrehliadkaScope {
  return { po: c !== 'bozp', bozp: c !== 'po' };
}

function numericParam(raw: string | null): number | null {
  return raw !== null && /^\d+$/.test(raw) ? Number(raw) : null;
}

function devicesLabel(n: number): string {
  if (n === 1) return '1 zariadenie';
  if (n >= 2 && n <= 4) return `${n} zariadenia`;
  return `${n} zariadení`;
}

type Loaded = {
  facility: Facility;
  company: Company | null;
  lastPeriodicity: Periodicity | null;
  groups: FacilityDeviceGroup[] | null;
  context: PrehliadkaContext | null;
};

/**
 * Krok 1 — Nastavenie (spec 6.1): one screen, not a wizard.
 *
 * Serves two routes. `/prehliadky/new` starts a prehliadka: it creates the
 * draft when the technician taps „Začať obchôdzku" (also offline — the draft
 * is an optimistic create). `/prehliadky/:id/nastavenie` is „Zmeniť nastavenie"
 * of a draft that exists; it keeps the devices written during the walk.
 *
 * On the second and every later prehliadka of a prevádzka the switches arrive
 * preset from the last one, shown as „Ako naposledy" first. On the first visit
 * nothing is preselected.
 */
export function PrehliadkaSetupPage() {
  const { id: idStr } = useParams<{ id?: string }>();
  const editingId = idStr !== undefined ? Number(idStr) : null;
  const [searchParams, setSearchParams] = useSearchParams();

  const visitId = numericParam(searchParams.get('visit_id'));
  const presetBozp = searchParams.get('preset') === 'bozp';

  // The prevádzka comes from the query, from the visit, or — editing — from the draft.
  const [visit, setVisit] = useState<Visit | null>(null);
  const [visitError, setVisitError] = useState<string | null>(null);
  const [draft, setDraft] = useState<InspectionDetail | null>(null);
  const [draftError, setDraftError] = useState<string | null>(null);

  useEffect(() => {
    if (visitId === null || editingId !== null) return;
    let cancelled = false;
    Visits.show(visitId)
      .then((res) => {
        if (!cancelled) setVisit(res.visit);
      })
      .catch((err: unknown) => {
        if (!cancelled) setVisitError(err instanceof ApiError ? err.message : 'Návštevu sa nepodarilo načítať.');
      });
    return () => {
      cancelled = true;
    };
  }, [visitId, editingId]);

  useEffect(() => {
    if (editingId === null) return;
    let cancelled = false;
    Inspections.show(editingId)
      .then((res) => {
        if (!cancelled) setDraft(res);
      })
      .catch((err: unknown) => {
        if (!cancelled) setDraftError(err instanceof ApiError ? err.message : 'Prehliadku sa nepodarilo načítať.');
      });
    return () => {
      cancelled = true;
    };
  }, [editingId]);

  const facilityId =
    editingId !== null
      ? (draft?.inspection.facility_id ?? null)
      : visit
        ? visit.facility_id
        : numericParam(searchParams.get('facility_id'));

  if (editingId !== null && draftError) return <ErrorCard message={draftError} to="/" />;
  if (visitId !== null && visitError && !visit) return <ErrorCard message={visitError} to={`/visits/${visitId}`} />;

  if (editingId !== null ? draft === null : visitId !== null && visit === null) {
    return <SetupSkeleton />;
  }

  if (facilityId === null) {
    return (
      <FacilityChooser
        onPick={(companyId, facId) => {
          const next = new URLSearchParams(searchParams);
          next.set('company_id', String(companyId));
          next.set('facility_id', String(facId));
          setSearchParams(next, { replace: true });
        }}
      />
    );
  }

  return (
    <SetupForm
      key={`${editingId ?? 'new'}-${facilityId}`}
      facilityId={facilityId}
      editing={draft}
      visit={visit}
      presetBozp={presetBozp}
      presetDate={searchParams.get('executed_on')}
    />
  );
}

// ─── The form ───────────────────────────────────────────────────────────────

function SetupForm({
  facilityId,
  editing,
  visit,
  presetBozp,
  presetDate,
}: {
  facilityId: number;
  editing: InspectionDetail | null;
  visit: Visit | null;
  presetBozp: boolean;
  presetDate: string | null;
}) {
  const { user, csrfToken } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [scope, setScope] = useState<PrehliadkaScope>({ po: false, bozp: false });
  const [routine, setRoutine] = useState<DeviceType[]>([]);
  const [bookEntry, setBookEntry] = useState(false);
  const [executedOn, setExecutedOn] = useState(
    editing?.inspection.executed_on ?? visit?.visit_date ?? presetDate ?? todayIso(),
  );
  const [periodicity, setPeriodicity] = useState<Periodicity>(() =>
    editing
      ? { value: editing.inspection.periodicity_value, unit: editing.inspection.periodicity_unit }
      : defaultPeriodicity(PREHLIADKA_TYPE),
  );
  const [periodicityTouched, setPeriodicityTouched] = useState(editing !== null);
  // „Ako naposledy" first; „Zmeniť nastavenie" opens the form itself.
  const [changing, setChanging] = useState(false);
  const [scopeError, setScopeError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const details = useMemo(() => parseDetails(editing?.inspection.details), [editing]);

  // The prevádzka, its device list and what it remembers of the last prehliadka.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const { facility } = await Facilities.show(facilityId);
        const [groups, context, companyDetail] = await Promise.all([
          Facilities.devices(facilityId).then((r) => r.groups).catch(() => null),
          Prehliadka.context(facilityId).catch(() => null),
          Companies.show(facility.company_id).catch(() => null),
        ]);
        if (cancelled) return;
        const row: FacilityListItem | undefined = companyDetail?.facilities.find((f) => f.id === facilityId);
        const last =
          row?.last_periodicities?.[PREHLIADKA_TYPE] ?? row?.last_periodicities?.poziarna_kniha ?? null;
        setLoaded({
          facility,
          company: companyDetail?.company ?? null,
          lastPeriodicity:
            last && typeof last.value === 'number' && last.unit
              ? { value: last.value, unit: last.unit as PeriodicityUnit }
              : null,
          groups,
          context,
        });
      } catch (err) {
        if (!cancelled) setLoadError(err instanceof ApiError ? err.message : 'Prevádzku sa nepodarilo načítať.');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [facilityId]);

  const counts = useMemo(() => {
    const map: Partial<Record<DeviceType, number>> = {};
    for (const g of loaded?.groups ?? []) map[g.type] = g.items.length;
    return map;
  }, [loaded]);

  const memory = editing === null ? (loaded?.context?.last ?? null) : null;
  const openDrafts = loaded?.context?.open_drafts ?? {};

  /** A type can be ticked when it has devices and no unfinished draft (Q10); a ticked one stays. */
  const tickable = (type: DeviceType, current: DeviceType[]) =>
    current.includes(type) || ((counts[type] ?? 0) > 0 && openDrafts[type] === undefined);

  // Preset once, as soon as the context is known: the draft being edited, else
  // the last prehliadka (spec 6.1 „Pamäť"), else nothing.
  const [seeded, setSeeded] = useState(false);
  useEffect(() => {
    if (!loaded || seeded) return;
    setSeeded(true);
    if (editing) {
      setScope(details.scope);
      setRoutine(details.routine);
      setBookEntry(details.book_entry);
      return;
    }
    if (memory) {
      setScope(memory.scope);
      setRoutine(memory.routine.filter((t) => tickable(t, [])));
      setBookEntry(memory.book_entry);
      if (!periodicityTouched && memory.periodicity_value !== null && memory.periodicity_unit) {
        setPeriodicity({ value: memory.periodicity_value, unit: memory.periodicity_unit });
      }
    } else if (!periodicityTouched && loaded.lastPeriodicity) {
      setPeriodicity(loaded.lastPeriodicity);
    }
    // „Kniha kontrol BOZP" (D2) starts a prehliadka preset to BOZP.
    if (presetBozp) {
      setScope({ po: false, bozp: true });
      setBookEntry(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loaded]);

  const showMemoryCard = editing === null && memory !== null && !changing && !presetBozp;

  const backTo = editing
    ? prehliadkaPath(editing.inspection.id)
    : visit
      ? `/visits/${visit.id}`
      : `/facilities/${facilityId}`;

  function toggleRoutine(type: DeviceType) {
    setRoutine((cur) => (cur.includes(type) ? cur.filter((t) => t !== type) : [...cur, type]));
  }

  async function submit(opts?: { useMemory?: boolean }) {
    if (!loaded) return;
    // The rest of `details` (devices, ticked activities, answers about the previous
    // defects …) belongs to the later steps; a PATCH replaces the object, so it travels along.
    const effective: PrehliadkaDetails = {
      ...details,
      scope,
      routine,
      book_entry: bookEntry,
    };
    if (!effective.scope.po && !effective.scope.bozp) {
      setScopeError('Vyber, akú prehliadku robíš.');
      return;
    }
    if (!executedOn) {
      setError('Zadaj dátum prehliadky.');
      return;
    }
    setError(null);
    setScopeError(null);
    setSubmitting(true);
    try {
      if (editing) {
        const patch = {
          // The date of a prehliadka in a visit is the visit's; the server ignores it there.
          executed_on: executedOn,
          periodicity_value: periodicity.value,
          periodicity_unit: periodicity.unit,
          details: detailsPayload(effective),
        };
        try {
          await Inspections.update(editing.inspection.id, patch, csrfToken);
        } catch (err) {
          if (!(err instanceof OfflineQueuedError)) throw err;
          toast.success('Uloží sa keď budeš online');
        }
        navigate(prehliadkaPath(editing.inspection.id), { replace: true });
        return;
      }

      const facility = loaded.facility;
      const payload = {
        type: PREHLIADKA_TYPE,
        periodicity_value: periodicity.value,
        periodicity_unit: periodicity.unit,
        executed_on: executedOn,
        company_id: facility.company_id,
        facility_id: facility.id,
        details: detailsPayload(effective),
        ...(visit ? { visit_id: visit.id } : {}),
      };
      // Offline the draft is created locally and syncs later; ignored when the POST reaches the server.
      const optimistic = inspectionCreateOptimistic({
        payload,
        company: {
          id: facility.company_id,
          name: facility.company_name ?? loaded.company?.name ?? '',
          ico: loaded.company?.ico ?? null,
          billing_mode: loaded.company?.billing_mode,
        },
        facility: { id: facility.id, name: facility.name },
        inspector: { id: user?.id ?? 0, name: user?.fullname ?? '' },
      });
      const res = await Inspections.createDraft(payload, csrfToken, optimistic);
      toast.success(opts?.useMemory ? 'Prehliadka začatá — nastavenie ako naposledy' : 'Prehliadka začatá');
      navigate(prehliadkaPath(res.inspection.id), { replace: true });
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  if (loadError) return <ErrorCard message={loadError} to={backTo} />;
  if (!loaded) return <SetupSkeleton />;

  const { facility, context } = loaded;
  const bd = isBytoveDruzstvo(facility.company_client_type);
  const inProgress = editing === null ? (context?.draft_id ?? null) : null;

  return (
    <div className="flex flex-col gap-5">
      {visit ? (
        <Breadcrumb
          items={[
            ...visitTrail({ id: visit.id, companyName: visit.company_name, date: visit.visit_date }),
            { label: 'Preventívna prehliadka' },
          ]}
        />
      ) : (
        <Link to={backTo} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          {editing ? 'Späť na obchôdzku' : 'Zrušiť'}
        </Link>
      )}

      <header className="animate-fade-up">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Krok 1 z 3 · Nastavenie</p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
          {showMemoryCard ? 'Ako naposledy?' : 'Preventívna prehliadka'}
        </h1>
        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500">
          <Building2 className="size-3" />
          {facility.company_name}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />
          {facility.name}
          <span className="text-ink-300">·</span>
          {formatDateSk(executedOn)}
        </p>
        <StepBars step={1} />
      </header>

      {inProgress !== null && (
        <Card className="animate-fade-up flex flex-col gap-3 p-4">
          <div>
            <p className="text-sm font-semibold text-ink-900">Prehliadka tu už prebieha</p>
            <p className="mt-0.5 text-xs text-ink-500">
              Na tejto prevádzke je rozpracovaná prehliadka. Dokonči ju, alebo ju zahoď na zozname kontrol.
            </p>
          </div>
          <Link to={prehliadkaPath(inProgress)} className="self-start">
            <Button type="button" id="pp-continue" rightIcon={<ArrowRight className="size-4" />}>
              Pokračovať
            </Button>
          </Link>
        </Card>
      )}

      {inProgress === null && showMemoryCard && memory && (
        <MemoryCard
          memory={memory}
          routine={memory.routine.filter((t) => tickable(t, []))}
          date={executedOn}
          submitting={submitting}
          error={error}
          onStart={() => void submit({ useMemory: true })}
          onChange={() => setChanging(true)}
        />
      )}

      {inProgress === null && !showMemoryCard && (
        <form
          className="flex flex-col gap-4"
          noValidate
          onSubmit={(e) => {
            e.preventDefault();
            void submit();
          }}
        >
          <Card className="animate-fade-up flex flex-col gap-2.5 p-4">
            <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Akú prehliadku robím</span>
            <Segmented
              label="Akú prehliadku robím"
              value={scopeToChoice(scope)}
              options={SCOPE_OPTIONS}
              onChange={(c) => {
                const next = choiceToScope(c);
                setScope(next);
                setScopeError(null);
                if (!next.po) setBookEntry(false);
              }}
            />
            {scopeError ? (
              <p className="text-xs text-status-bad">{scopeError}</p>
            ) : (
              <p className="text-xs text-ink-400">
                Jedna obchôdzka, viac záznamov. Pri každom nedostatku povieš, do ktorého patrí — predvyplní sa z
                číselníka.
              </p>
            )}
          </Card>

          <Card className="animate-fade-up flex flex-col gap-2.5 p-4">
            <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">
              Robím pri tom aj riadne kontroly?
            </span>
            {loaded.groups === null && (
              <p className="flex items-start gap-2 rounded-xl bg-[var(--color-status-warn-bg)]/60 px-3 py-2 text-xs text-ink-600">
                <CloudOff className="mt-0.5 size-3.5 shrink-0 text-status-warn" />
                Zoznam zariadení sa nepodarilo načítať — bez siete sa riadne kontroly zvoliť nedajú. Nedostatok
                môžeš napísať aj tak, zariadenie vypíšeš rukou.
              </p>
            )}
            <ul className="flex flex-col gap-2">
              {DEVICE_TYPES.map((type) => {
                const count = counts[type] ?? 0;
                const openDraft = openDrafts[type];
                const ticked = routine.includes(type);
                const disabled = !tickable(type, routine);
                return (
                  <li key={type} className="flex flex-col">
                    <CheckRow
                      id={`pp-routine-${type}`}
                      checked={ticked}
                      disabled={disabled}
                      onToggle={() => toggleRoutine(type)}
                      title={ROUTINE_LABELS[type]}
                      sub={
                        count === 0
                          ? 'Zatiaľ tu nie je žiadna kontrola tohto typu.'
                          : `${devicesLabel(count)} · na celý objekt`
                      }
                    />
                    {openDraft !== undefined && !ticked && (
                      <Link
                        to={`/inspections/${openDraft}`}
                        className="mt-1 px-3.5 text-xs font-medium text-firol-700 underline-offset-2 hover:underline"
                      >
                        Rozpracovaná kontrola už existuje — dokonči ju
                      </Link>
                    )}
                  </li>
                );
              })}
            </ul>
            <p className="text-xs text-ink-400">
              Z každej zaškrtnutej vznikne na konci vlastný koncept kontroly — aj s výsledkami, ktoré pri obchôdzke
              zapíšeš. Typ bez zariadení sa zvoliť nedá; zariadenia pribudnú z kontrol na tejto prevádzke.
            </p>
          </Card>

          <Card className="animate-fade-up flex flex-col gap-2.5 p-4">
            <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Zápis do knihy</span>
            {scope.po ? (
              <CheckRow
                id="pp-book-entry"
                checked={bookEntry}
                onToggle={() => setBookEntry((v) => !v)}
                title="Pridať aj ako zápis do požiarnej knihy"
                sub={
                  bd
                    ? 'Pri bytovom dome býva zbytočný — vyhláška žiada záznam, nie zápis do knihy. Pridaj ho, len keď ho správca vyslovene chce.'
                    : 'Zápis pristane v požiarnej knihe firmy. Keď ho nezapneš, appka ti ho po uzavretí ponúkne.'
                }
              />
            ) : (
              <p className="text-xs text-ink-400">Zápis do požiarnej knihy sa týka len protipožiarnej prehliadky.</p>
            )}
            {scope.bozp && (
              <p className="text-xs text-ink-500">
                Pri BOZP vznikne zápis do knihy BOZP vždy — je to zároveň záznam z prehliadky BOZP, samostatný
                dokument nevzniká.
              </p>
            )}
          </Card>

          <Card className="animate-fade-up flex flex-col gap-4 p-4">
            {visit ? (
              <Field label="Dátum prehliadky" hint="Dátum prehliadky je dátum návštevy.">
                {(p) => (
                  <Input
                    id={p.id}
                    type="text"
                    readOnly
                    disabled
                    value={formatDateSk(executedOn)}
                    leftIcon={<CalendarDays className="size-4" />}
                  />
                )}
              </Field>
            ) : (
              <Field
                label="Dátum prehliadky"
                required
                hint="Od tohto dátumu sa počítajú termíny. Predvyplnený je dnešný dátum, môžeš ho zmeniť aj na minulý."
              >
                {(p) => (
                  <Input
                    {...p}
                    type="date"
                    required
                    leftIcon={<CalendarDays className="size-4" />}
                    value={executedOn}
                    onChange={(e) => setExecutedOn(e.target.value)}
                  />
                )}
              </Field>
            )}

            <Field label="Periodicita ďalšej prehliadky">
              {() => (
                <PeriodicityPicker
                  type={PREHLIADKA_TYPE}
                  value={periodicity}
                  executedOn={executedOn || null}
                  onChange={(next) => {
                    setPeriodicity(next);
                    setPeriodicityTouched(true);
                  }}
                />
              )}
            </Field>
          </Card>

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}

          <Button
            type="submit"
            id="pp-start"
            loading={submitting}
            leftIcon={editing ? undefined : <Play className="size-4" />}
            rightIcon={<ArrowRight className="size-4" />}
          >
            {editing ? 'Uložiť nastavenie' : 'Začať obchôdzku'}
          </Button>
        </form>
      )}
    </div>
  );
}

// ─── „Ako naposledy" ────────────────────────────────────────────────────────

function MemoryCard({
  memory,
  routine: routineTypes,
  date,
  submitting,
  error,
  onStart,
  onChange,
}: {
  memory: NonNullable<PrehliadkaContext['last']>;
  routine: DeviceType[];
  date: string;
  submitting: boolean;
  error: string | null;
  onStart: () => void;
  onChange: () => void;
}) {
  const routine = routineTypes.map((t) => ROUTINE_LABELS[t]);
  return (
    <div className="animate-fade-up flex flex-col gap-3">
      <Card className="flex flex-col gap-3 border-firol-200 p-4">
        <div>
          <p className="text-sm font-semibold text-ink-900">
            Prevzaté z prehliadky {memory.executed_on ? formatDateSk(memory.executed_on) : '—'}
          </p>
          <p className="mt-0.5 text-xs text-ink-500">Všetko sa dá zmeniť.</p>
        </div>
        <ul className="flex flex-col gap-1.5 text-sm text-ink-800">
          <li>
            • Zapisujem <b>{scopeLabel(memory.scope)}</b>
          </li>
          <li>
            • Riadne kontroly: <b>{routine.length > 0 ? routine.join(', ') : 'žiadne'}</b>
          </li>
          {memory.scope.po && (
            <li>
              • Zápis do požiarnej knihy: <b>{memory.book_entry ? 'áno' : 'nie'}</b>
            </li>
          )}
          <li>
            • Dátum: <b>{formatDateSk(date)}</b>
          </li>
          <li>
            • Ďalšia prehliadka:{' '}
            <b>{periodicityLabel({ value: memory.periodicity_value, unit: memory.periodicity_unit })}</b>
          </li>
        </ul>
      </Card>
      {error && (
        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          {error}
        </div>
      )}
      <Button type="button" id="pp-start-memory" loading={submitting} onClick={onStart} leftIcon={<Play className="size-4" />}>
        Začať obchôdzku
      </Button>
      <Button type="button" id="pp-change-setup" variant="secondary" onClick={onChange} disabled={submitting}>
        Zmeniť nastavenie
      </Button>
    </div>
  );
}

// ─── Without a prevádzka in the link ────────────────────────────────────────

/** Reached from the type picker, where only a company may be known. */
function FacilityChooser({ onPick }: { onPick: (companyId: number, facilityId: number) => void }) {
  const [searchParams] = useSearchParams();
  const [companies, setCompanies] = useState<CompanyListItem[] | null>(null);
  const [facilities, setFacilities] = useState<FacilityListItem[]>([]);
  const [companyId, setCompanyId] = useState<number | null>(numericParam(searchParams.get('company_id')));
  const [facilityId, setFacilityId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loadingFacilities, setLoadingFacilities] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Companies.list()
      .then((res) => {
        if (cancelled) return;
        setCompanies(res.items);
        if (companyId === null && res.items.length === 1) setCompanyId(res.items[0].id);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať firmy.');
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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
        setFacilityId(res.facilities.length === 1 ? res.facilities[0].id : null);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať prevádzky.');
      })
      .finally(() => {
        if (!cancelled) setLoadingFacilities(false);
      });
    return () => {
      cancelled = true;
    };
  }, [companyId]);

  if (companies === null && !error) return <SetupSkeleton />;

  return (
    <div className="flex flex-col gap-5">
      <Link to="/opp" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
        <ArrowLeft className="size-4" />
        Späť
      </Link>
      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">Preventívna prehliadka</h1>
        <p className="mt-1 text-sm text-ink-500">Najprv vyber, kde sa prehliadka robí.</p>
      </header>
      <Card className="flex flex-col gap-4 p-5">
        <Field label="Spoločnosť" required>
          {(p) => (
            <Select
              id={p.id}
              value={companyId !== null ? String(companyId) : ''}
              onChange={(v) => setCompanyId(v ? Number(v) : null)}
              placeholder="— vyber firmu —"
              leftIcon={<Building2 className="size-4" />}
              searchable
              options={(companies ?? []).map((c) => ({
                value: String(c.id),
                label: c.name,
                description: c.ico ? `IČO ${c.ico}` : undefined,
              }))}
            />
          )}
        </Field>
        <Field label="Prevádzka" required>
          {(p) => (
            <Select
              id={p.id}
              value={facilityId !== null ? String(facilityId) : ''}
              onChange={(v) => setFacilityId(v ? Number(v) : null)}
              disabled={companyId === null || loadingFacilities}
              placeholder={companyId === null ? '— najprv vyber firmu —' : loadingFacilities ? 'Načítavam…' : '— vyber prevádzku —'}
              leftIcon={<Warehouse className="size-4" />}
              searchable
              options={facilities.map((f) => ({ value: String(f.id), label: f.name }))}
            />
          )}
        </Field>
        {error && <p className="text-sm text-status-bad">{error}</p>}
        <Button
          type="button"
          id="pp-pick-facility"
          disabled={companyId === null || facilityId === null}
          rightIcon={<ArrowRight className="size-4" />}
          onClick={() => companyId !== null && facilityId !== null && onPick(companyId, facilityId)}
        >
          Pokračovať
        </Button>
      </Card>
    </div>
  );
}

// ─── Chrome ─────────────────────────────────────────────────────────────────

function SetupSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-20" />
      <Skeleton className="h-7 w-56" />
      <Skeleton className="h-28 w-full rounded-2xl" />
      <Skeleton className="h-56 w-full rounded-2xl" />
    </div>
  );
}

function ErrorCard({ message, to }: { message: string; to: string }) {
  return (
    <div className="flex flex-col gap-4">
      <Link to={to} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
        <ArrowLeft className="size-4" />
        Späť
      </Link>
      <Card className="px-4 py-3 text-sm text-status-bad">{message}</Card>
    </div>
  );
}
