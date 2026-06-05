<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Splits a free-text Slovak address into its structured parts and joins them
 * back. Companies and facilities store the address as three columns
 * (street, postal_code, city), but the UI keeps a single combined "Adresa"
 * field — so the API parses on write and re-assembles on read, and PDF
 * protocols can pull the city ("obec") for the "Miesto a dátum" line without
 * re-parsing free text at render time.
 *
 * Expected shape: "Ulica 12, 851 01 Mesto". Parsing is best-effort and
 * lossy-tolerant: anything we cannot confidently classify lands in `street`,
 * so format(parse($x)) round-trips the common cases without dropping text.
 */
final class Address
{
    /**
     * @return array{street: ?string, postal_code: ?string, city: ?string}
     */
    public static function parse(?string $combined): array
    {
        $combined = self::clean((string) $combined);
        if ($combined === '') {
            return ['street' => null, 'postal_code' => null, 'city' => null];
        }

        $street = null;
        $postal = null;
        $city   = null;

        // Slovak PSČ: three digits + two digits, optionally space-separated.
        // When present it cleanly delimits the street (before) from the city
        // (after), regardless of comma placement.
        if (preg_match('/(\d{3})\s?(\d{2})/u', $combined, $m, PREG_OFFSET_CAPTURE)) {
            $postal = $m[1][0] . ' ' . $m[2][0];
            $street = self::clean((string) substr($combined, 0, (int) $m[0][1]));
            $city   = self::clean((string) substr($combined, (int) $m[0][1] + strlen($m[0][0])));
        } elseif (($pos = mb_strrpos($combined, ',')) !== false) {
            // No PSČ — fall back to "Street, City" on the last comma.
            $street = self::clean(mb_substr($combined, 0, $pos));
            $city   = self::clean(mb_substr($combined, $pos + 1));
        } else {
            // Single, ambiguous token — keep it as street, leave city empty.
            $street = $combined;
        }

        return [
            'street'      => $street !== '' ? $street : null,
            'postal_code' => $postal,
            'city'        => $city !== null && $city !== '' ? $city : null,
        ];
    }

    /**
     * Normalises an incoming write. The edit forms send the structured parts
     * directly; older/offline/import clients may still send a single combined
     * `address` string, which we parse. Structured input wins when any part is
     * present.
     *
     * @return array{street: ?string, postal_code: ?string, city: ?string}
     */
    public static function resolve(?string $street, ?string $postalCode, ?string $city, ?string $combined): array
    {
        if ($street !== null || $postalCode !== null || $city !== null) {
            return [
                'street'      => self::nullIfBlank($street),
                'postal_code' => self::nullIfBlank($postalCode),
                'city'        => self::nullIfBlank($city),
            ];
        }
        return self::parse($combined);
    }

    /**
     * Re-assembles the structured parts into the single combined string the UI
     * displays. Returns null when every part is empty.
     */
    public static function format(?string $street, ?string $postalCode, ?string $city): ?string
    {
        $street   = trim((string) $street);
        $locality = trim(trim((string) $postalCode) . ' ' . trim((string) $city));

        $parts = array_filter([$street, $locality], static fn (string $p): bool => $p !== '');
        $out   = implode(', ', $parts);

        return $out !== '' ? $out : null;
    }

    private static function nullIfBlank(?string $s): ?string
    {
        $s = trim((string) $s);
        return $s !== '' ? $s : null;
    }

    private static function clean(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s, " \t\n\r\0\x0B,;");
    }
}
