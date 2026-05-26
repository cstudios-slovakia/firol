<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Billing\SeatSync;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * App-admin endpoints. The /admin page consumes these to browse every
 * account in the system together with its users, edit/delete either, and
 * grant/revoke admin status. All routes are gated by Admin::require().
 *
 * Pagination: accounts are paginated by 10 (offset/limit query). Each
 * account row includes its full user list — accounts rarely have more
 * than a handful of users, so the N+1-style nested fetch is fine.
 */
final class AdminPanelController
{
    private const PAGE_SIZE = 10;

    public static function listAccounts(Request $req): void
    {
        Admin::require();

        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $limit  = self::PAGE_SIZE;
        $search = trim((string) ($_GET['search'] ?? ''));

        $pdo = Db::pdo();

        if ($search !== '') {
            $like = '%' . $search . '%';

            $countStmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT a.id)
                 FROM   accounts a
                 LEFT JOIN account_users au ON au.account_id = a.id
                 LEFT JOIN users u ON u.id = au.user_id
                 WHERE  a.invoice_company_name LIKE ?
                    OR  u.fullname LIKE ?
                    OR  u.email LIKE ?'
            );
            $countStmt->execute([$like, $like, $like]);
            $total = (int) $countStmt->fetchColumn();

            $accStmt = $pdo->prepare(
                'SELECT DISTINCT a.id, a.invoice_company_name, a.subscription_end_date,
                        a.main_user_id, a.stripe_status, a.billing_period, a.created_at,
                        a.included_technicians, a.extra_technicians
                 FROM   accounts a
                 LEFT JOIN account_users au ON au.account_id = a.id
                 LEFT JOIN users u ON u.id = au.user_id
                 WHERE  a.invoice_company_name LIKE ?
                    OR  u.fullname LIKE ?
                    OR  u.email LIKE ?
                 ORDER  BY a.id DESC
                 LIMIT  ? OFFSET ?'
            );
            $accStmt->bindValue(1, $like,   \PDO::PARAM_STR);
            $accStmt->bindValue(2, $like,   \PDO::PARAM_STR);
            $accStmt->bindValue(3, $like,   \PDO::PARAM_STR);
            $accStmt->bindValue(4, $limit,  \PDO::PARAM_INT);
            $accStmt->bindValue(5, $offset, \PDO::PARAM_INT);
            $accStmt->execute();
        } else {
            $total = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();

            $accStmt = $pdo->prepare(
                'SELECT id, invoice_company_name, subscription_end_date, main_user_id,
                        stripe_status, billing_period, created_at,
                        included_technicians, extra_technicians
                 FROM   accounts
                 ORDER  BY id DESC
                 LIMIT  ? OFFSET ?'
            );
            $accStmt->bindValue(1, $limit,  \PDO::PARAM_INT);
            $accStmt->bindValue(2, $offset, \PDO::PARAM_INT);
            $accStmt->execute();
        }

        $accounts = $accStmt->fetchAll();

        if ($accounts === []) {
            Response::json(['items' => [], 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
        }

        $ids = array_map(static fn ($a) => (int) $a['id'], $accounts);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $userStmt = $pdo->prepare(
            "SELECT au.account_id, u.id, u.fullname, u.email, u.phone, u.is_admin,
                    au.role, au.is_active, au.created_at
             FROM   account_users au
             JOIN   users u ON u.id = au.user_id
             WHERE  au.account_id IN ($placeholders)
             ORDER  BY au.is_active DESC, u.fullname ASC"
        );
        $userStmt->execute($ids);
        $usersByAccount = [];
        foreach ($userStmt->fetchAll() as $row) {
            $aid = (int) $row['account_id'];
            $usersByAccount[$aid] ??= [];
            $usersByAccount[$aid][] = [
                'id'         => (int) $row['id'],
                'fullname'   => (string) $row['fullname'],
                'email'      => (string) $row['email'],
                'phone'      => $row['phone'],
                'is_admin'   => (int) $row['is_admin'] === 1,
                'is_env_seed' => Admin::isEnvSeed((string) $row['email']),
                'role'       => (string) $row['role'],
                'is_active'  => (bool) $row['is_active'],
                'created_at' => $row['created_at'],
            ];
        }

        // Default for any legacy NULL row falls back to the current admin
        // setting (default_included_technicians) so the panel never shows a
        // stale literal that drifts away from what new accounts get.
        $defaultIncluded = SeatSync::defaultIncludedTechnicians();
        $items = array_map(static function (array $a) use ($usersByAccount, $defaultIncluded): array {
            $id = (int) $a['id'];
            return [
                'id'                    => $id,
                'invoice_company_name'  => (string) $a['invoice_company_name'],
                'subscription_end_date' => $a['subscription_end_date'],
                'main_user_id'          => (int) $a['main_user_id'],
                'stripe_status'         => $a['stripe_status'],
                'billing_period'        => $a['billing_period'],
                'created_at'            => $a['created_at'],
                'included_technicians'  => (int) ($a['included_technicians'] ?? $defaultIncluded),
                'extra_technicians'     => (int) ($a['extra_technicians'] ?? 0),
                'users'                 => $usersByAccount[$id] ?? [],
            ];
        }, $accounts);

        Response::json([
            'items'  => $items,
            'total'  => $total,
            'offset' => $offset,
            'limit'  => $limit,
        ]);
    }

    public static function updateAccount(Request $req, array $params): void
    {
        Admin::require();
        Csrf::require($req);

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) Response::error('Invalid account id', 422);

        $sets = [];
        $bind = [];

        $name = $req->jsonString('invoice_company_name');
        if ($name !== null) {
            $name = trim($name);
            if ($name === '') Response::error('invoice_company_name cannot be empty', 422);
            $sets[] = 'invoice_company_name = ?';
            $bind[] = $name;
        }

        $end = $req->jsonString('subscription_end_date');
        if ($end !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                Response::error('subscription_end_date must be YYYY-MM-DD', 422);
            }
            $sets[] = 'subscription_end_date = ?';
            $bind[] = $end;
        }

        // Per-account override of the base-plan technician seats. Admins use
        // this for negotiated contracts ("client X gets 5 seats included").
        // After persisting, re-sync the Stripe extra-seat line so the
        // customer is charged the correct amount.
        $includedRaw = $req->json()['included_technicians'] ?? null;
        $syncSeats   = false;
        if ($includedRaw !== null) {
            if (!is_int($includedRaw) && !(is_string($includedRaw) && ctype_digit($includedRaw))) {
                Response::error('included_technicians must be an integer', 422);
            }
            $included = (int) $includedRaw;
            if ($included < 1 || $included > 1000) {
                Response::error('included_technicians out of range (1–1000)', 422);
            }
            $sets[] = 'included_technicians = ?';
            $bind[] = $included;
            $syncSeats = true;
        }

        if ($sets === []) Response::error('Nothing to update', 422);

        // Snapshot the row before mutation so the audit log can show diff.
        $beforeStmt = Db::pdo()->prepare(
            'SELECT invoice_company_name, subscription_end_date, included_technicians FROM accounts WHERE id = ?'
        );
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch() ?: null;

        $bind[] = $id;
        $stmt = Db::pdo()->prepare(
            'UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $stmt->execute($bind);
        if ($stmt->rowCount() === 0) {
            // Either id doesn't exist, or values were unchanged. Verify.
            $exists = Db::pdo()->prepare('SELECT 1 FROM accounts WHERE id = ?');
            $exists->execute([$id]);
            if ($exists->fetchColumn() === false) Response::error('Account not found', 404);
        }

        $afterStmt = Db::pdo()->prepare(
            'SELECT invoice_company_name, subscription_end_date, included_technicians FROM accounts WHERE id = ?'
        );
        $afterStmt->execute([$id]);
        $after = $afterStmt->fetch() ?: null;
        AuditLog::record('account.update', 'accounts', $id, $before ?: null, $after ?: null);

        if ($syncSeats) {
            \Firol\Billing\SeatSync::recompute($id);
        }

        Response::json(['ok' => true]);
    }

    public static function deleteAccount(Request $req, array $params): void
    {
        Admin::require();
        Csrf::require($req);

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) Response::error('Invalid account id', 422);

        // Refuse to nuke the admin's own active tenant — otherwise they'd
        // need to re-login mid-session and the activeAccountId in the
        // session would point at nothing.
        if ($id === Tenant::currentAccountId()) {
            Response::error('Nemôžeš zmazať aktívny účet, prepni sa na iný a skús znova.', 409);
        }

        $pdo = Db::pdo();

        // Collect users that belong exclusively to this account so we can
        // delete them after the account (and its account_users rows) is gone.
        // Users shared with other accounts are intentionally left intact.
        $exclusiveStmt = $pdo->prepare(
            'SELECT u.id FROM users u
             JOIN account_users au ON au.user_id = u.id AND au.account_id = ?
             WHERE NOT EXISTS (
                 SELECT 1 FROM account_users au2
                 WHERE au2.user_id = u.id AND au2.account_id != ?
             )'
        );
        $exclusiveStmt->execute([$id, $id]);
        $userIds = $exclusiveStmt->fetchAll(\PDO::FETCH_COLUMN);

        $snap = $pdo->prepare('SELECT invoice_company_name FROM accounts WHERE id = ?');
        $snap->execute([$id]);
        $snapRow = $snap->fetch() ?: null;

        // FK cascades handle account_users, companies, facilities,
        // inspections, items, documents, sequences, inspector_profiles,
        // trainings, trainees and invoices.
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        AuditLog::record('account.delete', 'accounts', $id, $snapRow ?: null, null);

        // Delete users that had no other account membership.
        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($userIds);
        }

        Response::noContent();
    }

    public static function updateUser(Request $req, array $params): void
    {
        Admin::require();
        Csrf::require($req);

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) Response::error('Invalid user id', 422);

        $sets = [];
        $bind = [];

        $fullname = $req->jsonString('fullname');
        if ($fullname !== null) {
            $fullname = trim($fullname);
            if ($fullname === '') Response::error('fullname cannot be empty', 422);
            $sets[] = 'fullname = ?';
            $bind[] = $fullname;
        }

        $email = $req->jsonString('email');
        if ($email !== null) {
            $email = strtolower(trim($email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                Response::error('Invalid email', 422);
            }
            $sets[] = 'email = ?';
            $bind[] = $email;
        }

        $phone = $req->jsonString('phone');
        if ($phone !== null) {
            $sets[] = 'phone = ?';
            $bind[] = trim($phone) === '' ? null : trim($phone);
        }

        $isAdmin = $req->jsonBool('is_admin');
        if ($isAdmin !== null) {
            // Refuse to demote yourself — would lock yourself out instantly.
            if (!$isAdmin && $id === Tenant::currentUserId()) {
                Response::error('Nemôžeš si odobrať admin práva sám sebe.', 409);
            }
            // Env-seeded admins cannot be demoted through the UI — they
            // get re-promoted on every isAdmin() call anyway.
            $existing = Db::pdo()->prepare('SELECT email FROM users WHERE id = ?');
            $existing->execute([$id]);
            $existingEmail = (string) ($existing->fetchColumn() ?: '');
            if (!$isAdmin && Admin::isEnvSeed($existingEmail)) {
                Response::error('Tento admin je nastavený v .env a nedá sa odobrať tu.', 409);
            }
            $sets[] = 'is_admin = ?';
            $bind[] = $isAdmin ? 1 : 0;
        }

        if ($sets === []) Response::error('Nothing to update', 422);

        $beforeStmt = Db::pdo()->prepare('SELECT fullname, email, phone, is_admin FROM users WHERE id = ?');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch() ?: null;

        $bind[] = $id;
        try {
            Db::pdo()->prepare(
                'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute($bind);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Tento e-mail už používa iný používateľ.', 409);
            }
            throw $e;
        }

        $afterStmt = Db::pdo()->prepare('SELECT fullname, email, phone, is_admin FROM users WHERE id = ?');
        $afterStmt->execute([$id]);
        $after = $afterStmt->fetch() ?: null;
        AuditLog::record('user.update', 'users', $id, $before ?: null, $after ?: null);

        Response::json(['ok' => true]);
    }

    public static function deleteUser(Request $req, array $params): void
    {
        Admin::require();
        Csrf::require($req);

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) Response::error('Invalid user id', 422);

        if ($id === Tenant::currentUserId()) {
            Response::error('Nemôžeš zmazať sám seba.', 409);
        }

        // If this user is the main_user_id of any account, the FK refuses
        // the delete. Surface that with a friendlier message than the raw
        // PDO error so the admin knows to delete the account first.
        $main = Db::pdo()->prepare('SELECT COUNT(*) FROM accounts WHERE main_user_id = ?');
        $main->execute([$id]);
        if ((int) $main->fetchColumn() > 0) {
            Response::error(
                'Tento používateľ je hlavným používateľom aspoň jedného účtu. Najprv zmaž alebo preveď účet.',
                409,
            );
        }

        $snap = Db::pdo()->prepare('SELECT fullname, email FROM users WHERE id = ?');
        $snap->execute([$id]);
        $snapRow = $snap->fetch() ?: null;

        Db::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        AuditLog::record('user.delete', 'users', $id, $snapRow ?: null, null);

        Response::noContent();
    }
}
