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
 * Phase 7b adds (all optional, absent = nothing decided yet):
 *
 *     activities_po    list<string>   „Vykonané činnosti" ticked for the PO record
 *     activities_bozp  list<string>   the same for the BOZP record — never mixed
 *     previous         list<{key, kind, source_inspection_id, source_item_id,
 *                            status: null|ok|trva|zanik, reason}>
 *                                     what became of each defect of the last
 *                                     prehliadka (spec 6.5). `trva` is only
 *                                     real while a defect with
 *                                     `persists_from.key` = the entry's key exists
 *     term_overrides   map<key, Y-m-d> a hand-written date over an automatic
 *                                     term (spec 6.6, Q5: print and book only)
 *     present          list<{name, role}>   who was there (§ 14 ods. 3)
 *     handed_over      {name, role}|null    who took it over for the organisation
 *
 * A PATCH replaces the whole object, so every writer sends every key it read.
 *
 * The defects themselves are inspection items, see {@see PrehliadkaDefects}.
 */
final class PrehliadkaDetails
{
    public const TYPE = 'preventivna_prehliadka';

    /** Fat-finger guard, not a policy. */
    private const MAX_NEW_DEVICES = 200;
    private const MAX_ACTIVITIES = 60;
    private const MAX_PREVIOUS = 200;
    private const MAX_PRESENT = 40;
    private const MAX_OVERRIDES = 60;
    private const PREVIOUS_STATUSES = ['ok', 'trva', 'zanik'];
    private const PREVIOUS_KINDS = ['prehliadka', 'poziarna_kniha'];

    /**
     * The chip a list shows for a prehliadka: `po`, `bozp` or `po_bozp` — null
     * when the details carry no scope (not a prehliadka, or nothing chosen).
     *
     * @param array<string, mixed>|null $details
     */
    public static function scopeLabel(?array $details): ?string
    {
        $scope = is_array($details['scope'] ?? null) ? $details['scope'] : [];
        $po = ($scope['po'] ?? false) === true;
        $bozp = ($scope['bozp'] ?? false) === true;
        return match (true) {
            $po && $bozp => 'po_bozp',
            $po          => 'po',
            $bozp        => 'bozp',
            default      => null,
        };
    }

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
            // A set belongs to its record: unticking PO in the setup drops the
            // PO activities with it instead of leaving them to print nowhere.
            'activities_po'   => $po ? self::activities($raw['activities_po'] ?? []) : [],
            'activities_bozp' => $bozp ? self::activities($raw['activities_bozp'] ?? []) : [],
            'previous'        => self::previous($raw['previous'] ?? []),
            'term_overrides'  => self::termOverrides($raw['term_overrides'] ?? []),
            'present'         => self::present($raw['present'] ?? []),
            'handed_over'     => self::person($raw['handed_over'] ?? null, 'Prevzal za organizáciu'),
        ];
    }

    /** @return list<string> */
    private static function activities(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw) || count($raw) > self::MAX_ACTIVITIES) {
            throw new \InvalidArgumentException('Zoznam vykonaných činností má nesprávny tvar.');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $label) {
            $label = self::text($label, PrehliadkaActivities::MAX_LABEL);
            if ($label === null || isset($seen[mb_strtolower($label)])) {
                continue;
            }
            $seen[mb_strtolower($label)] = true;
            $out[] = $label;
        }
        return $out;
    }

    /**
     * @return list<array{key: string, kind: string, source_inspection_id: int, source_item_id: ?int, status: ?string, reason: ?string}>
     */
    private static function previous(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw) || count($raw) > self::MAX_PREVIOUS) {
            throw new \InvalidArgumentException('Zoznam nedostatkov z minulej prehliadky má nesprávny tvar.');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Zoznam nedostatkov z minulej prehliadky má nesprávny tvar.');
            }
            $key = $row['key'] ?? null;
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $key)) {
                throw new \InvalidArgumentException('Nedostatok z minulej prehliadky nemá platný identifikátor.');
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $kind = $row['kind'] ?? 'prehliadka';
            $sourceInspection = $row['source_inspection_id'] ?? null;
            if (!is_string($kind) || !in_array($kind, self::PREVIOUS_KINDS, true)
                || !is_int($sourceInspection) || $sourceInspection <= 0) {
                throw new \InvalidArgumentException('Nedostatok z minulej prehliadky má neplatný pôvod.');
            }
            $sourceItem = $row['source_item_id'] ?? null;
            $status = $row['status'] ?? null;
            if ($status !== null && (!is_string($status) || !in_array($status, self::PREVIOUS_STATUSES, true))) {
                throw new \InvalidArgumentException('Neplatný stav nedostatku z minulej prehliadky.');
            }
            $reason = self::text($row['reason'] ?? null, 500);
            // „Dôvod zanikol" is never saved without its one sentence (spec 6.5):
            // otherwise the record would read as if the client had put things right.
            if ($status === 'zanik' && $reason === null) {
                throw new \InvalidArgumentException('Napíš jednou vetou, prečo dôvod zanikol.');
            }
            $out[] = [
                'key'                  => $key,
                'kind'                 => $kind,
                'source_inspection_id' => $sourceInspection,
                'source_item_id'       => is_int($sourceItem) && $sourceItem > 0 ? $sourceItem : null,
                'status'               => $status,
                'reason'               => $status === 'zanik' ? $reason : null,
            ];
        }
        return $out;
    }

    /** @return array<string, string> */
    private static function termOverrides(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (!is_array($raw) || count($raw) > self::MAX_OVERRIDES) {
            throw new \InvalidArgumentException('Prepísané termíny majú nesprávny tvar.');
        }
        $out = [];
        foreach ($raw as $key => $date) {
            if (!is_string($key) || !preg_match('/^[a-z_]+(:[A-Za-z0-9_.-]{1,60})?$/', $key)) {
                throw new \InvalidArgumentException('Prepísaný termín má neplatný kľúč.');
            }
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('Prepísaný termín nie je platný dátum.');
            }
            $out[$key] = $date;
        }
        return $out;
    }

    /** @return list<array{name: string, role: ?string}> */
    private static function present(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (!is_array($raw) || count($raw) > self::MAX_PRESENT) {
            throw new \InvalidArgumentException('Zoznam prítomných osôb má nesprávny tvar.');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            $person = self::person($row, 'Prítomná osoba');
            if ($person === null || isset($seen[mb_strtolower($person['name'])])) {
                continue;
            }
            $seen[mb_strtolower($person['name'])] = true;
            $out[] = $person;
        }
        return $out;
    }

    /** @return array{name: string, role: ?string}|null */
    private static function person(mixed $raw, string $what): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("$what má nesprávny tvar.");
        }
        $name = self::text($raw['name'] ?? null, 191);
        if ($name === null) {
            // A row with no name at all is the empty line a form leaves behind.
            return null;
        }
        return ['name' => $name, 'role' => self::text($raw['role'] ?? null, 191)];
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
