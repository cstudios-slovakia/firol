import { useEffect, useState } from 'react';
import { Check, Receipt } from 'lucide-react';
import {
  BILLING_MODE_LABELS,
  BILLING_MODES,
  Invoicing,
  mergeInvoicing,
  type InvoicingFields,
  type InvoicingPatch,
  type InvoicingTarget,
} from '@/api/invoicing';
import { useAuth } from '@/auth/AuthContext';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';

/**
 * Fakturácia úkonu — block 4 / chapter 22.
 *
 * A compact block on the úkon summary / detail: the režim (prefilled from the
 * firm), and with „Na faktúru" the „Vyfakturované" check-off with its date.
 * With any other režim the check-off is not shown at all (chapter 22
 * acceptance criterion). The note is part of the model regardless of režim.
 *
 * Stays editable on a locked úkon — invoicing happens after the protocol is
 * issued and is printed on nothing, so changing it never touches the PDF.
 *
 * Saves on every change (no separate save button): the change is shown at
 * once, a failure puts the previous state back, and offline the PATCH waits
 * in the outbox like any other edit.
 */
export function InvoicingBlock({
  target,
  id,
  value,
  onChange,
  disabled = false,
}: {
  target: InvoicingTarget;
  id: number;
  value: InvoicingFields;
  /** Receives the new state — optimistic first, then the server's. */
  onChange: (next: InvoicingFields) => void;
  /** Read-only account (expired subscription). */
  disabled?: boolean;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [note, setNote] = useState(value.billing_note ?? '');

  // Follow the saved note when it changes from outside (server answer, reload).
  useEffect(() => { setNote(value.billing_note ?? ''); }, [value.billing_note]);

  async function save(patch: InvoicingPatch) {
    const before = value;
    onChange(mergeInvoicing(value, patch));
    setSaving(true);
    try {
      const res = await Invoicing.update(target, id, patch, csrfToken);
      onChange(res.invoicing);
    } catch (err) {
      if (handleOfflineSave(err, toast)) return;
      onChange(before);
      toast.error(err instanceof ApiError ? err.message : 'Fakturáciu sa nepodarilo uložiť.');
    } finally {
      setSaving(false);
    }
  }

  function saveNote() {
    const trimmed = note.trim();
    if (trimmed === (value.billing_note ?? '')) return;
    void save({ billing_note: trimmed === '' ? null : trimmed });
  }

  const mode = value.billing_mode;
  const locked = disabled;

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex items-start gap-3">
        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-ink-100 text-ink-500">
          <Receipt className="size-4" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-ink-500">
            Fakturácia
            {saving && <Spinner size="sm" />}
          </p>
          <p className="mt-0.5 text-xs text-ink-500">
            {mode === null
              ? 'Úkon vznikol pred evidenciou fakturácie — režim zatiaľ nie je zvolený.'
              : 'Netlačí sa na protokol — dá sa meniť aj po vystavení.'}
          </p>
        </div>
      </div>

      <div
        role="radiogroup"
        aria-label="Režim fakturácie"
        className="grid grid-cols-3 gap-1 rounded-2xl bg-ink-100 p-1"
      >
        {BILLING_MODES.map((m) => {
          const active = mode === m;
          return (
            <button
              key={m}
              type="button"
              role="radio"
              aria-checked={active}
              disabled={locked}
              onClick={() => { if (!active) void save({ billing_mode: m }); }}
              className={cn(
                'min-h-10 rounded-xl px-2 text-xs font-medium leading-tight transition-all duration-200',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
                'active:scale-[0.97] disabled:pointer-events-none disabled:opacity-60',
                active
                  ? 'bg-white text-ink-900 shadow-sm'
                  : 'text-ink-600 hover:bg-white/60 hover:text-ink-800',
              )}
            >
              {BILLING_MODE_LABELS[m]}
            </button>
          );
        })}
      </div>

      {/* Chapter 22 — the check-off exists only with „Na faktúru". */}
      {mode === 'na_fakturu' && (
        <div className="flex animate-fade-up flex-col gap-2 sm:flex-row sm:items-center">
          <label
            className={cn(
              'flex flex-1 cursor-pointer items-center gap-3 rounded-2xl border px-3 py-2.5 transition-all duration-200',
              value.invoiced
                ? 'border-status-ok/40 bg-[var(--color-status-ok-bg)]'
                : 'border-ink-200 bg-white hover:border-ink-300',
              locked && 'pointer-events-none opacity-60',
            )}
          >
            <input
              type="checkbox"
              checked={value.invoiced}
              disabled={locked}
              onChange={(e) => void save({ invoiced: e.target.checked })}
              className="size-4 shrink-0 accent-[var(--color-status-ok)]"
            />
            <span className="text-sm font-medium text-ink-900">Vyfakturované</span>
          </label>
          {value.invoiced && (
            <input
              type="date"
              aria-label="Dátum vyfakturovania"
              value={value.invoiced_at ?? ''}
              disabled={locked}
              onChange={(e) => {
                if (e.target.value) void save({ invoiced_at: e.target.value });
              }}
              className="h-11 min-w-0 appearance-none rounded-2xl border border-ink-200 bg-white px-3 text-sm text-ink-900 transition-colors hover:border-ink-300 focus:border-firol-300 focus:outline-none focus:ring-2 focus:ring-firol-200 sm:w-44"
            />
          )}
        </div>
      )}

      {mode !== null && (
        <input
          type="text"
          value={note}
          disabled={locked}
          maxLength={1000}
          onChange={(e) => setNote(e.target.value)}
          onBlur={saveNote}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              (e.target as HTMLInputElement).blur();
            }
          }}
          placeholder="Poznámka k fakturácii (nepovinné)"
          aria-label="Poznámka k fakturácii"
          className="h-11 w-full min-w-0 rounded-2xl border border-ink-200 bg-white px-3 text-sm text-ink-900 placeholder:text-ink-400 transition-colors hover:border-ink-300 focus:border-firol-300 focus:outline-none focus:ring-2 focus:ring-firol-200 disabled:bg-ink-50"
        />
      )}
    </Card>
  );
}

/**
 * One-tap „Vyfakturované" for the Nevyfakturované list — the end-of-month
 * round, where the technician goes down the list and ticks what they have
 * invoiced. Stamps today; the date can be corrected on the úkon itself.
 */
export function InvoiceTickButton({
  target,
  id,
  onDone,
  className,
  compact = false,
}: {
  target: InvoicingTarget;
  id: number;
  /** Called once the tick is saved (or queued offline). */
  onDone: (next: InvoicingFields) => void;
  className?: string;
  /** Icon only below the sm breakpoint — for rows with no second action line. */
  compact?: boolean;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  async function tick() {
    setBusy(true);
    try {
      const res = await Invoicing.update(target, id, { invoiced: true }, csrfToken);
      toast.success('Označené ako vyfakturované');
      onDone(res.invoicing);
    } catch (err) {
      if (handleOfflineSave(err, toast)) {
        onDone(mergeInvoicing({ billing_mode: 'na_fakturu' }, { invoiced: true }));
        return;
      }
      toast.error(err instanceof ApiError ? err.message : 'Nepodarilo sa označiť ako vyfakturované.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <button
      type="button"
      onClick={tick}
      disabled={busy}
      title="Označiť ako vyfakturované"
      aria-label="Označiť ako vyfakturované"
      className={cn(
        'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-xl border border-status-ok/40 bg-white px-2.5 text-xs font-medium text-status-ok',
        'transition-all duration-200 hover:bg-[var(--color-status-ok-bg)] active:scale-[0.97] disabled:opacity-60',
        className,
      )}
    >
      {busy ? <Spinner size="sm" /> : <Check className="size-3.5" />}
      <span className={cn(compact && 'hidden sm:inline')}>Vyfakturované</span>
    </button>
  );
}
