import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, CheckCircle2, Plus, Warehouse, X } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { Companies, type CompanyPerson } from '@/api/companies';
import { Facilities } from '@/api/facilities';
import { Inspections, type InspectionDetail } from '@/api/inspections';
import { Prehliadka, type PreviousDefects } from '@/api/prehliadka';
import { ApiError } from '@/lib/api';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { offlineMessage } from '@/lib/offline';
import { entityPendingMutations } from '@/lib/pendingEntity';
import { responsibleForFacility } from '@/lib/responsiblePerson';
import { useToast } from '@/lib/toast';
import { usePendingMutations } from '@/lib/usePendingMutations';
import {
  ROUTINE_LABELS,
  effectiveDeadline,
  parseDefect,
  parseDetails,
  prehliadkaResultPath,
  prehliadkaTermsPath,
  scopeApplies,
  scopeLabel,
  type DefectFields,
  type PrehliadkaDetails,
  type PrehliadkaPerson,
  type PreviousStatus,
} from '@/lib/prehliadka';
import { visitTrail } from '@/lib/visits';
import { Badge } from '@/components/ui/Badge';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { Chip } from '@/components/prehliadka/ui';
import { useDetailsSaver } from '@/pages/prehliadka/useDetailsSaver';

type Loaded = {
  detail: InspectionDetail;
  previous: PreviousDefects | null;
  persons: CompanyPerson[];
  /** The zodpovedná osoba the „prevzal" field is prefilled with (spec 6.6), if there is one. */
  handedOverDefault: PrehliadkaPerson | null;
};

function nedostatkyLabel(n: number): string {
  if (n === 1) return '1 nedostatok';
  if (n >= 2 && n <= 4) return `${n} nedostatky`;
  return `${n} nedostatkov`;
}

/**
 * Uzavretie (spec 6.7): the summary before the prehliadka is closed — how many
 * defects, how many put right, the nearest deadline and what will come out of
 * it — and the two things the record needs from the technician: who was
 * present (§ 14 ods. 3 asks for them as a list) and who took it over for the
 * organisation. Both are required to close.
 */
export function PrehliadkaClosePage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);

  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const detail = await Inspections.show(id);
        const i = detail.inspection;
        const [previous, persons, company, facility] = await Promise.all([
          Prehliadka.previous(i.facility_id, id).catch(() => null),
          Companies.persons(i.company_id).then((r) => r.items).catch(() => [] as CompanyPerson[]),
          Companies.show(i.company_id).catch(() => null),
          Facilities.show(i.facility_id).catch(() => null),
        ]);
        if (cancelled) return;
        const resolved = responsibleForFacility(
          i.facility_id,
          facility?.facility.contact_person ?? null,
          company?.company.approver ?? null,
          persons,
        );
        setLoaded({
          detail,
          previous,
          persons,
          handedOverDefault: resolved ? { name: resolved.fullname, role: resolved.role_title } : null,
        });
      } catch (err) {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Prehliadku sa nepodarilo načítať.');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }
  if (!loaded) return <CloseSkeleton />;
  return <CloseForm id={id} loaded={loaded} />;
}

function CloseForm({ id, loaded }: { id: number; loaded: Loaded }) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const isReadOnly = useIsReadOnly();
  const pendingMutations = usePendingMutations();
  const { inspection: i, items } = loaded.detail;
  const saver = useDetailsSaver(id, parseDetails(i.details));
  const details: PrehliadkaDetails = saver.details ?? parseDetails(i.details);
  const canEdit = i.status === 'draft' && !isReadOnly;

  const [closing, setClosing] = useState(false);
  const [closeError, setCloseError] = useState<string | null>(null);
  // Closing reads the prehliadka on the server, so what is still waiting in the
  // outbox (defects, photos, the answers saved here) has to get there first.
  const unsynced =
    saver.pending || entityPendingMutations(pendingMutations, { resource: 'inspections', id }).length > 0;

  // The person who took it over is prefilled once (spec 6.6) and then stays whatever the technician makes of it.
  const prefilled = useRef(false);
  useEffect(() => {
    if (prefilled.current || !saver.details) return;
    prefilled.current = true;
    if (!saver.details.handed_over && loaded.handedOverDefault && canEdit) {
      saver.update((d) => (d.handed_over ? d : { ...d, handed_over: loaded.handedOverDefault }));
    }
    // Runs once, when the saved details first arrive.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [saver.details]);

  const defects = useMemo(
    () => items.flatMap((item) => parseDefect(item.fields) ?? []),
    [items],
  );

  // ── Súhrn ────────────────────────────────────────────────────────────────
  // The record prints one point per „Uložiť a ďalšie zariadenie" group, so the summary counts the same.
  const pointCount = (list: DefectFields[]): number =>
    new Set(list.map((d, n) => d.group_key ?? `i${n}`)).size;
  const poCount = pointCount(defects.filter((d) => d.scope !== 'bo'));
  const bozpCount = pointCount(defects.filter((d) => d.scope !== 'po'));

  const previousCounts = useMemo(() => {
    const counts: Record<PreviousStatus | 'waiting', number> = { ok: 0, trva: 0, zanik: 0, waiting: 0 };
    const applicable = (loaded.previous?.defects ?? []).filter((d) => scopeApplies(d.scope, details.scope));
    for (const d of applicable) {
      const status = details.previous.find((e) => e.key === d.key)?.status ?? null;
      // „Trvá" only counts while the defect carried over exists.
      const carried = defects.some((x) => x.persists_from?.key === d.key);
      const effective = status === 'trva' && !carried ? null : status;
      counts[effective ?? 'waiting'] += 1;
    }
    return { counts, total: applicable.length };
  }, [loaded.previous, details.previous, details.scope, defects]);

  const nearest = useMemo(() => nearestDeadline(defects, i.executed_on), [defects, i.executed_on]);

  // ── Prítomní a prevzal ───────────────────────────────────────────────────
  const [adding, setAdding] = useState(false);
  const [newName, setNewName] = useState('');
  const [newRole, setNewRole] = useState('');

  function addPresent(p: PrehliadkaPerson) {
    saver.update((d) =>
      d.present.some((x) => x.name.toLowerCase() === p.name.toLowerCase()) ? d : { ...d, present: [...d.present, p] },
    );
  }
  function removePresent(name: string) {
    saver.update((d) => ({ ...d, present: d.present.filter((x) => x.name !== name) }));
  }
  function submitNew(e: React.FormEvent) {
    e.preventDefault();
    if (newName.trim() === '') return;
    addPresent({ name: newName.trim(), role: newRole.trim() || null });
    setNewName('');
    setNewRole('');
    setAdding(false);
  }

  const handed = details.handed_over;
  function setHanded(patch: Partial<PrehliadkaPerson>) {
    saver.update((d) => {
      const next = { name: d.handed_over?.name ?? '', role: d.handed_over?.role ?? null, ...patch };
      // An emptied name clears the person — an empty one cannot be stored.
      return { ...d, handed_over: next.name.trim() === '' ? null : { name: next.name, role: next.role } };
    });
  }

  const offeredPersons = loaded.persons.filter(
    (p) => !details.present.some((x) => x.name.toLowerCase() === p.fullname.toLowerCase()),
  );

  const missing: string[] = [];
  if (details.present.length === 0) missing.push('Doplň, kto bol prítomný.');
  if (!handed) missing.push('Doplň, kto prehliadku prevzal za organizáciu.');

  async function close() {
    setCloseError(null);
    setClosing(true);
    try {
      await saver.flush();
      await Prehliadka.close(id, csrfToken);
      toast.success('Prehliadka je uzavretá.');
      navigate(prehliadkaResultPath(id), { replace: true });
    } catch (err) {
      setCloseError(offlineMessage(err, 'Prehliadku sa nepodarilo uzavrieť.'));
      setClosing(false);
    }
  }

  return (
    <div className="flex flex-col gap-5">
      {i.visit_id !== null ? (
        <Breadcrumb
          items={[
            ...visitTrail({ id: i.visit_id, companyName: i.company_name, date: i.executed_on }),
            { label: 'Preventívna prehliadka' },
          ]}
        />
      ) : (
        <Link
          to={prehliadkaTermsPath(id)}
          className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700"
        >
          <ArrowLeft className="size-4" />
          Termíny
        </Link>
      )}

      <header className="animate-fade-up">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Uzavretie</p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Prítomní a prevzatie</h1>
        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500">
          <Building2 className="size-3" />
          {i.company_name}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />
          {i.facility_name}
          {i.executed_on && (
            <>
              <span className="text-ink-300">·</span>
              {formatDateSk(i.executed_on)}
            </>
          )}
        </p>
      </header>

      <PendingSyncBanner resource="inspections" id={id} />

      {/* Súhrn */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Súhrn prehliadky</h2>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          <Stat label="Nové nedostatky" value={String(pointCount(defects.filter((d) => !d.persists_from)))} />
          <Stat
            label="Najbližší termín"
            value={nearest === null ? '—' : nearest === 'bezodkladne' ? 'bezodkladne' : formatDateSk(nearest)}
          />
          {previousCounts.total > 0 && (
            <>
              <Stat
                label="Z minulej prehliadky odstránené"
                value={`${previousCounts.counts.ok} z ${previousCounts.total}`}
              />
              <Stat
                label="Trvá · dôvod zanikol"
                value={`${previousCounts.counts.trva} · ${previousCounts.counts.zanik}`}
              />
            </>
          )}
        </dl>
        {previousCounts.counts.waiting > 0 && (
          <p className="rounded-xl bg-[var(--color-status-warn-bg)] px-3 py-2 text-xs font-medium text-[var(--color-status-warn)]">
            {previousCounts.counts.waiting} nedostatkov z minulej prehliadky ešte čaká na overenie.
          </p>
        )}
      </Card>

      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Čo z prehliadky vznikne</h2>
        <ul className="flex flex-col gap-3">
          {details.scope.po && (
            <Output tag={<Badge tone="brand">PO</Badge>} title="Záznam z preventívnej prehliadky" meta={nedostatkyLabel(poCount)} />
          )}
          {details.scope.bozp && (
            <Output
              tag={<Badge className="bg-[#E4EEF9] text-[#2D63A0]">BOZP</Badge>}
              title="Zápis do knihy BOZP"
              meta={`${nedostatkyLabel(bozpCount)} · je zároveň záznamom z prehliadky BOZP`}
            />
          )}
          {details.scope.po && (
            <Output
              tag={<Badge tone={details.book_entry ? 'ok' : 'neutral'}>zápis</Badge>}
              title="Zápis do požiarnej knihy"
              meta={
                details.book_entry
                  ? 'pridá sa do knihy firmy ako bod'
                  : 'neoznačené — appka ho ponúkne po uzavretí'
              }
            />
          )}
          {details.routine.map((type) => (
            <Output
              key={type}
              tag={<Badge>koncept</Badge>}
              title={ROUTINE_LABELS[type]}
              meta="rozrobená kontrola — treba ju skontrolovať a potvrdiť"
            />
          ))}
        </ul>
        <p className="text-xs text-ink-400">Prehliadka: {scopeLabel(details.scope)}.</p>
      </Card>

      {/* Prítomní */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <div>
          <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">
            Prítomní<span className="ml-1 text-firol-500">*</span>
          </h2>
          <p className="mt-1 text-xs text-ink-500">
            Kto bol pri prehliadke — § 14 ods. 3 vyhlášky č. 121/2002 žiada, aby boli v zázname uvedení.
          </p>
        </div>

        {details.present.length > 0 && (
          <ul className="flex flex-col gap-2">
            {details.present.map((p) => (
              <li
                key={p.name}
                className="flex items-center gap-2 rounded-2xl border border-ink-200 bg-white px-3.5 py-2.5"
              >
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-ink-900">{p.name}</p>
                  {p.role && <p className="text-xs text-ink-500">{p.role}</p>}
                </div>
                {canEdit && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-label={`Odstrániť ${p.name}`}
                    onClick={() => removePresent(p.name)}
                  >
                    <X className="size-4" />
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}

        {canEdit && offeredPersons.length > 0 && (
          <div className="flex flex-col gap-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Osoby firmy</span>
            <div className="flex flex-wrap gap-2">
              {offeredPersons.map((p) => (
                <Chip
                  key={p.id}
                  onClick={() => addPresent({ name: p.fullname, role: p.role_title || null })}
                >
                  + {p.fullname}
                  {p.role_title ? ` · ${p.role_title}` : ''}
                </Chip>
              ))}
            </div>
          </div>
        )}

        {canEdit &&
          (adding ? (
            <form onSubmit={submitNew} className="flex flex-col gap-3 rounded-2xl bg-ink-50 p-3" noValidate>
              <Field label="Meno a priezvisko" required>
                {(p) => (
                  <Input
                    {...p}
                    value={newName}
                    autoFocus
                    maxLength={191}
                    placeholder="Ing. Peter Správca"
                    onChange={(e) => setNewName(e.target.value)}
                  />
                )}
              </Field>
              <Field label="Funkcia">
                {(p) => (
                  <Input
                    {...p}
                    value={newRole}
                    maxLength={191}
                    placeholder="predseda družstva"
                    onChange={(e) => setNewRole(e.target.value)}
                  />
                )}
              </Field>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={() => setAdding(false)}>
                  Zrušiť
                </Button>
                <Button type="submit" size="sm" id="pp-present-save" disabled={newName.trim() === ''}>
                  Pridať
                </Button>
              </div>
            </form>
          ) : (
            <Button
              type="button"
              id="pp-present-add"
              variant="secondary"
              className="border-dashed"
              leftIcon={<Plus className="size-4" />}
              onClick={() => setAdding(true)}
            >
              Pridať inú osobu
            </Button>
          ))}
      </Card>

      {/* Prevzal za organizáciu */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">
          Prevzal za organizáciu<span className="ml-1 text-firol-500">*</span>
        </h2>
        <Field label="Meno a priezvisko">
          {(p) => (
            <Input
              {...p}
              value={handed?.name ?? ''}
              disabled={!canEdit}
              maxLength={191}
              onChange={(e) => setHanded({ name: e.target.value })}
            />
          )}
        </Field>
        <Field label="Funkcia">
          {(p) => (
            <Input
              {...p}
              value={handed?.role ?? ''}
              disabled={!canEdit || !handed}
              maxLength={191}
              placeholder="predseda družstva"
              onChange={(e) => setHanded({ role: e.target.value })}
            />
          )}
        </Field>
        <p className="text-xs text-ink-400">
          Predvyplnené podľa osoby pri prevádzke, inak pri firme — dá sa prepísať. Zápis v požiarnej knihe podpisuje podľa
          § 29 ods. 1 vedúci zamestnanec organizačnej zložky aj ten, kto zápis vyhotovil — ten sa vytlačí a podpíše.
        </p>
      </Card>

      <div className="flex flex-col gap-2">
        {missing.length > 0 ? (
          <ul className="flex flex-col gap-1 rounded-2xl bg-[var(--color-status-warn-bg)] px-4 py-3 text-xs font-medium text-[var(--color-status-warn)]">
            {missing.map((m) => (
              <li key={m}>{m}</li>
            ))}
          </ul>
        ) : (
          <p className="flex items-center gap-2 rounded-2xl bg-[var(--color-status-ok-bg)] px-4 py-3 text-xs font-medium text-[var(--color-status-ok)]">
            <CheckCircle2 className="size-4" />
            Všetko potrebné je vyplnené.
          </p>
        )}
        {unsynced && canEdit && (
          <p className="rounded-2xl bg-[var(--color-status-warn-bg)] px-4 py-3 text-xs font-medium text-[var(--color-status-warn)]">
            Niektoré zmeny sa ešte neodoslali na server. Uzavrieť sa dá, keď budeš online a všetko sa uloží.
          </p>
        )}
        {closeError && (
          <p role="alert" className="rounded-2xl bg-[var(--color-status-bad-bg)] px-4 py-3 text-xs font-medium text-[var(--color-status-bad)]">
            {closeError}
          </p>
        )}
        {canEdit ? (
          <Button
            type="button"
            id="pp-close"
            loading={closing}
            disabled={missing.length > 0 || unsynced}
            onClick={() => void close()}
          >
            Uzavrieť a vytvoriť dokumenty
          </Button>
        ) : (
          <Link to={prehliadkaResultPath(id)}>
            <Button type="button" id="pp-to-result" className="w-full">
              Prehliadka je uzavretá — zobraziť dokumenty
            </Button>
          </Link>
        )}
        <p className="text-center text-xs text-ink-400">
          Uzavretie potrebuje pripojenie na internet — vydávajú sa čísla dokumentov. Až potom sa zápis a záznam nedajú
          meniť; na úpravy slúži „Upraviť".
        </p>
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-ink-500">{label}</dt>
      <dd className="mt-0.5 text-base font-semibold text-ink-900">{value}</dd>
    </div>
  );
}

function Output({ tag, title, meta }: { tag: React.ReactNode; title: string; meta: string }) {
  return (
    <li className="flex items-start gap-3">
      <span className="mt-0.5 shrink-0">{tag}</span>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold text-ink-900">{title}</p>
        <p className="text-xs text-ink-500">{meta}</p>
      </div>
    </li>
  );
}

/** The nearest deadline of the defects — `bezodkladne` outranks any date. */
function nearestDeadline(defects: DefectFields[], executedOn: string | null): string | 'bezodkladne' | null {
  let best: string | null = null;
  for (const d of defects) {
    if (d.deadline_choice === 'bezodkladne') return 'bezodkladne';
    const date = effectiveDeadline(d, executedOn);
    if (date && (best === null || date < best)) best = date;
  }
  return best;
}

function CloseSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-32" />
      <Skeleton className="h-7 w-64" />
      <Skeleton className="h-40 w-full rounded-2xl" />
      <Skeleton className="h-48 w-full rounded-2xl" />
    </div>
  );
}
