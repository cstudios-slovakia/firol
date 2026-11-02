import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BookOpen, ChevronRight } from 'lucide-react';
import { Book, bookPath, type BookSummary } from '@/api/book';

/**
 * Entry to the book on the firm's card (spec 9.1, 02.11 D4): the book is
 * a place at the firm, so it is reachable from here — „Požiarna kniha · N zápisov · M na pridanie".
 * The counts are a best-effort read: offline without a cached copy the card just shows the title.
 */
export function CompanyBookCard({ companyId }: { companyId: number }) {
  const [summary, setSummary] = useState<BookSummary | null>(null);

  useEffect(() => {
    let cancelled = false;
    Book.summary(companyId)
      .then((res) => {
        if (!cancelled) setSummary(res);
      })
      .catch(() => {
        if (!cancelled) setSummary(null);
      });
    return () => {
      cancelled = true;
    };
  }, [companyId]);

  const entries = summary ? summary.summary.po.entries : null;
  const offers = summary ? summary.summary.po.offers : null;

  const entriesLabel =
    entries === null
      ? null
      : entries === 1
        ? '1 zápis'
        : entries >= 2 && entries <= 4
          ? `${entries} zápisy`
          : `${entries} zápisov`;

  return (
    <Link
      to={bookPath(companyId, 'po')}
      className="mt-4 flex items-center gap-3 rounded-2xl border border-ink-100 bg-white px-4 py-3 transition-all duration-200 hover:bg-ink-50 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
    >
      <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
        <BookOpen className="size-5" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink-900">Požiarna kniha</span>
        {entriesLabel !== null && (
          <span className="mt-0.5 block truncate text-xs text-ink-500">
            {entriesLabel}
            {offers !== null && offers > 0 && (
              <>
                {' · '}
                <span className="font-medium text-[#5B3E8C]">{offers} na pridanie</span>
              </>
            )}
          </span>
        )}
      </span>
      <ChevronRight className="size-4 shrink-0 text-ink-300" />
    </Link>
  );
}
