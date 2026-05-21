<?php

declare(strict_types=1);

namespace Firol\Pdf;

use Firol\Storage\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders inspection PDF protocols. One method per inspection type;
 * Phase 3a-3 ships PHP, others land alongside their own form types.
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
     *   signature_path?), items (list of PHP fields + position),
     *   stats (A, TS, O, V, total).
     */
    public static function renderPhp(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/php.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    public static function renderHydranty(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/hydranty.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    public static function renderOpravaTsPhp(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/oprava_ts_php.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    public static function renderPoziarnaKniha(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/poziarna_kniha.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    public static function renderPuAkcieschopnost(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/pu_akcieschopnost.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    public static function renderPuUdrzba(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/pu_udrzba.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    public static function renderNudzoveOsvetlenie(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/nudzove_osvetlenie.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    public static function renderTsHadic(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/ts_hadic.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    public static function renderTraining(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/training.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    /**
     * Type-aware dispatcher. Adding a new inspection type means adding a
     * branch here + the corresponding template under templates/.
     */
    public static function renderForType(string $type, array $payload): string
    {
        return match ($type) {
            'php'                => self::renderPhp($payload),
            'hydranty'           => self::renderHydranty($payload),
            'oprava_ts_php'      => self::renderOpravaTsPhp($payload),
            'poziarna_kniha'     => self::renderPoziarnaKniha($payload),
            'pu_akcieschopnost'  => self::renderPuAkcieschopnost($payload),
            'pu_udrzba'          => self::renderPuUdrzba($payload),
            'nudzove_osvetlenie' => self::renderNudzoveOsvetlenie($payload),
            'ts_hadic'           => self::renderTsHadic($payload),
            default => throw new \InvalidArgumentException("No renderer for type: $type"),
        };
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
