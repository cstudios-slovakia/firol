import { useRef } from 'react';
import { cn } from '@/lib/cn';
import { ClearTextButton } from '@/components/ui/ClearTextButton';

type TextareaProps = Omit<React.TextareaHTMLAttributes<HTMLTextAreaElement>, 'value' | 'onChange'> & {
  value: string;
  onValueChange: (value: string) => void;
  /** Decorative icon in the top left corner. */
  leftIcon?: React.ReactNode;
  invalid?: boolean;
  /** Show the „Vymazať text" button in the top right corner while there is text. */
  clearable?: boolean;
};

/**
 * The multi-line text field of the item forms: optional icon on the left,
 * optional clear button in the top right corner ({@link ClearTextButton}).
 */
export function Textarea({
  value,
  onValueChange,
  leftIcon,
  invalid,
  clearable = false,
  className,
  disabled,
  ...rest
}: TextareaProps) {
  const ref = useRef<HTMLTextAreaElement>(null);
  return (
    <div className="relative">
      {leftIcon && (
        <span className="pointer-events-none absolute left-3 top-3 text-ink-400">{leftIcon}</span>
      )}
      <textarea
        ref={ref}
        value={value}
        disabled={disabled}
        onChange={(e) => onValueChange(e.target.value)}
        className={cn(
          'w-full rounded-xl border border-ink-200 bg-white py-2.5 pr-3 text-sm text-ink-800 placeholder:text-ink-400',
          'transition-colors duration-150 hover:border-ink-300',
          'focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200',
          'disabled:bg-ink-50 disabled:text-ink-400',
          leftIcon ? 'pl-10' : 'pl-3',
          clearable && 'pr-10',
          (invalid || rest['aria-invalid'] === true) && 'border-status-bad focus:border-status-bad focus:ring-[hsl(0_75%_90%)]',
          className,
        )}
        {...rest}
      />
      {clearable && (
        <span className="absolute right-1 top-1">
          <ClearTextButton value={value} onClear={() => onValueChange('')} target={ref} disabled={disabled} />
        </span>
      )}
    </div>
  );
}
