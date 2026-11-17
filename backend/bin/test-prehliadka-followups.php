<?php

declare(strict_types=1);

/**
 * Check of the two follow-ups of Phase 8b:
 *
 *   1. the „Overiť odstránenie" task for the defects of a CLOSED preventívna
 *      prehliadka — which defects are offered, the key a task carries, and how
 *      Dnes „Otvorené nedostatky" treats an open / done / foreign task;
 *   2. the required fields of a device in a concept from a prehliadka — which
 *      fields each type lacks, how the refusal reads, odd legacy rows;
 *   3. carrying items over (fix list of 16. 11. 2026): a fire-protection type
 *      takes every field of the last protocol, values and result included, a
 *      BOZP row its identification only; and the device list of a prevádzka
 *      never drops a device for want of a label;
 *   4. the review of round 1: what a concept does with a carried result, the
 *      tlaková skúška hadíc rows of the checklist and of the follow-up button,
 *      the recorded origin, which drafts the completeness check holds back,
 *      and the carried positions when items are deleted;
 *   5. round 3: the completeness rule for follow-ups of other types and for old
 *      Opakovať drafts, and what discarding an úkon does — a never-issued draft
 *      deleted for good, an issued úkon archived, the rest refused.
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
use Firol\Controllers\InspectionController;
use Firol\Support\CarryOver;
use Firol\Support\FacilityDevices;
use Firol\Support\PrehliadkaChecklist;
use Firol\Support\PrehliadkaConcepts;
use Firol\Support\PrehliadkaDefects;
use Firol\Support\UkonDiscard;

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
            // Every row of `previous` has its key: PrehliadkaDetails refuses a row
            // without one, and PrehliadkaDefects::answers() skips it.
            ['key' => 'p' . $p . '-' . $c, 'source_inspection_id' => $p, 'source_item_id' => $c, 'status' => 'zanik'],
            ['key' => 'p' . $p . '-' . $d, 'source_inspection_id' => $p, 'source_item_id' => $d, 'status' => 'trva'], // „Trvá" stays open
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
    // A draft carried over before 16. 11. 2026 (values blanked) or a hydrant whose
    // last protocol lacked them: the measurements are still required.
    $expect('2 hydranty without measurements: they are missing', $check('hydranty', [
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
    $expect('2 a row that is not an object counts as empty', [$legacy[0]['name'], count($legacy[0]['missing'])], ['Hasiaci prístroj', 6]);
    $expect('2 a type without rules passes', PrehliadkaConcepts::incompleteItems('poziarna_kniha', [['id' => 8, 'position' => 1, 'fields' => []]]), []);

    // The follow-ups the checklist after a prehliadka closes (spec 12.2).
    $expect('2 oprava a TS PHP carried from today is complete', $check('oprava_ts_php', [
        'manufacturer' => 'Gloria', 'type' => 'P6', 'serial' => 'A1', 'year' => 2019, 'location' => 'Chodba', 'notes' => null,
    ]), []);
    $expect('2 oprava a TS PHP: séria and rok missing', $check('oprava_ts_php', [
        'manufacturer' => 'Gloria', 'type' => 'P6', 'serial' => '', 'year' => null, 'location' => 'Chodba', 'notes' => null,
    ]), ['Položka č. 1', ['Výrobné číslo / séria', 'Rok výroby']]);
    $expect('2 vyradenie: dôvod missing', $check('vyradenie', [
        'manufacturer' => 'Gloria', 'type' => 'P6', 'serial' => 'A1', 'year' => 2019, 'location' => 'Chodba', 'reason' => '',
    ]), ['Položka č. 1', ['Dôvod vyradenia']]);
    $expect('2 ts hadíc carried is complete, rok výroby optional', $check('ts_hadic', [
        'hose_type' => 'C52', 'location' => 'Vchod', 'manufacturer' => 'Pyrotex', 'working_pressure' => 1.2,
        'test_pressure' => 1.5, 'length' => 20, 'year_of_manufacture' => null, 'result' => 'vyhovuje', 'notes' => null,
    ]), []);
    $expect('2 ts hadíc blank hose: values and result missing', $check('ts_hadic', [
        'hose_type' => 'C52', 'location' => 'Vchod', 'manufacturer' => '', 'working_pressure' => null,
        'test_pressure' => null, 'length' => null, 'year_of_manufacture' => null, 'result' => '', 'notes' => null,
    ]), ['Položka č. 1', ['Výrobca', 'Pracovný pretlak', 'Skúšobný pretlak', 'Dĺžka', 'Výsledok skúšky']]);
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
    // An ordinary draft of a fire-protection type gets the same check at generate.
    $expect('2 message of an ordinary draft', PrehliadkaConcepts::incompleteMessage([['item_id' => 1, 'name' => 'DN52 · Dvor', 'missing' => ['HS']]], false),
        'Kontrola nie je úplná — doplň povinné údaje: DN52 · Dvor (HS).');

    // ── Part 3 — carrying items over (fix list of 16. 11. 2026) ──────────────
    $hydrant = [
        'type' => 'DN52', 'type_other' => null, 'location' => 'Dvor', 'hose_count' => 2,
        'hs' => 0.3, 'hd' => 0.12, 'q' => 0.9, 'defects' => 'Nízky prietok', 'result' => 'nevyhovuje',
    ];
    $expect('3 hydranty carry values, result and the defect text', CarryOver::mapItem('hydranty', $hydrant), $hydrant);
    $expect('3 a carried hydrant is complete', $check('hydranty', (array) CarryOver::mapItem('hydranty', $hydrant)), []);
    $expect('3 meta keys of the source stay behind', CarryOver::mapItem('hydranty', $hydrant + [
        'previous_status' => 'vyhovuje', 'prehliadka_defects' => [['item_id' => 1, 'text' => 'x']],
    ]), $hydrant);
    $hose = [
        'hose_type' => 'C52', 'location' => 'Vchod', 'manufacturer' => 'Pyrotex', 'working_pressure' => 1.2,
        'test_pressure' => 1.5, 'length' => 20, 'year_of_manufacture' => 2018, 'result' => 'vyhovuje', 'notes' => null,
    ];
    $sorted = static function (?array $a): ?array {
        if ($a !== null) {
            ksort($a);
        }
        return $a;
    };
    $expect('3 ts hadíc carries pressures and result', $sorted(CarryOver::mapItem('ts_hadic', $hose)), $sorted($hose));
    $door = ['kind' => 'dvere', 'identifier' => 'PD-2', 'manufacturer' => 'Hörmann', 'location' => 'Kotolňa', 'result' => 'nevyhovuje', 'notes' => 'Nedovierajú'];
    $expect('3 požiarny uzáver carries its result', CarryOver::mapItem('pu_akcieschopnost', $door), $door);
    $upkeep = $door + ['maintenance_work' => 'Výmena tesnenia'];
    $expect('3 údržba uzáveru carries the práce', CarryOver::mapItem('pu_udrzba', $upkeep)['maintenance_work'] ?? null, 'Výmena tesnenia');
    $light = [
        'evid_number' => '', 'floor' => '1.PP', 'luminaire_type' => 'Piktogram', 'manufacturer' => 'Zemper',
        'location' => 'Sklad', 'duration_min' => 20, 'result' => 'nevyhovuje', 'notes' => 'Slabý akumulátor',
    ];
    $expect('3 núdzové osvetlenie carries the trvanie', CarryOver::mapItem('nudzove_osvetlenie', $light), $light);
    $php = ['manufacturer' => 'Hasma', 'type' => 'P6Če', 'serial' => 'PH-2', 'year' => 2018, 'location' => 'Sklad', 'status' => 'TS', 'notes' => 'TS 2026'];
    $expect('3 php carries its stav and note, no badge', CarryOver::mapItem('php', $php), $php);
    $expect('3 php disposed last time is not carried', CarryOver::mapItem('php', ['status' => 'V'] + $php), null);
    $expect('3 hydrant disposed last time is not carried', CarryOver::mapItem('hydranty', ['result' => 'vyradene'] + $hydrant), null);
    $expect('3 a source item lacking a value gets the blank', CarryOver::mapItem('hydranty', ['type' => 'DN25', 'location' => 'A', 'hose_count' => 1]), [
        'type' => 'DN25', 'location' => 'A', 'hose_count' => 1,
        'hs' => null, 'hd' => null, 'q' => null, 'defects' => null, 'result' => '',
    ]);
    // BOZP rows are unchanged: identification only, last time's výsledok as a badge.
    $ladder = [
        'inventory_number' => 'R-1', 'type' => 'Hliníkový', 'manufacturer' => 'Alve', 'year' => 2020, 'location' => 'Sklad',
        'faults' => 'Ohnutá priečka', 'result' => 'nevyhovuje', 'defects' => [['text' => 'x']],
    ];
    $expect('3 rebrík still carries identification only', CarryOver::mapItem('rebriky', $ladder), [
        'inventory_number' => 'R-1', 'type' => 'Hliníkový', 'manufacturer' => 'Alve', 'year' => 2020, 'location' => 'Sklad',
        'faults' => null, 'result' => '', 'defects' => [], 'previous_status' => 'nevyhovuje',
    ]);
    $expect('3 the fire types are the ones that carry values', array_values(array_filter(
        ['php', 'hydranty', 'ts_hadic', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie', 'rebriky', 'regale', 'oopp', 'oznacenie', 'pracovne_prostriedky'],
        [CarryOver::class, 'carriesValues'],
    )), ['php', 'hydranty', 'ts_hadic', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie']);

    // The device list names a svietidlo without an evidenčné číslo instead of dropping it.
    $label = static fn (string $type, array $f): ?string => FacilityDevices::shape($type, $f, true)['label'] ?? null;
    $expect('3 svietidlo with its number', $label('nudzove_osvetlenie', ['evid_number' => 'NO-1', 'luminaire_type' => 'LED']), 'NO-1');
    $expect('3 svietidlo without a number: its type', $label('nudzove_osvetlenie', ['evid_number' => '', 'luminaire_type' => 'LED panel', 'location' => 'Chodba']), 'LED panel');
    $expect('3 svietidlo without number and type: its place', $label('nudzove_osvetlenie', ['evid_number' => null, 'luminaire_type' => '', 'location' => 'Chodba']), 'Chodba');
    $expect('3 svietidlo with nothing: „Svietidlo"', $label('nudzove_osvetlenie', []), 'Svietidlo');
    $expect('3 a nameless hasiaci prístroj is still listed', $label('php', ['serial' => '', 'location' => 'Sklad']), 'Sklad');
    $expect('3 outside the device list a nameless item stays unnamed', FacilityDevices::shape('php', []), null);
    $shape = static fn (string $type, array $f): ?array => FacilityDevices::shape($type, $f, true);
    $expect('3 a place that became the name is not repeated as the place',
        array_intersect_key((array) $shape('nudzove_osvetlenie', ['floor' => '1.NP', 'location' => 'Chodba 3']), ['label' => 1, 'location' => 1]),
        ['label' => '1.NP · Chodba 3', 'location' => null]);
    $expect('3 a fallback label is cut to 80 characters',
        mb_strlen((string) ($shape('nudzove_osvetlenie', ['location' => str_repeat('Dlhá chodba ', 12)])['label'] ?? '')), 80);
    $expect('3 incompleteness names a svietidlo as step 1 does',
        $check('nudzove_osvetlenie', ['floor' => '1.NP', 'location' => 'Chodba 3', 'luminaire_type' => '', 'result' => 'vyhovuje', 'duration_min' => 60], 3)[0] ?? null,
        '1.NP · Chodba 3');

    // ── Part 4 — review of round 1 ───────────────────────────────────────────
    $ukonInsert = $pdo->prepare(
        'INSERT INTO inspections (account_id, company_id, facility_id, type, executed_on, inspector_user_id, status, created_by_user_id, details)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    /** @param array<string, mixed>|null $details */
    $ukon = static function (int $facilityId, string $type, ?string $on, string $status, ?array $details = null) use ($ukonInsert, $pdo, $accountId, $companyId, $userId): int {
        $ukonInsert->execute([$accountId, $companyId, $facilityId, $type, $on, $userId, $status, $userId,
            $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
        return (int) $pdo->lastInsertId();
    };

    // A — what a concept makes of a carried device (PrehliadkaConcepts::withResult).
    $withResult = new ReflectionMethod(PrehliadkaConcepts::class, 'withResult');
    $hit = ['defects' => [['item_id' => 7, 'text' => 'Chýba plomba']]];
    $carriedPhp = ['manufacturer' => 'Hasma', 'type' => 'P6', 'serial' => 'X1', 'year' => 2019, 'location' => 'Sklad', 'status' => 'TS', 'notes' => 'TS 2026'];
    $r = $withResult->invoke(null, 'php', $carriedPhp, $hit, true);
    $expect('4 a defect of today wins over the carried stav', [$r['fields']['status'], $r['fields']['notes'], $r['photo_items']], ['O', 'Chýba plomba', [7]]);
    $r = $withResult->invoke(null, 'php', $carriedPhp, null, true);
    $expect('4 without a defect the carried stav and note are kept', $r['fields'], $carriedPhp);
    $r = $withResult->invoke(null, 'hydranty', ['result' => ''] + $hydrant, null, true);
    $expect('4 an empty carried result stays empty, the texts stay', [$r['fields']['result'], $r['fields']['defects']], ['', 'Nízky prietok']);
    $expect('4 … and the item is reported as missing its result', $check('hydranty', $r['fields'])[1] ?? null, ['Výsledok kontroly']);
    $r = $withResult->invoke(null, 'hydranty', ['type' => 'other', 'type_other' => 'H-1', 'location' => 'Dvor'] + CarryOver::blank('hydranty'), null, false);
    $expect('4 a device written down on the walk gets the passing result', $r['fields']['result'], 'vyhovuje');
    $r = $withResult->invoke(null, 'rebriky', $ladder, null, true);
    $expect('4 a BOZP row still gets the passing result', $r['fields']['result'], 'vyhovuje');

    // B — the ts_hadic rows of the checklist and of the follow-up button (tsHadicRows).
    $f4 = $newFacility('Hadice');
    $hydrants = [['type' => 'C52', 'location' => 'Vchod', 'hose_count' => 1], ['type' => 'DN25', 'location' => 'Dvor', 'hose_count' => 1]];
    $built = PrehliadkaChecklist::tsHadicRows($accountId, $f4, $hydrants);
    $expect('4 no earlier ts hadíc: rows blank, result not presumed, no origin',
        [array_column($built['rows'], 'result'), $built['carried'], $built['origin']], [['', ''], [], null]);
    $lastTs = $ukon($f4, 'ts_hadic', '2025-10-01', 'finalized');
    $item($lastTs, 1, $hose);
    $built = PrehliadkaChecklist::tsHadicRows($accountId, $f4, $hydrants);
    $expect('4 the matching hose takes values and result',
        [$built['rows'][0]['test_pressure'], $built['rows'][0]['result'], $built['rows'][0]['manufacturer']], [1.5, 'vyhovuje', 'Pyrotex']);
    $expect('4 a hose with no match stays blank', [$built['rows'][1]['result'], $built['rows'][1]['test_pressure']], ['', null]);
    $expect('4 only the matched row is carried, the origin is named', [$built['carried'], $built['origin']['executed_on'] ?? null], [[0], '2025-10-01']);
    $expect('4 the follow-up button maps hydrants without a presumed result',
        array_column(InspectionController::mapHydrantyToTsHadic($hydrants), 'result'), ['', '']);

    // C — the recorded origin of Opakovať / „Prevziať položky".
    $origin = new ReflectionMethod(InspectionController::class, 'carriedOrigin');
    $expect('4 a fire type records where its values came from', $origin->invoke(null, $accountId, 'ts_hadic', $lastTs, 2), [
        PrehliadkaChecklist::CARRIED_FROM_KEY      => ['number' => null, 'executed_on' => '2025-10-01'],
        PrehliadkaChecklist::CARRIED_POSITIONS_KEY => [1, 2],
    ]);
    $expect('4 a BOZP type records no origin', $origin->invoke(null, $accountId, 'rebriky', $lastTs, 2), null);
    $expect('4 nothing carried, no origin', $origin->invoke(null, $accountId, 'hydranty', $lastTs, 0), null);

    // D — which drafts the completeness check holds back (CarryOver::checksCompleteness,
    // rounds 2 and 3).
    $carriedDetails = [PrehliadkaChecklist::CARRIED_FROM_KEY => null, PrehliadkaChecklist::CARRIED_POSITIONS_KEY => [1]];
    $row = static fn (string $type, ?array $details, ?int $from = null, int $issued = 0): array => [
        'type' => $type, 'status' => 'draft', 'details' => $details, 'carried_over_from_id' => $from, 'issued_before' => $issued,
    ];
    $expect('4 checked: a fire draft with a recorded origin', CarryOver::checksCompleteness($row('hydranty', $carriedDetails)), true);
    $expect('4 checked: a concept of any type', CarryOver::checksCompleteness($row('rebriky', [PrehliadkaConcepts::KEY => 5])), true);
    $expect('4 not checked: a draft typed through the forms or imported', CarryOver::checksCompleteness($row('hydranty', null)), false);
    $expect('4 not checked: an unlocked protocol without an origin', CarryOver::checksCompleteness($row('php', ['some' => 'header'])), false);
    // Round 3, A1.
    $expect('5 checked: an oprava follow-up with a recorded origin', CarryOver::checksCompleteness($row('oprava_ts_php', $carriedDetails)), true);
    $expect('5 checked: a vyradenie follow-up with a recorded origin', CarryOver::checksCompleteness($row('vyradenie', $carriedDetails)), true);
    $expect('5 checked: an Opakovať draft from before round 1 (no origin key, never issued)',
        CarryOver::checksCompleteness($row('php', null, 468)), true);
    $expect('5 not checked: an unlocked old protocol (carried_over_from_id, issued_contractor)',
        CarryOver::checksCompleteness($row('php', ['issued_contractor' => ['name' => 'X']], 455)), false);
    $expect('5 not checked: an unlocked old protocol (carried_over_from_id, issued_before)',
        CarryOver::checksCompleteness($row('php', null, 455, 1)), false);
    $expect('5 the oprava follow-up refuses a missing Rok výroby',
        PrehliadkaConcepts::incompleteItems('oprava_ts_php', [['id' => 1, 'position' => 1, 'fields' => ['manufacturer' => 'Hasma', 'type' => 'P6', 'serial' => 'X1', 'year' => null, 'location' => 'Sklad']]])[0]['missing'] ?? null,
        ['Rok výroby']);

    // E — carried positions follow deletes (PrehliadkaChecklist::dropCarriedPosition).
    $rep = $ukon($f4, 'hydranty', '2026-11-17', 'draft', [PrehliadkaChecklist::CARRIED_FROM_KEY => null, PrehliadkaChecklist::CARRIED_POSITIONS_KEY => [1, 2, 3, 4, 5]]);
    $positions = static fn (): array => json_decode((string) $pdo->query('SELECT details FROM inspections WHERE id = ' . $rep)->fetchColumn(), true)[PrehliadkaChecklist::CARRIED_POSITIONS_KEY];
    PrehliadkaChecklist::dropCarriedPosition($pdo, $rep, 5);
    $expect('4 a deleted carried item leaves the list', $positions(), [1, 2, 3, 4]);
    PrehliadkaChecklist::dropCarriedPosition($pdo, $rep, 9);
    $expect('4 a position not on the list changes nothing', $positions(), [1, 2, 3, 4]);
    $plain = $ukon($f4, 'hydranty', '2026-11-17', 'draft');
    PrehliadkaChecklist::dropCarriedPosition($pdo, $plain, 1);
    $expect('4 a draft without a list is left alone', $pdo->query('SELECT details FROM inspections WHERE id = ' . $plain)->fetchColumn(), null);

    // ── Part 5 — discarding an úkon (owner decision of 17. 11. 2026) ─────────
    $f5 = $newFacility('Zahodiť');
    $load = static fn (int $id): array => (array) $pdo->query(
        'SELECT id, account_id, type, status, details, issued_before, source_inspection_id FROM inspections WHERE id = ' . $id
    )->fetch(PDO::FETCH_ASSOC);
    $mode = static fn (int $id): array => UkonDiscard::decide($accountId, $load($id));

    $typed = $ukon($f5, 'hydranty', '2026-11-17', 'draft');
    $expect('5 a draft never issued is deleted for good', $mode($typed)['mode'], UkonDiscard::DELETE);
    $issued = $ukon($f5, 'hydranty', '2026-11-17', 'finalized');
    $expect('5 an issued úkon is archived', $mode($issued)['mode'], UkonDiscard::ARCHIVE);

    $unlocked = $ukon($f5, 'php', '2026-11-17', 'draft');
    $pdo->exec('UPDATE inspections SET issued_before = 1 WHERE id = ' . $unlocked);
    $expect('5 refused: a draft opened again with „Upraviť" (issued_before)', $mode($unlocked), ['mode' => null, 'reason' => UkonDiscard::REFUSE_ISSUED]);
    $snap = $ukon($f5, 'php', '2026-11-17', 'draft', ['issued_contractor' => ['name' => 'X']]);
    $expect('5 refused: a draft with the issue-time snapshot', $mode($snap)['mode'], null);
    $blank = $ukon($f5, 'dychova_skuska', '2026-11-17', 'draft');
    $pdo->prepare("INSERT INTO documents (account_id, parent_type, parent_id, type, number, form_variant, file_path) VALUES (?, 'inspection', ?, 'dychova_skuska', ?, 'prazdny', 'x.pdf')")
        ->execute([$accountId, $blank, 'R3-T-' . $blank]);
    $expect('5 refused: a test reopened with „Doplniť výsledky" (live document)', $mode($blank)['mode'], null);

    $withTask = $ukon($f5, 'hydranty', '2026-11-17', 'draft');
    $task->execute([$accountId, 'Úloha', $companyId, $f5, null, 0, $withTask, null, $userId]);
    $expect('5 refused: a draft a task was made from', $mode($withTask), ['mode' => null, 'reason' => UkonDiscard::REFUSE_MADE_FROM]);
    $withFollowUp = $ukon($f5, 'php', '2026-11-17', 'draft');
    $child = $ukon($f5, 'oprava_ts_php', '2026-11-17', 'draft');
    $pdo->exec("UPDATE inspections SET source_inspection_id = $withFollowUp WHERE id = $child");
    $expect('5 refused: a draft a follow-up was made from', $mode($withFollowUp)['mode'], null);
    $expect('5 … the follow-up itself may be deleted', $mode($child)['mode'], UkonDiscard::DELETE);

    $openPp = $prehliadka($f5, '2026-11-17', 'draft');
    $expect('5 a prehliadka never closed is deleted for good', $mode($openPp)['mode'], UkonDiscard::DELETE);
    $reopened = $prehliadka($f5, '2026-11-17', 'draft');
    $bozp = $ukon($f5, 'kniha_bozp', '2026-11-17', 'draft');
    $pdo->exec("UPDATE inspections SET source_inspection_id = $reopened WHERE id = $bozp");
    $expect('5 refused: a reopened prehliadka (its BOZP record exists)', $mode($reopened), ['mode' => null, 'reason' => UkonDiscard::REFUSE_PREHLIADKA_CLOSED]);
    $expect('5 refused: the kniha BOZP record of a prehliadka on its own', $mode($bozp), ['mode' => null, 'reason' => UkonDiscard::REFUSE_BOZP_CHILD]);
    $expect('5 the reopened prehliadka counts as closed before', UkonDiscard::prehliadkaClosedBefore($load($reopened)), true);
    $expect('5 the open one does not', UkonDiscard::prehliadkaClosedBefore($load($openPp)), false);

    $many = UkonDiscard::decideMany($accountId, [$load($typed), $load($issued), $load($withTask)]);
    $expect('5 the list decides every row at once', [$many[$typed]['mode'], $many[$issued]['mode'], $many[$withTask]['mode']],
        [UkonDiscard::DELETE, UkonDiscard::ARCHIVE, null]);
    $expect('5 another account sees nothing to delete', UkonDiscard::decideMany($otherAccountId, [$load($typed)])[$typed]['mode'], null);
} finally {
    $pdo->rollBack();
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
