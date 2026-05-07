/*
 * Thin fetch wrapper for the Firol JSON API.
 *
 * - Always sends cookies (`credentials: 'include'`) so the session sticks.
 * - Adds `X-CSRF-Token` to non-GET requests when a token is provided.
 * - Reads VITE_API_BASE_URL — empty in dev (Vite proxy on /api), set to
 *   `/api.php?path=` on prod.
 *
 * Throws ApiError on non-2xx so callers can branch on `.status`.
 */

const BASE = import.meta.env.VITE_API_BASE_URL ?? '';

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

  const res = await fetch(`${BASE}${path}`, {
    method,
    headers,
    body,
    credentials: 'include',
  });

  if (res.status === 204) {
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

  return parsed as T;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
