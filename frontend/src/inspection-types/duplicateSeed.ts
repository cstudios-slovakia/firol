/**
 * "Ďalší rovnaký" (duplicate item) support — change request 2.4.2.
 *
 * After Step 2 saves an item with "save-and-next", the parent page refetches
 * the inspection which remounts the form with a fresh key, wiping its state.
 * To carry a handful of fields into the next (blank) item, the form stashes a
 * seed here before that remount and consumes it on the next mount. Keyed by
 * inspection id so two inspections open in parallel don't cross-contaminate.
 *
 * The seed is a plain per-type object; each form decides which fields to carry
 * (typically identification + status, never serial number or measured values).
 */
const seeds = new Map<number, unknown>();

export function setDuplicateSeed(inspectionId: number, seed: unknown): void {
  seeds.set(inspectionId, seed);
}

/** Returns and removes the pending seed for this inspection, if any. */
export function consumeDuplicateSeed<T>(inspectionId: number): T | null {
  const seed = seeds.get(inspectionId);
  seeds.delete(inspectionId);
  return (seed as T) ?? null;
}
