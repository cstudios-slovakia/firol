import { useEffect, useMemo, useState } from 'react';
import { ChevronRight, History } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Inspections } from '@/api/inspections';
import { Prehliadka, type PreviousDefects as PreviousDefectsData } from '@/api/prehliadka';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { useConfirm } from '@/lib/confirm';
import { useToast } from '@/lib/toast';
import {
  DEADLINE_CHOICES,
  PREVIOUS_STATUS_LABELS,
  carriedScope,
  composeWhere,
  deadlineFor,
  scopeApplies,
  type DeadlineChoice,
  type DefectFields,
  type PrehliadkaDetails,
  type PreviousDefect,
  type PreviousEntry,
  type PreviousStatus,
} from '@/lib/prehliadka';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Chip, DefectScopeBadge, TEXTAREA_CLASS } from '@/components/prehliadka/ui';

/** A defect written in this prehliadka, as the section needs it to find the one carried over. */
export type CarriedRow = { itemId: number; persistsKey: string | null };

type Props = {
  inspectionId: number;
  facilityId: number;
  /** The date of this prehliadka — a „Trvá" deadline is counted from it. */
  executedOn: string | null;
  details: PrehliadkaDetails;
  onChangeDetails: (change: (d: PrehliadkaDetails) => PrehliadkaDetails) => void;
  /** Defects of this prehliadka; the ones with `persists_from` are the carried-over ones. */
  carried: CarriedRow[];
  /** Called after a carried-over defect was added or removed, so the list of defects is read again. */
  onItemsChanged: () => void | Promise<void>;
  canEdit: boolean;
};

type Sheet =
  | { kind: 'status'; defect: PreviousDefect }
  | { kind: 'zanik'; defect: PreviousDefect }
  | { kind: 'trva'; defect: PreviousDefect };

const STATUS_TONE: Record<PreviousStatus | 'cakaa', 'ok' | 'bad' | 'neutral' | 'warn'> = {
  ok: 'ok',
  trva: 'bad',
  zanik: 'neutral',
  cakaa: 'warn',
};

/**
 * „Nedostatky z minulej prehliadky" (spec 6.5) at the top of Krok 2. Every
 * defect of the last prehliadka is „Čaká na overenie" until it is set to
 * Odstránené, Trvá or Dôvod zanikol. The first and the last never merge, and
 * the last cannot be saved without its one sentence. Trvá copies the defect
 * into this prehliadka with a deadline chosen again from today's date.
 */
export function PreviousDefectsSection({
  inspectionId,
  facilityId,
  executedOn,
  details,
  onChangeDetails,
  carried,
  onItemsChanged,
  canEdit,
}: Props) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();

  const [data, setData] = useState<PreviousDefectsData | null>(null);
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Prehliadka.previous(facilityId, inspectionId)
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch(() => {
        // Nothing to show is a valid state; the walk-through does not depend on it.
        if (!cancelled) setData({ source: null, defects: [] });
      });
    return () => {
      cancelled = true;
    };
  }, [facilityId, inspectionId]);

  // Only the defects of a record being done today: a BOZP-only defect has no
  // record to be written into in a PO-only prehliadka.
  const defects = useMemo(
    () => (data?.defects ?? []).filter((d) => scopeApplies(d.scope, details.scope)),
    [data, details.scope],
  );

  // Declared before the early return below — a hook after it changes the hook count
  // between the render without data and the one with.
  const [optimisticCarried, setOptimisticCarried] = useState<Set<string>>(() => new Set());

  if (!data || !data.source || defects.length === 0) return null;
  const source = data.source;

  const entryOf = (key: string): PreviousEntry | undefined => details.previous.find((e) => e.key === key);
  const carriedOf = (key: string): boolean => carried.some((c) => c.persistsKey === key) || optimisticCarried.has(key);
  // „Trvá" is only real while the defect carried over exists (it may have been deleted since).
  const statusOf = (d: PreviousDefect): PreviousStatus | null => {
    const s = entryOf(d.key)?.status ?? null;
    return s === 'trva' && !carriedOf(d.key) ? null : s;
  };

  const waiting = defects.filter((d) => statusOf(d) === null).length;

  /** The complete list of answers — every defect has an entry once the first one is given. */
  function withAnswer(d: PreviousDefect, status: PreviousStatus | null, reason: string | null) {
    onChangeDetails((cur) => {
      const known = new Map(cur.previous.map((e) => [e.key, e]));
      for (const x of defects) {
        if (!known.has(x.key)) {
          known.set(x.key, {
            key: x.key,
            kind: source.kind,
            source_inspection_id: source.inspection_id,
            source_item_id: x.source_item_id,
            status: null,
            reason: null,
          });
        }
      }
      const entry = known.get(d.key)!;
      known.set(d.key, { ...entry, status, reason: status === 'zanik' ? reason : null });
      return { ...cur, previous: [...known.values()] };
    });
  }

  async function dropCarried(d: PreviousDefect): Promise<boolean> {
    const c = carried.find((x) => x.persistsKey === d.key);
    setOptimisticCarried((prev) => {
      const next = new Set(prev);
      next.delete(d.key);
      return next;
    });
    if (!c) return true;
    try {
      await Inspections.deleteItem(inspectionId, c.itemId, csrfToken);
    } catch (err) {
      if (!(err instanceof OfflineQueuedError)) {
        toast.error(err instanceof ApiError ? err.message : 'Prenesený nedostatok sa nepodarilo odstrániť.');
        return false;
      }
    }
    await onItemsChanged();
    return true;
  }

  async function choose(d: PreviousDefect, status: PreviousStatus) {
    if (status === 'zanik') return setSheet({ kind: 'zanik', defect: d });
    if (status === 'trva') {
      if (statusOf(d) === 'trva') return setSheet(null);
      return setSheet({ kind: 'trva', defect: d });
    }
    // „Odstránené" — undoing a „Trvá" removes the defect it carried over.
    if (statusOf(d) === 'trva') {
      const ok = await confirm({
        title: 'Zrušiť prenesenie nedostatku?',
        description: 'Nedostatok, ktorý sa preniesol do tejto prehliadky, sa z nej odstráni.',
        confirmLabel: 'Zrušiť prenesenie',
      });
      if (!ok) return;
      setBusy(true);
      const dropped = await dropCarried(d);
      setBusy(false);
      if (!dropped) return;
    }
    withAnswer(d, status, null);
    setSheet(null);
    toast.success('Zaznamenané.');
  }

  async function saveZanik(d: PreviousDefect, reason: string) {
    if (statusOf(d) === 'trva') {
      setBusy(true);
      const dropped = await dropCarried(d);
      setBusy(false);
      if (!dropped) return;
    }
    withAnswer(d, 'zanik', reason);
    setSheet(null);
    toast.success('Zaznamenané.');
  }

  async function saveTrva(
    d: PreviousDefect,
    input: { choice: DeadlineChoice; customDate: string; place: string; responsible: string },
  ) {
    const where = d.legacy || !d.where
      ? { entrance: null, floor: null, place: null, detail: input.place.trim() || null }
      : { entrance: d.where.entrance, floor: d.where.floor, place: d.where.place, detail: d.where.detail };
    const fields: DefectFields = {
      catalog_id: d.catalog_id,
      title: d.title,
      scope: carriedScope(d.scope, details.scope),
      where: { ...where, text: d.legacy || !d.where ? composeWhere(where) : d.where_text || composeWhere(where) },
      devices: d.devices,
      device_manual: d.device_manual,
      description: d.description,
      regulation: d.regulation,
      justification: d.justification,
      measure: d.measure,
      deadline_choice: input.choice,
      deadline_date:
        input.choice === 'bezodkladne'
          ? null
          : input.choice === 'custom'
            ? input.customDate
            : deadlineFor(input.choice, executedOn),
      responsible: input.responsible.trim() || null,
      group_key: null,
      persists_from: { key: d.key, inspection_id: source.inspection_id, executed_on: source.executed_on },
    };

    setBusy(true);
    try {
      try {
        await Inspections.addItem(inspectionId, fields, csrfToken);
      } catch (err) {
        if (!(err instanceof OfflineQueuedError)) throw err;
      }
      setOptimisticCarried((prev) => new Set(prev).add(d.key));
      await onItemsChanged();
      withAnswer(d, 'trva', null);
      setSheet(null);
      toast.success('Nedostatok sa prenesie do záznamu s novým termínom.');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Nedostatok sa nepodarilo preniesť.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="flex flex-col gap-2 animate-fade-up" aria-label="Nedostatky z minulej prehliadky">
      <h2 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-500">
        <History className="size-3.5" />
        {source.kind === 'prehliadka'
          ? `Z prehliadky${source.executed_on ? ` ${formatDateSk(source.executed_on)}` : ''}`
          : `Zo záznamu v požiarnej knihe${source.executed_on ? ` ${formatDateSk(source.executed_on)}` : ''}`}
        <span className="font-normal normal-case tracking-normal text-ink-400">
          · {waiting === 0 ? 'všetko overené' : `${waiting} čaká na overenie`}
        </span>
      </h2>
      <ul className="flex flex-col gap-2">
        {defects.map((d) => {
          const status = statusOf(d);
          const entry = entryOf(d.key);
          const tone = STATUS_TONE[status ?? 'cakaa'];
          const border =
            status === 'ok' ? 'border-l-status-ok' : status === 'trva' ? 'border-l-status-bad' : status === 'zanik' ? 'border-l-ink-300' : 'border-l-status-warn';
          const body = (
            <div className="flex items-start gap-2">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-1.5">
                  <DefectScopeBadge scope={d.scope} />
                  <Badge tone={tone}>{status ? PREVIOUS_STATUS_LABELS[status] : 'Čaká na overenie'}</Badge>
                </div>
                <p className="mt-1.5 text-sm font-semibold text-ink-900">{d.title}</p>
                {(d.where_text || d.deadline_date) && (
                  <p className="mt-0.5 text-xs text-ink-500">
                    {[d.where_text, d.deadline_date ? `termín bol ${formatDateSk(d.deadline_date)}` : null]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                )}
                {status === 'zanik' && entry?.reason && (
                  <p className="mt-1 text-xs text-ink-600">Dôvod: {entry.reason}</p>
                )}
              </div>
              {canEdit && <ChevronRight className="mt-1 size-4 shrink-0 text-ink-300" />}
            </div>
          );
          const cls = `block w-full rounded-2xl border border-l-4 border-ink-200 ${border} bg-white px-4 py-3 text-left`;
          return (
            <li key={d.key}>
              {canEdit ? (
                <button
                  type="button"
                  onClick={() => setSheet({ kind: 'status', defect: d })}
                  className={`${cls} animate-fade-in transition-[border-color,background-color,transform,box-shadow] duration-200 hover:bg-firol-50/40 hover:shadow-[var(--shadow-soft)] active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300`}
                >
                  {body}
                </button>
              ) : (
                <div className={cls}>{body}</div>
              )}
            </li>
          );
        })}
      </ul>

      {sheet?.kind === 'status' && (
        <Dialog
          open
          onClose={() => setSheet(null)}
          title={sheet.defect.title}
          description="Nedostatok z minulej prehliadky — čo sa s ním stalo?"
          dismissible={!busy}
        >
          <div className="flex flex-col gap-3">
            <p className="rounded-xl bg-ink-50 px-3 py-2 text-xs leading-relaxed text-ink-600">
              {sheet.defect.description}
            </p>
            <Button
              type="button"
              variant="secondary"
              id="pp-prev-ok"
              disabled={busy}
              onClick={() => void choose(sheet.defect, 'ok')}
            >
              Odstránené — náprava prebehla
            </Button>
            <Button
              type="button"
              variant="secondary"
              id="pp-prev-trva"
              disabled={busy}
              onClick={() => void choose(sheet.defect, 'trva')}
            >
              Trvá — prenesie sa do záznamu
            </Button>
            <Button
              type="button"
              variant="secondary"
              id="pp-prev-zanik"
              disabled={busy}
              onClick={() => void choose(sheet.defect, 'zanik')}
            >
              Dôvod zanikol
            </Button>
            <Button type="button" variant="ghost" size="sm" onClick={() => setSheet(null)}>
              Zavrieť
            </Button>
          </div>
        </Dialog>
      )}

      {sheet?.kind === 'zanik' && (
        <ReasonSheet
          defect={sheet.defect}
          initial={entryOf(sheet.defect.key)?.reason ?? ''}
          busy={busy}
          onClose={() => setSheet(null)}
          onSave={(reason) => void saveZanik(sheet.defect, reason)}
        />
      )}

      {sheet?.kind === 'trva' && (
        <TrvaSheet
          defect={sheet.defect}
          executedOn={executedOn}
          busy={busy}
          onClose={() => setSheet(null)}
          onSave={(input) => void saveTrva(sheet.defect, input)}
        />
      )}
    </section>
  );
}

/** „Dôvod zanikol" — one sentence, which prints next to the state. Cannot be left empty. */
function ReasonSheet({
  defect,
  initial,
  busy,
  onClose,
  onSave,
}: {
  defect: PreviousDefect;
  initial: string;
  busy: boolean;
  onClose: () => void;
  onSave: (reason: string) => void;
}) {
  const [reason, setReason] = useState(initial);
  const [error, setError] = useState<string | null>(null);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (reason.trim() === '') {
      setError('Napíš jednu vetu — vytlačí sa do záznamu vedľa stavu.');
      return;
    }
    onSave(reason.trim());
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Prečo dôvod zanikol?"
      description={`${defect.title} — jedna veta, ktorá sa vytlačí do záznamu vedľa stavu.`}
      dismissible={!busy}
    >
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Dôvod" required error={error ?? undefined}>
          {(p) => (
            <textarea
              {...p}
              rows={3}
              autoFocus
              maxLength={500}
              value={reason}
              placeholder="Dvere boli vymenené za nové, pôvodné už v objekte nie sú."
              onChange={(e) => {
                setReason(e.target.value);
                setError(null);
              }}
              className={TEXTAREA_CLASS}
            />
          )}
        </Field>
        <p className="-mt-2 text-xs text-ink-500">
          „Odstránené“ a „Dôvod zanikol“ sa nezlučujú — inak by záznam vyzeral, že klient všetko vyriešil.
        </p>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={busy}>
            Zrušiť
          </Button>
          <Button type="submit" size="sm" id="pp-prev-zanik-save" loading={busy}>
            Zaznamenať
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

/**
 * „Trvá" — the defect goes into this prehliadka, texts as they were, and its
 * deadline is chosen again, counted from today's date (spec 6.5).
 */
function TrvaSheet({
  defect,
  executedOn,
  busy,
  onClose,
  onSave,
}: {
  defect: PreviousDefect;
  executedOn: string | null;
  busy: boolean;
  onClose: () => void;
  onSave: (input: { choice: DeadlineChoice; customDate: string; place: string; responsible: string }) => void;
}) {
  const [choice, setChoice] = useState<DeadlineChoice>('30d');
  const [customDate, setCustomDate] = useState('');
  const [place, setPlace] = useState('');
  const [responsible, setResponsible] = useState(defect.responsible ?? '');
  const [error, setError] = useState<string | null>(null);

  const computed = choice === 'custom' ? null : deadlineFor(choice, executedOn);
  // An old požiarna kniha record has no place of its own — it has to be written here.
  const needsPlace = defect.legacy || !defect.where || defect.where_text === '';

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (needsPlace && place.trim() === '') {
      setError('Zadaj, kde nedostatok je — starý záznam miesto neuvádza.');
      return;
    }
    if (choice === 'custom' && !customDate) {
      setError('Zadaj vlastný dátum odstránenia.');
      return;
    }
    onSave({ choice, customDate, place, responsible });
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Trvá — prenesie sa do záznamu"
      description={`${defect.title}. Texty ostanú, lehotu zvoľ znovu — počíta sa od dátumu tejto prehliadky.`}
      dismissible={!busy}
    >
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {needsPlace && (
          <Field label="Kde" required>
            {(p) => (
              <Input
                {...p}
                value={place}
                maxLength={255}
                placeholder="Vchod 4 · 1. NP · chodba"
                onChange={(e) => {
                  setPlace(e.target.value);
                  setError(null);
                }}
              />
            )}
          </Field>
        )}

        <div className="flex flex-col gap-2">
          <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Dokedy</span>
          <div className="flex flex-wrap gap-2">
            {DEADLINE_CHOICES.map((c) => (
              <Chip key={c.value} active={choice === c.value} onClick={() => setChoice(c.value)}>
                {c.label}
              </Chip>
            ))}
          </div>
          {choice === 'custom' ? (
            <Input
              type="date"
              aria-label="Vlastný dátum"
              value={customDate}
              onChange={(e) => {
                setCustomDate(e.target.value);
                setError(null);
              }}
            />
          ) : (
            <p className="text-xs text-ink-500">
              {computed ? `Do ${formatDateSk(computed)}` : 'Bezodkladne — bez dátumu.'}
            </p>
          )}
        </div>

        <Field label="Zodpovedný">
          {(p) => <Input {...p} value={responsible} maxLength={191} onChange={(e) => setResponsible(e.target.value)} />}
        </Field>

        {error && (
          <p role="alert" className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={busy}>
            Zrušiť
          </Button>
          <Button type="submit" size="sm" id="pp-prev-trva-save" loading={busy}>
            Preniesť do záznamu
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
