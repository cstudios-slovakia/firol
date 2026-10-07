<?php

declare(strict_types=1);

namespace Firol\Pdf;

use Firol\Support\ImageProcessor;

/**
 * Makes an issued protocol lighter by re-encoding the photos inside its bytes
 * (chapter 9.1: over the mailbox limit the photos are shrunk).
 *
 * The file is edited, not re-rendered. A numbered protocol has to stay the
 * document that was issued — a re-render reads today's record and today's
 * templates and can come out with different content. Here only the pixels of
 * the big JPEG images change; text, layout, page count, signature and number
 * are the bytes that were archived. The stored file is never touched: the
 * caller keeps the original and mails the result.
 *
 * Works on what mPDF writes: PDF 1.4, one classic xref table, no object
 * streams, no encryption. Anything else is left alone (null).
 */
final class PdfImageShrinker
{
    /**
     * @return string|null the lighter PDF, or null when no image could be made
     *                     smaller or the file is not one this can edit safely
     */
    public static function shrink(string $pdf, int $maxEdge, int $quality): ?string
    {
        if (!ImageProcessor::jpegSupported()) {
            return null;
        }
        $table = self::readTable($pdf);
        if ($table === null) {
            return null;
        }

        // Object spans in file order: each runs to the start of the next one
        // (the last to the xref table).
        $numbers = array_keys($table['offsets']);
        usort($numbers, static fn (int $a, int $b): int => $table['offsets'][$a] <=> $table['offsets'][$b]);

        $out      = substr($pdf, 0, $table['offsets'][$numbers[0]]);
        $newOff   = [];
        $shrunk   = 0;
        $lastIdx  = count($numbers) - 1;
        foreach ($numbers as $k => $num) {
            $start = $table['offsets'][$num];
            $end   = $k === $lastIdx ? $table['xrefPos'] : $table['offsets'][$numbers[$k + 1]];
            $span  = substr($pdf, $start, $end - $start);

            $lighter = self::shrinkImageObject($span, $maxEdge, $quality);
            if ($lighter !== null) {
                $span = $lighter;
                $shrunk++;
            }
            $newOff[$num] = strlen($out);
            $out .= $span;
        }
        if ($shrunk === 0) {
            return null;
        }

        $xrefPos = strlen($out);
        $out .= "xref\n0 " . $table['size'] . "\n";
        for ($n = 0; $n < $table['size']; $n++) {
            $out .= isset($newOff[$n])
                ? sprintf("%010d %05d n \n", $newOff[$n], $table['generations'][$n])
                : $table['free'][$n];
        }
        $out .= $table['trailer'] . "startxref\n" . $xrefPos . "\n%%EOF\n";

        // Trust nothing: the result must read back as a consistent file.
        $check = self::readTable($out);
        if ($check === null || $check['offsets'] != $newOff) {
            return null;
        }
        return strlen($out) < strlen($pdf) ? $out : null;
    }

    /**
     * One image object → the same object with a smaller JPEG, or null to leave
     * it as it is (not a plain RGB JPEG, already small enough, or no gain).
     */
    private static function shrinkImageObject(string $span, int $maxEdge, int $quality): ?string
    {
        if (!str_contains($span, '/DCTDecode')) {
            return null;
        }
        if (!preg_match('/^(\d+ \d+ obj\s*<<)(.*?)(>>\s*stream\r?\n)/s', $span, $m)) {
            return null;
        }
        [$whole, $open, $dict, $close] = $m;

        // Plain 8-bit RGB JPEG only: a mask, a decode array or another colour
        // space would go wrong if the pixels changed underneath it.
        if (!preg_match('#/Subtype\s*/Image#', $dict)
            || !preg_match('#/Filter\s*/DCTDecode#', $dict)
            || !preg_match('#/ColorSpace\s*/DeviceRGB#', $dict)
            || !preg_match('#/BitsPerComponent\s+8\b#', $dict)
            || preg_match('#/(SMask|Mask|Decode)\b#', $dict)
            || !preg_match('#/Width\s+(\d+)#', $dict, $w)
            || !preg_match('#/Height\s+(\d+)#', $dict, $h)
            || !preg_match('#/Length\s+(\d+)(?!\s+\d+\s+R)#', $dict, $len)
        ) {
            return null;
        }
        if (max((int) $w[1], (int) $h[1]) <= $maxEdge) {
            return null;
        }

        $length = (int) $len[1];
        $data   = substr($span, strlen($whole), $length);
        $tail   = substr($span, strlen($whole) + $length);
        if (strlen($data) !== $length || !preg_match('/^\s*endstream\s*endobj\s*$/', $tail)) {
            return null;
        }

        $src = tempnam(sys_get_temp_dir(), 'pdf-img-');
        $dst = tempnam(sys_get_temp_dir(), 'pdf-img-');
        if ($src === false || $dst === false) {
            return null;
        }
        try {
            file_put_contents($src, $data);
            $meta = ImageProcessor::writeResizedJpeg($src, $dst, $maxEdge, $quality);
            $new  = file_get_contents($dst);
            if ($new === false || strlen($new) >= $length) {
                return null;
            }
        } catch (\RuntimeException) {
            return null;
        } finally {
            @unlink($src);
            @unlink($dst);
        }

        $dict = preg_replace('#/Width\s+\d+#', '/Width ' . $meta['width'], $dict, 1);
        $dict = preg_replace('#/Height\s+\d+#', '/Height ' . $meta['height'], (string) $dict, 1);
        $dict = preg_replace('#/Length\s+\d+#', '/Length ' . strlen($new), (string) $dict, 1);

        return $open . $dict . $close . $new . $tail;
    }

    /**
     * Object offsets and the pieces of the xref table, or null when the file
     * is not a single-xref PDF without object streams or encryption.
     *
     * @return array{
     *   xrefPos: int, size: int, offsets: array<int, int>,
     *   generations: array<int, int>, free: array<int, string>, trailer: string
     * }|null
     */
    private static function readTable(string $pdf): ?array
    {
        if (!str_starts_with($pdf, '%PDF-')
            || substr_count($pdf, 'startxref') !== 1
            || str_contains($pdf, '/ObjStm')
            || str_contains($pdf, '/Encrypt')
            || !preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $sx, PREG_OFFSET_CAPTURE)
        ) {
            return null;
        }
        $xrefPos = (int) $sx[1][0];
        if (!preg_match('/\Gxref\r?\n0 (\d+)\r?\n/', $pdf, $x, 0, $xrefPos)) {
            return null;
        }
        $size  = (int) $x[1];
        $first = $xrefPos + strlen($x[0]);

        $offsets = [];
        $gens    = [];
        $free    = [];
        for ($n = 0; $n < $size; $n++) {
            $entry = substr($pdf, $first + 20 * $n, 20);
            if (!preg_match('/^(\d{10}) (\d{5}) ([nf])[ \r]?[\r\n]$/', $entry, $e)) {
                return null;
            }
            if ($e[3] === 'f') {
                $free[$n] = $entry;
                continue;
            }
            $offset = (int) $e[1];
            if (!preg_match('/^' . $n . ' ' . (int) $e[2] . ' obj/', substr($pdf, $offset, 24))) {
                return null;
            }
            $offsets[$n] = $offset;
            $gens[$n]    = (int) $e[2];
        }
        if ($offsets === []) {
            return null;
        }

        $trailerStart = $first + 20 * $size;
        $trailer      = substr($pdf, $trailerStart, $sx[0][1] - $trailerStart);
        if (!str_starts_with($trailer, 'trailer')) {
            return null;
        }

        return [
            'xrefPos'     => $xrefPos,
            'size'        => $size,
            'offsets'     => $offsets,
            'generations' => $gens,
            'free'        => $free,
            'trailer'     => $trailer,
        ];
    }
}
