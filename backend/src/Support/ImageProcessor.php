<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Normalises uploaded field photos (change request 2.2).
 *
 * The browser already downsizes and re-encodes before uploading — that is what
 * keeps field uploads fast on mobile data. This class is the server-side
 * backstop: it re-decodes whatever actually arrived, applies the EXIF rotation,
 * caps the long edge and re-encodes as baseline JPEG. Re-encoding is also what
 * strips EXIF, so GPS coordinates from a technician's phone never reach a
 * protocol handed to a client.
 *
 * Requires ext-gd, which is already a hard dependency of mPDF.
 */
final class ImageProcessor
{
    /** Long-edge cap for the photo that lands in the PDF appendix. */
    public const MAX_EDGE = 1600;

    /** Long-edge cap for the list thumbnail. */
    public const THUMB_EDGE = 400;

    public const FULL_QUALITY  = 75;
    public const THUMB_QUALITY = 70;

    /**
     * GD is compiled with JPEG support. Not a given on shared hosting: a GD
     * built without libjpeg still loads and still serves mPDF, but has no
     * imagejpeg(). Callers degrade to storing the browser's already-resized
     * bytes rather than refusing the upload — losing a technician's field
     * photos would be a far worse failure than an unoptimised file.
     */
    public static function jpegSupported(): bool
    {
        return function_exists('imagejpeg') && function_exists('imagecreatefromjpeg');
    }

    /**
     * Upload MIME types this host can actually decode. Derived from GD rather
     * than hard-coded, because WebP support in particular varies between
     * builds — advertising a format we cannot read would turn into a confusing
     * "Fotku sa nepodarilo spracovať" at the end of an upload.
     *
     * JPEG stays on the list even without GD-JPEG: those uploads take the
     * store-as-is fallback path.
     *
     * @return list<string>
     */
    public static function acceptedMimeTypes(): array
    {
        $mimes = ['image/jpeg'];
        if (function_exists('imagecreatefrompng')) {
            $mimes[] = 'image/png';
        }
        if (function_exists('imagecreatefromwebp')) {
            $mimes[] = 'image/webp';
        }
        return $mimes;
    }

    /**
     * Decode → auto-rotate → downscale → re-encode to JPEG on disk.
     *
     * @return array{width: int, height: int, bytes: int}
     * @throws \RuntimeException when the source is not a decodable image.
     */
    public static function writeResizedJpeg(
        string $sourcePath,
        string $destPath,
        int $maxEdge,
        int $quality,
    ): array {
        $image = self::decode($sourcePath);

        try {
            $image = self::applyExifOrientation($image, $sourcePath);
            $image = self::downscale($image, $maxEdge);

            // Flatten onto white before writing: PNG/WebP sources may carry
            // alpha, which JPEG cannot represent — without this, transparent
            // regions come out black.
            $flat = self::flatten($image);
            try {
                if (!imagejpeg($flat, $destPath, $quality)) {
                    throw new \RuntimeException('Failed to write JPEG.');
                }
                $width  = imagesx($flat);
                $height = imagesy($flat);
            } finally {
                if ($flat !== $image) {
                    imagedestroy($flat);
                }
            }
        } finally {
            imagedestroy($image);
        }

        return [
            'width'  => $width,
            'height' => $height,
            'bytes'  => (int) (filesize($destPath) ?: 0),
        ];
    }

    private static function decode(string $path): \GdImage
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new \RuntimeException('Not a readable image.');
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default        => false,
        };

        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Unsupported image format.');
        }
        return $image;
    }

    /**
     * Phones record orientation in EXIF instead of rotating pixels. Canvas
     * re-encoding in the browser usually resolves it, but a photo that reaches
     * us straight from a gallery picker may still carry the tag — so honour it
     * here too rather than printing a sideways extinguisher into a protocol.
     */
    private static function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;

        $rotated = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => null,
        };
        if (!$rotated instanceof \GdImage) {
            return $image;
        }
        imagedestroy($image);
        return $rotated;
    }

    /** Scale down so the long edge is at most $maxEdge. Never upscales. */
    private static function downscale(\GdImage $image, int $maxEdge): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $long = max($w, $h);
        if ($long <= $maxEdge) {
            return $image;
        }

        $ratio = $maxEdge / $long;
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));

        $resized = imagescale($image, $nw, $nh, IMG_BICUBIC);
        if (!$resized instanceof \GdImage) {
            return $image;
        }
        imagedestroy($image);
        return $resized;
    }

    /** Composite onto an opaque white canvas so JPEG output has no black alpha. */
    private static function flatten(\GdImage $image): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        if (!$canvas instanceof \GdImage) {
            return $image;
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);
        return $canvas;
    }
}
