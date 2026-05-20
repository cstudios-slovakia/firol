<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use PDO;

/**
 * CRUD for inspection_items. The shape of `fields` is validated against
 * the parent inspection's type — RPHP enforces extinguisher metadata
 * plus the four-state A/TS/O/V status. Other inspection types land in
 * later phases (3b/3c…) and will register additional validators here.
 *
 * Position is auto-assigned (max + 1) on insert. Reordering is not
 * exposed yet — the technician adds items chronologically.
 */
final class InspectionItemController
{
    /** Allowed RPHP statuses: Akcieschopný / Tlaková skúška / Oprava / Vyradený. */
    private const RPHP_STATUSES = ['A', 'TS', 'O', 'V'];

    /** Hydrant nominal diameters per spec (DN25/33/52, C52, custom "other"). */
    private const HYDRANTY_TYPES = ['DN25', 'DN33', 'DN52', 'C52', 'other'];

    /** Common pass/fail enum reused across hydranty / PU / NO / TS-HAD. */
    private const RESULT_ENUM = ['vyhovuje', 'nevyhovuje'];

    /** Service actions that may be checked on an Oprava+TS RPHP item. */
    private const OPRAVA_TS_ACTIONS = ['tlakova_skuska', 'oprava', 'plnenie'];

    /**
     * Predefined activity slugs for Požiarna kniha entries. Custom activities
     * are stored as free-text strings in `custom_activities`.
     */
    private const PK_ACTIVITIES = [
        'visual_check',
        'rphp_check',
        'hydranty_check',
        'escape_routes_check',
        'pu_check',
        'training_initial',
        'training_repeated',
        'electrical_equipment_check',
        'technical_equipment_check',
        'electrical_appliances_check',
        'documentation_check',
        'employee_list_check',
        'fire_drill',
    ];

    /** Result of a Požiarna kniha entry. */
    private const PK_RESULTS = ['bez_nedostatkov', 'zistene_nedostatky'];

    /** Fire closure kinds (dvere = door, okno = window, klapka = damper). */
    private const PU_KINDS = ['dvere', 'okno', 'klapka'];

    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $inspectionId = (int) $params['id'];

        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $inspection = self::loadInspectionOrFail($isAdmin ? null : $accountId, $inspectionId);

        // Požiarna kniha is conceptually a single-record protocol; the
        // schema supports many items but the domain doesn't, so block it
        // at the controller. The UI enforces this too — this is the
        // belt-and-braces server-side check.
        if ($inspection['type'] === 'poziarna_kniha') {
            $existing = Db::pdo()->prepare(
                'SELECT COUNT(*) FROM inspection_items WHERE inspection_id = ?'
            );
            $existing->execute([$inspectionId]);
            if ((int) $existing->fetchColumn() > 0) {
                Response::error(
                    'Požiarna kniha má len jeden záznam — uprav existujúci namiesto pridania nového.',
                    409,
                );
            }
        }

        $fields = self::validateFields($inspection['type'], $req->json());

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $maxStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(position), 0) + 1
                 FROM   inspection_items WHERE inspection_id = ?'
            );
            $maxStmt->execute([$inspectionId]);
            $position = (int) $maxStmt->fetchColumn();

            $pdo->prepare(
                'INSERT INTO inspection_items (inspection_id, position, fields)
                 VALUES (?, ?, ?)'
            )->execute([$inspectionId, $position, json_encode($fields, JSON_UNESCAPED_UNICODE)]);

            $itemId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json([
            'item' => self::loadItem($itemId),
        ], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $inspectionId = (int) $params['id'];
        $itemId = (int) $params['item_id'];

        $inspection = self::loadInspectionOrFail($isAdmin ? null : $accountId, $inspectionId);

        self::loadItemForInspectionOrFail($itemId, $inspectionId);

        $fields = self::validateFields($inspection['type'], $req->json());

        Db::pdo()->prepare(
            'UPDATE inspection_items SET fields = ? WHERE id = ? AND inspection_id = ?'
        )->execute([
            json_encode($fields, JSON_UNESCAPED_UNICODE),
            $itemId,
            $inspectionId,
        ]);

        Response::json(['item' => self::loadItem($itemId)]);
    }

    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $inspectionId = (int) $params['id'];
        $itemId = (int) $params['item_id'];

        $inspection = self::loadInspectionOrFail($isAdmin ? null : $accountId, $inspectionId);

        self::loadItemForInspectionOrFail($itemId, $inspectionId);

        Db::pdo()->prepare(
            'DELETE FROM inspection_items WHERE id = ? AND inspection_id = ?'
        )->execute([$itemId, $inspectionId]);

        // Positions stay sparse after deletes — that's fine for ordering.
        // PDF generation later will renumber on render rather than rewriting
        // rows on every delete.

        Response::noContent();
    }

    /**
     * Dispatches per inspection type. Adding a new type means registering
     * a validator branch here that returns the canonical fields array.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validateFields(string $type, array $body): array
    {
        return match ($type) {
            'rphp'               => self::validateRphpFields($body),
            'hydranty'           => self::validateHydrantyFields($body),
            'oprava_ts_rphp'     => self::validateOpravaTsRphpFields($body),
            'poziarna_kniha'     => self::validatePoziarnaKnihaFields($body),
            'pu_akcieschopnost'  => self::validatePuAkcieschopnostFields($body),
            'pu_udrzba'          => self::validatePuUdrzbaFields($body),
            'nudzove_osvetlenie' => self::validateNudzoveOsvetlenieFields($body),
            'ts_hadic'           => self::validateTsHadicFields($body),
            default => self::failValidation("Items for type '$type' are not supported yet."),
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validateRphpFields(array $body): array
    {
        $manufacturer = self::stringField($body, 'manufacturer', required: true, max: 80);
        $extType      = self::stringField($body, 'type', required: true, max: 40);
        $serial       = self::stringField($body, 'serial', required: true, max: 80);
        $location     = self::stringField($body, 'location', required: true, max: 191);
        $notes        = self::stringField($body, 'notes', required: false, max: 500);

        $year = $body['year'] ?? null;
        if (is_string($year) && ctype_digit($year)) {
            $year = (int) $year;
        }
        if (!is_int($year) || $year < 1900 || $year > 2200) {
            self::failValidation('Field year must be an integer year (1900–2200).');
        }

        $status = $body['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::RPHP_STATUSES, true)) {
            self::failValidation('Field status must be one of: A, TS, O, V.');
        }

        return [
            'manufacturer' => $manufacturer,
            'type'         => $extType,
            'serial'       => $serial,
            'year'         => $year,
            'location'     => $location,
            'status'       => $status,
            'notes'        => $notes,
        ];
    }

    /**
     * Oprava + plnenie + TS RPHP. Same identification block as RPHP
     * (manufacturer/type/serial/year/location) plus a multi-select of
     * service actions performed during the visit. At least one action
     * must be checked — otherwise the item is meaningless on this kind
     * of protocol.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validateOpravaTsRphpFields(array $body): array
    {
        $manufacturer = self::stringField($body, 'manufacturer', required: true, max: 80);
        $extType      = self::stringField($body, 'type', required: true, max: 40);
        $serial       = self::stringField($body, 'serial', required: true, max: 80);
        $location     = self::stringField($body, 'location', required: true, max: 191);
        $notes        = self::stringField($body, 'notes', required: false, max: 500);

        $year = $body['year'] ?? null;
        if (is_string($year) && ctype_digit($year)) {
            $year = (int) $year;
        }
        if (!is_int($year) || $year < 1900 || $year > 2200) {
            self::failValidation('Field year must be an integer year (1900–2200).');
        }

        $actionsRaw = $body['actions'] ?? null;
        if (!is_array($actionsRaw)) {
            self::failValidation('Field actions must be an array.');
        }
        $actions = [];
        foreach ($actionsRaw as $a) {
            if (!is_string($a) || !in_array($a, self::OPRAVA_TS_ACTIONS, true)) {
                self::failValidation('Each action must be one of: tlakova_skuska, oprava, plnenie.');
            }
            if (!in_array($a, $actions, true)) {
                $actions[] = $a;
            }
        }
        if (count($actions) === 0) {
            self::failValidation('Vyber aspoň jeden vykonaný úkon (tlaková skúška, oprava alebo plnenie).');
        }

        return [
            'manufacturer' => $manufacturer,
            'type'         => $extType,
            'serial'       => $serial,
            'year'         => $year,
            'location'     => $location,
            'actions'      => $actions,
            'notes'        => $notes,
        ];
    }

    /**
     * Požiarna kniha entry. Conceptually one entry per inspection — the
     * UI prevents adding a second one — but storing it as a regular item
     * keeps the schema uniform across types. At least one activity (slug
     * or custom free-text) must be filled in, otherwise the record carries
     * no information.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validatePoziarnaKnihaFields(array $body): array
    {
        $workspaces = self::stringField($body, 'workspaces', required: true, max: 500);
        $notes      = self::stringField($body, 'notes', required: false, max: 1000);

        $activitiesRaw = $body['activities'] ?? null;
        if (!is_array($activitiesRaw)) {
            self::failValidation('Field activities must be an array of slugs.');
        }
        $activities = [];
        foreach ($activitiesRaw as $a) {
            if (!is_string($a) || !in_array($a, self::PK_ACTIVITIES, true)) {
                self::failValidation('Each activity must be a known slug from the spec list.');
            }
            if (!in_array($a, $activities, true)) {
                $activities[] = $a;
            }
        }

        $customRaw = $body['custom_activities'] ?? [];
        if (!is_array($customRaw)) {
            $customRaw = [];
        }
        $customActivities = [];
        foreach ($customRaw as $ca) {
            if (is_string($ca) && ($ca = trim($ca)) !== '' && mb_strlen($ca) <= 200) {
                $customActivities[] = $ca;
            }
        }

        if (count($activities) === 0 && count($customActivities) === 0) {
            self::failValidation('Vyber aspoň jednu vykonanú činnosť alebo pridaj vlastnú.');
        }

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::PK_RESULTS, true)) {
            self::failValidation('Field result must be bez_nedostatkov or zistene_nedostatky.');
        }

        // Per the PDF protokol (docs/handoff/.../04_Poziarna_kniha.pdf) every
        // defect carries its OWN deadline — not a single global date. The
        // legacy `defect_deadline` is still read by the PDF renderer for
        // backward compat with old records, but new writes use `defects`.
        $defects = [];
        if ($result === 'zistene_nedostatky') {
            $defectsRaw = $body['defects'] ?? null;
            if (!is_array($defectsRaw) || count($defectsRaw) === 0) {
                self::failValidation('Pri "Zistené nedostatky" treba pridať aspoň jeden nedostatok.');
            }
            foreach ($defectsRaw as $d) {
                if (!is_array($d)) {
                    self::failValidation('Field defects must be an array of objects.');
                }
                $desc = isset($d['description']) && is_string($d['description']) ? trim($d['description']) : '';
                if ($desc === '') {
                    self::failValidation('Každý nedostatok musí mať popis.');
                }
                if (mb_strlen($desc) > 500) {
                    self::failValidation('Popis nedostatku je príliš dlhý (max 500 znakov).');
                }
                $deadline = null;
                $rawDl = $d['deadline'] ?? null;
                if (is_string($rawDl) && $rawDl !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDl)) {
                    $deadline = $rawDl;
                }
                $defects[] = ['description' => $desc, 'deadline' => $deadline];
            }
        }

        return [
            'workspaces'        => $workspaces,
            'activities'        => $activities,
            'custom_activities' => $customActivities,
            'result'            => $result,
            'defects'           => $defects,
            'notes'             => $notes,
        ];
    }

    /**
     * Požiarne uzávery — kontrola akcieschopnosti (PU-AK).
     * Identification: kind + identifier + manufacturer + location.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validatePuAkcieschopnostFields(array $body): array
    {
        $kind = $body['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::PU_KINDS, true)) {
            self::failValidation('Field kind must be one of: dvere, okno, klapka.');
        }
        $identifier   = self::stringField($body, 'identifier',   required: true,  max: 80);
        $manufacturer = self::stringField($body, 'manufacturer', required: true,  max: 80);
        $location     = self::stringField($body, 'location',     required: true,  max: 191);
        $notes        = self::stringField($body, 'notes',        required: false, max: 500);

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::RESULT_ENUM, true)) {
            self::failValidation('Field result must be vyhovuje or nevyhovuje.');
        }

        return [
            'kind'         => $kind,
            'identifier'   => $identifier,
            'manufacturer' => $manufacturer,
            'location'     => $location,
            'result'       => $result,
            'notes'        => $notes,
        ];
    }

    /**
     * Požiarne uzávery — prevádzková údržba (PU-UD).
     * Same identification as AK minus manufacturer, plus mandatory
     * maintenance_work text describing what was done (lubrication,
     * gasket replacement, self-closer check, etc.).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validatePuUdrzbaFields(array $body): array
    {
        $kind = $body['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::PU_KINDS, true)) {
            self::failValidation('Field kind must be one of: dvere, okno, klapka.');
        }
        $identifier      = self::stringField($body, 'identifier',       required: true,  max: 80);
        $location        = self::stringField($body, 'location',         required: true,  max: 191);
        $maintenanceWork = self::stringField($body, 'maintenance_work', required: true,  max: 500);
        $notes           = self::stringField($body, 'notes',            required: false, max: 500);

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::RESULT_ENUM, true)) {
            self::failValidation('Field result must be vyhovuje or nevyhovuje.');
        }

        return [
            'kind'             => $kind,
            'identifier'       => $identifier,
            'location'         => $location,
            'maintenance_work' => $maintenanceWork,
            'result'           => $result,
            'notes'            => $notes,
        ];
    }

    /**
     * Núdzové osvetlenie (NO). Tests how long an emergency luminaire
     * keeps shining after mains power is cut. Duration is measured in
     * whole minutes; spec doesn't constrain the upper bound but 600 min
     * (10h) is more than any realistic system can sustain.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validateNudzoveOsvetlenieFields(array $body): array
    {
        $evidNumber    = self::stringField($body, 'evid_number',    required: false, max: 40);
        $floor         = self::stringField($body, 'floor',          required: false, max: 20);
        $luminaireType = self::stringField($body, 'luminaire_type', required: true,  max: 80);
        $manufacturer  = self::stringField($body, 'manufacturer',   required: false, max: 80);
        $location      = self::stringField($body, 'location',       required: true,  max: 191);
        $notes         = self::stringField($body, 'notes',          required: false, max: 500);
        $durationMin   = self::nonNegativeInt($body, 'duration_min', max: 600);

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::RESULT_ENUM, true)) {
            self::failValidation('Field result must be vyhovuje or nevyhovuje.');
        }

        return [
            'evid_number'    => $evidNumber,
            'floor'          => $floor,
            'luminaire_type' => $luminaireType,
            'manufacturer'   => $manufacturer,
            'location'       => $location,
            'duration_min'   => $durationMin,
            'result'         => $result,
            'notes'          => $notes,
        ];
    }

    /**
     * Tlaková skúška hadíc (TS-HAD). Test pressure is in MPa.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validateTsHadicFields(array $body): array
    {
        $hoseType          = self::stringField($body, 'hose_type',           required: true,  max: 40);
        $location          = self::stringField($body, 'location',            required: true,  max: 191);
        $manufacturer      = self::stringField($body, 'manufacturer',        required: true,  max: 100);
        $notes             = self::stringField($body, 'notes',               required: false, max: 500);
        $workingPressure   = self::float($body, 'working_pressure',   min: 0, max: 50);
        $testPressure      = self::float($body, 'test_pressure',      min: 0, max: 50);
        $length            = self::float($body, 'length',             min: 0.1, max: 9999);
        $yearOfManufacture = (int) self::float($body, 'year_of_manufacture', min: 1900, max: (float) date('Y'));

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::RESULT_ENUM, true)) {
            self::failValidation('Field result must be vyhovuje or nevyhovuje.');
        }

        return [
            'hose_type'           => $hoseType,
            'location'            => $location,
            'manufacturer'        => $manufacturer,
            'working_pressure'    => $workingPressure,
            'test_pressure'       => $testPressure,
            'length'              => $length,
            'year_of_manufacture' => $yearOfManufacture,
            'result'              => $result,
            'notes'               => $notes,
        ];
    }

    /**
     * Hydranty (požiarne hydranty / vodovody). Measurements are stored as
     * floats; HS = static pressure, HD = dynamic pressure (both MPa),
     * Q = flow rate (l/s). Allowing 0 makes sense (a fully blocked hydrant
     * legitimately measures zero flow).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function validateHydrantyFields(array $body): array
    {
        $type      = $body['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::HYDRANTY_TYPES, true)) {
            self::failValidation('Field type must be one of: DN25, DN33, DN52, C52, other.');
        }

        $typeOther = self::stringField($body, 'type_other', required: false, max: 60);
        if ($type === 'other' && ($typeOther === null || $typeOther === '')) {
            self::failValidation('Field type_other is required when type = other.');
        }
        // Drop the free-text label when it's not relevant — keeps payload tidy.
        if ($type !== 'other') {
            $typeOther = null;
        }

        $location = self::stringField($body, 'location', required: true, max: 191);
        $defects  = self::stringField($body, 'defects',  required: false, max: 500);

        $hoseCount = self::nonNegativeInt($body, 'hose_count', max: 20);
        $hs        = self::float($body, 'hs', min: 0, max: 50);
        $hd        = self::float($body, 'hd', min: 0, max: 50);
        $q         = self::float($body, 'q',  min: 0, max: 100);

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::RESULT_ENUM, true)) {
            self::failValidation('Field result must be vyhovuje or nevyhovuje.');
        }

        return [
            'type'       => $type,
            'type_other' => $typeOther,
            'location'   => $location,
            'hose_count' => $hoseCount,
            'hs'         => $hs,
            'hd'         => $hd,
            'q'          => $q,
            'defects'    => $defects,
            'result'     => $result,
        ];
    }

    /** @param array<string, mixed> $body */
    private static function nonNegativeInt(array $body, string $key, int $max): int
    {
        $raw = $body[$key] ?? null;
        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }
        if (!is_int($raw) || $raw < 0 || $raw > $max) {
            self::failValidation("Field $key must be an integer between 0 and $max.");
        }
        return $raw;
    }

    /** @param array<string, mixed> $body */
    private static function float(array $body, string $key, float $min, float $max): float
    {
        $raw = $body[$key] ?? null;
        if (is_string($raw) && is_numeric($raw)) {
            $raw = (float) $raw;
        }
        if (is_int($raw)) {
            $raw = (float) $raw;
        }
        if (!is_float($raw) || $raw < $min || $raw > $max) {
            self::failValidation("Field $key must be a number between $min and $max.");
        }
        return $raw;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function stringField(
        array $body,
        string $key,
        bool $required,
        int $max,
    ): ?string {
        $raw = $body[$key] ?? null;
        if (!is_string($raw)) {
            if ($required) {
                self::failValidation("Field required: $key");
            }
            return null;
        }
        $value = trim($raw);
        if ($value === '') {
            if ($required) {
                self::failValidation("Field required: $key");
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            self::failValidation("Field too long: $key (max $max chars)");
        }
        return $value;
    }

    private static function failValidation(string $message): never
    {
        Response::error($message, 422);
    }

    /** @return array<string, mixed> */
    private static function loadInspectionOrFail(?int $accountId, int $inspectionId): array
    {
        $sql = 'SELECT id, type, status FROM inspections
                WHERE id = ? AND archived_at IS NULL';
        $params = [$inspectionId];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Inspection not found', 404);
        }
        return $row;
    }

    private static function loadItemForInspectionOrFail(int $itemId, int $inspectionId): void
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM inspection_items WHERE id = ? AND inspection_id = ?'
        );
        $stmt->execute([$itemId, $inspectionId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Item not found', 404);
        }
    }

    /** @return array<string, mixed> */
    private static function loadItem(int $itemId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, inspection_id, position, fields, created_at, updated_at
             FROM   inspection_items WHERE id = ?'
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();
        return [
            'id'            => (int) $row['id'],
            'inspection_id' => (int) $row['inspection_id'],
            'position'      => (int) $row['position'],
            'fields'        => json_decode((string) $row['fields'], true) ?? [],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
        ];
    }
}
