<?php

declare(strict_types=1);

namespace Firol\Pdf;

use Firol\Storage\Storage;
use Firol\Support\Env;

/**
 * Fills a .docx template with data by shelling out to the bundled Node
 * renderer (backend/docx/render.mjs, which uses docxtemplater). PHP has no
 * native docxtemplater, and the FIROL template is authored for it, so the
 * cleanest path is a short-lived `node` child process per generation.
 *
 * Requirements on the host:
 *   - a `node` binary (configurable via FIROL_NODE_BIN; default "node")
 *   - backend/docx dependencies installed (`npm ci` in backend/docx),
 *     which the deploy step runs alongside the frontend build.
 *   - PHP proc_open enabled (most hosts; some lockdowns disable it).
 *
 * The renderer is stateless: it takes a template path + data array and
 * returns the rendered .docx bytes. Conversion to PDF is a separate step
 * (PdfConverter).
 */
final class DocxRenderer
{
    /**
     * @param array<string, mixed> $data docxtemplater payload
     * @return string rendered .docx bytes
     */
    public static function render(string $templatePath, array $data): string
    {
        if (!is_file($templatePath)) {
            throw new \RuntimeException("DOCX template not found: $templatePath");
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is disabled — cannot run the DOCX renderer.');
        }

        $tmp     = Storage::docxTempDir();
        $token   = bin2hex(random_bytes(8));
        $dataIn  = "$tmp/data-$token.json";
        $docxOut = "$tmp/out-$token.docx";

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode DOCX payload to JSON.');
        }
        if (file_put_contents($dataIn, $json) === false) {
            throw new \RuntimeException('Failed to write DOCX payload to scratch dir.');
        }

        $script = dirname(__DIR__, 2) . '/docx/render.mjs';
        $node   = Env::get('FIROL_NODE_BIN', 'node');

        try {
            self::exec([$node, $script, $templatePath, $dataIn, $docxOut]);

            if (!is_file($docxOut)) {
                throw new \RuntimeException('DOCX renderer produced no output.');
            }
            $bytes = file_get_contents($docxOut);
            if ($bytes === false) {
                throw new \RuntimeException('Failed to read rendered DOCX.');
            }
            return $bytes;
        } finally {
            @unlink($dataIn);
            @unlink($docxOut);
        }
    }

    /**
     * Run a command, throwing with captured stderr on non-zero exit.
     *
     * @param list<string> $cmd
     */
    private static function exec(array $cmd): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // proc_open accepts an argv array on both *nix and Windows in modern
        // PHP, which sidesteps shell-escaping pitfalls entirely.
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start the DOCX renderer process.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException(
                "DOCX renderer exited with code $code" . ($detail !== '' ? ": $detail" : ''),
            );
        }
    }
}
