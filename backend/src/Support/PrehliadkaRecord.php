<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\InspectionPhotoController;
use Firol\Db;

/**
 * What a closed preventívna prehliadka prints (spec 6.7, 7.1, 7.2, plan phase 8a).
 *
 * The walk-through is stored as inspection items (the defects) and
 * `inspections.details` (setup, activities, answers about the previous defects,
 * term overrides, who was present). This class turns that into the content of
 * the two records it yields:
 *
 *   po    the Záznam o vykonaní preventívnej protipožiarnej prehliadky (PPP-…)
 *   bozp  the zápis do knihy BOZP, which is also the BOZP record (spec 9.5a)
 *
 * Points. One printed „bod" per `group_key` — the rows saved with „Uložiť a ďalšie
 * zariadenie" are one defect on several devices or places — numbered 1…n inside
 * its record. A defect of scope `ob` is a point of both records, `po` / `bo` of
 * one. The numbers are what the photo captions and the „trvá — prechádza do
 * bodu N" cells refer to.
 *
 * Frozen copy. A protocol re-rendered with the client's signature must say what
 * it said when it was issued, so the parts that read live data (the Termíny
 * table, the previous-defect table, the facility's description and main
 * switches, the book entry number) are copied into `details.issued_record` at
 * issue time ({@see ISSUED_KEY}). The key is server-owned: the header validator
 * does not keep it, so unlocking and saving drops the copy.
 */
final class PrehliadkaRecord
{
    public const PO = 'po';
    public const BOZP = 'bozp';

    /** Key of the frozen block inside `inspections.details`. */
    public const ISSUED_KEY = 'issued_record';

    /** Fixed wording of the sample record (2_Vzor_hotoveho_zaznamu.pdf). */
    public const TITLE = 'Záznam o vykonaní preventívnej protipožiarnej prehliadky';
    public const TITLE_DOM_SUFFIX = 'bytového domu';
    public const SCOPE_FIRMA = 'PPP bola vykonaná vo všetkých pracoviskách v pôsobnosti spoločnosti.';
    public const SCOPE_DOM = 'PPP bola vykonaná iba v spoločných priestoroch a v priestoroch technického zabezpečenia '
        . 'a jej predmetom neboli priestory, ktorých vlastníkmi sú užívatelia jednotlivých bytov, resp. komerčných '
        . 'priestorov (pivničné kobky, byty, komerčné priestory).';
    public const CHECKED = 'Uvedené bolo v čase prehliadky skontrolované.';
    public const CHECKED_DOM = 'Priestory, ktorých vlastníkmi sú užívatelia jednotlivých bytov, neboli predmetom prehliadky.';
    public const NO_DEFECTS = 'V čase prehliadky neboli zistené nedostatky.';
    public const NO_OTHER_DEFECTS = 'Iné nedostatky neboli zistené.';
    public const RESPONSIBLE_DOM = 'správca objektu';
    public const RESPONSIBLE_FIRMA = 'prevádzkovateľ';
    public const CUSTOM_ACTIVITY_TAG = '(vlastná činnosť)';

    /** Scopes of a defect that belong to each record. */
    private const RECORD_SCOPES = [self::PO => ['po', 'ob'], self::BOZP => ['bo', 'ob']];

    /** Cap of the short row a point leaves in the generic `defects` list of a kniha BOZP item. */
    private const SHORT_TEXT = 500;

    // ── Points ───────────────────────────────────────────────────────────────

    /**
     * The printed points of one record, numbered from 1.
     *
     * @param list<array{id: int, fields: array<string, mixed>}> $items ordered by position
     * @param array<int, int> $photoCounts item id → number of photos
     * @return list<array<string, mixed>>
     */
    public static function points(array $items, string $record, array $photoCounts = []): array
    {
        $accepted = self::RECORD_SCOPES[$record] ?? [];
        $groups = [];
        foreach ($items as $item) {
            $f = (array) ($item['fields'] ?? []);
            if (!is_string($f['title'] ?? null)) {
                continue;
            }
            $scope = in_array($f['scope'] ?? null, PrehliadkaDefects::SCOPES, true) ? $f['scope'] : 'po';
            if (!in_array($scope, $accepted, true)) {
                continue;
            }
            $group = is_string($f['group_key'] ?? null) && $f['group_key'] !== ''
                ? 'g:' . $f['group_key']
                : 'i:' . (int) $item['id'];
            $groups[$group][] = ['id' => (int) $item['id'], 'f' => $f];
        }

        $points = [];
        $n = 0;
        foreach ($groups as $rows) {
            $points[] = self::point(++$n, $rows, $photoCounts);
        }
        return $points;
    }

    /**
     * @param list<array{id: int, f: array<string, mixed>}> $rows
     * @param array<int, int> $photoCounts
     * @return array<string, mixed>
     */
    private static function point(int $number, array $rows, array $photoCounts): array
    {
        $first = $rows[0]['f'];
        $itemIds = array_map(static fn (array $r): int => $r['id'], $rows);

        $devices = [];
        $serialsOnly = [];
        $anySerial = false;
        foreach ($rows as $r) {
            foreach ((array) ($r['f']['devices'] ?? []) as $d) {
                if (!is_array($d) || !is_string($d['label'] ?? null) || $d['label'] === '') {
                    continue;
                }
                $serial = is_string($d['serial'] ?? null) && $d['serial'] !== '' ? $d['serial'] : null;
                $anySerial = $anySerial || $serial !== null;
                // A PHP's label *is* its serial — say it once, and join several as
                // "v. č. A, B" rather than "X, v. č. X; Y, v. č. Y".
                if ($serial === $d['label']) {
                    $serialsOnly[$serial] = true;
                    continue;
                }
                $text = $d['label'] . ($serial !== null ? ', v. č. ' . $serial : '');
                $devices[$text] = true;
            }
            $manual = $r['f']['device_manual'] ?? null;
            if (is_string($manual) && trim($manual) !== '') {
                $devices[trim($manual)] = true;
            }
        }
        if ($serialsOnly !== []) {
            $anySerial = true;
            $devices['v. č. ' . implode(', ', array_keys($serialsOnly))] = true;
        }

        $persistKeys = [];
        foreach ($rows as $r) {
            $key = is_array($r['f']['persists_from'] ?? null) ? ($r['f']['persists_from']['key'] ?? null) : null;
            if (is_string($key) && $key !== '') {
                $persistKeys[] = $key;
            }
        }

        // Earliest deadline of the point; „bezodkladne" outranks any date.
        $immediate = false;
        $date = null;
        foreach ($rows as $r) {
            if (($r['f']['deadline_choice'] ?? null) === 'bezodkladne') {
                $immediate = true;
            }
            $d = $r['f']['deadline_date'] ?? null;
            if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($date === null || $d < $date)) {
                $date = $d;
            }
        }

        $responsible = null;
        foreach ($rows as $r) {
            $v = $r['f']['responsible'] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $responsible = trim($v);
                break;
            }
        }

        $photos = 0;
        foreach ($itemIds as $id) {
            $photos += $photoCounts[$id] ?? 0;
        }

        return [
            'number'        => $number,
            'title'         => (string) $first['title'],
            'persists'      => $persistKeys !== [],
            'persists_keys' => $persistKeys,
            'where'         => self::mergeWhere(array_map(static fn (array $r): array => (array) ($r['f']['where'] ?? []), $rows)),
            'devices'       => implode($anySerial ? '; ' : ', ', array_keys($devices)),
            'photos'        => $photos,
            'description'   => (string) ($first['description'] ?? ''),
            'regulation'    => (string) ($first['regulation'] ?? ''),
            'justification' => (string) ($first['justification'] ?? ''),
            'measure'       => (string) ($first['measure'] ?? ''),
            'deadline_date' => $immediate ? null : $date,
            'deadline_text' => $immediate ? 'bezodkladne' : ($date !== null ? 'do ' . BookSentences::dateSk($date) : ''),
            'responsible'   => $responsible,
            'item_ids'      => $itemIds,
        ];
    }

    /**
     * One „kde" line for the rows of a point. A point on several floors of one
     * entrance reads „Vchod 2 · 4. NP, 3. NP a 2. NP · chodba" (the sample); when
     * more than one component differs the pairs would be lost by merging, so the
     * rows are listed one after another instead.
     *
     * @param list<array<string, mixed>> $wheres
     */
    private static function mergeWhere(array $wheres): string
    {
        $components = ['entrance', 'floor', 'place', 'detail'];
        $varying = 0;
        $values = [];
        foreach ($components as $c) {
            $perRow = array_map(
                static fn (array $w): ?string => is_string($w[$c] ?? null) && trim($w[$c]) !== '' ? trim($w[$c]) : null,
                $wheres,
            );
            if (count(array_unique($perRow, SORT_REGULAR)) > 1) {
                $varying++;
            }
            $values[$c] = array_values(array_unique(array_filter($perRow, static fn (?string $v): bool => $v !== null)));
        }

        if ($varying <= 1) {
            $parts = [];
            foreach ($components as $c) {
                if ($values[$c] !== []) {
                    $parts[] = self::joinList($values[$c]);
                }
            }
            return implode(' · ', $parts);
        }

        $texts = [];
        foreach ($wheres as $w) {
            $t = is_string($w['text'] ?? null) ? trim($w['text']) : '';
            if ($t !== '') {
                $texts[$t] = true;
            }
        }
        return implode('; ', array_keys($texts));
    }

    /** „a, b a c". */
    private static function joinList(array $values): string
    {
        $values = array_values($values);
        if (count($values) <= 1) {
            return (string) ($values[0] ?? '');
        }
        $last = array_pop($values);
        return implode(', ', $values) . ' a ' . $last;
    }

    /**
     * Photos per item of one inspection — the „N fotografie v prílohe" figure.
     *
     * @return array<int, int>
     */
    public static function photoCounts(int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT item_id, COUNT(*) AS n FROM inspection_item_photos WHERE inspection_id = ? GROUP BY item_id'
        );
        $stmt->execute([$inspectionId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['item_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** Number of printed points of a record — what a book entry says „Zistených nedostatkov". */
    public static function pointCount(int $inspectionId, string $record): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fields FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$inspectionId]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = ['id' => (int) $r['id'], 'fields' => json_decode((string) $r['fields'], true) ?: []];
        }
        return count(self::points($items, $record));
    }

    /**
     * The photo appendix of a record, captioned „Nedostatok č. N — názov" with
     * the number the point has in THAT record.
     *
     * @param list<array{id: int, fields: array<string, mixed>}> $items items of the prehliadka
     * @return list<array{caption: string, path: string, width: int, height: int}>
     */
    public static function photoAppendix(int $inspectionId, string $record, array $items): array
    {
        $paths = InspectionPhotoController::fullSizePathsByItem($inspectionId);
        if ($paths === []) {
            return [];
        }
        $out = [];
        foreach (self::points($items, $record) as $p) {
            $caption = PhotoCaption::buildForDefect($p['number'], $p['title']);
            foreach ($p['item_ids'] as $itemId) {
                foreach ($paths[$itemId] ?? [] as $photo) {
                    $out[] = [
                        'caption' => $caption,
                        'path'    => $photo['path'],
                        'width'   => $photo['width'],
                        'height'  => $photo['height'],
                    ];
                }
            }
        }
        return $out;
    }

    // ── Previous defects ─────────────────────────────────────────────────────

    /**
     * The „Nedostatky z predchádzajúcej prehliadky" table of one record: every
     * defect of the last prehliadka that belongs to it, with what became of it.
     *
     * @param array<string, mixed> $details validated PrehliadkaDetails
     * @param array{source: ?array<string, mixed>, defects: list<array<string, mixed>>} $previous PrehliadkaPrevious
     * @param list<array<string, mixed>> $points the points of this record
     * @return list<array{title: string, meta: string, date: ?string, status: string, text: string}>
     */
    public static function previousRows(array $details, array $previous, string $record, array $points): array
    {
        $accepted = self::RECORD_SCOPES[$record] ?? [];
        $answers = [];
        foreach ((array) ($details['previous'] ?? []) as $e) {
            if (is_array($e) && is_string($e['key'] ?? null)) {
                $answers[$e['key']] = $e;
            }
        }
        $carried = [];
        foreach ($points as $p) {
            foreach ($p['persists_keys'] as $key) {
                $carried[$key] = $p['number'];
            }
        }
        $date = is_string($previous['source']['executed_on'] ?? null) ? $previous['source']['executed_on'] : null;

        $rows = [];
        foreach ($previous['defects'] as $d) {
            if (!in_array($d['scope'] ?? 'po', $accepted, true)) {
                continue;
            }
            $answer = $answers[$d['key']] ?? null;
            $status = $answer['status'] ?? null;
            $point = null;
            if ($status === 'trva') {
                $point = $carried[$d['key']] ?? null;
                if ($point === null) {
                    // „Trvá" is only real while the defect carried over exists.
                    $status = null;
                }
            }

            $meta = [];
            if (is_string($d['where_text'] ?? null) && $d['where_text'] !== '') {
                $meta[] = $d['where_text'];
            }
            $deviceNames = [];
            foreach ((array) ($d['devices'] ?? []) as $dev) {
                if (is_array($dev) && is_string($dev['label'] ?? null) && $dev['label'] !== '') {
                    $deviceNames[] = $dev['label'];
                }
            }
            if (is_string($d['device_manual'] ?? null) && $d['device_manual'] !== '') {
                $deviceNames[] = $d['device_manual'];
            }
            if ($deviceNames !== []) {
                $meta[] = implode(', ', $deviceNames);
            }

            $reason = trim((string) ($answer['reason'] ?? ''));
            [$state, $text] = match ($status) {
                'ok'    => ['ok', 'odstránené'],
                'trva'  => ['trva', 'trvá'],
                'zanik' => ['zanik', 'dôvod zanikol' . ($reason !== '' ? "\n" . $reason : '')],
                default => ['waiting', 'neoverené'],
            };
            $rows[] = [
                'title'  => (string) $d['title'],
                'meta'   => implode(' · ', $meta),
                'date'   => $date,
                'status' => $state,
                'text'   => $text,
            ];
        }
        return $rows;
    }

    // ── Terms ────────────────────────────────────────────────────────────────

    /**
     * The terms this prehliadka puts into one record by itself — the next
     * prehliadka, every ticked riadna kontrola of that book and the trainings
     * of the book — each with the hand-written date when it was overridden
     * (spec 6.6, Q5). The keys are the ones step 3 of the prehliadka writes
     * `term_overrides` under.
     *
     * @param array<string, mixed> $inspection the loaded inspection row
     * @param array<string, mixed> $details
     * @param array{trainings: list<array<string, mixed>>, routine_periodicities: array<string, array{value: ?int, unit: ?string}>} $facts
     * @return list<array{key: string, label: string, done: ?string, valid: ?string, overridden: bool}>
     */
    public static function autoTerms(array $inspection, array $details, string $record, array $facts): array
    {
        $on = (string) $inspection['executed_on'];
        $overrides = (array) ($details['term_overrides'] ?? []);
        $lehotaZakon = isset($details['lehota_zakon']) ? (int) $details['lehota_zakon'] : 12;
        $lehotaVlastna = isset($details['lehota_vlastna']) && $details['lehota_vlastna'] !== null ? (int) $details['lehota_vlastna'] : null;
        $effectiveMonths = ($lehotaVlastna !== null && $lehotaVlastna > 0) ? $lehotaVlastna : $lehotaZakon;
        $own = [
            'value' => $effectiveMonths ?: ($inspection['periodicity_value'] !== null ? (int) $inspection['periodicity_value'] : null),
            'unit'  => 'mesiac',
        ];

        $rows = [];
        $key = $record === self::PO ? 'prehliadka_po' : 'prehliadka_bozp';
        $override = $overrides[$key] ?? null;
        $rows[] = [
            'key'        => $key,
            'label'      => $record === self::PO ? ClientTerms::LABELS['preventivna_prehliadka'] : 'Preventívna prehliadka BOZP',
            'done'       => $on,
            'valid'      => $override ?? Periodicity::validUntil($on, $own['value'], $own['unit']),
            'overridden' => $override !== null,
        ];

        foreach ((array) ($details['routine'] ?? []) as $type) {
            if (!is_string($type) || BookMapping::forInspectionType($type) !== $record) {
                continue;
            }
            $p = $facts['routine_periodicities'][$type] ?? ['value' => null, 'unit' => null];
            $override = $overrides['routine:' . $type] ?? null;
            $rows[] = [
                'key'        => 'routine:' . $type,
                'label'      => ClientTerms::LABELS[$type] ?? $type,
                'done'       => $on,
                'valid'      => $override ?? Periodicity::validUntil($on, $p['value'], $p['unit']),
                'overridden' => $override !== null,
            ];
        }

        foreach ($facts['trainings'] as $t) {
            if (($t['book'] ?? null) !== $record) {
                continue;
            }
            $override = $overrides[(string) $t['key']] ?? null;
            $rows[] = [
                'key'        => (string) $t['key'],
                'label'      => (string) $t['label'],
                'done'       => (string) $t['done_on'],
                'valid'      => $override ?? ($t['valid_until'] ?? null),
                'overridden' => $override !== null || !empty($t['overridden']),
            ];
        }
        return $rows;
    }

    // ── Activities ───────────────────────────────────────────────────────────

    /**
     * „Vykonané činnosti" of the PO record, the firm's own ones tagged. The
     * defaults come first in their own order, then the rest as ticked.
     *
     * @param array<string, mixed> $details
     * @return list<array{label: string, custom: bool}>
     */
    public static function poActivities(array $details): array
    {
        $ticked = array_values(array_filter((array) ($details['activities_po'] ?? []), 'is_string'));
        [$defaults, $custom] = self::splitActivities($ticked, PrehliadkaActivities::defaults(PrehliadkaActivities::PO));
        $out = [];
        foreach ($defaults as $label) {
            $out[] = ['label' => $label, 'custom' => false];
        }
        foreach ($custom as $label) {
            $out[] = ['label' => $label, 'custom' => true];
        }
        return $out;
    }

    /**
     * Ticked activities split into the predefined ones (in the checklist's own
     * order, whatever order they were ticked in) and the firm's own.
     *
     * @param list<string> $ticked
     * @param list<string> $defaults
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function splitActivities(array $ticked, array $defaults): array
    {
        $folded = static fn (string $s): string => mb_strtolower(trim($s));
        $tickedSet = array_map($folded, $ticked);
        $known = array_values(array_filter($defaults, static fn (string $d): bool => in_array($folded($d), $tickedSet, true)));
        $defaultSet = array_map($folded, $defaults);
        $custom = array_values(array_filter($ticked, static fn (string $t): bool => !in_array($folded($t), $defaultSet, true)));
        return [$known, $custom];
    }

    // ── Facility ─────────────────────────────────────────────────────────────

    /**
     * What the record reads off the prevádzka beyond its name and address.
     *
     * @return array{kind: string, description: ?string, switches: list<array{label: string, value: string}>}
     */
    public static function facilityBlock(int $accountId, int $facilityId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT f.description, f.main_power_location, f.main_gas_location, f.main_water_location, c.client_type
             FROM   facilities f JOIN companies c ON c.id = f.company_id
             WHERE  f.id = ? AND f.account_id = ?'
        );
        $stmt->execute([$facilityId, $accountId]);
        $r = $stmt->fetch() ?: [];
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $switches = [];
        foreach ([
            'Hlavný vypínač elektriny' => $text($r['main_power_location'] ?? null),
            'Hlavný uzáver plynu'      => $text($r['main_gas_location'] ?? null),
            'Hlavný uzáver vody'       => $text($r['main_water_location'] ?? null),
        ] as $label => $value) {
            if ($value !== null) {
                $switches[] = ['label' => $label, 'value' => $value];
            }
        }
        return [
            'kind'        => ClientKind::isBytovyDom((string) ($r['client_type'] ?? '')) ? 'dom' : 'firma',
            'description' => $text($r['description'] ?? null),
            'switches'    => $switches,
        ];
    }

    // ── The PO record ────────────────────────────────────────────────────────

    /**
     * The `prehliadka` block the PPP template prints.
     *
     * @param array<string, mixed> $inspection the loaded inspection row
     * @param list<array{id: int, fields: array<string, mixed>}> $items
     * @param array<string, mixed> $details validated PrehliadkaDetails
     * @param array<string, mixed>|null $issued the frozen block, when re-rendering an issued record
     * @param int|null $bookSeq number of the PO book entry created together with the record
     * @return array<string, mixed>
     */
    public static function poBlock(
        int $accountId,
        array $inspection,
        array $items,
        array $details,
        bool $includePhotos,
        ?array $issued,
        ?int $bookSeq,
    ): array {
        $facilityId = (int) $inspection['facility_id'];
        $points = self::points($items, self::PO, self::photoCounts((int) $inspection['id']));
        $note = $details['note'] ?? ($details['poznamka'] ?? null);
        $lehotaZakon = $details['lehota_zakon'] ?? null;
        $lehotaVlastna = $details['lehota_vlastna'] ?? null;

        if (is_array($issued)) {
            $terms = (array) ($issued['terms'] ?? []);
            $previous = (array) ($issued['previous'] ?? []);
            $facility = (array) ($issued['facility'] ?? self::facilityBlock($accountId, $facilityId));
            $note = $issued['note'] ?? $note;
            $lehotaZakon = $issued['lehota_zakon'] ?? $lehotaZakon;
            $lehotaVlastna = $issued['lehota_vlastna'] ?? $lehotaVlastna;
            $bookSeq = isset($issued['book_seq']) && is_int($issued['book_seq']) ? $issued['book_seq'] : null;
        } else {
            $facts = PrehliadkaTerms::forFacility($accountId, $facilityId, (string) $inspection['executed_on']);
            $terms = ClientTerms::forPrehliadka(
                $accountId,
                (int) $inspection['company_id'],
                $facilityId,
                (string) $inspection['executed_on'],
                BookMapping::PO,
                self::autoTerms($inspection, $details, self::PO, $facts),
            );
            $previous = self::previousRows(
                $details,
                PrehliadkaPrevious::forFacility($accountId, $facilityId, (int) $inspection['id']),
                self::PO,
                $points,
            );
            $facility = self::facilityBlock($accountId, $facilityId);
        }

        return [
            'kind'           => $facility['kind'],
            'description'    => $facility['description'],
            'note'           => $note,
            'lehota_zakon'   => $lehotaZakon,
            'lehota_vlastna' => $lehotaVlastna,
            'switches'       => $facility['switches'],
            'activities'     => self::poActivities($details),
            'points'         => $points,
            'previous'       => $previous,
            'terms'          => $terms,
            'present'        => (array) ($details['present'] ?? []),
            'handed_over'    => is_array($details['handed_over'] ?? null) ? $details['handed_over'] : null,
            'book_seq'       => $bookSeq,
            'include_photos' => $includePhotos,
            // What re-rendering needs to say the same thing again.
            '_issued'        => [
                'terms'          => $terms,
                'previous'       => $previous,
                'facility'       => $facility,
                'note'           => $note,
                'lehota_zakon'   => $lehotaZakon,
                'lehota_vlastna' => $lehotaVlastna,
                'book_seq'       => $bookSeq,
            ],
        ];
    }

    /**
     * Frozen copy onto the prehliadka row, next to the issued contractor.
     *
     * @param array<string, mixed> $issued the `_issued` part of {@see poBlock()}
     */
    public static function freeze(int $inspectionId, array $issued): void
    {
        $stmt = Db::pdo()->prepare('SELECT details FROM inspections WHERE id = ?');
        $stmt->execute([$inspectionId]);
        $details = InspectionDetails::decode($stmt->fetchColumn() ?: null) ?? [];
        $details[self::ISSUED_KEY] = $issued;
        Db::pdo()->prepare('UPDATE inspections SET details = ? WHERE id = ?')
            ->execute([InspectionDetails::encode($details), $inspectionId]);
    }

    /** @return array<string, mixed>|null */
    public static function frozen(?array $details): ?array
    {
        $frozen = is_array($details) ? ($details[self::ISSUED_KEY] ?? null) : null;
        return is_array($frozen) ? $frozen : null;
    }

    // ── The BOZP record ──────────────────────────────────────────────────────

    /**
     * The `fields` of the kniha BOZP item that is the BOZP record of a
     * prehliadka. Beside the usual keys (BozpRecords) it carries the structured
     * points and the previous-defect table under `prehliadka`, which the
     * kniha_bozp template prints instead of the plain defect list. `defects`
     * keeps one short row per point, so everything that reads that list
     * (counts, Dnes) sees them.
     *
     * @param array<string, mixed> $inspection the loaded prehliadka row
     * @param array<string, mixed> $details validated PrehliadkaDetails
     * @param list<array<string, mixed>> $previousRows {@see previousRows()} of the BOZP record
     * @param list<array<string, mixed>> $points {@see points()} of the BOZP record
     * @return array<string, mixed>
     */
    public static function bozpFields(array $inspection, array $details, array $previousRows, array $points): array
    {
        $ticked = array_values(array_filter((array) ($details['activities_bozp'] ?? []), 'is_string'));
        [$activities, $custom] = self::splitActivities($ticked, BozpRecords::knihaActivities());

        $defects = [];
        foreach ($points as $p) {
            $where = $p['where'] !== '' ? ' — ' . $p['where'] : '';
            $deadline = $p['deadline_date'];
            $defects[] = [
                'key'         => 'pp' . $p['item_ids'][0],
                'description' => self::cut($p['title'] . $where, self::SHORT_TEXT),
                'measure'     => $p['measure'] !== '' ? self::cut($p['measure'], self::SHORT_TEXT) : null,
                'deadline'    => $deadline,
            ];
        }

        return [
            'workspaces'        => [(string) $inspection['facility_name']],
            'activities'        => $activities,
            'custom_activities' => $custom,
            'result'            => $points === [] ? 'bez_nedostatkov' : 'zistene_nedostatky',
            'defects'           => $defects,
            'prehliadka'        => [
                'source_inspection_id' => (int) $inspection['id'],
                'points'               => $points,
                'previous'             => $previousRows,
                'handed_over'          => is_array($details['handed_over'] ?? null) ? $details['handed_over'] : null,
                'note'                 => $details['note'] ?? ($details['poznamka'] ?? null),
                'lehota_zakon'         => $details['lehota_zakon'] ?? null,
                'lehota_vlastna'       => $details['lehota_vlastna'] ?? null,
            ],
        ];
    }

    /**
     * The defects a book entry written by a prehliadka carries, one short line
     * each, for the entry's print: the PO entry shows the PO record's points, the
     * BOZP entry those of the kniha BOZP record. Empty for any other entry.
     *
     * @param array<string, mixed> $entry a BookLedger entry
     * @return list<string>
     */
    public static function bookLines(int $accountId, array $entry): array
    {
        $ids = array_values(array_filter((array) ($entry['inspection_ids'] ?? []), 'is_int'));
        if ($ids === []) {
            return [];
        }
        $record = (string) ($entry['book'] ?? '') === BookMapping::BOZP ? self::BOZP : self::PO;

        $stmt = Db::pdo()->prepare(
            'SELECT id, type, source_inspection_id FROM inspections WHERE id = ? AND account_id = ?'
        );
        $lines = [];
        foreach ($ids as $id) {
            $stmt->execute([$id, $accountId]);
            $row = $stmt->fetch();
            if (!$row) {
                continue;
            }
            $sourceId = null;
            if ($row['type'] === PrehliadkaDetails::TYPE && $record === self::PO) {
                $sourceId = (int) $row['id'];
            } elseif ($row['type'] === 'kniha_bozp' && $record === self::BOZP && $row['source_inspection_id'] !== null) {
                $sourceId = (int) $row['source_inspection_id'];
            }
            if ($sourceId === null) {
                continue;
            }
            $items = [];
            $it = Db::pdo()->prepare('SELECT id, fields FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC');
            $it->execute([$sourceId]);
            foreach ($it->fetchAll() as $r) {
                $items[] = ['id' => (int) $r['id'], 'fields' => json_decode((string) $r['fields'], true) ?: []];
            }
            foreach (self::points($items, $record) as $p) {
                $where = array_filter([$p['where'], $p['devices']], static fn (string $s): bool => $s !== '');
                $tail = array_filter([$p['deadline_text'], (string) ($p['responsible'] ?? '')], static fn (string $s): bool => $s !== '');
                $lines[] = $p['number'] . '. ' . $p['title']
                    . ($where !== [] ? ' — ' . implode(' · ', $where) : '')
                    . ($tail !== [] ? ' (' . implode(' · ', $tail) . ')' : '');
            }
        }
        return $lines;
    }

    /** „Ing. Peter Správca, predseda družstva" — name and role of a person of `details`. */
    public static function signerName(array $person): string
    {
        $name = trim((string) ($person['name'] ?? ''));
        $role = trim((string) ($person['role'] ?? ''));
        return $role !== '' ? $name . ', ' . $role : $name;
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
