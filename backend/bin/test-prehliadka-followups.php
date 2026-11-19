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
 *      deleted for good, an issued úkon archived, the rest refused;
 *   6. round 4: the old-Opakovať rule only for the fire types, the reason of
 *      the BOZP record of a prehliadka, the permanent delete re-decided under
 *      its lock (archived, issued or followed up meanwhile), and backup /
 *      restore keeping `issued_before` (also for a backup from before it).
 *      This part writes scratch accounts and removes them at the end;
 *   7. round 5: the BOZP item checks carry their result and texts (not their
 *      nedostatky), the completeness rule for them, no „dôvod zanikol" for a
 *      new defect, the fixed sentence of a bytové družstvo;
 *   8. round 5: restore of a backup from before `issued_before` (every draft
 *      issued, counted) and a draft named by a documents row not restored;
 *   9. round 5: požiarna kniha numbers PK-<KÓD>-NNN — codes, the series across
 *      a year change, „Upraviť" keeping the number, old numbers, a second
 *      account, a firma without a code, other types, two parallel issues.
 *      Writes scratch accounts and removes them at the end;
 *  10. round 6: a header save keeps the origin of carried items and the concept
 *      marks (an unconfirmed concept stays refused), rebríky / regály PDFs with
 *      a failing row (no „Array", the rows' nedostatky printed), a merge restore
 *      of a firma whose code the target holds (the next issue skips the restored
 *      numbers, a lost number makes an unlocked draft, true warnings, document
 *      versions kept, an issue during a restore), migrations 080 / 081, the
 *      client-kind code messages and the origin of a carried defect. Writes
 *      scratch accounts and removes them at the end.
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
use Firol\Support\UkonDiscardRefused;

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '') === 'production') {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

// Part 9 starts this script twice as a child that issues one book number and
// holds the firma's row lock for a while (two technicians at the same moment).
if (($argv[1] ?? '') === '--book-race') {
    $racePdo = Db::pdo();
    $racePdo->beginTransaction();
    $raceRow = $racePdo->query('SELECT id, company_id FROM inspections WHERE id = ' . (int) $argv[3] . ' FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    $raceNumber = \Firol\Documents\BookNumber::forIssue($racePdo, (int) $argv[2], $raceRow)['number'];
    usleep((int) $argv[4] * 1000);
    $racePdo->commit();
    echo $raceNumber;
    exit(0);
}

// Part 6 runs code that names its actor (the audit row of a permanent
// delete); the session has to exist before the first line is printed.
\Firol\Auth\Session::start();

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
    // BOZP item checks carry their result and texts too since the customer's
    // answer of 18. 11. 2026 (part 7); their structured nedostatky stay behind.
    $ladder = [
        'inventory_number' => 'R-1', 'type' => 'Hliníkový', 'manufacturer' => 'Alve', 'year' => 2020, 'location' => 'Sklad',
        'faults' => 'Ohnutá priečka', 'result' => 'nevyhovuje', 'defects' => [['text' => 'x']],
    ];
    $expect('3 rebrík carries its result and závady, not its nedostatky', CarryOver::mapItem('rebriky', $ladder), [
        'inventory_number' => 'R-1', 'type' => 'Hliníkový', 'manufacturer' => 'Alve', 'year' => 2020, 'location' => 'Sklad',
        'faults' => 'Ohnutá priečka', 'result' => 'nevyhovuje', 'defects' => [],
    ]);
    $expect('3 the device types are the ones that carry values', array_values(array_filter(
        ['php', 'hydranty', 'ts_hadic', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie', 'rebriky', 'regale', 'oopp', 'oznacenie', 'pracovne_prostriedky', 'kniha_bozp', 'pracovisko', 'skolenie_bozp'],
        [CarryOver::class, 'carriesValues'],
    )), ['php', 'hydranty', 'ts_hadic', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie', 'rebriky', 'regale', 'oopp', 'oznacenie', 'pracovne_prostriedky']);

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
    $r = $withResult->invoke(null, 'rebriky', (array) CarryOver::mapItem('rebriky', $ladder), null, true);
    $expect('7 a carried rebrík without a defect today keeps its result (answer 2 „asi A")', [$r['fields']['result'], $r['fields']['faults']], ['nevyhovuje', 'Ohnutá priečka']);
    $r = $withResult->invoke(null, 'rebriky', ['inventory_number' => 'R-9', 'type' => 'Drevený', 'manufacturer' => '', 'year' => null, 'location' => 'Dvor'] + CarryOver::blank('rebriky'), null, false);
    $expect('7 a rebrík written down on the walk gets the passing result', $r['fields']['result'], 'vyhovuje');

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
    $expect('7 a BOZP item check records its origin too', $origin->invoke(null, $accountId, 'rebriky', $lastTs, 2), [
        PrehliadkaChecklist::CARRIED_FROM_KEY      => ['number' => null, 'executed_on' => '2025-10-01'],
        PrehliadkaChecklist::CARRIED_POSITIONS_KEY => [1, 2],
    ]);
    $expect('4 a BOZP record type records no origin', $origin->invoke(null, $accountId, 'pracovisko', $lastTs, 1), null);
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
    // Round 4, item 7: the old-Opakovať rule is for the types that carry values —
    // since 18. 11. 2026 the BOZP item checks too, not the BOZP records.
    $expect('6 not checked: a BOZP record Opakovať draft from before round 1',
        CarryOver::checksCompleteness($row('pracovisko', null, 468)), false);
    $expect('7 checked: a BOZP item Opakovať draft from before round 1',
        CarryOver::checksCompleteness($row('rebriky', null, 468)), true);
    $expect('6 not checked: a book entry with carried_over_from_id',
        CarryOver::checksCompleteness($row('poziarna_kniha', null, 468)), false);
    $expect('6 still checked: a hydranty Opakovať draft from before round 1',
        CarryOver::checksCompleteness($row('hydranty', null, 468)), true);
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

    // Round 4, item 3: the BOZP record of a reopened prehliadka was issued with
    // it (issued_before) but cannot be issued on its own — its own reason wins.
    $pdo->exec('UPDATE inspections SET issued_before = 1 WHERE id = ' . $bozp);
    $expect('6 refused: an issued BOZP record of a prehliadka names the prehliadka, not „vystav znova"',
        $mode($bozp), ['mode' => null, 'reason' => UkonDiscard::REFUSE_BOZP_CHILD]);
    $expect('6 … also in the list', UkonDiscard::decideMany($accountId, [$load($bozp)])[$bozp]['reason'], UkonDiscard::REFUSE_BOZP_CHILD);
    $loneBozp = $ukon($f5, 'kniha_bozp', '2026-11-17', 'draft');
    $pdo->exec('UPDATE inspections SET issued_before = 1 WHERE id = ' . $loneBozp);
    $expect('6 a kniha BOZP record of its own issued before keeps „vystav znova"', $mode($loneBozp)['reason'], UkonDiscard::REFUSE_ISSUED);

    $many = UkonDiscard::decideMany($accountId, [$load($typed), $load($issued), $load($withTask)]);
    $expect('5 the list decides every row at once', [$many[$typed]['mode'], $many[$issued]['mode'], $many[$withTask]['mode']],
        [UkonDiscard::DELETE, UkonDiscard::ARCHIVE, null]);
    $expect('5 another account sees nothing to delete', UkonDiscard::decideMany($otherAccountId, [$load($typed)])[$typed]['mode'], null);

    // ── Part 7 — round 5: BOZP item checks carry the result, small changes ───
    $sorted7 = static function (?array $a): ?array {
        if ($a !== null) {
            ksort($a);
        }
        return $a;
    };
    $shelf = ['label' => 'R-A1', 'type' => 'Policový', 'capacity' => '200 kg', 'location' => 'Sklad', 'capacity_marked' => false,
        'faults' => 'Chýba štítok', 'result' => 'nevyhovuje', 'defects' => [['key' => 'k1', 'description' => 'x']]];
    $expect('7 regál carries capacity_marked, závady, result; not its nedostatky', $sorted7(CarryOver::mapItem('regale', $shelf)),
        $sorted7(['defects' => []] + $shelf));
    $ppe = ['position' => 'Zvárač', 'equipment' => 'Kukla, rukavice', 'provided' => true, 'used' => false, 'condition' => 'opotrebeny', 'notes' => 'Nepoužíva kuklu', 'defects' => []];
    $expect('7 OOPP carries poskytnuté, používané, stav, poznámku', $sorted7(CarryOver::mapItem('oopp', $ppe)), $sorted7($ppe));
    $tool = ['name' => 'Uhlová brúska', 'manufacturer_type' => 'Bosch GWS', 'inventory_number' => 'N-4', 'location' => 'Dielňa', 'faults' => 'Chýba kryt', 'result' => 'nevyhovuje', 'defects' => []];
    $expect('7 pracovný prostriedok carries závady and result', $sorted7(CarryOver::mapItem('pracovne_prostriedky', $tool)), $sorted7($tool));
    $sign = ['kind' => 'Únikový východ', 'location' => 'Hala', 'result' => 'vyhovuje', 'notes' => 'Vybledne', 'defects' => []];
    $expect('7 označenie carries stav and poznámku', $sorted7(CarryOver::mapItem('oznacenie', $sign)), $sorted7($sign));
    $expect('7 no previous_status badge on a carried BOZP row', array_key_exists('previous_status', (array) CarryOver::mapItem('oznacenie', $sign)), false);
    $expect('7 a rebrík disposed last time is still left behind', CarryOver::mapItem('rebriky', ['result' => 'vyradene'] + $ladder), null);

    // Opakovať / „Prevziať položky" go through carryItems + carriedOrigin.
    $carryItems = new ReflectionMethod(InspectionController::class, 'carryItems');
    $carried7 = $carryItems->invoke(null, 'rebriky', [$ladder, ['result' => 'vyradene'] + $ladder, ['result' => 'vyhovuje', 'faults' => null] + $ladder]);
    $expect('7 Opakovať carries two rebríky with their results, one disposed', [array_column($carried7['items'], 'result'), $carried7['disposed']], [['nevyhovuje', 'vyhovuje'], 1]);
    $expect('7 a carried rebrík with no result is held back („Chýba")', $check('rebriky', ['result' => ''] + (array) CarryOver::mapItem('rebriky', $ladder))[1] ?? null, ['Výsledok']);
    $expect('7 a carried regál with no označenie nosnosti is held back', $check('regale', ['capacity_marked' => null] + (array) CarryOver::mapItem('regale', $shelf))[1] ?? null, ['Označenie nosnosti']);
    $expect('7 a complete carried rebrík passes', $check('rebriky', (array) CarryOver::mapItem('rebriky', $ladder)), []);
    $expect('7 checked: a BOZP item draft with a recorded origin', CarryOver::checksCompleteness($row('oopp', $carriedDetails)), true);
    $expect('7 not checked: an imported BOZP item draft', CarryOver::checksCompleteness($row('oopp', null)), false);
    $expect('7 not checked: an unlocked BOZP item protocol', CarryOver::checksCompleteness($row('oznacenie', null, 455, 1)), false);

    // Part C1 — a new defect is not offered „dôvod zanikol" (answer 6 B).
    $zanik = ['title' => 'Chýba PHP', 'scope' => 'po', 'where' => ['place' => 'Chodba'], 'description' => 'Popis', 'deadline_choice' => 'bezodkladne', 'status' => 'zanik', 'reason' => 'Priestor zrušený.'];
    $zanikRefusal = static function (array $body, array $options = []): ?string {
        try {
            PrehliadkaDefects::validate($body, '2026-11-18', false, $options);
            return null;
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    };
    $expect('7 a new defect with „dôvod zanikol" is refused', str_starts_with((string) $zanikRefusal($zanik), 'Stav „dôvod zanikol" má len nedostatok prenesený'), true);
    $expect('7 … stored with it before, it keeps it', $zanikRefusal($zanik, ['allow_new_zanik' => true]), null);
    $expect('7 a carried defect may be „dôvod zanikol"', $zanikRefusal($zanik + ['persists_from' => ['key' => 'k-1', 'inspection_id' => 1, 'source_item_id' => null, 'number' => 'PPP-2026-001', 'executed_on' => '2026-01-01']]), null);
    $expect('7 a new defect „zistený" is fine', $zanikRefusal(['status' => 'zisteny'] + $zanik), null);

    // Part C2 — the fixed sentence of a book entry (answer 9b).
    $expect('7 firma, one prevádzka', \Firol\Support\BookSentences::fixed('2026-11-18', ['Sklad']), 'Dňa 18. 11. 2026 bola vykonaná prehliadka v prevádzke Sklad.');
    $expect('7 firma, two prevádzky', \Firol\Support\BookSentences::fixed('2026-11-18', ['Sklad', 'Hala']), 'Dňa 18. 11. 2026 bola vykonaná prehliadka v prevádzkach Sklad, Hala.');
    $expect('7 bytové družstvo, one dom', \Firol\Support\BookSentences::fixed('2026-11-18', ['Hlavná 5'], true), 'Dňa 18. 11. 2026 bola vykonaná prehliadka v bytovom dome Hlavná 5.');
    $expect('7 bytové družstvo, two domy', \Firol\Support\BookSentences::fixed('2026-11-18', ['Hlavná 5', 'Krátka 2'], true), 'Dňa 18. 11. 2026 bola vykonaná prehliadka v bytových domoch Hlavná 5, Krátka 2.');
} finally {
    $pdo->rollBack();
}

// ── Part 6 — round 4: the permanent delete under its lock, backup / restore ──
// UkonDiscard::delete() and Restorer::run() open transactions of their own, so
// this part cannot sit inside the rolled-back one above: it writes scratch
// accounts (users test-followups-r4-…@firol.test) and removes them, their rows
// and files at the end.
$r4Users = [];
$r4Accounts = [];
$r4Files = [];
try {
    $r4Account = static function (string $label) use ($pdo, &$r4Users, &$r4Accounts): array {
        $pdo->prepare(
            'INSERT INTO users (fullname, email, password_hash, is_pending, is_admin) VALUES (?, ?, ?, 0, 0)'
        )->execute(['Test R4 ' . $label, 'test-followups-r4-' . bin2hex(random_bytes(4)) . '@firol.test', 'x']);
        $userId = (int) $pdo->lastInsertId();
        $r4Users[] = $userId;
        $pdo->prepare(
            "INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city,
                                   invoice_ico, main_user_id, subscription_end_date, member_rights)
             VALUES ('R4 Test s.r.o.', 'Skúšobná 1', '949 01', 'Nitra', '12345678', ?, '2030-12-31', 'plne')"
        )->execute([$userId]);
        $accountId = (int) $pdo->lastInsertId();
        $r4Accounts[] = $accountId;
        return [$accountId, $userId];
    };
    [$a6, $u6] = $r4Account('zdroj');
    $_SESSION['user_id'] = $u6;
    $pdo->prepare('INSERT INTO companies (account_id, name, client_type, street, postal_code, city, ico) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$a6, 'R4 Firma', 'firma', 'Ulica 1', '949 01', 'Nitra', '87654321']);
    $c6 = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO facilities (account_id, company_id, name, street, postal_code, city) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$a6, $c6, 'R4 Prevádzka', 'Ulica 2', '949 01', 'Nitra']);
    $f6 = (int) $pdo->lastInsertId();
    $ins6 = $pdo->prepare(
        'INSERT INTO inspections (account_id, company_id, facility_id, type, executed_on, inspector_user_id, status,
                                  issued_before, created_by_user_id, details, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    // A merge restore matches an úkon by prevádzka, type and date, so each row
    // of the backup check gets a date of its own.
    $u6row = static function (string $type, string $status, int $issued = 0, ?array $details = null, ?string $notes = null, string $on = '2026-11-18')
        use ($ins6, $pdo, $a6, $c6, $f6, $u6): int {
        $ins6->execute([$a6, $c6, $f6, $type, $on, $u6, $status, $issued, $u6,
            $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE), $notes]);
        return (int) $pdo->lastInsertId();
    };
    $row6 = static fn (int $id): array|false => $pdo->query(
        'SELECT id, account_id, type, status, details, issued_before, source_inspection_id, facility_id, company_id, executed_on
         FROM inspections WHERE id = ' . $id
    )->fetch(PDO::FETCH_ASSOC);
    $refusal = static function (int $id, array $snapshot) use ($a6): ?string {
        try {
            UkonDiscard::delete($a6, $snapshot);
            return null;
        } catch (UkonDiscardRefused $e) {
            return $e->reason();
        }
    };

    // Item 3 — what changes between the check and the lock. Each snapshot is
    // the row as the controller decided on it; the change lands after.
    $race = $u6row('hydranty', 'draft');
    $snapshot = $row6($race);
    $expect('6 race: decided as a permanent delete', UkonDiscard::decide($a6, $snapshot)['mode'], UkonDiscard::DELETE);
    $pdo->exec('UPDATE inspections SET archived_at = NOW() WHERE id = ' . $race);
    $expect('6 race: a draft archived meanwhile is refused with its own reason', $refusal($race, $snapshot), UkonDiscard::REFUSE_GONE);
    $expect('6 race: … and is still there', $row6($race) !== false, true);

    $race = $u6row('php', 'draft');
    $snapshot = $row6($race);
    $pdo->exec('UPDATE inspections SET issued_before = 1 WHERE id = ' . $race);
    $expect('6 race: a draft issued meanwhile answers „vystav znova"', $refusal($race, $snapshot), UkonDiscard::REFUSE_ISSUED);

    $race = $u6row('php', 'draft');
    $snapshot = $row6($race);
    $u6row('oprava_ts_php', 'draft');
    $pdo->exec('UPDATE inspections SET source_inspection_id = ' . $race . ' WHERE id = LAST_INSERT_ID()');
    $expect('6 race: a draft followed up meanwhile answers „vznikli ďalšie záznamy"', $refusal($race, $snapshot), UkonDiscard::REFUSE_MADE_FROM);

    $gone = $u6row('hydranty', 'draft');
    $snapshot = $row6($gone);
    $expect('6 a never-issued draft is deleted', [$refusal($gone, $snapshot), $row6($gone)], [null, false]);
    $expect('6 a refusal is not a RuntimeException, so a database error is never read as one',
        is_subclass_of(UkonDiscardRefused::class, RuntimeException::class), false);

    // Item 4 — backup and restore keep issued_before.
    $u6row('php', 'draft', 1, null, 'R4-unlocked', '2026-10-01');
    $u6row('php', 'draft', 0, null, 'R4-never', '2026-10-02');
    $u6row('php', 'finalized', 1, ['issued_contractor' => ['name' => 'X']], 'R4-final', '2026-10-03');
    $u6row('php', 'draft', 1, ['issued_contractor' => ['name' => 'X']], 'R4-snapshot', '2026-10-04');
    $blank6 = $u6row('dychova_skuska', 'draft', 1, null, 'R4-document', '2026-10-05');
    $number6 = 'R4-T-' . $blank6;
    $docRel = \Firol\Storage\Storage::documentRelative($a6, 2026, $number6);
    $docAbs = \Firol\Storage\Storage::absolute($docRel);
    @mkdir(dirname($docAbs), 0775, true);
    file_put_contents($docAbs, "%PDF-1.4\n% R4 test\n");
    $r4Files[] = $docAbs;
    $pdo->prepare("INSERT INTO documents (account_id, parent_type, parent_id, type, number, form_variant, file_path, generated_at)
                   VALUES (?, 'inspection', ?, 'dychova_skuska', ?, 'prazdny', ?, '2026-11-18 10:00:00')")
        ->execute([$a6, $blank6, $number6, $docRel]);

    $archive = \Firol\Backup\Writer::build($a6, false, true);
    $r4Files[] = $archive['path'];
    $zip = new ZipArchive();
    $zip->open($archive['path']);
    $manifest = json_decode((string) $zip->getFromName(\Firol\Backup\Archive::MANIFEST), true);
    $zip->close();
    $exported = array_column($manifest['inspections'], 'issued_before', 'notes');
    $expect('6 the backup carries issued_before', isset($exported['R4-unlocked']) ? (int) $exported['R4-unlocked'] : null, 1);

    $issuedAfter = static function (int $accountId) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT notes, issued_before FROM inspections WHERE account_id = ? AND notes LIKE "R4-%" ORDER BY notes');
        $stmt->execute([$accountId]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'issued_before', 'notes'));
    };
    [$b6, $ub6] = $r4Account('obnova');
    \Firol\Backup\Restorer::run($b6, $ub6, $archive['path'], \Firol\Backup\Restorer::MODE_MERGE);
    $expect('6 restore keeps issued_before of every úkon', $issuedAfter($b6), [
        'R4-document' => 1, 'R4-final' => 1, 'R4-never' => 0, 'R4-snapshot' => 1, 'R4-unlocked' => 1,
    ]);
    $restoredUnlocked = $pdo->query("SELECT id, account_id, type, status, details, issued_before, source_inspection_id
                                     FROM inspections WHERE account_id = $b6 AND notes = 'R4-unlocked'")->fetch(PDO::FETCH_ASSOC);
    $expect('6 … so a restored unlocked protocol cannot be deleted for good',
        UkonDiscard::decide($b6, $restoredUnlocked), ['mode' => null, 'reason' => UkonDiscard::REFUSE_ISSUED]);

    // A backup written before the column existed: the same archive without it.
    $legacyPath = $archive['path'] . '-legacy.zip';
    copy($archive['path'], $legacyPath);
    $r4Files[] = $legacyPath;
    foreach ($manifest['inspections'] as &$legacyRow) {
        unset($legacyRow['issued_before']);
    }
    unset($legacyRow);
    $zip = new ZipArchive();
    $zip->open($legacyPath);
    $zip->addFromString(\Firol\Backup\Archive::MANIFEST, json_encode($manifest, JSON_UNESCAPED_UNICODE));
    $zip->close();
    [$l6, $ul6] = $r4Account('stara-zaloha');
    $legacyResult = \Firol\Backup\Restorer::run($l6, $ul6, $legacyPath, \Firol\Backup\Restorer::MODE_MERGE);
    // Round 5, item 4 (b): an archive without the key cannot tell an unlocked
    // protocol from a never-issued draft — every draft comes back as issued.
    $expect('8 an old backup: every draft counts as issued (safe default)', $issuedAfter($l6), [
        'R4-document' => 1, 'R4-final' => 1, 'R4-never' => 1, 'R4-snapshot' => 1, 'R4-unlocked' => 1,
    ]);
    // Every restored draft without the contractor snapshot (part 6's race rows
    // included; a row the merge matched to another is not restored) was marked
    // by the default.
    $assumed = (int) $pdo->query("SELECT COUNT(*) FROM inspections WHERE account_id = $l6 AND status = 'draft'
        AND (details IS NULL OR NOT JSON_EXISTS(details, '$.issued_contractor'))")->fetchColumn();
    $expect('8 … and the result says how many drafts were marked that way',
        [$legacyResult['issued_assumed'], count(array_filter($legacyResult['warnings'], static fn (string $w): bool => str_contains($w, 'označen')))], [$assumed, 1]);
    $restoredNever = $pdo->query("SELECT id, account_id, type, status, details, issued_before, source_inspection_id, carried_over_from_id
                                  FROM inspections WHERE account_id = $l6 AND notes = 'R4-never'")->fetch(PDO::FETCH_ASSOC);
    $expect('8 … the completeness rule does not change for them (no carried_over_from_id)',
        [$restoredNever['carried_over_from_id'], CarryOver::checksCompleteness($restoredNever)], [null, false]);

    // Round 5, item 4 (a): a current archive whose documents row is not
    // restored (its PDF is missing from the archive) still marks its draft.
    $neverId = null;
    foreach ($manifest['inspections'] as $mi) {
        if (($mi['notes'] ?? null) === 'R4-never') {
            $neverId = (int) $mi['id'];
        }
    }
    $withKey = json_decode((string) json_encode($manifest), true);
    foreach ($withKey['inspections'] as &$keyRow) {
        $keyRow['issued_before'] = ($keyRow['notes'] ?? null) === 'R4-never' ? 0 : 1;
    }
    unset($keyRow);
    $withKey['documents'][] = [
        'number' => 'R4-MISSING-' . $neverId, 'parent_type' => 'inspection', 'parent_id' => $neverId, 'type' => 'php',
        'file' => 'documents/missing-from-archive.pdf', 'generated_at' => '2026-11-18 10:00:00', 'signed' => 1,
    ];
    $missingPath = $archive['path'] . '-missing.zip';
    copy($archive['path'], $missingPath);
    $r4Files[] = $missingPath;
    $zip = new ZipArchive();
    $zip->open($missingPath);
    $zip->addFromString(\Firol\Backup\Archive::MANIFEST, json_encode($withKey, JSON_UNESCAPED_UNICODE));
    $zip->close();
    [$m6, $um6] = $r4Account('chyba-pdf');
    $missingResult = \Firol\Backup\Restorer::run($m6, $um6, $missingPath, \Firol\Backup\Restorer::MODE_MERGE);
    $expect('8 a draft named by a documents row that was not restored is issued', [$issuedAfter($m6)['R4-never'] ?? null, $missingResult['issued_assumed']], [1, 0]);
} catch (Throwable $e) {
    $failures++;
    echo 'FAIL part 6 stopped: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($r4Accounts as $acc) {
        \Firol\Support\AccountPurge::everything($acc);
        $pdo->prepare('DELETE FROM inspections WHERE account_id = ?')->execute([$acc]);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$acc]);
    }
    foreach ($r4Users as $uid) {
        $pdo->prepare("DELETE FROM audit_log WHERE actor_user_id = ?")->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    }
    foreach ($r4Files as $file) {
        @unlink($file);
    }
}

// ── Part 9 — round 5: numbers of požiarna kniha entries (answer 3) ──────────
// Issues real protocols (DocumentController opens its own transactions), so
// like part 6 it writes scratch accounts and removes them, rows and files.
$r5Users = [];
$r5Accounts = [];
try {
    $r5Account = static function (string $label) use ($pdo, &$r5Users, &$r5Accounts): array {
        $pdo->prepare('INSERT INTO users (fullname, email, password_hash, is_pending, is_admin) VALUES (?, ?, ?, 0, 0)')
            ->execute(['Test R5 ' . $label, 'test-followups-r5-' . bin2hex(random_bytes(4)) . '@firol.test', 'x']);
        $userId = (int) $pdo->lastInsertId();
        $r5Users[] = $userId;
        $pdo->prepare(
            "INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city,
                                   invoice_ico, main_user_id, subscription_end_date, member_rights)
             VALUES ('R5 Test s.r.o.', 'Skúšobná 1', '949 01', 'Nitra', '12345678', ?, '2030-12-31', 'plne')"
        )->execute([$userId]);
        $accountId = (int) $pdo->lastInsertId();
        $r5Accounts[] = $accountId;
        return [$accountId, $userId];
    };
    $r5Company = static function (int $accountId, string $name, string $type = 'firma') use ($pdo): array {
        $pdo->prepare('INSERT INTO companies (account_id, name, client_type, street, postal_code, city, ico) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $name, $type, 'Ulica 1', '949 01', 'Nitra', '87654321']);
        $companyId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO facilities (account_id, company_id, name, street, postal_code, city) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $companyId, 'Sklad ' . $name, 'Ulica 2', '949 01', 'Nitra']);
        return [$companyId, (int) $pdo->lastInsertId()];
    };
    $r5Entry = static function (int $accountId, int $userId, int $companyId, int $facilityId, string $on) use ($pdo): int {
        $details = ['format' => 'pk2', 'sposob' => 2, 'sposob_rucne' => false, 'uvod' => null, 'cinnosti' => [], 'vlastne_cinnosti' => [],
            'doplnujuci_text' => '', 'text_zapisu' => 'Kontrola stavu ochrany pred požiarmi.', 'zaznamy' => [], 'zaver' => '', 'schvalil' => 'Ján Test'];
        $pdo->prepare(
            'INSERT INTO inspections (account_id, company_id, facility_id, type, executed_on, inspector_user_id, status,
                                      created_by_user_id, details, is_preventive_inspection)
             VALUES (?, ?, ?, "poziarna_kniha", ?, ?, "draft", ?, ?, 0)'
        )->execute([$accountId, $companyId, $facilityId, $on, $userId, $userId, json_encode($details, JSON_UNESCAPED_UNICODE)]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO inspection_facilities (account_id, inspection_id, facility_id) VALUES (?, ?, ?)')
            ->execute([$accountId, $id, $facilityId]);
        return $id;
    };
    $issue = static function (int $accountId, int $userId, int $id): array {
        $r = \Firol\Controllers\DocumentController::generateForInspectionInternal($accountId, $userId, $id, false);
        return isset($r['document']) ? ['number' => $r['document']['number'], 'version' => (int) $r['document']['version'], 'file' => $r['document']['file_path']] : ['error' => $r['error'] ?? '?'];
    };
    // „Upraviť" as InspectionController::unlock() does it to the rows.
    $unlock = static function (int $accountId, int $id) use ($pdo): void {
        $pdo->prepare("DELETE FROM documents WHERE account_id = ? AND parent_type = 'inspection' AND parent_id = ?")->execute([$accountId, $id]);
        $pdo->prepare("UPDATE inspections SET status = 'draft', issued_before = 1 WHERE id = ? AND account_id = ?")->execute([$id, $accountId]);
        $pdo->prepare("UPDATE inspections SET details = JSON_REMOVE(details, '$.issued') WHERE id = ? AND JSON_EXISTS(details, '$.issued')")->execute([$id]);
    };
    $code = static fn (int $companyId): ?string => ($v = $pdo->query('SELECT book_code FROM companies WHERE id = ' . $companyId)->fetchColumn()) === false ? null : $v;
    $BN = \Firol\Documents\BookNumber::class;

    // The code from the name.
    $expect('9 „Stavby Novák s.r.o." → STA', $BN::candidates('Stavby Novák s.r.o.')[0] ?? null, 'STA');
    $expect('9 „Železiarne" → ZEL (diacritics removed)', $BN::candidates('Železiarne')[0] ?? null, 'ZEL');
    $expect('9 „3M Slovensko" → MSL (letters only)', $BN::candidates('3M Slovensko')[0] ?? null, 'MSL');
    $expect('9 next variants: first two + following letters, then a digit',
        array_slice($BN::candidates('Stavby'), 0, 6), ['STA', 'STV', 'STB', 'STY', 'ST1', 'ST2']);
    $expect('9 a name of two letters uses what it has', array_slice($BN::candidates('A.B.'), 0, 2), ['AB', 'AB1']);
    $expect('9 a name of one letter has no proposal', $BN::candidates('Q 1'), []);
    $codeRefusal = static function (string $raw) use ($BN): ?string {
        try {
            return $BN::normalizeCode($raw);
        } catch (\InvalidArgumentException $e) {
            return 'refused';
        }
    };
    $expect('9 a typed code is upper-cased; 2–4 letters or digits with a letter',
        array_map($codeRefusal, ['sta', 'ab12', 'S', 'STAVB', 'ŠT', '2026']), ['STA', 'AB12', 'refused', 'refused', 'refused', 'refused']);

    [$a9, $u9] = $r5Account('kniha');
    [$cSta, $fSta] = $r5Company($a9, 'Stavby Novák s.r.o.');
    [$cStv, $fStv] = $r5Company($a9, 'Stavebniny Kováč');
    $BN::assignProposal($a9, $cSta, 'Stavby Novák s.r.o.');
    $BN::assignProposal($a9, $cStv, 'Stavebniny Kováč');
    $expect('9 two firms with the same first three letters get different codes', [$code($cSta), $code($cStv)], ['STA', 'STV']);

    // Three entries of one firma across a year change: 001, 002, 003.
    $e1 = $r5Entry($a9, $u9, $cSta, $fSta, '2026-12-20');
    $e2 = $r5Entry($a9, $u9, $cSta, $fSta, '2027-01-05');
    $e3 = $r5Entry($a9, $u9, $cSta, $fSta, '2027-01-10');
    $i1 = $issue($a9, $u9, $e1);
    $expect('9 the code is locked once the first entry is issued', (int) $pdo->query("SELECT book_last_seq FROM companies WHERE id = $cSta")->fetchColumn() > 0, true);
    $i2 = $issue($a9, $u9, $e2);
    $i3 = $issue($a9, $u9, $e3);
    $expect('9 three entries across a year change: 001, 002, 003', [$i1['number'] ?? $i1, $i2['number'] ?? $i2, $i3['number'] ?? $i3], ['PK-STA-001', 'PK-STA-002', 'PK-STA-003']);
    $expect('9 the file sits in the year folder of the entry', [str_contains((string) ($i1['file'] ?? ''), '/2026/PK-STA-001.pdf'), str_contains((string) ($i2['file'] ?? ''), '/2027/PK-STA-002.pdf')], [true, true]);

    // „Upraviť" + issue again: the same number as version 2; the PDF says it.
    $unlock($a9, $e2);
    $re = $issue($a9, $u9, $e2);
    $expect('9 unlock + issue keeps the number, as version 2', [$re['number'] ?? $re, $re['version'] ?? null, str_ends_with((string) ($re['file'] ?? ''), 'PK-STA-002-v2.pdf')], ['PK-STA-002', 2, true]);
    $pdfText = (string) @file_get_contents(\Firol\Storage\Storage::documentAbsolute((string) ($re['file'] ?? 'x')));
    $expect('9 … the re-issued file is a PDF', str_starts_with($pdfText, '%PDF'), true);
    $e4 = $r5Entry($a9, $u9, $cSta, $fSta, '2027-02-01');
    $expect('9 the next entry continues the series without a gap', $issue($a9, $u9, $e4)['number'] ?? null, 'PK-STA-004');
    $expect('9 drafts hold no number: four issues, counter at 4', (int) $pdo->query("SELECT book_last_seq FROM companies WHERE id = $cSta")->fetchColumn(), 4);

    // An entry issued before this change keeps PK-RRRR-NNN, also after „Upraviť".
    $old = $r5Entry($a9, $u9, $cStv, $fStv, '2026-05-01');
    $pdo->exec("UPDATE inspections SET book_number = 'PK-2026-950', book_number_version = 1, issued_before = 1 WHERE id = $old");
    $expect('9 an old PK-2026-… entry is issued again under its number', $issue($a9, $u9, $old)['number'] ?? null, 'PK-2026-950');
    $expect('9 … and the firma\'s own series is untouched', (int) $pdo->query("SELECT book_last_seq FROM companies WHERE id = $cStv")->fetchColumn(), 0);

    // A second account may use the same code and numbers.
    [$b9, $ub9] = $r5Account('druhy');
    [$cOther, $fOther] = $r5Company($b9, 'Stavby Iné');
    $o1 = $r5Entry($b9, $ub9, $cOther, $fOther, '2026-11-18');
    $expect('9 another account: STA and 001 again', [$issue($b9, $ub9, $o1)['number'] ?? null, $code($cOther)], ['PK-STA-001', 'STA']);

    // A firma whose name gives no code: refused with the reason, nothing taken.
    [$cQ, $fQ] = $r5Company($a9, 'Q 1');
    $q1 = $r5Entry($a9, $u9, $cQ, $fQ, '2026-11-18');
    $expect('9 a firma without a code is refused with the reason', $issue($a9, $u9, $q1)['error'] ?? null, $BN::NO_CODE_MESSAGE);
    $pdo->exec("UPDATE companies SET book_code = 'Q1X' WHERE id = $cQ");
    $expect('9 … and numbered once the user typed one', $issue($a9, $u9, $q1)['number'] ?? null, 'PK-Q1X-001');

    // Other protocol types are numbered as before.
    $phpNo = \Firol\Documents\NumberAllocator::allocate($a9, 'php', 2026)['number'];
    $expect('9 other protocols keep PREFIX-RRRR-NNN', $phpNo, 'PHP-2026-001');

    // Two technicians at the same moment: two processes, the firma's row lock.
    $r1 = $r5Entry($a9, $u9, $cStv, $fStv, '2026-11-18');
    $r2 = $r5Entry($a9, $u9, $cStv, $fStv, '2026-11-18');
    $spawn = static function (int $id, int $sleepMs) use ($a9) {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __FILE__, '--book-race', (string) $a9, (string) $id, (string) $sleepMs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return [$proc, $pipes];
    };
    $p1 = $spawn($r1, 1500);
    $p2 = $spawn($r2, 0);
    $out1 = trim((string) stream_get_contents($p1[1][1]));
    $out2 = trim((string) stream_get_contents($p2[1][1]));
    $err = trim((string) stream_get_contents($p1[1][2]) . stream_get_contents($p2[1][2]));
    proc_close($p1[0]);
    proc_close($p2[0]);
    $pair = [$out1, $out2];
    sort($pair);
    $expect('9 two parallel issues for one firma: two consecutive numbers' . ($err !== '' ? " ($err)" : ''), $pair, ['PK-STV-001', 'PK-STV-002']);
} catch (Throwable $e) {
    $failures++;
    echo 'FAIL part 9 stopped: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($r5Accounts as $acc) {
        $files = $pdo->prepare('SELECT v.file_path FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.account_id = ?');
        $files->execute([$acc]);
        foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $rel) {
            @unlink(\Firol\Storage\Storage::documentAbsolute((string) $rel));
        }
        // Versions an „Upraviť" left behind on disk are not in document_versions.
        foreach (glob(\Firol\Storage\Storage::documentAbsolute("documents/$acc") . '/*/*.pdf') ?: [] as $leftover) {
            @unlink($leftover);
        }
        \Firol\Support\AccountPurge::everything($acc);
        $pdo->prepare('DELETE FROM inspections WHERE account_id = ?')->execute([$acc]);
        $pdo->prepare('DELETE FROM document_sequences WHERE account_id = ?')->execute([$acc]);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$acc]);
    }
    foreach ($r5Users as $uid) {
        $pdo->prepare('DELETE FROM audit_log WHERE actor_user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    }
}

// ── Part 10 — round 6: what the check of round 5 found ──────────────────────
// Issues and restores (own transactions), so like parts 6 and 9 it writes
// scratch accounts (users test-followups-r6-…@firol.test) and removes them.
$r6Users = [];
$r6Accounts = [];
try {
    $r6Account = static function (string $label) use ($pdo, &$r6Users, &$r6Accounts): array {
        $pdo->prepare('INSERT INTO users (fullname, email, password_hash, is_pending, is_admin) VALUES (?, ?, ?, 0, 0)')
            ->execute(['Test R6 ' . $label, 'test-followups-r6-' . bin2hex(random_bytes(4)) . '@firol.test', 'x']);
        $userId = (int) $pdo->lastInsertId();
        $r6Users[] = $userId;
        $pdo->prepare(
            "INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city,
                                   invoice_ico, main_user_id, subscription_end_date, member_rights)
             VALUES ('R6 Test s.r.o.', 'Skúšobná 1', '949 01', 'Nitra', '12345678', ?, '2030-12-31', 'plne')"
        )->execute([$userId]);
        $accountId = (int) $pdo->lastInsertId();
        $r6Accounts[] = $accountId;
        return [$accountId, $userId];
    };
    $r6Company = static function (int $accountId, string $name, string $type = 'firma') use ($pdo): array {
        $pdo->prepare('INSERT INTO companies (account_id, name, client_type, street, postal_code, city, ico) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $name, $type, 'Ulica 1', '949 01', 'Nitra', '87654321']);
        $companyId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO facilities (account_id, company_id, name, street, postal_code, city) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $companyId, 'Sklad ' . $name, 'Ulica 2', '949 01', 'Nitra']);
        return [$companyId, (int) $pdo->lastInsertId()];
    };
    $r6Ukon = static function (int $accountId, int $userId, int $companyId, int $facilityId, string $type, string $on, ?array $details, array $rows) use ($pdo): int {
        $pdo->prepare(
            'INSERT INTO inspections (account_id, company_id, facility_id, type, executed_on, inspector_user_id, status, created_by_user_id, details, is_preventive_inspection)
             VALUES (?, ?, ?, ?, ?, ?, "draft", ?, ?, 0)'
        )->execute([$accountId, $companyId, $facilityId, $type, $on, $userId, $userId, $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE)]);
        $id = (int) $pdo->lastInsertId();
        foreach ($rows as $i => $fields) {
            $pdo->prepare('INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)')
                ->execute([$id, $i + 1, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
        }
        if ($type === 'poziarna_kniha') {
            $pdo->prepare('INSERT INTO inspection_facilities (account_id, inspection_id, facility_id) VALUES (?, ?, ?)')
                ->execute([$accountId, $id, $facilityId]);
        }
        return $id;
    };
    $r6Issue = static function (int $accountId, int $userId, int $id): array {
        $r = \Firol\Controllers\DocumentController::generateForInspectionInternal($accountId, $userId, $id, false);
        return isset($r['document'])
            ? ['number' => $r['document']['number'], 'version' => (int) $r['document']['version']]
            : ['error' => $r['error'] ?? '?', 'status' => $r['status'] ?? null];
    };
    $ID = \Firol\Support\InspectionDetails::class;
    $BN6 = \Firol\Documents\BookNumber::class;

    // Item 1 — a header save keeps the keys the server wrote.
    $origin = ['carried_from' => ['number' => 'REB-2026-001', 'executed_on' => '2026-10-01'], 'carried_positions' => [1, 3]];
    $header = [
        'rebriky'              => ['measures_text' => 'Vymeniť pätku.'],
        'regale'               => ['measures_text' => 'Vymeniť policu.'],
        'pracovne_prostriedky' => ['measures' => [['measure' => 'Doplniť kryt', 'deadline' => null, 'immediately' => true]]],
        'oznacenie'            => ['measures' => [['measure' => 'Vymeniť tabuľku', 'deadline' => '2026-12-31', 'immediately' => false]]],
        'oopp'                 => ['conclusion' => 'Záver kontroly.'],
    ];
    foreach ($header as $type => $sent) {
        $merged = $ID::mergeOver($origin + ['issued_contractor' => ['name' => 'X']], $ID::validate($type, $sent));
        $key = (string) array_key_first($sent);
        $expect("10 $type: saving opatrenia / záver keeps the origin, the marks and the snapshot",
            [$merged['carried_from'] ?? null, $merged['carried_positions'] ?? null, isset($merged['issued_contractor']), $merged[$key] !== null],
            [$origin['carried_from'], $origin['carried_positions'], true, true]);
    }
    $expect('10 a cleared field is still cleared', $ID::mergeOver(['measures_text' => 'Staré.'] + $origin, $ID::validate('rebriky', []))['measures_text'], null);
    $expect('10 details: null keeps only the keys the server wrote', $ID::serverOwned('rebriky', ['measures_text' => 'x'] + $origin), $origin);

    [$a10, $u10] = $r6Account('prenos');
    [$c10, $f10] = $r6Company($a10, 'Prenos Test');
    $ladder6 = ['inventory_number' => 'R-1', 'type' => 'Dvojitý', 'manufacturer' => null, 'year' => null, 'location' => 'Sklad',
        'faults' => null, 'result' => 'vyhovuje', 'defects' => []];
    // A concept of rebríky, never confirmed: saving its opatrenia must not make it issuable.
    $concept = $r6Ukon($a10, $u10, $c10, $f10, 'rebriky', '2026-11-12', [
        'concept_from_prehliadka' => 1, 'concept_built_at' => '2026-11-12 10:00:00', 'concept_item_count' => 1,
        'concept_confirmed_at' => null, 'carried_from' => null, 'carried_positions' => [],
    ], [$ladder6]);
    $stored = $ID::decode($pdo->query("SELECT details FROM inspections WHERE id = $concept")->fetchColumn());
    $pdo->prepare('UPDATE inspections SET details = ? WHERE id = ?')
        ->execute([$ID::encode($ID::mergeOver($stored, $ID::validate('rebriky', ['measures_text' => 'Opatrenie.']))), $concept]);
    $expect('10 a concept is still unconfirmed after saving opatrenia, and generate refuses it', $r6Issue($a10, $u10, $concept),
        ['error' => 'Koncept z prehliadky — potvrď výsledky.', 'status' => 422]);

    // Item 2 — rebríky / regály with a failing row: závady, a nedostatok only, neither.
    $nedostatok = ['key' => 'k-r6', 'description' => 'Prasknutá priečka', 'measure' => 'Vymeniť priečku', 'deadline' => '2026-12-15'];
    $shelf6 = ['label' => 'A1', 'type' => 'Policový', 'capacity' => '200 kg', 'location' => 'Sklad', 'capacity_marked' => true];
    $rows6 = [
        'rebriky' => [
            ['faults' => 'Chýba pätka', 'result' => 'nevyhovuje'] + $ladder6,
            ['faults' => null, 'result' => 'nevyhovuje', 'defects' => [$nedostatok], 'inventory_number' => 'R-2'] + $ladder6,
            ['faults' => null, 'result' => 'nevyhovuje', 'inventory_number' => 'R-3'] + $ladder6,
            ['result' => 'vyhovuje', 'defects' => [['key' => 'k-r6b', 'description' => 'Odretý lak'] + $nedostatok], 'inventory_number' => 'R-4'] + $ladder6,
        ],
        'regale' => [
            ['faults' => 'Ohnutá polica', 'result' => 'nevyhovuje', 'defects' => []] + $shelf6,
            ['faults' => null, 'result' => 'nevyhovuje', 'defects' => [$nedostatok]] + $shelf6,
            ['faults' => null, 'result' => 'nevyhovuje', 'defects' => []] + $shelf6,
        ],
    ];
    foreach ($rows6 as $type => $rows) {
        $u = $r6Ukon($a10, $u10, $c10, $f10, $type, '2026-11-15', ['measures_text' => null], $rows);
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $payload = \Firol\Controllers\DocumentController::buildPayload($a10, $u10,
                \Firol\Controllers\DocumentController::loadInspectionForGenerate($a10, $u), \Firol\Controllers\DocumentController::loadItems($u));
            $html = \Firol\Pdf\PdfRenderer::renderHtmlForType($type, $payload);
        } finally {
            restore_error_handler();
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $expect("10 $type PDF: no „Array\", no PHP warning", [substr_count($html, 'Array'), $warnings], [0, []]);
        preg_match_all('#Zistený stav</td><td[^>]*>(.*?)</td>#s', $html, $states);
        $expect("10 $type PDF: zistený stav from the závady, else the nedostatok, else „Nevyhovuje\"",
            array_map(static fn (string $s): string => html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'), $states[1]),
            $type === 'rebriky'
                ? ['Chýba pätka', 'Prasknutá priečka', 'Nevyhovuje', 'Odretý lak']
                : ['Ohnutá polica', 'Prasknutá priečka', 'Nevyhovuje']);
        $expect("10 $type PDF: the row's nedostatok with its opatrenie and termín", [str_contains($text, 'Vymeniť priečku'), str_contains($text, '15. 12. 2026')], [true, true]);
        $expect("10 $type PDF: one „Zistené nedostatky\" band", substr_count($text, 'Zistené nedostatky'), 1);
        $expect("10 $type: the protocol is issued", isset($r6Issue($a10, $u10, $u)['number']), true);
    }

    // Item 3 — a merge restore of a firma whose code the target account holds.
    $bookDetails = ['format' => 'pk2', 'sposob' => 2, 'sposob_rucne' => false, 'uvod' => null, 'cinnosti' => [], 'vlastne_cinnosti' => [],
        'doplnujuci_text' => '', 'text_zapisu' => 'Kontrola stavu ochrany pred požiarmi.', 'zaznamy' => [], 'zaver' => '', 'schvalil' => 'Ján Test'];
    [$aSrc, $uSrc] = $r6Account('zdroj');
    [$cSrc, $fSrc] = $r6Company($aSrc, 'Stavby Novák s.r.o.');
    $pdo->exec("UPDATE companies SET ico = '11223344' WHERE id = $cSrc"); // a merge matches firms by IČO
    $BN6::assignProposal($aSrc, $cSrc, 'Stavby Novák s.r.o.');
    $src = [];
    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $on) {
        $src[] = $id = $r6Ukon($aSrc, $uSrc, $cSrc, $fSrc, 'poziarna_kniha', $on, $bookDetails, []);
        $r6Issue($aSrc, $uSrc, $id);
    }
    // The second entry opened with „Upraviť" and issued again: version 2.
    $pdo->prepare("DELETE FROM documents WHERE account_id = ? AND parent_type = 'inspection' AND parent_id = ?")->execute([$aSrc, $src[1]]);
    $pdo->prepare("UPDATE inspections SET status = 'draft', issued_before = 1, details = JSON_REMOVE(details, '$.issued') WHERE id = ?")->execute([$src[1]]);
    $expect('10 source: PK-STA-002 issued again as version 2', $r6Issue($aSrc, $uSrc, $src[1]), ['number' => 'PK-STA-002', 'version' => 2]);
    $archive6 = \Firol\Backup\Writer::build($aSrc, false, true);

    [$aDst, $uDst] = $r6Account('ciel');
    [$cDst, $fDst] = $r6Company($aDst, 'Stavebniny Kováč');
    $BN6::assignProposal($aDst, $cDst, 'Stavebniny Kováč');
    $dst1 = $r6Ukon($aDst, $uDst, $cDst, $fDst, 'poziarna_kniha', '2026-10-01', $bookDetails, []);
    $expect('10 target: its own firma holds STA', $r6Issue($aDst, $uDst, $dst1)['number'] ?? null, 'PK-STA-001');

    $result6 = \Firol\Backup\Restorer::run($aDst, $uDst, $archive6['path'], \Firol\Backup\Restorer::MODE_MERGE);
    @unlink($archive6['path']);
    $restoredFirma = (int) $pdo->query("SELECT id FROM companies WHERE account_id = $aDst AND name = 'Stavby Novák s.r.o.'")->fetchColumn();
    $entries = $pdo->query("SELECT i.executed_on, i.status, i.issued_before, i.book_number, i.book_number_version,
                                   (SELECT GROUP_CONCAT(d.number, ' v', d.version) FROM documents d WHERE d.account_id = i.account_id AND d.parent_type = 'inspection' AND d.parent_id = i.id) AS docs
                            FROM inspections i WHERE i.account_id = $aDst AND i.company_id = $restoredFirma ORDER BY i.executed_on")->fetchAll(PDO::FETCH_ASSOC);
    $expect('10 restore: the firma gets another code, its counter at 0', [$code6 = (string) $pdo->query("SELECT book_code FROM companies WHERE id = $restoredFirma")->fetchColumn(),
        (int) $pdo->query("SELECT book_last_seq FROM companies WHERE id = $restoredFirma")->fetchColumn()], ['STV', 0]);
    $expect('10 restore: PK-STA-001 is taken here — that entry is an unlocked draft without a number or a document',
        array_values($entries[0] ?? []), ['2026-09-01', 'draft', 1, null, 0, null]);
    $expect('10 restore: PK-STA-002 keeps its number and version 2 (entry and document)',
        array_values($entries[1] ?? []), ['2026-09-02', 'finalized', 1, 'PK-STA-002', 2, 'PK-STA-002 v2']);
    $expect('10 restore: PK-STA-003 keeps its number', [$entries[2]['book_number'] ?? null, $entries[2]['docs'] ?? null], ['PK-STA-003', 'PK-STA-003 v1']);
    $w = implode("\n", $result6['warnings']);
    $expect('10 restore: the warnings say what happened to each entry', [
        str_contains($w, 'Firma „Stavby Novák s.r.o.": kód „STA" už v tomto účte patrí inému klientovi — dostala kód „STV"'),
        str_contains($w, 'PK-STA-002, PK-STA-003. Ten klient tieto čísla pri ďalších zápisoch preskočí.'),
        str_contains($w, 'Zápis do požiarnej knihy č. PK-STA-001: toto číslo už v účte má iný zápis — zápis je obnovený ako rozpracovaný'),
        !str_contains($w, 'si čísla ponechali'), // the old, untrue „vystavené zápisy si čísla ponechali" is gone
    ], [true, true, true, true]);
    $dst2 = $r6Ukon($aDst, $uDst, $cDst, $fDst, 'poziarna_kniha', '2026-11-01', $bookDetails, []);
    $expect('10 the next issue of the firma holding STA skips the restored numbers (no 500)', $r6Issue($aDst, $uDst, $dst2)['number'] ?? null, 'PK-STA-004');
    $lostId = (int) $pdo->query("SELECT id FROM inspections WHERE account_id = $aDst AND company_id = $restoredFirma AND executed_on = '2026-09-01'")->fetchColumn();
    $expect('10 the entry that lost its number is issued under the firma\'s new code', $r6Issue($aDst, $uDst, $lostId)['number'] ?? null, 'PK-STV-001');

    // A code is taken also by numbers of its series alone.
    $pdo->prepare("INSERT INTO documents (account_id, parent_type, parent_id, type, number, file_path, generated_at) VALUES (?, 'inspection', ?, 'poziarna_kniha', 'PK-QUA-007', 'x.pdf', NOW())")
        ->execute([$aDst, $dst1]);
    $expect('10 a code only numbers use is taken (proposal and edit)', [$BN6::isCodeTaken($aDst, 'QUA'), $BN6::propose($aDst, 'Quarta')], [true, 'QUR']);

    // Lock order: the restore's first statement locks the firms; an issue at the
    // same moment waits, then skips a number the restore wrote.
    $dst3 = $r6Ukon($aDst, $uDst, $cDst, $fDst, 'poziarna_kniha', '2026-11-02', $bookDetails, []);
    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM companies WHERE account_id = ? ORDER BY id FOR UPDATE')->execute([$aDst]);
    $pdo->prepare("INSERT INTO documents (account_id, parent_type, parent_id, type, number, file_path, generated_at) VALUES (?, 'inspection', ?, 'poziarna_kniha', 'PK-STA-005', 'y.pdf', NOW())")
        ->execute([$aDst, $dst1]);
    $racePipes = [];
    $raceProc = proc_open([PHP_BINARY, __FILE__, '--book-race', (string) $aDst, (string) $dst3, '0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $racePipes);
    usleep(1_200_000);
    $raceStatus = proc_get_status($raceProc);
    $pdo->commit();
    $raceOut = trim((string) stream_get_contents($racePipes[1]));
    $raceErr = trim((string) stream_get_contents($racePipes[2]));
    proc_close($raceProc);
    $expect('10 an issue during a restore waits for it, then skips its numbers' . ($raceErr !== '' ? " ($raceErr)" : ''),
        [$raceStatus['running'], $raceOut], [true, 'PK-STA-006']);

    // Migration 080's backfill takes the newest documents row of an entry.
    $two = $r6Ukon($aDst, $uDst, $cDst, $fDst, 'poziarna_kniha', '2026-08-01', $bookDetails, []);
    $pdo->exec("UPDATE inspections SET status = 'finalized' WHERE id = $two");
    $pdo->prepare("INSERT INTO documents (account_id, parent_type, parent_id, type, number, version, file_path, generated_at) VALUES
                   (?, 'inspection', ?, 'poziarna_kniha', 'PK-2026-881', 1, 'a.pdf', NOW()), (?, 'inspection', ?, 'poziarna_kniha', 'PK-2026-880', 3, 'b.pdf', NOW())")
        ->execute([$aDst, $two, $aDst, $two]);
    $sql080 = (string) file_get_contents(__DIR__ . '/../db/migrations/080_book_numbering.sql');
    $backfill = substr($sql080, (int) strpos($sql080, 'UPDATE inspections i'));
    $pdo->exec(rtrim(trim($backfill), ';') . " AND i.account_id = $aDst");
    $expect('10 migration 080: an entry with two documents rows takes the newest version',
        $pdo->query("SELECT CONCAT(book_number, ' v', book_number_version) FROM inspections WHERE id = $two")->fetchColumn(), 'PK-2026-880 v3');

    // Item 4 — the wording of a bytové družstvo, an archived firma, the stored proposals.
    $codeMsg = static function (string $raw, string $kind) use ($BN6): string {
        try {
            $BN6::normalizeCode($raw, $kind);
            return '';
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    };
    $expect('10 the code messages use the client kind', [$codeMsg('X', 'bytove_druzstvo'), $codeMsg('12', 'firma'),
        \Firol\Support\ClientKind::bookCodeLocked('bytove_druzstvo'), \Firol\Support\ClientKind::bookCodeTaken('BYT', 'bytove_druzstvo')], [
        'Kód bytového družstva musí mať 2 až 4 znaky — písmená bez diakritiky alebo číslice.',
        'Kód firmy musí obsahovať aspoň jedno písmeno.',
        'Kód sa už nedá zmeniť — bytové družstvo má vystavený zápis do požiarnej knihy.',
        'Kód „BYT" už má iné bytové družstvo tohto účtu. Zvoľ iný.',
    ]);
    [$aMig, ] = $r6Account('migracia');
    [$cVer1, ] = $r6Company($aMig, 'Verex s.r.o.');
    [$cVer2, ] = $r6Company($aMig, 'Verona a.s.');
    [$cQ6, ] = $r6Company($aMig, 'Q 1');
    $migration081 = require __DIR__ . '/../db/migrations/081_book_code_proposals.php';
    $migration081($pdo);
    $expect('10 migration 081: two old firms with the same letters get different stored codes, by id; a name without one keeps none',
        $pdo->query("SELECT book_code FROM companies WHERE id IN ($cVer1, $cVer2, $cQ6) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN), ['VER', 'VEO', null]);

    // Item 5 — the origin of a carried defect must name a defect of its record.
    $pp = $prehliadkaId6 = $r6Ukon($a10, $u10, $c10, $f10, 'preventivna_prehliadka', '2026-10-05', null,
        [['title' => 'Chýba PHP', 'scope' => 'po', 'description' => 'Popis', 'status' => 'zisteny']]);
    $ppItem = (int) $pdo->query("SELECT id FROM inspection_items WHERE inspection_id = $pp")->fetchColumn();
    $PP = \Firol\Support\PrehliadkaPrevious::class;
    $expect('10 an origin names a defect of its record; a made-up one does not',
        [$PP::namesDefectOf("p$pp-$ppItem", $pp), $PP::namesDefectOf("p$pp-999999999", $pp), $PP::namesDefectOf("p1-$ppItem", $pp), $PP::namesDefectOf('x-1', $pp)],
        [true, false, false, false]);
} catch (Throwable $e) {
    $failures++;
    echo 'FAIL part 10 stopped: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($r6Accounts as $acc) {
        $files = $pdo->prepare('SELECT v.file_path FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.account_id = ?');
        $files->execute([$acc]);
        foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $rel) {
            @unlink(\Firol\Storage\Storage::documentAbsolute((string) $rel));
        }
        foreach (glob(\Firol\Storage\Storage::documentAbsolute("documents/$acc") . '/*/*.pdf') ?: [] as $leftover) {
            @unlink($leftover);
        }
        \Firol\Support\AccountPurge::everything($acc);
        $pdo->prepare('DELETE FROM inspections WHERE account_id = ?')->execute([$acc]);
        $pdo->prepare('DELETE FROM document_sequences WHERE account_id = ?')->execute([$acc]);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$acc]);
    }
    foreach ($r6Users as $uid) {
        $pdo->prepare('DELETE FROM audit_log WHERE actor_user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    }
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
