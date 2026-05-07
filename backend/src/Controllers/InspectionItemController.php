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

    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $inspectionId = (int) $params['id'];

        $inspection = self::loadInspectionOrFail($accountId, $inspectionId);
        if ($inspection['status'] === 'finalized') {
            Response::error('Inspection is finalized — items cannot change.', 409);
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
            'rphp' => self::validateRphpFields($body),
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
