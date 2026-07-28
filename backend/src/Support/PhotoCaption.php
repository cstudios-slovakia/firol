<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Builds the caption printed under each photo in the PDF appendix
 * (change request 2.2), e.g.
 *
 *   "Položka č. 3 — P6, výr. č. 122345, kancelária 1 — skorodovaná spojka"
 *
 * The identification part is per inspection type because every type carries
 * different fields; the trailing part is whatever the technician wrote as the
 * item's note (or, for požiarna kniha, the recorded deficiencies), which is
 * what makes the photo self-explanatory to the client reading the protocol.
 */
final class PhotoCaption
{
    private const PU_KIND_LABELS = [
        'dvere'  => 'Požiarne dvere',
        'okno'   => 'Požiarne okno',
        'klapka' => 'Požiarna klapka',
    ];

    /**
     * @param array<string, mixed> $fields Item payload as stored in
     *                                     inspection_items.fields.
     * @param int $number 1-based position shown to the reader.
     */
    public static function build(string $type, array $fields, int $number): string
    {
        $identity = self::identity($type, $fields);
        $detail   = self::detail($type, $fields);

        $caption = 'Položka č. ' . $number;
        if ($identity !== '') {
            $caption .= ' — ' . $identity;
        }
        if ($detail !== '') {
            $caption .= ' — ' . $detail;
        }
        return $caption;
    }

    /** The "which thing is this" half of the caption. */
    private static function identity(string $type, array $fields): string
    {
        $parts = match ($type) {
            'php', 'oprava_ts_php' => [
                self::str($fields, 'type'),
                self::prefixed('výr. č. ', self::str($fields, 'serial')),
                self::str($fields, 'location'),
            ],
            'hydranty' => [
                self::hydrantType($fields),
                self::str($fields, 'location'),
            ],
            'pu_akcieschopnost', 'pu_udrzba' => [
                self::puKind($fields),
                self::str($fields, 'identifier'),
                self::str($fields, 'location'),
            ],
            'nudzove_osvetlenie' => [
                self::str($fields, 'luminaire_type'),
                self::prefixed('ev. č. ', self::str($fields, 'evid_number')),
                self::str($fields, 'location'),
            ],
            'ts_hadic' => [
                self::str($fields, 'hose_type'),
                self::str($fields, 'location'),
            ],
            'poziarna_kniha' => [
                self::str($fields, 'workspaces'),
            ],
            default => [],
        };

        return implode(', ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * The "what is wrong with it" half. Požiarna kniha keeps its findings in a
     * `defects` list rather than a single note, so it gets its own branch.
     */
    private static function detail(string $type, array $fields): string
    {
        if ($type === 'poziarna_kniha') {
            $descriptions = [];
            $defects = $fields['defects'] ?? [];
            if (is_array($defects)) {
                foreach ($defects as $d) {
                    if (is_array($d) && isset($d['description']) && is_string($d['description'])) {
                        $desc = trim($d['description']);
                        if ($desc !== '') {
                            $descriptions[] = $desc;
                        }
                    }
                }
            }
            if ($descriptions !== []) {
                return implode('; ', $descriptions);
            }
        }

        if ($type === 'hydranty') {
            $defects = self::str($fields, 'defects');
            if ($defects !== '') {
                return $defects;
            }
        }

        return self::str($fields, 'notes');
    }

    /** Hydrant type is an enum with an "other" escape hatch holding free text. */
    private static function hydrantType(array $fields): string
    {
        $type = self::str($fields, 'type');
        if ($type === 'other') {
            return self::str($fields, 'type_other');
        }
        return $type;
    }

    private static function puKind(array $fields): string
    {
        $kind = self::str($fields, 'kind');
        return self::PU_KIND_LABELS[$kind] ?? $kind;
    }

    private static function str(array $fields, string $key): string
    {
        $value = $fields[$key] ?? null;
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    private static function prefixed(string $prefix, string $value): string
    {
        return $value === '' ? '' : $prefix . $value;
    }
}
