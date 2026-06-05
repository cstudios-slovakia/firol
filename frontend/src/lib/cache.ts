/*
 * Read-cache helpers for offline GETs.
 *
 * The cache is invalidated implicitly: every successful network GET
 * overwrites the entry, so callers always see the freshest data when
 * online. There's no TTL — going offline simply means "show what we
 * last saw" rather than "show nothing".
 */
import { cacheKey, db, type CacheEntry } from './db';
import { getActiveAccountId } from './session';

export async function readCache<T = unknown>(path: string): Promise<T | undefined> {
  const accountId = getActiveAccountId();
  const entry = await db.cache.get(cacheKey(accountId, path));
  return entry ? (entry.data as T) : undefined;
}

export async function writeCache(path: string, data: unknown): Promise<void> {
  const accountId = getActiveAccountId();
  const entry: CacheEntry = {
    key: cacheKey(accountId, path),
    accountId,
    path,
    data,
    fetchedAt: Date.now(),
  };
  await db.cache.put(entry);
}

export async function invalidatePath(path: string): Promise<void> {
  const accountId = getActiveAccountId();
  await db.cache.delete(cacheKey(accountId, path));
}

/**
 * Drop every cache entry whose path starts with the given prefix.
 * Used after a successful mutation to bust the list-view it likely
 * affected, e.g. `/api/inspections` after creating an inspection.
 */
export async function invalidatePrefix(prefix: string): Promise<void> {
  const accountId = getActiveAccountId();
  const keyPrefix = `${accountId ?? 'anon'}::${prefix}`;
  const keysToDelete: string[] = [];
  await db.cache
    .where('key')
    .startsWith(keyPrefix)
    .each((entry) => {
      keysToDelete.push(entry.key);
    });
  if (keysToDelete.length) {
    await db.cache.bulkDelete(keysToDelete);
  }
}

/**
 * Paths of every cached GET whose key falls under `prefix`, for the active
 * account. Used to *refresh* (re-fetch) the reads a mutation likely affected
 * instead of deleting them: deleting would leave a hole that breaks the
 * offline fallback if connectivity drops before the next successful GET.
 */
export async function cachedPathsUnder(prefix: string): Promise<string[]> {
  const accountId = getActiveAccountId();
  const keyPrefix = `${accountId ?? 'anon'}::${prefix}`;
  const paths: string[] = [];
  await db.cache
    .where('key')
    .startsWith(keyPrefix)
    .each((entry) => {
      paths.push(entry.path);
    });
  return paths;
}
