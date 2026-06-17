import { cn } from '@/lib/cn';

/**
 * POapp brand mark — the shield-and-flame crest from the marketing site
 * (poapp.sk). Monochrome: it paints with `currentColor`, so set the colour
 * via a text utility on the element (e.g. `text-firol-600` on light surfaces,
 * `text-white` on the brand gradient).
 */
export function BrandMark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 34 38"
      fill="none"
      className={className}
      aria-hidden="true"
      xmlns="http://www.w3.org/2000/svg"
    >
      <path
        d="M17 1 L32 6 V18 C32 28 25 34 17 37 C9 34 2 28 2 18 V6 Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
      />
      <path
        d="M17 9 C20 13 22 15 22 19.5 C22 23 19.8 25.5 17 25.5 C14.2 25.5 12 23 12 19.5 C12 17.5 13 16 14 15 C14 17 15 18 16 18.5 C16 16 16.5 12 17 9 Z"
        fill="currentColor"
      />
    </svg>
  );
}

/**
 * Full POapp logo lockup: crest + wordmark, matching the landing page.
 * `tone="brand"` for light backgrounds (ink wordmark, red accent on "app"),
 * `tone="inverted"` for the brand gradient panel (all white).
 */
export function Logo({
  className,
  markClassName,
  wordClassName,
  tone = 'brand',
}: {
  className?: string;
  markClassName?: string;
  wordClassName?: string;
  tone?: 'brand' | 'inverted';
}) {
  const inverted = tone === 'inverted';
  return (
    <span className={cn('inline-flex items-center gap-2.5', className)}>
      <BrandMark
        className={cn(
          'h-[1.4em] w-auto',
          inverted ? 'text-white' : 'text-firol-600',
          markClassName,
        )}
      />
      <span
        className={cn(
          'font-bold tracking-tight',
          inverted ? 'text-white' : 'text-ink-900',
          wordClassName,
        )}
      >
        PO<span className={inverted ? undefined : 'text-firol-600'}>app</span>
      </span>
    </span>
  );
}
