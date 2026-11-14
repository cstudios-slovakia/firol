import { mutationMatchesEntity } from '@/lib/pendingEntity';
import { drainQueue, listMutations } from '@/lib/queue';

/** How long a screen waits for the outbox before it loads anyway. */
const MAX_WAIT_MS = 10_000;

async function hasQueued(id: number): Promise<boolean> {
  return (await listMutations()).some(
    (m) => m.status !== 'failed' && mutationMatchesEntity(m, { resource: 'inspections', id }),
  );
}

/**
 * Resolves once no write for the entry waits in the outbox — call it before a
 * book screen loads the entry from the server.
 *
 * `PATCH /api/inspections/{id}` replaces the whole header. An entry created
 * offline is remapped (and its screen re-opened under the real id) as soon as
 * its create syncs, while its queued PATCHes still wait; loading then would
 * show the create-time header and the next edit would write it back over the
 * offline work. Offline, or when the outbox cannot get through, it resolves at
 * once — the read then falls back to the cache, which holds the offline state.
 */
export async function entrySynced(id: number): Promise<void> {
  const deadline = Date.now() + MAX_WAIT_MS;
  while (Date.now() < deadline) {
    if (typeof navigator !== 'undefined' && !navigator.onLine) return;
    if (!(await hasQueued(id))) return;
    const res = await drainQueue().catch(() => null);
    if (!res || res.networkError) return;
    if (!(await hasQueued(id))) return;
    // Another drain is running (or a write waits on a blocked create) — look again shortly.
    await new Promise((r) => setTimeout(r, 300));
  }
}
