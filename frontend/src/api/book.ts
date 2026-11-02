/**
 * Požiarna kniha as a ledger (spec 9, phase 5, 02.11 D4).
 *
 * A book is a place at a firm; an entry is one numbered row in it, never a
 * document. Offline handling, per endpoint:
 *   GET   /api/companies/{id}/book            cached by `lib/api.ts`; the book opens offline
 *   GET   /api/companies/{id}/book-summary    cached the same way
 *   POST  /api/companies/{id}/book/entries    online-only (the poradové číslo is allocated by the server)
 *   POST  /api/book-entries/{id}/additions    online-only
 *   POST  /api/companies/{id}/book/mark-signed online-only
 *   PATCH /api/companies/{id}/book-scope      online-only
 *   GET   /api/companies/{id}/book/print      PDF, opened in a new tab — needs a connection
 */
import { api, buildUrl } from '@/lib/api';
import type { ClientType } from '@/lib/clientKind';
import type { BookKey } from '@/lib/bookMapping';

export type BookScope = 'firma' | 'prevadzka';
export type BookEntrySource = 'ukon' | 'cislo' | 'vlastny' | 'termin' | 'revizia' | 'hromadny';

export type BookAddition = {
  id: number;
  text: string;
  created_at: string;
  created_by_name: string | null;
};

export type BookEntry = {
  id: number;
  /** Set only for an entry written in a per-prevádzka book. */
  facility_id: number | null;
  facility_name: string | null;
  book: BookKey;
  /** Poradové číslo within the ledger. */
  seq: number;
  entry_date: string;
  text: string;
  source: BookEntrySource;
  protocol_numbers: string[];
  inspection_ids: number[];
  training_ids: number[];
  no_defects_statement: boolean;
  defects_text: string | null;
  /** Made by the technician — true from the moment the entry is written. */
  signed_technician: boolean;
  /** Printed and signed by the vedúci zamestnanec. */
  signed_manager: boolean;
  signed_manager_at: string | null;
  signer_name: string | null;
  signer_role: string | null;
  created_by_name: string | null;
  created_at: string;
  additions: BookAddition[];
};

/** One finished úkon (or several merged, same type and day) the app offers to add. */
export type BookOffer = {
  key: string;
  kind: 'inspection' | 'training' | 'termin';
  type: string;
  title: string;
  date: string;
  facilities: string[];
  /** How many úkony the offer merges (spec 9.2: same úkon, same day, several prevádzky). */
  merged: number;
  protocol_numbers: string[];
  inspection_ids: number[];
  training_ids: number[];
  text: string;
  /** True when the úkon carries defects, so „bez nedostatkov" cannot be ticked. */
  has_defects: boolean;
  is_latest?: boolean;
  preselected?: boolean;
  defects_text?: string | null;
};

export type BookActionGroup = {
  type: string;
  title: string;
  count: number;
  items: BookOffer[];
};

export type BookManualKind = { kind: string; label: string };

export type BookView = {
  company: { id: number; name: string; client_type: ClientType; archived: boolean };
  book: BookKey;
  book_label: string;
  scope: BookScope;
  facilities: { id: number; name: string }[];
  /** The prevádzka of the ledger shown; null for the firm-wide book. */
  facility_id: number | null;
  can_switch_scope: boolean;
  entries: BookEntry[];
  offers: BookOffer[];
  available_actions?: BookActionGroup[];
  previous_scope_entries: BookEntry[];
  unsigned_count: number;
  /** Prefill of the signing person (company level); the field stays editable. */
  signer: { fullname: string; role_title: string | null } | null;
  manual_kinds?: BookManualKind[];
  legal_sentence: string;
  no_defects_sentence: string;
};

export type BookSummary = {
  scope: BookScope;
  summary: Record<BookKey, { entries: number; offers: number }>;
};

export type CreateEntryBody = {
  book: BookKey;
  facility_id?: number | null;
  source: BookEntrySource;
  date?: string;
  text?: string;
  vlastny_text?: string;
  defects_text?: string;
  legal_sentence?: boolean;
  no_defects_statement?: boolean;
  protocol_numbers?: string[];
  inspection_ids?: number[];
  training_ids?: number[];
  /** `cislo` only. */
  kind?: string;
  number?: string;
  object?: string;
};

export const Book = {
  show: (companyId: number, book: BookKey, facilityId?: number | null) => {
    const qs = new URLSearchParams({ book });
    if (facilityId) qs.set('facility_id', String(facilityId));
    return api<BookView>(`/api/companies/${companyId}/book?${qs.toString()}`);
  },
  summary: (companyId: number) => api<BookSummary>(`/api/companies/${companyId}/book-summary`),
  createEntry: (companyId: number, body: CreateEntryBody, csrfToken: string | null) =>
    api<{ entry: BookEntry }>(`/api/companies/${companyId}/book/entries`, {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),
  addAddition: (entryId: number, text: string, csrfToken: string | null) =>
    api<{ entry: BookEntry }>(`/api/book-entries/${entryId}/additions`, {
      method: 'POST',
      body: { text },
      csrfToken,
      requireOnline: true,
    }),
  markSigned: (
    companyId: number,
    body: {
      book: BookKey;
      facility_id?: number | null;
      entry_ids?: number[];
      signer_name?: string;
      signer_role?: string;
    },
    csrfToken: string | null,
  ) =>
    api<{ marked: number }>(`/api/companies/${companyId}/book/mark-signed`, {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),
  setScope: (companyId: number, bookScope: BookScope, csrfToken: string | null) =>
    api<{ book_scope: BookScope }>(`/api/companies/${companyId}/book-scope`, {
      method: 'PATCH',
      body: { book_scope: bookScope },
      csrfToken,
      requireOnline: true,
    }),
};

/** URL of the PDF of the full book (`mode=all`), unsigned entries (`mode=unsigned`), or one entry (`mode=entry`, `entryId`). */
export function bookPrintUrl(
  companyId: number,
  book: BookKey,
  opts: {
    facilityId?: number | null;
    entryId?: number;
    mode?: 'entry' | 'all' | 'unsigned';
    signerName?: string;
    signerRole?: string;
  } = {},
): string {
  const mode = opts.mode ?? (opts.entryId ? 'entry' : 'unsigned');
  const qs = new URLSearchParams({ book, mode });
  if (opts.facilityId) qs.set('facility_id', String(opts.facilityId));
  if (opts.entryId) qs.set('entry_id', String(opts.entryId));
  if (opts.signerName) qs.set('signer_name', opts.signerName);
  if (opts.signerRole) qs.set('signer_role', opts.signerRole);
  return buildUrl(`/api/companies/${companyId}/book/print?${qs.toString()}`);
}

/** Link to the book of a firm, optionally with a prevádzka selected. */
export function bookPath(companyId: number, book: BookKey = 'po', facilityId?: number | null): string {
  const qs = new URLSearchParams({ book });
  if (facilityId) qs.set('facility', String(facilityId));
  return `/companies/${companyId}/kniha?${qs.toString()}`;
}
