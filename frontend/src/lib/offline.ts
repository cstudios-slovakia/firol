/*
 * Tiny helpers that smooth over the offline-queued case so call sites
 * keep their existing "ApiError | unknown" branching intact.
 *
 * `handleOfflineSave(err, toast)` returns true if it consumed the error
 * by showing a "uloží sa keď budeš online" toast — the caller should
 * then proceed as if the save had succeeded. Returns false otherwise,
 * which means the caller's normal error path should run.
 */
import { ApiError, OfflineQueuedError } from './api';

type ToastLike = { success: (m: string) => void };

const QUEUED_MESSAGE = 'Uloží sa keď budeš online';
const OFFLINE_MESSAGE = 'Táto akcia vyžaduje pripojenie na internet.';

export function handleOfflineSave(err: unknown, toast: ToastLike): boolean {
  if (err instanceof OfflineQueuedError) {
    toast.success(QUEUED_MESSAGE);
    return true;
  }
  return false;
}

/**
 * Picks the right user-facing message for a failed online-only action.
 * - ApiError → the server's own message (meaningful, e.g. validation).
 * - any other error while offline → a clear "needs connection" message,
 *   since the fetch most likely rejected because the device is offline.
 * - otherwise → the caller's domain-specific fallback (server down, etc.).
 */
export function offlineMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError) return err.message;
  if (typeof navigator !== 'undefined' && !navigator.onLine) return OFFLINE_MESSAGE;
  return fallback;
}
