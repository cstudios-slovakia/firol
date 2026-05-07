import { cn } from '@/lib/cn';

type Tone = 'neutral' | 'ok' | 'warn' | 'bad' | 'brand';

const toneClasses: Record<Tone, string> = {
  neutral: 'bg-ink-100 text-ink-700',
  ok:      'bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
  warn:    'bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
  bad:     'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
  brand:   'bg-firol-100 text-firol-700',
};

export function Badge({
  tone = 'neutral',
  className,
  children,
}: {
  tone?: Tone;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
        toneClasses[tone],
        className,
      )}
    >
      {children}
    </span>
  );
}
