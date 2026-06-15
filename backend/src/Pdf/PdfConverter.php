<?php

declare(strict_types=1);

namespace Firol\Pdf;

use Firol\Storage\Storage;
use Firol\Support\Env;

/**
 * Converts a rendered .docx into a PDF. There is no pure-PHP way to do
 * this with layout fidelity, so we delegate to an external engine and
 * keep the choice configurable per environment:
 *
 *   FIROL_PDF_CONVERTER = gotenberg | libreoffice   (default: gotenberg)
 *
 * Gotenberg (recommended — runs as a container, no system packages):
 *   FIROL_GOTENBERG_URL   e.g. http://gotenberg:3000   (local dev compose
 *                         ships one; prod points at the team's instance)
 *
 * LibreOffice (when a soffice binary is available on the host):
 *   FIROL_LIBREOFFICE_BIN e.g. soffice | libreoffice | /usr/bin/soffice
 *
 * Both drivers take .docx bytes and return PDF bytes; callers don't care
 * which engine ran.
 */
final class PdfConverter
{
    public static function convert(string $docxBytes): string
    {
        $driver = strtolower(Env::get('FIROL_PDF_CONVERTER', 'gotenberg'));
        return match ($driver) {
            'gotenberg'   => self::viaGotenberg($docxBytes),
            'libreoffice' => self::viaLibreOffice($docxBytes),
            default       => throw new \RuntimeException("Unknown FIROL_PDF_CONVERTER: $driver"),
        };
    }

    private static function viaGotenberg(string $docxBytes): string
    {
        $base = rtrim(Env::get('FIROL_GOTENBERG_URL'), '/');
        if ($base === '') {
            throw new \RuntimeException('FIROL_GOTENBERG_URL is not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('ext-curl is required for the Gotenberg converter.');
        }

        $tmp  = Storage::docxTempDir();
        $file = "$tmp/gotenberg-" . bin2hex(random_bytes(8)) . '.docx';
        if (file_put_contents($file, $docxBytes) === false) {
            throw new \RuntimeException('Failed to stage DOCX for conversion.');
        }

        try {
            $ch = curl_init("$base/forms/libreoffice/convert");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_POSTFIELDS     => [
                    'files' => new \CURLFile($file, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document.docx'),
                ],
            ]);
            $pdf  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($pdf === false || $code !== 200) {
                $detail = $err !== '' ? $err : (is_string($pdf) ? substr($pdf, 0, 500) : '');
                throw new \RuntimeException("Gotenberg conversion failed (HTTP $code): $detail");
            }
            return (string) $pdf;
        } finally {
            @unlink($file);
        }
    }

    private static function viaLibreOffice(string $docxBytes): string
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is disabled — cannot run LibreOffice.');
        }
        $bin = Env::get('FIROL_LIBREOFFICE_BIN', 'soffice');

        $tmp     = Storage::docxTempDir();
        $token   = bin2hex(random_bytes(8));
        $workDir = "$tmp/lo-$token";
        Storage::ensureDir($workDir);
        $inFile  = "$workDir/document.docx";
        $outFile = "$workDir/document.pdf";

        if (file_put_contents($inFile, $docxBytes) === false) {
            throw new \RuntimeException('Failed to stage DOCX for conversion.');
        }

        try {
            // A dedicated per-run user profile dir avoids the "another
            // instance is running" lock and keeps headless conversions
            // independent under concurrency.
            $cmd = [
                $bin,
                '--headless',
                '--norestore',
                '-env:UserInstallation=file://' . $workDir . '/profile',
                '--convert-to', 'pdf',
                '--outdir', $workDir,
                $inFile,
            ];
            self::exec($cmd);

            if (!is_file($outFile)) {
                throw new \RuntimeException('LibreOffice produced no PDF.');
            }
            $pdf = file_get_contents($outFile);
            if ($pdf === false) {
                throw new \RuntimeException('Failed to read converted PDF.');
            }
            return $pdf;
        } finally {
            self::rrmdir($workDir);
        }
    }

    /** @param list<string> $cmd */
    private static function exec(array $cmd): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start LibreOffice.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException("LibreOffice exited with code $code" . ($detail !== '' ? ": $detail" : ''));
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
