<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\DocumentController;
use Firol\Controllers\InspectionController;
use Firol\Controllers\InspectionItemController;
use Firol\Db;

/**
 * The checklist after a preventívna prehliadka (spec 12.2, plan 4.3): one list
 * of everything the round gives rise to, ticked and closed in one go.
 *
 * What the list holds (customer, 13. 11. 2026):
 *
 *   - the concepts of the routine checks done in the round
 *     ({@see PrehliadkaConcepts}); their devices are carried from the last
 *     protocol of that kind at the prevádzka, the result is the technician's
 *     (prefilled from the defects of the prehliadka, as before);
 *   - the follow-up úkony of a routine check of the round once that check is
 *     closed: oprava a TS PHP and vyradenie with the devices of TODAY'S PHP
 *     check (the existing follow-up mappers of InspectionController), and
 *     tlaková skúška hadíc with one row per hose of today's hydranty check and
 *     the values of each hose — the result included — from the last ts_hadic
 *     protocol at that prevádzka.
 *
 * An entry without an earlier protocol to take values from cannot be ticked:
 * it stays a draft for the technician to fill in by hand (spec: „odvtedy sa už
 * preberá").
 *
 * Nothing is issued blind: {@see review()} hands the review screen every value
 * that goes into each ticked protocol, the technician edits them through the
 * ordinary item API, and only {@see generate()} issues — each úkon on its own,
 * so one that is incomplete or that the technician cannot sign stays a draft
 * and is reported with the reason while the others are issued.
 *
 * Where a draft's values came from is kept in its `details`, number and date
 * only (no ids, so a restored backup reads the same):
 *
 *     carried_from        {number: ?string, executed_on: ?string}|null
 *     carried_positions   list<int>   positions of the items that carry them
 *
 * A follow-up belongs to the round through `source_inspection_id` = the routine
 * concept it came from, exactly as a follow-up made on the úkon's own screen.
 */
final class PrehliadkaChecklist
{
    public const CARRIED_FROM_KEY = 'carried_from';
    public const CARRIED_POSITIONS_KEY = 'carried_positions';

    /** Routine check → the follow-ups it offers (the existing follow-up offer, change request 2.1). */
    private const FOLLOW_UPS = [
        'php'      => ['oprava_ts_php', 'vyradenie'],
        'hydranty' => ['ts_hadic'],
    ];

    /** Follow-ups whose devices come from today's check itself (decision 2). */
    private const FROM_TODAY = ['oprava_ts_php', 'vyradenie'];

    /** Follow-ups stored outside the statutory cycle — as InspectionController::followUp() does. */
    private const NON_CYCLIC = ['vyradenie'];

    /** What a hose takes over from the last tlaková skúška hadíc (decision 3): all but its identification. */
    private const TS_HADIC_VALUES = [
        'manufacturer', 'working_pressure', 'test_pressure', 'length', 'year_of_manufacture', 'result', 'notes',
    ];

    private const NOTE_NO_SOURCE = 'Bez predchádzajúceho protokolu — vyplň ručne.';
    private const NOTE_MANUAL = 'Vyplň ručne.';

    /**
     * Number and date of the protocol of an úkon — what „prevzaté z protokolu
     * č. … zo dňa …" prints. The number is null for an úkon without a document.
     *
     * @return array{number: ?string, executed_on: ?string}|null
     */
    public static function originOf(int $accountId, int $inspectionId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT i.executed_on,
                    (SELECT d.number FROM documents d
                      WHERE d.account_id = i.account_id AND d.parent_type = "inspection" AND d.parent_id = i.id
                      ORDER BY d.id DESC LIMIT 1) AS number
             FROM   inspections i
             WHERE  i.id = ? AND i.account_id = ?'
        );
        $stmt->execute([$inspectionId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'number'      => $row['number'] !== null ? (string) $row['number'] : null,
            'executed_on' => $row['executed_on'] !== null ? (string) $row['executed_on'] : null,
        ];
    }

    /**
     * Everything the round gives rise to, in the order of the routine checks:
     * their concepts first, the follow-ups of the closed ones after.
     *
     * key            `i{id}` for an existing úkon, `f{source id}:{type}` for a
     *                follow-up not created yet
     * selectable     can be ticked for „vytvoriť a rovno uzavrieť"
     * note           what to do when it cannot (Slovak), null otherwise
     * manual_create  a follow-up with nothing to take values from —
     *                {@see createManual()} creates its blank draft
     *
     * @param array<string, mixed> $prehliadka a row with id, facility_id
     * @return list<array<string, mixed>>
     */
    public static function entries(int $accountId, array $prehliadka): array
    {
        $concepts = PrehliadkaConcepts::conceptsOf($accountId, (int) $prehliadka['id']);
        $out = [];
        foreach ($concepts as $c) {
            $out[] = self::conceptEntry($accountId, (int) $prehliadka['facility_id'], $c);
        }
        foreach ($concepts as $c) {
            if ($c['status'] !== 'finalized') {
                continue; // decision 1: offered once the check it arises from is closed
            }
            foreach (self::FOLLOW_UPS[$c['type']] ?? [] as $target) {
                $entry = self::followUpEntry($accountId, $c['id'], $target);
                if ($entry !== null) {
                    $out[] = $entry;
                }
            }
        }
        return $out;
    }

    /**
     * Turn the ticked entries into draft úkony: an existing one is taken as it
     * is, a follow-up not created yet is created with its carried values and
     * the prehliadka's date. One the technician cannot sign is refused — as on
     * every create — and reported.
     *
     * @param array<string, mixed> $prehliadka
     * @param list<string> $keys
     * @return array{inspection_ids: list<int>, refused: list<array{key: string, type: ?string, reason: string}>}
     */
    public static function prepare(int $accountId, int $userId, array $prehliadka, array $keys): array
    {
        $byKey = [];
        foreach (self::entries($accountId, $prehliadka) as $e) {
            $byKey[$e['key']] = $e;
        }
        // In the order of the list, not of the taps — the review shows them so.
        $keys = array_values(array_unique($keys));
        $order = array_flip(array_keys($byKey));
        usort($keys, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        $ids = [];
        $refused = [];
        foreach ($keys as $key) {
            $entry = $byKey[$key] ?? self::createdMeanwhile($byKey, $key);
            if ($entry === null || !$entry['selectable']) {
                $refused[] = ['key' => $key, 'type' => $entry['type'] ?? null, 'reason' => 'Túto položku už nemožno uzavrieť týmto spôsobom.'];
                continue;
            }
            if ($entry['inspection_id'] !== null) {
                $ids[] = (int) $entry['inspection_id'];
                continue;
            }
            $created = self::createFollowUp($accountId, $userId, $prehliadka, (int) $entry['follow_up_of']['inspection_id'], (string) $entry['type']);
            if (isset($created['error'])) {
                $refused[] = ['key' => $key, 'type' => (string) $entry['type'], 'reason' => $created['error']];
                continue;
            }
            $ids[] = $created['inspection_id'];
        }
        return ['inspection_ids' => array_values(array_unique($ids)), 'refused' => $refused];
    }

    /**
     * The entry a follow-up key `f{source id}:{type}` stands for once that
     * follow-up exists (a retried prepare, a list not reloaded after „Späť"):
     * its key is `i{id}` by then.
     *
     * @param array<string, array<string, mixed>> $byKey
     * @return array<string, mixed>|null
     */
    private static function createdMeanwhile(array $byKey, string $key): ?array
    {
        if (preg_match('/^f(\d+):([a-z_]+)$/', $key, $m) !== 1) {
            return null;
        }
        foreach ($byKey as $e) {
            if ($e['kind'] === 'follow_up'
                && $e['inspection_id'] !== null
                && $e['type'] === $m[2]
                && (int) ($e['follow_up_of']['inspection_id'] ?? 0) === (int) $m[1]) {
                return $e;
            }
        }
        return null;
    }

    /**
     * The blank draft of a follow-up with nothing to take values from (a
     * tlaková skúška hadíc without an earlier protocol here, or none of whose
     * hoses is today's): today's hoses, no value and no result presumed, for
     * the technician to fill in by hand (spec 12.2).
     *
     * @param array<string, mixed> $prehliadka
     * @return array{inspection_id: int}|array{error: string, status: int, code?: string, cert?: string}
     */
    public static function createManual(int $accountId, int $userId, array $prehliadka, string $key): array
    {
        foreach (self::entries($accountId, $prehliadka) as $e) {
            if ($e['key'] === $key) {
                if (!$e['manual_create']) {
                    break;
                }
                $created = self::createFollowUp($accountId, $userId, $prehliadka, (int) $e['follow_up_of']['inspection_id'], (string) $e['type'], true);
                return isset($created['error']) ? $created + ['status' => 422] : $created;
            }
            if ($e['inspection_id'] !== null && self::createdMeanwhile([$e], $key) !== null) {
                return ['inspection_id' => (int) $e['inspection_id']]; // a second tap: open the one made
            }
        }
        return ['error' => 'Túto položku už nemožno vytvoriť týmto spôsobom.', 'status' => 409];
    }

    /**
     * What goes into each ticked protocol: the items with their values, which
     * of them were carried and from where, what is still missing, and whether
     * the technician can sign that kind of úkon.
     *
     * @param array<string, mixed> $prehliadka
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public static function review(int $accountId, array $prehliadka, array $ids): array
    {
        $round = self::roundEntries($accountId, $prehliadka);
        $blocks = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $entry = $round[$id] ?? null;
            if ($entry === null) {
                continue;
            }
            $row = self::inspectionRow($accountId, $id);
            if ($row === null) {
                continue;
            }
            if ($row['status'] === 'draft' && !$entry['selectable']) {
                continue; // nothing to take over: filled in by hand on its own screen
            }
            $type = (string) $row['type'];
            $items = DocumentController::loadItems($id);
            $carried = self::carriedPositions($type, InspectionDetails::decode($row['details'] ?? null), $items);

            $outItems = [];
            foreach ($items as $item) {
                $fields = is_array($item['fields']) ? $item['fields'] : [];
                $outItems[] = [
                    'id'       => $item['id'],
                    'position' => $item['position'],
                    'fields'   => $fields,
                    'name'     => self::itemName($type, $fields, $item['position']),
                    'missing'  => $row['status'] === 'draft' ? InspectionItemController::missingFields($type, $fields) : [],
                    'carried'  => in_array($item['position'], $carried, true),
                ];
            }
            $cert = $row['status'] === 'draft'
                ? CertGuard::missingForType($accountId, (int) $row['inspector_user_id'], $type)
                : null;

            $blocks[] = [
                'inspection_id' => $id,
                'type'          => $type,
                'status'        => (string) $row['status'],
                'executed_on'   => $row['executed_on'],
                'visit_id'      => $row['visit_id'] !== null ? (int) $row['visit_id'] : null,
                'origin'        => $entry['origin'],
                'follow_up_of'  => $entry['follow_up_of'],
                'document'      => $entry['document'],
                'cert_missing'  => $cert['error'] ?? null,
                'items'         => $outItems,
            ];
        }
        return $blocks;
    }

    /**
     * Issue the protocols of the ticked úkony, each on its own. `$executedOn`
     * is the date the technician saw on the review screen (decision 4); an úkon
     * of a visit keeps the visit's date (chapter 9).
     *
     * A concept is confirmed here — the technician has just seen and accepted
     * its values — and the confirmation is taken back when the protocol is not
     * issued, so the draft is left exactly as it was.
     *
     * @param array<string, mixed> $prehliadka
     * @param list<int> $ids
     * @return array{generated: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public static function generate(int $accountId, int $userId, array $prehliadka, array $ids, ?string $executedOn): array
    {
        $round = self::roundEntries($accountId, $prehliadka);
        $pdo = Db::pdo();
        $generated = [];
        $skipped = [];

        foreach (array_values(array_unique($ids)) as $id) {
            if (!isset($round[$id])) {
                $skipped[] = ['inspection_id' => $id, 'type' => null, 'reason' => 'Úkon nepatrí k tejto prehliadke.'];
                continue;
            }
            // One issue at a time per úkon: a second device or tab sending the
            // same batch waits here and then finds the protocol issued, instead
            // of taking a second number for it.
            $lock = 'firol-checklist-generate-' . $accountId . '-' . $id;
            $got = $pdo->prepare('SELECT GET_LOCK(?, 15)');
            $got->execute([$lock]);
            if ((int) $got->fetchColumn() !== 1) {
                $skipped[] = ['inspection_id' => $id, 'type' => (string) $round[$id]['type'], 'reason' => 'Protokol sa práve vystavuje na inom zariadení.'];
                continue;
            }
            try {
                $outcome = self::generateOne($accountId, $userId, $prehliadka, $round[$id], $id, $executedOn);
            } finally {
                $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
            }
            if (isset($outcome['document'])) {
                $generated[] = $outcome;
            } else {
                $skipped[] = $outcome;
            }
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Issue one úkon of the batch. Whatever goes wrong — a refusal or an
     * exception — the draft is left as it was and the reason comes back, so
     * the rest of the batch still runs and what was issued is still reported.
     *
     * @param array<string, mixed> $prehliadka
     * @param array<string, mixed> $entry its entry of the checklist
     * @return array<string, mixed> generated: {inspection_id, type, document}; skipped: {inspection_id, type, reason, …}
     */
    private static function generateOne(int $accountId, int $userId, array $prehliadka, array $entry, int $id, ?string $executedOn): array
    {
        $pdo = Db::pdo();
        // Read again under the lock: another request may have issued it meanwhile.
        $row = self::inspectionRow($accountId, $id);
        if ($row === null) {
            return ['inspection_id' => $id, 'type' => null, 'reason' => 'Úkon nepatrí k tejto prehliadke.'];
        }
        $type = (string) $row['type'];
        $skip = static fn (string $reason, array $extra = []): array => ['inspection_id' => $id, 'type' => $type, 'reason' => $reason] + $extra;

        if ($row['status'] !== 'draft') {
            return $skip('Protokol už je vystavený.');
        }
        if (!$entry['selectable']) {
            // Nothing to take over (spec 12.2): it stays a draft, filled in by hand.
            return $skip((string) ($entry['note'] ?? self::NOTE_MANUAL));
        }

        $oldDate = $row['executed_on'] !== null ? (string) $row['executed_on'] : null;
        $details = InspectionDetails::decode($row['details'] ?? null);
        $confirm = PrehliadkaConcepts::isPending($details);
        $setDate = $pdo->prepare('UPDATE inspections SET executed_on = ? WHERE id = ? AND account_id = ?');
        $setDetails = $pdo->prepare('UPDATE inspections SET details = ? WHERE id = ? AND account_id = ?');
        $dateSet = false;
        $confirmSet = false;
        $restore = static function () use (&$dateSet, &$confirmSet, $oldDate, $details, $setDate, $setDetails, $id, $accountId): void {
            if ($dateSet) {
                $setDate->execute([$oldDate, $id, $accountId]);
            }
            if ($confirmSet) {
                $setDetails->execute([InspectionDetails::encode($details), $id, $accountId]);
            }
        };

        try {
            $items = DocumentController::loadItems($id);
            if ($items === []) {
                return $skip('Úkon nemá žiadne položky — pridaj aspoň jednu.');
            }
            $cert = CertGuard::missingForType($accountId, (int) $row['inspector_user_id'], $type);
            if ($cert !== null) {
                return $skip($cert['error'], ['code' => 'cert_missing', 'cert' => $cert['cert']]);
            }
            $incomplete = PrehliadkaConcepts::incompleteItems($type, $items);
            if ($incomplete !== []) {
                return $skip(PrehliadkaConcepts::incompleteMessage($incomplete), ['incomplete' => $incomplete]);
            }

            $date = $row['visit_id'] === null && $executedOn !== null ? $executedOn : $oldDate;
            if ($date === null) {
                return $skip('Doplň dátum vykonania.');
            }
            if ($date !== $oldDate) {
                $setDate->execute([$date, $id, $accountId]);
                $dateSet = true;
            }
            if ($confirm) {
                $confirmed = $details;
                $confirmed[PrehliadkaConcepts::CONFIRMED_KEY] = date('Y-m-d H:i:s');
                $setDetails->execute([InspectionDetails::encode($confirmed), $id, $accountId]);
                $confirmSet = true;
            }

            $result = DocumentController::generateForInspectionInternal($accountId, $userId, $id, true);
            if (isset($result['error'])) {
                $restore();
                return $skip((string) $result['error']);
            }
            $document = $result['document'];
        } catch (\Throwable $e) {
            error_log('[prehliadka-checklist] generate ' . $id . ': ' . $e::class . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $after = self::inspectionRow($accountId, $id);
            $issued = $after !== null && $after['status'] === 'finalized' ? self::documentOf($accountId, $id) : null;
            if ($issued === null) {
                $restore();
                return $skip('Protokol sa nepodarilo vygenerovať.');
            }
            // Failed only after the protocol was committed: it is issued all the same.
            $document = $issued;
        }

        if ($confirmSet) {
            \Firol\Audit\AuditLog::record('concept.confirm', 'inspections', $id, null, [
                'source_inspection_id' => (int) $prehliadka['id'],
            ]);
        }
        return ['inspection_id' => $id, 'type' => $type, 'document' => $document];
    }

    /**
     * The úkony of the round that exist, by id — what review and generate may
     * touch. Anything else is refused.
     *
     * @param array<string, mixed> $prehliadka
     * @return array<int, array<string, mixed>>
     */
    private static function roundEntries(int $accountId, array $prehliadka): array
    {
        $out = [];
        foreach (self::entries($accountId, $prehliadka) as $e) {
            if ($e['inspection_id'] !== null) {
                $out[(int) $e['inspection_id']] = $e;
            }
        }
        return $out;
    }

    /**
     * @param array{id: int, type: string, status: string, details: array<string, mixed>} $concept
     * @return array<string, mixed>
     */
    private static function conceptEntry(int $accountId, int $facilityId, array $concept): array
    {
        $details = $concept['details'];
        if (array_key_exists(self::CARRIED_FROM_KEY, $details)) {
            $origin = is_array($details[self::CARRIED_FROM_KEY]) ? $details[self::CARRIED_FROM_KEY] : null;
        } else {
            // A concept built before its origin was recorded: the last protocol of
            // its kind here other than itself is the one its devices came from.
            $sourceId = CarryOver::latestSourceId($accountId, $facilityId, $concept['type'], $concept['id']);
            $origin = $sourceId !== null ? self::originOf($accountId, $sourceId) : null;
        }
        $draft = $concept['status'] === 'draft';
        [$count, $failing] = self::counts($concept['type'], self::itemFields($concept['id']));

        return [
            'key'           => 'i' . $concept['id'],
            'kind'          => 'concept',
            'type'          => $concept['type'],
            'inspection_id' => $concept['id'],
            'status'        => $concept['status'],
            'follow_up_of'  => null,
            'items'         => $count,
            'failing'       => $failing,
            'origin'        => $origin,
            'document'      => $draft ? null : self::documentOf($accountId, $concept['id']),
            'selectable'    => $draft && $origin !== null,
            'note'          => $draft && $origin === null ? self::NOTE_NO_SOURCE : null,
            'manual_create' => false,
        ];
    }

    /** @return array<string, mixed>|null null when the closed check gives no reason for this follow-up */
    private static function followUpEntry(int $accountId, int $sourceId, string $target): ?array
    {
        $source = self::inspectionRow($accountId, $sourceId);
        if ($source === null) {
            return null;
        }
        $base = [
            'kind'         => 'follow_up',
            'type'         => $target,
            'follow_up_of' => ['inspection_id' => $sourceId, 'type' => (string) $source['type']],
        ];

        $existing = Db::pdo()->prepare(
            'SELECT id, status, details FROM inspections
             WHERE  account_id = ? AND source_inspection_id = ? AND type = ? AND archived_at IS NULL
             ORDER  BY id DESC LIMIT 1'
        );
        $existing->execute([$accountId, $sourceId, $target]);
        $row = $existing->fetch();

        if ($row) {
            $id = (int) $row['id'];
            $details = InspectionDetails::decode($row['details'] ?? null) ?? [];
            if (array_key_exists(self::CARRIED_FROM_KEY, $details)) {
                $origin = is_array($details[self::CARRIED_FROM_KEY]) ? $details[self::CARRIED_FROM_KEY] : null;
            } else {
                // Made with the follow-up button on the check's own screen before it
                // recorded an origin: oprava and vyradenie hold today's devices all the
                // same; such a ts_hadic started blank and is the technician's to fill in.
                // (The button records the origin since 16. 11. 2026, as this list does.)
                $origin = in_array($target, self::FROM_TODAY, true) ? self::originOf($accountId, $sourceId) : null;
            }
            $draft = $row['status'] === 'draft';
            [$count, $failing] = self::counts($target, self::itemFields($id));
            return ['key' => 'i' . $id, 'inspection_id' => $id, 'status' => (string) $row['status']] + $base + [
                'items'         => $count,
                'failing'       => $failing,
                'origin'        => $origin,
                'document'      => $draft ? null : self::documentOf($accountId, $id),
                'selectable'    => $draft && $origin !== null,
                'note'          => $draft && $origin === null ? self::NOTE_MANUAL : null,
                'manual_create' => false,
            ];
        }

        $build = self::followUpItems($accountId, $source, $target);
        if ($build['rows'] === []) {
            return null;
        }
        [$count, $failing] = self::counts($target, $build['rows']);
        return ['key' => 'f' . $sourceId . ':' . $target, 'inspection_id' => null, 'status' => null] + $base + [
            'items'         => $count,
            'failing'       => $failing,
            'origin'        => $build['origin'],
            'document'      => null,
            'selectable'    => $build['origin'] !== null,
            'note'          => $build['origin'] === null ? self::NOTE_NO_SOURCE : null,
            'manual_create' => $build['origin'] === null,
        ];
    }

    /**
     * The rows of a follow-up of `$source` and where their values come from.
     *
     * @param array<string, mixed> $source the closed routine check
     * @return array{rows: list<array<string, mixed>>, carried: list<int>, origin: array{number: ?string, executed_on: ?string}|null}
     *         carried = indexes into rows
     */
    private static function followUpItems(int $accountId, array $source, string $target): array
    {
        $sourceItems = self::itemFields((int) $source['id']);

        if ($target === 'oprava_ts_php' || $target === 'vyradenie') {
            $rows = $target === 'oprava_ts_php'
                ? InspectionController::mapPhpToOprava($sourceItems)
                : InspectionController::mapPhpToVyradenie($sourceItems);
            return [
                'rows'    => $rows,
                'carried' => array_keys($rows),
                'origin'  => self::originOf($accountId, (int) $source['id']),
            ];
        }

        return self::tsHadicRows($accountId, (int) $source['facility_id'], $sourceItems);
    }

    /**
     * Tlaková skúška hadíc from a hydranty check: today's hoses (one row per
     * hose of each hydrant), each with the values it had on the last ts_hadic
     * protocol at the prevádzka, the result included (decision 3, fix list of
     * 16. 11. 2026). The same matching serves the checklist after a prehliadka
     * and the follow-up button on the hydranty úkon itself
     * ({@see InspectionController::followUp()}).
     *
     * A hose with no match in that protocol, or without any earlier protocol,
     * stays empty for manual entry — its result is not presumed either.
     *
     * @param list<array<string, mixed>> $hydrantItems the hydranty úkon's item fields
     * @return array{rows: list<array<string, mixed>>, carried: list<int>, origin: array{number: ?string, executed_on: ?string}|null}
     *         carried = indexes into rows
     */
    public static function tsHadicRows(int $accountId, int $facilityId, array $hydrantItems): array
    {
        $rows = InspectionController::mapHydrantyToTsHadic($hydrantItems);
        $previousId = CarryOver::latestSourceId($accountId, $facilityId, 'ts_hadic');
        if ($previousId === null || $rows === []) {
            return ['rows' => $rows, 'carried' => [], 'origin' => null];
        }

        $previous = self::itemFields($previousId);
        $norm = static fn (mixed $s): string => mb_strtolower(trim(is_scalar($s) ? (string) $s : ''));
        $used = [];
        $carried = [];
        foreach ($rows as $i => $row) {
            $location = $norm($row['location'] ?? '');
            $hoseType = $norm($row['hose_type'] ?? '');
            // The same hose: same place and type first, then the same place.
            $pick = null;
            foreach ([true, false] as $byType) {
                foreach ($previous as $j => $p) {
                    if (isset($used[$j]) || $norm($p['location'] ?? '') !== $location) {
                        continue;
                    }
                    if ($byType && $norm($p['hose_type'] ?? '') !== $hoseType) {
                        continue;
                    }
                    $pick = $j;
                    break 2;
                }
            }
            if ($pick === null) {
                continue; // nothing to take over; the row stays blank, result included
            }
            $used[$pick] = true;
            foreach (self::TS_HADIC_VALUES as $key) {
                if (array_key_exists($key, $previous[$pick])) {
                    $rows[$i][$key] = $previous[$pick][$key];
                }
            }
            $carried[] = $i;
        }

        // No hose matched: nothing came from that protocol, so it is not named as the origin.
        return ['rows' => $rows, 'carried' => $carried, 'origin' => $carried === [] ? null : self::originOf($accountId, $previousId)];
    }

    /**
     * @param array<string, mixed> $prehliadka
     * @param bool $blank the draft of an entry with nothing to carry ({@see createManual()})
     * @return array{inspection_id: int}|array{error: string, code?: string, cert?: string}
     */
    private static function createFollowUp(int $accountId, int $userId, array $prehliadka, int $sourceId, string $target, bool $blank = false): array
    {
        $source = self::inspectionRow($accountId, $sourceId);
        if ($source === null || $source['status'] !== 'finalized') {
            return ['error' => 'Kontrola, z ktorej úkon vychádza, nie je uzavretá.'];
        }
        // A new úkon signed by the same technician — refused now, as on every create.
        $missing = CertGuard::missingForType($accountId, (int) $source['inspector_user_id'], $target);
        if ($missing !== null) {
            return ['error' => $missing['error'], 'code' => 'cert_missing', 'cert' => $missing['cert']];
        }
        $build = self::followUpItems($accountId, $source, $target);
        if ($blank) {
            if ($build['rows'] === [] || $build['origin'] !== null) {
                return ['error' => 'Túto položku už nemožno vytvoriť týmto spôsobom.'];
            }
        } elseif ($build['rows'] === [] || $build['origin'] === null) {
            return ['error' => 'Úkon nemá z čoho prevziať položky — vyplň ho ručne.'];
        }

        $recommended = Periodicity::RECOMMENDED_MONTHS[$target] ?? [];
        $value = $recommended[0] ?? null;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Created meanwhile (a second tap, another device): take that one.
            $existing = $pdo->prepare(
                'SELECT id FROM inspections
                 WHERE  account_id = ? AND source_inspection_id = ? AND type = ? AND archived_at IS NULL
                 ORDER  BY id DESC LIMIT 1 FOR UPDATE'
            );
            $existing->execute([$accountId, $sourceId, $target]);
            $found = $existing->fetchColumn();
            if ($found !== false) {
                $pdo->commit();
                return ['inspection_id' => (int) $found];
            }

            $positions = array_map(static fn (int $i): int => $i + 1, $build['carried']);
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, visit_id, source_inspection_id, type,
                     periodicity_value, periodicity_unit, executed_on, inspector_user_id,
                     status, notes, is_preventive_inspection, created_by_user_id, details)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", NULL, ?, ?, ?)'
            )->execute([
                $accountId,
                (int) $source['company_id'],
                (int) $source['facility_id'],
                $source['visit_id'] !== null ? (int) $source['visit_id'] : null,
                $sourceId,
                $target,
                $value,
                $value === null ? null : 'mesiac',
                // Decision 4: closed with the prehliadka's date unless the technician changes it.
                $prehliadka['executed_on'],
                (int) $source['inspector_user_id'],
                in_array($target, self::NON_CYCLIC, true) ? 0 : 1,
                $userId,
                InspectionDetails::encode([
                    self::CARRIED_FROM_KEY      => $build['origin'],
                    self::CARRIED_POSITIONS_KEY => $positions,
                ]),
            ]);
            $newId = (int) $pdo->lastInsertId();
            // Chapter 22 — a follow-up is a new úkon; its režim comes from the firm.
            $pdo->prepare('UPDATE inspections SET billing_mode = ? WHERE id = ?')
                ->execute([Invoicing::companyMode((int) $source['company_id']), $newId]);

            $insert = $pdo->prepare('INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)');
            foreach ($build['rows'] as $i => $fields) {
                $insert->execute([$newId, $i + 1, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        \Firol\Audit\AuditLog::record('inspection.follow_up', 'inspections', $newId, null, [
            'source_inspection_id' => $sourceId,
            'type'                 => $target,
            'prehliadka_id'        => (int) $prehliadka['id'],
        ]);
        return ['inspection_id' => $newId];
    }

    /**
     * Positions of the items that carry values from elsewhere. Oprava and
     * vyradenie made on the check's own screen hold nothing but today's devices.
     *
     * @param array<string, mixed>|null $details
     * @param list<array{id: int, position: int, fields: mixed}> $items
     * @return list<int>
     */
    private static function carriedPositions(string $type, ?array $details, array $items): array
    {
        if (is_array($details) && is_array($details[self::CARRIED_POSITIONS_KEY] ?? null)) {
            return array_values(array_filter($details[self::CARRIED_POSITIONS_KEY], 'is_int'));
        }
        if (in_array($type, self::FROM_TODAY, true)) {
            return array_map(static fn (array $i): int => (int) $i['position'], $items);
        }
        return [];
    }

    /**
     * How the review names a device: its label and where it is.
     *
     * @param array<string, mixed> $fields
     */
    private static function itemName(string $type, array $fields, int $position): string
    {
        $text = static fn (string $k): string => is_scalar($fields[$k] ?? null) ? trim((string) $fields[$k]) : '';
        $join = static fn (array $parts): string => implode(' · ', array_filter($parts, static fn (string $p): bool => $p !== ''));

        $name = match ($type) {
            'oprava_ts_php', 'vyradenie' => $join([$text('serial'), trim($text('manufacturer') . ' ' . $text('type')), $text('location')]),
            'ts_hadic'                   => $join([$text('hose_type'), $text('location')]),
            default                      => (static function () use ($type, $fields, $join): string {
                $shaped = FacilityDevices::shape($type, $fields);
                return $shaped === null ? '' : $join([(string) $shaped['label'], (string) ($shaped['description'] ?? ''), (string) ($shaped['location'] ?? '')]);
            })(),
        };
        return $name !== '' ? $name : 'Položka č. ' . $position;
    }

    /**
     * Devices and how many of them do not pass (status O for a hasiaci prístroj,
     * „nevyhovuje" elsewhere) — as the result screen counted concepts.
     *
     * @param list<array<string, mixed>> $rows item fields
     * @return array{0: int, 1: int}
     */
    private static function counts(string $type, array $rows): array
    {
        $failing = 0;
        foreach ($rows as $f) {
            if ($type === 'php' ? ($f['status'] ?? null) === 'O' : ($f['result'] ?? null) === 'nevyhovuje') {
                $failing++;
            }
        }
        return [count($rows), $failing];
    }

    /** @return list<array<string, mixed>> */
    private static function itemFields(int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare('SELECT fields FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC');
        $stmt->execute([$inspectionId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $f = json_decode((string) $r['fields'], true);
            $out[] = is_array($f) ? $f : [];
        }
        return $out;
    }

    /** @return array{id: int, number: string}|null */
    private static function documentOf(int $accountId, int $inspectionId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, number FROM documents
             WHERE  account_id = ? AND parent_type = "inspection" AND parent_id = ?
             ORDER  BY id DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $inspectionId]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int) $row['id'], 'number' => (string) $row['number']] : null;
    }

    /** @return array<string, mixed>|null */
    private static function inspectionRow(int $accountId, int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, type, status, executed_on, visit_id, company_id, facility_id,
                    inspector_user_id, source_inspection_id, details
             FROM   inspections
             WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
