<?php

declare(strict_types=1);

namespace Firol\Controllers;

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
     * Predefined activity slugs for Požiarna kniha entries. Mirrors the 14
     * checkboxes from the spec; the "other" free-text input is stored
     * separately in `activities_other` so it never collides with the slugs.
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
        'fire_cabinet_check',
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

        $inspection = self::loadInspectionOrFail($accountId, $inspectionId);
        if ($inspection['status'] === 'finalized') {
            Response::error('Inspection is finalized — items cannot change.', 409);
        }

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
        $inspectionId = (int) $params['id'];
        $itemId = (int) $params['item_id'];

        $inspection = self::loadInspectionOrFail($accountId, $inspectionId);
        if ($inspection['status'] === 'finalized') {
            Response::error('Inspection is finalized — items cannot change.', 409);
        }

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
        $inspectionId = (int) $params['id'];
        $itemId = (int) $params['item_id'];

        $inspection = self::loadInspectionOrFail($accountId, $inspectionId);
        if ($inspection['status'] === 'finalized') {
            Response::error('Inspection is finalized — items cannot change.', 409);
        }

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
        $workspaces       = self::stringField($body, 'workspaces', required: true, max: 500);
        $activitiesOther  = self::stringField($body, 'activities_other', required: false, max: 500);
        $notes            = self::stringField($body, 'notes', required: false, max: 1000);

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
        if (count($activities) === 0 && ($activitiesOther === null || $activitiesOther === '')) {
            self::failValidation('Vyber aspoň jednu vykonanú činnosť alebo doplň vlastnú v poli „iné".');
        }

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::PK_RESULTS, true)) {
            self::failValidation('Field result must be bez_nedostatkov or zistene_nedostatky.');
        }

        return [
            'workspaces'       => $workspaces,
            'activities'       => $activities,
            'activities_other' => $activitiesOther,
            'result'           => $result,
            'notes'            => $notes,
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
        $luminaireType = self::stringField($body, 'luminaire_type', required: true,  max: 80);
        $manufacturer  = self::stringField($body, 'manufacturer',   required: true,  max: 80);
        $location      = self::stringField($body, 'location',       required: true,  max: 191);
        $notes         = self::stringField($body, 'notes',          required: false, max: 500);
        $durationMin   = self::nonNegativeInt($body, 'duration_min', max: 600);

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::RESULT_ENUM, true)) {
            self::failValidation('Field result must be vyhovuje or nevyhovuje.');
        }

        return [
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
        $hoseType     = self::stringField($body, 'hose_type', required: true,  max: 40);
        $serial       = self::stringField($body, 'serial',    required: true,  max: 80);
        $location     = self::stringField($body, 'location',  required: true,  max: 191);
        $notes        = self::stringField($body, 'notes',     required: false, max: 500);
        $testPressure = self::float($body, 'test_pressure', min: 0, max: 50);

        $result = $body['result'] ?? null;
        if (!is_string($result) || !in_array($result, self::RESULT_ENUM, true)) {
            self::failValidation('Field result must be vyhovuje or nevyhovuje.');
        }

        return [
            'hose_type'     => $hoseType,
            'serial'        => $serial,
            'location'      => $location,
            'test_pressure' => $testPressure,
            'result'        => $result,
            'notes'         => $notes,
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
    private static function loadInspectionOrFail(int $accountId, int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, type, status FROM inspections
             WHERE id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$inspectionId, $accountId]);
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
