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
 *
 * The DELETE answers with the mode it actually applied; the toast uses that,
 * never the possibly stale `discard` the screen had cached.
 */
import { Inspections, type DiscardMode, type InspectionStatus } from '@/api/inspections';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { offlineMessage } from '@/lib/offline';
import { KNIHA_TYPE } from '@/lib/kniha';

export type { DiscardMode };

export const DISCARD_OFFLINE_MESSAGE =
  'Natrvalo zmazať rozpracovanú kontrolu sa dá len s pripojením na internet.';

/** The type of a preventive inspection (PrehliadkaDetails::TYPE). */
const PREHLIADKA_TYPE = 'preventivna_prehliadka';

/**
 * The discard a row offers. A row cached before the server sent `discard`:
 * an issued úkon archives as it always did; a draft offers nothing until the
 * list is read again online.
 */
export function discardModeOf(it: { status: InspectionStatus; discard?: DiscardMode | null }): DiscardMode | null {
  if (it.discard !== undefined) return it.discard;
  return it.status === 'finalized' ? 'archive' : null;
}

/** The sentence every dialog of a permanent delete ends with. */
export const PERMANENT_SENTENCE = 'Túto akciu nemožno vrátiť späť.';

type DialogText = { title: string; description: string; confirmLabel: string };

/** „Zahodiť prehliadku" — on the prehliadka itself and from the lists. */
export const DISCARD_PREHLIADKA_DIALOG: DialogText = {
  title: 'Zahodiť rozrobenú prehliadku?',
  description: `Prehliadka sa natrvalo zmaže aj so zapísanými nedostatkami a fotkami. Nevznikne z nej žiadny záznam. ${PERMANENT_SENTENCE}`,
  confirmLabel: 'Natrvalo zmazať',
};

/** „Zahodiť zápis" — a draft book entry, on its summary and from the lists. */
export const DISCARD_BOOK_ENTRY_DIALOG: DialogText = {
  title: 'Zahodiť rozpracovaný zápis?',
  description: `Zápis sa natrvalo zmaže aj so zapísanými nedostatkami a fotkami. ${PERMANENT_SENTENCE}`,
  confirmLabel: 'Natrvalo zmazať',
};

/** The confirm dialog of each mode — the list screens and the facility page. */
export const DISCARD_DIALOG: Record<DiscardMode, DialogText> = {
  delete: {
    title: 'Zahodiť rozpracovanú kontrolu?',
    description: `Kontrola sa natrvalo zmaže aj so všetkými položkami a fotkami. ${PERMANENT_SENTENCE}`,
    confirmLabel: 'Natrvalo zmazať',
  },
  archive: {
    title: 'Archivovať kontrolu?',
    description: 'Kontrola sa archivuje a zmizne zo zoznamov. Vystavený protokol sa nezmaže.',
    confirmLabel: 'Archivovať',
  },
};

/**
 * The dialog for a discard from a list: a draft prehliadka or book entry gets
 * its own text, everything else the generic one of its mode.
 */
export function discardDialog(mode: DiscardMode, type: string): DialogText {
  if (mode === 'delete' && type === PREHLIADKA_TYPE) return DISCARD_PREHLIADKA_DIALOG;
  if (mode === 'delete' && type === KNIHA_TYPE) return DISCARD_BOOK_ENTRY_DIALOG;
  return DISCARD_DIALOG[mode];
}

/** What a discard ended in: the mode the server applied, or queued offline. */
export type DiscardOutcome = DiscardMode | 'queued';

/**
 * Run the discard. Resolves with the mode the server applied, or `queued`
 * when an archive went to the offline queue. Throws on a refusal or, for a
 * delete, when offline ({@link DISCARD_OFFLINE_MESSAGE}).
 */
export async function discardUkon(
  id: number,
  mode: DiscardMode,
  csrfToken: string | null,
): Promise<DiscardOutcome> {
  const permanent = mode === 'delete';
  if (permanent && typeof navigator !== 'undefined' && !navigator.onLine) {
    throw new Error(DISCARD_OFFLINE_MESSAGE);
  }
  try {
    const res = await Inspections.archive(id, csrfToken, { requireOnline: permanent });
    // An older server answered 204 without a body: fall back to the asked mode.
    return res?.mode === 'delete' || res?.mode === 'archive' ? res.mode : mode;
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

/** The toast after a discard, from what the server actually did. */
export function discardDoneMessage(outcome: DiscardOutcome): string {
  if (outcome === 'queued') return 'Archivovanie sa odošle po pripojení.';
  return outcome === 'delete' ? 'Kontrola natrvalo zmazaná' : 'Kontrola archivovaná';
}
