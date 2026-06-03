/*
 * Mutation outbox.
 *
 * When a POST/PATCH/DELETE fails because the device is offline, the
 * api wrapper calls `enqueueMutation()` and resolves the call with an
 * `OfflineQueuedError` so the UI can show a friendly "uloží sa keď
 * budeš online" toast instead of a generic error.
 *
 * `drainQueue()` is invoked on app start and on every `online` event.
 * It walks pending mutations serially (FIFO) and replays them against
 * the network. Auth failures abort the drain — we'll try again after
 * the user re-authenticates.
 *
 * Failed mutations (4xx other than 401) stay in the table with status
 * `'failed'` and a human-readable error, ready for manual retry from
 * the PendingChanges UI.
 */
import { db, cacheKey, type MutationEntry, type SerializedFormData, type SerializedBlob } from './db';
import { getActiveAccountId, getCsrfToken } from './session';
import { invalidatePrefix } from './cache';

const BASE = import.meta.env.VITE_API_BASE_URL ?? '';

export class OfflineQueuedError extends Error {
  constructor(public readonly mutationId: number) {
    super('offline-queued');
    this.name = 'OfflineQueuedError';
  }
}

export type EnqueueInput = {
  method: 'POST' | 'PATCH' | 'DELETE';
  path: string;
  body: unknown;
  label: string;
  /** Temp id this create resolves — drives id remapping on sync. */
  clientId?: number;
  /** Dot-path to the real id in the server response, e.g. `inspection.id`. */
  idPath?: string;
};

export async function enqueueMutation(input: EnqueueInput): Promise<number> {
  const { method, path, body, label, clientId, idPath } = input;
  let serialised: unknown;
  let kind: MutationEntry['contentKind'];
  if (body === undefined) {
    serialised = null;
    kind = 'none';
  } else if (body instanceof FormData) {
    serialised = await serialiseFormData(body);
    kind = 'formdata';
  } else {
    serialised = body;
    kind = 'json';
  }
  const id = await db.mutations.add({
    accountId: getActiveAccountId(),
    method,
    path,
    body: serialised,
    contentKind: kind,
    label,
    createdAt: Date.now(),
    attempts: 0,
    status: 'pending',
    clientId,
    idPath,
  });
  return id as number;
}

let draining = false;

export async function drainQueue(): Promise<void> {
  if (draining) return;
  if (typeof navigator !== 'undefined' && !navigator.onLine) return;
  draining = true;
  try {
    while (true) {
      const next = await db.mutations
        .where('status')
        .equals('pending')
        .sortBy('createdAt');
      const mutation = next[0];
      if (!mutation) break;
      const stop = await replay(mutation);
      if (stop) break;
    }
  } finally {
    draining = false;
  }
}

/** Returns true to abort the drain (e.g. 401 — wait for re-auth). */
async function replay(m: MutationEntry): Promise<boolean> {
  await db.mutations.update(m.id!, { status: 'syncing', attempts: m.attempts + 1 });
  try {
    const headers: Record<string, string> = {};
    let body: BodyInit | undefined;
    if (m.contentKind === 'json' && m.body !== null) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(m.body);
    } else if (m.contentKind === 'formdata') {
      body = deserialiseFormData(m.body as SerializedFormData);
    }
    const csrf = getCsrfToken();
    if (csrf) headers['X-CSRF-Token'] = csrf;

    const res = await fetch(`${BASE}${m.path}`, {
      method: m.method,
      headers,
      body,
      credentials: 'include',
    });

    if (res.status === 401) {
      // Session lost — leave the mutation pending and stop. We'll try
      // again when the user logs back in and AuthContext re-runs drain.
      await db.mutations.update(m.id!, { status: 'pending', lastStatus: 401 });
      return true;
    }

    if (!res.ok) {
      const text = await res.text().catch(() => '');
      const parsed = safeJson(text);
      const message =
        parsed && typeof parsed === 'object' && parsed && 'error' in parsed
          ? String((parsed as { error: unknown }).error)
          : `HTTP ${res.status}`;
      // 4xx → permanent failure, parked for user retry/discard.
      // 5xx → also park (likely server bug; auto-retry won't help).
      await db.mutations.update(m.id!, {
        status: 'failed',
        lastStatus: res.status,
        lastError: message,
      });
      return false;
    }

    // Success — if this was a create, swap the temp id for the real one
    // across the rest of the outbox and the cache before dropping it.
    if (m.clientId != null && m.idPath) {
      const text = await res.text().catch(() => '');
      const parsed = safeJson(text);
      const realId = readPath(parsed, m.idPath);
      if (typeof realId === 'number') {
        await db.mutations.delete(m.id!);
        await remapId(m.clientId, realId, m.accountId);
        await invalidatePrefix(resourceRoot(m.path));
        return false;
      }
    }
    // Drop from queue and bust any list caches under the same resource root,
    // so the next GET picks up the server's view.
    await db.mutations.delete(m.id!);
    await invalidatePrefix(resourceRoot(m.path));
    return false;
  } catch (err) {
    // Network error — leave pending, stop draining to wait for next online tick.
    await db.mutations.update(m.id!, {
      status: 'pending',
      lastError: err instanceof Error ? err.message : String(err),
    });
    return true;
  }
}

export async function retryMutation(id: number): Promise<void> {
  await db.mutations.update(id, { status: 'pending', lastError: undefined, lastStatus: undefined });
  await drainQueue();
}

export async function discardMutation(id: number): Promise<void> {
  await db.mutations.delete(id);
}

export async function listMutations(): Promise<MutationEntry[]> {
  const accountId = getActiveAccountId();
  return db.mutations.where('accountId').equals(accountId ?? -1).sortBy('createdAt');
}

// --- helpers -------------------------------------------------------------

async function serialiseFormData(fd: FormData): Promise<SerializedFormData> {
  const entries: SerializedFormData['entries'] = [];
  for (const [key, value] of fd.entries()) {
    if (typeof value === 'string') {
      entries.push([key, value]);
    } else {
      // File / Blob — copy into a plain Blob so IndexedDB can persist it.
      const blob: SerializedBlob = {
        __blob: true,
        blob: value,
        filename: 'name' in value ? value.name : 'blob',
        contentType: value.type || 'application/octet-stream',
      };
      entries.push([key, blob]);
    }
  }
  return { __formData: true, entries };
}

function deserialiseFormData(s: SerializedFormData): FormData {
  const fd = new FormData();
  for (const [key, value] of s.entries) {
    if (typeof value === 'string') {
      fd.append(key, value);
    } else {
      fd.append(key, new File([value.blob], value.filename, { type: value.contentType }));
    }
  }
  return fd;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/**
 * Derive the "resource root" path used to invalidate list caches after
 * a successful mutation. /api/inspections/123/items → /api/inspections.
 */
function resourceRoot(path: string): string {
  const m = path.match(/^(\/api\/[^/?]+)/);
  return m ? m[1] : path;
}

// --- temp-id remapping ---------------------------------------------------

/** Read a dot-path (`inspection.id`) out of a parsed JSON response. */
function readPath(obj: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((acc, key) => {
    if (acc && typeof acc === 'object') return (acc as Record<string, unknown>)[key];
    return undefined;
  }, obj);
}

/** Replace a `/{tempId}` path segment with `/{realId}` (segment-exact). */
function replaceIdSegment(path: string, tempId: number, realId: number): string {
  // tempId is negative, e.g. -7 → match "/-7" only when a "/" or end follows,
  // so "/-70" isn't clobbered.
  const re = new RegExp(`/${tempId}(?=/|$|\\?)`, 'g');
  return path.replace(re, `/${realId}`);
}

/** Deep-clone `value`, replacing every number equal to `tempId` with `realId`. */
function deepReplaceNumber(
  value: unknown,
  tempId: number,
  realId: number,
): { value: unknown; replaced: boolean } {
  if (typeof value === 'number') {
    return value === tempId ? { value: realId, replaced: true } : { value, replaced: false };
  }
  if (Array.isArray(value)) {
    let replaced = false;
    const next = value.map((v) => {
      const r = deepReplaceNumber(v, tempId, realId);
      replaced = replaced || r.replaced;
      return r.value;
    });
    return { value: replaced ? next : value, replaced };
  }
  if (value && typeof value === 'object') {
    let replaced = false;
    const next: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      const r = deepReplaceNumber(v, tempId, realId);
      replaced = replaced || r.replaced;
      next[k] = r.value;
    }
    return { value: replaced ? next : value, replaced };
  }
  return { value, replaced: false };
}

/**
 * After a create syncs, swap its temp id for the server-issued real id
 * everywhere it's referenced: the rest of the outbox (paths + JSON bodies),
 * the IDB cache (keys + stored data), then tell the UI so an open temp-id
 * route can redirect.
 */
async function remapId(tempId: number, realId: number, accountId: number | null): Promise<void> {
  // 1. Outbox: any other queued mutation that targets this parent. Temp ids
  //    are globally unique, so a global sweep is safe across accounts.
  const all = await db.mutations.toArray();
  for (const mu of all) {
    if (mu.id == null) continue;
    const patch: Partial<MutationEntry> = {};
    const newPath = replaceIdSegment(mu.path, tempId, realId);
    if (newPath !== mu.path) patch.path = newPath;
    if (mu.contentKind === 'json') {
      const r = deepReplaceNumber(mu.body, tempId, realId);
      if (r.replaced) patch.body = r.value;
    }
    if (Object.keys(patch).length > 0) await db.mutations.update(mu.id, patch);
  }

  // 2. Cache: re-key entries under the new id and rewrite stored ids. Scoped
  //    to the account that owns the entity (drain isn't account-bound).
  await remapCache(tempId, realId, accountId);

  // 3. UI: let an open detail/step page jump to the real id.
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('firol:remap', { detail: { tempId, realId } }));
  }
}

async function remapCache(
  tempId: number,
  realId: number,
  accountId: number | null,
): Promise<void> {
  const entries = await db.cache.toArray();
  for (const entry of entries) {
    if (entry.accountId !== accountId) continue;
    const newPath = replaceIdSegment(entry.path, tempId, realId);
    const { value: newData, replaced } = deepReplaceNumber(entry.data, tempId, realId);
    if (newPath !== entry.path) {
      // Move to the real-id key; the old temp key is now dead.
      await db.cache.delete(entry.key);
      const newKey = cacheKey(accountId, newPath);
      await db.cache.put({ ...entry, key: newKey, path: newPath, data: newData });
    } else if (replaced) {
      await db.cache.put({ ...entry, data: newData });
    }
  }
}
