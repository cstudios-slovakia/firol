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
import { db, type MutationEntry, type SerializedFormData, type SerializedBlob } from './db';
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
};

export async function enqueueMutation(input: EnqueueInput): Promise<number> {
  const { method, path, body, label } = input;
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

    // Success — drop from queue and bust any list caches under the same
    // resource root, so the next GET picks up the server's view.
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
