/**
 * „Zahodiť" / the trash icon of an úkon (owner decision of 17. 11. 2026).
 *
 * The server decides per úkon (`discard` on list rows and on show()):
 *   - `delete`  — a draft that was never issued: deleted for good with its
 *                 items and photos. Online only — a permanent delete never
 *                 waits in the offline queue.
 *   - `archive` — an issued úkon: hidden from the lists, the protocol stays.
 *                 May be queued offline as before.
 *   - null      — refused (issued before, something was made from it): the
 *                 screen offers no button.
 */
import { Inspections, type DiscardMode, type InspectionStatus } from '@/api/inspections';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { offlineMessage } from '@/lib/offline';

export type { DiscardMode };

export const DISCARD_OFFLINE_MESSAGE =
  'Natrvalo zmazať rozpracovanú kontrolu sa dá len s pripojením na internet.';

/**
 * The discard a row offers. A row cached before the server sent `discard`:
 * an issued úkon archives as it always did; a draft offers nothing until the
 * list is read again online.
 */
export function discardModeOf(it: { status: InspectionStatus; discard?: DiscardMode | null }): DiscardMode | null {
  if (it.discard !== undefined) return it.discard;
  return it.status === 'finalized' ? 'archive' : null;
}

/** The confirm dialog of each mode — the list screens and the facility page. */
export const DISCARD_DIALOG: Record<DiscardMode, { title: string; description: string; confirmLabel: string }> = {
  delete: {
    title: 'Zahodiť rozpracovanú kontrolu?',
    description: 'Kontrola sa natrvalo zmaže aj so všetkými položkami a fotkami. Túto akciu nemožno vrátiť späť.',
    confirmLabel: 'Natrvalo zmazať',
  },
  archive: {
    title: 'Archivovať kontrolu?',
    description: 'Kontrola sa archivuje a zmizne zo zoznamov. Vystavený protokol sa nezmaže.',
    confirmLabel: 'Archivovať',
  },
};

/** The sentence every dialog of a permanent delete ends with. */
export const PERMANENT_SENTENCE = 'Túto akciu nemožno vrátiť späť.';

/**
 * Run the discard. Resolves `done` when the server answered, `queued` when an
 * archive went to the offline queue. Throws on a refusal or, for a delete,
 * when offline ({@link DISCARD_OFFLINE_MESSAGE}).
 */
export async function discardUkon(
  id: number,
  mode: DiscardMode,
  csrfToken: string | null,
): Promise<'done' | 'queued'> {
  const permanent = mode === 'delete';
  if (permanent && typeof navigator !== 'undefined' && !navigator.onLine) {
    throw new Error(DISCARD_OFFLINE_MESSAGE);
  }
  try {
    await Inspections.archive(id, csrfToken, { requireOnline: permanent });
    return 'done';
  } catch (err) {
    if (err instanceof OfflineQueuedError) return 'queued';
    // requireOnline: a network failure is thrown instead of queued.
    if (permanent && !(err instanceof ApiError)) throw new Error(DISCARD_OFFLINE_MESSAGE);
    throw err;
  }
}

/** The message for a failed discard. */
export function discardErrorMessage(err: unknown, mode: DiscardMode): string {
  if (err instanceof Error && err.message === DISCARD_OFFLINE_MESSAGE) return err.message;
  return offlineMessage(err, mode === 'delete' ? 'Kontrolu sa nepodarilo zmazať.' : 'Kontrolu sa nepodarilo archivovať.');
}

/** The toast after a discard. */
export function discardDoneMessage(mode: DiscardMode, outcome: 'done' | 'queued'): string {
  if (outcome === 'queued') return 'Archivovanie sa odošle po pripojení.';
  return mode === 'delete' ? 'Kontrola natrvalo zmazaná' : 'Kontrola archivovaná';
}
