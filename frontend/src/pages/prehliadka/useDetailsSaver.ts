import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Inspections, type InspectionDetail } from '@/api/inspections';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { readCache, writeCache } from '@/lib/cache';
import { useToast } from '@/lib/toast';
import { detailsPayload, type PrehliadkaDetails } from '@/lib/prehliadka';

async function syncCacheDetails(id: number, details: PrehliadkaDetails): Promise<void> {
  const path = `/api/inspections/${id}`;
  try {
    const cached = await readCache<InspectionDetail>(path);
    if (cached && cached.inspection) {
      await writeCache(path, {
        ...cached,
        inspection: {
          ...cached.inspection,
          details: detailsPayload(details),
        },
      });
    }
  } catch {
    // Non-blocking cache write
  }
}

/**
 * Keeps the `details` of a prehliadka (ticked activities, answers about the
 * previous defects, term overrides, present persons …) and writes them back.
 *
 * `PATCH /api/inspections/{id}` replaces the whole object, so every write sends
 * the complete, latest state. Changes made while a write is in flight are
 * coalesced into one more write that follows it — the requests never overtake
 * each other, and the screen never waits for the network: it shows the new
 * state at once. Offline, the write queues like any other (OfflineQueuedError
 * is not an error here).
 */
export function useDetailsSaver(inspectionId: number, initial: PrehliadkaDetails | null) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [details, setDetails] = useState<PrehliadkaDetails | null>(initial);
  const [pending, setPending] = useState(false);
  const latest = useRef<PrehliadkaDetails | null>(initial);
  const running = useRef(false);
  const dirty = useRef(false);
  const inFlightPromise = useRef<Promise<void> | null>(null);
  const csrf = useRef(csrfToken);
  csrf.current = csrfToken;

  // The first non-null value seeds the state; later changes come from update().
  useEffect(() => {
    if (initial && latest.current === null) {
      latest.current = initial;
      setDetails(initial);
    }
  }, [initial]);

  const run = useCallback(async (): Promise<void> => {
    if (running.current) {
      dirty.current = true;
      return;
    }
    running.current = true;
    setPending(true);
    try {
      do {
        dirty.current = false;
        const current = latest.current;
        if (!current) break;
        try {
          await Inspections.update(inspectionId, { details: detailsPayload(current) }, csrf.current);
        } catch (err) {
          if (err instanceof OfflineQueuedError) continue;
          toast.error(err instanceof ApiError ? err.message : 'Zmenu sa nepodarilo uložiť.');
        }
      } while (dirty.current);
    } finally {
      running.current = false;
      setPending(false);
    }
  }, [inspectionId, toast]);

  /** Applies a change to the latest state, shows it at once and schedules the write. */
  const update = useCallback(
    (change: (d: PrehliadkaDetails) => PrehliadkaDetails) => {
      const base = latest.current;
      if (!base) return;
      const next = change(base);
      latest.current = next;
      setDetails(next);
      void syncCacheDetails(inspectionId, next);
      inFlightPromise.current = run();
    },
    [inspectionId, run],
  );

  /** Awaits any in-flight or dirty writes before navigating to the next step. */
  const flush = useCallback(async (): Promise<void> => {
    while (running.current || dirty.current) {
      if (inFlightPromise.current) {
        await inFlightPromise.current;
      } else {
        await new Promise((r) => setTimeout(r, 20));
      }
    }
  }, []);

  return { details, update, pending, flush };
}
