import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Building, CalendarDays, RotateCcw } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type Company, type CompanyListItem } from '@/api/companies';
import { Facilities } from '@/api/facilities';
import { Inspections, type InspectionDetail, type InspectionDraftPayload } from '@/api/inspections';
import { Kniha, prefillMonths, type KnihaContext } from '@/api/kniha';
import { Visits, type Visit } from '@/api/visits';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { cachedCertRefusal, useCertMissingToast, warmCertProfile } from '@/lib/certMissing';
import { CLIENT_NEUTRAL, facilityNouns, isBytoveDruzstvo } from '@/lib/clientKind';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { cn } from '@/lib/cn';
import { todayIso } from '@/lib/dates';
import {
  KNIHA_TYPE,
  PERIODICITY_MONTHS,
  defaultSposob,
  knihaDetailsPayload,
  knihaPath,
  monthsWord,
  newKnihaDetails,
  parseKnihaDetails,
  sk3,
  validUntilLikeServer,
  zaznamKey,
  type KnihaMonths,
  type KnihaSposob,
} from '@/lib/kniha';
import { inspectionCreateOptimistic } from '@/lib/offlineEntities';
import { useToast } from '@/lib/toast';
import { visitTrail } from '@/lib/visits';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { CheckRow, Chip, StepBars } from '@/components/prehliadka/ui';
import { entrySynced } from '@/pages/kniha/entrySynced';

function numericParam(v: string | null): number | null {
  if (v === null || !/^-?\d+$/.test(v)) return null;
  return Number(v);
}

/**
 * Krok 1 of a požiarna kniha entry (spec 4): Prevádzky → Spôsob zápisu → Dátum.
 *
 *   /kniha/new?company_id&facility_id&visit_id&executed_on   a new entry
 *   /kniha/:id/krok-1                                          the draft's step 1 (also after Opakovať)
 *
 * The company comes from the draft, the visit, the query or the prevádzka in
 * the query; without any of them it is asked for first.
 */
export function KnihaStep1Page() {
  const { id: idStr } = useParams<{ id?: string }>();
  const editingId = idStr !== undefined ? Number(idStr) : null;
  const [search, setSearch] = useSearchParams();
  const visitParam = numericParam(search.get('visit_id'));
  const facilityParam = numericParam(search.get('facility_id'));
  const companyParam = numericParam(search.get('company_id'));

  const [editing, setEditing] = useState<InspectionDetail | null>(null);
  const [visit, setVisit] = useState<Visit | null>(null);
  const [facilityCompany, setFacilityCompany] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        let detail: InspectionDetail | null = null;
        if (editingId !== null) {
          await entrySynced(editingId);
          detail = await Inspections.show(editingId);
        }
        const visitId = detail ? detail.inspection.visit_id : visitParam;
        const v = visitId !== null ? (await Visits.show(visitId).catch(() => null))?.visit ?? null : null;
        let fc: number | null = null;
        if (!detail && !v && companyParam === null && facilityParam !== null) {
          fc = (await Facilities.show(facilityParam)).facility.company_id;
        }
        if (cancelled) return;
        setEditing(detail);
        setVisit(v);
        setFacilityCompany(fc);
        setReady(true);
      } catch (err) {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať údaje.');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [editingId, visitParam, companyParam, facilityParam]);

  const backTo = editingId !== null
    ? knihaPath(editingId)
    : visitParam !== null
      ? `/visits/${visitParam}`
      : facilityParam !== null
        ? `/facilities/${facilityParam}`
        : companyParam !== null
          ? `/companies/${companyParam}`
          : '/opp';

  if (error) return <ErrorCard message={error} to={backTo} />;
  if (!ready) return <Step1Skeleton />;

  if (editing && editing.inspection.status !== 'draft') {
    return <ErrorCard message="Zápis je uzamknutý — najprv ho odomkni cez „Upraviť“." to={knihaPath(editing.inspection.id)} />;
  }

  const companyId = editing?.inspection.company_id ?? visit?.company_id ?? companyParam ?? facilityCompany;
  if (companyId === null) {
    return (
      <CompanyChooser
        backTo={backTo}
        onPick={(id) => {
          const next = new URLSearchParams(search);
          next.set('company_id', String(id));
          setSearch(next, { replace: true });
        }}
      />
    );
  }

  return (
    <Step1Form
      key={`${editingId ?? 'new'}-${companyId}`}
      companyId={companyId}
      editing={editing}
      visit={visit}
      presetFacilityId={visit?.facility_id ?? facilityParam}
      presetDate={search.get('executed_on')}
      backTo={backTo}
    />
  );
}

// ─── The form ───────────────────────────────────────────────────────────────

function Step1Form({
  companyId,
  editing,
  visit,
  presetFacilityId,
  presetDate,
  backTo,
}: {
  companyId: number;
  editing: InspectionDetail | null;
  visit: Visit | null;
  presetFacilityId: number | null;
  presetDate: string | null;
  backTo: string;
}) {
  const { user, csrfToken } = useAuth();
  const toast = useToast();
  const certMissing = useCertMissingToast();
  const navigate = useNavigate();
  // Fresh `can_create` verdict for an offline start (see cachedCertRefusal).
  useEffect(() => warmCertProfile(), []);

  const [ctx, setCtx] = useState<KnihaContext | null>(null);
  const [company, setCompany] = useState<Company | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([Kniha.context(companyId), Companies.show(companyId).then((r) => r.company).catch(() => null)])
      .then(([c, comp]) => {
        if (cancelled) return;
        setCtx(c);
        setCompany(comp);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setLoadError(
            err instanceof ApiError ? err.message : 'Údaje klienta sa nepodarilo načítať. Skús to znova, keď budeš online.',
          );
        }
      });
    return () => {
      cancelled = true;
    };
  }, [companyId]);

  const stored = useMemo(() => (editing ? parseKnihaDetails(editing.inspection.details) : null), [editing]);
  // The visit's prevádzka stays selected — the server refuses to drop it.
  const lockedId = visit?.facility_id ?? null;

  const [selected, setSelected] = useState<number[]>(() => {
    if (editing) {
      const rows = editing.inspection.facilities;
      return rows && rows.length > 0 ? rows.map((r) => r.facility_id) : [editing.inspection.facility_id];
    }
    return presetFacilityId !== null ? [presetFacilityId] : [];
  });
  const [months, setMonths] = useState<Record<number, KnihaMonths | null>>({});
  const [manual, setManual] = useState<boolean>(stored?.sposob_rucne ?? false);
  const [manualSposob, setManualSposob] = useState<KnihaSposob>(stored?.sposob ?? 1);
  const [executedOn, setExecutedOn] = useState<string>(
    editing?.inspection.executed_on ?? visit?.visit_date ?? presetDate ?? todayIso(),
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Once the context is known: each prevádzka's periodicity — the draft's own
  // row, else its last prehliadka's (or none) — and the only prevádzka preselected.
  const [seeded, setSeeded] = useState(false);
  useEffect(() => {
    if (!ctx || seeded) return;
    setSeeded(true);
    const rows = new Map((editing?.inspection.facilities ?? []).map((r) => [r.facility_id, r]));
    const next: Record<number, KnihaMonths | null> = {};
    for (const f of ctx.facilities) {
      const row = rows.get(f.id);
      if (row) {
        const v = row.periodicity_value;
        next[f.id] = row.periodicity_unit === 'mesiac' && (v === 12 || v === 6 || v === 3) ? v : null;
      } else {
        next[f.id] = prefillMonths(f.last_prehliadka);
      }
    }
    setMonths(next);
    const known = new Set(ctx.facilities.map((f) => f.id));
    setSelected((cur) => {
      const kept = cur.filter((id) => known.has(id));
      if (kept.length === 0 && !editing && ctx.facilities.length === 1) return [ctx.facilities[0].id];
      return kept;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ctx]);

  if (loadError) return <ErrorCard message={loadError} to={backTo} />;
  if (!ctx) return <Step1Skeleton />;

  const bd = isBytoveDruzstvo(ctx.company.client_type);
  const nouns = facilityNouns(ctx.company.client_type);
  // Selected in the order of the list (by name).
  const ordered = ctx.facilities.filter((f) => selected.includes(f.id));
  const sposob: KnihaSposob = manual ? manualSposob : defaultSposob(ordered.length);
  const missingPeriodicity = sposob === 1 && ordered.some((f) => !months[f.id]);
  const allSelected = ordered.length === ctx.facilities.length && ctx.facilities.length > 0;

  function toggle(id: number) {
    if (id === lockedId) return;
    setSelected((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]));
  }

  function toggleAll() {
    if (!ctx) return;
    setSelected(allSelected ? (lockedId !== null ? [lockedId] : []) : ctx.facilities.map((f) => f.id));
  }

  function pickSposob(s: KnihaSposob) {
    setManual(true);
    setManualSposob(s);
  }

  async function submit() {
    if (!ctx || ordered.length === 0 || missingPeriodicity || !executedOn) return;
    setError(null);
    setSubmitting(true);
    const rows = ordered.map((f) => ({
      facility_id: f.id,
      periodicity_value: months[f.id] ?? null,
      periodicity_unit: months[f.id] ? ('mesiac' as const) : null,
    }));
    try {
      if (editing && stored) {
        const ids = new Set(ordered.map((f) => f.id));
        const recordFacility = new Map(ctx.records.map((r) => [r.key, r.facility_id]));
        const next = {
          ...stored,
          sposob,
          sposob_rucne: manual,
          // A record of a prevádzka no longer selected goes; the organisation's stay.
          zaznamy: stored.zaznamy.filter((z) => {
            const fid = recordFacility.get(zaznamKey(z));
            return fid === undefined || fid === null || ids.has(fid);
          }),
        };
        try {
          await Inspections.update(
            editing.inspection.id,
            {
              ...(visit ? {} : { executed_on: executedOn }),
              details: knihaDetailsPayload(next),
              facilities: rows.map((r) => ({ ...r, name: ctx.facilities.find((f) => f.id === r.facility_id)?.name })),
            },
            csrfToken,
          );
        } catch (err) {
          if (!(err instanceof OfflineQueuedError)) throw err;
          toast.success('Uloží sa keď budeš online');
        }
        navigate(knihaPath(editing.inspection.id), { replace: true });
        return;
      }

      if (!navigator.onLine) {
        const refusal = await cachedCertRefusal([KNIHA_TYPE]);
        if (refusal) throw refusal;
      }
      const primary = ordered.find((f) => f.id === presetFacilityId) ?? ordered[0];
      const primaryMonths = months[primary.id] ?? null;
      const payload: InspectionDraftPayload = {
        type: KNIHA_TYPE,
        periodicity_value: sposob === 1 ? primaryMonths : null,
        periodicity_unit: sposob === 1 && primaryMonths ? 'mesiac' : null,
        executed_on: executedOn,
        company_id: ctx.company.id,
        facility_id: primary.id,
        details: knihaDetailsPayload(newKnihaDetails(sposob, manual, ctx.schvalil_default)),
        facilities: rows,
        ...(visit ? { visit_id: visit.id } : {}),
      };
      // Offline the entry is created locally and syncs later; ignored when the POST reaches the server.
      const optimistic = inspectionCreateOptimistic({
        payload,
        company: {
          id: ctx.company.id,
          name: ctx.company.name,
          ico: ctx.company.ico,
          billing_mode: company?.billing_mode,
        },
        facility: { id: primary.id, name: primary.name },
        inspector: { id: user?.id ?? 0, name: user?.fullname ?? '' },
        facilityNames: Object.fromEntries(ordered.map((f) => [f.id, f.name])),
      });
      const res = await Inspections.createDraft(payload, csrfToken, optimistic);
      navigate(knihaPath(res.inspection.id), { replace: true });
    } catch (err) {
      setSubmitting(false);
      if (certMissing(err, { returnLabel: 'Späť na zápis' })) return;
      setError(err instanceof ApiError ? err.message : 'Zápis sa nepodarilo uložiť.');
    }
  }

  const blocked =
    ordered.length === 0
      ? bd
        ? 'Vyber aspoň jeden bytový dom.'
        : 'Vyber aspoň jednu prevádzku.'
      : missingPeriodicity
        ? bd
          ? 'Vyber periodicitu pri každom bytovom dome.'
          : 'Vyber periodicitu pri každej prevádzke.'
        : !executedOn
          ? 'Zadaj dátum zápisu.'
          : null;

  return (
    <div className="flex flex-col gap-5">
      {visit ? (
        <Breadcrumb
          items={[
            ...visitTrail({ id: visit.id, companyName: visit.company_name, date: visit.visit_date }),
            { label: 'Požiarna kniha' },
          ]}
        />
      ) : (
        <Link to={backTo} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700">
          <ArrowLeft className="size-4" />
          {editing ? 'Späť na zápis' : 'Zrušiť'}
        </Link>
      )}

      <header className="animate-fade-up">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Krok 1 z 3 · Čoho sa zápis týka</p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Zápis do požiarnej knihy</h1>
        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500">
          <Building className="size-3" />
          {ctx.company.name}
          {executedOn && (
            <>
              <span className="text-ink-300">·</span>
              {formatDateSk(executedOn)}
            </>
          )}
        </p>
        <StepBars step={1} />
      </header>

      {/* Prevádzky */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <div className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">{nouns.tabLabel}</h2>
            <p className="mt-0.5 text-xs text-ink-500">Vyber jednu alebo viac.</p>
          </div>
          {ctx.facilities.length > 1 && (
            <Button type="button" id="kn-select-all" variant="ghost" size="sm" className="shrink-0" onClick={toggleAll}>
              {allSelected ? 'Zrušiť výber' : 'Označiť všetky'}
            </Button>
          )}
        </div>
        {ctx.facilities.length === 0 ? (
          <p className="rounded-xl bg-ink-50 px-3 py-2.5 text-sm text-ink-500">
            {bd ? 'Bytové družstvo zatiaľ nemá žiadny bytový dom.' : 'Firma zatiaľ nemá žiadnu prevádzku.'}
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {ctx.facilities.map((f) => {
              const on = selected.includes(f.id);
              const m = months[f.id] ?? null;
              const until = m ? validUntilLikeServer(executedOn, m) : null;
              const last = f.last_prehliadka?.valid_until ?? null;
              return (
                <li key={f.id} className="flex flex-col gap-2">
                  <CheckRow
                    id={`kn-facility-${f.id}`}
                    checked={on}
                    disabled={f.id === lockedId}
                    onToggle={() => toggle(f.id)}
                    title={f.name}
                    sub={
                      `${f.attach_count} ${sk3(f.attach_count, 'záznam', 'záznamy', 'záznamov')} na priloženie` +
                      (last ? ` · ďalšia prehliadka do ${formatDateSk(last)}` : '')
                    }
                  />
                  {on && sposob === 1 && (
                    <div className="animate-fade-in ml-8 flex flex-col gap-1.5">
                      <span className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Periodicita</span>
                      <div className="flex flex-wrap gap-2">
                        {PERIODICITY_MONTHS.map((n) => (
                          <Chip
                            key={n}
                            id={`kn-period-${f.id}-${n}`}
                            active={m === n}
                            onClick={() => setMonths((cur) => ({ ...cur, [f.id]: n }))}
                          >
                            {n} mes.
                          </Chip>
                        ))}
                      </div>
                      {m && until && (
                        <p className="text-xs text-ink-600">
                          Po vykonaní platí <b className="font-semibold text-ink-900">{m} {monthsWord(m)}</b> — ďalšia
                          prehliadka do <b className="font-semibold text-ink-900">{formatDateSk(until)}</b>.
                        </p>
                      )}
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Card>

      {/* Spôsob zápisu */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Spôsob zápisu</h2>
        <div role="radiogroup" aria-label="Spôsob zápisu" className="flex flex-col gap-2">
          <SposobCard
            n={1}
            active={sposob === 1}
            title="Zápis je zároveň preventívkou"
            meta={`Posúva termín vybraných ${nouns.pluralGen}.`}
            onClick={() => pickSposob(1)}
          />
          <SposobCard n={2} active={sposob === 2} title="Preventívky sú priložené" meta="Termín sa nemení." onClick={() => pickSposob(2)} />
        </div>
        {manual && (
          <Button
            type="button"
            id="kn-sposob-default"
            variant="ghost"
            size="sm"
            className="animate-fade-in self-start"
            leftIcon={<RotateCcw className="size-3.5" />}
            onClick={() => setManual(false)}
          >
            Vrátiť na predvolené
          </Button>
        )}
      </Card>

      {/* Dátum */}
      <Card className="animate-fade-up flex flex-col gap-4 p-4">
        {visit ? (
          <Field label="Dátum zápisu">
            {(p) => (
              <Input id={p.id} type="text" readOnly disabled value={formatDateSk(executedOn)} leftIcon={<CalendarDays className="size-4" />} />
            )}
          </Field>
        ) : (
          <Field label="Dátum zápisu" required>
            {(p) => (
              <Input
                {...p}
                id="kn-date"
                type="date"
                required
                leftIcon={<CalendarDays className="size-4" />}
                value={executedOn}
                onChange={(e) => setExecutedOn(e.target.value)}
              />
            )}
          </Field>
        )}
      </Card>

      {error && (
        <p role="alert" className="rounded-2xl bg-[var(--color-status-bad-bg)] px-4 py-3 text-sm text-[var(--color-status-bad)]">
          {error}
        </p>
      )}

      <div className="flex flex-col gap-2">
        {blocked !== null && (
          <p id="kn-step1-blocked" className="animate-fade-in text-center text-xs font-medium text-ink-600">
            {blocked}
          </p>
        )}
        <Button
          type="button"
          id="kn-step1-continue"
          loading={submitting}
          disabled={blocked !== null}
          aria-describedby={blocked !== null ? 'kn-step1-blocked' : undefined}
          rightIcon={<ArrowRight className="size-4" />}
          onClick={() => void submit()}
        >
          Pokračovať
        </Button>
      </div>
    </div>
  );
}

function SposobCard({
  n,
  active,
  title,
  meta,
  onClick,
}: {
  n: 1 | 2;
  active: boolean;
  title: string;
  meta: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      role="radio"
      id={`kn-sposob-${n}`}
      aria-checked={active}
      onClick={onClick}
      className={cn(
        'flex w-full items-start gap-3 rounded-2xl border px-3.5 py-3 text-left',
        'transition-[background-color,border-color,box-shadow,transform] duration-200 active:scale-[0.99]',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
        active
          ? 'border-firol-400 bg-firol-50 shadow-[var(--shadow-soft)]'
          : 'border-ink-200 bg-white hover:border-firol-300 hover:bg-firol-50/50',
      )}
    >
      <span
        aria-hidden
        className={cn(
          'grid size-8 shrink-0 place-items-center rounded-xl text-sm font-bold transition-colors duration-200',
          active ? 'bg-firol-500 text-white' : 'bg-ink-100 text-ink-600',
        )}
      >
        {n}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink-900">{title}</span>
        <span className="mt-0.5 block text-xs text-ink-500">{meta}</span>
      </span>
    </button>
  );
}

// ─── Without a company in the link ──────────────────────────────────────────

/** Reached from the type picker of a section, where no client is known yet. */
function CompanyChooser({ backTo, onPick }: { backTo: string; onPick: (companyId: number) => void }) {
  const [companies, setCompanies] = useState<CompanyListItem[] | null>(null);
  const [companyId, setCompanyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Companies.list()
      .then((res) => {
        if (cancelled) return;
        setCompanies(res.items);
        if (res.items.length === 1) setCompanyId(res.items[0].id);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať klientov.');
      });
    return () => {
      cancelled = true;
    };
  }, []);

  if (companies === null && !error) return <Step1Skeleton />;

  return (
    <div className="flex flex-col gap-5">
      <Link to={backTo} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700">
        <ArrowLeft className="size-4" />
        Späť
      </Link>
      <header className="animate-fade-up">
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">Zápis do požiarnej knihy</h1>
      </header>
      <Card className="animate-fade-up flex flex-col gap-4 p-4">
        <Field label={CLIENT_NEUTRAL.singular} required>
          {(p) => (
            <Select
              id={p.id}
              value={companyId !== null ? String(companyId) : ''}
              onChange={(v) => setCompanyId(v ? Number(v) : null)}
              placeholder={CLIENT_NEUTRAL.pickPlaceholder}
              leftIcon={<Building className="size-4" />}
              searchable
              options={(companies ?? []).map((c) => ({
                value: String(c.id),
                label: c.name,
                description: c.ico ? `IČO ${c.ico}` : undefined,
              }))}
            />
          )}
        </Field>
        {error && <p className="text-sm text-status-bad">{error}</p>}
        <Button
          type="button"
          id="kn-pick-company"
          disabled={companyId === null}
          rightIcon={<ArrowRight className="size-4" />}
          onClick={() => companyId !== null && onPick(companyId)}
        >
          Pokračovať
        </Button>
      </Card>
    </div>
  );
}

// ─── Chrome ─────────────────────────────────────────────────────────────────

function Step1Skeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-20" />
      <Skeleton className="h-7 w-56" />
      <Skeleton className="h-48 w-full rounded-2xl" />
      <Skeleton className="h-36 w-full rounded-2xl" />
    </div>
  );
}

function ErrorCard({ message, to }: { message: string; to: string }) {
  return (
    <div className="flex flex-col gap-4">
      <Link to={to} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700">
        <ArrowLeft className="size-4" />
        Späť
      </Link>
      <Card className="px-4 py-3 text-sm text-status-bad">{message}</Card>
    </div>
  );
}
