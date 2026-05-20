import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/cn';

type Props = {
  page: number;
  totalPages: number;
  totalItems: number;
  pageSize: number;
  onChange: (page: number) => void;
};

export function Pagination({ page, totalPages, totalItems, pageSize, onChange }: Props) {
  if (totalPages <= 1) return null;

  const from = (page - 1) * pageSize + 1;
  const to = Math.min(page * pageSize, totalItems);

  const slots: (number | 'gap')[] = buildSlots(page, totalPages);

  return (
    <div className="flex items-center justify-center gap-3">
      <div className="flex items-center gap-1">
        <button
          type="button"
          onClick={() => onChange(page - 1)}
          disabled={page === 1}
          aria-label="Predchádzajúca strana"
          className="grid size-8 place-items-center rounded-xl text-ink-600 transition-colors hover:bg-ink-100 disabled:pointer-events-none disabled:opacity-35"
        >
          <ChevronLeft className="size-4" />
        </button>

        {slots.map((slot, i) =>
          slot === 'gap' ? (
            <span key={`g${i}`} className="grid size-8 place-items-center text-sm text-ink-400 select-none">
              …
            </span>
          ) : (
            <button
              key={slot}
              type="button"
              onClick={() => onChange(slot)}
              aria-current={slot === page ? 'page' : undefined}
              className={cn(
                'grid h-8 min-w-[2rem] place-items-center rounded-xl px-1.5 text-sm font-medium transition-colors',
                slot === page
                  ? 'bg-firol-500 text-white'
                  : 'text-ink-600 hover:bg-ink-100',
              )}
            >
              {slot}
            </button>
          ),
        )}

        <button
          type="button"
          onClick={() => onChange(page + 1)}
          disabled={page === totalPages}
          aria-label="Ďalšia strana"
          className="grid size-8 place-items-center rounded-xl text-ink-600 transition-colors hover:bg-ink-100 disabled:pointer-events-none disabled:opacity-35"
        >
          <ChevronRight className="size-4" />
        </button>
      </div>

      <span className="text-xs text-ink-400 tabular-nums">
        {from}–{to} z {totalItems}
      </span>
    </div>
  );
}

function buildSlots(page: number, total: number): (number | 'gap')[] {
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1);
  }
  const slots: (number | 'gap')[] = [1];
  const lo = Math.max(2, page - 1);
  const hi = Math.min(total - 1, page + 1);
  if (lo > 2) slots.push('gap');
  for (let p = lo; p <= hi; p++) slots.push(p);
  if (hi < total - 1) slots.push('gap');
  slots.push(total);
  return slots;
}
