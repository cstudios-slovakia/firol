/**
 * Building blocks shared by the four single-record BOZP forms (kniha BOZP,
 * pracovisko, osamelé pracoviská, fajčenie). Built for the field: large tap
 * targets, one-handed use, and nothing that needs a connection to work —
 * the place suggestions are a convenience that simply stays empty offline.
 */
import { useEffect, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Check, CheckCircle2, Edit2, MapPin, Plus, Trash2, X } from 'lucide-react';
import { Inspections } from '@/api/inspections';
import {
  OVERALL_HINTS,
  OVERALL_LABELS,
  OVERALL_RESULTS,
  ROW_RESULT_LABELS,
  type OverallResult,
  type RowResult,
} from '@/api/bozpRecords';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';
import { fold } from '@/lib/text';

const TEXTAREA =
  'w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';
const INPUT =
  'min-h-11 w-full rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';

export const fieldClasses = { textarea: TEXTAREA, input: INPUT };

/** Section heading with the required star, matching <Field>'s label. */
export function SectionLabel({ children, required, aside }: { children: ReactNode; required?: boolean; aside?: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-ink-500">
        {children}
        {required && <span className="ml-1 text-firol-500">*</span>}
      </h3>
      {aside}
    </div>
  );
}

export function FieldError({ children }: { children: ReactNode }) {
  return children ? <p className="text-xs text-status-bad">{children}</p> : null;
}

/**
 * A list of places (prehliadnuté pracoviská, kontrolované priestory): typed
 * one at a time or picked from the places already recorded on this
 * prevádzka — the „výber z prevádzky" of chapter 7.
 */
export function PlaceListField({
  label,
  values,
  onChange,
  facilityId,
  placeholder,
  error,
}: {
  label: string;
  values: string[];
  onChange: (next: string[]) => void;
  facilityId: number;
  placeholder: string;
  error: string | null;
}) {
  const [draft, setDraft] = useState('');
  const [known, setKnown] = useState<string[]>([]);

  useEffect(() => {
    let cancelled = false;
    Inspections.suggestions('location', '', facilityId, true)
      .then((res) => { if (!cancelled) setKnown(res.suggestions); })
      .catch(() => { /* offline or no history — typing still works */ });
    return () => { cancelled = true; };
  }, [facilityId]);

  function add(value: string) {
    const v = value.trim();
    if (!v) return;
    if (!values.some((x) => fold(x) === fold(v))) onChange([...values, v]);
    setDraft('');
  }

  const q = fold(draft.trim());
  const offered = known
    .filter((k) => !values.some((v) => fold(v) === fold(k)))
    .filter((k) => !q || fold(k).includes(q))
    .slice(0, 12);

  return (
    <div className="flex flex-col gap-2">
      <SectionLabel required>{label}</SectionLabel>
      {values.length > 0 && (
        <ul className="flex flex-wrap gap-2">
          {values.map((v) => (
            <li key={v}
              className="inline-flex min-h-10 items-center gap-1 rounded-full border border-firol-300 bg-firol-50 py-1 pl-3 pr-1 text-sm text-firol-800 transition-all duration-300">
              {v}
              <button type="button" aria-label={`Odstrániť ${v}`}
                onClick={() => onChange(values.filter((x) => x !== v))}
                className="grid size-8 place-items-center rounded-full text-firol-600 transition-colors hover:bg-firol-100 active:scale-95">
                <X className="size-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}
      <div className="flex gap-2">
        <input
          type="text"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              add(draft);
            }
          }}
          placeholder={placeholder}
          aria-label={label}
          className={INPUT}
        />
        <Button type="button" variant="secondary" onClick={() => add(draft)} disabled={!draft.trim()}
          leftIcon={<Plus className="size-4" />} className="min-h-11 shrink-0">
          Pridať
        </Button>
      </div>
      {offered.length > 0 && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-ink-400">Z tejto prevádzky:</span>
          <div className="flex flex-wrap gap-2">
            {offered.map((k) => (
              <button key={k} type="button" onClick={() => add(k)}
                className="inline-flex min-h-10 items-center gap-1 rounded-full border border-dashed border-ink-300 px-3 py-1 text-sm text-ink-600 transition-all duration-300 hover:border-firol-400 hover:text-firol-700 active:scale-95">
                <MapPin className="size-3.5" />
                {k}
              </button>
            ))}
          </div>
        </div>
      )}
      <FieldError>{error}</FieldError>
    </div>
  );
}

/** A large tappable checkbox row. */
export function CheckRow({
  label,
  checked,
  onToggle,
  onRemove,
}: {
  label: ReactNode;
  checked: boolean;
  onToggle: () => void;
  /** Custom rows can be removed again. */
  onRemove?: () => void;
}) {
  return (
    <div className={cn(
      'flex items-stretch rounded-xl border transition-all duration-300',
      checked ? 'border-firol-500 bg-firol-50' : 'border-ink-200 bg-white hover:border-firol-300',
    )}>
      <button type="button" role="checkbox" aria-checked={checked} onClick={onToggle}
        className="flex min-h-12 flex-1 items-start gap-3 px-3 py-3 text-left text-sm text-ink-800 active:scale-[0.99]">
        <span className={cn(
          'mt-0.5 grid size-5 shrink-0 place-items-center rounded-md border transition-colors',
          checked ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
        )}>
          {checked && <Check className="size-3.5" strokeWidth={3} />}
        </span>
        <span className="flex-1">{label}</span>
      </button>
      {onRemove && (
        <button type="button" onClick={onRemove} aria-label="Odstrániť"
          className="grid w-11 shrink-0 place-items-center rounded-r-xl text-ink-400 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad">
          <X className="size-4" />
        </button>
      )}
    </div>
  );
}

/** „Vyhovuje / Nevyhovuje" as two big buttons. Tapping the active one clears it. */
export function PassFailToggle({
  value,
  onChange,
  allowClear = false,
  label,
}: {
  value: RowResult | '';
  onChange: (v: RowResult | '') => void;
  allowClear?: boolean;
  label: string;
}) {
  return (
    <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label={label}>
      {(['vyhovuje', 'nevyhovuje'] as RowResult[]).map((r) => {
        const active = value === r;
        const tone = r === 'vyhovuje'
          ? 'border-status-ok bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]'
          : 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]';
        return (
          <button key={r} type="button" role="radio" aria-checked={active}
            onClick={() => onChange(active && allowClear ? '' : r)}
            className={cn(
              'flex min-h-11 items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-semibold transition-all duration-300 active:scale-[0.98]',
              active ? tone : 'border-ink-200 bg-white text-ink-600 hover:border-ink-300',
            )}>
            {active && <CheckCircle2 className="size-4" />}
            {ROW_RESULT_LABELS[r]}
          </button>
        );
      })}
    </div>
  );
}

/** Celkové hodnotenie — ciselniky.json `hodnotenie_auditu`, with its description. */
export function OverallPicker({
  value,
  onChange,
  error,
}: {
  value: OverallResult | '';
  onChange: (v: OverallResult) => void;
  error: string | null;
}) {
  return (
    <div className="flex flex-col gap-2">
      <SectionLabel required>Celkové hodnotenie</SectionLabel>
      <div className="flex flex-col gap-2" role="radiogroup" aria-label="Celkové hodnotenie">
        {OVERALL_RESULTS.map((o) => {
          const active = value === o;
          const tone = o === 'vyhovujuci'
            ? 'border-status-ok bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]'
            : o === 'nevyhovujuci'
              ? 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]'
              : 'border-status-warn bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]';
          return (
            <button key={o} type="button" role="radio" aria-checked={active} onClick={() => onChange(o)}
              className={cn(
                'flex min-h-12 flex-col items-start rounded-xl border px-4 py-2.5 text-left transition-all duration-300 active:scale-[0.99]',
                active ? tone : 'border-ink-200 bg-white text-ink-700 hover:border-ink-300',
              )}>
              <span className="flex items-center gap-1.5 text-sm font-semibold">
                {active && <CheckCircle2 className="size-4" />}
                {OVERALL_LABELS[o]}
              </span>
              <span className={cn('mt-0.5 text-xs', active ? 'opacity-80' : 'text-ink-500')}>{OVERALL_HINTS[o]}</span>
            </button>
          );
        })}
      </div>
      <FieldError>{error}</FieldError>
    </div>
  );
}

/** The error box + submit button every record form ends with. */
export function RecordSubmit({
  submitting,
  editing,
  apiError,
  hasErrors,
}: {
  submitting: boolean;
  editing: boolean;
  apiError: string | null;
  hasErrors: boolean;
}) {
  return (
    <>
      {apiError && (
        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          {apiError}
        </div>
      )}
      {hasErrors && !apiError && (
        <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          Formulár obsahuje nevyplnené povinné polia.
        </p>
      )}
      <div className="sticky bottom-3 z-10 flex justify-end pt-1">
        <Button type="submit" loading={submitting} className="min-h-12 w-full shadow-lg sm:w-auto">
          {editing ? 'Uložiť zmeny' : 'Uložiť záznam a prejsť na súhrn'}
        </Button>
      </div>
    </>
  );
}

/** Summary row shell: number badge, title, badges, detail lines, edit/delete. */
export function RecordRow({
  inspectionId,
  itemId,
  index,
  title,
  badges,
  lines,
  canEdit,
  deleting,
  onDelete,
}: {
  inspectionId: number;
  itemId: number;
  index: number;
  title: ReactNode;
  badges?: ReactNode;
  lines?: ReactNode;
  canEdit: boolean;
  deleting: boolean;
  onDelete: () => void;
}) {
  return (
    <div className="px-4 py-3">
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-sm font-semibold text-firol-700">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="min-w-0 truncate text-sm font-semibold text-ink-900">{title}</h3>
            {badges}
          </div>
          {lines}
        </div>
        {canEdit && (
          <div className="flex shrink-0 items-center gap-2">
            <Link to={`/inspections/${inspectionId}/items/${itemId}`} aria-label="Upraviť záznam"
              className="grid size-10 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]">
              <Edit2 className="size-4" />
            </Link>
            <button type="button" onClick={onDelete} disabled={deleting} aria-label="Zmazať záznam"
              className="grid size-10 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)] disabled:opacity-50">
              {deleting ? <Spinner size="sm" /> : <Trash2 className="size-4" />}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

/** Slovak plural for a count: 1 nedostatok, 2 nedostatky, 5 nedostatkov. */
export function plural(n: number, one: string, few: string, many: string): string {
  return `${n} ${n === 1 ? one : n > 1 && n < 5 ? few : many}`;
}
