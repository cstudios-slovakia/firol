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
 * Session handling (see SESSION_EXPIRED / CSRF_INVALID below):
 * - 403 `csrf_invalid` — the page's CSRF token went stale (the server
 *   renewed the session from the "remember me" cookie). Recovered silently:
 *   re-sync the token from /api/me and replay the request once.
 * - 401 `session_expired` — the login is really gone. Any in-flight
 *   mutation is parked in the outbox as a draft first, so the technician's
 *   half-written inspection survives, and only then is the app signed out.
 *
 * Throws ApiError on non-2xx so callers can branch on `.status` / `.code`.
 */
import { readCache, writeCache, cachedPathsUnder } from './cache';
import { enqueueMutation, OfflineQueuedError } from './queue';
import { autoOptimistic } from './offlineEntities';
import { getCsrfToken, setCsrfToken } from './session';

const BASE = import.meta.env.VITE_API_BASE_URL ?? '';

/** A read-modify-write against one IDB cache path, applied at enqueue time. */
export type CachePatch = {
  path: string;
  /** Receives the current cached value (or undefined) and returns the next one. */
  apply: (current: unknown) => unknown;
};

/**
 * Describes how a mutation should behave when it's queued offline: which
 * caches to patch so the UI reflects the change immediately, what to hand
 * back to the caller, and — for creates — the temp id to remap on sync.
 */
export type OptimisticSpec = {
  /** Returned to the caller instead of throwing (creates need this for the new id). */
  returns?: unknown;
  /** Cache patches applied synchronously when the request is queued. */
  patches?: CachePatch[];
  /** Present on creates: temp id + dot-path to the real id in the replayed response. */
  create?: { clientId: number; idPath: string };
  /** Friendly label for the pending-changes UI. */
  label?: string;
  /** Concrete name of the record (e.g. company name) shown under the label. */
  detail?: string;
};

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

/** Machine codes the backend attaches to session/CSRF rejections. */
export const SESSION_EXPIRED = 'session_expired';
export const NO_ACCOUNT = 'no_active_account';
export const CSRF_INVALID = 'csrf_invalid';

/** Shown when the session is gone for good and re-login is the only way on. */
export const SESSION_EXPIRED_MESSAGE = 'Prihlásenie vypršalo, prihláste sa znova.';

/** `detail` of the `firol:unauthorized` event. */
export type UnauthorizedDetail = {
  /** Slovak, safe to show as-is. */
  message: string;
  /** True when the interrupted write was parked in the outbox as a draft. */
  draftSaved: boolean;
  /**
   * False when we were never signed in to begin with — a 401 on the first
   * /api/me of a cold start just means "not logged in", and telling a visitor
   * on the login screen that their session expired is nonsense. The app still
   * needs the event to settle into the unauthed state; only the notice is
   * suppressed.
   */
  announce: boolean;
};

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly body: unknown,
    /** Backend `code` field, when present — e.g. `session_expired`. */
    public readonly code: string | null = null,
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
  /** Human-readable label shown in the pending-changes UI. */
  label?: string;
  /**
   * Opt-in offline-create behaviour. When the request fails offline the api
   * seeds the caches described here and resolves with `optimistic.returns`
   * instead of throwing, so the create flow can continue with a temp id.
   */
  optimistic?: OptimisticSpec;
  /**
   * Internal: set on the single replay that follows a CSRF re-sync, so a
   * token the server keeps rejecting can't spin into a retry loop.
   */
  csrfRetry?: boolean;
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
    // Endpoints that genuinely need the server (PDF, billing, …) bail out.
    if (opts.requireOnline) throw networkErr;

    const parked = await parkMutation(method, path, opts);
    if (!parked.parked) throw networkErr;
    if (parked.returns !== undefined) return parked.returns as T;
    throw new OfflineQueuedError(parked.id);
  }

  if (res.status === 204) {
    // Fire-and-forget refresh; the mutation already succeeded server-side.
    if (method !== 'GET') refreshAfterMutation(path).catch(() => undefined);
    return undefined as T;
  }

  const text = await res.text();
  const parsed = text ? safeJson(text) : null;

  if (!res.ok) {
    const message =
      parsed && typeof parsed === 'object' && 'error' in parsed
        ? String((parsed as { error: unknown }).error)
        : `HTTP ${res.status}`;
    const code =
      parsed && typeof parsed === 'object' && 'code' in parsed
        ? String((parsed as { code: unknown }).code)
        : null;

    // Recoverable: the session outlived the token the page is holding (it
    // was renewed from the "remember me" cookie while the tab sat open).
    // Fetch the current token and replay once — the user sees nothing.
    if (res.status === 403 && code === CSRF_INVALID && !opts.csrfRetry) {
      const fresh = await resyncSession();
      if (fresh) {
        return api<T>(path, { ...opts, csrfToken: fresh, csrfRetry: true });
      }
      // /api/me says we're logged out too — fall through to the sign-out path.
      return signOutPreservingWork<T>(method, path, opts, SESSION_EXPIRED_MESSAGE);
    }

    if (isSessionLoss(res.status, code, path)) {
      return signOutPreservingWork<T>(method, path, opts, message || SESSION_EXPIRED_MESSAGE);
    }

    throw new ApiError(res.status, message, parsed, code);
  }

  if (method === 'GET') {
    // Fire-and-forget cache write; failures here shouldn't break the call.
    writeCache(path, parsed).catch(() => undefined);
  } else {
    // Successful mutation: refresh the reads it likely affected so the cache
    // stays in step with the server — without ever leaving an empty hole.
    refreshAfterMutation(path).catch(() => undefined);
  }

  return parsed as T;
}

// --- session recovery ----------------------------------------------------

/**
 * Is this a "your login is gone" answer, as opposed to any other 4xx?
 *
 * The code is authoritative when the backend sends one. The path check is the
 * fallback for a bare 401: without it a wrong password on the login form would
 * read as an expired session and toast "prihláste sa znova" at someone who is
 * already on the login screen.
 */
function isSessionLoss(status: number, code: string | null, path: string): boolean {
  if (code === SESSION_EXPIRED || code === NO_ACCOUNT) return true;
  return status === 401 && !path.startsWith('/api/auth/');
}

let resyncInFlight: Promise<string | null> | null = null;

/**
 * Pull the current CSRF token off /api/me and adopt it.
 *
 * Shared across concurrent callers: a screen that fires several writes at once
 * would otherwise re-sync once per request. Returns null when /api/me itself
 * is unauthenticated — i.e. the session really is gone, not just the token.
 *
 * Exported for the mutation-queue drain, which fetches directly (it needs to
 * replay a serialised request) and so can't reuse api()'s own recovery.
 */
export async function resyncSession(): Promise<string | null> {
  if (resyncInFlight) return resyncInFlight;

  // Cleared inside the chain rather than by each awaiting caller, so a
  // second wave of failures can start its own re-sync the moment this one
  // settles — and can't have its promise cleared out from under it.
  const run: Promise<string | null> = (async (): Promise<string | null> => {
    try {
      const res = await fetch(buildUrl('/api/me'), { credentials: 'include' });
      if (!res.ok) return null;
      const snapshot = (await res.json()) as { csrfToken?: unknown };
      const token = typeof snapshot.csrfToken === 'string' ? snapshot.csrfToken : null;
      if (!token) return null;
      // Update the low-level holder the mutation queue reads, and tell
      // AuthContext to re-read the snapshot — most callers pass the token
      // down from there, so leaving it stale would re-break the next write.
      setCsrfToken(token);
      window.dispatchEvent(new Event('firol:session-refreshed'));
      return token;
    } catch {
      return null; // offline mid-recovery — nothing to adopt
    }
  })().finally(() => {
    resyncInFlight = null;
  });

  resyncInFlight = run;
  return run;
}

/**
 * The session is gone and can't be recovered. Before the app drops to the
 * login screen, park whatever the user was writing in the mutation outbox so
 * it replays once they sign back in (AuthContext drains the queue on every
 * successful auth) — an inspection half-entered in the field must not be lost
 * because the login timed out.
 */
async function signOutPreservingWork<T>(
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
  path: string,
  opts: ApiOptions,
  message: string,
): Promise<T> {
  const parked =
    method === 'GET' || opts.requireOnline
      ? ({ parked: false } as const)
      : await parkMutation(method, path, opts);

  const detail: UnauthorizedDetail = {
    message,
    draftSaved: parked.parked,
    announce: getCsrfToken() !== null,
  };
  window.dispatchEvent(new CustomEvent('firol:unauthorized', { detail }));

  if (parked.parked) {
    if (parked.returns !== undefined) return parked.returns as T;
    throw new OfflineQueuedError(parked.id);
  }
  throw new ApiError(401, message, null, SESSION_EXPIRED);
}

type ParkResult =
  | { parked: false }
  | { parked: true; id: number; returns?: unknown };

/**
 * Append a mutation to the outbox and apply its optimistic cache patches, so
 * the change is visible locally and replays on the next drain.
 *
 * Shared by the two situations where a write can't reach the server right now:
 * the device is offline, and the session expired mid-edit. Returns
 * `{ parked: false }` for requests that can't be replayed later — creating a
 * new top-level entity whose id the caller needs immediately, PDF generation,
 * billing — which the caller then surfaces as a plain error.
 */
async function parkMutation(
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
  path: string,
  opts: ApiOptions,
): Promise<ParkResult> {
  if (method === 'GET') return { parked: false };
  const verb = method as 'POST' | 'PATCH' | 'DELETE';

  // An explicit optimistic spec (top-level create) or an auto-derived one
  // (nested item/trainee write) lets us queue + reflect the change locally.
  const optimistic = opts.optimistic ?? autoOptimistic(verb, path, opts.body);
  if (optimistic) {
    for (const patch of optimistic.patches ?? []) {
      const current = await readCache(patch.path);
      const next = patch.apply(current);
      if (next !== undefined) await writeCache(patch.path, next);
    }
    const id = await enqueueMutation({
      method: verb,
      path,
      body: opts.body,
      label: opts.label ?? optimistic.label ?? `${method} ${path}`,
      detail: optimistic.detail,
      clientId: optimistic.create?.clientId,
      idPath: optimistic.create?.idPath,
    });
    return { parked: true, id, returns: optimistic.returns };
  }

  if (isQueueable(verb, path)) {
    const id = await enqueueMutation({
      method: verb,
      path,
      body: opts.body,
      label: opts.label ?? `${method} ${path}`,
    });
    return { parked: true, id };
  }

  return { parked: false };
}

/**
 * After a successful mutation, refresh the reads it likely affected so the
 * IndexedDB cache reflects the server — WITHOUT deleting anything. We re-fetch
 * the resource's list view(s) and the one entity the mutation touched; a failed
 * re-fetch (e.g. connectivity just dropped) leaves the existing cache entry in
 * place, so the offline fallback is never left empty.
 *
 * Deliberately bounded: only the list root and the single affected entity are
 * refreshed, never every cached detail under the resource — so e.g. editing one
 * inspection doesn't re-fetch twenty unrelated detail pages.
 *
 * Exported so the mutation-queue drain can reuse it after replaying a queued
 * write back online.
 */
export async function refreshAfterMutation(path: string): Promise<void> {
  const pathOnly = path.split('?')[0];
  const root = resourceRoot(pathOnly);
  const idMatch = pathOnly.match(/^\/api\/[^/]+\/(-?\d+)/);
  const entityRoot = idMatch ? `${root}/${idMatch[1]}` : null;

  const cached = await cachedPathsUnder(root);
  const targets = cached.filter((p) => {
    const po = p.split('?')[0];
    if (po === root) return true; // list view(s), incl. ?search=/?filter= variants
    if (entityRoot && (po === entityRoot || po.startsWith(`${entityRoot}/`))) return true;
    return false;
  });

  // Re-fetch via the GET path: success overwrites the cache with fresh data;
  // failure falls back to (and preserves) the existing entry.
  await Promise.all(targets.map((p) => api(p).catch(() => undefined)));
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
  // Nested actions that always need the server, even on an existing draft.
  if (/\/(generate-pdf|repeat)$/i.test(pathOnly)) return false;
  // Ids may be negative (locally-minted temp ids for not-yet-synced parents).
  if (method === 'PATCH' || method === 'DELETE') {
    return /^\/api\/[a-z-]+\/-?\d+(\/.*)?$/i.test(pathOnly);
  }
  // POST is queueable only when nested under an existing resource id.
  return /^\/api\/(inspections|trainings)\/-?\d+\/.+$/i.test(pathOnly);
}

function resourceRoot(path: string): string {
  const pathOnly = path.split('?')[0];
  const m = pathOnly.match(/^(\/api\/[^/]+)/);
  return m ? m[1] : pathOnly;
}
