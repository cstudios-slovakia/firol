/*
 * Thin fetch wrapper for the Firol JSON API.
 *
 * - Always sends cookies (`credentials: 'include'`) so the session sticks.
 * - Adds `X-CSRF-Token` to non-GET requests when a token is provided.
 * - Reads VITE_API_BASE_URL — empty in dev (Vite proxy on /api), set to
 *   `/api.php?path=` on prod.
 *
 * Offline behaviour:
 * - GET: on network failure (offline) falls back to the IndexedDB cache;
 *   on success writes the response into the cache.
 * - POST/PATCH/DELETE: when offline AND the path looks "queueable"
 *   (existing-resource paths like `/api/inspections/123/...` or
 *   `/api/trainings/45/...`), the request is appended to the mutation
 *   outbox and an `OfflineQueuedError` is thrown so the caller can show
 *   a "uloží sa keď budeš online" message. Non-queueable mutations
 *   (creating new top-level entities, PDF generation, auth, billing)
 *   rethrow the original network error so the UI shows "vyžaduje pripojenie".
 *
 * Throws ApiError on non-2xx so callers can branch on `.status`.
 */
import { readCache, writeCache, invalidatePrefix } from './cache';
import { enqueueMutation, OfflineQueuedError } from './queue';

const BASE = import.meta.env.VITE_API_BASE_URL ?? '';

// In production VITE_API_BASE_URL is "/api.php?path=" — the path and its
// own query string must be split so PHP receives them as separate $_GET keys.
// e.g. /api/admin/accounts?offset=0 → /api.php?path=/api/admin/accounts&offset=0
export function buildUrl(path: string): string {
  if (!BASE) return path;
  const q = path.indexOf('?');
  if (q === -1) return `${BASE}${path}`;
  return `${BASE}${path.slice(0, q)}&${path.slice(q + 1)}`;
}

export { OfflineQueuedError } from './queue';

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly body: unknown,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export type ApiOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  csrfToken?: string | null;
  /** Force "vyžaduje pripojenie" semantics — never queue this mutation. */
  requireOnline?: boolean;
  /** Skip the read cache for this GET (still writes through on success). */
  bypassCache?: boolean;
  /** Human-readable label shown in the pending-changes UI. */
  label?: string;
};

export async function api<T = unknown>(path: string, opts: ApiOptions = {}): Promise<T> {
  const method = opts.method ?? 'GET';
  const headers: Record<string, string> = {};

  let body: BodyInit | undefined;
  if (opts.body !== undefined) {
    if (opts.body instanceof FormData) {
      // Let the browser set Content-Type with the multipart boundary —
      // setting it manually here would clobber the boundary.
      body = opts.body;
    } else {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.body);
    }
  }
  if (method !== 'GET' && opts.csrfToken) {
    headers['X-CSRF-Token'] = opts.csrfToken;
  }

  let res: Response;
  try {
    res = await fetch(buildUrl(path), {
      method,
      headers,
      body,
      credentials: 'include',
    });
  } catch (networkErr) {
    // The fetch itself rejected — DNS failed, offline, CORS, etc.
    if (method === 'GET') {
      const cached = await readCache<T>(path);
      if (cached !== undefined) return cached;
      throw networkErr;
    }
    if (!opts.requireOnline && isQueueable(method, path)) {
      const id = await enqueueMutation({
        method,
        path,
        body: opts.body,
        label: opts.label ?? `${method} ${path}`,
      });
      throw new OfflineQueuedError(id);
    }
    throw networkErr;
  }

  if (res.status === 204) {
    if (method !== 'GET') await invalidatePrefix(resourceRoot(path));
    return undefined as T;
  }

  const text = await res.text();
  const parsed = text ? safeJson(text) : null;

  if (!res.ok) {
    const message =
      parsed && typeof parsed === 'object' && 'error' in parsed
        ? String((parsed as { error: unknown }).error)
        : `HTTP ${res.status}`;
    throw new ApiError(res.status, message, parsed);
  }

  if (method === 'GET') {
    // Fire-and-forget cache write; failures here shouldn't break the call.
    writeCache(path, parsed).catch(() => undefined);
  } else {
    // Successful mutation likely invalidates the list view at the resource root.
    invalidatePrefix(resourceRoot(path)).catch(() => undefined);
  }

  // For cached GETs the caller may want to render stale data first when
  // offline; the bypassCache hint just disables the inbound cache lookup
  // path that runs *before* fetch. The lookup actually happens above only
  // on network failure, so this hint is a no-op today but documented for
  // future SWR-style behaviour.
  void opts.bypassCache;

  return parsed as T;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

/**
 * Heuristic for "this mutation targets an existing server-issued resource",
 * meaning the path is stable and the server doesn't need to mint a new id
 * before we can sync. Matches:
 *   PATCH/DELETE on any /api/<resource>/<numeric-id>(/...)?
 *   POST on /api/(inspections|trainings)/<id>/<child>(/...)?
 *
 * Creating a new top-level entity (POST /api/inspections, etc.) does NOT
 * match — the caller needs the freshly-minted id to continue the flow.
 */
function isQueueable(method: 'POST' | 'PATCH' | 'DELETE', path: string): boolean {
  const pathOnly = path.split('?')[0];
  if (method === 'PATCH' || method === 'DELETE') {
    return /^\/api\/[a-z-]+\/\d+(\/.*)?$/i.test(pathOnly);
  }
  // POST is queueable only when nested under an existing resource id.
  return /^\/api\/(inspections|trainings)\/\d+\/.+$/i.test(pathOnly);
}

function resourceRoot(path: string): string {
  const pathOnly = path.split('?')[0];
  const m = pathOnly.match(/^(\/api\/[^/]+)/);
  return m ? m[1] : pathOnly;
}
