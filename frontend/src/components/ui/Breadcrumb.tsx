import { Fragment } from 'react';
import { Link } from 'react-router-dom';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/cn';

export type Crumb = {
  label: string;
  /** Label of the crumb when it is the only one left on a narrow screen. */
  shortLabel?: string;
  /** Where the crumb leads. The last crumb is the current page and has none. */
  to?: string;
};

/**
 * Where the technician is inside a návšteva, one level per crumb:
 * Dnes › Návšteva › Úkon › Položka.
 *
 * Each crumb but the last is a link one level up, so „Späť" walks back one
 * step at a time and ends at the visit — never in a section's list. On a phone
 * the trail would not fit, so below `sm` it collapses to the parent crumb
 * alone („‹ Návšteva"), which is exactly what a back button would do.
 */
export function Breadcrumb({ items, className }: { items: Crumb[]; className?: string }) {
  if (items.length === 0) return null;
  const parent = items.length > 1 ? items[items.length - 2] : null;

  return (
    <nav aria-label="Breadcrumb" className={cn('min-w-0 self-stretch', className)}>
      {parent?.to && (
        <Link
          to={parent.to}
          className="inline-flex max-w-full items-center gap-1 text-sm text-ink-500 transition-colors hover:text-ink-700 sm:hidden"
        >
          <ChevronLeft className="size-4 shrink-0" />
          <span className="truncate">{parent.shortLabel ?? parent.label}</span>
        </Link>
      )}
      <ol
        className={cn(
          'min-w-0 items-center gap-1 text-sm text-ink-500',
          parent?.to ? 'hidden sm:flex' : 'flex',
        )}
      >
        {items.map((crumb, index) => {
          const last = index === items.length - 1;
          return (
            <Fragment key={`${index}-${crumb.label}`}>
              {index > 0 && (
                <li aria-hidden className="shrink-0 text-ink-300">
                  <ChevronRight className="size-3.5" />
                </li>
              )}
              <li className={cn('min-w-0', last ? 'shrink' : 'shrink-[2]')}>
                {last || !crumb.to ? (
                  <span
                    aria-current={last ? 'page' : undefined}
                    className={cn('block truncate', last && 'font-medium text-ink-700')}
                  >
                    {crumb.label}
                  </span>
                ) : (
                  <Link
                    to={crumb.to}
                    className="block max-w-[16rem] truncate transition-colors hover:text-firol-600"
                  >
                    {crumb.label}
                  </Link>
                )}
              </li>
            </Fragment>
          );
        })}
      </ol>
    </nav>
  );
}
