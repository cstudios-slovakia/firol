<?php

declare(strict_types=1);

/**
 * Seed for the acceptance pass of the POapp zadanie 26. 10. 2026 (phase 9).
 *
 *     php bin/seed-acceptance.php            create the throwaway account and its data
 *     php bin/seed-acceptance.php --remove   delete it again (account, user, files)
 *
 * Local / staging only — it refuses to run when APP_ENV is "production".
 * Nothing here is shipped data: the account is `akceptacia@firol.test`, it owns
 *
 *   - "Firma s 50 PHP"        firma, one prevádzka with a finalized PHP úkon of
 *                             50 extinguishers (serial SN-2019-0001 … 0050),
 *                             6 hydrants and 3 emergency lights (routine controls
 *                             the walk-through can tick),
 *   - "Druhá firma s.r.o."    firma, one prevádzka with 3 extinguishers (used to
 *                             check that a custom catalogue item is shared across
 *                             firms but a custom activity is not),
 *   - "Bytové družstvo Lipová" bytové družstvo, three houses (Lipová 1, 2, 3) of
 *                             8 nadzemných + 1 podzemné podlažie, 4 extinguishers
 *                             each.
 *
 * The úkony are written finalized and without a document, so they feed the
 * device list and the carry-over but not the book offers; create documented
 * úkony through the app for those.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Db;
use Firol\Auth\Password;
use Firol\Legal\Terms;
use Firol\Support\AccountPurge;

const SEED_EMAIL = 'akceptacia@firol.test';
const SEED_PASSWORD = 'Akceptacia-2026!';

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '') === 'production') {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

$pdo = Db::pdo();

$existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$existing->execute([SEED_EMAIL]);
$userId = $existing->fetchColumn();

if (in_array('--remove', $argv, true)) {
    if ($userId === false) {
        echo "Nothing to remove.\n";
        exit(0);
    }
    $accountStmt = $pdo->prepare('SELECT account_id FROM account_users WHERE user_id = ?');
    $accountStmt->execute([$userId]);
    $accountId = (int) $accountStmt->fetchColumn();
    if ($accountId > 0) {
        AccountPurge::defectCatalog($accountId);
        AccountPurge::everything($accountId);
        // The account goes first: `main_user_id` is NOT NULL and points at the user.
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$accountId]);
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    echo "Removed account $accountId and user $userId.\n";
    exit(0);
}

if ($userId !== false) {
    fwrite(STDERR, "Already seeded (" . SEED_EMAIL . "). Run with --remove first.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO users (fullname, email, password_hash, is_pending, is_admin,
                            vop_version, vop_accepted_at, privacy_version, privacy_accepted_at)
         VALUES (?, ?, ?, 0, 0, ?, NOW(), ?, NOW())'
    )->execute(['Akceptačný technik', SEED_EMAIL, Password::hash(SEED_PASSWORD), Terms::VOP_VERSION, Terms::PRIVACY_VERSION]);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city,
                               invoice_ico, main_user_id, subscription_end_date, member_rights)
         VALUES ('Akceptácia s.r.o.', 'Skúšobná 1', '949 01', 'Nitra', '12345678', ?, '2027-12-31', 'plne')"
    )->execute([$userId]);
    $accountId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO account_users (account_id, user_id, role, is_active, initials) VALUES (?, ?, 'main', 1, 'AT')")
        ->execute([$accountId, $userId]);

    $pdo->prepare(
        "INSERT INTO inspector_profiles
            (user_id, account_id, certification_number, cert_php, cert_oprava, cert_general, cert_bt,
             valid_from, valid_to, valid_from_php, valid_to_php, valid_from_oprava, valid_to_oprava,
             valid_from_general, valid_to_general, valid_from_bt, valid_to_bt, is_active)
         VALUES (?, ?, 'T-2026-001', 'PHP-001/2026', 'OPR-001/2026', 'GEN-001/2026', 'BT-001/2026',
                 '2026-01-01', '2035-12-31', '2026-01-01', '2035-12-31', '2026-01-01', '2035-12-31',
                 '2026-01-01', '2035-12-31', '2026-01-01', '2035-12-31', 1)"
    )->execute([$userId, $accountId]);

    $cert = $pdo->prepare('INSERT INTO account_certificates (account_id, type, number, valid_from, valid_to) VALUES (?, ?, ?, ?, ?)');
    $cert->execute([$accountId, 'bts', 'BTS-ACC-0001', '2026-01-01', '2035-12-31']);
    $cert->execute([$accountId, 'vv', 'VV-ACC-0001', '2026-01-01', '2035-12-31']);

    $company = $pdo->prepare(
        'INSERT INTO companies (account_id, name, client_type, street, postal_code, city, ico, contact_email, approver)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $facility = $pdo->prepare(
        'INSERT INTO facilities (account_id, company_id, name, street, postal_code, city, contact_person,
                                 description, floors_above, floors_below)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $inspection = $pdo->prepare(
        "INSERT INTO inspections
            (account_id, company_id, facility_id, type, periodicity_value, periodicity_unit, executed_on,
             inspector_user_id, effective_inspector_user_id, effective_cert_number, effective_cert_valid_from,
             effective_cert_valid_to, status, created_by_user_id)
         VALUES (?, ?, ?, ?, 12, 'mesiac', ?, ?, ?, 'PHP-001/2026', '2026-01-01', '2035-12-31', 'finalized', ?)"
    );
    $item = $pdo->prepare('INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)');

    $day = (new DateTimeImmutable('today'))->modify('-6 months')->format('Y-m-d');

    /** @param list<array<string, mixed>> $items */
    $writeUkon = static function (int $companyId, int $facilityId, string $type, array $items) use ($inspection, $item, $pdo, $accountId, $userId, $day): void {
        $inspection->execute([$accountId, $companyId, $facilityId, $type, $day, $userId, $userId, $userId]);
        $inspectionId = (int) $pdo->lastInsertId();
        foreach ($items as $i => $fields) {
            $item->execute([$inspectionId, $i + 1, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
        }
    };

    $extinguishers = static function (string $prefix, int $count): array {
        $types = ['S6' => 'Práškový 6 kg', 'S2' => 'Práškový 2 kg', 'CO2' => 'CO2 5 kg'];
        $places = ['vchod', 'chodba 1. NP', 'dielňa', 'sklad A', 'kancelária', 'kotolňa', 'garáž'];
        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $kind = array_keys($types)[$n % 3];
            $rows[] = [
                'manufacturer' => ['Hasičák', 'Ognivo', 'Protec'][$n % 3],
                'type'         => $kind,
                'serial'       => sprintf('%s-%04d', $prefix, $n),
                'year'         => 2015 + ($n % 9),
                'location'     => $places[$n % count($places)],
                'status'       => 'A',
                'notes'        => null,
            ];
        }
        return $rows;
    };

    // --- Firma s 50 PHP ---------------------------------------------------
    $company->execute([$accountId, 'Firma s 50 PHP', 'firma', 'Hlavná 10', '949 01', 'Nitra', '31234567', 'info@firma50.test', 'Ing. Jana Vedúca']);
    $firmaA = (int) $pdo->lastInsertId();
    $facility->execute([$accountId, $firmaA, 'Sklad Nitra', 'Priemyselná 5', '949 01', 'Nitra', 'Pavol Skladník', 'Skladová hala, dve podlažia.', null, null]);
    $skladId = (int) $pdo->lastInsertId();
    $writeUkon($firmaA, $skladId, 'php', $extinguishers('SN-2019', 50));
    $writeUkon($firmaA, $skladId, 'hydranty', array_map(static fn (int $n): array => [
        'type' => 'C52', 'type_other' => null, 'location' => 'hydrant ' . $n, 'hose_count' => 1,
        'result' => 'vyhovuje', 'notes' => null,
    ], range(1, 6)));
    $writeUkon($firmaA, $skladId, 'nudzove_osvetlenie', array_map(static fn (int $n): array => [
        'evid_number' => 'NO-' . $n, 'floor' => '1. NP', 'luminaire_type' => 'LED', 'manufacturer' => 'Svetlo',
        'location' => 'chodba ' . $n, 'result' => 'vyhovuje', 'notes' => null,
    ], range(1, 3)));

    // --- Druhá firma ------------------------------------------------------
    $company->execute([$accountId, 'Druhá firma s.r.o.', 'firma', 'Vedľajšia 2', '811 01', 'Bratislava', '32345678', 'info@druha.test', null]);
    $firmaB = (int) $pdo->lastInsertId();
    $facility->execute([$accountId, $firmaB, 'Kancelária BA', 'Vedľajšia 2', '811 01', 'Bratislava', null, null, null, null]);
    $kancId = (int) $pdo->lastInsertId();
    $writeUkon($firmaB, $kancId, 'php', $extinguishers('BA-2020', 3));

    // --- Bytové družstvo with three houses --------------------------------
    $company->execute([$accountId, 'Bytové družstvo Lipová', 'bytove_druzstvo', 'Lipová 1', '949 01', 'Nitra', '33456789', 'sprava@lipova.test', 'Mgr. Eva Predsedníčka']);
    $bd = (int) $pdo->lastInsertId();
    foreach ([1, 2, 3] as $n) {
        $facility->execute([$accountId, $bd, "Lipová $n", "Lipová $n", '949 01', 'Nitra', null, null, 8, 1]);
        $houseId = (int) $pdo->lastInsertId();
        $writeUkon($bd, $houseId, 'php', $extinguishers("LP$n", 4));
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Seeded account $accountId, user $userId.\n";
echo 'Login: ' . SEED_EMAIL . ' / ' . SEED_PASSWORD . "\n";
