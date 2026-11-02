<?php

declare(strict_types=1);

/**
 * Check: the požiarna kniha offer „Termíny ďalších kontrol" follows a term the
 * technician overrode in a closed preventívna prehliadka (spec 6.6, Q5, owner
 * decision 2. 11. 2026) — for that one offer only.
 *
 *     docker compose exec php php bin/test-term-override-offer.php
 *
 * Everything runs inside one transaction that is rolled back at the end, so the
 * database is left as it was. Local / staging only.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Db;
use Firol\Support\BookMapping;
use Firol\Support\ClientTerms;

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
    $pdo->prepare(
        'INSERT INTO users (fullname, email, password_hash, is_pending, is_admin) VALUES (?, ?, ?, 0, 0)'
    )->execute(['Test termínov', 'test-terms-' . bin2hex(random_bytes(4)) . '@firol.test', 'x']);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city,
                               invoice_ico, main_user_id, subscription_end_date, member_rights)
         VALUES ('Test s.r.o.', 'Skúšobná 1', '949 01', 'Nitra', '12345678', ?, '2030-12-31', 'plne')"
    )->execute([$userId]);
    $accountId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO companies (account_id, name, client_type, street, postal_code, city, ico) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$accountId, 'Firma', 'firma', 'Ulica 1', '949 01', 'Nitra', '87654321']);
    $companyId = (int) $pdo->lastInsertId();

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
         VALUES (?, ?, ?, ?, 12, 'mesiac', ?, ?, ?, 'T-1', '2026-01-01', '2035-12-31', 'finalized', ?, ?, 1)"
    );
    /** @param array<string, mixed>|null $details */
    $ukon = static function (int $facilityId, string $type, string $on, ?array $details = null) use ($insert, $accountId, $companyId, $userId): void {
        $insert->execute([
            $accountId, $companyId, $facilityId, $type, $on, $userId, $userId, $userId,
            $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    };
    $prehliadka = static fn (array $overrides, array $routine = ['php'], bool $po = true): array => [
        'scope' => ['po' => $po, 'bozp' => !$po], 'routine' => $routine, 'term_overrides' => $overrides,
    ];
    $offer = static function (int $facilityId, string $asOf) use ($accountId, $companyId): array {
        $rows = [];
        foreach (ClientTerms::upcoming($accountId, $companyId, $facilityId, $asOf, BookMapping::PO) as $r) {
            $rows[$r['label']] = $r['valid'];
        }
        return $rows;
    };
    $php = ClientTerms::LABELS['php'];
    $pp = ClientTerms::LABELS['preventivna_prehliadka'];

    // 1 — the record said 15. 4. 2027, the offer used to say 2. 11. 2027.
    $f1 = $newFacility('Override');
    $ukon($f1, 'php', '2026-11-02');
    $ukon($f1, 'preventivna_prehliadka', '2026-11-02', $prehliadka(['routine:php' => '2027-04-15', 'prehliadka_po' => '2027-03-01']));
    $expect('1 control term follows the override', $offer($f1, '2026-11-02')[$php] ?? null, '2027-04-15');
    $expect('1 prehliadka term follows the override', $offer($f1, '2026-11-02')[$pp] ?? null, '2027-03-01');

    // The override is no new interval: the úkon keeps 12 months, and the
    // dated record table (no overrides involved) still reads from it.
    $periodicity = $pdo->prepare('SELECT periodicity_value, periodicity_unit FROM inspections WHERE facility_id = ? AND type = "php"');
    $periodicity->execute([$f1]);
    $expect('1 periodicity of the úkon unchanged', $periodicity->fetch(PDO::FETCH_ASSOC), ['periodicity_value' => 12, 'periodicity_unit' => 'mesiac']);
    $own = array_column(ClientTerms::forProtocol($accountId, $companyId, $f1, '2026-11-02', '', BookMapping::PO), 'valid_until', 'label');
    $expect('1 other terms table stays on the interval', $own[$php] ?? null, 'november 2027');

    // 2 — a later úkon of the same kind replaces the override with its own term.
    $f2 = $newFacility('Neskorší úkon');
    $ukon($f2, 'php', '2026-11-02');
    $ukon($f2, 'preventivna_prehliadka', '2026-11-02', $prehliadka(['routine:php' => '2027-04-15']));
    $ukon($f2, 'php', '2027-01-10');
    $expect('2 newer úkon brings the automatic term back', $offer($f2, '2027-01-10')[$php] ?? null, '2028-01-10');

    // 3 — a later prehliadka that ticks the control without an override clears it.
    $f3 = $newFacility('Neskoršia prehliadka');
    $ukon($f3, 'php', '2026-11-02');
    $ukon($f3, 'preventivna_prehliadka', '2026-11-02', $prehliadka(['routine:php' => '2027-04-15']));
    $ukon($f3, 'preventivna_prehliadka', '2027-01-05', $prehliadka([]));
    $expect('3 later prehliadka without override clears it', $offer($f3, '2027-01-05')[$php] ?? null, '2027-11-02');

    // 4 — a BOZP-only prehliadka governs nothing of the požiarna kniha.
    $f4 = $newFacility('BOZP');
    $ukon($f4, 'php', '2026-11-02');
    $ukon($f4, 'preventivna_prehliadka', '2026-11-02', $prehliadka(['routine:php' => '2027-04-15'], ['php'], false));
    $expect('4 BOZP-only prehliadka is ignored', $offer($f4, '2026-11-02')[$php] ?? null, '2027-11-02');

    // 5 — a control the prehliadka did not tick is not touched, and a malformed date is ignored.
    $f5 = $newFacility('Neodkliknuté');
    $ukon($f5, 'php', '2026-11-02');
    $ukon($f5, 'hydranty', '2026-11-02');
    $ukon($f5, 'preventivna_prehliadka', '2026-11-02', $prehliadka(['routine:php' => '2027-04-15', 'routine:hydranty' => 'not-a-date'], ['php', 'hydranty']));
    $o5 = $offer($f5, '2026-11-02');
    $expect('5 ticked control overridden', $o5[$php] ?? null, '2027-04-15');
    $expect('5 malformed override ignored', $o5[ClientTerms::LABELS['hydranty']] ?? null, '2027-11-02');

    // 6 — a control finalized the same day, but after the prehliadka (the concept it
    // created), keeps its own periodicity; the prehliadka's own term still follows its override.
    $f6 = $newFacility('Koncept v ten istý deň');
    $ukon($f6, 'preventivna_prehliadka', '2026-11-02', $prehliadka(['routine:php' => '2027-04-15', 'prehliadka_po' => '2027-03-01']));
    $ukon($f6, 'php', '2026-11-02');
    $o6 = $offer($f6, '2026-11-02');
    $expect('6 same-day control finalized after the prehliadka uses its own term', $o6[$php] ?? null, '2027-11-02');
    $expect('6 prehliadka term keeps its same-day override', $o6[$pp] ?? null, '2027-03-01');
} finally {
    $pdo->rollBack();
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
