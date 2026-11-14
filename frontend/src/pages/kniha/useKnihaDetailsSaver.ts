import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Inspections, type InspectionDetail } from '@/api/inspections';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { readCache, writeCache } from '@/lib/cache';
import { useToast } from '@/lib/toast';
import { knihaDetailsPayload, type KnihaDetails } from '@/lib/kniha';

/** How long typing has to pause before a text is written. */
const TYPING_PAUSE_MS = 600;

async function syncCacheDetails(id: number, details: KnihaDetails): Promise<void> {
  const path = `/api/inspections/${id}`;
  try {
    const cached = await readCache<InspectionDetail>(path);
    if (cached && cached.inspection) {
      await writeCache(path, {
        ...cached,
        inspection: { ...cached.inspection, details: { ...knihaDetailsPayload(details), ...(details.issued ? { issued: details.issued } : {}) } },
      });
    }
  } catch {
    // Non-blocking cache write
  }
}

/**
 * Keeps the `details` of a požiarna kniha entry and writes them back — the
 * pattern of the prehliadka's useDetailsSaver, for the book's own shape.
 *
 * `PATCH /api/inspections/{id}` replaces the whole header, so every write sends
 * the complete, latest state; writes never overtake each other, changes made
 * while one is in flight are coalesced into one more, and offline the write
 * queues (OfflineQueuedError is not an error here). Ticks and choices are
 * written at once; texts (`typing: true`) after a pause in typing, on
 * {@link commit} (blur) or on {@link flush} (before leaving the screen).
 */
export function useKnihaDetailsSaver(inspectionId: number, initial: KnihaDetails | null) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [details, setDetails] = useState<KnihaDetails | null>(initial);
  const [pending, setPending] = useState(false);
  const latest = useRef<KnihaDetails | null>(initial);
  const running = useRef(false);
  const dirty = useRef(false);
  const inFlight = useRef<Promise<void> | null>(null);
  const timer = useRef<number | null>(null);
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
          await Inspections.update(inspectionId, { details: knihaDetailsPayload(current) }, csrf.current);
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

  const start = useCallback(() => {
    if (timer.current !== null) {
      window.clearTimeout(timer.current);
      timer.current = null;
    }
    // A loop already running picks the change up; keep its promise so flush()
    // awaits the real write instead of an already settled early return.
    if (running.current) {
      dirty.current = true;
      return;
    }
    inFlight.current = run();
  }, [run]);

  /** Applies a change, shows it at once and schedules the write. */
  const update = useCallback(
    (change: (d: KnihaDetails) => KnihaDetails, opts?: { typing?: boolean }) => {
      const base = latest.current;
      if (!base) return;
      const next = change(base);
      latest.current = next;
      setDetails(next);
      void syncCacheDetails(inspectionId, next);
      if (opts?.typing) {
        if (timer.current !== null) window.clearTimeout(timer.current);
        setPending(true);
        timer.current = window.setTimeout(start, TYPING_PAUSE_MS);
      } else {
        start();
      }
    },
    [inspectionId, start],
  );

  /** Writes a text still waiting for the typing pause (a field lost focus). */
  const commit = useCallback(() => {
    if (timer.current !== null) start();
  }, [start]);

  /** Awaits every pending write — before navigating on or generating. */
  const flush = useCallback(async (): Promise<void> => {
    if (timer.current !== null) start();
    while (running.current || dirty.current) {
      await (inFlight.current ?? Promise.resolve());
      // Yield to the task queue so a settled promise can never spin the tab.
      if (running.current || dirty.current) await new Promise((r) => setTimeout(r, 20));
    }
  }, [start]);

  // Leaving the screen mid-typing still writes the text.
  useEffect(
    () => () => {
      if (timer.current !== null) {
        window.clearTimeout(timer.current);
        timer.current = null;
        void run();
      }
    },
    [run],
  );

  return { details, update, commit, flush, pending };
}
