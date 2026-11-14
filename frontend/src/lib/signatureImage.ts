/*
 * Drawn signatures (SignaturePad) as they are stored: the empty margin of the
 * pad cut off, so the ink fills the small printed cell (`.sig-img`, at most
 * 38 x 150 pt) and the line keeps its thickness there. Person rows also
 * shrink the result (toSmallDataUri); a handover or the technician's own
 * signature keeps the pad's resolution (cropToInk).
 */

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
export async function toSmallDataUri(blob: Blob): Promise<string> {
  const bitmap = await createImageBitmap(blob);
  const [full, fullCtx] = canvasOf(bitmap.width, bitmap.height);
  fullCtx.drawImage(bitmap, 0, 0);
  bitmap.close?.();

  const { sx, sy, sw, sh } = cropBox(full, fullCtx);

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

/** The margin left around the ink: 2 % of the longer side. */
function cropBox(full: HTMLCanvasElement, ctx: CanvasRenderingContext2D) {
  const box = inkBox(ctx, full.width, full.height) ?? { x: 0, y: 0, w: full.width, h: full.height };
  const pad = Math.round(Math.max(full.width, full.height) * 0.02);
  const sx = Math.max(0, box.x - pad);
  const sy = Math.max(0, box.y - pad);
  return {
    sx,
    sy,
    sw: Math.min(full.width, box.x + box.w + pad) - sx,
    sh: Math.min(full.height, box.y + box.h + pad) - sy,
  };
}

/**
 * The pad's PNG with the empty margin around the ink cut off, at full
 * resolution. A blank image comes back unchanged.
 */
export async function cropToInk(blob: Blob): Promise<Blob> {
  const bitmap = await createImageBitmap(blob);
  const [full, fullCtx] = canvasOf(bitmap.width, bitmap.height);
  fullCtx.drawImage(bitmap, 0, 0);
  bitmap.close?.();
  const { sx, sy, sw, sh } = cropBox(full, fullCtx);
  if (sx === 0 && sy === 0 && sw === full.width && sh === full.height) return blob;
  const [crop, cropCtx] = canvasOf(sw, sh);
  cropCtx.drawImage(full, sx, sy, sw, sh, 0, 0, sw, sh);
  return await new Promise<Blob>((resolve) => {
    crop.toBlob((b) => resolve(b ?? blob), 'image/png');
  });
}
