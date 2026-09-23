/**
 * Building blocks shared by the block 2 BOZP row types (rebríky, regály,
 * OOPP, pracovné prostriedky, označenie — see `api/bozpItems.ts`).
 *
 * The five forms differ only in their fields. How a row is saved, which three
 * buttons sit under it, how a row reads on the summary and how the úkon-level
 * opatrenia / záver are edited are the same for all of them, and keeping that
 * here means a fix to one reaches the other four.
 *
 * Everything is sized for one thumb in a glove: every choice is a full-width
 * button of at least 44 px, never a dropdown.
 */
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, CalendarDays, CheckCircle2, CopyPlus, Edit2, ListChecks,
  NotebookPen, Plus, Save, Trash2, Zap,
} from 'lucide-react';
import { Inspections, type InspectionItem } from '@/api/inspections';
import { isUnassessed, type BozpItemType, type MeasureRow } from '@/api/bozpItems';
import { ApiError } from '@/lib/api';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { Input } from '@/components/ui/Input';
import type { DetailsBlockProps, StatsBarProps } from './common';

/* ------------------------------------------------------------------ fields */

export type FieldErrors = Record<string, string>;

/** Drops one key from the error map as soon as the technician touches it. */
export function clearError(setErrors: React.Dispatch<React.SetStateAction<FieldErrors>>, key: string) {
  setErrors((prev) => {
    if (!(key in prev)) return prev;
    const next = { ...prev };
    delete next[key];
    return next;
  });
}

const TEXTAREA_CLASS =
  'w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';

export function TextArea({
  id,
  value,
  onChange,
  placeholder,
  rows = 3,
  disabled,
}: {
  id?: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  rows?: number;
  disabled?: boolean;
}) {
  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
        <NotebookPen className="size-4" />
      </span>
      <textarea
        id={id}
        rows={rows}
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className={cn(TEXTAREA_CLASS, 'disabled:bg-ink-50 disabled:text-ink-500')}
      />
    </div>
  );
}

type Tone = 'ok' | 'bad' | 'warn' | 'neutral';

const TONE_CLASSES: Record<Tone, { active: string; idle: string }> = {
  ok: {
    active: 'border-status-ok bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
    idle: 'hover:border-status-ok',
  },
  bad: {
    active: 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
    idle: 'hover:border-status-bad',
  },
  warn: {
    active: 'border-status-warn bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
    idle: 'hover:border-status-warn',
  },
  neutral: {
    active: 'border-ink-500 bg-ink-100 text-ink-800',
    idle: 'hover:border-ink-400',
  },
};

export type Choice<T extends string> = { value: T; label: string; tone: Tone };

/**
 * A row of big radio buttons. `allowClear` lets an optional choice (OOPP
 * „používané", „stav") be taken back by tapping the selected one again.
 */
export function ChoiceGroup<T extends string>({
  label,
  choices,
  value,
  onChange,
  allowClear = false,
  invalid = false,
}: {
  label: string;
  choices: Choice<T>[];
  value: T | null;
  onChange: (v: T | null) => void;
  allowClear?: boolean;
  invalid?: boolean;
}) {
  return (
    <div
      className={cn(
        'grid gap-2',
        choices.length === 2 ? 'grid-cols-2' : 'grid-cols-3',
        invalid && 'rounded-xl ring-2 ring-status-bad/40 ring-offset-2',
      )}
      role="radiogroup"
      aria-label={label}
    >
      {choices.map((c) => {
        const active = value === c.value;
        const tone = TONE_CLASSES[c.tone];
        return (
          <button
            key={c.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(active && allowClear ? null : c.value)}
            className={cn(
              'min-h-12 rounded-xl border px-3 py-2.5 text-sm font-semibold transition-all duration-200 active:scale-[0.98]',
              active ? tone.active : `border-ink-200 bg-white text-ink-700 ${tone.idle}`,
            )}
          >
            <span className="flex items-center justify-center gap-1.5">
              {active && <CheckCircle2 className="size-4" />}
              {c.label}
            </span>
          </button>
        );
      })}
    </div>
  );
}

export const YES_NO: Choice<'ano' | 'nie'>[] = [
  { value: 'ano', label: 'Áno', tone: 'ok' },
  { value: 'nie', label: 'Nie', tone: 'bad' },
];

export function toYesNo(v: boolean | null): 'ano' | 'nie' | null {
  return v === null ? null : v ? 'ano' : 'nie';
}

export function fromYesNo(v: 'ano' | 'nie' | null): boolean | null {
  return v === null ? null : v === 'ano';
}

/** Read a string field off stored item JSON. */
export function str(fields: Record<string, unknown>, key: string): string {
  const v = fields[key];
  return typeof v === 'string' ? v : typeof v === 'number' ? String(v) : '';
}

/** Read a boolean-or-null field off stored item JSON. */
export function boolOrNull(fields: Record<string, unknown>, key: string): boolean | null {
  const v = fields[key];
  return typeof v === 'boolean' ? v : null;
}

/* ------------------------------------------------------------- form footer */

/**
 * The buttons under a row form. Chapter 6 requires „Uložiť a ďalší",
 * „Ďalší rovnaký" and „Uložiť a prejsť na súhrn" for the `polozky` pattern;
 * the `tabulka` types omit the middle one (`duplicateHint` unset).
 */
export function ItemFormFooter({
  editing,
  submitting,
  apiError,
  hasErrors,
  onSummary,
  onDuplicate,
  duplicateHint,
}: {
  editing: boolean;
  submitting: boolean;
  apiError: string | null;
  hasErrors: boolean;
  onSummary: (e: React.SyntheticEvent) => void;
  onDuplicate?: (e: React.SyntheticEvent) => void;
  duplicateHint?: string;
}) {
  return (
    <>
      {apiError && (
        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          {apiError}
        </div>
      )}
      {hasErrors && (
        <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          Formulár obsahuje nevyplnené povinné polia.
        </p>
      )}
      <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
        <Button type="button" variant="secondary" onClick={onSummary}
          loading={submitting} leftIcon={<ListChecks className="size-4" />}>
          Uložiť a prejsť na súhrn
        </Button>
        {onDuplicate && (
          <Button type="button" variant="secondary" onClick={onDuplicate}
            loading={submitting} leftIcon={<CopyPlus className="size-4" />} title={duplicateHint}>
            Ďalší rovnaký
          </Button>
        )}
        <Button type="submit" loading={submitting}
          rightIcon={editing ? <Save className="size-4" /> : <ArrowRight className="size-4" />}>
          {editing ? 'Uložiť zmeny a ďalší' : 'Uložiť a ďalší'}
        </Button>
      </div>
    </>
  );
}

/** Message for a failed save — the server's own Slovak text when it sent one. */
export function saveErrorMessage(err: unknown): string {
  return err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
}

/** Standard submit guard: prevents default and swallows a double tap. */
export type SubmitHandler = (
  e: FormEvent | React.SyntheticEvent,
  action: 'save-and-next' | 'save-and-summary',
  duplicate?: boolean,
) => Promise<void>;

/* ---------------------------------------------------------------- item row */

/**
 * One row of the summary list. The per-type module decides the title, the
 * badges and the detail lines; the shell adds numbering, the „výsledok
 * chýba" flag on a row carried over from last time, and the edit / delete
 * affordances.
 */
export function BozpItemRowShell({
  type,
  inspectionId,
  index,
  item,
  canEdit,
  deleting,
  onDelete,
  icon,
  title,
  badges,
  lines,
}: {
  type: BozpItemType;
  inspectionId: number;
  index: number;
  item: InspectionItem;
  canEdit: boolean;
  deleting: boolean;
  onDelete: () => void;
  icon: ReactNode;
  title: ReactNode;
  badges?: ReactNode;
  lines: ReactNode[];
}) {
  const unassessed = isUnassessed(type, item.fields);
  const faults = str(item.fields, 'faults');
  const defects = Array.isArray(item.fields.defects) ? item.fields.defects.length : 0;
  return (
    <div className="px-4 py-3">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-sm font-semibold text-firol-700">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="min-w-0 truncate text-sm font-semibold text-ink-900">
              <span className="-mt-0.5 mr-1 inline-block align-middle text-ink-400">{icon}</span>
              {title}
            </h3>
            {unassessed ? <Badge tone="warn">Chýba výsledok</Badge> : badges}
          </div>
          {lines.filter(Boolean).map((line, i) => (
            <p key={i} className="mt-0.5 truncate text-xs text-ink-500">{line}</p>
          ))}
          {faults && (
            <p className="mt-1 line-clamp-2 text-xs text-ink-600">
              <AlertTriangle className="-mt-0.5 mr-1 inline size-3 text-status-warn" />
              {faults}
            </p>
          )}
          {defects > 0 && (
            <p className="mt-1 text-xs font-medium text-status-bad">
              {defects === 1 ? '1 nedostatok' : defects < 5 ? `${defects} nedostatky` : `${defects} nedostatkov`}
            </p>
          )}
        </div>
        {canEdit && (
          <div className="flex shrink-0 items-center gap-3.5">
            <Link to={`/inspections/${inspectionId}/items/${item.id}`} aria-label="Opraviť"
              className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]">
              <Edit2 className="size-4" />
            </Link>
            <button type="button" onClick={onDelete} disabled={deleting} aria-label="Zmazať"
              className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)] disabled:opacity-50">
              {deleting ? <Spinner size="sm" /> : <Trash2 className="size-4" />}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------- stats */

export type StatCell = { label: string; value: number; tone: Tone };

const STAT_TONE: Record<Tone, string> = {
  ok: 'bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
  bad: 'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
  warn: 'bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
  neutral: 'bg-ink-100 text-ink-700',
};

/** Step 3 „Štatistika" — counts by the type's own číselník (chapter 6). */
export function StatsGrid({ total, cells }: { total: number; cells: StatCell[] }) {
  return (
    <Card className="px-4 py-3">
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold uppercase tracking-wider text-ink-500">Štatistika</span>
        <span className="text-ink-500">spolu {total}</span>
      </div>
      <div className={cn('mt-2 grid gap-2', cells.length > 2 ? 'grid-cols-3' : 'grid-cols-2')}>
        {cells.map((c) => (
          <div key={c.label}
            className={cn('flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm', STAT_TONE[c.tone])}>
            <span className="text-xs">{c.label}</span>
            <span className="text-base font-semibold tabular-nums">{c.value}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}

/** Counts rows by `result`. */
export function countResults(items: StatsBarProps['items']): Record<string, number> {
  const out: Record<string, number> = {};
  for (const it of items) {
    const r = it.fields.result;
    if (typeof r === 'string' && r !== '') out[r] = (out[r] ?? 0) + 1;
  }
  return out;
}

/* ---------------------------------------------------------------- details */

export type { DetailsBlockProps } from './common';

/**
 * Save the úkon-level fields. Offline, the write goes to the outbox and the
 * local copy is kept as if it had been saved (same pattern as the date).
 */
async function saveDetails(
  inspectionId: number,
  details: Record<string, unknown>,
  csrfToken: string | null,
  toast: { success: (m: string) => void },
): Promise<void> {
  try {
    await Inspections.update(inspectionId, { details }, csrfToken);
    toast.success('Uložené');
  } catch (err) {
    if (handleOfflineSave(err, toast)) return;
    throw err;
  }
}

/**
 * „Opatrenia" (rebríky, regály) or „Záver" (OOPP) — one free-text field on
 * the úkon. Read-only and hidden when empty once the protocol is issued
 * (POKYNY bod 8, empty sections are not shown).
 */
export function DetailsTextBlock({
  inspectionId,
  details,
  canEdit,
  csrfToken,
  onSaved,
  field,
  title,
  placeholder,
  hint,
}: DetailsBlockProps & { field: string; title: string; placeholder: string; hint: string }) {
  const saved = typeof details?.[field] === 'string' ? (details[field] as string) : '';
  const [value, setValue] = useState(saved);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();
  useEffect(() => { setValue(saved); }, [saved]);

  if (!canEdit && saved.trim() === '') return null;

  async function handleSave() {
    setBusy(true);
    setError(null);
    const next = { ...(details ?? {}), [field]: value.trim() === '' ? null : value.trim() };
    try {
      await saveDetails(inspectionId, next, csrfToken, toast);
      onSaved(next);
    } catch (err) {
      setError(saveErrorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wider text-ink-500">{title}</p>
        {canEdit && <p className="mt-0.5 text-xs text-ink-500">{hint}</p>}
      </div>
      {canEdit ? (
        <>
          <TextArea value={value} onChange={setValue} placeholder={placeholder} rows={4} />
          {error && <p className="text-xs text-status-bad">{error}</p>}
          {value !== saved && (
            <Button type="button" loading={busy} onClick={handleSave} className="self-end"
              leftIcon={<Save className="size-4" />}>
              Uložiť
            </Button>
          )}
        </>
      ) : (
        <p className="whitespace-pre-line text-sm text-ink-700">{saved}</p>
      )}
    </Card>
  );
}

function readMeasures(details: Record<string, unknown> | null): MeasureRow[] {
  const raw = details?.measures;
  if (!Array.isArray(raw)) return [];
  return raw
    .filter((r): r is Record<string, unknown> => typeof r === 'object' && r !== null)
    .map((r) => ({
      measure: typeof r.measure === 'string' ? r.measure : '',
      deadline: typeof r.deadline === 'string' ? r.deadline : null,
      immediately: r.immediately === true,
    }));
}

function formatDate(iso: string): string {
  const [y, m, d] = iso.split('-');
  return `${Number(d)}. ${Number(m)}. ${y}`;
}

/**
 * „Opatrenia" as a repeatable block of opatrenie + termín (pracovné
 * prostriedky, označenie — chapter 7). The termín is a date or „ihneď".
 */
export function MeasuresBlock({ inspectionId, details, canEdit, csrfToken, onSaved }: DetailsBlockProps) {
  const saved = readMeasures(details);
  const savedKey = JSON.stringify(saved);
  const [rows, setRows] = useState<MeasureRow[]>(saved);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { setRows(readMeasures(details)); }, [savedKey]);

  if (!canEdit && saved.length === 0) return null;

  const dirty = JSON.stringify(rows) !== savedKey;

  function patch(i: number, next: Partial<MeasureRow>) {
    setRows((prev) => prev.map((r, idx) => (idx === i ? { ...r, ...next } : r)));
  }

  async function handleSave() {
    setBusy(true);
    setError(null);
    const cleaned = rows
      .map((r) => ({ ...r, measure: r.measure.trim() }))
      .filter((r) => r.measure !== '');
    const next = { ...(details ?? {}), measures: cleaned };
    try {
      await saveDetails(inspectionId, next, csrfToken, toast);
      setRows(cleaned);
      onSaved(next);
    } catch (err) {
      setError(saveErrorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wider text-ink-500">Opatrenia</p>
        {canEdit && (
          <p className="mt-0.5 text-xs text-ink-500">
            Čo má organizácia urobiť a do kedy. Vytlačí sa v protokole ako tabuľka.
          </p>
        )}
      </div>

      {canEdit ? (
        <>
          {rows.map((r, i) => (
            <div key={i} className="flex flex-col gap-2 rounded-2xl border border-ink-100 bg-ink-50/50 p-3">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold text-ink-500">Opatrenie č. {i + 1}</span>
                <button type="button" aria-label="Odstrániť opatrenie"
                  onClick={() => setRows((prev) => prev.filter((_, idx) => idx !== i))}
                  className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]">
                  <Trash2 className="size-4" />
                </button>
              </div>
              <TextArea value={r.measure} onChange={(v) => patch(i, { measure: v })} rows={2}
                placeholder="Napr. Uhlovú brúsku vyradiť z používania do doplnenia krytu" />
              <div className="grid grid-cols-[1fr_auto] gap-2">
                <Input type="date" aria-label="Termín" value={r.deadline ?? ''}
                  disabled={r.immediately} leftIcon={<CalendarDays className="size-4" />}
                  onChange={(e) => patch(i, { deadline: e.target.value || null })} />
                <button type="button" aria-pressed={r.immediately}
                  onClick={() => patch(i, { immediately: !r.immediately, deadline: null })}
                  className={cn(
                    'flex h-11 items-center gap-1.5 rounded-xl border px-3 text-sm font-medium transition-colors',
                    r.immediately
                      ? 'border-status-warn bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]'
                      : 'border-ink-200 bg-white text-ink-700 hover:border-status-warn',
                  )}>
                  <Zap className="size-4" />
                  Ihneď
                </button>
              </div>
            </div>
          ))}
          <Button type="button" variant="secondary" className="self-start"
            leftIcon={<Plus className="size-4" />}
            onClick={() => setRows((prev) => [...prev, { measure: '', deadline: null, immediately: false }])}>
            Pridať opatrenie
          </Button>
          {error && <p className="text-xs text-status-bad">{error}</p>}
          {dirty && (
            <Button type="button" loading={busy} onClick={handleSave} className="self-end"
              leftIcon={<Save className="size-4" />}>
              Uložiť opatrenia
            </Button>
          )}
        </>
      ) : (
        <ol className="flex flex-col gap-1.5">
          {saved.map((r, i) => (
            <li key={i} className="text-sm text-ink-700">
              <span className="font-semibold text-ink-500">{i + 1}.</span> {r.measure}
              <span className="ml-1.5 text-xs text-ink-500">
                · {r.immediately ? 'ihneď' : r.deadline ? formatDate(r.deadline) : 'bez termínu'}
              </span>
            </li>
          ))}
        </ol>
      )}
    </Card>
  );
}
