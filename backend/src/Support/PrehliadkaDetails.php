<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Header data of a preventívna prehliadka — `inspections.details` of the type
 * `preventivna_prehliadka` (plan decision D5, spec 6.1).
 *
 *     scope        { po: bool, bozp: bool }   which prehliadka is being done
 *     routine      list<type>                 riadne kontroly ticked in step 1
 *     book_entry   bool                       zápis do požiarnej knihy (PO only)
 *     new_devices  list<{tmp_id, type, label, serial, description, location}>
 *                                             devices written during the walk
 *                                             that the derived list does not
 *                                             know yet
 *
 * Later phases add their own keys (activities, previous defects, overrides);
 * this class is where they get validated.
 *
 * The defects themselves are inspection items, see {@see PrehliadkaDefects}.
 */
final class PrehliadkaDetails
{
    public const TYPE = 'preventivna_prehliadka';

    /** Fat-finger guard, not a policy. */
    private const MAX_NEW_DEVICES = 200;

    public static function supports(string $type): bool
    {
        return $type === self::TYPE;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function validate(array $raw): array
    {
        $scopeRaw = is_array($raw['scope'] ?? null) ? $raw['scope'] : [];
        $po = ($scopeRaw['po'] ?? false) === true;
        $bozp = ($scopeRaw['bozp'] ?? false) === true;
        if (!$po && !$bozp) {
            throw new \InvalidArgumentException(
                'Vyber, akú prehliadku robíš — protipožiarnu, BOZP alebo obidve.',
            );
        }

        $routine = [];
        $routineRaw = $raw['routine'] ?? [];
        if (!is_array($routineRaw)) {
            throw new \InvalidArgumentException('Riadne kontroly majú nesprávny tvar.');
        }
        foreach ($routineRaw as $type) {
            if (!is_string($type) || !in_array($type, FacilityDevices::TYPES, true)) {
                throw new \InvalidArgumentException('Neznámy typ riadnej kontroly.');
            }
            if (!in_array($type, $routine, true)) {
                $routine[] = $type;
            }
        }

        return [
            'scope'       => ['po' => $po, 'bozp' => $bozp],
            'routine'     => $routine,
            // The checkbox is about the požiarna kniha only; BOZP always
            // produces its own entry (spec 9.5a), so it never reads as a choice.
            'book_entry'  => $po && ($raw['book_entry'] ?? false) === true,
            'new_devices' => self::newDevices($raw['new_devices'] ?? []),
        ];
    }

    /**
     * @return list<array{tmp_id: string, type: string, label: string, serial: ?string, description: ?string, location: ?string}>
     */
    private static function newDevices(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw) || count($raw) > self::MAX_NEW_DEVICES) {
            throw new \InvalidArgumentException('Zoznam nových zariadení má nesprávny tvar.');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Zoznam nových zariadení má nesprávny tvar.');
            }
            $tmpId = $row['tmp_id'] ?? null;
            if (!is_string($tmpId) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $tmpId)) {
                throw new \InvalidArgumentException('Nové zariadenie nemá platný identifikátor.');
            }
            if (isset($seen[$tmpId])) {
                continue;
            }
            $seen[$tmpId] = true;

            $type = $row['type'] ?? null;
            if (!is_string($type) || !in_array($type, FacilityDevices::TYPES, true)) {
                throw new \InvalidArgumentException('Nové zariadenie má neznámy typ.');
            }
            $label = self::text($row['label'] ?? null, 80);
            if ($label === null) {
                throw new \InvalidArgumentException('Zadaj evidenčné číslo nového zariadenia.');
            }
            $out[] = [
                'tmp_id'      => $tmpId,
                'type'        => $type,
                'label'       => $label,
                'serial'      => self::text($row['serial'] ?? null, 80),
                'description' => self::text($row['description'] ?? null, 191),
                'location'    => self::text($row['location'] ?? null, 191),
            ];
        }
        return $out;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException("Text je príliš dlhý (najviac $max znakov).");
        }
        return $value;
    }
}
