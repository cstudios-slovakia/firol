<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\InspectionPhotoController;
use Firol\Db;

/**
 * A požiarna kniha entry in the new format (POapp 7. 11. 2026, plan K1–K7).
 *
 * The entry is an ordinary úkon of the type `poziarna_kniha` whose content is
 * its header (`inspections.details`, `format` = {@see self::FORMAT}); its items
 * are the defects (the shared defect screen, {@see PrehliadkaDefects}). Old
 * entries keep their single-item shape and stay readable; nothing here
 * applies to them.
 *
 * `details`:
 *
 *   format            'pk2'
 *   sposob            1 = the entry is the prehliadka (moves the terms of its
 *                     prevádzky), 2 = prehliadky are attached (moves nothing)
 *   sposob_rucne      the technician overrode the default of {@see defaultSposob()}
 *   uvod              filled with {@see self::LEGAL} once, on creation; never again
 *   cinnosti          ticked labels of {@see activitiesFor()}, in list order
 *   vlastne_cinnosti  the technician's own lines
 *   doplnujuci_text   spôsob 1, printed after the attachments
 *   text_zapisu       spôsob 2, printed before them
 *   zaznamy           [{kind: inspection|training, id, forma: plny|odkaz}]
 *   zaver             free text at the end
 *   schvalil          the client's person printed under „Schválil za klienta"
 *   issued            written by the server when the PDF is generated (what
 *                     was printed: prevádzky, fixed sentence, paragraphs,
 *                     schvalil); removed on unlock
 *
 * Switching spôsob deletes nothing: both texts, the Úvod, the activities and
 * the per-prevádzka periodicities stay stored and the screens show what
 * belongs to the spôsob in force.
 *
 * Prevádzky: one `inspection_facilities` row per selected prevádzka, the
 * primary one ({@see inspections.facility_id}) included, each with its own
 * periodicity (12 / 6 / 3 months or none). While the entry is spôsob 1,
 * `inspections.is_preventive_inspection` = 1 and `inspections.periodicity_*`
 * mirror the primary row; in spôsob 2 they are 0 / NULL — {@see syncHeader()}.
 */
final class BookEntry
{
    public const TYPE = 'poziarna_kniha';

    public const FORMAT = 'pk2';

    /** Spec 5 — the Úvod an entry starts with. */
    public const LEGAL = 'Vykonaná preventívna protipožiarna prehliadka v zmysle zákona č. 314/2001 Z. z. '
        . 'o ochrane pred požiarmi v znení neskorších predpisov a vyhlášky MV SR č. 121/2002 Z. z. '
        . 'o požiarnej prevencii v znení neskorších predpisov.';

    /** Spec 6.1 — a firma: the nine points, no letters. */
    public const FIRMA_ACTIVITIES = FirePreventionPoints::FIRMA;

    /** Spec 6.2 — a bytový dom keeps the list the book has today (PK_ACTIVITY_LABELS, in PK_ACTIVITIES order). */
    public const DOM_ACTIVITIES = [
        'Vizuálna kontrola priestorov spoločnosti',
        'Kontrola stavu, označenia a dostupnosti PHP',
        'Kontrola stavu, označenia a dostupnosti požiarnych hydrantov',
        'Kontrola stavu, označenia a voľnosti únikových ciest',
        'Kontrola akcieschopnosti požiarnych uzáverov',
        'Vykonané vstupné školenie z predpisov OPP',
        'Vykonané opakované školenie vedúcich a ostatných zamestnancov',
        'Kontrola stavu používaných elektrických zariadení',
        'Kontrola stavu používaných technických zariadení',
        'Kontrola stavu používaných elektrických spotrebičov',
        'Kontrola aktuálnosti dokumentácie ochrany pred požiarmi',
        'Kontrola aktuálneho zoznamu zamestnancov a ich školení',
        'Vykonaný cvičný požiarny poplach',
    ];

    /** Spec 8 — the two sentences the „Na záver" buttons append. */
    public const NA_ZAVER = [
        'V čase kontroly na kontrolovaných pracoviskách neboli zistené žiadne nedostatky.',
        'Nedostatky z predchádzajúceho zápisu boli odstránené.',
    ];

    /** Spec 4 — above the activities on the screen. */
    public const ACTIVITIES_HEADING_NOW = 'Obsahom prehliadky je kontrola:';

    /** Spec 6.3 / 9 — above the activities in the document. */
    public const ACTIVITIES_HEADING_PRINT = 'Obsahom prehliadky bola kontrola:';

    /** Spec 4 — the periodicities an entry offers per prevádzka, in months. */
    public const PERIODICITY_MONTHS = [12, 6, 3];

    private const MAX_UVOD = 4000;
    private const MAX_TEXT = 5000;
    private const MAX_OWN = 30;
    private const MAX_OWN_LEN = 200;
    private const MAX_RECORDS = 200;

    /** @param array<string, mixed>|null $details */
    public static function isNew(?array $details): bool
    {
        return is_array($details) && ($details['format'] ?? null) === self::FORMAT;
    }

    /**
     * SQL: row `$s` is a new-format book entry. Always true or false, never
     * NULL — an old entry has no `format` (JSON_VALUE gives NULL), and a NULL
     * would make `NOT isNewSql(...)` drop the old entry too.
     */
    public static function isNewSql(string $s): string
    {
        return "($s.type = 'poziarna_kniha' AND COALESCE(JSON_VALUE($s.details, '$.format'), '') = 'pk2')";
    }

    /** Spec 4 — one prevádzka → spôsob 1, two or more → spôsob 2. */
    public static function defaultSposob(int $count): int
    {
        return $count === 1 ? 1 : 2;
    }

    /** @return list<string> */
    public static function activitiesFor(?string $clientType): array
    {
        return $clientType === 'bytove_druzstvo' ? self::DOM_ACTIVITIES : self::FIRMA_ACTIVITIES;
    }

    // ── Validation ───────────────────────────────────────────────────────────

    /**
     * The shape of `details` off the wire. What depends on the account (the
     * activity list, the records offered, the prefills) is {@see applyContext()}.
     * `uvod` / `schvalil` stay null when not sent: on create the server fills
     * them, on an edit the stored value stays.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function validateShape(mixed $raw): array
    {
        if (!is_array($raw) || ($raw['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('Chýba nastavenie zápisu.');
        }
        $sposob = $raw['sposob'] ?? null;
        if (is_string($sposob) && ctype_digit($sposob)) {
            $sposob = (int) $sposob;
        }
        if ($sposob !== 1 && $sposob !== 2) {
            throw new \InvalidArgumentException('Vyber spôsob zápisu.');
        }

        $uvod = $raw['uvod'] ?? null;
        if ($uvod !== null) {
            if (!is_string($uvod)) {
                throw new \InvalidArgumentException('Úvod má nesprávny tvar.');
            }
            self::assertLength($uvod, self::MAX_UVOD, 'Úvod');
        }

        $cinnosti = [];
        foreach ((array) ($raw['cinnosti'] ?? []) as $c) {
            if (!is_string($c)) {
                throw new \InvalidArgumentException('Neznáma vykonaná činnosť.');
            }
            $cinnosti[] = $c;
        }

        $own = [];
        foreach ((array) ($raw['vlastne_cinnosti'] ?? []) as $c) {
            if (!is_string($c) || ($c = trim($c)) === '') {
                continue;
            }
            if (mb_strlen($c) > self::MAX_OWN_LEN) {
                throw new \InvalidArgumentException('Vlastná činnosť je príliš dlhá (najviac ' . self::MAX_OWN_LEN . ' znakov).');
            }
            $own[] = $c;
        }
        if (count($own) > self::MAX_OWN) {
            throw new \InvalidArgumentException('Vlastných činností môže byť najviac ' . self::MAX_OWN . '.');
        }

        $zaznamy = [];
        $seen = [];
        foreach ((array) ($raw['zaznamy'] ?? []) as $z) {
            if (!is_array($z)) {
                continue;
            }
            $kind = $z['kind'] ?? null;
            $id = $z['id'] ?? null;
            if (is_string($id) && ctype_digit($id)) {
                $id = (int) $id;
            }
            if (!in_array($kind, ['inspection', 'training'], true) || !is_int($id) || $id <= 0) {
                continue;
            }
            $k = $kind . ':' . $id;
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $zaznamy[] = ['kind' => $kind, 'id' => $id, 'forma' => ($z['forma'] ?? null) === 'odkaz' ? 'odkaz' : 'plny'];
        }
        if (count($zaznamy) > self::MAX_RECORDS) {
            throw new \InvalidArgumentException('Priložených záznamov je príliš veľa.');
        }

        $schvalil = $raw['schvalil'] ?? null;
        if ($schvalil !== null) {
            if (!is_string($schvalil)) {
                throw new \InvalidArgumentException('Schválil má nesprávny tvar.');
            }
            $schvalil = trim($schvalil);
            if (mb_strlen($schvalil) > 191) {
                throw new \InvalidArgumentException('Meno pri „Schválil" je príliš dlhé (najviac 191 znakov).');
            }
        }

        return [
            'format'           => self::FORMAT,
            'sposob'           => $sposob,
            'sposob_rucne'     => ($raw['sposob_rucne'] ?? false) === true,
            'uvod'             => $uvod,
            'cinnosti'         => $cinnosti,
            'vlastne_cinnosti' => $own,
            'doplnujuci_text'  => self::text($raw['doplnujuci_text'] ?? '', 'Doplňujúci text'),
            'text_zapisu'      => self::text($raw['text_zapisu'] ?? '', 'Text zápisu'),
            'zaznamy'          => $zaznamy,
            'zaver'            => self::text($raw['zaver'] ?? '', 'Text na záver'),
            'schvalil'         => $schvalil,
        ];
    }

    /**
     * Completes validated details against the account:
     *   - activities must be on the client type's list (deduplicated, list order);
     *   - an attached record must be one {@see BookRecords} offers for a selected
     *     prevádzka (or the organisation's training); others are dropped silently,
     *     as a replayed request may name a record that moved on;
     *   - uvod / schvalil: prefilled on create, the stored value kept on an edit;
     *   - `issued` is the server's and never comes from the client.
     *
     * @param array<string, mixed> $details from {@see validateShape()}
     * @param array{account_id: int, company_id: int, client_type: ?string, facility_ids: list<int>, stored: ?array<string, mixed>, creating: bool} $ctx
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function applyContext(array $details, array $ctx): array
    {
        $allowed = self::activitiesFor($ctx['client_type']);
        $ticked = [];
        foreach ($details['cinnosti'] as $c) {
            if (!in_array($c, $allowed, true)) {
                throw new \InvalidArgumentException('Neznáma vykonaná činnosť.');
            }
            $ticked[$c] = true;
        }
        $details['cinnosti'] = array_values(array_filter($allowed, static fn (string $a): bool => isset($ticked[$a])));

        $details['zaznamy'] = self::filterRecords($details['zaznamy'], $ctx['account_id'], $ctx['company_id'], $ctx['facility_ids']);

        $stored = $ctx['stored'] ?? null;
        if ($details['uvod'] === null) {
            $details['uvod'] = $ctx['creating'] || !is_array($stored)
                ? self::LEGAL
                : (string) ($stored['uvod'] ?? '');
        }
        if ($details['schvalil'] === null) {
            $details['schvalil'] = !$ctx['creating'] && is_array($stored) && is_string($stored['schvalil'] ?? null)
                ? $stored['schvalil']
                : (self::schvalilDefault($ctx['account_id'], $ctx['company_id']) ?? '');
        }
        unset($details['issued']);
        if (is_array($stored) && is_array($stored['issued'] ?? null)) {
            $details['issued'] = $stored['issued'];
        }
        return $details;
    }

    /**
     * Keeps only the references the offer of the company still holds for the
     * given prevádzky.
     *
     * @param list<array{kind: string, id: int, forma: string}> $zaznamy
     * @param list<int> $facilityIds
     * @return list<array{kind: string, id: int, forma: string}>
     */
    public static function filterRecords(array $zaznamy, int $accountId, int $companyId, array $facilityIds): array
    {
        if ($zaznamy === []) {
            return [];
        }
        $offer = [];
        foreach (BookRecords::forCompany($accountId, $companyId) as $rec) {
            $offer[$rec['kind'] . ':' . $rec['id']] = $rec['facility_id'];
        }
        return array_values(array_filter($zaznamy, static function (array $z) use ($offer, $facilityIds): bool {
            $k = $z['kind'] . ':' . $z['id'];
            if (!array_key_exists($k, $offer)) {
                return false;
            }
            $fid = $offer[$k];
            return $fid === null ? BookRecords::OFFER_COMPANY_TRAININGS : in_array($fid, $facilityIds, true);
        }));
    }

    /** „Meno, funkcia" of the client's person for the book, or null when none is set. */
    public static function schvalilDefault(int $accountId, int $companyId): ?string
    {
        $p = ResponsiblePerson::forFireBook($accountId, $companyId);
        if ($p === null || $p['fullname'] === '') {
            return null;
        }
        return $p['fullname'] . ($p['role_title'] !== null ? ', ' . $p['role_title'] : '');
    }

    // ── Prevádzky ────────────────────────────────────────────────────────────

    /**
     * The `facilities` list off the wire, deduplicated (the first wins).
     *
     * @return list<array{facility_id: int, periodicity_value: ?int, periodicity_unit: ?string}>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function readFacilities(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new \InvalidArgumentException('Vyber aspoň jednu prevádzku.');
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Vyber aspoň jednu prevádzku.');
            }
            $id = $row['facility_id'] ?? null;
            if (is_string($id) && ctype_digit($id)) {
                $id = (int) $id;
            }
            if (!is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('Vyber aspoň jednu prevádzku.');
            }
            if (isset($out[$id])) {
                continue;
            }
            [$value, $unit] = self::periodicity($row['periodicity_value'] ?? null, $row['periodicity_unit'] ?? null);
            $out[$id] = ['facility_id' => $id, 'periodicity_value' => $value, 'periodicity_unit' => $unit];
        }
        return array_values($out);
    }

    /**
     * Null + null, or 12 / 6 / 3 months.
     *
     * @return array{0: ?int, 1: ?string}
     * @throws \InvalidArgumentException
     */
    public static function periodicity(mixed $value, mixed $unit): array
    {
        if (($value === null || $value === '') && ($unit === null || $unit === '')) {
            return [null, null];
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || !in_array($value, self::PERIODICITY_MONTHS, true) || $unit !== 'mesiac') {
            throw new \InvalidArgumentException('Periodicita zápisu môže byť 12, 6 alebo 3 mesiace.');
        }
        return [$value, 'mesiac'];
    }

    /**
     * Names of the given prevádzky when every one is active, of the company and
     * (for a non-admin) of the account; null otherwise.
     *
     * @param list<int> $ids
     * @return array<int, string>|null facility id → name
     */
    public static function facilityNames(?int $accountId, int $companyId, array $ids): ?array
    {
        if ($ids === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT f.id, f.name FROM facilities f
                JOIN   companies c ON c.id = f.company_id
                WHERE  f.id IN ($in) AND f.company_id = ? AND f.archived_at IS NULL AND c.archived_at IS NULL";
        $args = [...$ids, $companyId];
        if ($accountId !== null) {
            $sql .= ' AND f.account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $names[(int) $r['id']] = (string) $r['name'];
        }
        return count($names) === count($ids) ? $names : null;
    }

    /**
     * The entry's prevádzky, by name.
     *
     * @return list<array{facility_id: int, name: string, periodicity_value: ?int, periodicity_unit: ?string, company_id: int, archived: bool}>
     */
    public static function facilityRows(int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ix.facility_id, f.name, ix.periodicity_value, ix.periodicity_unit, f.company_id,
                    f.archived_at IS NOT NULL AS archived
             FROM   inspection_facilities ix
             JOIN   facilities f ON f.id = ix.facility_id
             WHERE  ix.inspection_id = ?
             ORDER  BY f.name ASC, ix.facility_id ASC'
        );
        $stmt->execute([$inspectionId]);
        return array_map(static fn (array $r): array => [
            'facility_id'       => (int) $r['facility_id'],
            'name'              => (string) $r['name'],
            'periodicity_value' => $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null,
            'periodicity_unit'  => $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null,
            'company_id'        => (int) $r['company_id'],
            'archived'          => (bool) $r['archived'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<int> */
    public static function facilityIds(int $inspectionId): array
    {
        return array_map(static fn (array $r): int => $r['facility_id'], self::facilityRows($inspectionId));
    }

    /**
     * Writes the selected set: rows no longer selected go, the rest are upserted.
     *
     * @param list<array{facility_id: int, periodicity_value: ?int, periodicity_unit: ?string}> $rows
     */
    public static function writeFacilities(\PDO $pdo, int $accountId, int $inspectionId, array $rows): void
    {
        $ids = array_map(static fn (array $r): int => $r['facility_id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM inspection_facilities WHERE inspection_id = ? AND facility_id NOT IN ($in)")
            ->execute([$inspectionId, ...$ids]);
        $upsert = $pdo->prepare(
            'INSERT INTO inspection_facilities (account_id, inspection_id, facility_id, periodicity_value, periodicity_unit)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE periodicity_value = VALUES(periodicity_value), periodicity_unit = VALUES(periodicity_unit)'
        );
        foreach ($rows as $r) {
            $upsert->execute([$accountId, $inspectionId, $r['facility_id'], $r['periodicity_value'], $r['periodicity_unit']]);
        }
    }

    /**
     * Re-derives the columns the term chain reads from the details and the
     * rows: the primary prevádzka (kept while it is selected, else the first
     * by name), `is_preventive_inspection` = spôsob 1, and the primary row's
     * periodicity while spôsob 1 (NULL in spôsob 2).
     */
    public static function syncHeader(\PDO $pdo, int $inspectionId): void
    {
        $stmt = $pdo->prepare('SELECT facility_id, details FROM inspections WHERE id = ?');
        $stmt->execute([$inspectionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $details = InspectionDetails::decode($row['details']);
        if (!self::isNew($details)) {
            return;
        }
        $rows = self::facilityRows($inspectionId);
        if ($rows === []) {
            return;
        }
        $primary = null;
        foreach ($rows as $r) {
            if ($r['facility_id'] === (int) $row['facility_id']) {
                $primary = $r;
            }
        }
        $primary ??= $rows[0];
        $preventive = (int) ($details['sposob'] ?? 2) === 1;
        $value = $preventive ? $primary['periodicity_value'] : null;
        $unit = $preventive ? $primary['periodicity_unit'] : null;
        $pdo->prepare(
            'UPDATE inspections
             SET    facility_id = ?, is_preventive_inspection = ?,
                    periodicity_value = ?, periodicity_unit = ?, periodicity_is_custom = 0
             WHERE  id = ?'
        )->execute([$primary['facility_id'], $preventive ? 1 : 0, $value, $unit, $inspectionId]);
    }

    // ── Term chain ───────────────────────────────────────────────────────────

    /**
     * The record that holds the prehliadka term at a prevádzka: the newest
     * finalized prehliadka, old preventive book entry or spôsob 1 entry
     * covering it, with that prevádzka's periodicity. `defects` counts the
     * prehliadka's defects (closed carry-overs aside), the old entry's
     * nedostatky, or a new entry's defects at that prevádzka.
     *
     * @return array{kind: string, inspection_id: int, executed_on: ?string, periodicity_value: ?int, periodicity_unit: ?string, valid_until: ?string, defects: int}|null
     */
    public static function lastTerm(int $accountId, int $facilityId): ?array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT s.id, s.type, s.executed_on, s.details,
                    " . InspectionCoverage::periodicityValue('s', 'sx') . " AS pv,
                    " . InspectionCoverage::periodicityUnit('s', 'sx') . " AS pu
             FROM   inspections s
             LEFT   JOIN inspection_facilities sx ON sx.inspection_id = s.id AND sx.facility_id = ?
             WHERE  s.account_id = ? AND s.archived_at IS NULL AND s.status = 'finalized'
               AND  " . InspectionCoverage::chain('s') . "
               AND  (sx.id IS NOT NULL OR s.facility_id = ?)
             ORDER  BY COALESCE(s.executed_on, '1000-01-01') DESC, s.id DESC
             LIMIT  1"
        );
        $stmt->execute([$facilityId, $accountId, $facilityId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $id = (int) $r['id'];
        $value = $r['pv'] !== null ? (int) $r['pv'] : null;
        $unit = $r['pu'] !== null ? (string) $r['pu'] : null;
        $executedOn = $r['executed_on'] !== null ? (string) $r['executed_on'] : null;
        $isPrehliadka = $r['type'] === 'preventivna_prehliadka';
        $isNew = !$isPrehliadka && self::isNew(InspectionDetails::decode($r['details']));

        $items = Db::pdo()->prepare('SELECT fields FROM inspection_items WHERE inspection_id = ?');
        $items->execute([$id]);
        $defects = 0;
        foreach ($items->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
            $f = json_decode((string) $raw, true);
            $f = is_array($f) ? $f : [];
            if ($isPrehliadka) {
                $defects += PrehliadkaDefects::isClosedCarry($f) ? 0 : 1;
            } elseif ($isNew) {
                $defects += (int) ($f['facility_id'] ?? 0) === $facilityId ? 1 : 0;
            } else {
                $defects += count(PkDefects::rows($f)['rows']);
            }
        }

        return [
            'kind'              => $isPrehliadka ? 'prehliadka' : 'kniha',
            'inspection_id'     => $id,
            'executed_on'       => $executedOn,
            'periodicity_value' => $value,
            'periodicity_unit'  => $unit,
            'valid_until'       => Periodicity::validUntil($executedOn, $value, $unit),
            'defects'           => $defects,
        ];
    }

    /**
     * The months a new entry prefills for a prevádzka: its last term when it is
     * 12 / 6 / 3 months, else nothing (spec 4, criterion 23).
     */
    public static function prefillMonths(?array $lastTerm): ?int
    {
        if ($lastTerm === null || ($lastTerm['periodicity_unit'] ?? null) !== 'mesiac') {
            return null;
        }
        $v = $lastTerm['periodicity_value'] ?? null;
        return is_int($v) && in_array($v, self::PERIODICITY_MONTHS, true) ? $v : null;
    }

    /**
     * Finalized new-format entries (either spôsob) covering a prevádzka that are
     * newer than the given (date, id) — all of them when none is given — oldest
     * first. Their open defects feed the next prehliadka there.
     *
     * @return list<array{id: int, executed_on: ?string}>
     */
    public static function openDefectEntries(int $accountId, int $facilityId, ?string $afterExecutedOn, ?int $afterId): array
    {
        $sql = "SELECT s.id, s.executed_on FROM inspections s
                WHERE  s.account_id = ? AND " . self::isNewSql('s') . "
                  AND  s.archived_at IS NULL AND s.status = 'finalized'
                  AND  " . InspectionCoverage::covers('s', '?');
        $args = [$accountId, $facilityId, $facilityId];
        if ($afterId !== null) {
            $sql .= " AND (COALESCE(s.executed_on, '1000-01-01'), s.id) > (COALESCE(?, '1000-01-01'), ?)";
            $args[] = $afterExecutedOn;
            $args[] = $afterId;
        }
        $sql .= " ORDER BY COALESCE(s.executed_on, '1000-01-01') ASC, s.id ASC";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'executed_on' => $r['executed_on'] !== null ? (string) $r['executed_on'] : null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // ── Generate ─────────────────────────────────────────────────────────────

    /**
     * Why an entry cannot be issued yet, or null when it can.
     *
     * @param array<string, mixed> $inspection the generate row (id, executed_on, company_id, details)
     */
    public static function readyError(int $accountId, array $inspection): ?string
    {
        if ($inspection['executed_on'] === null) {
            return 'Doplň dátum vykonania pred generovaním PDF.';
        }
        $rows = self::facilityRows((int) $inspection['id']);
        if ($rows === []) {
            return 'Prevádzka zápisu je archivovaná alebo sa nenašla.';
        }
        foreach ($rows as $r) {
            if ($r['archived'] || $r['company_id'] !== (int) $inspection['company_id']) {
                return 'Prevádzka zápisu je archivovaná alebo sa nenašla.';
            }
        }
        $details = InspectionDetails::decode($inspection['details'] ?? null) ?? [];
        if ((int) ($details['sposob'] ?? 0) === 1) {
            foreach ($rows as $r) {
                if ($r['periodicity_value'] === null) {
                    return 'Vyber periodicitu pri každej prevádzke.';
                }
            }
        }
        $zaznamy = (array) ($details['zaznamy'] ?? []);
        $ids = array_map(static fn (array $r): int => $r['facility_id'], $rows);
        if (count(self::filterRecords($zaznamy, $accountId, (int) $inspection['company_id'], $ids)) !== count($zaznamy)) {
            return 'Niektorý priložený záznam už nie je uzavretý — skontroluj Priložiť záznamy.';
        }
        return null;
    }

    /**
     * The `kniha` part of the renderer payload ({@see poziarna_kniha_zapis.php}).
     * With `$useIssued` the prevádzky, the fixed sentence, the paragraphs and
     * Schválil are what was printed at issue, so a re-render prints the same
     * document; everything else comes from the locked record.
     *
     * Internal keys `facilities` and `paragraph_rows` feed {@see issuedFrom()}.
     *
     * @param array<string, mixed> $inspection
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public static function payload(int $accountId, array $inspection, array $items, array $details, bool $useIssued): array
    {
        $issued = $useIssued && is_array($details['issued'] ?? null) ? $details['issued'] : null;
        $sposob = (int) ($details['sposob'] ?? 2) === 1 ? 1 : 2;
        $rows = self::facilityRows((int) $inspection['id']);
        $namesById = [];
        foreach ($rows as $r) {
            $namesById[$r['facility_id']] = $r['name'];
        }

        if ($issued !== null) {
            $facilities = array_values(array_map(
                static fn ($f): array => ['id' => (int) ($f['id'] ?? 0), 'name' => (string) ($f['name'] ?? '')],
                (array) ($issued['facilities'] ?? []),
            ));
            $fixed = (string) ($issued['fixed_sentence'] ?? '');
            $paragraphRows = array_values(array_filter((array) ($issued['paragraphs'] ?? []), 'is_array'));
            $schvalil = (string) ($issued['schvalil'] ?? '');
        } else {
            $facilities = array_map(static fn (array $r): array => ['id' => $r['facility_id'], 'name' => $r['name']], $rows);
            $fixed = BookSentences::fixed((string) $inspection['executed_on'], array_column($facilities, 'name'));
            $paragraphRows = self::paragraphRows($accountId, (int) $inspection['company_id'], (array) ($details['zaznamy'] ?? []));
            $schvalil = trim((string) ($details['schvalil'] ?? ''));
        }
        $namesForDefects = $namesById + array_column($facilities, 'name', 'id');

        $defects = [];
        $n = 0;
        foreach ($items as $item) {
            $f = (array) ($item['fields'] ?? []);
            if (!is_string($f['title'] ?? null)) {
                continue;
            }
            $devices = [];
            foreach ((array) ($f['devices'] ?? []) as $d) {
                if (is_array($d) && is_string($d['label'] ?? null) && $d['label'] !== '') {
                    $devices[] = $d['label'] . (is_string($d['serial'] ?? null) && $d['serial'] !== '' && $d['serial'] !== $d['label'] ? ', v. č. ' . $d['serial'] : '');
                }
            }
            if (is_string($f['device_manual'] ?? null) && trim($f['device_manual']) !== '') {
                $devices[] = trim($f['device_manual']);
            }
            $date = is_string($f['deadline_date'] ?? null) ? $f['deadline_date'] : null;
            $deadline = ($f['deadline_choice'] ?? null) === 'bezodkladne'
                ? 'bezodkladne'
                : ($date !== null ? BookSentences::dateSk($date) : '');
            $fid = (int) ($f['facility_id'] ?? 0);
            $status = PrehliadkaDefects::statusOf($f);
            $reason = $status === 'zanik' && is_string($f['reason'] ?? null) ? trim($f['reason']) : '';
            // A defect closed on the spot (odstránený, dôvod zanikol) has nothing
            // left to remove by a date: its state prints instead, as in the
            // prehliadka record (spec 12.1 — it stays in the protocol).
            if ($status === 'odstraneny' || $status === 'zanik') {
                $deadline = '';
            }
            $defects[] = [
                'n'               => ++$n,
                'item_id'         => (int) $item['id'],
                'title'           => (string) $f['title'],
                'description'     => (string) ($f['description'] ?? ''),
                'where_text'      => is_array($f['where'] ?? null) ? (string) ($f['where']['text'] ?? '') : '',
                'devices_text'    => implode(', ', $devices),
                'facility_name'   => $namesForDefects[$fid] ?? '',
                'deadline_text'   => $deadline,
                'removed_on_site' => $status === 'odstraneny' && !is_array($f['persists_from'] ?? null),
                'status'          => $status,
                'reason'          => $reason,
            ];
        }

        return [
            'sposob'             => $sposob,
            'uvod'               => (string) ($details['uvod'] ?? ''),
            'fixed_sentence'     => $fixed,
            'activities_heading' => self::ACTIVITIES_HEADING_PRINT,
            'cinnosti'           => array_values(array_filter((array) ($details['cinnosti'] ?? []), 'is_string')),
            'vlastne'            => array_values(array_filter((array) ($details['vlastne_cinnosti'] ?? []), 'is_string')),
            'text_zapisu'        => (string) ($details['text_zapisu'] ?? ''),
            'doplnujuci_text'    => (string) ($details['doplnujuci_text'] ?? ''),
            'paragraphs'         => array_values(array_map(static fn (array $p): string => (string) ($p['text'] ?? ''), $paragraphRows)),
            'objects'            => array_column($facilities, 'name'),
            'multi'              => count($facilities) > 1,
            'defects'            => $defects,
            'zaver'              => (string) ($details['zaver'] ?? ''),
            'schvalil'           => $schvalil,
            'facilities'         => $facilities,
            'paragraph_rows'     => $paragraphRows,
        ];
    }

    /**
     * What is frozen into `details.issued` when the PDF is generated.
     *
     * @param array<string, mixed> $kniha {@see payload()}
     * @return array<string, mixed>
     */
    public static function issuedFrom(array $kniha): array
    {
        return [
            'at'             => date('c'),
            'facilities'     => $kniha['facilities'],
            'fixed_sentence' => $kniha['fixed_sentence'],
            'paragraphs'     => $kniha['paragraph_rows'],
            'schvalil'       => $kniha['schvalil'],
        ];
    }

    /**
     * The attached records as paragraphs, in the offer's order (by prevádzka,
     * then the organisation, newest first).
     *
     * @param list<array<string, mixed>> $zaznamy
     * @return list<array{kind: string, id: int, forma: string, number: string, text: string}>
     */
    private static function paragraphRows(int $accountId, int $companyId, array $zaznamy): array
    {
        if ($zaznamy === []) {
            return [];
        }
        $forma = [];
        foreach ($zaznamy as $z) {
            if (is_array($z) && isset($z['kind'], $z['id'])) {
                $forma[$z['kind'] . ':' . (int) $z['id']] = ($z['forma'] ?? 'plny') === 'odkaz' ? 'odkaz' : 'plny';
            }
        }
        $out = [];
        foreach (BookRecords::forCompany($accountId, $companyId) as $rec) {
            $k = $rec['kind'] . ':' . $rec['id'];
            if (!isset($forma[$k])) {
                continue;
            }
            $out[] = [
                'kind'   => $rec['kind'],
                'id'     => $rec['id'],
                'forma'  => $forma[$k],
                'number' => $rec['number'],
                'text'   => $forma[$k] === 'odkaz' ? $rec['text_ref'] : $rec['text_full'],
            ];
        }
        return $out;
    }

    /**
     * Photo appendix: every photo of each defect, in the order of the defect
     * table, captioned „Nedostatok č. N — title" (criterion 24).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array{caption: string, path: string, width: int, height: int}>
     */
    public static function photoAppendix(int $inspectionId, array $items): array
    {
        $paths = InspectionPhotoController::fullSizePathsByItem($inspectionId);
        if ($paths === []) {
            return [];
        }
        $out = [];
        $n = 0;
        foreach ($items as $item) {
            $f = (array) ($item['fields'] ?? []);
            if (!is_string($f['title'] ?? null)) {
                continue;
            }
            $n++;
            $caption = PhotoCaption::buildForDefect($n, (string) $f['title']);
            foreach ($paths[(int) $item['id']] ?? [] as $p) {
                $out[] = ['caption' => $caption, 'path' => $p['path'], 'width' => $p['width'], 'height' => $p['height']];
            }
        }
        return $out;
    }

    // ── Opakovať ─────────────────────────────────────────────────────────────

    /**
     * A new draft from an entry (old or new): the same company, inspector and
     * active prevádzky, dated today; spôsob back to the default for the number
     * of prevádzky (criterion 3); each periodicity from that prevádzka's last
     * term (criterion 4); texts empty except the Úvod; nothing ticked or
     * attached (spec 6.4); no defects.
     *
     * @param array<string, mixed> $source InspectionController row
     * @return int the new inspection id
     * @throws \InvalidArgumentException when no prevádzka of the source is active
     */
    public static function repeat(int $accountId, array $source, int $userId): int
    {
        $sourceId = (int) $source['id'];
        $companyId = (int) $source['company_id'];
        $ids = array_map(
            static fn (array $r): int => $r['facility_id'],
            array_filter(self::facilityRows($sourceId), static fn (array $r): bool => !$r['archived']),
        );
        if ($ids === [] && !self::isNew(InspectionDetails::decode($source['details'] ?? null))) {
            $ids = [(int) $source['facility_id']];
        }
        $names = self::facilityNames($accountId, $companyId, array_values($ids));
        if ($names === null) {
            throw new \InvalidArgumentException('Prevádzka zápisu je archivovaná alebo sa nenašla.');
        }
        asort($names);
        $primary = isset($names[(int) $source['facility_id']]) ? (int) $source['facility_id'] : (int) array_key_first($names);

        $rows = [];
        foreach (array_keys($names) as $fid) {
            $months = self::prefillMonths(self::lastTerm($accountId, $fid));
            $rows[] = [
                'facility_id'       => $fid,
                'periodicity_value' => $months,
                'periodicity_unit'  => $months !== null ? 'mesiac' : null,
            ];
        }
        $sposob = self::defaultSposob(count($rows));
        $details = [
            'format'           => self::FORMAT,
            'sposob'           => $sposob,
            'sposob_rucne'     => false,
            'uvod'             => self::LEGAL,
            'cinnosti'         => [],
            'vlastne_cinnosti' => [],
            'doplnujuci_text'  => '',
            'text_zapisu'      => '',
            'zaznamy'          => [],
            'zaver'            => '',
            'schvalil'         => self::schvalilDefault($accountId, $companyId) ?? '',
        ];

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, type, is_preventive_inspection,
                     executed_on, inspector_user_id, status, notes, carried_over_from_id,
                     created_by_user_id, details)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "draft", NULL, ?, ?, ?)'
            )->execute([
                $accountId,
                $companyId,
                $primary,
                self::TYPE,
                $sposob === 1 ? 1 : 0,
                date('Y-m-d'),
                (int) $source['inspector_user_id'],
                $sourceId,
                $userId,
                InspectionDetails::encode($details),
            ]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE inspections SET billing_mode = ? WHERE id = ?')
                ->execute([Invoicing::companyMode($companyId), $newId]);
            self::writeFacilities($pdo, $accountId, $newId, $rows);
            self::syncHeader($pdo, $newId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $newId;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function text(mixed $value, string $label): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($label . ' má nesprávny tvar.');
        }
        self::assertLength($value, self::MAX_TEXT, $label);
        return $value;
    }

    private static function assertLength(string $value, int $max, string $label): void
    {
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException($label . ' je príliš dlhý (najviac ' . $max . ' znakov).');
        }
    }
}
