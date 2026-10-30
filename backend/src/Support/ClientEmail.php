<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Where a client e-mail is prefilled (spec 3.2).
 *
 * The prevádzka's own address wins, then the firm's „Kontaktný e-mail", then
 * the first address typed into the free-text „Kontakt" line. The same order
 * is in frontend/src/lib/companyEmail.ts — keep the two together.
 */
final class ClientEmail
{
    private const IN_TEXT = '/[^\s@,;<>()]+@[^\s@,;<>()]+\.[^\s@,;<>()]+/';

    public static function resolve(?string $facilityEmail, ?string $companyEmail, ?string $companyContact): ?string
    {
        foreach ([$facilityEmail, $companyEmail] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        $contact = trim((string) $companyContact);
        if ($contact !== '' && preg_match(self::IN_TEXT, $contact, $match) === 1) {
            return $match[0];
        }
        return null;
    }
}
