/*
 * Tiny module-level holder for the currently active account id, so layers
 * below React (the api wrapper, Dexie cache, mutation queue) can scope
 * data without subscribing to AuthContext. AuthContext is the single
 * writer via setActiveAccountId().
 *
 * Persisted to localStorage so the value survives a hard reload before
 * /api/me has resolved — the cache layer can still answer offline GETs
 * for the previously active account immediately on app open.
 */

const STORAGE_KEY = 'firol.activeAccountId';

let current: number | null = readInitial();
let csrf: string | null = null;

function readInitial(): number | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? Number(raw) || null : null;
  } catch {
    return null;
  }
}

export function getActiveAccountId(): number | null {
  return current;
}

export function setActiveAccountId(id: number | null): void {
  current = id;
  try {
    if (id == null) {
      localStorage.removeItem(STORAGE_KEY);
    } else {
      localStorage.setItem(STORAGE_KEY, String(id));
    }
  } catch {
    // ignore — private mode etc.
  }
}

export function getCsrfToken(): string | null {
  return csrf;
}

export function setCsrfToken(token: string | null): void {
  csrf = token;
}
