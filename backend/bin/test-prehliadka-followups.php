<?php

declare(strict_types=1);

/**
 * Check of the two follow-ups of Phase 8b:
 *
 *   1. the „Overiť odstránenie" task for the defects of a CLOSED preventívna
 *      prehliadka — which defects are offered, the key a task carries, and how
 *      Dnes „Otvorené nedostatky" treats an open / done / foreign task;
 *   2. the required fields of a device in a concept from a prehliadka — which
 *      fields each type lacks, how the refusal reads, odd legacy rows.
 *
 *     docker compose exec php php bin/test-prehliadka-followups.php
 *
 * Everything runs inside one transaction that is rolled back at the end, so the
 * database is left as it was. Local / staging only.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Controllers\TodayController;
use Firol\Db;
use Firol\Support\PrehliadkaConcepts;
use Firol\Support\PrehliadkaDefects;

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '') === 'production') {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

$failures = 0;
$expect = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    $ok = $actual === $expected;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
        echo '       expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . "\n       got      " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    }
};

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $newAccount = static function (string $label) use ($pdo): array {
        $pdo->prepare(
            'INSERT INTO users (fullname, email, password_hash, is_pending, is_admin) VALUES (?, ?, ?, 0, 0)'
        )->execute(['Test ' . $label, 'test-followups-' . bin2hex(random_bytes(4)) . '@firol.test', 'x']);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city,
                                   invoice_ico, main_user_id, subscription_end_date, member_rights)
             VALUES ('Test s.r.o.', 'Skúšobná 1', '949 01', 'Nitra', '12345678', ?, '2030-12-31', 'plne')"
        )->execute([$userId]);
        $accountId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO companies (account_id, name, client_type, street, postal_code, city, ico) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, 'Firma ' . $label, 'firma', 'Ulica 1', '949 01', 'Nitra', '87654321']);
        return [$accountId, $userId, (int) $pdo->lastInsertId()];
    };
    [$accountId, $userId, $companyId] = $newAccount('A');
    [$otherAccountId, , ] = $newAccount('B');

    $newFacility = static function (string $name) use ($pdo, $accountId, $companyId): int {
        $pdo->prepare('INSERT INTO facilities (account_id, company_id, name, street, postal_code, city) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $companyId, $name, 'Ulica 2', '949 01', 'Nitra']);
        return (int) $pdo->lastInsertId();
    };
    $insert = $pdo->prepare(
        "INSERT INTO inspections
            (account_id, company_id, facility_id, type, periodicity_value, periodicity_unit, executed_on,
             inspector_user_id, effective_inspector_user_id, effective_cert_number, effective_cert_valid_from,
             effective_cert_valid_to, status, created_by_user_id, details, is_preventive_inspection)
         VALUES (?, ?, ?, 'preventivna_prehliadka', 12, 'mesiac', ?, ?, ?, 'T-1', '2026-01-01', '2035-12-31', ?, ?, ?, 1)"
    );
    /** @param array<string, mixed>|null $details */
    $prehliadka = static function (int $facilityId, string $on, string $status = 'finalized', ?array $details = null) use ($insert, $pdo, $accountId, $companyId, $userId): int {
        $insert->execute([
            $accountId, $companyId, $facilityId, $on, $userId, $userId, $status, $userId,
            $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $pdo->lastInsertId();
    };
    $itemInsert = $pdo->prepare('INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)');
    /** @param array<string, mixed> $fields */
    $item = static function (int $inspectionId, int $position, array $fields) use ($itemInsert, $pdo): int {
        $itemInsert->execute([$inspectionId, $position, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
        return (int) $pdo->lastInsertId();
    };
    $defect = static fn (string $title, string $choice, ?string $date): array => [
        'title' => $title, 'scope' => 'po', 'where' => ['text' => 'Chodba'], 'devices' => [],
        'description' => 'Popis', 'deadline_choice' => $choice, 'deadline_date' => $date,
    ];
    $header = static fn (int $id): array => (array) $pdo->query(
        'SELECT status, facility_id, executed_on FROM inspections WHERE id = ' . $id
    )->fetch(PDO::FETCH_ASSOC);
    $items = static function (int $id) use ($pdo): array {
        $rows = $pdo->query('SELECT id, position, fields FROM inspection_items WHERE inspection_id = ' . $id . ' ORDER BY position')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'position' => (int) $r['position'], 'fields' => json_decode((string) $r['fields'], true),
        ], $rows);
    };
    $task = $pdo->prepare(
        'INSERT INTO tasks (account_id, text, company_id, facility_id, due_date, done, source_inspection_id, source_defect_key, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $dnes = new ReflectionMethod(TodayController::class, 'openPrehliadkaDefects');
    $dnesKeys = static fn (): array => array_map(
        static fn (array $r): string => $r['key'],
        $dnes->invoke(null, $accountId, null),
    );

    // ── Part 1 — task offer for a closed prehliadka ───────────────────────────
    $f1 = $newFacility('Úlohy');
    $p = $prehliadka($f1, '2026-11-02');
    $a = $item($p, 1, $defect('Chýba tabuľka', '30d', '2026-12-02'));       // offered
    $b = $item($p, 2, $defect('Okamžitá oprava', 'bezodkladne', null));       // no date → never offered
    $c = $item($p, 3, $defect('Zanikol dôvod', 'custom', '2026-12-10'));       // answered by a later draft
    $item($p, 4, ['not' => 'a defect']);                                       // odd row, skipped
    $d = $item($p, 5, $defect('Druhý s dátumom', '3m', '2027-02-02'));         // offered

    $draft = $prehliadka($f1, '2026-11-20', 'draft', [
        'previous' => [
            ['source_inspection_id' => $p, 'source_item_id' => $c, 'status' => 'zanik'],
            ['source_inspection_id' => $p, 'source_item_id' => $d, 'status' => 'trva'], // „Trvá" stays open
        ],
    ]);
    $cands = PrehliadkaDefects::taskCandidates($accountId, $p, $header($p), $items($p));
    $expect('1 offered: dated defects not answered as zanikol', array_column($cands, 'key'), ['p' . $a, 'p' . $d]);
    $expect('1 key is p<item id> and fits the task key rule', preg_match('/^[A-Za-z0-9_-]{1,40}$/', $cands[0]['key']), 1);
    $expect('1 carries the deadline_date and the title', [$cands[0]['deadline_date'], $cands[0]['title'], $cands[0]['task']], ['2026-12-02', 'Chýba tabuľka', null]);
    $expect('1 bezodkladne is not offered', in_array('p' . $b, array_column($cands, 'key'), true), false);

    // Dnes lists the same two (and not bezodkladne / zanikol), the key matches the task key.
    $expect('1 Dnes shows the dated, unanswered defects', $dnesKeys(), ['nedostatok-' . $p . '-p' . $a, 'nedostatok-' . $p . '-p' . $d]);
    $rows = $dnes->invoke(null, $accountId, null);
    $expect('1 Dnes row defect_key = task key', $rows[0]['defect_key'], 'p' . $a);

    // An open task keeps the defect open in Dnes and is not offered again.
    $task->execute([$accountId, 'Overiť odstránenie nedostatku — Chýba tabuľka', $companyId, $f1, '2026-12-02', 0, $p, 'p' . $a, $userId]);
    $taskId = (int) $pdo->lastInsertId();
    $expect('1 open task still counts in Dnes', in_array('nedostatok-' . $p . '-p' . $a, $dnesKeys(), true), true);
    $cands = PrehliadkaDefects::taskCandidates($accountId, $p, $header($p), $items($p));
    $expect('1 open task is marked on the candidate', array_column($cands, 'task', 'key'), ['p' . $a => 'open', 'p' . $d => null]);

    // A task of ANOTHER account with the same key verifies nothing here.
    $task->execute([$otherAccountId, 'cudzia', null, null, '2026-12-02', 1, $p, 'p' . $a, null]);
    $expect('1 a foreign account\'s done task does not verify', in_array('nedostatok-' . $p . '-p' . $a, $dnesKeys(), true), true);
    $expect('1 a foreign account\'s task is not read as ours', array_column(
        PrehliadkaDefects::taskCandidates($accountId, $p, $header($p), $items($p)), 'task', 'key'
    )['p' . $a], 'open');

    // Done and nothing open → verified, leaves Dnes; a second open task would bring it back.
    $pdo->prepare('UPDATE tasks SET done = 1, done_at = NOW() WHERE id = ?')->execute([$taskId]);
    $expect('1 done task verifies the defect in Dnes', $dnesKeys(), ['nedostatok-' . $p . '-p' . $d]);
    $expect('1 done task is marked done', array_column(
        PrehliadkaDefects::taskCandidates($accountId, $p, $header($p), $items($p)), 'task', 'key'
    )['p' . $a], 'done');

    // Never offered while a draft, nor for a prehliadka a newer finalized one replaced.
    $expect('1 a draft prehliadka offers nothing', PrehliadkaDefects::taskCandidates($accountId, $draft, $header($draft), $items($draft)), []);
    $newer = $prehliadka($f1, '2026-12-01');
    $expect('1 a superseded prehliadka offers nothing', PrehliadkaDefects::taskCandidates($accountId, $p, $header($p), $items($p)), []);
    $expect('1 the newer one, without defects, offers nothing', PrehliadkaDefects::taskCandidates($accountId, $newer, $header($newer), $items($newer)), []);

    // ── Part 2 — required fields of a concept's devices ──────────────────────
    $check = static function (string $type, array $fields, int $position = 1): array {
        $r = PrehliadkaConcepts::incompleteItems($type, [['id' => 100 + $position, 'position' => $position, 'fields' => $fields]]);
        return $r === [] ? [] : [$r[0]['name'], $r[0]['missing']];
    };

    $expect('2 php carried over and passing is complete', $check('php', [
        'manufacturer' => 'Gloria', 'type' => 'P6', 'serial' => 'A1', 'year' => 2019, 'location' => 'Chodba', 'status' => 'A', 'notes' => null,
    ]), []);
    $expect('2 php new device: year, výrobca, typ missing', $check('php', [
        'manufacturer' => '', 'type' => '', 'serial' => 'LP1-0002', 'year' => null, 'location' => 'Sklad', 'status' => 'O', 'notes' => 'Chýba tabuľka',
    ]), ['LP1-0002 · Sklad', ['Výrobca', 'Typ', 'Rok výroby']]);
    $expect('2 hydranty carried over: measurements missing', $check('hydranty', [
        'type' => 'DN52', 'type_other' => null, 'location' => 'Vchod', 'hose_count' => 2,
        'hs' => null, 'hd' => null, 'q' => null, 'defects' => null, 'result' => 'vyhovuje',
    ]), ['DN52 · Vchod', ['HS', 'HD', 'Q']]);
    $expect('2 hydranty new device: počet hadíc too', $check('hydranty', [
        'type' => 'other', 'type_other' => 'H-9', 'location' => 'Dvor', 'hose_count' => null,
        'hs' => null, 'hd' => null, 'q' => null, 'defects' => null, 'result' => 'vyhovuje',
    ]), ['H-9 · Dvor', ['Počet hadíc', 'HS', 'HD', 'Q']]);
    $expect('2 hydranty measured is complete', $check('hydranty', [
        'type' => 'DN52', 'location' => 'Vchod', 'hose_count' => 2, 'hs' => 0.4, 'hd' => 0.3, 'q' => 1.5, 'result' => 'vyhovuje',
    ]), []);
    $expect('2 požiarny uzáver new device: druh, výrobca', $check('pu_akcieschopnost', [
        'kind' => '', 'identifier' => 'D-1', 'manufacturer' => '', 'location' => 'Schody', 'result' => 'vyhovuje', 'notes' => null,
    ]), ['D-1 · Schody', ['Druh uzáveru', 'Výrobca']]);
    $expect('2 údržba uzáveru: výrobca is optional, práce are not', $check('pu_udrzba', [
        'kind' => 'dvere', 'identifier' => 'D-1', 'manufacturer' => '', 'location' => 'Schody',
        'maintenance_work' => '', 'result' => 'vyhovuje', 'notes' => null,
    ]), ['D-1 · Schody', ['Vykonané práce']]);
    $expect('2 núdzové osvetlenie new device: druh, trvanie', $check('nudzove_osvetlenie', [
        'evid_number' => 'NL-1', 'floor' => null, 'luminaire_type' => '', 'manufacturer' => '', 'location' => 'Hala',
        'duration_min' => null, 'result' => 'vyhovuje', 'notes' => null,
    ]), ['NL-1 · Hala', ['Druh / typ', 'Doba svietenia v núdzovom režime']]);
    $expect('2 rebrík new device: typ missing', $check('rebriky', [
        'inventory_number' => 'R-1', 'type' => '', 'manufacturer' => '', 'year' => null, 'location' => 'Sklad',
        'faults' => null, 'result' => 'vyhovuje', 'defects' => [],
    ]), ['R-1 · Sklad', ['Typ rebríka']]);
    $expect('2 regál: nosnosť and označenie nosnosti missing', $check('regale', [
        'label' => 'RG-1', 'type' => 'Paletový', 'capacity' => '', 'location' => 'Sklad',
        'capacity_marked' => null, 'faults' => null, 'result' => 'vyhovuje', 'defects' => [],
    ]), ['RG-1 · Sklad', ['Nosnosť', 'Označenie nosnosti']]);

    // Odd legacy rows must not crash the check.
    $legacy = PrehliadkaConcepts::incompleteItems('php', [['id' => 7, 'position' => 3, 'fields' => 'not-an-object']]);
    $expect('2 a row that is not an object counts as empty', [$legacy[0]['name'], count($legacy[0]['missing'])], ['Položka č. 3', 6]);
    $expect('2 a type without rules passes', PrehliadkaConcepts::incompleteItems('ts_hadic', [['id' => 8, 'position' => 1, 'fields' => []]]), []);
    $expect('2 the check is repeatable (collect mode is reset)', $check('php', ['serial' => 'X']), $check('php', ['serial' => 'X']));

    // The refusal names the devices; a long list is cut.
    $many = [];
    for ($n = 1; $n <= 7; $n++) {
        $many[] = ['item_id' => $n, 'name' => 'LP' . $n, 'missing' => ['Typ']];
    }
    $expect('2 message lists five devices and counts the rest', PrehliadkaConcepts::incompleteMessage($many),
        'Koncept nie je úplný — doplň povinné údaje: LP1 (Typ); LP2 (Typ); LP3 (Typ); LP4 (Typ); LP5 (Typ). Ešte 2 zariadenia.');
    $expect('2 message of one device', PrehliadkaConcepts::incompleteMessage([['item_id' => 1, 'name' => 'LP1', 'missing' => ['Typ', 'Rok výroby']]]),
        'Koncept nie je úplný — doplň povinné údaje: LP1 (Typ, Rok výroby).');
} finally {
    $pdo->rollBack();
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
