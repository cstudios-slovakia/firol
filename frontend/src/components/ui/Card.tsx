import { cn } from '@/lib/cn';

type CardProps = React.HTMLAttributes<HTMLDivElement> & {
  elevated?: boolean;
};

export function Card({ elevated = true, className, ...rest }: CardProps) {
  return (
    <div
      className={cn(
        'rounded-[var(--radius-card)] bg-white border border-ink-100',
        elevated ? 'shadow-[var(--shadow-lift)]' : 'shadow-[var(--shadow-soft)]',
        className,
      )}
      {...rest}
    />
  );
}
