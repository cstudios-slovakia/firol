/*
 * Maps queued mutations to the entity they touch, so a detail screen can tell
 * whether "this record" is still waiting to sync. Used by PendingSyncBanner.
 */
import type { MutationEntry } from './db';

export type EntityRef = {
  /** Resource segment as it appears in the API path, e.g. `companies`. */
  resource: string;
  /** The entity id (may be a negative temp id for an offline create). */
  id: number;
};

/**
 * True when `m` creates, edits, deletes or writes a child of the given entity:
 * - the create mutation (matched by `clientId`, since its own path may live
 *   under a parent — e.g. a facility POSTs to `/api/companies/{cid}/facilities`),
 * - any path under `/api/{resource}/{id}` (edits, deletes, nested items/trainees).
 */
export function mutationMatchesEntity(m: MutationEntry, ref: EntityRef): boolean {
  if (m.clientId === ref.id) return true;
  const pathOnly = m.path.split('?')[0];
  const prefix = `/api/${ref.resource}/${ref.id}`;
  return pathOnly === prefix || pathOnly.startsWith(`${prefix}/`);
}

export function entityPendingMutations(
  mutations: MutationEntry[],
  ref: EntityRef,
): MutationEntry[] {
  return mutations.filter((m) => mutationMatchesEntity(m, ref));
}
