<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\InspectionPhotoController;
use Firol\Db;
use Firol\Storage\Storage;

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
 *                       | null only where the host makes the deadline optional (the book)
 *     deadline_date     Y-m-d|null   a concrete date; null for bezodkladne and no deadline
 *     responsible       string|null
 *     group_key         string|null  shared by the rows saved with „Uložiť a ďalšie zariadenie"
 *     status            zisteny | odstraneny | trva | zanik
 *                                  „Stav nedostatku" (POapp 7. 11. 2026, spec 12.1).
 *                                  Rows written before it have none: they read as
 *                                  `trva` when `persists_from` is set, else
 *                                  `zisteny` ({@see statusOf()}).
 *     reason            string|null  the one sentence of „dôvod zanikol"; null otherwise
 *     persists_from     null | { key, inspection_id, executed_on, number, source_item_id }
 *                                  set when the defect was taken over from the last
 *                                  prehliadka (spec 6.5, 12.1), whatever its state;
 *                                  `key` is the previous defect it answers (the key
 *                                  of {@see PrehliadkaPrevious}), `executed_on` and
 *                                  `number` the date and document number of the
 *                                  record it came from, `source_item_id` the item of
 *                                  that record (its photos are copied onto this row
 *                                  when it is created). Rows carried over before
 *                                  12.1 have only key, inspection_id and executed_on.
 *     facility_id       int          only when the host offers several prevádzky to
 *                                  choose from (the book, spec 8); never written by
 *                                  a prehliadka, which has one
 *
 * Open and closed. A defect is OPEN while its state is `zisteny` or `trva`: the
 * next prehliadka offers it again, it can get a „Overiť odstránenie" task and it
 * shows on Dnes. `odstraneny` and `zanik` close it: it stays in the record and in
 * history and nothing else picks it up. A carried-over defect that is closed is
 * not a point of the record — it prints only in the table of the previous
 * defects, where the answers printed before 12.1.
 *
 * Answers. Before 12.1 the answer to a previous defect lived only in the draft's
 * `details.previous` (ok / trva / zanik, see {@see PrehliadkaDetails}). Since 12.1
 * every answer is a row of its own with `persists_from`, and the row is the
 * truth; `details.previous` is no longer written by the app. {@see answers()}
 * reads both, a row winning over an old entry with the same key, so drafts begun
 * before 12.1 keep what they hold.
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

    /** „Stav nedostatku" — the same four choices for a new and a carried-over defect. */
    public const STATUSES = ['zisteny', 'odstraneny', 'trva', 'zanik'];

    /** The states that keep a defect open (see the class comment). */
    public const OPEN_STATUSES = ['zisteny', 'trva'];

    private const MAX_DEVICES = 60;

    /**
     * The key a defect carries in a „Overiť odstránenie" task
     * (`tasks.source_inspection_id` = the prehliadka, `tasks.source_defect_key` = this).
     * A defect is an item row, so its id is the stable key — the same `p<id>` Dnes
     * already puts in the row key of a prehliadka defect. Fits the task's key rule
     * (`[A-Za-z0-9_-]{1,40}`).
     */
    public static function taskKey(int $itemId): string
    {
        return 'p' . $itemId;
    }

    /**
     * The state of a defect row; rows written before „Stav nedostatku" existed
     * read as `trva` when they were carried over, else `zisteny`.
     *
     * @param array<string, mixed> $fields
     */
    public static function statusOf(array $fields): string
    {
        $status = $fields['status'] ?? null;
        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            return $status;
        }
        return is_array($fields['persists_from'] ?? null) ? 'trva' : 'zisteny';
    }

    /** @param array<string, mixed> $fields */
    public static function isOpen(array $fields): bool
    {
        return in_array(self::statusOf($fields), self::OPEN_STATUSES, true);
    }

    /**
     * A carried-over defect answered „odstránený" or „dôvod zanikol": it prints
     * only in the previous-defect table, never as a point of the record.
     *
     * @param array<string, mixed> $fields
     */
    public static function isClosedCarry(array $fields): bool
    {
        return is_array($fields['persists_from'] ?? null) && !self::isOpen($fields);
    }

    /**
     * What a prehliadka has answered about each defect of the previous one,
     * keyed by the previous defect's key. `status` keeps the words of the old
     * `details.previous` — ok / trva / zanik, null = not decided. A row with
     * `persists_from` wins over an old entry; an old „trvá" without its row is
     * not an answer (the carried-over defect was deleted since). A carried-over
     * row in the state `zisteny` reads as „trvá": it is the same defect, still there.
     *
     * @param array<string, mixed> $details the prehliadka's `details`
     * @param list<array{id: int, fields: array<string, mixed>}> $items its defect rows
     * @return array<string, array{status: ?string, reason: ?string, source_inspection_id: int, source_item_id: ?int, item_id: ?int}>
     */
    public static function answers(array $details, array $items): array
    {
        $out = [];
        foreach ((array) ($details['previous'] ?? []) as $e) {
            if (!is_array($e) || !is_string($e['key'] ?? null) || $e['key'] === '') {
                continue;
            }
            $status = in_array($e['status'] ?? null, ['ok', 'zanik'], true) ? (string) $e['status'] : null;
            $reason = is_string($e['reason'] ?? null) ? trim($e['reason']) : '';
            $out[$e['key']] = [
                'status'               => $status,
                'reason'               => $status === 'zanik' && $reason !== '' ? $reason : null,
                'source_inspection_id' => (int) ($e['source_inspection_id'] ?? 0),
                'source_item_id'       => is_int($e['source_item_id'] ?? null) ? $e['source_item_id'] : null,
                'item_id'              => null,
            ];
        }
        foreach ($items as $item) {
            $f = (array) ($item['fields'] ?? []);
            $pf = $f['persists_from'] ?? null;
            if (!is_array($pf) || !is_string($pf['key'] ?? null) || $pf['key'] === '') {
                continue;
            }
            $status = match (self::statusOf($f)) {
                'odstraneny' => 'ok',
                'zanik'      => 'zanik',
                default      => 'trva',
            };
            $reason = is_string($f['reason'] ?? null) ? trim($f['reason']) : '';
            $out[$pf['key']] = [
                'status'               => $status,
                'reason'               => $status === 'zanik' && $reason !== '' ? $reason : null,
                'source_inspection_id' => (int) ($pf['inspection_id'] ?? 0),
                'source_item_id'       => self::sourceItemOf($pf),
                'item_id'              => (int) $item['id'],
            ];
        }
        return $out;
    }

    /**
     * How many defects the previous prehliadka left that this one has not
     * decided about yet — only those of a record being done (a BOZP defect has
     * no record to go into in a PO-only prehliadka). Closing waits for zero.
     *
     * @param array<string, mixed> $details validated PrehliadkaDetails
     * @param array{source: ?array<string, mixed>, defects: list<array<string, mixed>>} $previous {@see PrehliadkaPrevious::forFacility()}
     * @param list<array{id: int, fields: array<string, mixed>}> $items
     */
    public static function undecided(array $details, array $previous, array $items): int
    {
        $po = ($details['scope']['po'] ?? false) === true;
        $bozp = ($details['scope']['bozp'] ?? false) === true;
        $answers = self::answers($details, $items);
        $n = 0;
        foreach ($previous['defects'] as $d) {
            $applies = match ($d['scope'] ?? 'po') {
                'ob'    => $po || $bozp,
                'bo'    => $bozp,
                default => $po,
            };
            if ($applies && ($answers[(string) $d['key']]['status'] ?? null) === null) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Defects of earlier prehliadky that a prehliadka still open as a draft has
     * already set to „odstránené" or „dôvod zanikol" (spec 6.5, 12.1). „Trvá" is
     * not here — that defect lives on. Reads the rows and the old answers alike
     * ({@see answers()}).
     *
     * Keyed by "inspection id:item id".
     *
     * @return array<string, true>
     */
    public static function answeredInOpenDrafts(int $accountId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, details FROM inspections
             WHERE  account_id = ? AND type = 'preventivna_prehliadka' AND status = 'draft' AND archived_at IS NULL"
        );
        $stmt->execute([$accountId]);
        $itemStmt = $pdo->prepare('SELECT id, fields FROM inspection_items WHERE inspection_id = ?');
        $answered = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $d = json_decode((string) $row['details'], true);
            $itemStmt->execute([(int) $row['id']]);
            $items = [];
            foreach ($itemStmt->fetchAll(\PDO::FETCH_ASSOC) as $it) {
                $items[] = ['id' => (int) $it['id'], 'fields' => json_decode((string) $it['fields'], true) ?: []];
            }
            foreach (self::answers(is_array($d) ? $d : [], $items) as $a) {
                if (in_array($a['status'], ['ok', 'zanik'], true) && $a['source_item_id'] !== null) {
                    $answered[$a['source_inspection_id'] . ':' . $a['source_item_id']] = true;
                }
            }
        }
        return $answered;
    }

    /**
     * The item of the previous record a carried-over defect came from: stored
     * since 12.1, read off the key („p<inspection>-<item>") for older rows.
     *
     * @param array<string, mixed> $persistsFrom
     */
    public static function sourceItemOf(array $persistsFrom): ?int
    {
        $id = $persistsFrom['source_item_id'] ?? null;
        if (is_int($id) && $id > 0) {
            return $id;
        }
        $key = $persistsFrom['key'] ?? null;
        if (is_string($key) && preg_match('/^p\d+-(\d+)$/', $key, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * The photos of the previous record's defect, copied onto the row that
     * carries it over (spec 12.1: „Pôvodná fotka zostáva, nová sa pridáva k nej").
     * Each copy is a file of its own, so the past record's photos are never
     * touched by anything done to this one. Runs inside the create, on the
     * server, so a create replayed from the offline outbox copies them too.
     * Only a prehliadka is a source of photos; an old book record has none here.
     *
     * @param array<string, mixed> $fields validated defect fields
     * @param list<string> $files absolute paths written, for cleanup on failure
     */
    public static function copyCarriedPhotos(int $accountId, array $fields, int $toInspectionId, int $toItemId, array &$files): void
    {
        $pf = $fields['persists_from'] ?? null;
        if (!is_array($pf)) {
            return;
        }
        $fromInspectionId = (int) ($pf['inspection_id'] ?? 0);
        $fromItemId = is_int($pf['source_item_id'] ?? null) ? $pf['source_item_id'] : null;
        if ($fromInspectionId <= 0 || $fromItemId === null) {
            return;
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            "SELECT p.file_path, p.thumb_path, p.byte_size, p.width, p.height
             FROM   inspection_item_photos p
             JOIN   inspections i ON i.id = p.inspection_id
             WHERE  p.inspection_id = ? AND p.item_id = ? AND p.account_id = ?
               AND  i.type = 'preventivna_prehliadka'
             ORDER  BY p.position ASC, p.id ASC"
        );
        $stmt->execute([$fromInspectionId, $fromItemId, $accountId]);
        $photos = $stmt->fetchAll();
        if ($photos === []) {
            return;
        }

        $count = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(position), 0) FROM inspection_item_photos WHERE item_id = ?');
        $count->execute([$toItemId]);
        [$have, $position] = array_map('intval', $count->fetch(\PDO::FETCH_NUM) ?: [0, 0]);

        Storage::ensureDir(Storage::photoDir($accountId, $toInspectionId));
        $insert = $pdo->prepare(
            'INSERT INTO inspection_item_photos
                (account_id, inspection_id, item_id, defect_key, position,
                 file_path, thumb_path, byte_size, width, height)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($photos as $p) {
            if ($have >= InspectionPhotoController::MAX_PER_ITEM) {
                break;
            }
            $srcFull = Storage::absolute((string) $p['file_path']);
            $srcThumb = Storage::absolute((string) $p['thumb_path']);
            if (!is_file($srcFull) || !is_file($srcThumb)) {
                error_log('[carried-photos] file missing: ' . $srcFull);
                continue;
            }
            $token = bin2hex(random_bytes(16));
            $relFull = Storage::photoRelative($accountId, $toInspectionId, $token);
            $relThumb = Storage::photoThumbRelative($accountId, $toInspectionId, $token);
            if (!copy($srcFull, Storage::absolute($relFull))) {
                throw new \RuntimeException('Failed to copy a carried-over photo.');
            }
            $files[] = Storage::absolute($relFull);
            if (!copy($srcThumb, Storage::absolute($relThumb))) {
                throw new \RuntimeException('Failed to copy a carried-over photo thumbnail.');
            }
            $files[] = Storage::absolute($relThumb);
            $have++;
            $position++;
            $insert->execute([
                $accountId, $toInspectionId, $toItemId, $position, $relFull, $relThumb,
                (int) $p['byte_size'], (int) $p['width'], (int) $p['height'],
            ]);
        }
    }

    /**
     * The defects of a CLOSED prehliadka for which the app can offer the task
     * „Overiť odstránenie nedostatku" (spec 20, plan 8b point 3): those with a
     * concrete deadline (`bezodkladne` carries none), not already answered as
     * odstránené / dôvod zanikol by a later open prehliadka. Empty for a draft
     * and for a prehliadka a newer finalized one has replaced at the prevádzka
     * — the same "latest one counts" rule as Dnes.
     *
     * `task` is null while the defect has no task, else `open` / `done` — the
     * client offers only the null ones.
     *
     * @param array{status: string, facility_id: int|string, executed_on: ?string} $inspection
     * @param list<array{id: int, fields: array<string, mixed>}> $items
     * @return list<array{key: string, title: string, deadline_date: string, task: ?string}>
     */
    public static function taskCandidates(int $accountId, int $inspectionId, array $inspection, array $items): array
    {
        if ((string) $inspection['status'] !== 'finalized') {
            return [];
        }
        $newer = Db::pdo()->prepare(
            "SELECT 1 FROM inspections s
             WHERE  s.account_id = ? AND s.facility_id = ? AND s.type = 'preventivna_prehliadka'
               AND  s.archived_at IS NULL AND s.status = 'finalized'
               AND  (COALESCE(s.executed_on, '1000-01-01'), s.id) > (COALESCE(?, '1000-01-01'), ?)
             LIMIT 1"
        );
        $newer->execute([$accountId, (int) $inspection['facility_id'], $inspection['executed_on'], $inspectionId]);
        if ($newer->fetchColumn() !== false) {
            return [];
        }

        $answered = self::answeredInOpenDrafts($accountId);
        $tasks = Db::pdo()->prepare(
            'SELECT source_defect_key, MAX(done = 0) AS has_open
             FROM   tasks
             WHERE  account_id = ? AND source_inspection_id = ? AND source_defect_key IS NOT NULL
             GROUP  BY source_defect_key'
        );
        $tasks->execute([$accountId, $inspectionId]);
        $taskState = [];
        foreach ($tasks->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            $taskState[(string) $t['source_defect_key']] = (int) $t['has_open'] === 1 ? 'open' : 'done';
        }

        $out = [];
        foreach ($items as $item) {
            $f = $item['fields'];
            $title = is_string($f['title'] ?? null) ? trim($f['title']) : '';
            $deadline = $f['deadline_date'] ?? null;
            if ($title === '' || !is_string($deadline) || !self::isDate($deadline)) {
                continue;
            }
            // Removed on the spot, or a carried-over one answered odstránený /
            // dôvod zanikol: no removal is left to verify.
            if (!self::isOpen($f)) {
                continue;
            }
            $itemId = (int) $item['id'];
            if (isset($answered[$inspectionId . ':' . $itemId])) {
                continue;
            }
            $key = self::taskKey($itemId);
            $out[] = [
                'key'           => $key,
                'title'         => $title,
                'deadline_date' => $deadline,
                'task'          => $taskState[$key] ?? null,
            ];
        }
        return $out;
    }

    /**
     * The same rules serve every host of the defect screen (spec 8, 12.1): the
     * prehliadka calls it with no options; the book will pass its own.
     *
     * @param array<string, mixed> $body
     * @param string|null $executedOn the date computed deadlines count from (Y-m-d)
     * @param bool $isBytovyDom whether the facility belongs to a bytový dom
     * @param array{deadline_optional?: bool, facility_ids?: list<int>} $options
     *        deadline_optional  no deadline is a valid answer; it stays empty
     *                           (`deadline_choice` and `deadline_date` null)
     *        facility_ids       the prevádzky the defect may belong to; with more
     *                           than one, `facility_id` is required and stored
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function validate(array $body, ?string $executedOn, bool $isBytovyDom = true, array $options = []): array
    {
        $title = self::text($body['title'] ?? null, 255);
        if ($title === null) {
            throw new \InvalidArgumentException('Vyber, o aký nedostatok ide.');
        }

        $scope = $body['scope'] ?? 'po';
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('Neplatné určenie nedostatku (požiarny / BOZP / oboje).');
        }

        $persistsFrom = self::persistsFrom($body['persists_from'] ?? null);
        [$status, $reason] = self::status($body, $persistsFrom !== null);

        // A carried-over defect answered odstránený / dôvod zanikol prints only
        // in the table of the previous defects — an old book entry gave it no
        // place, and asking for one would only stand in the technician's way.
        $closedCarry = $persistsFrom !== null && !in_array($status, self::OPEN_STATUSES, true);
        try {
            $where = self::where($body['where'] ?? null, $isBytovyDom);
        } catch (\InvalidArgumentException $e) {
            if (!$closedCarry) {
                throw $e;
            }
            $where = ['entrance' => null, 'floor' => null, 'place' => null, 'detail' => null, 'text' => ''];
        }

        $description = self::text($body['description'] ?? null, 2000);
        if ($description === null) {
            throw new \InvalidArgumentException('Doplň popis zisteného stavu.');
        }

        [$choice, $deadline] = self::deadline($body, $executedOn, ($options['deadline_optional'] ?? false) === true);

        $out = [
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
            'status'        => $status,
            'reason'        => $reason,
            'persists_from' => $persistsFrom,
        ];

        $facilityIds = array_values(array_filter(
            (array) ($options['facility_ids'] ?? []),
            static fn (mixed $id): bool => is_int($id) && $id > 0,
        ));
        if (count($facilityIds) > 1) {
            $facilityId = $body['facility_id'] ?? null;
            if (is_string($facilityId) && ctype_digit($facilityId)) {
                $facilityId = (int) $facilityId;
            }
            if (!is_int($facilityId) || !in_array($facilityId, $facilityIds, true)) {
                throw new \InvalidArgumentException('Vyber, ktorej prevádzky sa nedostatok týka.');
            }
            $out['facility_id'] = $facilityId;
        }
        return $out;
    }

    /**
     * „Stav nedostatku". A write without the key comes from a client older than
     * the field (or its queued write): it means what such a row always meant.
     * A carried-over defect sent with an empty state is undecided and is refused
     * (spec 12.1: it cannot be saved undecided).
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: ?string}
     */
    private static function status(array $body, bool $carried): array
    {
        if (!array_key_exists('status', $body)) {
            $status = $carried ? 'trva' : 'zisteny';
        } else {
            $status = $body['status'];
            if (($status === null || $status === '') && !$carried) {
                $status = 'zisteny';
            }
        }
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Vyber stav nedostatku.');
        }
        if ($status !== 'zanik') {
            return [$status, null];
        }
        // „Dôvod zanikol" is never saved without its one sentence (spec 6.5):
        // otherwise the record would read as if the client had put things right.
        $reason = self::text($body['reason'] ?? null, 500);
        if ($reason === null) {
            throw new \InvalidArgumentException('Napíš jednou vetou, prečo dôvod zanikol.');
        }
        return [$status, $reason];
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
     * @return array{0: ?string, 1: ?string}
     */
    private static function deadline(array $body, ?string $executedOn, bool $optional = false): array
    {
        $choice = $body['deadline_choice'] ?? null;
        if ($optional && ($choice === null || $choice === '')) {
            return [null, null];
        }
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
    private static function where(mixed $raw, bool $isBytovyDom = true): array
    {
        $raw = is_array($raw) ? $raw : [];
        $entrance = $isBytovyDom ? self::text($raw['entrance'] ?? null, 120) : null;
        $floor = $isBytovyDom ? self::text($raw['floor'] ?? null, 120) : null;
        $place = self::text($raw['place'] ?? null, 120);
        $detail = self::text($raw['detail'] ?? null, 255);

        $parts = $isBytovyDom ? [$entrance, $floor, $place, $detail] : [$place, $detail];
        $text = implode(' · ', array_values(array_filter(
            $parts,
            static fn (?string $p): bool => $p !== null,
        )));
        if ($text === '') {
            throw new \InvalidArgumentException(
                $isBytovyDom
                    ? 'Zadaj miesto nedostatku — vchod, podlažie, miesto alebo vlastný popis.'
                    : 'Zadaj miesto nedostatku — miesto alebo vlastný popis.',
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

    /**
     * @return array{key: string, inspection_id: int, executed_on: ?string, number: ?string, source_item_id: ?int}|null
     */
    private static function persistsFrom(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $key = self::key($raw['key'] ?? null);
        $inspectionId = $raw['inspection_id'] ?? null;
        if ($key === null || !is_int($inspectionId) || $inspectionId <= 0) {
            throw new \InvalidArgumentException('Nedostatok z minulej prehliadky má neplatný pôvod.');
        }
        $date = $raw['executed_on'] ?? null;
        $sourceItem = $raw['source_item_id'] ?? null;
        return [
            'key'            => $key,
            'inspection_id'  => $inspectionId,
            'executed_on'    => is_string($date) && self::isDate($date) ? $date : null,
            'number'         => self::text($raw['number'] ?? null, 40),
            'source_item_id' => is_int($sourceItem) && $sourceItem > 0 ? $sourceItem : null,
        ];
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
