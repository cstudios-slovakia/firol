/*
 * IndexedDB schema (via Dexie).
 *
 * Two tables:
 * - `cache`: URL-keyed snapshot of GET responses, scoped by account id.
 *   Used as the offline fallback for `lib/api.ts` GETs.
 * - `mutations`: outbox of POST/PATCH/DELETE calls that failed because
 *   the network was down. Drained by `lib/queue.ts` once we're online.
 *
 * Schema is intentionally minimal — we don't normalize entities, we
 * store raw JSON keyed by request path. The server remains the source
 * of truth for shape and validation.
 */
import Dexie, { type Table } from 'dexie';

export type CacheKey = string; // "<accountId>::<path>"

export interface CacheEntry {
  key: CacheKey;
  accountId: number | null;
  path: string;
  data: unknown;
  fetchedAt: number;
}

export type MutationStatus = 'pending' | 'syncing' | 'failed';

export interface SerializedBlob {
  __blob: true;
  blob: Blob;
  filename: string;
  contentType: string;
}

export interface SerializedFormData {
  __formData: true;
  entries: Array<[string, string | SerializedBlob]>;
}

export interface MutationEntry {
  id?: number;
  accountId: number | null;
  method: 'POST' | 'PATCH' | 'DELETE';
  path: string;
  /** Either a JSON-serialisable body (object/null) or a SerializedFormData envelope. */
  body: unknown;
  contentKind: 'json' | 'formdata' | 'none';
  /** Optional one-line description for the pending-changes UI. */
  label: string;
  createdAt: number;
  attempts: number;
  status: MutationStatus;
  lastError?: string;
  /** HTTP status from the last attempt, when the server responded. */
  lastStatus?: number;
}

class FirolDb extends Dexie {
  cache!: Table<CacheEntry, CacheKey>;
  mutations!: Table<MutationEntry, number>;

  constructor() {
    super('firol');
    this.version(1).stores({
      cache: '&key, accountId, fetchedAt',
      mutations: '++id, status, accountId, createdAt',
    });
  }
}

export const db = new FirolDb();

export function cacheKey(accountId: number | null, path: string): CacheKey {
  return `${accountId ?? 'anon'}::${path}`;
}

export async function clearAccountData(accountId: number): Promise<void> {
  await db.cache.where('accountId').equals(accountId).delete();
  await db.mutations.where('accountId').equals(accountId).delete();
}
