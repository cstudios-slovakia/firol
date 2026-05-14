import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';

/**
 * Base shimmer block. Pages compose these into skeletons that mirror the
 * shape of their real content — so the layout doesn't jump when data
 * lands and the perceived load time drops.
 */
export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      aria-hidden
      className={cn('animate-pulse rounded-lg bg-ink-100/80', className)}
    />
  );
}

/** Mirrors the company card on Dashboard / company lists. */
export function CompanyCardSkeleton() {
  return (
    <Card className="px-4 py-3.5">
      <div className="flex items-start gap-3">
        <Skeleton className="size-10 shrink-0 rounded-2xl" />
        <div className="min-w-0 flex-1 space-y-2">
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-3 w-1/2" />
          <Skeleton className="h-5 w-32 rounded-full" />
        </div>
      </div>
    </Card>
  );
}

/** Mirrors inspection / training list rows. */
export function ListItemSkeleton() {
  return (
    <Card className="px-4 py-3">
      <div className="flex items-start gap-3">
        <Skeleton className="size-11 shrink-0 rounded-2xl" />
        <div className="min-w-0 flex-1 space-y-2">
          <div className="flex items-center gap-2">
            <Skeleton className="h-4 w-32" />
            <Skeleton className="h-4 w-14 rounded-full" />
          </div>
          <Skeleton className="h-3 w-3/4" />
          <Skeleton className="h-3 w-2/3" />
        </div>
      </div>
    </Card>
  );
}

/** Header for detail pages — avatar tile + title + meta line + action. */
export function DetailHeaderSkeleton() {
  return (
    <Card className="overflow-hidden">
      <div className="bg-gradient-to-br from-firol-50/60 to-transparent px-5 pt-5">
        <div className="flex items-start gap-3">
          <Skeleton className="size-12 shrink-0 rounded-2xl" />
          <div className="min-w-0 flex-1 space-y-2 pt-1">
            <Skeleton className="h-5 w-2/3" />
            <Skeleton className="h-3 w-1/3" />
          </div>
        </div>
      </div>
      <div className="flex flex-col divide-y divide-ink-100 px-5 py-3">
        <div className="flex items-start gap-3 py-2.5">
          <Skeleton className="size-6 shrink-0" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-2.5 w-12" />
            <Skeleton className="h-3.5 w-1/2" />
          </div>
        </div>
        <div className="flex items-start gap-3 py-2.5">
          <Skeleton className="size-6 shrink-0" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-2.5 w-16" />
            <Skeleton className="h-3.5 w-2/3" />
          </div>
        </div>
      </div>
    </Card>
  );
}

/** Generic 3-line card skeleton for sections of a detail page. */
export function CardBlockSkeleton({ rows = 3 }: { rows?: number }) {
  return (
    <Card className="space-y-3 px-5 py-4">
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className={i === 0 ? 'h-4 w-1/3' : 'h-3 w-full'} />
      ))}
    </Card>
  );
}

/**
 * Renders N copies of a skeleton variant inside a flex column — convenience
 * for replacing the "centered spinner" pattern in list views.
 */
export function SkeletonList({
  count = 3,
  variant = 'list',
}: {
  count?: number;
  variant?: 'list' | 'company';
}) {
  const Item = variant === 'company' ? CompanyCardSkeleton : ListItemSkeleton;
  return (
    <ul className="flex flex-col gap-2">
      {Array.from({ length: count }, (_, i) => (
        <li key={i}>
          <Item />
        </li>
      ))}
    </ul>
  );
}
