import { forwardRef } from 'react';
import { cn } from '@/lib/cn';

type InputProps = React.InputHTMLAttributes<HTMLInputElement> & {
  invalid?: boolean;
  leftIcon?: React.ReactNode;
  /**
   * Optional interactive slot rendered on the right edge of the field
   * (e.g. a "show password" toggle). Unlike `leftIcon` this stays
   * pointer-active so it can be clicked.
   */
  rightSlot?: React.ReactNode;
  /** Non-interactive unit label shown inside the right edge (e.g. "MPa", "m", "l/s"). */
  suffix?: string;
};

const DATE_TYPES = new Set(['date', 'time', 'datetime-local', 'month', 'week']);

function makePickerClick(userOnClick?: React.MouseEventHandler<HTMLInputElement>) {
  return (e: React.MouseEvent<HTMLInputElement>) => {
    userOnClick?.(e);
    if (!e.defaultPrevented && DATE_TYPES.has((e.currentTarget as HTMLInputElement).type)) {
      (e.currentTarget as HTMLInputElement).showPicker?.();
    }
  };
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { className, invalid, leftIcon, rightSlot, suffix, onClick, ...rest },
  ref,
) {
  const handleClick = DATE_TYPES.has(rest.type ?? '') ? makePickerClick(onClick) : onClick;

  if (leftIcon || rightSlot || suffix) {
    return (
      <div className="relative w-full min-w-0">
        {leftIcon && (
          <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-400">
            {leftIcon}
          </span>
        )}
        <input
          ref={ref}
          className={cn(
            baseClasses,
            leftIcon && 'pl-10',
            rightSlot && 'pr-11',
            suffix && 'pr-14',
            (invalid || rest['aria-invalid'] === true) && invalidClasses,
            className,
          )}
          onClick={handleClick}
          {...rest}
        />
        {rightSlot && (
          <span className="absolute right-1 top-1/2 -translate-y-1/2">
            {rightSlot}
          </span>
        )}
        {suffix && (
          <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-ink-400">
            {suffix}
          </span>
        )}
      </div>
    );
  }
  return (
    <input
      ref={ref}
      className={cn(baseClasses, (invalid || rest['aria-invalid'] === true) && invalidClasses, className)}
      onClick={handleClick}
      {...rest}
    />
  );
});

const baseClasses =
  'h-11 w-full min-w-0 appearance-none rounded-xl border border-ink-200 bg-white px-3 text-sm text-ink-800 ' +
  'placeholder:text-ink-400 transition-colors duration-150 ' +
  'hover:border-ink-300 ' +
  'focus:outline-none focus:border-firol-400 focus:ring-2 focus:ring-firol-200 ' +
  'disabled:bg-ink-50 disabled:text-ink-400';

const invalidClasses =
  'border-status-bad focus:border-status-bad focus:ring-[hsl(0_75%_90%)]';
