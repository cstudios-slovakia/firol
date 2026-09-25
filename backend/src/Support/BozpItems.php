<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * The item-list BOZP úkony of block 2 — chapters 5.3, 5.4 and 7:
 *
 *   oopp                  Kontrola OOPP                         (tabulka)
 *   pracovne_prostriedky  Kontrola pracovných prostriedkov      (tabulka)
 *   rebriky               Kontrola rebríkov                     (polozky)
 *   regale                Kontrola regálov                      (polozky)
 *   oznacenie             Kontrola bezpečnostného označenia     (riadky)
 *
 * Every row of every one of them is an `inspection_items` row, whether the
 * spec calls the pattern `polozky` or `tabulka`: a row is what a photo and a
 * nedostatok hang off (chapter 7, „pri každom type sa dá pridať nedostatok
 * s fotkami"), and it is what carries over to next year (chapter 12).
 *
 * What is NOT a row — the záver of an OOPP check, the opatrenia of the others —
 * lives on the úkon in `inspections.details` and is validated here too, so the
 * shape of one type is readable in one place.
 *
 * Field sets are exactly the ones chapter 7 lists; `*` there is `required`
 * here. Messages are Slovak because they reach the technician verbatim.
 */
final class BozpItems
{
    /** @var list<string> */
    public const TYPES = ['oopp', 'pracovne_prostriedky', 'rebriky', 'regale', 'oznacenie'];

    /** Chapter 5.4 — `rebrik` / `regal`. */
    public const RESULTS_WITH_DISPOSAL = ['vyhovuje', 'nevyhovuje', 'vyradene'];

    /** Chapter 7 — pracovné prostriedky and označenie know no „vyradené". */
    public const RESULTS_PASS_FAIL = ['vyhovuje', 'nevyhovuje'];

    /** Chapter 5.4 — `oopp`. */
    public const OOPP_CONDITIONS = ['vyhovujuci', 'opotrebeny', 'chyba'];

    /** Labels as ciselniky.json spells them (`stavy_poloziek`). */
    public const RESULT_LABELS = [
        'vyhovuje'   => 'Vyhovuje',
        'nevyhovuje' => 'Nevyhovuje',
        'vyradene'   => 'Vyradené',
    ];

    public const OOPP_CONDITION_LABELS = [
        'vyhovujuci' => 'Vyhovujúci',
        'opotrebeny' => 'Opotrebený',
        'chyba'      => 'Chýba',
    ];

    /** Upper bound on repeatable opatrenia rows — a fat-finger guard only. */
    private const MAX_MEASURES = 50;

    public static function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Validate one row off the wire and return its canonical fields.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function validate(string $type, array $body): array
    {
        $fields = match ($type) {
            'rebriky' => [
                'inventory_number' => self::text($body, 'inventory_number', 'Inventárne číslo', true, 80),
                'type'             => self::text($body, 'type', 'Typ rebríka', true, 120),
                'manufacturer'     => self::text($body, 'manufacturer', 'Výrobca', false, 80),
                'year'             => self::year($body),
                'location'         => self::text($body, 'location', 'Umiestnenie', true, 191),
                'faults'           => self::text($body, 'faults', 'Zistené závady', false, 500),
                'result'           => self::choice($body, 'result', 'Výsledok', self::RESULTS_WITH_DISPOSAL),
            ],
            'regale' => [
                'label'           => self::text($body, 'label', 'Označenie regálu', true, 80),
                'type'            => self::text($body, 'type', 'Typ regálu', true, 120),
                'capacity'        => self::text($body, 'capacity', 'Nosnosť', true, 80),
                'location'        => self::text($body, 'location', 'Umiestnenie', true, 191),
                'capacity_marked' => self::yesNo($body, 'capacity_marked', 'Označenie nosnosti', true),
                'faults'          => self::text($body, 'faults', 'Zistené závady', false, 500),
                'result'          => self::choice($body, 'result', 'Výsledok', self::RESULTS_WITH_DISPOSAL),
            ],
            'oopp' => [
                'position'  => self::text($body, 'position', 'Pracovná pozícia', true, 120),
                'equipment' => self::text($body, 'equipment', 'Pridelené OOPP', true, 300),
                'provided'  => self::yesNo($body, 'provided', 'Poskytnuté', true),
                'used'      => self::yesNo($body, 'used', 'Používané', false),
                'condition' => self::optionalChoice($body, 'condition', 'Stav', self::OOPP_CONDITIONS),
                'notes'     => self::text($body, 'notes', 'Poznámka', false, 500),
            ],
            'pracovne_prostriedky' => [
                'name'              => self::text($body, 'name', 'Názov', true, 150),
                'manufacturer_type' => self::text($body, 'manufacturer_type', 'Výrobca a typ', false, 150),
                'inventory_number'  => self::text($body, 'inventory_number', 'Inventárne číslo', false, 80),
                'location'          => self::text($body, 'location', 'Umiestnenie', true, 191),
                'faults'            => self::text($body, 'faults', 'Zistené závady', false, 500),
                'result'            => self::choice($body, 'result', 'Výsledok', self::RESULTS_PASS_FAIL),
            ],
            'oznacenie' => [
                'kind'     => self::text($body, 'kind', 'Druh označenia', true, 150),
                'location' => self::text($body, 'location', 'Umiestnenie', true, 191),
                'result'   => self::choice($body, 'result', 'Stav', self::RESULTS_PASS_FAIL),
                'notes'    => self::text($body, 'notes', 'Poznámka', false, 500),
            ],
            default => throw new \InvalidArgumentException('Neznámy typ kontroly.'),
        };

        // Chapter 7 — „pri každom type sa naviac dá pridať nedostatok". A
        // nedostatok is recorded on the row it concerns, so its photos are
        // that row's photos (Firol\Support\Defects).
        $fields[Defects::FIELD] = Defects::normalize($body[Defects::FIELD] ?? []);
        return $fields;
    }

    /**
     * Úkon-level fields of step 2 that belong to no single row
     * (`inspections.details`):
     *
     *   oopp                          záver (text)
     *   rebriky, regale               opatrenia (text)
     *   pracovne_prostriedky,
     *   oznacenie                     opatrenia (repeatable: opatrenie, termín)
     *
     * A row of opatrenia with nothing written in it is dropped rather than
     * refused — an empty row the technician added and never filled is not an
     * error worth a message. Its termín is a date or „ihneď", which is how the
     * delivered protocol mockup writes an immediate one.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function validateDetails(string $type, array $details): array
    {
        return match ($type) {
            'oopp' => [
                'conclusion' => self::text($details, 'conclusion', 'Záver', false, 3000),
            ],
            'rebriky', 'regale' => [
                'measures_text' => self::text($details, 'measures_text', 'Opatrenia', false, 3000),
            ],
            'pracovne_prostriedky', 'oznacenie' => [
                'measures' => self::measures($details['measures'] ?? []),
            ],
            default => throw new \InvalidArgumentException('Tento typ kontroly nemá doplňujúce údaje.'),
        };
    }

    /**
     * Rows whose required assessment is still blank. Only reachable for rows
     * carried over from last time (chapter 12 carries identification and
     * leaves the verdict empty) — a row typed in fresh is refused by
     * validate() before it is stored. Issuing a protocol that prints an empty
     * výsledok over the technician's signature would claim a check nobody
     * recorded, so generation waits for them.
     *
     * @param list<array<string, mixed>> $items each with `fields`
     */
    public static function unassessedCount(string $type, array $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            $f = (array) ($item['fields'] ?? []);
            $missing = match ($type) {
                'oopp'   => !is_bool($f['provided'] ?? null),
                'regale' => !is_bool($f['capacity_marked'] ?? null)
                    || !in_array($f['result'] ?? null, self::RESULTS_WITH_DISPOSAL, true),
                'rebriky' => !in_array($f['result'] ?? null, self::RESULTS_WITH_DISPOSAL, true),
                'pracovne_prostriedky',
                'oznacenie' => !in_array($f['result'] ?? null, self::RESULTS_PASS_FAIL, true),
                default => false,
            };
            if ($missing) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Counts by stav for the protocol's „Súhrn výsledkov" and the API
     * response of generate-pdf. Always carries `total`.
     *
     * @param list<array<string, mixed>> $items each with `fields`
     * @return array<string, int>
     */
    public static function stats(string $type, array $items): array
    {
        $stats = ['total' => count($items)];
        if ($type === 'oopp') {
            $stats += [
                'provided'     => 0,
                'not_provided' => 0,
                'not_used'     => 0,
                'vyhovujuci'   => 0,
                'opotrebeny'   => 0,
                'chyba'        => 0,
            ];
            foreach ($items as $item) {
                $f = (array) ($item['fields'] ?? []);
                if (($f['provided'] ?? null) === true) {
                    $stats['provided']++;
                } elseif (($f['provided'] ?? null) === false) {
                    $stats['not_provided']++;
                }
                if (($f['used'] ?? null) === false) {
                    $stats['not_used']++;
                }
                $c = $f['condition'] ?? null;
                if (is_string($c) && isset($stats[$c])) {
                    $stats[$c]++;
                }
            }
            return $stats;
        }

        $stats += ['vyhovuje' => 0, 'nevyhovuje' => 0];
        if ($type === 'rebriky' || $type === 'regale') {
            $stats['vyradene'] = 0;
        }
        foreach ($items as $item) {
            $r = ((array) ($item['fields'] ?? []))['result'] ?? null;
            if (is_string($r) && isset($stats[$r]) && $r !== 'total') {
                $stats[$r]++;
            }
        }
        return $stats;
    }

    /**
     * @param mixed $raw
     * @return list<array{measure: string, deadline: ?string, immediately: bool}>
     */
    private static function measures(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('Opatrenia musia byť zoznam.');
        }
        if (count($raw) > self::MAX_MEASURES) {
            throw new \InvalidArgumentException('Opatrení môže byť najviac ' . self::MAX_MEASURES . '.');
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $measure = self::text($row, 'measure', 'Opatrenie', false, 1000);
            if ($measure === null) {
                continue;
            }
            $immediately = ($row['immediately'] ?? false) === true;
            $deadline = $row['deadline'] ?? null;
            if ($immediately || $deadline === '' || $deadline === null) {
                $deadline = null;
            } elseif (!is_string($deadline) || !self::isDate($deadline)) {
                throw new \InvalidArgumentException('Termín opatrenia musí byť dátum.');
            }
            $out[] = ['measure' => $measure, 'deadline' => $deadline, 'immediately' => $immediately];
        }
        return $out;
    }

    /** @param array<string, mixed> $body */
    private static function text(array $body, string $key, string $label, bool $required, int $max): ?string
    {
        $raw = $body[$key] ?? null;
        $value = is_string($raw) ? trim($raw) : (is_int($raw) || is_float($raw) ? (string) $raw : '');
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException('Vyplň pole „' . $label . '".');
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException('Pole „' . $label . '" môže mať najviac ' . $max . ' znakov.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $allowed
     */
    private static function choice(array $body, string $key, string $label, array $allowed): string
    {
        $raw = $body[$key] ?? null;
        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            throw new \InvalidArgumentException('Vyber pole „' . $label . '".');
        }
        return $raw;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $allowed
     */
    private static function optionalChoice(array $body, string $key, string $label, array $allowed): ?string
    {
        $raw = $body[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            throw new \InvalidArgumentException('Neplatná hodnota v poli „' . $label . '".');
        }
        return $raw;
    }

    /** @param array<string, mixed> $body */
    private static function yesNo(array $body, string $key, string $label, bool $required): ?bool
    {
        $raw = $body[$key] ?? null;
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            if ($required) {
                throw new \InvalidArgumentException('Vyber pole „' . $label . '" (áno / nie).');
            }
            return null;
        }
        throw new \InvalidArgumentException('Pole „' . $label . '" má hodnotu áno alebo nie.');
    }

    /** Rok výroby — optional; a ladder of unknown age is ordinary. */
    private static function year(array $body): ?int
    {
        $raw = $body['year'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }
        if (!is_int($raw) || $raw < 1900 || $raw > 2200) {
            throw new \InvalidArgumentException('Rok výroby musí byť rok (napr. 2019).');
        }
        return $raw;
    }

    private static function isDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y);
    }
}
