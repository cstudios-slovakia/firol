import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Building, ChevronRight, Pencil, Plus, Trash2, Warehouse } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { Companies } from '@/api/companies';
import { clientNouns } from '@/lib/clientKind';
import { ExternalTerms, termState, type ExternalTerm, type ExternalTermSuggestion } from '@/api/externalTerms';
import { Inspections, type InspectionDetail } from '@/api/inspections';
import { Prehliadka, type CompanyActivities, type TermFacts } from '@/api/prehliadka';
import { ApiError } from '@/lib/api';
import { BOOK_LABELS, bookOfInspectionType, type BookKey } from '@/lib/bookMapping';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { useConfirm } from '@/lib/confirm';
import { offlineMessage } from '@/lib/offline';
import { validUntil } from '@/lib/periodicity';
import {
  ROUTINE_LABELS,
  TERM_KEY_PREHLIADKA_BOZP,
  TERM_KEY_PREHLIADKA_PO,
  effectivePeriodicityMonths,
  parseDetails,
  prehliadkaClosePath,
  prehliadkaPath,
  routineTermKey,
  type AutoTerm,
  type PrehliadkaDetails,
} from '@/lib/prehliadka';
import { useToast } from '@/lib/toast';
import { visitTrail } from '@/lib/visits';
import { Badge } from '@/components/ui/Badge';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { ExternalTermSheet } from '@/components/book/ExternalTermSheet';
import { CheckRow, StepBars } from '@/components/prehliadka/ui';
import { useDetailsSaver } from '@/pages/prehliadka/useDetailsSaver';

type Loaded = {
  detail: InspectionDetail;
  facts: TermFacts | null;
  activities: CompanyActivities | null;
  external: ExternalTerm[];
  suggestions: ExternalTermSuggestion[];
  facilities: { id: number; name: string }[];
  /** Type of the client — drives firma / bytové družstvo wording. */
  clientType: string | null;
};

const todayIso = (): string => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** The one place the training row of „doplniť školenie" gets its name (spec 6.6). */
const MISSING_TRAINING_NAME = 'Školenie o ochrane pred požiarmi';

/**
 * Krok 3 — Termíny a činnosti (spec 6.4, 6.6): one screen, the last step.
 * What was walked through (a plain list per record), the terms the app fills
 * in by itself — each can be overwritten by hand — and the firm's own terms
 * of revisions somebody else does.
 */
export function PrehliadkaTermsPage() {
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
        const asOf = i.executed_on ?? todayIso();
        // Everything beyond the prehliadka itself is optional: the step still works without it.
        const [facts, activities, external, company] = await Promise.all([
          Prehliadka.terms(i.facility_id, asOf).catch(() => null),
          Prehliadka.activities(i.company_id).catch(() => null),
          ExternalTerms.list(i.company_id, undefined, i.facility_id).catch(() => null),
          Companies.show(i.company_id).catch(() => null),
        ]);
        if (cancelled) return;
        setLoaded({
          detail,
          facts,
          activities,
          external: external?.items ?? [],
          suggestions: external?.suggestions ?? [],
          facilities: (company?.facilities ?? []).map((f) => ({ id: f.id, name: f.name })),
          clientType: company?.company.client_type ?? null,
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
  if (!loaded) return <TermsSkeleton />;
  return <TermsForm id={id} loaded={loaded} />;
}

function TermsForm({ id, loaded }: { id: number; loaded: Loaded }) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const isReadOnly = useIsReadOnly();

  const { inspection: i } = loaded.detail;
  const client = clientNouns(loaded.clientType);
  const saver = useDetailsSaver(id, parseDetails(i.details));
  const details: PrehliadkaDetails = saver.details ?? parseDetails(i.details);
  const canEdit = i.status === 'draft' && !isReadOnly;
  const executedOn = i.executed_on;

  const [activities, setActivities] = useState<CompanyActivities | null>(loaded.activities);
  const [external, setExternal] = useState<ExternalTerm[]>(loaded.external);
  const [overrideFor, setOverrideFor] = useState<AutoTerm | null>(null);
  const [customFor, setCustomFor] = useState<'po' | 'bozp' | null>(null);
  const [externalSheet, setExternalSheet] = useState<
    | { kind: 'add'; preset?: { name: string; book: BookKey } }
    | { kind: 'edit'; term: ExternalTerm }
    | null
  >(null);

  const books: BookKey[] = [...(details.scope.po ? (['po'] as const) : []), ...(details.scope.bozp ? (['bozp'] as const) : [])];

  // ── Doplnené samy ────────────────────────────────────────────────────────
  const autoTerms = useMemo<AutoTerm[]>(() => {
    const rows: AutoTerm[] = [];
    const own = { value: i.periodicity_value, unit: i.periodicity_unit };
    const pppMonths = effectivePeriodicityMonths(details);
    const ownSource = `${pppMonths} mesiacov od dnešnej prehliadky${
      details.lehota_vlastna ? ` (dohodnutá kratšia, zákonná ${details.lehota_zakon})` : ''
    }`;
    if (details.scope.po) {
      rows.push({
        key: TERM_KEY_PREHLIADKA_PO,
        label: 'Preventívna prehliadka',
        book: 'po',
        source: ownSource,
        valid_until: validUntil(executedOn, own),
      });
    }
    if (details.scope.bozp) {
      rows.push({
        key: TERM_KEY_PREHLIADKA_BOZP,
        label: 'Preventívna prehliadka BOZP',
        book: 'bozp',
        source: ownSource,
        valid_until: validUntil(executedOn, own),
      });
    }
    for (const type of details.routine) {
      const p = loaded.facts?.routine_periodicities[type] ?? { value: null, unit: null };
      rows.push({
        key: routineTermKey(type),
        label: ROUTINE_LABELS[type],
        book: bookOfInspectionType(type) ?? 'po',
        source: p.value === null ? 'z dnešnej kontroly, bez opakovania' : 'z dnešnej kontroly',
        valid_until: validUntil(executedOn, p),
      });
    }
    for (const t of loaded.facts?.trainings ?? []) {
      // A training of a book that has no record today has nowhere to be printed.
      if (!books.includes(t.book)) continue;
      rows.push({
        key: t.key,
        label: t.label,
        book: t.book,
        source: t.overridden ? 'zo školenia v appke, platnosť prepísaná rukou' : 'zo školenia v appke',
        valid_until: t.valid_until,
      });
    }
    return rows;
    // `books` is derived from details.scope, listed through it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [i.periodicity_value, i.periodicity_unit, executedOn, details.scope, details.routine, loaded.facts]);

  // „Doplniť školenie" is offered until the firm has a PO training — in the app, or written down
  // here as a term of its own (which is what the row's button does).
  const trainingMissing =
    details.scope.po &&
    !(loaded.facts?.trainings ?? []).some((t) => t.book === 'po') &&
    !external.some((t) => t.book === 'po' && /školenie/i.test(t.name));

  // ── Činnosti ─────────────────────────────────────────────────────────────
  function toggleActivity(kind: 'po' | 'bozp', label: string) {
    saver.update((d) => {
      const field = kind === 'po' ? 'activities_po' : 'activities_bozp';
      const cur = d[field];
      return { ...d, [field]: cur.includes(label) ? cur.filter((x) => x !== label) : [...cur, label] };
    });
  }

  async function addActivity(kind: 'po' | 'bozp', label: string): Promise<string | null> {
    try {
      const res = await Prehliadka.addActivity(i.company_id, kind, label, csrfToken);
      setActivities((cur) =>
        cur
          ? {
              ...cur,
              [kind]: {
                ...cur[kind],
                custom: cur[kind].custom.some((c) => c.id === res.activity.id)
                  ? cur[kind].custom
                  : [...cur[kind].custom, res.activity],
              },
            }
          : cur,
      );
      saver.update((d) => {
        const field = kind === 'po' ? 'activities_po' : 'activities_bozp';
        return d[field].includes(res.activity.label) ? d : { ...d, [field]: [...d[field], res.activity.label] };
      });
      toast.success(`Pridané. Ostane pri ${client.demo.loc}.`);
      return null;
    } catch (err) {
      return offlineMessage(err, 'Činnosť sa nepodarilo pridať.');
    }
  }

  async function removeActivity(kind: 'po' | 'bozp', activity: { id: number; label: string }) {
    const ok = await confirm({
      title: 'Odstrániť činnosť?',
      description: `„${activity.label}“ sa odstráni zo zoznamu ${client.demo.gen}. Už hotové záznamy sa nemenia.`,
      confirmLabel: 'Odstrániť',
    });
    if (!ok) return;
    try {
      await Prehliadka.removeActivity(i.company_id, activity.id, csrfToken);
    } catch (err) {
      toast.error(offlineMessage(err, 'Činnosť sa nepodarilo odstrániť.'));
      return;
    }
    setActivities((cur) =>
      cur ? { ...cur, [kind]: { ...cur[kind], custom: cur[kind].custom.filter((c) => c.id !== activity.id) } } : cur,
    );
    saver.update((d) => {
      const field = kind === 'po' ? 'activities_po' : 'activities_bozp';
      return { ...d, [field]: d[field].filter((x) => x !== activity.label) };
    });
    toast.success('Odstránené.');
  }

  // ── Prepísanie termínu ───────────────────────────────────────────────────
  function setOverride(key: string, date: string | null) {
    saver.update((d) => {
      const next = { ...d.term_overrides };
      if (date) next[key] = date;
      else delete next[key];
      return { ...d, term_overrides: next };
    });
  }

  // ── Termíny, ktoré nerobíme my ───────────────────────────────────────────
  const shownExternal = external.filter((t) => {
    if (t.book === 'ob') return details.scope.po || details.scope.bozp;
    return books.includes(t.book);
  });

  async function removeExternal(t: ExternalTerm) {
    const ok = await confirm({
      title: 'Odstrániť termín?',
      description: `„${t.name}“ sa odstráni zo zoznamu ${client.demo.gen}. Už hotové záznamy sa nemenia.`,
      confirmLabel: 'Odstrániť',
    });
    if (!ok) return;
    try {
      await ExternalTerms.remove(i.company_id, t.id, csrfToken);
      setExternal((cur) => cur.filter((x) => x.id !== t.id));
      toast.success('Odstránené.');
    } catch (err) {
      toast.error(offlineMessage(err, 'Termín sa nepodarilo odstrániť.'));
    }
  }

  function externalSaved(term: ExternalTerm) {
    setExternal((cur) => {
      const next = cur.some((x) => x.id === term.id) ? cur.map((x) => (x.id === term.id ? term : x)) : [...cur, term];
      // Soonest validity first, no date last — the order the record prints.
      return [...next].sort((a, b) =>
        a.valid_until === b.valid_until ? a.name.localeCompare(b.name, 'sk') : a.valid_until === null ? 1 : b.valid_until === null ? -1 : a.valid_until.localeCompare(b.valid_until),
      );
    });
    setExternalSheet(null);
  }

  const sets: { kind: 'po' | 'bozp'; ticked: string[] }[] = [
    ...(details.scope.po ? [{ kind: 'po' as const, ticked: details.activities_po }] : []),
    ...(details.scope.bozp ? [{ kind: 'bozp' as const, ticked: details.activities_bozp }] : []),
  ];

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
        <Link to={prehliadkaPath(id)} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Obchôdzka
        </Link>
      )}

      <header className="animate-fade-up">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Krok 3 z 3 · Termíny</p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Termíny a činnosti</h1>
        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500">
          <Building className="size-3" />
          {i.company_name}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />
          {i.facility_name}
        </p>
        <StepBars step={3} />
      </header>

      <PendingSyncBanner resource="inspections" id={id} />

      {/* Vykonané činnosti — one card per record, asked twice for „obidve". */}
      {sets.map(({ kind, ticked }) => (
        <ActivitiesCard
          key={kind}
          clientType={loaded.clientType}
          kind={kind}
          defaults={activities?.[kind].defaults ?? []}
          custom={activities?.[kind].custom ?? []}
          ticked={ticked}
          canEdit={canEdit}
          onToggle={(label) => toggleActivity(kind, label)}
          onAdd={() => setCustomFor(kind)}
          onRemove={(a) => void removeActivity(kind, a)}
        />
      ))}

      {/* Poznámka do záznamu */}
      <Card className="animate-fade-up flex flex-col gap-2 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Poznámka do záznamu</h2>
        <p className="text-xs text-ink-400">Nepovinné. Čo sa nezmestí do činností ani nedostatkov.</p>
        <textarea
          className="w-full rounded-xl border border-ink-200 bg-white p-3 text-sm text-ink-900 placeholder:text-ink-400 focus:border-firol-500 focus:outline-none focus:ring-1 focus:ring-firol-500 disabled:opacity-50 min-h-[72px]"
          placeholder="Kotolňa bola v čase prehliadky uzamknutá, správca prístup zabezpečí do konca mesiaca."
          value={details.note ?? ''}
          disabled={!canEdit}
          onChange={(e) => {
            const val = e.target.value;
            saver.update((d) => ({ ...d, note: val }));
          }}
        />
      </Card>

      {/* Doplnené samy */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Doplnené samy</h2>
        {autoTerms.length === 0 && !trainingMissing && (
          <p className="text-sm text-ink-500">Z tejto prehliadky nevznikajú žiadne termíny.</p>
        )}
        <ul className="flex flex-col divide-y divide-ink-100">
          {autoTerms.map((t) => {
            const override = details.term_overrides[t.key] ?? null;
            const date = override ?? t.valid_until;
            return (
              <li key={t.key} className="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-ink-900">{t.label}</p>
                  <p className="mt-0.5 text-xs text-ink-500">
                    ďalšia do <b className="text-ink-700">{date ? formatDateSk(date) : 'podľa potreby'}</b>
                    {' · '}
                    {override ? 'prepísané rukou' : t.source}
                    {' · '}
                    <span className={t.book === 'bozp' ? 'text-[#2D63A0]' : 'text-firol-700'}>{BOOK_LABELS[t.book as BookKey]}</span>
                  </p>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1.5">
                  <Badge tone={override ? 'warn' : 'ok'}>{override ? 'Prepísané' : 'Automaticky'}</Badge>
                  {canEdit && (
                    <button
                      type="button"
                      onClick={() => setOverrideFor(t)}
                      className="rounded-lg px-2 py-0.5 text-xs font-medium text-firol-700 transition-colors duration-150 hover:bg-firol-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
                    >
                      {override ? 'Zmeniť' : 'Prepísať'}
                    </button>
                  )}
                </div>
              </li>
            );
          })}
          {trainingMissing && (
            <li className="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-ink-900">{MISSING_TRAINING_NAME}</p>
                <p className="mt-0.5 text-xs text-ink-500">
                  V appke nie je žiadne školenie —{' '}
                  <span className="text-firol-700">{BOOK_LABELS.po}</span>
                </p>
              </div>
              {canEdit && (
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  id="pp-add-training"
                  onClick={() => setExternalSheet({ kind: 'add', preset: { name: MISSING_TRAINING_NAME, book: 'po' } })}
                >
                  Doplniť školenie
                </Button>
              )}
            </li>
          )}
        </ul>
        <p className="text-xs text-ink-400">
          Aj termín, ktorý appka pozná, sa dá prepísať — napríklad keď ste sa s klientom dohodli inak, než hovorí
          periodicita. Prepísaný termín je v zázname označený.
        </p>
      </Card>

      {/* Termíny, ktoré nerobíme my */}
      <section className="flex flex-col gap-2 animate-fade-up">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Termíny, ktoré nerobíme my</h2>
        {shownExternal.length === 0 && (
          <p className="rounded-2xl border border-dashed border-ink-200 bg-white px-4 py-4 text-sm text-ink-500">
            Zatiaľ žiadne. Revízie, ktoré u klienta robí niekto iný, sa tu zapíšu raz a nabudúce sa predvyplnia.
          </p>
        )}
        <ul className="flex flex-col gap-2">
          {shownExternal.map((t) => {
            const state = termState(t.valid_until, executedOn ?? todayIso());
            return (
              <li key={t.id}>
                <Card className="flex items-start gap-3 p-3.5">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-ink-900">{t.name}</p>
                    <p className="mt-0.5 text-xs text-ink-500">
                      {t.valid_until ? `platnosť do ${formatDateSk(t.valid_until)}` : 'nedoložené'}
                      {' · '}
                      <span className={t.book === 'bozp' ? 'text-[#2D63A0]' : 'text-firol-700'}>{t.book === 'ob' ? 'oba záznamy' : BOOK_LABELS[t.book]}</span>
                      {t.facility_name ? ` · ${t.facility_name}` : ''}
                    </p>
                    {state.tone !== 'ok' && (
                      <div className="mt-1.5">
                        <Badge tone={state.tone === 'bad' ? 'bad' : state.tone === 'warn' ? 'warn' : 'neutral'}>
                          {state.label}
                        </Badge>
                      </div>
                    )}
                  </div>
                  {canEdit && (
                    <div className="flex shrink-0 gap-1">
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        aria-label={`Upraviť termín ${t.name}`}
                        onClick={() => setExternalSheet({ kind: 'edit', term: t })}
                      >
                        <Pencil className="size-4" />
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        aria-label={`Odstrániť termín ${t.name}`}
                        onClick={() => void removeExternal(t)}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  )}
                </Card>
              </li>
            );
          })}
        </ul>
        {canEdit && (
          <Button
            type="button"
            id="pp-add-external"
            variant="secondary"
            className="border-dashed"
            leftIcon={<Plus className="size-4" />}
            onClick={() => setExternalSheet({ kind: 'add' })}
          >
            Pridať termín
          </Button>
        )}
        <p className="text-xs text-ink-400">
          Napríklad revízia elektroinštalácie, plynu, komínov alebo výťahu. Uložia sa k {client.cases.dat}, takže o rok ich už len
          aktualizujete. Termín sa vytlačí len v zázname tej knihy, ktorú zvolíte.
        </p>
      </section>

      <div className="flex gap-2">
        <Button type="button" variant="secondary" className="flex-1" onClick={() => navigate(prehliadkaPath(id))}>
          Späť
        </Button>
        <Button
          type="button"
          id="pp-to-close"
          className="flex-1"
          rightIcon={<ChevronRight className="size-4" />}
          onClick={async () => {
            await saver.flush();
            navigate(prehliadkaClosePath(id));
          }}
        >
          Na uzavretie
        </Button>
      </div>

      {overrideFor && (
        <OverrideSheet
          term={overrideFor}
          current={details.term_overrides[overrideFor.key] ?? null}
          onClose={() => setOverrideFor(null)}
          onSave={(date) => {
            setOverride(overrideFor.key, date);
            setOverrideFor(null);
          }}
        />
      )}

      {customFor && (
        <CustomActivitySheet
          kind={customFor}
          clientType={loaded.clientType}
          onClose={() => setCustomFor(null)}
          onSave={async (label) => {
            const err = await addActivity(customFor, label);
            if (err === null) setCustomFor(null);
            return err;
          }}
        />
      )}

      {externalSheet && (
        <ExternalTermSheet
          companyId={i.company_id}
          clientType={loaded.clientType}
          term={externalSheet.kind === 'edit' ? externalSheet.term : undefined}
          preset={externalSheet.kind === 'add' ? externalSheet.preset : undefined}
          currentBook={details.scope.po ? 'po' : 'bozp'}
          suggestions={loaded.suggestions}
          facilities={loaded.facilities}
          onClose={() => setExternalSheet(null)}
          onSaved={externalSaved}
        />
      )}
    </div>
  );
}

function ActivitiesCard({
  kind,
  clientType,
  defaults,
  custom,
  ticked,
  canEdit,
  onToggle,
  onAdd,
  onRemove,
}: {
  kind: 'po' | 'bozp';
  clientType: string | null;
  defaults: string[];
  custom: { id: number; label: string }[];
  ticked: string[];
  canEdit: boolean;
  onToggle: (label: string) => void;
  onAdd: () => void;
  onRemove: (a: { id: number; label: string }) => void;
}) {
  const customLabels = new Set(custom.map((c) => c.label));
  // Ticked here but gone from the firm's list since: still shown, so it can be unticked.
  const orphans = ticked.filter((t) => !defaults.includes(t) && !customLabels.has(t));
  const rows = [
    ...defaults.map((label) => ({ label, custom: null as { id: number; label: string } | null })),
    ...custom.map((c) => ({ label: c.label, custom: c })),
    ...orphans.map((label) => ({ label, custom: null as { id: number; label: string } | null })),
  ];
  const picked = rows.filter((r) => ticked.includes(r.label)).length;
  const target = kind === 'bozp' ? 'do záznamu Preventívna prehliadka BOZP' : 'do záznamu z preventívnej prehliadky';

  return (
    <Card className="animate-fade-up flex flex-col gap-3 p-4">
      <div>
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">
          Vykonané činnosti — {kind === 'bozp' ? 'BOZP' : 'ochrana pred požiarmi'}
        </h2>
        <p className="mt-1 text-xs text-ink-500">
          Odklikni, čo si naozaj prešiel. Ide to {target} ako zoznam — bez stavov pri jednotlivých položkách. Čo nie je
          medzi nedostatkami, bolo v poriadku.
        </p>
      </div>
      <ul className="flex flex-col gap-2">
        {rows.map((r) => (
          <li key={r.label} className="flex items-stretch gap-2">
            <div className="min-w-0 flex-1">
              <CheckRow
                checked={ticked.includes(r.label)}
                onToggle={() => onToggle(r.label)}
                disabled={!canEdit}
                title={
                  <>
                    {r.label}
                    {r.custom && <span className="ml-1.5 text-xs font-normal text-ink-400">· vlastná</span>}
                  </>
                }
              />
            </div>
            {canEdit && r.custom && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-auto self-stretch"
                aria-label={`Odstrániť činnosť ${r.label}`}
                onClick={() => onRemove(r.custom!)}
              >
                <Trash2 className="size-4 text-status-bad" />
              </Button>
            )}
          </li>
        ))}
      </ul>
      {canEdit && (
        <Button
          type="button"
          variant="secondary"
          className="border-dashed"
          id={`pp-add-activity-${kind}`}
          leftIcon={<Plus className="size-4" />}
          onClick={onAdd}
        >
          Pridať vlastnú činnosť
        </Button>
      )}
      <p className="text-xs text-ink-400">
        Vlastná činnosť ostane <b>pri {clientNouns(clientType).demo.loc}</b> a nabudúce sa ponúkne sama. Pri iných klientoch nie.
      </p>
      {picked > 0 ? (
        <p className="rounded-xl bg-[var(--color-status-ok-bg)] px-3 py-2 text-xs font-medium text-[var(--color-status-ok)]">
          Do záznamu pôjde {picked} {picked === 1 ? 'činnosť' : picked >= 2 && picked <= 4 ? 'činnosti' : 'činností'}.
        </p>
      ) : (
        <p className="rounded-xl bg-[var(--color-status-warn-bg)] px-3 py-2 text-xs font-medium text-[var(--color-status-warn)]">
          Zatiaľ nič neoznačené — sekcia „Vykonané činnosti“ sa do záznamu nevytlačí.
        </p>
      )}
    </Card>
  );
}

function CustomActivitySheet({
  kind,
  clientType,
  onClose,
  onSave,
}: {
  kind: 'po' | 'bozp';
  clientType: string | null;
  onClose: () => void;
  /** Resolves with an error message, or null when saved. */
  onSave: (label: string) => Promise<string | null>;
}) {
  const [label, setLabel] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (label.trim() === '') {
      setError('Zadaj, čo bolo kontrolované.');
      return;
    }
    setSaving(true);
    const err = await onSave(label.trim());
    if (err !== null) {
      setError(err);
      setSaving(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Vlastná činnosť"
      description={`Pridá sa do zoznamu vykonaných činností. Ostane pri ${clientNouns(clientType).demo.loc} aj pri ďalších prehliadkach.`}
      dismissible={!saving}
    >
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Čo bolo kontrolované" required error={error ?? undefined}>
          {(p) => (
            <Input
              {...p}
              value={label}
              autoFocus
              maxLength={191}
              placeholder={
                kind === 'bozp' ? 'Kontrola stavu rebríkov a schodíkov' : 'Kontrola lakovne — priestor so zvýšeným nebezpečenstvom'
              }
              onChange={(e) => {
                setLabel(e.target.value);
                setError(null);
              }}
            />
          )}
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={saving}>
            Zrušiť
          </Button>
          <Button type="submit" size="sm" id="pp-activity-save" loading={saving}>
            Pridať
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function OverrideSheet({
  term,
  current,
  onClose,
  onSave,
}: {
  term: AutoTerm;
  current: string | null;
  onClose: () => void;
  /** null clears the override and the term follows its periodicity again. */
  onSave: (date: string | null) => void;
}) {
  const [date, setDate] = useState(current ?? '');

  return (
    <Dialog
      open
      onClose={onClose}
      title="Prepísať termín"
      description={`„${term.label}“ — appka si ho doplnila sama. Keď to má byť inak, zadaj vlastný dátum.`}
    >
      <form
        className="flex flex-col gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          onSave(date || null);
        }}
      >
        <Field label="Platnosť do">
          {(p) => <Input {...p} type="date" value={date} autoFocus onChange={(e) => setDate(e.target.value)} />}
        </Field>
        <p className="-mt-2 text-xs text-ink-500">
          Prepísaný termín sa vytlačí a zapíše do knihy. Kalendár ďalej ide podľa periodicity.
        </p>
        <div className="flex flex-wrap justify-end gap-2">
          {current && (
            <Button type="button" variant="ghost" size="sm" onClick={() => onSave(null)}>
              Zrušiť prepísanie
            </Button>
          )}
          <Button type="button" variant="ghost" size="sm" onClick={onClose}>
            Zavrieť
          </Button>
          <Button type="submit" size="sm" id="pp-override-save" disabled={date === '' && current === null}>
            Uložiť
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function TermsSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-32" />
      <Skeleton className="h-7 w-64" />
      <Skeleton className="h-64 w-full rounded-2xl" />
      <Skeleton className="h-40 w-full rounded-2xl" />
    </div>
  );
}
