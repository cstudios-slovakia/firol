/**
 * „Zistené nedostatky" editor — the generic nedostatok block every úkon can
 * carry (block 2 / chapter 7: popis *, opatrenie, termín odstránenia, fotky).
 *
 * ─── USAGE (inside a Step 2 form) ────────────────────────────────────────────
 *
 *   const defects = useDefects(initialItem);          // seeds from item.fields.defects
 *
 *   <DefectsEditor
 *     state={defects}
 *     initialPhotos={initialItem?.photos}
 *     required={result === 'zistene_nedostatky'}     // optional: at least one row
 *   />
 *
 *   // on submit
 *   const collected = defects.collect({ requireOne: result === 'zistene_nedostatky' });
 *   if (!collected) return;                          // error is shown in the editor
 *   const saved = await saveItemWithPhotos({
 *     inspectionId, itemId, csrfToken,
 *     fields: { ...yourFields, defects: collected.defects },
 *     photos: [...yourItemPhotos, ...collected.photos],
 *   });
 *   collected.discardRemovedPhotos(inspectionId, savedItemId, csrfToken); // best effort
 *
 * Storage is `fields.defects` on the item (see backend Firol\Support\Defects,
 * which validates it again server-side). Each nedostatok's photos are ordinary
 * item photos tagged with the nedostatok's `key` (`defect_key`), so they go
 * through the same offline outbox as every other photo and are captioned
 * „Nedostatok č. N — popis" in the PDF appendix automatically.
 *
 * Nedostatky never carry over to the next úkon (chapter 12) — seed from the
 * item being edited, never from a previous inspection.
 * ─────────────────────────────────────────────────────────────────────────────
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { AlertTriangle, Calendar, Plus, X } from 'lucide-react';
import { Inspections, type InspectionItem, type InspectionPhoto } from '@/api/inspections';
import { ItemPhotoField, usePhotoStaging, type PhotoStaging } from '@/components/ItemPhotos';
import { Input } from '@/components/ui/Input';
import { cn } from '@/lib/cn';

/** One nedostatok as stored and sent to the API. */
export type DefectPayload = {
  key: string;
  description: string;
  measure: string | null;
  deadline: string | null;
};

type DefectRow = {
  key: string;
  description: string;
  measure: string;
  deadline: string;
};

export type DefectsCollected = {
  defects: DefectPayload[];
  /** Photo stagings of the kept nedostatky — pass to saveItemWithPhotos. */
  photos: PhotoStaging[];
  /** Deletes already-uploaded photos of nedostatky that were removed. Call after the save. */
  discardRemovedPhotos: (inspectionId: number, itemId: number | null, csrfToken: string | null) => void;
};

export type DefectsState = {
  rows: DefectRow[];
  error: string | null;
  add: () => void;
  remove: (key: string) => void;
  update: (key: string, patch: Partial<Omit<DefectRow, 'key'>>) => void;
  /** Removes every row (e.g. the result was switched to „bez nedostatkov"). */
  clear: () => void;
  /** Validates; returns null (and shows the error) when something required is missing. */
  collect: (opts?: { requireOne?: boolean }) => DefectsCollected | null;
  /** Internal — lets each row's photo field register its staging. */
  registerPhotos: (key: string, staging: PhotoStaging) => void;
};

/** Stable id a nedostatok's photos attach to. */
export function newDefectKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function rowsFrom(item: InspectionItem | null): DefectRow[] {
  const raw = item?.fields?.defects;
  if (!Array.isArray(raw)) return [];
  return raw.flatMap((d): DefectRow[] => {
    if (!d || typeof d !== 'object') return [];
    const r = d as Record<string, unknown>;
    const description = typeof r.description === 'string' ? r.description : '';
    if (!description) return [];
    return [{
      key: typeof r.key === 'string' && r.key ? r.key : newDefectKey(),
      description,
      measure: typeof r.measure === 'string' ? r.measure : '',
      deadline: typeof r.deadline === 'string' ? r.deadline : '',
    }];
  });
}

export function useDefects(initialItem: InspectionItem | null): DefectsState {
  const [rows, setRows] = useState<DefectRow[]>(() => rowsFrom(initialItem));
  const [error, setError] = useState<string | null>(null);
  const stagings = useRef<Map<string, PhotoStaging>>(new Map());
  // Keys of the nedostatky that belong to the item being edited (loaded or
  // added since) — only their photos may be discarded as „removed".
  const ownKeys = useRef<Set<string>>(new Set(rows.map((r) => r.key)));

  // Step 2 reuses one mounted form across items — re-seed on a different item.
  // Only on a real change of item — on mount the rows are already seeded.
  // The staging map is never cleared: the rows' photo fields register into it
  // from their own effects, and a row that stays mounted would not register
  // again.
  const seedKey = initialItem ? `${initialItem.id}:${initialItem.updated_at}` : 'new';
  const seededFor = useRef(seedKey);
  useEffect(() => {
    if (seededFor.current === seedKey) return;
    seededFor.current = seedKey;
    const next = rowsFrom(initialItem);
    ownKeys.current = new Set(next.map((r) => r.key));
    setRows(next);
    setError(null);
  }, [seedKey, initialItem]);

  const add = useCallback(() => {
    const key = newDefectKey();
    ownKeys.current.add(key);
    setRows((prev) => [...prev, { key, description: '', measure: '', deadline: '' }]);
    setError(null);
  }, []);
  const remove = useCallback((key: string) => {
    setRows((prev) => prev.filter((r) => r.key !== key));
  }, []);
  const update = useCallback((key: string, patch: Partial<Omit<DefectRow, 'key'>>) => {
    setRows((prev) => prev.map((r) => (r.key === key ? { ...r, ...patch } : r)));
    setError(null);
  }, []);
  const clear = useCallback(() => {
    setRows([]);
    setError(null);
  }, []);
  const registerPhotos = useCallback((key: string, staging: PhotoStaging) => {
    stagings.current.set(key, staging);
  }, []);

  const collect = useCallback((opts?: { requireOne?: boolean }): DefectsCollected | null => {
    // A row with nothing typed in it is the empty row „Pridať nedostatok"
    // added — not a nedostatok. One with an opatrenie, a termín or a photo but
    // no popis is a mistake the technician has to fix: popis is required.
    const touched = rows.filter((r) =>
      r.description.trim() || r.measure.trim() || r.deadline
      || (stagings.current.get(r.key)?.total ?? 0) > 0);
    const missing = rows.findIndex((r) => touched.includes(r) && !r.description.trim());
    if (missing >= 0) {
      setError(`Nedostatok č. ${missing + 1} nemá popis — popis je povinný.`);
      return null;
    }
    if (opts?.requireOne && touched.length === 0) {
      setError('Pridaj aspoň jeden nedostatok s popisom.');
      return null;
    }
    setError(null);

    const keptKeys = new Set(touched.map((r) => r.key));
    const removedPhotos: InspectionPhoto[] = [];
    for (const [key, staging] of stagings.current) {
      if (ownKeys.current.has(key) && !keptKeys.has(key)) removedPhotos.push(...staging.existing.filter((p) => !p.pending));
    }

    return {
      defects: touched.map((r) => ({
        key: r.key,
        description: r.description.trim(),
        measure: r.measure.trim() || null,
        deadline: r.deadline || null,
      })),
      photos: touched
        .map((r) => stagings.current.get(r.key))
        .filter((s): s is PhotoStaging => !!s),
      discardRemovedPhotos: (inspectionId, itemId, csrfToken) => {
        if (itemId === null || itemId < 0) return;
        for (const photo of removedPhotos) {
          Inspections.deleteItemPhoto(inspectionId, itemId, photo.id, csrfToken).catch(() => {});
        }
      },
    };
  }, [rows]);

  return { rows, error, add, remove, update, clear, collect, registerPhotos };
}

export function DefectsEditor({
  state,
  initialPhotos,
  title = 'Zistené nedostatky',
  required = false,
  disabled = false,
  hint = 'Popis je povinný. Opatrenie, termín odstránenia a fotky sú voliteľné.',
}: {
  state: DefectsState;
  initialPhotos: InspectionPhoto[] | undefined;
  title?: string;
  /** Marks the section as required (at least one nedostatok) — visual only; enforce with collect({ requireOne }). */
  required?: boolean;
  disabled?: boolean;
  hint?: string;
}) {
  return (
    <section className="flex flex-col gap-2" aria-label={title}>
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-ink-500">
          {title}
          {required && <span className="ml-1 text-firol-500">*</span>}
        </h3>
        {state.rows.length > 0 && (
          <span className="text-xs text-ink-400">{state.rows.length}</span>
        )}
      </div>

      {state.rows.map((row, idx) => (
        <div key={row.key} className="rounded-2xl border border-ink-200 bg-white p-3 transition-all duration-300">
          <div className="mb-2 flex items-center justify-between gap-2">
            <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-status-bad">
              <AlertTriangle className="size-3.5" />
              Nedostatok č. {idx + 1}
            </span>
            {!disabled && (
              <button
                type="button"
                onClick={() => state.remove(row.key)}
                aria-label={`Odstrániť nedostatok č. ${idx + 1}`}
                className="grid size-10 place-items-center rounded-xl text-ink-400 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad active:scale-95"
              >
                <X className="size-4" />
              </button>
            )}
          </div>

          <label htmlFor={`defect-desc-${row.key}`} className="mb-1 block text-xs font-medium text-ink-500">
            Popis nedostatku <span className="text-firol-500">*</span>
          </label>
          <textarea
            id={`defect-desc-${row.key}`}
            rows={2}
            disabled={disabled}
            value={row.description}
            onChange={(e) => state.update(row.key, { description: e.target.value })}
            placeholder="Napr. Úniková cesta v hale čiastočne zastavaná paletami."
            className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
          />

          <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-start">
            <div className="min-w-0 flex-1">
              <label htmlFor={`defect-measure-${row.key}`} className="mb-1 block text-xs font-medium text-ink-500">
                Navrhované opatrenie
              </label>
              <textarea
                id={`defect-measure-${row.key}`}
                rows={2}
                disabled={disabled}
                value={row.measure}
                onChange={(e) => state.update(row.key, { measure: e.target.value })}
                placeholder="Napr. Uvoľniť a označiť plochu zákazu skladovania."
                className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
              />
            </div>
            <div className="shrink-0 sm:w-44">
              <label htmlFor={`defect-deadline-${row.key}`} className="mb-1 block text-xs font-medium text-ink-500">
                Termín odstránenia
              </label>
              <Input
                id={`defect-deadline-${row.key}`}
                type="date"
                disabled={disabled}
                leftIcon={<Calendar className="size-4" />}
                value={row.deadline}
                onChange={(e) => state.update(row.key, { deadline: e.target.value })}
              />
            </div>
          </div>

          <div className="mt-3 rounded-xl border border-firol-200 bg-firol-50/50 p-3">
            <DefectPhotos
              defectKey={row.key}
              initialPhotos={initialPhotos}
              disabled={disabled}
              onRegister={state.registerPhotos}
            />
          </div>
        </div>
      ))}

      {!disabled && (
        <button
          type="button"
          onClick={state.add}
          className="flex min-h-12 items-center justify-center gap-1.5 rounded-2xl border border-dashed border-ink-300 px-3 py-3 text-sm font-medium text-ink-600 transition-all duration-300 hover:border-firol-400 hover:text-firol-600 active:scale-[0.99]"
        >
          <Plus className="size-4" />
          Pridať nedostatok
        </button>
      )}

      {(state.error || (hint && state.rows.length > 0)) && (
        <p className={cn('text-xs', state.error ? 'text-status-bad' : 'text-ink-400')}>
          {state.error ?? hint}
        </p>
      )}
    </section>
  );
}

/**
 * Owns the photo staging of one nedostatok. `usePhotoStaging` can't live in
 * the hook above because the number of rows — and so of stagings — changes.
 */
function DefectPhotos({
  defectKey,
  initialPhotos,
  disabled,
  onRegister,
}: {
  defectKey: string;
  initialPhotos: InspectionPhoto[] | undefined;
  disabled: boolean;
  onRegister: (key: string, staging: PhotoStaging) => void;
}) {
  const photos = usePhotoStaging(initialPhotos, defectKey);
  useEffect(() => {
    onRegister(defectKey, photos);
  }, [defectKey, photos, onRegister]);
  return (
    <ItemPhotoField
      photos={photos}
      disabled={disabled}
      helpText="Fotky sa pripoja k tomuto nedostatku v prílohe protokolu, s jeho číslom."
    />
  );
}

/** Count of stored nedostatky on an item — for list rows and stats. */
export function defectCount(fields: Record<string, unknown> | undefined): number {
  const raw = fields?.defects;
  return Array.isArray(raw)
    ? raw.filter((d) => d && typeof d === 'object' && typeof (d as Record<string, unknown>).description === 'string').length
    : 0;
}
