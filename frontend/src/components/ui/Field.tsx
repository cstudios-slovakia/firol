import { useId } from 'react';
import { cn } from '@/lib/cn';

type FieldProps = {
  label: string;
  hint?: string;
  error?: string | null;
  required?: boolean;
  children: (props: { id: string; 'aria-invalid': boolean | undefined }) => React.ReactNode;
  className?: string;
};

export function Field({ label, hint, error, required, children, className }: FieldProps) {
  const id = useId();
  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-xs font-semibold uppercase tracking-wide text-ink-500">
        {label}
        {required && <span className="ml-1 text-firol-500">*</span>}
      </label>
      {children({ id, 'aria-invalid': error ? true : undefined })}
      {(hint || error) && (
        <p
          className={cn(
            'text-xs',
            error ? 'text-status-bad' : 'text-ink-400',
          )}
        >
          {error ?? hint}
        </p>
      )}
    </div>
  );
}
