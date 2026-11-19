import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowLeft,
  Building,
  CalendarDays,
  CheckCircle2,
  CopyCheck,
  Download,
  FileText,
  Pencil,
  Save,
  Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  PHP_STATUS_LABELS,
  documentDownloadUrl,
  type InspectionType,
  type PhpStatus,
} from '@/api/inspections';
import {
  Prehliadka,
  type ChecklistGenerateResult,
  type ChecklistReview,
  type ReviewBlock,
  type ReviewItem,
} from '@/api/prehliadka';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { handleOfflineSave, offlineMessage } from '@/lib/offline';
import { mutationMatchesEntity } from '@/lib/pendingEntity';
import { drainQueue, listMutations } from '@/lib/queue';
import { prehliadkaPath } from '@/lib/prehliadka';
import { useToast } from '@/lib/toast';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { ClearTextButton } from '@/components/ui/ClearTextButton';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { Segmented } from '@/components/prehliadka/ui';
import { devicesLabel, originText, type ReviewNavState } from '@/components/prehliadka/ConceptChecklist';

/**
 * „Vytvoriť a rovno uzavrieť" — the review screen of the checklist after a
 * prehliadka (spec 12.2). Every ticked úkon is one block with its items and the
 * values that go into its protocol, the result included; those values are
 * edited in place (the ordinary item PATCH, queued offline), anything else in
 * the item's own form. One date for all (decision 4) and one „Vygenerovať":
 * each protocol is issued on its own, and one that cannot be — incomplete, an
 * oprávnenie missing — stays a draft and is named with the reason.
 *
 * URL: /prehliadky/:id/koncepty?ids=1,2,3 — the drafts to review, so the
 * screen survives a reload and „Späť" from an item's form.
 */

type ChoiceOption = { value: string; label: string };
type InlineField =
  | { key: string; label: string; kind: 'number'; unit: string; step: string; min: number; max: number }
  // `clearable`: a free text carried from the last protocol gets the „Vymazať text" button.
  // `max`: the length the item validator accepts, so the field stops there (round 6).
  | { key: string; label: string; kind: 'text'; optional?: true; clearable?: true; max?: number }
  | { key: string; label: string; kind: 'choice'; options: ChoiceOption[]; select?: boolean };

const PASS_FAIL: ChoiceOption[] = [
  { value: 'vyhovuje', label: 'Vyhovuje' },
  { value: 'nevyhovuje', label: 'Nevyhovuje' },
];
const PHP_STATUSES: ChoiceOption[] = (Object.keys(PHP_STATUS_LABELS) as PhpStatus[]).map((s) => ({
  value: s,
  label: PHP_STATUS_LABELS[s],
}));

/**
 * The values of each kind the review edits in place — the assessment fields of
 * its item form. The labels are the form's, which are also the names the
 * server uses for a missing field, so a field filled here drops out of „Chýba".
 */
const FIELDS: Partial<Record<InspectionType, InlineField[]>> = {
  php: [
    { key: 'status', label: 'Stav', kind: 'choice', options: PHP_STATUSES, select: true },
    { key: 'notes', label: 'Poznámky / závady', kind: 'text', optional: true, clearable: true },
  ],
  hydranty: [
    { key: 'hs', label: 'HS', kind: 'number', unit: 'MPa', step: '0.01', min: 0, max: 50 },
    { key: 'hd', label: 'HD', kind: 'number', unit: 'MPa', step: '0.01', min: 0, max: 50 },
    { key: 'q', label: 'Q', kind: 'number', unit: 'l/s', step: '0.01', min: 0, max: 100 },
    { key: 'defects', label: 'Zistené závady', kind: 'text', optional: true, clearable: true, max: 500 },
    { key: 'result', label: 'Výsledok kontroly', kind: 'choice', options: PASS_FAIL },
  ],
  pu_akcieschopnost: [
    { key: 'notes', label: 'Poznámky / závady', kind: 'text', optional: true, clearable: true },
    { key: 'result', label: 'Výsledok kontroly', kind: 'choice', options: PASS_FAIL },
  ],
  pu_udrzba: [
    { key: 'maintenance_work', label: 'Vykonané práce', kind: 'text', clearable: true },
    { key: 'notes', label: 'Poznámky / závady', kind: 'text', optional: true, clearable: true },
    { key: 'result', label: 'Výsledok', kind: 'choice', options: PASS_FAIL },
  ],
  nudzove_osvetlenie: [
    { key: 'duration_min', label: 'Doba svietenia v núdzovom režime', kind: 'number', unit: 'min', step: '1', min: 0, max: 600 },
    { key: 'notes', label: 'Poznámky / závady', kind: 'text', optional: true, clearable: true },
    { key: 'result', label: 'Výsledok testu', kind: 'choice', options: PASS_FAIL },
  ],
  // The závady travel with a rebrík / regál since the customer's answer of
  // 18. 11. 2026, so they are shown — and clearable — like the fire types' notes.
  rebriky: [
    { key: 'faults', label: 'Zistené závady', kind: 'text', optional: true, clearable: true, max: 500 },
    { key: 'result', label: 'Výsledok', kind: 'choice', options: [...PASS_FAIL, { value: 'vyradene', label: 'Vyradené' }] },
  ],
  regale: [
    { key: 'faults', label: 'Zistené závady', kind: 'text', optional: true, clearable: true, max: 500 },
    { key: 'result', label: 'Výsledok', kind: 'choice', options: [...PASS_FAIL, { value: 'vyradene', label: 'Vyradené' }] },
  ],
  oprava_ts_php: [{ key: 'notes', label: 'Poznámky', kind: 'text', optional: true }],
  vyradenie: [{ key: 'reason', label: 'Dôvod vyradenia', kind: 'text' }],
  ts_hadic: [
    { key: 'manufacturer', label: 'Výrobca', kind: 'text' },
    { key: 'working_pressure', label: 'Pracovný pretlak', kind: 'number', unit: 'MPa', step: '0.1', min: 0, max: 50 },
    { key: 'test_pressure', label: 'Skúšobný pretlak', kind: 'number', unit: 'MPa', step: '0.1', min: 0, max: 50 },
    { key: 'length', label: 'Dĺžka', kind: 'number', unit: 'm', step: '0.5', min: 0.1, max: 9999 },
    { key: 'year_of_manufacture', label: 'Rok výroby', kind: 'number', unit: 'r.', step: '1', min: 1900, max: new Date().getFullYear() },
    {
      key: 'result',
      label: 'Výsledok skúšky',
      kind: 'choice',
      options: [
        { value: 'vyhovuje', label: 'Funkčná' },
        { value: 'nevyhovuje', label: 'Nefunkčná' },
      ],
    },
    // Carried with the rest of the hose's data (decision 3), so it is shown too.
    { key: 'notes', label: 'Poznámky / závady', kind: 'text', optional: true, clearable: true },
  ],
};

type Edits = Record<number, Record<string, string>>;

function shown(v: unknown): string {
  if (typeof v === 'number') return String(v);
  return typeof v === 'string' ? v : '';
}

function valueOf(item: ReviewItem, edits: Edits, key: string): string {
  return edits[item.id]?.[key] ?? shown(item.fields[key]);
}

function isFilled(f: InlineField, raw: string): boolean {
  const v = raw.trim();
  if (f.kind === 'number') {
    const n = Number(v.replace(',', '.'));
    return v !== '' && Number.isFinite(n) && n >= f.min && n <= f.max;
  }
  if (f.kind === 'choice') return f.options.some((o) => o.value === v);
  return v !== '';
}

/** What is still missing, with the fields filled in on this screen taken off. */
function missingNow(type: InspectionType, item: ReviewItem, edits: Edits): string[] {
  const fields = FIELDS[type] ?? [];
  return item.missing.filter((label) => {
    const f = fields.find((x) => x.label === label);
    return !f || !isFilled(f, valueOf(item, edits, f.key));
  });
}

function isDirty(item: ReviewItem, edits: Edits): boolean {
  const e = edits[item.id];
  return !!e && Object.entries(e).some(([k, v]) => v !== shown(item.fields[k]));
}

/** The item as the items API takes it: its stored fields with the edits applied. */
function mergedFields(type: InspectionType, item: ReviewItem, edits: Edits): Record<string, unknown> {
  const out: Record<string, unknown> = { ...item.fields };
  for (const f of FIELDS[type] ?? []) {
    const raw = edits[item.id]?.[f.key];
    if (raw === undefined) continue;
    const v = raw.trim();
    out[f.key] =
      f.kind === 'number' ? (v === '' ? null : Number(v.replace(',', '.'))) : f.kind === 'text' && f.optional && v === '' ? null : v;
  }
  return out;
}

/**
 * The sentence after an item refusal. The server names the refused field
 * (`field`); an empty one is to be filled in, one with a value (a range or
 * length refusal) corrected — here when this screen shows the field, else in
 * the item's form (round 5 of the fix list of 16. 11. 2026).
 */
function refusalHint(err: ApiError, type: InspectionType, fields: Record<string, unknown>): string {
  const key = err.body && typeof err.body === 'object' && 'field' in err.body
    ? String((err.body as { field: unknown }).field)
    : null;
  const value = key !== null ? fields[key] : undefined;
  const filled = value !== null && value !== undefined && String(value).trim() !== '';
  const onScreen = key !== null && (FIELDS[type] ?? []).some((f) => f.key === key);
  const verb = filled ? 'Oprav' : 'Doplň';
  return onScreen ? `${verb} ho tu alebo vo formulári položky.` : `${verb} ho vo formulári položky.`;
}

export function PrehliadkaConceptsReviewPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const prehliadkaId = Number(idStr);
  const [params] = useSearchParams();
  const location = useLocation();
  const refused = (location.state as ReviewNavState | null)?.refused ?? [];
  const ids = useMemo(
    () => (params.get('ids') ?? '').split(',').map(Number).filter((n) => Number.isInteger(n) && n > 0),
    [params],
  );

  const { csrfToken } = useAuth();
  const toast = useToast();
  const isReadOnly = useIsReadOnly();

  const [data, setData] = useState<ChecklistReview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [edits, setEdits] = useState<Edits>({});
  const [itemErrors, setItemErrors] = useState<Record<number, string>>({});
  const [savingItem, setSavingItem] = useState<number | null>(null);
  const [date, setDate] = useState('');
  const [generating, setGenerating] = useState(false);
  const [generateError, setGenerateError] = useState<string | null>(null);
  const [outcome, setOutcome] = useState<ChecklistGenerateResult | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await Prehliadka.reviewChecklist(prehliadkaId, ids);
      setData(res);
      setDate((prev) => prev || res.prehliadka.executed_on || '');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : offlineMessage(err, 'Úkony sa nepodarilo načítať.'));
    }
  }, [prehliadkaId, ids]);

  useEffect(() => {
    void load();
  }, [load]);

  const backHref = `${prehliadkaPath(prehliadkaId)}/vysledok`;

  if (error && !data) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink href={backHref} />
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }
  if (!data) {
    return (
      <div className="flex flex-col gap-4" aria-busy>
        <Skeleton className="h-4 w-40" />
        <Skeleton className="h-7 w-64" />
        <Skeleton className="h-24 w-full rounded-2xl" />
        <Skeleton className="h-40 w-full rounded-2xl" />
      </div>
    );
  }

  const head = data.prehliadka;
  const drafts = data.blocks.filter((b) => b.status === 'draft');
  // An úkon of a visit carries the visit's date (chapter 9) — the server keeps
  // it whatever date is sent; the date on screen applies to the others.
  const visitDate = drafts.length > 0 && drafts.every((b) => b.visit_id !== null);
  const someVisit = drafts.some((b) => b.visit_id !== null);

  function setEdit(itemId: number, key: string, value: string) {
    setEdits((prev) => ({ ...prev, [itemId]: { ...prev[itemId], [key]: value } }));
    setItemErrors((prev) => {
      if (!(itemId in prev)) return prev;
      const next = { ...prev };
      delete next[itemId];
      return next;
    });
  }

  /** Save one item's edits: 'saved', 'queued' (offline, not on the server yet) or false on a refusal (shown at the item). */
  async function saveItem(block: ReviewBlock, item: ReviewItem): Promise<'saved' | 'queued' | false> {
    const fields = mergedFields(block.type, item, edits);
    setSavingItem(item.id);
    try {
      let stored = fields;
      let outcome: 'saved' | 'queued' = 'saved';
      try {
        const res = await Inspections.updateItem(block.inspection_id, item.id, fields as never, csrfToken);
        stored = res.item.fields;
      } catch (err) {
        if (!(err instanceof OfflineQueuedError)) throw err;
        handleOfflineSave(err, toast);
        outcome = 'queued';
      }
      // Keep what is now stored, and drop from „Chýba" what was filled in here.
      const missing = missingNow(block.type, item, edits);
      setData((prev) =>
        prev && {
          ...prev,
          blocks: prev.blocks.map((b) =>
            b.inspection_id !== block.inspection_id
              ? b
              : { ...b, items: b.items.map((it) => (it.id === item.id ? { ...it, fields: stored, missing } : it)) },
          ),
        },
      );
      setEdits((prev) => {
        const next = { ...prev };
        delete next[item.id];
        return next;
      });
      return outcome;
    } catch (err) {
      setItemErrors((prev) => ({
        ...prev,
        [item.id]: err instanceof ApiError ? `${err.message} ${refusalHint(err, block.type, fields)}` : 'Položku sa nepodarilo uložiť.',
      }));
      return false;
    } finally {
      setSavingItem(null);
    }
  }

  async function generate() {
    if (!data) return;
    setGenerateError(null);
    setGenerating(true);
    try {
      // What was edited here goes in first, so the protocols carry it.
      let queued = false;
      for (const block of drafts) {
        for (const item of block.items) {
          if (!isDirty(item, edits)) continue;
          const saved = await saveItem(block, item);
          if (!saved) {
            setGenerateError('Niektorú položku sa nepodarilo uložiť — oprav ju a skús znova.');
            return;
          }
          if (saved === 'queued') queued = true;
        }
      }
      // A change still waiting in the outbox would miss the protocol, and the
      // items of an issued úkon cannot change any more: send it first.
      const draftIds = drafts.map((b) => b.inspection_id);
      const pendingFor = async () =>
        (await listMutations()).some((m) => draftIds.some((id) => mutationMatchesEntity(m, { resource: 'inspections', id })));
      if (queued || (await pendingFor())) {
        await drainQueue().catch(() => undefined);
        if (await pendingFor()) {
          setGenerateError('Najprv sa musia odoslať uložené zmeny.');
          return;
        }
      }
      const res = await Prehliadka.generateChecklist(prehliadkaId, draftIds, visitDate ? null : date || null, csrfToken);
      setOutcome(res);
      if (res.generated.length > 0) {
        toast.success(
          res.generated.length === 1 ? 'Protokol vygenerovaný.' : `Vygenerované protokoly: ${res.generated.length}.`,
        );
      }
      await load();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
      setGenerateError(offlineMessage(err, 'Protokoly sa nepodarilo vygenerovať.'));
    } finally {
      setGenerating(false);
    }
  }

  const label = (t: InspectionType | null) => (t ? (INSPECTION_TYPE_LABELS[t] ?? t) : 'Úkon');

  return (
    <div className="flex flex-col gap-5">
      <BackLink href={backHref} />

      <header className="animate-fade-up">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Vytvoriť a rovno uzavrieť</p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Skontroluj hodnoty</h1>
        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500">
          <Building className="size-3" />
          {head.company_name}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />
          {head.facility_name}
        </p>
      </header>

      {outcome && <OutcomeCard outcome={outcome} label={label} />}

      {refused.length > 0 && !outcome && (
        <Card className="flex items-start gap-3 border-status-warn/40 bg-[var(--color-status-warn-bg)]/60 px-4 py-3 animate-fade-up">
          <AlertTriangle className="mt-0.5 size-4 shrink-0 text-status-warn" />
          <div className="min-w-0 text-xs text-ink-700">
            <p className="font-semibold text-ink-900">Nevytvorené</p>
            <ul className="mt-1 flex flex-col gap-1">
              {refused.map((r) => (
                <li key={r.key}>
                  <b className="font-semibold">{label(r.type)}</b> — {r.reason}
                </li>
              ))}
            </ul>
          </div>
        </Card>
      )}

      {drafts.length > 0 && (
        <Card className="flex flex-col gap-3 border-status-warn/30 bg-[var(--color-status-warn-bg)]/40 p-4">
          <div className="flex items-start gap-3">
            <span className="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-white text-status-warn">
              <CalendarDays className="size-4" />
            </span>
            <div className="flex-1">
              <p className="text-xs font-semibold uppercase tracking-wider text-status-warn">Dátum protokolov</p>
              <p className="mt-0.5 text-xs text-ink-600">
                {visitDate ? 'Dátum je spoločný pre celú návštevu — mení sa na návšteve.' : 'Zmeň ho, ak sa úkony robili v iný deň.'}
              </p>
            </div>
          </div>
          <input
            id="pp-review-date"
            type="date"
            value={visitDate ? (head.executed_on ?? '') : date}
            disabled={visitDate || generating}
            onChange={(e) => setDate(e.target.value)}
            aria-label="Dátum protokolov"
            className={cn(
              'h-11 w-full min-w-0 appearance-none rounded-xl border bg-white px-3 text-sm font-medium text-ink-900 transition-colors',
              'border-status-warn/40 hover:border-status-warn focus:border-status-warn focus:outline-none focus:ring-2 focus:ring-status-warn/30',
              'disabled:bg-ink-50 disabled:text-ink-500',
            )}
          />
        </Card>
      )}

      {data.blocks.map((block) => (
        <div key={block.inspection_id} className="flex flex-col gap-2">
          {block.status === 'draft' && <PendingSyncBanner resource="inspections" id={block.inspection_id} />}
          <BlockCard
            block={block}
            edits={edits}
            itemErrors={itemErrors}
            savingItem={savingItem}
            canEdit={!isReadOnly && !generating}
            visitNote={someVisit && !visitDate}
            onEdit={setEdit}
            onSave={(item) => void saveItem(block, item)}
          />
        </div>
      ))}

      {data.blocks.length === 0 && (
        <Card className="px-4 py-3 text-sm text-ink-600">Nie je čo uzavrieť — vráť sa na výsledok prehliadky.</Card>
      )}

      {drafts.length > 0 && !isReadOnly && (
        <div className="flex flex-col gap-2">
          {generateError && <Card className="px-3 py-2 text-sm text-status-bad">{generateError}</Card>}
          <Button
            type="button"
            id="pp-review-generate"
            loading={generating}
            leftIcon={<FileText className="size-4" />}
            onClick={() => void generate()}
          >
            {drafts.length === 1 ? 'Vygenerovať protokol' : `Vygenerovať protokoly (${drafts.length})`}
          </Button>
        </div>
      )}

      <Link to={backHref}>
        <Button type="button" variant="ghost" className="w-full">
          Späť na výsledok prehliadky
        </Button>
      </Link>
    </div>
  );
}

function BackLink({ href }: { href: string }) {
  return (
    <Link to={href} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
      <ArrowLeft className="size-4" />
      Späť na výsledok prehliadky
    </Link>
  );
}

function OutcomeCard({
  outcome,
  label,
}: {
  outcome: ChecklistGenerateResult;
  label: (t: InspectionType | null) => string;
}) {
  return (
    <Card className="flex flex-col gap-3 p-4 animate-fade-up" id="pp-review-outcome">
      {outcome.generated.length > 0 && (
        <div>
          <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-status-ok">
            <CheckCircle2 className="size-3.5" />
            Vygenerované
          </p>
          <ul className="mt-2 flex flex-col divide-y divide-ink-100">
            {outcome.generated.map((g) => (
              <li key={g.inspection_id} className="flex items-center gap-3 py-2">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-ink-900">{label(g.type)}</p>
                  <Link to={`/inspections/${g.inspection_id}`} className="font-mono text-xs text-firol-600 hover:underline">
                    {g.document.number}
                  </Link>
                </div>
                <a
                  href={documentDownloadUrl(g.document.id)}
                  target="_blank"
                  rel="noopener"
                  aria-label={`Stiahnuť ${g.document.number}`}
                  className="grid size-9 shrink-0 place-items-center rounded-xl text-ink-400 transition-colors duration-200 hover:bg-ink-50 hover:text-firol-600"
                >
                  <Download className="size-4" />
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}
      {outcome.skipped.length > 0 && (
        <div>
          <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-status-bad">
            <AlertTriangle className="size-3.5" />
            Nevygenerované — zostávajú rozpracované
          </p>
          <ul className="mt-2 flex flex-col gap-2">
            {outcome.skipped.map((s) => (
              <li key={s.inspection_id} className="text-xs text-ink-700">
                <Link to={`/inspections/${s.inspection_id}`} className="font-semibold text-ink-900 hover:text-firol-600">
                  {label(s.type)}
                </Link>{' '}
                — {s.reason}
              </li>
            ))}
          </ul>
        </div>
      )}
    </Card>
  );
}

function BlockCard({
  block,
  edits,
  itemErrors,
  savingItem,
  canEdit,
  visitNote,
  onEdit,
  onSave,
}: {
  block: ReviewBlock;
  edits: Edits;
  itemErrors: Record<number, string>;
  savingItem: number | null;
  canEdit: boolean;
  /** The batch mixes úkony of a visit with others: say which date this one keeps. */
  visitNote: boolean;
  onEdit: (itemId: number, key: string, value: string) => void;
  onSave: (item: ReviewItem) => void;
}) {
  const draft = block.status === 'draft';
  const fields = FIELDS[block.type] ?? [];
  const allCarried = block.items.length > 0 && block.items.every((it) => it.carried);

  return (
    <Card className="overflow-hidden animate-fade-up" id={`pp-review-${block.type}`}>
      <div className="flex items-start gap-3 border-b border-ink-100 px-4 py-3">
        <span
          className={cn(
            'mt-0.5 grid size-9 shrink-0 place-items-center rounded-2xl',
            draft ? 'bg-firol-50 text-firol-600' : 'bg-[var(--color-status-ok-bg)] text-status-ok',
          )}
        >
          {draft ? <CopyCheck className="size-4" /> : <CheckCircle2 className="size-4" />}
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-sm font-semibold text-ink-900">{INSPECTION_TYPE_LABELS[block.type] ?? block.type}</h2>
          <p className="text-xs text-ink-500">
            {devicesLabel(block.items.length)}
            {!draft && block.document && (
              <>
                {' · '}
                <Link to={`/inspections/${block.inspection_id}`} className="font-mono text-firol-600 hover:underline">
                  {block.document.number}
                </Link>
              </>
            )}
          </p>
          {draft && block.origin && (
            <p className="text-xs text-ink-500">
              {allCarried ? 'Položky ' : 'Označené položky '}
              {originText(block.origin)}
            </p>
          )}
          {draft && visitNote && block.visit_id !== null && (
            <p className="text-xs text-ink-500">
              Dátum návštevy{block.executed_on ? ` ${formatDateSk(block.executed_on)}` : ''}
            </p>
          )}
        </div>
        {!draft && <Badge tone="ok">Uzavreté</Badge>}
      </div>

      {draft && block.cert_missing && (
        <p className="flex items-start gap-2 border-b border-ink-100 bg-status-bad-bg/40 px-4 py-2.5 text-xs font-medium text-status-bad">
          <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
          {block.cert_missing}
        </p>
      )}

      {draft && (
        <ul className="divide-y divide-ink-100">
          {block.items.map((item) => (
            <ItemEditor
              key={item.id}
              block={block}
              item={item}
              fields={fields}
              edits={edits}
              error={itemErrors[item.id] ?? null}
              saving={savingItem === item.id}
              canEdit={canEdit}
              markCarried={!allCarried && item.carried}
              onEdit={onEdit}
              onSave={onSave}
            />
          ))}
        </ul>
      )}
    </Card>
  );
}

function ItemEditor({
  block,
  item,
  fields,
  edits,
  error,
  saving,
  canEdit,
  markCarried,
  onEdit,
  onSave,
}: {
  block: ReviewBlock;
  item: ReviewItem;
  fields: InlineField[];
  edits: Edits;
  error: string | null;
  saving: boolean;
  canEdit: boolean;
  markCarried: boolean;
  onEdit: (itemId: number, key: string, value: string) => void;
  onSave: (item: ReviewItem) => void;
}) {
  const missing = missingNow(block.type, item, edits);
  const dirty = isDirty(item, edits);
  const numbers = fields.filter((f) => f.kind === 'number');
  const others = fields.filter((f) => f.kind !== 'number');

  return (
    <li className="flex flex-col gap-2.5 px-4 py-3" data-review-item={item.id}>
      <div className="flex items-start gap-2">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-ink-900">{item.name}</p>
          {markCarried && block.origin && <p className="text-xs text-ink-400">{originText(block.origin)}</p>}
        </div>
        {canEdit && (
          <Link
            to={`/inspections/${block.inspection_id}/items/${item.id}`}
            aria-label="Upraviť položku"
            className="grid size-9 shrink-0 place-items-center rounded-xl text-ink-400 transition-colors duration-200 hover:bg-ink-50 hover:text-firol-600"
          >
            <Pencil className="size-4" />
          </Link>
        )}
      </div>

      {numbers.length > 0 && (
        <div
          className={cn(
            'grid gap-2',
            // HS / HD / Q fit three to a row; longer labels two, a single field the whole row.
            numbers.length === 1 ? 'grid-cols-1' : numbers.every((f) => f.label.length <= 3) ? 'grid-cols-3' : 'grid-cols-2',
          )}
        >
          {numbers.map((f) =>
            f.kind !== 'number' ? null : (
              <label key={f.key} className="flex min-w-0 flex-col gap-1">
                {/* The unit sits in the label: three fields share 360 px. */}
                <span className="truncate text-[11px] font-medium text-ink-500">
                  {f.label} <span className="font-normal text-ink-400">({f.unit})</span>
                </span>
                <Input
                  type="number"
                  inputMode="decimal"
                  step={f.step}
                  min={f.min}
                  max={f.max}
                  aria-label={`${f.label} (${f.unit})`}
                  disabled={!canEdit}
                  value={valueOf(item, edits, f.key)}
                  onChange={(e) => onEdit(item.id, f.key, e.target.value)}
                  className="h-10 px-2.5"
                />
              </label>
            ),
          )}
        </div>
      )}

      {others.map((f) =>
        f.kind === 'text' ? (
          <label key={f.key} className="flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">{f.label}</span>
            <ReviewTextInput
              disabled={!canEdit}
              clearable={f.clearable === true}
              maxLength={f.max}
              value={valueOf(item, edits, f.key)}
              onChange={(v) => onEdit(item.id, f.key, v)}
            />
          </label>
        ) : f.kind === 'choice' && f.select ? (
          <div key={f.key} className="flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">{f.label}</span>
            <Select
              value={valueOf(item, edits, f.key)}
              onChange={(v) => onEdit(item.id, f.key, v)}
              options={f.options}
              placeholder="Vyber"
              disabled={!canEdit}
            />
          </div>
        ) : f.kind === 'choice' ? (
          <div key={f.key} className={cn(!canEdit && 'pointer-events-none opacity-70')}>
            <Segmented
              label={f.label}
              value={valueOf(item, edits, f.key) || null}
              options={f.options}
              onChange={(v) => onEdit(item.id, f.key, v)}
            />
          </div>
        ) : null,
      )}

      {missing.length > 0 && (
        <p className="text-xs font-medium text-status-bad" data-missing>
          Chýba: {missing.join(', ')}
        </p>
      )}
      {error && <p className="text-xs font-medium text-status-bad">{error}</p>}

      {dirty && canEdit && (
        <Button
          type="button"
          size="sm"
          variant="secondary"
          className="self-end"
          loading={saving}
          leftIcon={<Save className="size-4" />}
          onClick={() => onSave(item)}
        >
          Uložiť
        </Button>
      )}
    </li>
  );
}

/** A text value edited in place; a carried free text gets the clear button. */
function ReviewTextInput({
  value,
  onChange,
  disabled,
  clearable,
  maxLength,
}: {
  value: string;
  onChange: (value: string) => void;
  disabled: boolean;
  clearable: boolean;
  maxLength?: number;
}) {
  const ref = useRef<HTMLInputElement>(null);
  return (
    <Input
      ref={ref}
      disabled={disabled}
      maxLength={maxLength}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="h-10"
      rightSlot={
        clearable ? (
          <ClearTextButton value={value} onClear={() => onChange('')} target={ref} disabled={disabled} />
        ) : undefined
      }
    />
  );
}
