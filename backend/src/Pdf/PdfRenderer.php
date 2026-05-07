<?php

declare(strict_types=1);

namespace Firol\Pdf;

use Firol\Storage\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders inspection PDF protocols. One method per inspection type;
 * Phase 3a-3 ships RPHP, others land alongside their own form types.
 *
 * Templates are plain PHP files in templates/ that read their data from
 * `$payload` (extracted into local variables). Keeping them as PHP keeps
 * the templating layer dependency-free and the diff readable for a
 * tracked PDF — designers can tweak the markup without touching this
 * service.
 *
 * Branding: Firol red (#E8433A) + monogram for now. Account-specific
 * logo + theme color is Phase 5; the renderer reads from $payload['brand']
 * so adding it later is a payload change, not a renderer change.
 */
final class PdfRenderer
{
    /**
     * @param array<string, mixed> $payload Must contain: number, generated_at,
     *   brand{name,logo_path?,color}, inspection (date, periodicity, notes),
     *   company (name, ico, address), facility (name, address),
     *   inspector (fullname, certification_number, valid_from, valid_to,
     *   signature_path?), items (list of RPHP fields + position),
     *   stats (A, TS, O, V, total).
     */
    public static function renderRphp(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/rphp.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    /** @param array<string, mixed> $payload */
    private static function renderTemplate(string $templatePath, array $payload): string
    {
        ob_start();
        // Templates read variables by name — extract is the cleanest way
        // to pass the payload without polluting global state.
        extract($payload, EXTR_SKIP);
        // The template is trusted code in our repo, not user content.
        include $templatePath;
        return (string) ob_get_clean();
    }

    private static function buildPdf(string $html, string $title): string
    {
        $mpdf = new Mpdf([
            'tempDir'        => Storage::mpdfTempDir(),
            'mode'           => 'utf-8',
            'format'         => 'A4',
            'margin_top'     => 22,
            'margin_bottom'  => 18,
            'margin_left'    => 16,
            'margin_right'   => 16,
            'default_font'   => 'dejavusans',
            'default_font_size' => 10,
        ]);

        $mpdf->SetTitle('Firol — ' . $title);
        $mpdf->SetCreator('Firol');
        $mpdf->SetAuthor('Firol');

        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }
}
