<?php

declare(strict_types=1);

namespace Firol\Pdf;

use Firol\Support\Handover;

/**
 * The client half of a protocol's signature table — block 1 / chapter 13.
 *
 * Every template used to spell this out for itself, which is how the column
 * ended up reading „Za spoločnosť" on one document and „Predložené na podpis"
 * on the next. It lives here now so the heading is „Za organizáciu"
 * everywhere and the line above the signature follows the document type.
 *
 * Two states:
 *   signed   — the client signed on the technician's screen; the captured
 *              image, their name and their role are printed.
 *   unsigned — nothing is printed but the line itself. Handing over an
 *              unsigned protocol and collecting the signature on paper is
 *              ordinary practice, so this is a first-class outcome, never a
 *              missing value.
 */
final class SignatureBlock
{
    /** Column heading, e.g. „Za organizáciu — prevzal na vedomie". */
    public static function heading(string $documentType): string
    {
        return self::esc(Handover::headingFor($documentType));
    }

    /**
     * The cell naming who signs: their name and role when the protocol was
     * signed, otherwise a neutral prompt for whoever signs the printout.
     *
     * `$fallback` is for documents that already carry a named approver on the
     * company record — printing that name beats printing a generic prompt when
     * nobody signed on the screen.
     *
     * @param array<string, mixed>|null $handover
     */
    public static function nameCell(?array $handover, string $fallback = ''): string
    {
        if ($handover === null) {
            $fallback = trim($fallback);
            return $fallback !== '' ? self::esc($fallback) : 'Zodpovedná osoba organizácie';
        }
        $html = self::esc((string) ($handover['fullname'] ?? ''));
        $role = trim((string) ($handover['role_title'] ?? ''));
        if ($role !== '') {
            $html .= '<br><span style="font-size:8pt; color:#555;">' . self::esc($role) . '</span>';
        }
        return $html;
    }

    /**
     * The cell holding the signature itself. `$action` is the wording printed
     * under the line — „Prevzal na vedomie", „Schválil" and so on.
     *
     * @param array<string, mixed>|null $handover
     */
    public static function signCell(?array $handover, string $documentType): string
    {
        $caption = self::esc(Handover::actionFor($documentType));
        $img = '';
        if ($handover !== null && !empty($handover['signature_data_uri'])) {
            $img = '<img class="sig-img" src="' . self::esc((string) $handover['signature_data_uri']) . '" alt="">';
        }
        return $img . '<div class="sig-line">' . $caption . '</div>';
    }

    /**
     * „Miesto a dátum" for the client column. Falls back to the protocol's own
     * place and date when the handover carries none, so the cell never
     * contradicts the header of the document it sits on.
     *
     * @param array<string, mixed>|null $handover
     */
    public static function placeAndDate(?array $handover, string $fallback): string
    {
        if ($handover === null || empty($handover['place']) || empty($handover['signed_on'])) {
            return self::esc($fallback);
        }
        $date = self::formatDate((string) $handover['signed_on']);
        return self::esc((string) $handover['place'] . ', ' . $date);
    }

    private static function formatDate(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts ? date('j. n. Y', $ts) : $iso;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
