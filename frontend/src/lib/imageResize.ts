/*
 * Client-side photo downscaling (change request 2.2).
 *
 * Resizing before upload is what makes photo documentation usable in the
 * field: a raw phone shot is 3–8 MB, and a technician on mobile data adding
 * several photos per item would be waiting on the network far longer than on
 * the inspection itself. A 1600px / q75 JPEG lands around 300 KB, which is
 * both fast to upload and more than enough resolution for the A4 appendix.
 *
 * It also sidesteps PHP's upload_max_filesize, which on shared hosting is
 * often 2 MB — smaller than the photos a modern phone produces.
 *
 * Re-encoding through a canvas drops EXIF as a side effect, so GPS
 * coordinates from the technician's phone never travel to the server. The
 * backend re-encodes again as a backstop.
 */

/** Long edge of the uploaded photo, in pixels. Matches the server's cap. */
export const MAX_EDGE = 1600;

/** JPEG quality for the upload. Matches the server's re-encode. */
export const JPEG_QUALITY = 0.75;

export type ResizedPhoto = {
  blob: Blob;
  /** Object URL for the local preview. Caller must revoke it when done. */
  previewUrl: string;
  width: number;
  height: number;
};

/**
 * Decode → downscale → re-encode as JPEG. Never upscales: a photo already
 * smaller than the cap is re-encoded at its own size, which still normalises
 * the format and strips metadata.
 *
 * Rejects when the file isn't a decodable image.
 */
export async function resizeForUpload(
  file: File,
  maxEdge: number = MAX_EDGE,
  quality: number = JPEG_QUALITY,
): Promise<ResizedPhoto> {
  const source = await decode(file);

  const longEdge = Math.max(source.width, source.height);
  const ratio = longEdge > maxEdge ? maxEdge / longEdge : 1;
  const width = Math.max(1, Math.round(source.width * ratio));
  const height = Math.max(1, Math.round(source.height * ratio));

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    close(source.image);
    throw new Error('Canvas nie je dostupný.');
  }
  // Photos may be transparent PNGs; JPEG has no alpha, so paint white first
  // rather than letting transparent areas encode as black.
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, width, height);
  ctx.drawImage(source.image, 0, 0, width, height);
  close(source.image);

  const blob = await toBlob(canvas, quality);
  return {
    blob,
    previewUrl: URL.createObjectURL(blob),
    width,
    height,
  };
}

type DecodedImage = {
  image: CanvasImageSource & { width: number; height: number };
  width: number;
  height: number;
};

/**
 * `createImageBitmap` is both faster and the only path that applies the EXIF
 * orientation for us (`imageOrientation: 'from-image'`), so a portrait shot
 * doesn't end up sideways in the protocol. Older Safari lacks the option — and
 * some builds lack the function entirely — so fall back to an <img> decode.
 */
async function decode(file: File): Promise<DecodedImage> {
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
      return { image: bitmap, width: bitmap.width, height: bitmap.height };
    } catch {
      // Fall through to the <img> path below.
    }
  }

  const url = URL.createObjectURL(file);
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const el = new Image();
      el.onload = () => resolve(el);
      el.onerror = () => reject(new Error('Súbor sa nepodarilo načítať ako obrázok.'));
      el.src = url;
    });
    return { image: img, width: img.naturalWidth, height: img.naturalHeight };
  } finally {
    URL.revokeObjectURL(url);
  }
}

function close(image: CanvasImageSource): void {
  if (typeof ImageBitmap !== 'undefined' && image instanceof ImageBitmap) {
    image.close();
  }
}

function toBlob(canvas: HTMLCanvasElement, quality: number): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error('Fotku sa nepodarilo skomprimovať.'))),
      'image/jpeg',
      quality,
    );
  });
}
