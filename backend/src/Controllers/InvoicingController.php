<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\Invoicing;

/**
 * Fakturácia úkonu — block 4 / chapter 22.
 *
 * Kept apart from the úkon controllers on purpose: an úkon locks when its
 * protocol is issued (InspectionController::assertUnlocked, the Pokyn guard in
 * TrainingController), but invoicing happens *after* that. These endpoints
 * write only the four invoicing columns, never the status, never a document,
 * so a locked úkon stays locked and its PDF is neither re-rendered nor
 * touched. `updated_at` is pinned too — ticking off an invoice is not an edit
 * of the record the protocol was rendered from.
 *
 * Not to be confused with BillingController, which is the SaaS subscription.
 */
final class InvoicingController
{
    /** PATCH /api/inspections/{id}/invoicing */
    public static function updateInspection(Request $req, array $params): void
    {
        self::update($req, 'inspections', (int) $params['id']);
    }

    /** PATCH /api/trainings/{id}/invoicing */
    public static function updateTraining(Request $req, array $params): void
    {
        self::update($req, 'trainings', (int) $params['id']);
    }

    /**
     * GET /api/invoicing/summary[?scope=mine]
     *
     * The number behind the Dnes card „Nevyfakturované" (chapter 18, row 6):
     * úkony of the active account with režim na faktúru that are not checked
     * off yet. Uses the same condition as the `?uninvoiced=1` list filters, so
     * the count always equals the rows the card opens.
     *
     * `scope=mine` narrows it to the logged-in technician's own úkony — the
     * „Moje" side of the Moje / Celý tím switch (executor of an inspection,
     * trainer of a training). Anything else counts the whole account.
     *
     * Response: { inspections: int, trainings: int, total: int }
     */
    public static function summary(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $mine = $req->query('scope') === 'mine';
        $userId = Tenant::currentUserId();

        $count = static function (string $table, string $userColumn) use ($accountId, $mine, $userId): int {
            $sql = "SELECT COUNT(*) FROM $table x
                    WHERE  x.account_id = ? AND x.archived_at IS NULL
                      AND  " . Invoicing::uninvoicedCondition('x');
            $args = [$accountId];
            if ($mine) {
                $sql .= " AND x.$userColumn = ?";
                $args[] = $userId;
            }
            $stmt = Db::pdo()->prepare($sql);
            $stmt->execute($args);
            return (int) $stmt->fetchColumn();
        };

        $inspections = $count('inspections', 'inspector_user_id');
        $trainings = $count('trainings', 'trainer_id');

        Response::json([
            'inspections' => $inspections,
            'trainings'   => $trainings,
            'total'       => $inspections + $trainings,
        ]);
    }

    /**
     * Partial update of the four invoicing fields. Body keys (all optional):
     *   billing_mode  'pausal' | 'na_fakturu' | 'nefakturuje_sa'
     *   invoiced      bool
     *   invoiced_at   'YYYY-MM-DD' | null   (defaults to today when ticking)
     *   billing_note  string | null
     *
     * Response: { invoicing: { billing_mode, invoiced, invoiced_at, billing_note } }
     *
     * @param 'inspections'|'trainings' $table fixed by the route, never user input
     */
    private static function update(Request $req, string $table, int $id): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());

        $sql = "SELECT id, account_id, billing_mode, invoiced, invoiced_at, billing_note
                FROM   $table
                WHERE  id = ? AND archived_at IS NULL";
        $args = [$id];
        // Sys-admins act across accounts, like everywhere else; the write is
        // then scoped to the record's own account.
        if (!$isAdmin) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error($table === 'inspections' ? 'Kontrola sa nenašla.' : 'Školenie sa nenašlo.', 404);
        }

        try {
            $next = Invoicing::merge($row, $req->json());
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }

        Db::pdo()->prepare(
            "UPDATE $table
             SET    billing_mode = ?, invoiced = ?, invoiced_at = ?, billing_note = ?,
                    updated_at = updated_at
             WHERE  id = ? AND account_id = ?"
        )->execute([
            $next['billing_mode'],
            $next['invoiced'],
            $next['invoiced_at'],
            $next['billing_note'],
            $id,
            (int) $row['account_id'],
        ]);

        Response::json(['invoicing' => Invoicing::shape($next)]);
    }
}
