import { api } from '@/lib/api';

/**
 * Sklad — materiál a značenie (block 4 / chapter 21).
 *
 * A count of what the firm has and who has it: „Sklad" plus every technician.
 * Not a warehouse system — no prices, batches or low-stock alerts.
 *
 * Every write is online-only (`requireOnline`): the server has to check the
 * holder's balance against every other move in flight, and a použitie queued
 * offline could overdraw a holder by the time it replays. The journal of
 * movements is read-only — there is no call that edits or deletes one; only
 * the invoice flags of a použitie can change.
 */

export type StockUnit = 'ks' | 'bal' | 'm';
export type StockAction = 'nakup' | 'presun' | 'pouzite';

/** A holder in a request: the firm's own stock or a technician's user id. */
export type HolderRef = 'sklad' | number;

export type StockHolder = {
  user_id: number;
  name: string;
  is_active: boolean;
};

export type StockItem = {
  id: number;
  name: string;
  unit: StockUnit;
  /** Balance of the holder „Sklad". */
  warehouse: number;
  /** user id (as a string key) → count; technicians holding nothing are absent. */
  balances: Record<string, number>;
  /** Sum over all holders. */
  total: number;
};

export type MovementSide = {
  holder: 'sklad' | 'technik';
  user_id: number | null;
  name: string;
};

export type StockMovement = {
  id: number;
  item_id: number;
  item_name: string;
  unit: StockUnit;
  action: StockAction;
  from: MovementSide | null;
  to: MovementSide | null;
  qty: number;
  company_id: number | null;
  company_name: string | null;
  inspection_id: number | null;
  inspection_type: string | null;
  inspection_date: string | null;
  inspection_number: string | null;
  note: string | null;
  issue_id: number | null;
  issue_document_id: number | null;
  issue_number: string | null;
  to_invoice: boolean;
  invoiced: boolean;
  invoiced_at: string | null;
  created_by_name: string;
  created_at: string;
};

export type StockIssue = {
  id: number;
  company_id: number;
  company_name: string;
  facility_id: number | null;
  facility_name: string | null;
  issued_on: string;
  issuer_name: string;
  line_count: number;
  document_id: number | null;
  document_number: string | null;
  document_version: number | null;
  handover: { fullname: string; role_title: string; place: string; signed_on: string } | null;
};

export type MovementPayload = {
  item_id: number;
  action: StockAction;
  from?: HolderRef;
  to?: HolderRef;
  qty: number;
  company_id?: number;
  inspection_id?: number;
  note?: string;
};

/** Backend `code` of the 409 that refuses to delete an item already on a výdajka. */
export const STOCK_ITEM_USED = 'stock_item_used';

export const STOCK_UNITS: StockUnit[] = ['ks', 'bal', 'm'];

/** texty_ui.json → sklad */
export const STOCK_TEXTS = {
  nazov: 'Sklad — materiál a značenie',
  polozky: 'Položky',
  pohyby: 'Pohyby',
  nova_polozka: 'Nová položka',
  nakup: 'Nákup',
  presun: 'Presun',
  pouzite: 'Použité',
  sklad: 'Sklad',
  spolu: 'Spolu',
  pridat_na_fakturu: 'Pridať na faktúru',
  vystavit_vydajku: 'Vystaviť výdajku',
} as const;

export const ACTION_LABELS: Record<StockAction, string> = {
  nakup: STOCK_TEXTS.nakup,
  presun: STOCK_TEXTS.presun,
  pouzite: STOCK_TEXTS.pouzite,
};

function movementQuery(filters: { item_id?: number; company_id?: number; na_fakturu?: boolean; limit?: number }): string {
  const parts: string[] = [];
  if (filters.item_id) parts.push(`item_id=${filters.item_id}`);
  if (filters.company_id) parts.push(`company_id=${filters.company_id}`);
  if (filters.na_fakturu) parts.push('na_fakturu=1');
  if (filters.limit) parts.push(`limit=${filters.limit}`);
  return parts.length > 0 ? `?${parts.join('&')}` : '';
}

export const Stock = {
  overview: () => api<{ holders: StockHolder[]; items: StockItem[] }>('/api/stock'),

  createItem: (body: { name: string; unit: StockUnit }, csrfToken: string | null) =>
    api<{ item: StockItem }>('/api/stock/items', {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),

  updateItem: (id: number, body: { name: string; unit: StockUnit }, csrfToken: string | null) =>
    api<{ item: StockItem }>(`/api/stock/items/${id}`, {
      method: 'PATCH',
      body,
      csrfToken,
      requireOnline: true,
    }),

  /** Plain delete; the server answers 409 `stock_item_used` when the item is already on a výdajka. */
  deleteItem: (id: number, csrfToken: string | null) =>
    api<{ deleted: true }>(`/api/stock/items/${id}`, {
      method: 'DELETE',
      csrfToken,
      requireOnline: true,
    }),

  /** Vyradenie zo skladu: the item leaves the sklad but stays in the journal and on výdajky. */
  retireItem: (id: number, csrfToken: string | null) =>
    api<{ retired: true }>(`/api/stock/items/${id}/retire`, {
      method: 'POST',
      csrfToken,
      requireOnline: true,
    }),

  movements: (filters: { item_id?: number; company_id?: number; na_fakturu?: boolean; limit?: number } = {}) =>
    api<{ items: StockMovement[] }>(`/api/stock/movements${movementQuery(filters)}`),

  record: (body: MovementPayload, csrfToken: string | null) =>
    api<{ movement: StockMovement; item: StockItem }>('/api/stock/movements', {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),

  /** „Pridať na faktúru" / the vyfakturované check-off. Nothing else changes. */
  billing: (id: number, body: { to_invoice?: boolean; invoiced?: boolean }, csrfToken: string | null) =>
    api<{ movement: StockMovement }>(`/api/stock/movements/${id}/billing`, {
      method: 'PATCH',
      body,
      csrfToken,
      requireOnline: true,
    }),

  /** Použitia that can share one výdajka with movement {id}: same client, same day. */
  issuable: (id: number) => api<{ items: StockMovement[] }>(`/api/stock/movements/${id}/issuable`),

  issue: (movementIds: number[], csrfToken: string | null) =>
    api<{ issue: StockIssue; document: { id: number; number: string; download_url: string } }>(
      '/api/stock/issues',
      { method: 'POST', body: { movement_ids: movementIds }, csrfToken, requireOnline: true },
    ),

  issues: (companyId?: number) =>
    api<{ items: StockIssue[] }>(`/api/stock/issues${companyId ? `?company_id=${companyId}` : ''}`),
};

/** „j. n. yyyy" from an ISO date or `Y-m-d H:i:s`. */
export function formatStockDate(value: string): string {
  const [date] = value.split(' ');
  const [y, m, d] = date.split('-').map(Number);
  if (!y || !m || !d) return value;
  return `${d}. ${m}. ${y}`;
}

/** „Sklad → Filip", „→ Sklad", „Filip" (for a použitie). */
export function movementRoute(m: StockMovement): string {
  if (m.from && m.to) return `${m.from.name} → ${m.to.name}`;
  if (m.to) return `→ ${m.to.name}`;
  return m.from?.name ?? '—';
}
