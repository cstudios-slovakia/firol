/**
 * "Ďalší rovnaký" (duplicate item) support — change request 2.4.2.
 *
 * After Step 2 saves an item with "save-and-next", the parent page refetches
 * the inspection which remounts the form with a fresh key, wiping its state.
 * To carry a handful of fields into the next (blank) item, the form stashes a
 * seed here before that remount and reads it on the next mount. Keyed by
 * inspection id so two inspections open in parallel don't cross-contaminate.
 *
 * The seed is a plain per-type object; each form decides which fields to carry
 * (typically identification + status, never serial number or measured values).
 *
 * Reading is deliberately non-destructive: the forms apply the seed from an
 * effect, and React StrictMode runs effects twice on mount in development — a
 * read-once-and-delete seed was consumed by the first pass and came back empty
 * on the second, which blanked the freshly prefilled form. Ownership of the
 * lifetime therefore sits with the writers: a form clears the seed when it
 * saves without duplicating, and the Step 3 summary clears it on mount —
 * getting there means the technician left the item-entry flow.
 */
const seeds = new Map<number, unknown>();

export function setDuplicateSeed(inspectionId: number, seed: unknown): void {
  seeds.set(inspectionId, seed);
}

/** Returns the pending seed for this inspection without discarding it. */
export function peekDuplicateSeed<T>(inspectionId: number): T | null {
  return (seeds.get(inspectionId) as T) ?? null;
}

/** Drops the pending seed so the next blank form starts empty. */
export function clearDuplicateSeed(inspectionId: number): void {
  seeds.delete(inspectionId);
}
