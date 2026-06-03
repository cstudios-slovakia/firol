/*
 * Client-minted temporary ids for entities created while offline.
 *
 * The whole app types entity ids as `number` and routes via Number(param),
 * so temp ids must stay numeric. We use *negative* integers: server ids are
 * always positive, so the sign cleanly partitions "not yet synced" from
 * "persisted". A given temp id lives only until its create mutation replays,
 * at which point queue.ts remaps it to the real server id everywhere.
 *
 * The counter is persisted to localStorage and seeded from the current time
 * so ids stay unique across reloads and never collide with a value still
 * sitting in the outbox from a previous session.
 */

const STORAGE_KEY = 'firol.tempIdSeq';

function readSeq(): number {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const n = raw ? Number(raw) : NaN;
    if (Number.isFinite(n) && n < 0) return n;
  } catch {
    // ignore — private mode etc.
  }
  // Seed from the clock (negated) so a fresh device still starts well clear
  // of any positive server id and of older sessions.
  return -Date.now();
}

let seq = readSeq();

/** Returns a fresh, unique negative integer id. */
export function mintTempId(): number {
  seq -= 1;
  try {
    localStorage.setItem(STORAGE_KEY, String(seq));
  } catch {
    // ignore
  }
  return seq;
}

/** True when `id` was minted locally and has not been synced yet. */
export function isTempId(id: number): boolean {
  return typeof id === 'number' && id < 0;
}
