/*
 * Shared save path for every inspection-type form.
 *
 * Saving an item and attaching its photos (change request 2.2) has one subtle
 * case that is easy to get wrong per-type: offline. The item write is absorbed
 * by the mutation outbox and rejects with `OfflineQueuedError` instead of
 * returning the new record, so a create has no server id to hang photos off —
 * only the temp id minted for the queued create. Posting the photos against
 * that temp id is correct, because the outbox rewrites the path once the item
 * itself syncs.
 *
 * Keeping that in one place means the eight per-type forms only decide what
 * their fields are, not how offline id remapping works.
 */
import { Inspections } from '@/api/inspections';
import { OfflineQueuedError } from '@/lib/api';
import { queuedClientId } from '@/lib/offline';
import type { PhotoStaging } from '@/components/ItemPhotos';

/** The per-type field payload, as accepted by the items API. */
export type ItemFields = Parameters<typeof Inspections.addItem>[1];

export type SaveItemResult = {
  /** True when the write (or a photo write) went to the outbox instead of the server. */
  queued: boolean;
  /** Photos that could not be stored — the item itself is saved regardless. */
  photosFailed: number;
};

export async function saveItemWithPhotos(args: {
  inspectionId: number;
  /** null → create a new item; a number → update that item. */
  itemId: number | null;
  fields: ItemFields;
  csrfToken: string | null;
  /**
   * One staging per item (every module except Požiarna kniha), or one per
   * nedostatok when photos are scoped to individual defects.
   */
  photos: PhotoStaging | PhotoStaging[];
}): Promise<SaveItemResult> {
  const { inspectionId, itemId, fields, csrfToken } = args;
  const photosList = Array.isArray(args.photos) ? args.photos : [args.photos];

  let queued = false;
  let targetId = itemId;

  try {
    if (itemId !== null) {
      await Inspections.updateItem(inspectionId, itemId, fields, csrfToken);
    } else {
      const res = await Inspections.addItem(inspectionId, fields, csrfToken);
      targetId = res.item.id;
    }
  } catch (err) {
    // A genuine failure (validation, server error) belongs to the caller.
    if (!(err instanceof OfflineQueuedError)) throw err;
    queued = true;
    // Edits already know their id; creates have to read the temp id back off
    // the queued mutation.
    targetId = itemId ?? (await queuedClientId(err));
  }

  let photosFailed = 0;
  if (targetId !== null) {
    for (const photos of photosList) {
      const outcome = await photos.commit(inspectionId, targetId, csrfToken);
      if (outcome.queued > 0) queued = true;
      photosFailed += outcome.failed;
    }
  } else {
    // Only reachable if the outbox entry vanished between enqueue and read —
    // report it rather than silently dropping the technician's photos.
    photosFailed = photosList.reduce((sum, p) => sum + (p.total > 0 ? p.staged.length : 0), 0);
  }

  return { queued, photosFailed };
}

/**
 * The toast to show after a save. Mirrors the wording the offline helper uses
 * for plain queued saves so the two paths read the same to the technician.
 */
export function saveItemMessage(result: SaveItemResult, savedLabel = 'Položka uložená'): string {
  if (result.photosFailed > 0) {
    return `${savedLabel} — ${result.photosFailed} fotiek sa nepodarilo uložiť.`;
  }
  return result.queued ? 'Uloží sa keď budeš online' : savedLabel;
}
