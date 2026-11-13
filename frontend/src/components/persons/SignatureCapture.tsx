import { useRef, useState } from 'react';
import { Check, PenLine, X } from 'lucide-react';
import { SignaturePad, type SignaturePadHandle } from '@/components/SignaturePad';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';

/**
 * Largest signature kept. The protocol prints it in a table cell 22 pt high,
 * so 120 px of height is still close to 400 dpi — and a few tens of kB as PNG
 * at most (PersonList caps a row's signature at 200 kB).
 */
const MAX_WIDTH = 360;
const MAX_HEIGHT = 120;

/** Bounding box of every non-transparent pixel, or null for a blank image. */
function inkBox(ctx: CanvasRenderingContext2D, w: number, h: number) {
  const data = ctx.getImageData(0, 0, w, h).data;
  let minX = w, minY = h, maxX = -1, maxY = -1;
  for (let y = 0; y < h; y++) {
    const row = y * w * 4;
    for (let x = 0; x < w; x++) {
      if (data[row + x * 4 + 3] === 0) continue;
      if (x < minX) minX = x;
      if (x > maxX) maxX = x;
      if (y < minY) minY = y;
      if (y > maxY) maxY = y;
    }
  }
  return maxX < 0 ? null : { x: minX, y: minY, w: maxX - minX + 1, h: maxY - minY + 1 };
}

function canvasOf(w: number, h: number): [HTMLCanvasElement, CanvasRenderingContext2D] {
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(w));
  canvas.height = Math.max(1, Math.round(h));
  const ctx = canvas.getContext('2d');
  if (!ctx) throw new Error('canvas');
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';
  return [canvas, ctx];
}

/**
 * Shrink the pad's HiDPI PNG so a row with a signature stays a few
 * kilobytes: the signature lives on the person row itself (offline outbox,
 * backup and restore then carry it with no second upload channel).
 *
 * The empty margin around the ink is cut off first, so the signature fills
 * the small printed cell and its line keeps a readable thickness there. The
 * shrinking goes in halving steps: one large drawImage step samples only
 * every few source pixels and frays a thin line.
 */
async function toSmallDataUri(blob: Blob): Promise<string> {
  const bitmap = await createImageBitmap(blob);
  const [full, fullCtx] = canvasOf(bitmap.width, bitmap.height);
  fullCtx.drawImage(bitmap, 0, 0);
  bitmap.close?.();

  const box = inkBox(fullCtx, full.width, full.height)
    ?? { x: 0, y: 0, w: full.width, h: full.height };
  const pad = Math.round(Math.max(full.width, full.height) * 0.02);
  const sx = Math.max(0, box.x - pad);
  const sy = Math.max(0, box.y - pad);
  const sw = Math.min(full.width, box.x + box.w + pad) - sx;
  const sh = Math.min(full.height, box.y + box.h + pad) - sy;

  const scale = Math.min(1, MAX_WIDTH / sw, MAX_HEIGHT / sh);
  const targetW = Math.max(1, Math.round(sw * scale));
  const targetH = Math.max(1, Math.round(sh * scale));

  const [crop, cropCtx] = canvasOf(sw, sh);
  cropCtx.drawImage(full, sx, sy, sw, sh, 0, 0, sw, sh);
  let cur = crop;
  while (cur.width / 2 >= targetW && cur.height / 2 >= targetH) {
    const [half, halfCtx] = canvasOf(cur.width / 2, cur.height / 2);
    halfCtx.drawImage(cur, 0, 0, half.width, half.height);
    cur = half;
  }
  const [out, outCtx] = canvasOf(targetW, targetH);
  outCtx.drawImage(cur, 0, 0, targetW, targetH);
  return out.toDataURL('image/png');
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
