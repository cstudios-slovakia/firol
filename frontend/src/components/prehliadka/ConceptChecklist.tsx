/**
 * The checklist after a closed prehliadka (spec 12.2): everything the round
 * gives rise to — the concepts of its routine checks and, once the PHP or
 * hydranty check is closed, its follow-ups — in one list with tick-boxes and
 * one action, „Vytvoriť a rovno uzavrieť", which leads to the review screen
 * (nothing is issued from here). Each existing úkon keeps its own link, so
 * finishing one by one works as before.
 *
 * When the list cannot be read (offline, never seen online) the plain links of
 * the result answer are shown instead.
 */
import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Check, CheckCircle2, ChevronRight, ListChecks, Plus, ShieldCheck } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { INSPECTION_TYPE_LABELS } from '@/api/inspections';
import {
  Prehliadka,
  type ChecklistEntry,
  type ChecklistOrigin,
  type ChecklistRefusal,
  type PrehliadkaConcept,
} from '@/api/prehliadka';
import { ApiError } from '@/lib/api';
import { useCertMissingToast } from '@/lib/certMissing';
import { cn } from '@/lib/cn';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Skeleton } from '@/components/ui/Skeleton';

/** Router state the review screen reads: what could not even be created. */
export type ReviewNavState = { refused?: ChecklistRefusal[] };

/** „prevzaté z protokolu č. … zo dňa …" — the number is missing on old data without a document. */
export function originText(origin: ChecklistOrigin): string {
  const date = origin.executed_on ? ` zo dňa ${formatDateSk(origin.executed_on)}` : '';
  return origin.number ? `prevzaté z protokolu č. ${origin.number}${date}` : `prevzaté z kontroly${date}`;
}

export function devicesLabel(n: number): string {
  return `${n} ${n === 1 ? 'zariadenie' : n >= 2 && n <= 4 ? 'zariadenia' : 'zariadení'}`;
}

/** Follow-ups carry no výsledok of their own to count. */
const NO_RESULT = new Set(['oprava_ts_php', 'vyradenie']);

export function ConceptChecklist({
  prehliadkaId,
  fallback,
  readOnly,
}: {
  prehliadkaId: number;
  /** The concepts of the result answer — shown as plain links when the list cannot load. */
  fallback: PrehliadkaConcept[];
  readOnly: boolean;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const certMissing = useCertMissingToast();
  const navigate = useNavigate();

  const [entries, setEntries] = useState<ChecklistEntry[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [preparing, setPreparing] = useState(false);
  const [creating, setCreating] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await Prehliadka.checklist(prehliadkaId);
      setEntries(res.entries);
      setFailed(false);
    } catch {
      setFailed(true);
    }
  }, [prehliadkaId]);

  useEffect(() => {
    void load();
  }, [load]);

  function toggle(key: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  async function prepare() {
    setPreparing(true);
    try {
      const res = await Prehliadka.prepareChecklist(prehliadkaId, [...selected], csrfToken);
      if (res.inspection_ids.length === 0) {
        toast.error(res.refused.map((r) => r.reason).join(' ') || 'Žiadny úkon sa nepodarilo pripraviť.');
        void load();
        return;
      }
      const state: ReviewNavState = { refused: res.refused };
      navigate(`/prehliadky/${prehliadkaId}/koncepty?ids=${res.inspection_ids.join(',')}`, { state });
    } catch (err) {
      toast.error(offlineMessage(err, 'Úkony sa nepodarilo pripraviť.'));
    } finally {
      setPreparing(false);
    }
  }

  /** A follow-up with nothing to carry: its blank draft — no value and no result presumed. */
  async function createManual(entry: ChecklistEntry) {
    setCreating(entry.key);
    try {
      const res = await Prehliadka.createChecklistManual(prehliadkaId, entry.key, csrfToken);
      navigate(`/inspections/${res.inspection_id}`);
    } catch (err) {
      if (!certMissing(err, { returnLabel: 'Späť na prehliadku' })) {
        toast.error(err instanceof ApiError ? err.message : offlineMessage(err, 'Koncept sa nepodarilo vytvoriť.'));
      }
    } finally {
      setCreating(null);
    }
  }

  if (failed) {
    return fallback.length === 0 ? null : (
      <section className="flex flex-col gap-2 animate-fade-up">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Koncepty kontrol</h2>
        {fallback.map((c) => (
          <FallbackLink key={c.inspection_id} concept={c} />
        ))}
      </section>
    );
  }

  if (entries === null) {
    return (
      <section className="flex flex-col gap-2" aria-busy>
        <Skeleton className="h-4 w-36" />
        <Skeleton className="h-16 w-full rounded-2xl" />
        <Skeleton className="h-16 w-full rounded-2xl" />
      </section>
    );
  }
  if (entries.length === 0) return null;

  const selectable = entries.filter((e) => e.selectable && !readOnly);
  const count = selectable.filter((e) => selected.has(e.key)).length;

  return (
    <section className="flex flex-col gap-2 animate-fade-up" id="pp-concepts">
      <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Koncepty kontrol</h2>
      {selectable.length > 0 && (
        <p className="text-xs text-ink-500">Zaškrtni úkony, ktoré chceš vytvoriť a rovno uzavrieť.</p>
      )}

      <ul className="flex flex-col gap-2">
        {entries.map((e) => (
          <li key={e.key}>
            <EntryRow
              entry={e}
              checked={selected.has(e.key)}
              canTick={e.selectable && !readOnly}
              onToggle={() => toggle(e.key)}
              creating={creating === e.key}
              onCreateManual={readOnly ? undefined : () => void createManual(e)}
            />
          </li>
        ))}
      </ul>

      {selectable.length > 0 && (
        <Button
          type="button"
          id="pp-concepts-close"
          className="mt-1 w-full"
          disabled={count === 0}
          loading={preparing}
          leftIcon={<ListChecks className="size-4" />}
          onClick={() => void prepare()}
        >
          {count > 0 ? `Vytvoriť a rovno uzavrieť (${count})` : 'Vytvoriť a rovno uzavrieť'}
        </Button>
      )}
    </section>
  );
}

function EntryRow({
  entry: e,
  checked,
  canTick,
  onToggle,
  creating,
  onCreateManual,
}: {
  entry: ChecklistEntry;
  checked: boolean;
  canTick: boolean;
  onToggle: () => void;
  creating: boolean;
  onCreateManual?: () => void;
}) {
  const issued = e.status === 'finalized';
  const title = INSPECTION_TYPE_LABELS[e.type] ?? e.type;
  const facts = [
    devicesLabel(e.items),
    !NO_RESULT.has(e.type) && !issued ? `${e.failing} nevyhovuje` : null,
    issued && e.document ? `protokol ${e.document.number}` : null,
  ].filter(Boolean).join(' · ');

  const body = (
    <span className="min-w-0 flex-1">
      <span className="block text-sm font-semibold text-ink-900">{title}</span>
      <span className="mt-0.5 block text-xs text-ink-500">{facts}</span>
      {!issued && e.origin && <span className="block text-xs text-ink-500">{originText(e.origin)}</span>}
      {!issued && e.note && <span className="mt-0.5 block text-xs font-medium text-status-warn">{e.note}</span>}
    </span>
  );

  return (
    <div
      id={`pp-concept-${e.type}`}
      className={cn(
        'flex items-stretch overflow-hidden rounded-2xl border bg-white transition-[background-color,border-color,box-shadow] duration-300',
        checked ? 'border-firol-300 bg-firol-50' : 'border-ink-200 hover:border-ink-300 hover:shadow-sm',
      )}
    >
      {canTick ? (
        <button
          type="button"
          role="checkbox"
          aria-checked={checked}
          onClick={onToggle}
          className="flex min-w-0 flex-1 items-start gap-3 px-4 py-3.5 text-left transition-transform duration-200 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-firol-300"
        >
          <span
            aria-hidden
            className={cn(
              'mt-0.5 grid size-5 shrink-0 place-items-center rounded-md border-2 transition-colors duration-200',
              checked ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
            )}
          >
            {checked && <Check className="size-3.5" strokeWidth={3} />}
          </span>
          {body}
        </button>
      ) : (
        <div className="flex min-w-0 flex-1 items-start gap-3 px-4 py-3.5">
          <span
            className={cn(
              'grid size-5 shrink-0 place-items-center',
              issued ? 'text-status-ok' : 'text-ink-400',
            )}
          >
            {issued ? <CheckCircle2 className="size-5" /> : <ShieldCheck className="size-4" />}
          </span>
          {body}
        </div>
      )}

      {e.inspection_id !== null ? (
        <Link
          to={`/inspections/${e.inspection_id}`}
          aria-label={`Otvoriť — ${INSPECTION_TYPE_LABELS[e.type] ?? e.type}`}
          className="grid w-12 shrink-0 place-items-center border-l border-ink-100 text-ink-400 transition-colors duration-200 hover:bg-ink-50 hover:text-firol-600"
        >
          <ChevronRight className="size-5" />
        </Link>
      ) : (
        e.manual_create && onCreateManual && (
          <div className="flex shrink-0 items-center border-l border-ink-100 px-2">
            <Button type="button" size="sm" variant="secondary" loading={creating} leftIcon={<Plus className="size-4" />} onClick={onCreateManual}>
              Vytvoriť
            </Button>
          </div>
        )
      )}
    </div>
  );
}

/** The one-by-one link of a concept, as the result screen showed it before the checklist. */
function FallbackLink({ concept }: { concept: PrehliadkaConcept }) {
  const issued = concept.status === 'finalized';
  const state = issued ? 'protokol vydaný' : concept.confirmed ? 'potvrdený' : 'čaká na potvrdenie';
  return (
    <Link
      to={`/inspections/${concept.inspection_id}`}
      id={`pp-concept-${concept.type}`}
      className="flex items-center gap-3 rounded-2xl border border-ink-200 bg-white px-4 py-3.5 transition-all duration-300 hover:border-ink-300 hover:shadow-sm active:scale-[0.99]"
    >
      <span
        className={
          concept.failing > 0
            ? 'grid size-9 shrink-0 place-items-center rounded-xl bg-status-bad-bg text-status-bad'
            : 'grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600'
        }
      >
        <ShieldCheck className="size-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink-900">
          {INSPECTION_TYPE_LABELS[concept.type] ?? concept.type}
        </span>
        <span className="block text-xs text-ink-500">
          {devicesLabel(concept.items)} · {concept.failing} nevyhovuje · {state}
        </span>
      </span>
    </Link>
  );
}
