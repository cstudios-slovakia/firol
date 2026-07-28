import { useCallback, useEffect, useRef, useState } from 'react';
import { Camera, CloudUpload, ImagePlus, Loader2, X } from 'lucide-react';
import { Inspections, photoSrc, type InspectionPhoto } from '@/api/inspections';
import { resizeForUpload } from '@/lib/imageResize';
import { OfflineQueuedError } from '@/lib/api';
import { cn } from '@/lib/cn';

/**
 * Photo documentation for one inspection item (change request 2.2).
 *
 * Photos are staged locally while the technician fills the form and are only
 * sent once the item itself has an id — which is why this is a hook plus a
 * presentational block rather than a self-contained widget. A new item has no
 * id to attach to yet, and the technician should be able to shoot the photo at
 * the same moment they are standing in front of the device.
 *
 * Deletions of already-uploaded photos are deferred the same way, so backing
 * out of an edit without saving leaves the protocol untouched.
 */

/** Server-side cap, mirrored here so the UI can stop before the round trip. */
export const MAX_PHOTOS_PER_ITEM = 20;

export type StagedPhoto = {
  /** Stable local key — staged photos have no server id yet. */
  key: string;
  blob: Blob;
  previewUrl: string;
};

export type PhotoStaging = {
  existing: InspectionPhoto[];
  staged: StagedPhoto[];
  /** Photos that will exist after commit — drives the counter and the cap. */
  total: number;
  processing: boolean;
  error: string | null;
  addFiles: (files: FileList | File[]) => Promise<void>;
  removeStaged: (key: string) => void;
  removeExisting: (id: number) => void;
  /**
   * Apply the staged changes against a saved item. Uploads run sequentially:
   * a phone on mobile data handles one request at a time far more reliably
   * than six in parallel, and it keeps the outbox order meaningful offline.
   *
   * Never throws — the item itself is already saved at this point, and losing
   * that save because a photo failed would be the worse outcome. Returns what
   * actually happened so the caller can tell the technician.
   */
  commit: (
    inspectionId: number,
    itemId: number,
    csrfToken: string | null,
  ) => Promise<{ uploaded: number; queued: number; failed: number }>;
};

export function usePhotoStaging(initialPhotos: InspectionPhoto[] | undefined): PhotoStaging {
  const [existing, setExisting] = useState<InspectionPhoto[]>(initialPhotos ?? []);
  const [removedIds, setRemovedIds] = useState<number[]>([]);
  const [staged, setStaged] = useState<StagedPhoto[]>([]);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Re-seed when the parent form switches to a different item (Step 2 reuses
  // one mounted form for "save and next").
  const initialKey = (initialPhotos ?? []).map((p) => p.id).join(',');
  useEffect(() => {
    setExisting(initialPhotos ?? []);
    setRemovedIds([]);
    setStaged((prev) => {
      prev.forEach((p) => URL.revokeObjectURL(p.previewUrl));
      return [];
    });
    setError(null);
    // initialKey collapses the array identity to its ids — the parent rebuilds
    // the array on every render, so depending on it directly would loop.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialKey]);

  // Release the last previews when the form unmounts.
  const stagedRef = useRef(staged);
  stagedRef.current = staged;
  useEffect(
    () => () => {
      stagedRef.current.forEach((p) => URL.revokeObjectURL(p.previewUrl));
    },
    [],
  );

  const kept = existing.filter((p) => !removedIds.includes(p.id));
  const total = kept.length + staged.length;

  const addFiles = useCallback(
    async (files: FileList | File[]) => {
      const list = Array.from(files).filter((f) => f.type.startsWith('image/'));
      if (list.length === 0) return;

      setProcessing(true);
      setError(null);
      const accepted: StagedPhoto[] = [];
      let rejectedForCap = 0;
      let failed = 0;

      for (const file of list) {
        // Recomputed per file so a multi-select stops exactly at the cap.
        if (kept.length + staged.length + accepted.length >= MAX_PHOTOS_PER_ITEM) {
          rejectedForCap += 1;
          continue;
        }
        try {
          const resized = await resizeForUpload(file);
          accepted.push({
            key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
            blob: resized.blob,
            previewUrl: resized.previewUrl,
          });
        } catch {
          failed += 1;
        }
      }

      if (accepted.length > 0) setStaged((prev) => [...prev, ...accepted]);
      if (rejectedForCap > 0) {
        setError(`K položke je možné pripojiť najviac ${MAX_PHOTOS_PER_ITEM} fotiek.`);
      } else if (failed > 0) {
        setError(
          failed === list.length
            ? 'Fotku sa nepodarilo načítať.'
            : `${failed} z ${list.length} fotiek sa nepodarilo načítať.`,
        );
      }
      setProcessing(false);
    },
    [kept.length, staged.length],
  );

  const removeStaged = useCallback((key: string) => {
    setStaged((prev) => {
      const hit = prev.find((p) => p.key === key);
      if (hit) URL.revokeObjectURL(hit.previewUrl);
      return prev.filter((p) => p.key !== key);
    });
    setError(null);
  }, []);

  const removeExisting = useCallback((id: number) => {
    setRemovedIds((prev) => (prev.includes(id) ? prev : [...prev, id]));
    setError(null);
  }, []);

  const commit = useCallback(
    async (inspectionId: number, itemId: number, csrfToken: string | null) => {
      let uploaded = 0;
      let queued = 0;
      let failed = 0;

      for (const id of removedIds) {
        try {
          await Inspections.deleteItemPhoto(inspectionId, itemId, id, csrfToken);
        } catch (err) {
          if (err instanceof OfflineQueuedError) queued += 1;
          else failed += 1;
        }
      }

      for (const photo of staged) {
        try {
          await Inspections.addItemPhoto(inspectionId, itemId, photo.blob, csrfToken);
          uploaded += 1;
        } catch (err) {
          if (err instanceof OfflineQueuedError) queued += 1;
          else failed += 1;
        }
      }

      return { uploaded, queued, failed };
    },
    [removedIds, staged],
  );

  return {
    existing: kept,
    staged,
    total,
    processing,
    error,
    addFiles,
    removeStaged,
    removeExisting,
    commit,
  };
}

type ItemPhotoFieldProps = {
  photos: PhotoStaging;
  /** Locked protocols show the photos read-only. */
  disabled?: boolean;
};

export function ItemPhotoField({ photos, disabled = false }: ItemPhotoFieldProps) {
  const cameraRef = useRef<HTMLInputElement>(null);
  const galleryRef = useRef<HTMLInputElement>(null);
  const atCap = photos.total >= MAX_PHOTOS_PER_ITEM;

  async function handlePicked(e: React.ChangeEvent<HTMLInputElement>) {
    const files = e.target.files;
    if (files && files.length > 0) await photos.addFiles(files);
    // Reset so picking the same file twice in a row still fires onChange.
    e.target.value = '';
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">
          Fotodokumentácia
        </span>
        <span className="text-xs text-ink-400 tabular-nums">
          {photos.total} / {MAX_PHOTOS_PER_ITEM}
        </span>
      </div>

      {(photos.existing.length > 0 || photos.staged.length > 0) && (
        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
          {photos.existing.map((p) =>
            p.pending ? (
              // Still in the outbox — there is no server copy to show or delete
              // yet. It uploads on its own once the device is back online.
              <li
                key={`e${p.id}`}
                className="grid aspect-square place-items-center gap-1 rounded-xl border border-dashed border-ink-300 bg-ink-50 text-ink-400"
              >
                <CloudUpload className="size-5" />
                <span className="text-[10px]">čaká</span>
              </li>
            ) : (
              <PhotoTile
                key={`e${p.id}`}
                src={photoSrc(p, 'thumb')}
                href={photoSrc(p, 'full')}
                onRemove={disabled ? undefined : () => photos.removeExisting(p.id)}
              />
            ),
          )}
          {photos.staged.map((p) => (
            <PhotoTile
              key={p.key}
              src={p.previewUrl}
              href={p.previewUrl}
              isNew
              onRemove={disabled ? undefined : () => photos.removeStaged(p.key)}
            />
          ))}
        </ul>
      )}

      {!disabled && (
        <div className="flex flex-wrap gap-2">
          {/* `capture` opens the camera straight away on mobile; on desktop the
              attribute is ignored and the file picker opens instead. */}
          <input
            ref={cameraRef}
            type="file"
            accept="image/*"
            capture="environment"
            className="sr-only"
            onChange={handlePicked}
          />
          <input
            ref={galleryRef}
            type="file"
            accept="image/*"
            multiple
            className="sr-only"
            onChange={handlePicked}
          />
          <PhotoButton
            onClick={() => cameraRef.current?.click()}
            disabled={atCap || photos.processing}
            icon={photos.processing ? <Loader2 className="size-4 animate-spin" /> : <Camera className="size-4" />}
            label="Odfotiť"
          />
          <PhotoButton
            onClick={() => galleryRef.current?.click()}
            disabled={atCap || photos.processing}
            icon={<ImagePlus className="size-4" />}
            label="Z galérie"
          />
        </div>
      )}

      {photos.error ? (
        <p className="text-xs text-status-bad">{photos.error}</p>
      ) : (
        !disabled && (
          <p className="text-xs text-ink-400">
            Fotky sa pripoja ako samostatná príloha na konci protokolu. Pred odoslaním sa
            automaticky zmenšia.
          </p>
        )
      )}
    </div>
  );
}

/**
 * Read-only thumbnail strip shown under an item on the summary screen, so the
 * technician can see at a glance which items carry photo documentation.
 * Editing and deleting happen in the item form, which is one tap away via
 * "Opraviť" — keeping the summary row uncluttered on a phone.
 */
export function ItemPhotoStrip({ photos }: { photos: InspectionPhoto[] | undefined }) {
  if (!photos || photos.length === 0) return null;
  return (
    <ul className="flex flex-wrap items-center gap-1.5 px-4 pb-3">
      {photos.map((p) =>
        p.pending ? (
          // Queued offline: the blob lives in the outbox, not on the server, so
          // there is nothing to fetch yet. Say so rather than showing a broken
          // image and letting the technician think the photo was lost.
          <li
            key={p.id}
            title="Čaká na odoslanie"
            className="grid size-12 place-items-center rounded-lg border border-dashed border-ink-300 bg-ink-50 text-ink-400"
          >
            <CloudUpload className="size-4" />
          </li>
        ) : (
          <li key={p.id}>
            <a
              href={photoSrc(p, 'full')}
              target="_blank"
              rel="noreferrer"
              className="block size-12 overflow-hidden rounded-lg border border-ink-200 bg-ink-50 transition-transform duration-200 hover:scale-105"
            >
              <img src={photoSrc(p, 'thumb')} alt="" loading="lazy" className="size-full object-cover" />
            </a>
          </li>
        ),
      )}
    </ul>
  );
}

function PhotoButton({
  onClick,
  disabled,
  icon,
  label,
}: {
  onClick: () => void;
  disabled: boolean;
  icon: React.ReactNode;
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'inline-flex items-center gap-2 rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm font-medium text-ink-700',
        'transition-all duration-200 hover:border-firol-300 hover:bg-firol-50 hover:text-firol-700',
        'active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50',
      )}
    >
      {icon}
      {label}
    </button>
  );
}

function PhotoTile({
  src,
  href,
  isNew = false,
  onRemove,
}: {
  src: string;
  href: string;
  isNew?: boolean;
  onRemove?: () => void;
}) {
  return (
    <li className="group relative aspect-square overflow-hidden rounded-xl border border-ink-200 bg-ink-50">
      <a href={href} target="_blank" rel="noreferrer" className="block size-full">
        <img
          src={src}
          alt=""
          loading="lazy"
          className="size-full object-cover transition-transform duration-300 group-hover:scale-105"
        />
      </a>
      {isNew && (
        <span className="pointer-events-none absolute bottom-1 left-1 rounded-md bg-black/55 px-1.5 py-0.5 text-[10px] font-medium text-white">
          nová
        </span>
      )}
      {onRemove && (
        <button
          type="button"
          onClick={onRemove}
          aria-label="Odstrániť fotku"
          className={cn(
            'absolute right-1 top-1 grid size-6 place-items-center rounded-lg bg-black/55 text-white',
            'transition-colors duration-200 hover:bg-[var(--color-status-bad)]',
          )}
        >
          <X className="size-3.5" />
        </button>
      )}
    </li>
  );
}
