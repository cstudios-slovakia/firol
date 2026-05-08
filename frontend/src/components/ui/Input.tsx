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
};

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { className, invalid, leftIcon, rightSlot, ...rest },
  ref,
) {
  if (leftIcon || rightSlot) {
    return (
      <div className="relative">
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
            invalid && invalidClasses,
            className,
          )}
          {...rest}
        />
        {rightSlot && (
          <span className="absolute right-1 top-1/2 -translate-y-1/2">
            {rightSlot}
          </span>
        )}
      </div>
    );
  }
  return (
    <input
      ref={ref}
      className={cn(baseClasses, invalid && invalidClasses, className)}
      {...rest}
    />
  );
});

const baseClasses =
  'h-11 w-full rounded-xl border border-ink-200 bg-white px-3 text-sm text-ink-800 ' +
  'placeholder:text-ink-400 transition-colors duration-150 ' +
  'hover:border-ink-300 ' +
  'focus:outline-none focus:border-firol-400 focus:ring-2 focus:ring-firol-200 ' +
  'disabled:bg-ink-50 disabled:text-ink-400';

const invalidClasses =
  'border-status-bad focus:border-status-bad focus:ring-[hsl(0_75%_90%)]';
