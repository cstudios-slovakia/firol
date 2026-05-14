/*
 * Tiny helpers that smooth over the offline-queued case so call sites
 * keep their existing "ApiError | unknown" branching intact.
 *
 * `handleOfflineSave(err, toast)` returns true if it consumed the error
 * by showing a "uloží sa keď budeš online" toast — the caller should
 * then proceed as if the save had succeeded. Returns false otherwise,
 * which means the caller's normal error path should run.
 */
import { OfflineQueuedError } from './api';

type ToastLike = { success: (m: string) => void };

const QUEUED_MESSAGE = 'Uloží sa keď budeš online';

export function handleOfflineSave(err: unknown, toast: ToastLike): boolean {
  if (err instanceof OfflineQueuedError) {
    toast.success(QUEUED_MESSAGE);
    return true;
  }
  return false;
}
