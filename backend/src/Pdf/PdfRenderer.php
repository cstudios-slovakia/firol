<?php

declare(strict_types=1);

namespace Firol\Pdf;

use Firol\Storage\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders inspection PDF protocols. `renderForType()` maps an inspection type
 * slug to its template; trainings have their own entry point.
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
     * Trainings render the attendance protocol, except the Pokyn — žatevné
     * práce, which is a training-tree document with its own layout (no
     * attendee table, an editable instruction text instead).
     *
     * @param array<string, mixed> $payload
     */
    public static function renderTraining(array $payload): string
    {
        $template = ($payload['training']['type'] ?? '') === 'pokyn_zatva'
            ? 'pokyn_zatva.php'
            : 'training.php';

        $html = self::renderTemplate(__DIR__ . '/templates/' . $template, $payload);
        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    /**
     * Potvrdenie o vykonaní práce (block 1 / chapter 10). Its own entry point
     * rather than a branch of renderForType(): it is not an inspection
     * protocol, it has no items or stats, and its header names the
     * technician's firm instead of the client's.
     *
     * @param array<string, mixed> $payload
     */
    public static function renderWorkConfirmation(array $payload): string
    {
        $html = self::renderTemplate(__DIR__ . '/templates/potvrdenie_prace.php', $payload);
        return self::buildPdf($html, $payload['number'] ?? 'potvrdenie');
    }

    /**
     * Type-aware dispatcher. Adding a new inspection type means adding a
     * branch here + the corresponding template under templates/.
     *
     * When `$payload['photos']` is a non-empty list the photo appendix
     * (change request 2.2) is appended after the body on its own page. The
     * body markup itself is untouched — types don't need to know about it.
     *
     * @param array<string, mixed> $payload Must contain: number, generated_at,
     *   brand{name,logo_data_uri?,color}, inspection (date, periodicity, notes),
     *   company (name, ico, address), facility (name, address),
     *   inspector (fullname, certification_number, valid_from, valid_to,
     *   signature_data_uri?), items (fields + position), stats, and — when the
     *   protocol carries photo documentation — photos (caption + path).
     */
    public static function renderForType(string $type, array $payload): string
    {
        $bodyTemplate = match ($type) {
            'php'                => 'php.php',
            'hydranty'           => 'hydranty.php',
            'oprava_ts_php'      => 'oprava_ts_php.php',
            'poziarna_kniha'     => 'poziarna_kniha.php',
            'pu_akcieschopnost'  => 'pu_akcieschopnost.php',
            'pu_udrzba'          => 'pu_udrzba.php',
            'nudzove_osvetlenie' => 'nudzove_osvetlenie.php',
            'ts_hadic'           => 'ts_hadic.php',
            'vyradenie'          => 'vyradenie.php',
            // Both audits share one template — the two differ in their
            // wording and their colour, and both of those travel in the
            // payload (see Firol\Audit\AuditProtocol).
            'audit_bozp',
            'audit_opp'          => 'audit.php',
            default => throw new \InvalidArgumentException("No renderer for type: $type"),
        };

        $html = self::renderTemplate(__DIR__ . '/templates/' . $bodyTemplate, $payload);
        $html .= self::renderPhotoAppendix($payload);

        return self::buildPdf($html, $payload['number'] ?? 'firol');
    }

    /**
     * Renders the "Príloha — Fotodokumentácia" pages, or an empty string when
     * the inspection has no photos (or the technician unticked the option) —
     * in which case the PDF looks exactly as it did before 2.2 existed.
     *
     * @param array<string, mixed> $payload
     */
    public static function renderPhotoAppendix(array $payload): string
    {
        $photos = $payload['photos'] ?? [];
        if (!is_array($photos) || $photos === []) {
            return '';
        }
        return '<pagebreak />'
            . self::renderTemplate(__DIR__ . '/templates/photo_appendix.php', $payload);
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
            'margin_top'     => 12,
            'margin_bottom'  => 12,
            'margin_left'    => 11,
            'margin_right'   => 11,
            'default_font'   => 'dejavusans',
            'default_font_size' => 10,
        ]);

        $mpdf->SetTitle('POapp — ' . $title);
        $mpdf->SetCreator('POapp');
        $mpdf->SetAuthor('POapp');

        // Small promotional line on the bottom of every page of every
        // generated document (inspections + trainings). Rendered inside the
        // bottom page margin so it never overlaps the document content.
        $mpdf->SetHTMLFooter(
            '<div style="text-align:center; font-size:7pt; color:#9b9ba3;">'
            . 'Tento dokument bol vytvorený pomocou softvéru poapp.sk'
            . '</div>'
        );

        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }
}
