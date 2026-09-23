import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';

/**
 * Circle with a technician's initials in their avatar colour — chapter 11.5.
 *
 * Only for the calendar, the Časová os and the Dnes screen, placed on the
 * right of the row. NOT in the Revízie / OPP / BOZP lists or the úkon list —
 * those already name who performed the úkon.
 *
 * The colour identifies the person only; the odbor keeps its own colour on the
 * same row (a bar, dot or chip), so both are always visible side by side.
 * Hovering shows the full name (native title); tapping shows it in a small
 * bubble, since touch has no hover. The tap never reaches the row underneath,
 * so an avatar inside a link does not navigate.
 */
export type AvatarTechnician = {
  fullname: string;
  initials: string;
  avatar_color: string;
};

const SIZES = {
  xs: 'size-5 text-[9px]',
  sm: 'size-6 text-[10px]',
  md: 'size-8 text-xs',
  lg: 'size-10 text-sm',
} as const;

export function TechnicianAvatar({
  technician,
  size = 'sm',
  className,
}: {
  technician: AvatarTechnician | null | undefined;
  size?: keyof typeof SIZES;
  className?: string;
}) {
  const [showName, setShowName] = useState(false);
  const timer = useRef<number | null>(null);

  useEffect(
    () => () => {
      if (timer.current !== null) window.clearTimeout(timer.current);
    },
    [],
  );

  if (!technician) return null;

  function reveal(e: React.MouseEvent | React.KeyboardEvent) {
    e.preventDefault();
    e.stopPropagation();
    setShowName(true);
    if (timer.current !== null) window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setShowName(false), 2000);
  }

  return (
    <span className={cn('relative inline-flex shrink-0', className)}>
      <span
        role="img"
        tabIndex={0}
        aria-label={technician.fullname}
        title={technician.fullname}
        onClick={reveal}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') reveal(e);
        }}
        style={{ backgroundColor: technician.avatar_color }}
        className={cn(
          'grid cursor-default select-none place-items-center rounded-full font-semibold uppercase leading-none tracking-tight text-white ring-2 ring-white',
          'transition-transform duration-150 ease-out hover:scale-110 active:scale-95',
          'focus-visible:outline-none focus-visible:ring-firol-300',
          SIZES[size],
        )}
      >
        {technician.initials}
      </span>
      <span
        role="tooltip"
        aria-hidden={!showName}
        className={cn(
          'pointer-events-none absolute bottom-full right-0 z-20 mb-1.5 whitespace-nowrap rounded-lg bg-ink-900 px-2 py-1 text-[11px] font-medium text-white shadow-lg',
          'transition-[opacity,transform] duration-150 ease-out',
          showName ? 'translate-y-0 opacity-100' : 'translate-y-1 opacity-0',
        )}
      >
        {technician.fullname}
      </span>
    </span>
  );
}
