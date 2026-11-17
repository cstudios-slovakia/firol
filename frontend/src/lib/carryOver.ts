/**
 * Carrying items over from the last protocol (block 1 / chapter 12) — what the
 * client needs to know about it. The rules themselves live on the server
 * (`Firol\Support\CarryOver`).
 *
 * The fire-protection types carry every field of the last protocol of the same
 * kind at the prevádzka: identification, measured values, the result / stav
 * and the texts (fix list of 16. 11. 2026, spec 12.2). The technician changes
 * only what changed. Every other type carries identification only.
 */
import type { InspectionType } from '@/api/inspections';
import type { ChecklistOrigin } from '@/api/prehliadka';
import { formatDateSk } from '@/lib/clientNoticeEmail';

const FULL: ReadonlySet<string> = new Set([
  'php',
  'hydranty',
  'ts_hadic',
  'pu_akcieschopnost',
  'pu_udrzba',
  'nudzove_osvetlenie',
]);

/** True when this type's items arrive with last protocol's values and result. */
export function carriesValues(type: InspectionType | string): boolean {
  return FULL.has(type);
}

/** „prevzaté z protokolu č. … zo dňa …" — the number is missing on old data without a document. */
export function originText(origin: ChecklistOrigin): string {
  const date = origin.executed_on ? ` zo dňa ${formatDateSk(origin.executed_on)}` : '';
  return origin.number ? `prevzaté z protokolu č. ${origin.number}${date}` : `prevzaté z kontroly${date}`;
}

/**
 * Where a draft's values came from — `details.carried_from` and
 * `details.carried_positions`, written by Opakovať, „Prevziať položky", a
 * concept from a prehliadka and the follow-ups (the checklist after a
 * prehliadka, the ts_hadic button). Null when the draft records no origin.
 */
export function carriedOriginOf(
  details: Record<string, unknown> | null | undefined,
): { origin: ChecklistOrigin; positions: Set<number> } | null {
  const raw = details?.carried_from;
  if (!raw || typeof raw !== 'object') return null;
  const o = raw as Record<string, unknown>;
  const origin: ChecklistOrigin = {
    number: typeof o.number === 'string' ? o.number : null,
    executed_on: typeof o.executed_on === 'string' ? o.executed_on : null,
  };
  const list = Array.isArray(details?.carried_positions) ? details.carried_positions : [];
  const positions = new Set(list.filter((p): p is number => typeof p === 'number'));
  return positions.size > 0 ? { origin, positions } : null;
}
