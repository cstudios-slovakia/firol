/**
 * Small building blocks shared by the screens of a preventívna prehliadka:
 * toggle chips (tiles), a check row, a segmented switch and the step bars.
 * Raw ink / firol palette like the rest of the app.
 */
import type { ReactNode } from 'react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/cn';
import { scopeLabel, type DefectScope, type PrehliadkaScope } from '@/lib/prehliadka';
import { Badge } from '@/components/ui/Badge';

/** A multi-line text field, styled like `Input`. */
export const TEXTAREA_CLASS =
  'w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 ' +
  'transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';

/** A tile the technician taps instead of typing (entrance, floor, place, deadline). */
export function Chip({
  active,
  onClick,
  children,
  dashed = false,
  disabled = false,
  id,
}: {
  active?: boolean;
  onClick: () => void;
  children: ReactNode;
  /** The „+ iný" tile that opens a text field. */
  dashed?: boolean;
  disabled?: boolean;
  id?: string;
}) {
  return (
    <button
      id={id}
      type="button"
      aria-pressed={active}
      disabled={disabled}
      onClick={onClick}
      className={cn(
        'min-h-10 rounded-xl border px-3 py-2 text-sm font-medium',
        'transition-[background-color,border-color,color,transform] duration-200 active:scale-[0.97]',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 disabled:pointer-events-none disabled:opacity-50',
        dashed && !active && 'border-dashed',
        active
          ? 'border-firol-500 bg-firol-50 text-firol-700'
          : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300 hover:bg-firol-50/60',
      )}
    >
      {children}
    </button>
  );
}

/** A tick-box row: a label, an optional second line, a tick when on. */
export function CheckRow({
  checked,
  onToggle,
  title,
  sub,
  disabled = false,
  id,
}: {
  checked: boolean;
  onToggle: () => void;
  title: ReactNode;
  sub?: ReactNode;
  disabled?: boolean;
  id?: string;
}) {
  return (
    <button
      id={id}
      type="button"
      role="checkbox"
      aria-checked={checked}
      disabled={disabled}
      onClick={onToggle}
      className={cn(
        'group flex w-full items-start gap-3 rounded-2xl border px-3.5 py-3 text-left',
        'transition-[background-color,border-color,transform] duration-200 active:scale-[0.99]',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
        disabled && 'cursor-not-allowed opacity-50',
        checked
          ? 'border-firol-300 bg-firol-50'
          : 'border-ink-200 bg-white enabled:hover:border-firol-300 enabled:hover:bg-firol-50/50',
      )}
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
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink-900">{title}</span>
        {sub && <span className="mt-0.5 block text-xs text-ink-500">{sub}</span>}
      </span>
    </button>
  );
}

/** Two or three exclusive buttons side by side (prototype `.seg`). */
export function Segmented<T extends string>({
  value,
  options,
  onChange,
  label,
}: {
  value: T | null;
  options: { value: T; label: string }[];
  onChange: (v: T) => void;
  label: string;
}) {
  return (
    <div
      role="radiogroup"
      aria-label={label}
      className="grid gap-1 rounded-2xl bg-ink-100 p-1"
      style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}
    >
      {options.map((o) => {
        const active = value === o.value;
        return (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(o.value)}
            className={cn(
              'min-h-10 rounded-xl px-2 text-sm font-medium transition-[background-color,color,box-shadow] duration-200',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
              active ? 'bg-white text-firol-700 shadow-[var(--shadow-soft)]' : 'text-ink-600 hover:text-ink-900',
            )}
          >
            {o.label}
          </button>
        );
      })}
    </div>
  );
}

/** „Krok N z 3" bars. */
export function StepBars({ step }: { step: 1 | 2 | 3 }) {
  return (
    <div className="mt-2 flex gap-1.5" role="img" aria-label={`Krok ${step} z 3`}>
      {[1, 2, 3].map((n) => (
        <i
          key={n}
          className={cn(
            'h-1 flex-1 rounded-full transition-colors duration-300',
            n <= step ? 'bg-firol-500' : 'bg-ink-200',
          )}
        />
      ))}
    </div>
  );
}

const SCOPE_TONE: Record<DefectScope, 'brand' | 'neutral'> = { po: 'brand', bo: 'neutral', ob: 'neutral' };

/** PO / BOZP / PO + BOZP chip of a defect. */
export function DefectScopeBadge({ scope }: { scope: DefectScope }) {
  const label = scope === 'po' ? 'PO' : scope === 'bo' ? 'BOZP' : 'PO + BOZP';
  return (
    <Badge tone={SCOPE_TONE[scope]} className={scope === 'bo' ? 'bg-[#E4EEF9] text-[#2D63A0]' : undefined}>
      {label}
    </Badge>
  );
}

/** What the prehliadka is, as the two-part chip of its header. */
export function ScopeBadge({ scope }: { scope: PrehliadkaScope }) {
  return <Badge tone="brand">{scopeLabel(scope)}</Badge>;
}
