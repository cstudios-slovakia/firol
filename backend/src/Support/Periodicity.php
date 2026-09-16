<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Periodicity of an inspection — a value plus a unit, or nothing at all.
 *
 * Block 1 / chapter 5. The rule the whole app is built around: the app
 * SUGGESTS a period, the technician decides it. Lehoty depend on the type of
 * building, its environment and the operator's own decision, so nothing here
 * may be presented as statutory — the phrase „zákonný termín" must not appear
 * anywhere in the UI or on a protocol.
 *
 * `null` value + `null` unit means „bez opakovania": the úkon happened once
 * and no next term follows from it.
 */
final class Periodicity
{
    public const UNITS = ['den', 'tyzden', 'mesiac'];

    /** Upper bound per unit — keeps a typo ("1200 weeks") out of the calendar. */
    private const MAX = ['den' => 3650, 'tyzden' => 520, 'mesiac' => 120];

    /**
     * Recommended values offered per inspection type, in the order they are
     * shown; the first one is preselected. An empty list means the type
     * defaults to „bez opakovania" — the technician can still set a period,
     * because no type has periodicity forbidden (chapter 5).
     *
     * Values are in months unless stated otherwise; the UI additionally offers
     * denne / týždenne and a free value in any unit.
     *
     * @var array<string, list<int>>
     */
    public const RECOMMENDED_MONTHS = [
        'php'                => [24, 12],
        'oprava_ts_php'      => [],
        'vyradenie'          => [],
        'hydranty'           => [12],
        'ts_hadic'           => [12],
        'poziarna_kniha'     => [12, 6, 3],
        'pu_akcieschopnost'  => [3, 6, 12],
        'pu_udrzba'          => [12],
        'nudzove_osvetlenie' => [12],
    ];

    /**
     * Normalise a (value, unit) pair coming off the wire.
     *
     * Returns [value, unit] with both null for „bez opakovania". Throws
     * InvalidArgumentException with a Slovak message when the pair is
     * contradictory or out of range — callers turn that into a 422.
     *
     * @return array{0: int|null, 1: string|null}
     */
    public static function normalize(mixed $value, mixed $unit): array
    {
        $hasValue = $value !== null && $value !== '';
        $hasUnit  = $unit !== null && $unit !== '';

        if (!$hasValue && !$hasUnit) {
            return [null, null];
        }
        if (!$hasValue || !$hasUnit) {
            throw new \InvalidArgumentException(
                'Periodicita potrebuje hodnotu aj jednotku, alebo nechaj „bez opakovania".',
            );
        }

        $unit = is_string($unit) ? $unit : '';
        if (!in_array($unit, self::UNITS, true)) {
            throw new \InvalidArgumentException('Neznáma jednotka periodicity.');
        }

        $int = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
        if ($int === null || $int < 1) {
            throw new \InvalidArgumentException('Periodicita musí byť celé číslo väčšie ako nula.');
        }
        if ($int > self::MAX[$unit]) {
            throw new \InvalidArgumentException(
                'Periodicita je príliš vysoká — najviac ' . self::MAX[$unit] . ' ' . self::unitPlural($int, $unit) . '.',
            );
        }

        return [$int, $unit];
    }

    /**
     * True when the pair is not one of the recommended values for this type —
     * stored on the úkon so a later report can tell a deliberate choice from
     * the default the app offered.
     */
    public static function isCustom(string $type, ?int $value, ?string $unit): bool
    {
        $recommended = self::RECOMMENDED_MONTHS[$type] ?? [];
        if ($value === null) {
            return $recommended !== [];
        }
        return $unit !== 'mesiac' || !in_array($value, $recommended, true);
    }

    /**
     * Date the úkon stays valid until — `platnost_do`. Null when there is no
     * recurrence, or when the úkon has no execution date yet.
     */
    public static function validUntil(?string $executedOn, ?int $value, ?string $unit): ?string
    {
        if ($executedOn === null || $executedOn === '' || $value === null || $unit === null) {
            return null;
        }
        $interval = match ($unit) {
            'den'    => '+' . $value . ' days',
            'tyzden' => '+' . $value . ' weeks',
            default  => '+' . $value . ' months',
        };
        try {
            return (new \DateTimeImmutable($executedOn))->modify($interval)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Human label for a protocol or a list row — „12 mesiacov", „2 týždne".
     * Without recurrence the app says „podľa potreby"; it never qualifies the
     * period as statutory or otherwise.
     */
    public static function label(?int $value, ?string $unit): string
    {
        if ($value === null || $unit === null) {
            return 'podľa potreby';
        }
        return $value . ' ' . self::unitPlural($value, $unit);
    }

    private static function unitPlural(int $n, string $unit): string
    {
        return match ($unit) {
            'den'    => $n === 1 ? 'deň' : ($n < 5 ? 'dni' : 'dní'),
            'tyzden' => $n === 1 ? 'týždeň' : ($n < 5 ? 'týždne' : 'týždňov'),
            default  => $n === 1 ? 'mesiac' : ($n < 5 ? 'mesiace' : 'mesiacov'),
        };
    }
}
