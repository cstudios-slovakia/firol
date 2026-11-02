/**
 * Termíny cudzích revízií of a firm (spec 6.6, phase 6) — revisions the client
 * has done by someone else, remembered per firma and printed in the „Termíny
 * a kontroly" table of the record of their book.
 *
 * Offline handling, per endpoint:
 *   GET    /api/companies/{id}/external-terms        cached by `lib/api.ts`; the list opens offline
 *   POST   /api/companies/{id}/external-terms        online-only
 *   PATCH  /api/companies/{id}/external-terms/{id}   online-only
 *   DELETE /api/companies/{id}/external-terms/{id}   online-only (archives the term)
 */
import { api } from '@/lib/api';
import type { BookKey } from '@/lib/bookMapping';

export type ExternalTerm = {
  id: number;
  /** Null = the whole firm; set = pinned to one prevádzka. */
  facility_id: number | null;
  facility_name: string | null;
  book: BookKey;
  name: string;
  performed_by: string | null;
  done_on: string | null;
  valid_until: string | null;
  note: string | null;
  /** The sentence „Do knihy" proposes — editable before it is written. */
  book_sentence: string;
};

/** A name the form offers; the book is only a suggestion, the technician answers it. */
export type ExternalTermSuggestion = { name: string; book: BookKey };

export type ExternalTermBody = {
  name: string;
  book: BookKey;
  facility_id: number | null;
  performed_by: string | null;
  done_on: string | null;
  valid_until: string | null;
  note: string | null;
};

export const ExternalTerms = {
  /** `facilityId` narrows the list to the firm-wide terms plus those pinned to that prevádzka. */
  list: (companyId: number, book?: BookKey, facilityId?: number) => {
    const parts = [book ? `book=${book}` : '', facilityId ? `facility_id=${facilityId}` : ''].filter(Boolean);
    const qs = parts.length > 0 ? `?${parts.join('&')}` : '';
    return api<{ items: ExternalTerm[]; suggestions: ExternalTermSuggestion[] }>(
      `/api/companies/${companyId}/external-terms${qs}`,
    );
  },
  create: (companyId: number, body: ExternalTermBody, csrfToken: string | null) =>
    api<{ term: ExternalTerm }>(`/api/companies/${companyId}/external-terms`, {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),
  update: (companyId: number, id: number, body: Partial<ExternalTermBody>, csrfToken: string | null) =>
    api<{ term: ExternalTerm }>(`/api/companies/${companyId}/external-terms/${id}`, {
      method: 'PATCH',
      body,
      csrfToken,
      requireOnline: true,
    }),
  remove: (companyId: number, id: number, csrfToken: string | null) =>
    api<void>(`/api/companies/${companyId}/external-terms/${id}`, {
      method: 'DELETE',
      csrfToken,
      requireOnline: true,
    }),
};

export type TermState = { label: string; tone: 'ok' | 'warn' | 'bad' | 'neutral' };

/** The state the record prints (same 30-day window), judged today. */
export function termState(validUntil: string | null, todayIso: string): TermState {
  if (!validUntil) return { label: 'nedoložené', tone: 'neutral' };
  const days = Math.floor((Date.parse(validUntil) - Date.parse(todayIso)) / 86_400_000);
  if (days < 0) return { label: 'po termíne', tone: 'bad' };
  if (days <= 30) return { label: 'blíži sa termín', tone: 'warn' };
  return { label: 'v platnosti', tone: 'ok' };
}
