<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * The „osoby" úkony of block 2 — chapters 7, 8 and 8.1.
 *
 *   dychova_skuska   Dychová skúška na alkohol            DS-RRRR-NNN
 *   omamne_latky     Kontrola omamných a psych. látok     OPL-RRRR-NNN
 *   skolenie_bozp    Oboznámenie zamestnancov v o. BOZP   SKB-RRRR-NNN
 *
 * All three are a list of people with a header above it, so they share one
 * shape: every person is an inspection_items row, the header (device, test
 * kit, druh oboznámenia, …) sits in inspections.details. This class is the
 * single place that knows what a valid row and a valid header look like, what
 * must be filled in before a protocol can be issued, and what carries over to
 * the next test.
 *
 * Wording and code lists are verbatim from 05_DATA (ciselniky.json,
 * typy_ukonov.json, texty_ui.json) — nothing here is invented.
 */
final class PersonList
{
    public const DYCHOVA_SKUSKA = 'dychova_skuska';
    public const OMAMNE_LATKY   = 'omamne_latky';
    public const SKOLENIE_BOZP  = 'skolenie_bozp';

    public const TYPES = [self::DYCHOVA_SKUSKA, self::OMAMNE_LATKY, self::SKOLENIE_BOZP];

    /** The two tests that can be printed blank for handwriting (chapter 8.1). */
    public const TEST_TYPES = [self::DYCHOVA_SKUSKA, self::OMAMNE_LATKY];

    /** tlac_formulara — ciselniky.json. */
    public const VARIANT_FILLED = 'vyplneny';
    public const VARIANT_BLANK  = 'prazdny';
    public const VARIANTS = [self::VARIANT_FILLED, self::VARIANT_BLANK];

    /** stavy_poloziek.osoba_skuska — ciselniky.json. */
    public const RESULT_LABELS = [
        'negativny' => 'Negatívny',
        'pozitivny' => 'Pozitívny',
        'odmietol'  => 'Odmietol podrobiť sa skúške',
    ];

    /** typy_skolenia_bozp — ciselniky.json. `vlastne` takes a name of its own. */
    public const TRAINING_KINDS = [
        'vstupne'        => 'Vstupné oboznámenie zamestnanca',
        'opakovane'      => 'Opakované oboznámenie',
        'veduci'         => 'Oboznámenie vedúcich zamestnancov',
        'zastupca'       => 'Oboznámenie zástupcu zamestnancov pre bezpečnosť',
        'pri_zmene'      => 'Oboznámenie pri zmene technológie, prostriedku alebo postupu',
        'stavebne_prace' => 'Oboznámenie pri stavebných prácach',
        'dodavatel'      => 'Oboznámenie zamestnancov dodávateľa',
        'vlastne'        => 'Vlastný názov',
    ];

    /** odkazy_na_osnovu — ciselniky.json. Offered, never required verbatim. */
    public const CONTENT_REFERENCES = [
        'Obsah školenia podľa smernice o výchove a vzdelávaní zamestnávateľa.',
        'Obsah školenia podľa prílohy — osnova školenia.',
    ];

    /** Largest signature image accepted on one row (a PNG data URI). */
    private const MAX_SIGNATURE_BYTES = 200_000;

    public static function isPersonType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function isTestType(string $type): bool
    {
        return in_array($type, self::TEST_TYPES, true);
    }

    // ------------------------------------------------------------------
    // Rows
    // ------------------------------------------------------------------

    /**
     * Validate one person row. Only the name and the pracovné zaradenie are
     * required on save: the list is typed before the test, often days before,
     * and the blank form (chapter 8.1) is printed from exactly that. The
     * result becomes mandatory when the FILLED protocol is issued
     * ({@see missingForProtocol()}).
     *
     * `defects` is the shared „Zistené nedostatky" block (Firol\Support\Defects),
     * validated by that class.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function validateRow(string $type, array $body): array
    {
        $out = [
            'name'     => self::requiredString($body, 'name', 191, 'Doplň meno a priezvisko.'),
            'position' => self::requiredString($body, 'position', 191, 'Doplň pracovné zaradenie.'),
        ];

        if ($type === self::SKOLENIE_BOZP) {
            $out['date'] = self::optionalDate($body, 'date', 'Dátum účastníka musí byť v tvare RRRR-MM-DD.');
        } else {
            $time = self::optionalString($body, 'time', 5);
            if ($time !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new \InvalidArgumentException('Čas zadaj v tvare HH:MM.');
            }
            $out['time'] = $time;
            if ($type === self::DYCHOVA_SKUSKA) {
                // Free text, as read off the device — „0,00", „0,42". The
                // unit is printed by the protocol, not typed by the technician.
                $out['value'] = self::optionalString($body, 'value', 20);
            }
            $result = $body['result'] ?? null;
            if ($result === '' || $result === null) {
                $out['result'] = null;
            } elseif (is_string($result) && isset(self::RESULT_LABELS[$result])) {
                $out['result'] = $result;
            } else {
                throw new \InvalidArgumentException('Výsledok musí byť negatívny, pozitívny alebo odmietol.');
            }
        }

        $out['signature'] = self::optionalSignature($body['signature'] ?? null);

        // Chapter 7: „pri každom type sa naviac dá pridať nedostatok" — the
        // shared block, kept on the row it concerns (Firol\Support\Defects).
        $out[Defects::FIELD] = Defects::normalize($body[Defects::FIELD] ?? []);
        return $out;
    }

    /**
     * Carry-over (chapter 12, and the person takeover of chapters 7, 8, 8.1):
     * names and pracovné zaradenie only. Never a result, a time, a value or a
     * signature — those are this test's observations, and a signature copied
     * from last year would be a forgery.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null null when the row has no name to carry
     */
    public static function carryRow(string $type, array $fields): ?array
    {
        $name = trim((string) ($fields['name'] ?? $fields['fullname'] ?? ''));
        if ($name === '') {
            return null;
        }
        $position = trim((string) ($fields['position'] ?? ''));
        $out = ['name' => $name, 'position' => $position];
        if ($type === self::SKOLENIE_BOZP) {
            $out['date'] = null;
        } else {
            $out['time'] = null;
            if ($type === self::DYCHOVA_SKUSKA) {
                $out['value'] = null;
            }
            $out['result'] = null;
        }
        $out['signature'] = null;
        $out[Defects::FIELD] = [];
        return $out;
    }

    // ------------------------------------------------------------------
    // Header (inspections.details)
    // ------------------------------------------------------------------

    /**
     * Validate the header of one of the three types. Every field is optional
     * on save — a draft is saved as it is typed — and the required ones are
     * enforced at the summary and when the protocol is issued.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function validateDetails(string $type, array $body): array
    {
        return match ($type) {
            self::DYCHOVA_SKUSKA => [
                'device_type'          => self::optionalString($body, 'device_type', 191),
                'device_serial'        => self::optionalString($body, 'device_serial', 100),
                'calibration_valid_to' => self::optionalDate(
                    $body,
                    'calibration_valid_to',
                    'Platnosť kalibrácie musí byť dátum.',
                ),
                'measures'             => self::optionalString($body, 'measures', 4000),
            ],
            self::OMAMNE_LATKY => [
                'test_type' => self::optionalString($body, 'test_type', 191),
                'batch'     => self::optionalString($body, 'batch', 100),
                'expiry'    => self::optionalMonth($body, 'expiry'),
                'measures'  => self::optionalString($body, 'measures', 4000),
            ],
            self::SKOLENIE_BOZP => self::validateTrainingDetails($body),
            default => throw new \InvalidArgumentException('Tento typ úkonu nemá hlavičku.'),
        };
    }

    /**
     * What travels to the next test on „Opakovať": the device and the kind of
     * oboznámenie are the same next time far more often than not. Opatrenia
     * and the kit's šarža / exspirácia describe THIS test and stay behind.
     *
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>|null
     */
    public static function carryDetails(string $type, ?array $details): ?array
    {
        if ($details === null) {
            return null;
        }
        return match ($type) {
            self::DYCHOVA_SKUSKA => [
                'device_type'          => $details['device_type'] ?? null,
                'device_serial'        => $details['device_serial'] ?? null,
                'calibration_valid_to' => $details['calibration_valid_to'] ?? null,
                'measures'             => null,
            ],
            self::OMAMNE_LATKY => [
                'test_type' => $details['test_type'] ?? null,
                'batch'     => null,
                'expiry'    => null,
                'measures'  => null,
            ],
            self::SKOLENIE_BOZP => [
                'kind'         => $details['kind'] ?? null,
                'kind_custom'  => $details['kind_custom'] ?? null,
                'duration_min' => $details['duration_min'] ?? null,
                'content'      => $details['content'] ?? null,
            ],
            default => null,
        };
    }

    /**
     * Everything still missing before the protocol can be issued, as Slovak
     * sentences — empty when it is ready. Chapter 7: „povinné polia sa nedajú
     * obísť". The blank variant needs only the list itself (names and
     * positions), because everything else is written on it by hand.
     *
     * @param array<string, mixed>|null      $details
     * @param list<array<string, mixed>>     $items   each with `fields`
     * @return list<string>
     */
    public static function missingForProtocol(string $type, ?array $details, array $items, string $variant): array
    {
        $missing = [];
        $details ??= [];

        if ($items === []) {
            $missing[] = $type === self::SKOLENIE_BOZP
                ? 'Pridaj aspoň jedného účastníka.'
                : 'Pridaj aspoň jednu osobu.';
            return $missing;
        }

        $noName = 0;
        $noResult = 0;
        foreach ($items as $item) {
            $f = (array) ($item['fields'] ?? []);
            if (trim((string) ($f['name'] ?? '')) === '' || trim((string) ($f['position'] ?? '')) === '') {
                $noName++;
            }
            if (!isset(self::RESULT_LABELS[(string) ($f['result'] ?? '')])) {
                $noResult++;
            }
        }
        if ($noName > 0) {
            $missing[] = 'Pri ' . $noName . ' ' . ($noName === 1 ? 'osobe' : 'osobách')
                . ' chýba meno alebo pracovné zaradenie.';
        }

        if ($type === self::SKOLENIE_BOZP) {
            $kind = (string) ($details['kind'] ?? '');
            if (!isset(self::TRAINING_KINDS[$kind])) {
                $missing[] = 'Vyber druh oboznámenia.';
            } elseif ($kind === 'vlastne' && trim((string) ($details['kind_custom'] ?? '')) === '') {
                $missing[] = 'Doplň vlastný názov oboznámenia.';
            }
            if (trim((string) ($details['content'] ?? '')) === '') {
                $missing[] = 'Vyber alebo napíš obsah oboznámenia.';
            }
            return $missing;
        }

        if ($variant === self::VARIANT_BLANK) {
            return $missing;
        }

        $deviceKey = $type === self::DYCHOVA_SKUSKA ? 'device_type' : 'test_type';
        if (trim((string) ($details[$deviceKey] ?? '')) === '') {
            $missing[] = $type === self::DYCHOVA_SKUSKA
                ? 'Doplň typ prístroja.'
                : 'Doplň typ testu.';
        }
        if ($noResult > 0) {
            $missing[] = 'Pri ' . $noResult . ' ' . ($noResult === 1 ? 'osobe' : 'osobách')
                . ' chýba výsledok — doplň ho, alebo vygeneruj prázdny formulár na ručné doplnenie.';
        }
        return $missing;
    }

    /**
     * Counts for the summary screen, the protocol footer and the stats of
     * the generate response.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, int>
     */
    public static function stats(string $type, array $items): array
    {
        $stats = ['total' => count($items)];
        if ($type === self::SKOLENIE_BOZP) {
            return $stats;
        }
        $stats += ['negativny' => 0, 'pozitivny' => 0, 'odmietol' => 0, 'bez_vysledku' => 0];
        foreach ($items as $item) {
            $r = (string) (($item['fields'] ?? [])['result'] ?? '');
            if (isset(self::RESULT_LABELS[$r])) {
                $stats[$r]++;
            } else {
                $stats['bez_vysledku']++;
            }
        }
        return $stats;
    }

    /** Printed name of the oboznámenie — the code list entry or the technician's own. */
    public static function trainingKindLabel(?array $details): string
    {
        $kind = (string) ($details['kind'] ?? '');
        if ($kind === 'vlastne') {
            return trim((string) ($details['kind_custom'] ?? ''));
        }
        return self::TRAINING_KINDS[$kind] ?? '';
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private static function validateTrainingDetails(array $body): array
    {
        $kind = $body['kind'] ?? null;
        if ($kind === '' || $kind === null) {
            $kind = null;
        } elseif (!is_string($kind) || !isset(self::TRAINING_KINDS[$kind])) {
            throw new \InvalidArgumentException('Neznámy druh oboznámenia.');
        }

        $duration = $body['duration_min'] ?? null;
        if ($duration === '' || $duration === null) {
            $duration = null;
        } else {
            if (is_string($duration) && ctype_digit($duration)) {
                $duration = (int) $duration;
            }
            if (!is_int($duration) || $duration < 1 || $duration > 6000) {
                throw new \InvalidArgumentException('Časový rozsah zadaj v celých minútach.');
            }
        }

        return [
            'kind'         => $kind,
            'kind_custom'  => $kind === 'vlastne' ? self::optionalString($body, 'kind_custom', 191) : null,
            'duration_min' => $duration,
            'content'      => self::optionalString($body, 'content', 2000),
        ];
    }

    /** @param array<string, mixed> $body */
    private static function requiredString(array $body, string $key, int $max, string $message): string
    {
        $value = self::optionalString($body, $key, $max);
        if ($value === null) {
            throw new \InvalidArgumentException($message);
        }
        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalString(array $body, string $key, int $max): ?string
    {
        $raw = $body[$key] ?? null;
        if ($raw === null) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Neplatná hodnota poľa.');
        }
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException('Text je príliš dlhý (najviac ' . $max . ' znakov).');
        }
        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalDate(array $body, string $key, string $message): ?string
    {
        $value = self::optionalString($body, $key, 10);
        if ($value === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($message);
        }
        return $value;
    }

    /**
     * Exspirácia of a test kit is printed on it as a month („11/2027"), so a
     * month is accepted; a full date is kept as typed.
     *
     * @param array<string, mixed> $body
     */
    private static function optionalMonth(array $body, string $key): ?string
    {
        $value = self::optionalString($body, $key, 10);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return $value;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d !== false && $d->format('Y-m-d') === $value) {
            return $value;
        }
        throw new \InvalidArgumentException('Exspiráciu zadaj ako mesiac a rok.');
    }

    /**
     * A signature drawn on the screen, stored as a PNG data URI on the row.
     * Kept on the row rather than as a file: the row is then complete on its
     * own — offline outbox, backup and restore carry it without a second
     * upload channel — and a downscaled signature is a few kilobytes.
     */
    private static function optionalSignature(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !str_starts_with($raw, 'data:image/png;base64,')) {
            throw new \InvalidArgumentException('Podpis musí byť obrázok PNG.');
        }
        if (strlen($raw) > self::MAX_SIGNATURE_BYTES) {
            throw new \InvalidArgumentException('Podpis je príliš veľký.');
        }
        $bytes = base64_decode(substr($raw, strlen('data:image/png;base64,')), true);
        if ($bytes === false || !str_starts_with($bytes, "\x89PNG")) {
            throw new \InvalidArgumentException('Podpis musí byť obrázok PNG.');
        }
        return $raw;
    }

    /** „11/2027" from „2027-11", „3. 4. 2027" from a full date. */
    public static function formatMonthOrDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
            return (int) $m[2] . '/' . $m[1];
        }
        $ts = strtotime($value);
        return $ts ? date('j. n. Y', $ts) : $value;
    }
}
