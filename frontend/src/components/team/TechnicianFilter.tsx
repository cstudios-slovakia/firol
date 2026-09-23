import { UserRound } from 'lucide-react';
import { Select } from '@/components/ui/Select';
import { cn } from '@/lib/cn';
import type { TechnicianFilterState } from '@/lib/technicianFilter';

/**
 * „Kto: [Všetci ▾] [Len moje]" — chapter 11.6. Pair with
 * `useTechnicianFilter()`:
 *
 *   const kto = useTechnicianFilter();
 *   <TechnicianFilter filter={kto} />
 *   rows.filter((r) => kto.matches(r.technician?.id))
 *
 * Renders nothing for a solo technician (nothing to filter). Combines freely
 * with any other filter on the page (odbor), since it only decides `matches`.
 * Texts from texty_ui.json → tim.
 */
export function TechnicianFilter({
  filter,
  className,
}: {
  filter: TechnicianFilterState;
  className?: string;
}) {
  const { value, setValue, team, currentUserId, isSolo } = filter;
  if (isSolo) return null;

  const mineActive = currentUserId !== null && value === currentUserId;

  return (
    <div className={cn('flex items-center gap-2', className)}>
      <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">Kto</span>
      <Select
        value={value === 'all' ? 'all' : String(value)}
        onChange={(v) => setValue(v === 'all' || v === '' ? 'all' : Number(v))}
        className="w-40"
        options={[
          { value: 'all', label: 'Všetci' },
          ...team.map((m) => ({
            value: String(m.id),
            label: m.fullname,
            description: m.initials,
          })),
        ]}
      />
      {currentUserId !== null && (
        <button
          type="button"
          aria-pressed={mineActive}
          onClick={() => setValue(mineActive ? 'all' : currentUserId)}
          className={cn(
            'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xl border px-3 text-sm font-medium',
            'transition-[background-color,border-color,color,transform] duration-150 active:scale-[0.97]',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 focus-visible:ring-offset-2',
            mineActive
              ? 'border-firol-300 bg-firol-50 text-firol-700'
              : 'border-ink-200 bg-white text-ink-700 hover:border-ink-300 hover:bg-ink-50',
          )}
        >
          <UserRound className="size-4" />
          Len moje
        </button>
      )}
    </div>
  );
}
