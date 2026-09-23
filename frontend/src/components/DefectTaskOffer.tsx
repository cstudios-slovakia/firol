/**
 * Chapter 20 / 4.4 — when a nedostatok with a termín odstránenia is saved, the
 * app offers a task „Overiť odstránenie nedostatku — {popis}" due that day.
 *
 * ─── How it is wired ────────────────────────────────────────────────────────
 *
 * Every Step 2 form saves through `saveItemWithPhotos` (inspection-types/
 * saveItem.ts), which calls {@link offerDefectTasks} after the item is stored
 * or queued. That covers the shared „Zistené nedostatky" block
 * (DefectsEditor) and the Požiarna kniha's own defect rows alike, since both
 * store `fields.defects[] = { key, description, deadline }`.
 *
 * The dialog itself lives in {@link DefectTaskOfferHost}, mounted once in the
 * app shell, so it survives the form navigating on to the next item or the
 * summary right after the save.
 *
 * Each nedostatok is offered once. Accepted and declined ones are remembered
 * on the device by their key (the same key their photos carry); nedostatky
 * that already have a task on the server are skipped too, so another device
 * — or a reinstall — doesn't ask again for one that was accepted. The server
 * refuses a second task for the same nedostatok regardless.
 * ─────────────────────────────────────────────────────────────────────────────
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { Check, ListTodo } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Tasks } from '@/api/tasks';
import { OfflineQueuedError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Dialog } from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';

const OFFER_EVENT = 'firol:defect-task-offer';
const STORAGE_KEY = 'firol.defectTaskOffers';
/** Remembered decisions kept per device — oldest dropped beyond this. */
const MAX_REMEMBERED = 2000;

type Offer = {
  inspectionId: number;
  key: string;
  description: string;
  deadline: string;
};

type Decision = 'accepted' | 'declined';

function readDecisions(): Record<string, Decision> {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : null;
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, Decision>) : {};
  } catch {
    return {};
  }
}

function remember(keys: string[], decision: Decision) {
  if (keys.length === 0) return;
  try {
    const all = readDecisions();
    for (const key of keys) {
      delete all[key]; // re-insert so it counts as the newest
      all[key] = decision;
    }
    const entries = Object.entries(all);
    const kept = entries.length > MAX_REMEMBERED ? entries.slice(-MAX_REMEMBERED) : entries;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.fromEntries(kept)));
  } catch {
    // Private mode / full storage — the server-side check still prevents duplicates.
  }
}

/** „2026-08-26" → „26. 8. 2026" */
function formatDay(iso: string): string {
  const [y, m, d] = iso.split('-').map(Number);
  return `${d}. ${m}. ${y}`;
}

/** Task text — texty_ui.json → ulohy.ponuka_z_nedostatku, the quoted part. */
export function defectTaskText(description: string): string {
  return `Overiť odstránenie nedostatku — ${description}`;
}

/** The offer sentence — texty_ui.json → ulohy.ponuka_z_nedostatku, verbatim. */
function offerSentence(offer: Offer): string {
  return `Vytvoriť úlohu „${defectTaskText(offer.description)}“ s termínom ${formatDay(offer.deadline)}?`;
}

/**
 * Call after an item carrying `fields.defects` was saved (or queued). Offers a
 * task for each nedostatok with a termín that hasn't been offered before.
 */
export function offerDefectTasks(inspectionId: number, fields: Record<string, unknown>): void {
  const raw = fields.defects;
  if (!Array.isArray(raw)) return; // hydranty keeps a plain-text `defects`
  const decided = readDecisions();
  const offers: Offer[] = [];
  for (const row of raw) {
    if (!row || typeof row !== 'object') continue;
    const r = row as Record<string, unknown>;
    const key = typeof r.key === 'string' ? r.key : '';
    const description = typeof r.description === 'string' ? r.description.trim() : '';
    const deadline = typeof r.deadline === 'string' ? r.deadline : '';
    if (!key || !description || !/^\d{4}-\d{2}-\d{2}$/.test(deadline) || decided[key]) continue;
    offers.push({ inspectionId, key, description, deadline });
  }
  if (offers.length === 0) return;
  window.dispatchEvent(new CustomEvent<Offer[]>(OFFER_EVENT, { detail: offers }));
}

/** Mount once, inside the auth + toast providers (the app shell). */
export function DefectTaskOfferHost() {
  const { csrfToken, user } = useAuth();
  const toast = useToast();
  const [offers, setOffers] = useState<Offer[]>([]);
  const [checked, setChecked] = useState<Set<string>>(new Set());
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  // Keys currently in the dialog, so a second save of the same item while it
  // is open doesn't list a nedostatok twice.
  const shown = useRef<Set<string>>(new Set());

  useEffect(() => {
    async function onOffer(e: Event) {
      let incoming = (e as CustomEvent<Offer[]>).detail.filter((o) => !shown.current.has(o.key));
      if (incoming.length === 0) return;

      // Skip nedostatky that already have a task — accepted on another
      // device, or before this device kept any memory of it. Best effort:
      // offline (or for an úkon that hasn't synced) the local memory decides.
      const inspectionId = incoming[0].inspectionId;
      if (inspectionId > 0) {
        try {
          const res = await Tasks.forInspection(inspectionId);
          const taken = new Set(res.items.map((t) => t.source_defect_key));
          const already = incoming.filter((o) => taken.has(o.key)).map((o) => o.key);
          remember(already, 'accepted');
          incoming = incoming.filter((o) => !taken.has(o.key));
        } catch {
          // keep all
        }
      }
      incoming = incoming.filter((o) => !shown.current.has(o.key));
      if (incoming.length === 0) return;

      for (const o of incoming) shown.current.add(o.key);
      setOffers((prev) => [...prev, ...incoming]);
      // Pre-ticked, as in the mockup — the technician unticks what he doesn't want.
      setChecked((prev) => new Set([...prev, ...incoming.map((o) => o.key)]));
      setOpen(true);
    }
    window.addEventListener(OFFER_EVENT, onOffer);
    return () => window.removeEventListener(OFFER_EVENT, onOffer);
  }, []);

  const close = useCallback(() => {
    setOpen(false);
    // Cleared after the exit animation so the text doesn't blank mid-fade.
    window.setTimeout(() => {
      setOffers([]);
      setChecked(new Set());
      shown.current = new Set();
    }, 250);
  }, []);

  const decline = useCallback(() => {
    remember(offers.map((o) => o.key), 'declined');
    close();
  }, [offers, close]);

  async function accept() {
    const chosen = offers.filter((o) => checked.has(o.key));
    remember(offers.filter((o) => !checked.has(o.key)).map((o) => o.key), 'declined');
    if (chosen.length === 0) {
      close();
      return;
    }
    setSaving(true);
    let created = 0;
    let queued = 0;
    let failed = 0;
    for (const offer of chosen) {
      try {
        await Tasks.createFromDefect({
          text: defectTaskText(offer.description),
          due_date: offer.deadline,
          // The technician who recorded the nedostatok follows it up.
          assignee_user_id: user?.id ?? null,
          source_inspection_id: offer.inspectionId,
          source_defect_key: offer.key,
        }, csrfToken);
        remember([offer.key], 'accepted');
        if (offer.inspectionId < 0 || !navigator.onLine) queued++;
        else created++;
      } catch (err) {
        if (err instanceof OfflineQueuedError) {
          remember([offer.key], 'accepted');
          queued++;
        } else {
          failed++;
        }
      }
    }
    setSaving(false);
    if (failed > 0) {
      toast.error(failed === chosen.length ? 'Úlohu sa nepodarilo vytvoriť.' : `${failed} z ${chosen.length} úloh sa nepodarilo vytvoriť.`);
    } else if (queued > 0 && created === 0) {
      toast.success('Uloží sa keď budeš online');
    } else {
      toast.success(chosen.length === 1 ? 'Úloha vytvorená' : 'Úlohy vytvorené');
    }
    close();
  }

  const single = offers.length === 1;
  const count = offers.filter((o) => checked.has(o.key)).length;

  return (
    <Dialog
      open={open}
      onClose={decline}
      title="Nová úloha"
      dismissible={!saving}
    >
      <div className="flex flex-col gap-4">
        {single ? (
          <p className="flex gap-3 text-sm text-ink-700">
            <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
              <ListTodo className="size-4" />
            </span>
            <span className="min-w-0 break-words pt-1.5">{offerSentence(offers[0])}</span>
          </p>
        ) : (
          <ul className="flex max-h-[50vh] flex-col gap-2 overflow-y-auto">
            {offers.map((offer) => {
              const on = checked.has(offer.key);
              return (
                <li key={offer.key}>
                  <button
                    type="button"
                    role="checkbox"
                    aria-checked={on}
                    onClick={() => setChecked((prev) => {
                      const next = new Set(prev);
                      if (on) next.delete(offer.key); else next.add(offer.key);
                      return next;
                    })}
                    className={cn(
                      'flex w-full items-start gap-3 rounded-2xl border px-3 py-3 text-left text-sm transition-all duration-200 active:scale-[0.99]',
                      on
                        ? 'border-firol-300 bg-firol-50/60 text-ink-800'
                        : 'border-ink-200 bg-white text-ink-500 hover:border-ink-300',
                    )}
                  >
                    <span
                      className={cn(
                        'mt-0.5 grid size-5 shrink-0 place-items-center rounded-md border transition-colors duration-200',
                        on ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
                      )}
                    >
                      {on && <Check className="size-3.5" />}
                    </span>
                    <span className="min-w-0 break-words">{offerSentence(offer)}</span>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={decline} disabled={saving}>
            Nie
          </Button>
          <Button
            size="sm"
            onClick={accept}
            loading={saving}
            disabled={!single && count === 0}
          >
            {single ? 'Áno, vytvoriť' : `Vytvoriť (${count})`}
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
