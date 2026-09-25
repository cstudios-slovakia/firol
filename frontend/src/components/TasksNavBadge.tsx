/**
 * Chapter 20 — „Odznak s počtom otvorených úloh na záložke." The number of
 * open tasks of the account, i.e. what the Úlohy list shows with its default
 * filter. Hidden at zero. Re-read on every screen change and after any task
 * write, so ticking a task off updates it at once.
 */
import { useLocation } from 'react-router-dom';
import { useOpenTaskCount } from '@/api/tasks';
import { cn } from '@/lib/cn';

export function TasksNavBadge({ variant }: { variant: 'side' | 'bottom' }) {
  const location = useLocation();
  const count = useOpenTaskCount(location.pathname);
  if (count <= 0) return null;
  const label = count > 99 ? '99+' : String(count);
  return (
    <span
      aria-label={`${count} otvorených úloh`}
      className={cn(
        'inline-flex min-w-5 items-center justify-center rounded-full bg-firol-500 px-1.5 text-[11px] font-semibold leading-5 text-white shadow-sm tabular-nums animate-fade-up',
        variant === 'side'
          ? 'ml-auto'
          // Pinned to the icon's top-right corner in the bottom bar.
          : 'pointer-events-none absolute left-1/2 top-0.5 ml-1.5 min-w-4 px-1 text-[10px] leading-4',
      )}
    >
      {label}
    </span>
  );
}
