import { useRef, useState } from 'react';
import { Check, PenLine, X } from 'lucide-react';
import { SignaturePad, type SignaturePadHandle } from '@/components/SignaturePad';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';

/** Widest signature kept — enough for the protocol cell, a few kB as PNG. */
const MAX_WIDTH = 360;

/**
 * Downscale the pad's HiDPI PNG so a row with a signature stays a few
 * kilobytes: the signature lives on the person row itself (offline outbox,
 * backup and restore then carry it with no second upload channel).
 */
async function toSmallDataUri(blob: Blob): Promise<string> {
  const bitmap = await createImageBitmap(blob);
  const scale = Math.min(1, MAX_WIDTH / bitmap.width);
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(bitmap.width * scale));
  canvas.height = Math.max(1, Math.round(bitmap.height * scale));
  const ctx = canvas.getContext('2d');
  if (!ctx) throw new Error('canvas');
  ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close?.();
  return canvas.toDataURL('image/png');
}

/**
 * „Podpis (na displeji alebo prázdny)" — chapters 7 and 8. The signature is
 * optional: left empty, the protocol keeps the cell blank for a pen, which is
 * the ordinary way (and the one a shift of twenty people is fastest with).
 * Drawing happens inline in the row — no dialog per person.
 */
export function SignatureCapture({
  value,
  onChange,
  disabled,
  name,
}: {
  value: string | null;
  onChange: (next: string | null) => void;
  disabled?: boolean;
  /** Whose signature — read out to the person holding the phone. */
  name?: string;
}) {
  const [open, setOpen] = useState(false);
  const [empty, setEmpty] = useState(true);
  const [busy, setBusy] = useState(false);
  const padRef = useRef<SignaturePadHandle | null>(null);

  async function accept() {
    const blob = await padRef.current?.toBlob();
    if (!blob) return;
    setBusy(true);
    try {
      onChange(await toSmallDataUri(blob));
      setOpen(false);
    } finally {
      setBusy(false);
    }
  }

  if (open) {
    return (
      <div className="flex animate-fade-up flex-col gap-2 rounded-2xl border border-firol-200 bg-firol-50/40 p-2">
        <p className="px-1 text-xs text-ink-600">
          {name ? <>Podpis: <span className="font-medium text-ink-800">{name}</span></> : 'Podpis'}
        </p>
        <div className="overflow-hidden rounded-xl border border-ink-200 bg-white">
          <SignaturePad ref={padRef} heightPx={140} onEmptyChange={setEmpty} />
        </div>
        <div className="flex gap-2">
          <Button type="button" variant="secondary" size="sm" className="flex-1"
            onClick={() => setOpen(false)} leftIcon={<X className="size-3.5" />}>
            Zrušiť
          </Button>
          <Button type="button" size="sm" className="flex-1" disabled={empty} loading={busy}
            onClick={accept} leftIcon={<Check className="size-3.5" />}>
            Uložiť podpis
          </Button>
        </div>
      </div>
    );
  }

  if (value) {
    return (
      <div className="flex items-center gap-2">
        <img src={value} alt="Podpis" className="h-10 max-w-[9rem] rounded-lg border border-ink-100 bg-white object-contain" />
        {!disabled && (
          <button type="button" onClick={() => onChange(null)}
            className="text-xs font-medium text-ink-500 transition-colors hover:text-status-bad">
            Zmazať podpis
          </button>
        )}
      </div>
    );
  }

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={() => setOpen(true)}
      className={cn(
        'inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-dashed border-ink-300 px-3 text-xs font-medium text-ink-600',
        'transition-all duration-200 hover:border-firol-300 hover:text-firol-700 active:scale-[0.98] disabled:opacity-50',
      )}
    >
      <PenLine className="size-3.5" />
      Podpísať na displeji
    </button>
  );
}
