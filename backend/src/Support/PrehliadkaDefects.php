<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * The defects of a preventívna prehliadka (spec 3.5, 6.3, plan Phase 7a).
 *
 * One defect is one `inspection_items` row of an inspection of the type
 * `preventivna_prehliadka`; its `fields`:
 *
 *     catalog_id        int|null   the číselník row it was picked from — only
 *                                  used to count „Najčastejšie"; the texts
 *                                  below are copies and never follow it
 *     title             string
 *     scope             po | bo | ob
 *     where             { entrance, floor, place, detail, text }
 *                                  `text` is the composed „Vchod 2 · 3. NP · chodba"
 *     devices           list of { type, source_item_id, label, serial, location }
 *                       or      { type, new_device_tmp_id, label, serial, location }
 *                                  the label / serial / location are a snapshot,
 *                                  so the printed record does not depend on a
 *                                  device list that moves on with later controls
 *     device_manual     string|null  zar_rucne, printed as typed
 *     description, regulation, justification, measure
 *     deadline_choice   bezodkladne | 30d | 3m | 6m | custom
 *     deadline_date     Y-m-d|null   a concrete date; null only for bezodkladne
 *     responsible       string|null
 *     group_key         string|null  shared by the rows saved with „Uložiť a ďalšie zariadenie"
 *     persists_from     null         reserved for phase 7b („Trvá")
 *
 * `deadline_date` is computed from the prehliadka date: here when a defect is
 * saved, and again by {@see recomputeDeadlines()} when that date changes.
 * A `custom` deadline is the technician's own date and is never recomputed.
 *
 * The same rules are mirrored in frontend/src/lib/prehliadka.ts.
 */
final class PrehliadkaDefects
{
    public const SCOPES = ['po', 'bo', 'ob'];

    public const DEADLINE_CHOICES = ['bezodkladne', '30d', '3m', '6m', 'custom'];

    private const MAX_DEVICES = 60;

    /**
     * @param array<string, mixed> $body
     * @param string|null $executedOn the prehliadka's date (Y-m-d)
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function validate(array $body, ?string $executedOn): array
    {
        $title = self::text($body['title'] ?? null, 255);
        if ($title === null) {
            throw new \InvalidArgumentException('Vyber, o aký nedostatok ide.');
        }

        $scope = $body['scope'] ?? 'po';
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('Neplatné určenie nedostatku (požiarny / BOZP / oboje).');
        }

        $where = self::where($body['where'] ?? null);

        $description = self::text($body['description'] ?? null, 2000);
        if ($description === null) {
            throw new \InvalidArgumentException('Doplň popis zisteného stavu.');
        }

        [$choice, $deadline] = self::deadline($body, $executedOn);

        return [
            'catalog_id'    => self::catalogId($body['catalog_id'] ?? null),
            'title'         => $title,
            'scope'         => $scope,
            'where'         => $where,
            'devices'       => self::devices($body['devices'] ?? []),
            'device_manual' => self::text($body['device_manual'] ?? null, 191),
            'description'   => $description,
            'regulation'    => self::text($body['regulation'] ?? null, 500) ?? '',
            'justification' => self::text($body['justification'] ?? null, 1000) ?? '',
            'measure'       => self::text($body['measure'] ?? null, 1000) ?? '',
            'deadline_choice' => $choice,
            'deadline_date' => $deadline,
            'responsible'   => self::text($body['responsible'] ?? null, 191),
            'group_key'     => self::key($body['group_key'] ?? null),
            'persists_from' => null,
        ];
    }

    /**
     * The concrete deadline of a choice, counted from the prehliadka date.
     * Months clamp to the end of a shorter month (31. 1. + 1 month = 28. 2.),
     * the same arithmetic as validUntil in lib/periodicity.ts.
     */
    public static function deadlineFor(string $choice, string $executedOn): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $executedOn);
        if ($date === false) {
            return null;
        }
        return match ($choice) {
            '30d'   => $date->modify('+30 days')->format('Y-m-d'),
            '3m'    => self::addMonths($date, 3)->format('Y-m-d'),
            '6m'    => self::addMonths($date, 6)->format('Y-m-d'),
            default => null,
        };
    }

    /**
     * The prehliadka date changed: every defect with a computed deadline moves
     * with it. `custom` ones keep the technician's date.
     */
    public static function recomputeDeadlines(int $inspectionId, string $executedOn): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, fields FROM inspection_items WHERE inspection_id = ?');
        $stmt->execute([$inspectionId]);
        $update = $pdo->prepare('UPDATE inspection_items SET fields = ? WHERE id = ?');
        foreach ($stmt->fetchAll() as $row) {
            $fields = json_decode((string) $row['fields'], true);
            if (!is_array($fields)) {
                continue;
            }
            $choice = $fields['deadline_choice'] ?? null;
            if (!is_string($choice) || !in_array($choice, ['30d', '3m', '6m'], true)) {
                continue;
            }
            $next = self::deadlineFor($choice, $executedOn);
            if ($next === null || ($fields['deadline_date'] ?? null) === $next) {
                continue;
            }
            $fields['deadline_date'] = $next;
            $update->execute([json_encode($fields, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: ?string}
     */
    private static function deadline(array $body, ?string $executedOn): array
    {
        $choice = $body['deadline_choice'] ?? null;
        if (!is_string($choice) || !in_array($choice, self::DEADLINE_CHOICES, true)) {
            throw new \InvalidArgumentException('Vyber lehotu na odstránenie nedostatku.');
        }
        if ($choice === 'bezodkladne') {
            return [$choice, null];
        }
        if ($choice === 'custom') {
            $raw = $body['deadline_date'] ?? null;
            if (!is_string($raw) || !self::isDate($raw)) {
                throw new \InvalidArgumentException('Zadaj vlastný dátum odstránenia nedostatku.');
            }
            return [$choice, $raw];
        }
        // A computed choice: the server's date wins over whatever the client
        // sent, so a queued write replayed after the date changed still lands
        // on the right day.
        $computed = $executedOn !== null ? self::deadlineFor($choice, $executedOn) : null;
        if ($computed !== null) {
            return [$choice, $computed];
        }
        $raw = $body['deadline_date'] ?? null;
        if (!is_string($raw) || !self::isDate($raw)) {
            throw new \InvalidArgumentException('Chýba dátum odstránenia nedostatku.');
        }
        return [$choice, $raw];
    }

    /**
     * @return array{entrance: ?string, floor: ?string, place: ?string, detail: ?string, text: string}
     */
    private static function where(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $entrance = self::text($raw['entrance'] ?? null, 120);
        $floor = self::text($raw['floor'] ?? null, 120);
        $place = self::text($raw['place'] ?? null, 120);
        $detail = self::text($raw['detail'] ?? null, 255);

        $text = implode(' · ', array_values(array_filter(
            [$entrance, $floor, $place, $detail],
            static fn (?string $p): bool => $p !== null,
        )));
        if ($text === '') {
            throw new \InvalidArgumentException(
                'Zadaj miesto nedostatku — vchod, podlažie, miesto alebo vlastný popis.',
            );
        }
        return [
            'entrance' => $entrance,
            'floor'    => $floor,
            'place'    => $place,
            'detail'   => $detail,
            'text'     => $text,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function devices(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw) || count($raw) > self::MAX_DEVICES) {
            throw new \InvalidArgumentException('Zoznam zariadení má nesprávny tvar.');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Zoznam zariadení má nesprávny tvar.');
            }
            $type = $row['type'] ?? null;
            if (!is_string($type) || !in_array($type, FacilityDevices::TYPES, true)) {
                throw new \InvalidArgumentException('Zariadenie má neznámy typ.');
            }
            $entry = ['type' => $type];
            $label = self::text($row['label'] ?? null, 80) ?? '';

            $source = $row['source_item_id'] ?? null;
            $tmp = self::key($row['new_device_tmp_id'] ?? null);
            if (is_int($source) && $source > 0) {
                $entry['source_item_id'] = $source;
                $ref = 's' . $source;
            } elseif ($tmp !== null) {
                $entry['new_device_tmp_id'] = $tmp;
                $ref = 'n' . $tmp;
            } elseif ($label !== '') {
                // A device whose list entry is gone (a restored backup could not
                // map it back): only the snapshot is left, and that still prints.
                $ref = 'l' . $type . '|' . mb_strtolower($label);
            } else {
                throw new \InvalidArgumentException('Zariadenie nemá platný odkaz.');
            }
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;

            $entry['label'] = $label;
            $entry['serial'] = self::text($row['serial'] ?? null, 80);
            $entry['location'] = self::text($row['location'] ?? null, 191);
            $out[] = $entry;
        }
        return $out;
    }

    private static function catalogId(mixed $raw): ?int
    {
        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }
        // A negative id is a catalogue row still waiting in the outbox; the
        // outbox rewrites it, anything that arrives like that is unknown.
        return is_int($raw) && $raw > 0 ? $raw : null;
    }

    private static function key(mixed $raw): ?string
    {
        if (!is_string($raw) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $raw)) {
            return null;
        }
        return $raw;
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $day = (int) $date->format('j');
        $first = $date->modify('first day of this month')->modify("+$months months");
        $last = (int) $first->format('t');
        return $first->setDate(
            (int) $first->format('Y'),
            (int) $first->format('n'),
            min($day, $last),
        );
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
