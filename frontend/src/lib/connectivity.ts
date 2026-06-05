/*
 * Connectivity store — a single source of truth for "are we really online?".
 *
 * `navigator.onLine` alone is unreliable: after a reload while offline the
 * freshly loaded document frequently reports `true`, and the `online`/`offline`
 * events only fire on a *transition*, never on initial load. So a page reloaded
 * while offline would wrongly look online and the offline indicator would
 * vanish even though nothing can reach the server.
 *
 * We back `navigator.onLine` with an active probe against the lightweight
 * `/api/health` endpoint and expose a tiny subscribe / refresh API:
 * - `subscribeConnectivity` — React hooks listen for changes.
 * - `probeConnectivity` — actively re-check now (used by "is the connection
 *   back yet?" handlers like the manual sync button) and returns the result.
 */
import { buildUrl } from './api';

const HEALTH_PATH = '/api/health';
const PROBE_TIMEOUT_MS = 4000;
// While offline, re-probe on this cadence so the app recovers on its own once
// the network is back, without the user having to do anything.
const POLL_INTERVAL_MS = 15000;

let online = typeof navigator === 'undefined' ? true : navigator.onLine;
let started = false;
const listeners = new Set<(online: boolean) => void>();

function emit(): void {
  for (const fn of listeners) fn(online);
}

function setOnline(next: boolean): void {
  if (next === online) return;
  online = next;
  emit();
}

/** Last known connectivity. Synchronous — safe for `useState` initialisers. */
export function getConnectivity(): boolean {
  return online;
}

/**
 * Actively check the network by hitting `/api/health`. Updates the shared
 * state and returns the fresh value. The endpoint is auth-free and tiny, so
 * this is cheap to call from a click handler.
 */
export async function probeConnectivity(): Promise<boolean> {
  // A `false` from navigator is reliable; trust it and skip the round-trip.
  if (typeof navigator !== 'undefined' && !navigator.onLine) {
    setOnline(false);
    return false;
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), PROBE_TIMEOUT_MS);
  try {
    const res = await fetch(buildUrl(HEALTH_PATH), {
      method: 'GET',
      cache: 'no-store',
      credentials: 'include',
      signal: controller.signal,
    });
    setOnline(res.ok);
    return res.ok;
  } catch {
    setOnline(false);
    return false;
  } finally {
    clearTimeout(timer);
  }
}

export function subscribeConnectivity(fn: (online: boolean) => void): () => void {
  listeners.add(fn);
  start();
  return () => {
    listeners.delete(fn);
  };
}

function start(): void {
  if (started || typeof window === 'undefined') return;
  started = true;

  // `offline` is reliable — flip immediately. `online` only means "maybe", so
  // confirm it with a probe before we tell the UI we're back.
  window.addEventListener('offline', () => setOnline(false));
  window.addEventListener('online', () => {
    probeConnectivity().catch(() => undefined);
  });

  // Probe right away so a reload-while-offline reflects reality instead of the
  // stale `navigator.onLine === true`.
  probeConnectivity().catch(() => undefined);

  // Keep checking *while offline* so the app recovers automatically. When
  // online we stay quiet and rely on the `offline` event to notice a drop.
  setInterval(() => {
    if (!online) probeConnectivity().catch(() => undefined);
  }, POLL_INTERVAL_MS);
}
