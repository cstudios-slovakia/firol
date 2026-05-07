import { cn } from '@/lib/cn';

export function Spinner({ size = 'md', className }: { size?: 'sm' | 'md' | 'lg'; className?: string }) {
  const dims = size === 'sm' ? 'size-4' : size === 'lg' ? 'size-7' : 'size-5';
  return (
    <span
      role="status"
      aria-label="Načítavam"
      className={cn(
        'inline-block rounded-full border-2 border-current border-r-transparent animate-spin',
        dims,
        className,
      )}
    />
  );
}
