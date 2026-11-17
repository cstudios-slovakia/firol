<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Billing\SeatSync;
use Firol\Billing\SubscriptionStatus;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;

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

        // A user whose name is printed on a generated protocol cannot be
        // hard-deleted — inspections.inspector_user_id and the immutable
        // effective_inspector_user_id snapshot both reference users(id) with
        // ON DELETE RESTRICT. Surface that to the panel so it can de-emphasise
        // the delete action instead of letting it 409 on click.
        $referenced = [];
        $userIds = [];
        foreach ($usersByAccount as $list) {
            foreach ($list as $u) {
                $userIds[(int) $u['id']] = true;
            }
        }
        if ($userIds !== []) {
            $uids = array_keys($userIds);
            $uph  = implode(',', array_fill(0, count($uids), '?'));
            $refStmt = $pdo->prepare(
                "SELECT inspector_user_id AS uid FROM inspections WHERE inspector_user_id IN ($uph)
                 UNION
                 SELECT effective_inspector_user_id FROM inspections WHERE effective_inspector_user_id IN ($uph)"
            );
            $refStmt->execute([...$uids, ...$uids]);
            foreach ($refStmt->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
                $referenced[(int) $uid] = true;
            }
        }

        // Default for any legacy NULL row falls back to the current admin
        // setting (default_included_technicians) so the panel never shows a
        // stale literal that drifts away from what new accounts get.
        $defaultIncluded = SeatSync::defaultIncludedTechnicians();
        $items = array_map(static function (array $a) use ($usersByAccount, $defaultIncluded, $referenced): array {
            $id      = (int) $a['id'];
            $mainId  = (int) $a['main_user_id'];
            $users   = array_map(static function (array $u) use ($mainId, $referenced): array {
                $uid = (int) $u['id'];
                // Deletable only when nothing pins the row: not the account
                // owner and not printed on any protocol.
                $u['deletable'] = $uid !== $mainId && !isset($referenced[$uid]);
                return $u;
            }, $usersByAccount[$id] ?? []);
            return [
                'id'                    => $id,
                'invoice_company_name'  => (string) $a['invoice_company_name'],
                'subscription_end_date' => $a['subscription_end_date'],
                'main_user_id'          => $mainId,
                'stripe_status'         => $a['stripe_status'],
                // Stripe controls subscription_end_date while this is true
                // (the admin edit is refused then).
                'has_live_subscription' => SubscriptionStatus::isLive($a['stripe_status'] ?? null),
                'billing_period'        => $a['billing_period'],
                'created_at'            => $a['created_at'],
                'included_technicians'  => (int) ($a['included_technicians'] ?? $defaultIncluded),
                'extra_technicians'     => (int) ($a['extra_technicians'] ?? 0),
                'users'                 => $users,
            ];
        }, $accounts);

        Response::json([
            'items'  => $items,
            'total'  => $total,
            'offset' => $offset,
            'limit'  => $limit,
        ]);
    }

    /**
     * GET /api/admin/accounts/{id}/invoices?year=YYYY
     *
     * Admin view of one account's invoices, paginated by calendar year.
     * Returns the requested year's rows plus the list of years that have
     * invoices so the UI can render a year switcher. When no year is given
     * (or it has no invoices) we default to the most recent year on file.
     */
    public static function listInvoices(Request $req, array $params): void
    {
        Admin::require();

        $accountId = (int) ($params['id'] ?? 0);
        if ($accountId <= 0) Response::error('Neplatné ID účtu.', 422);

        $pdo = Db::pdo();
        $exists = $pdo->prepare('SELECT 1 FROM accounts WHERE id = ?');
        $exists->execute([$accountId]);
        if ($exists->fetchColumn() === false) Response::error('Účet sa nenašiel.', 404);

        // Distinct years that actually have invoices, newest first.
        $yearsStmt = $pdo->prepare(
            'SELECT DISTINCT YEAR(issued_at) AS y
             FROM   invoices WHERE account_id = ?
             ORDER  BY y DESC'
        );
        $yearsStmt->execute([$accountId]);
        $years = array_map(static fn ($r) => (int) $r['y'], $yearsStmt->fetchAll());

        // Resolve the requested year: honour ?year= when it has invoices,
        // otherwise fall back to the newest year (or the current year when
        // the account has no invoices at all, so the empty state is sane).
        $requested = isset($_GET['year']) ? (int) $_GET['year'] : 0;
        $year = in_array($requested, $years, true)
            ? $requested
            : ($years[0] ?? (int) date('Y'));

        $stmt = $pdo->prepare(
            'SELECT id, stripe_invoice_id, idoklad_invoice_id, document_number,
                    idoklad_public_url, stripe_invoice_pdf_url,
                    amount_cents, stripe_total_cents, stripe_amount_paid_cents, stripe_livemode,
                    currency, status, review_reason, error_message, attempts, last_attempt_at,
                    stripe_invoice_json, issued_at
             FROM   invoices
             WHERE  account_id = ? AND YEAR(issued_at) = ?
             ORDER  BY issued_at DESC, id DESC'
        );
        $stmt->execute([$accountId, $year]);

        $items = array_map(static function (array $r): array {
            // Prefer the legal iDoklad invoice; fall back to the Stripe PDF.
            $pdfUrl = $r['idoklad_public_url'] ?: ($r['stripe_invoice_pdf_url'] ?: null);
            $status = (string) $r['status'];
            $reason = $r['review_reason'] !== null ? (string) $r['review_reason'] : null;
            return [
                'id'                 => (int) $r['id'],
                'stripe_invoice_id'  => (string) $r['stripe_invoice_id'],
                'idoklad_invoice_id' => $r['idoklad_invoice_id'] !== null ? (int) $r['idoklad_invoice_id'] : null,
                'document_number'    => $r['document_number'] ?: null,
                'amount_cents'       => (int) $r['amount_cents'],
                'currency'           => (string) $r['currency'],
                'status'             => $status,
                'issued_at'          => $r['issued_at'],
                'pdf_url'            => $pdfUrl,
                // The Stripe numbers and what went wrong, for a row held for
                // review or one that failed.
                'stripe_total_cents'       => $r['stripe_total_cents'] !== null ? (int) $r['stripe_total_cents'] : null,
                'stripe_amount_paid_cents' => $r['stripe_amount_paid_cents'] !== null ? (int) $r['stripe_amount_paid_cents'] : null,
                'stripe_livemode'          => $r['stripe_livemode'] !== null ? (int) $r['stripe_livemode'] === 1 : null,
                'stripe_balance'           => self::stripeBalanceNumbers((string) ($r['stripe_invoice_json'] ?? '')),
                'review_reason'            => $reason,
                'error_message'            => $r['error_message'],
                'attempts'                 => (int) $r['attempts'],
                'last_attempt_at'          => $r['last_attempt_at'],
                'can_reissue'              => in_array($status, ['pending', 'error', 'prepared', 'skipped'], true)
                    || ($status === 'needs_review' && in_array($reason, ['uncertain_send', 'missing_snapshot'], true)),
            ];
        }, $stmt->fetchAll());

        Response::json([
            'items'      => $items,
            'years'      => $years,
            'year'       => $year,
            'account_id' => $accountId,
        ]);
    }

    /**
     * Balance figures of a stored Stripe invoice (subtotal, amount due, the
     * customer balance before and after), so the admin sees how a credit
     * was used. Null when no snapshot is stored.
     *
     * @return array<string, int>|null
     */
    private static function stripeBalanceNumbers(string $json): ?array
    {
        if ($json === '') {
            return null;
        }
        $inv = json_decode($json, true);
        if (!is_array($inv)) {
            return null;
        }
        $out = [];
        foreach (['subtotal', 'amount_due', 'starting_balance', 'ending_balance'] as $k) {
            if (isset($inv[$k]) && is_int($inv[$k])) {
                $out[$k] = $inv[$k];
            }
        }
        return $out;
    }

    /**
     * POST /api/admin/invoices/{id}/reissue — „Vystaviť znova".
     *
     * Runs InvoiceIssuer for one row on demand: a failed one (also past the
     * automatic retry cap), a prepared or skipped one, or one held because
     * iDoklad gave no definite answer (after the admin checked in iDoklad
     * that no invoice exists). Whether it is really sent still follows
     * IssuePolicy. A row held for a credit is not offered.
     *
     * @param array<string, string> $params
     */
    public static function reissueInvoice(Request $req, array $params): void
    {
        Admin::require();
        Csrf::require($req);

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) Response::error('Neplatné ID faktúry.', 422);

        $stmt = Db::pdo()->prepare('SELECT id, status, review_reason FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) Response::error('Faktúra sa nenašla.', 404);

        $before = (string) $row['status'];
        $reason = (string) ($row['review_reason'] ?? '');
        $allowed = in_array($before, ['pending', 'error', 'prepared', 'skipped'], true)
            || ($before === 'needs_review' && in_array($reason, ['uncertain_send', 'missing_snapshot'], true));
        if (!$allowed) {
            Response::error('Túto faktúru nie je možné vystaviť znova.', 409, ['status' => $before]);
        }

        $after = (new \Firol\Idoklad\InvoiceIssuer())->issue($id, true);
        AuditLog::record('invoice.reissue', 'invoices', $id, ['status' => $before], ['status' => $after]);

        $msgStmt = Db::pdo()->prepare('SELECT error_message FROM invoices WHERE id = ?');
        $msgStmt->execute([$id]);
        Response::json([
            'ok'      => $after === 'issued',
            'status'  => $after,
            'message' => $after === 'issued' ? null : ($msgStmt->fetchColumn() ?: null),
        ]);
    }

    /**
     * The account's live Stripe subscription (status read from Stripe), or
     * null when it has none. Refuses with 502 when Stripe cannot be asked —
     * an admin action that depends on it must not guess.
     *
     * @return array{id: string, status: string}|null
     */
    private static function liveSubscription(int $accountId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT stripe_subscription_id, stripe_status FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        $subId = (string) ($row['stripe_subscription_id'] ?? '');
        if ($subId === '' || !\Firol\Stripe\StripeClient::isConfigured()) {
            return null;
        }
        try {
            $sub = \Firol\Stripe\StripeClient::get()->subscriptions->retrieve($subId);
        } catch (\Throwable $e) {
            error_log('[admin.liveSubscription] ' . $e->getMessage());
            Response::error('Stav predplatného v Stripe sa nepodarilo overiť. Skús to znova.', 502);
        }
        $status = (string) $sub->status;
        return SubscriptionStatus::isLive($status) ? ['id' => $subId, 'status' => $status] : null;
    }

    public static function updateAccount(Request $req, array $params): void
    {
        Admin::require();
        Csrf::require($req);

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) Response::error('Neplatné ID účtu.', 422);

        $sets = [];
        $bind = [];

        $name = $req->jsonString('invoice_company_name');
        if ($name !== null) {
            $name = trim($name);
            if ($name === '') Response::error('Zadaj názov firmy pre fakturáciu.', 422);
            $sets[] = 'invoice_company_name = ?';
            $bind[] = $name;
        }

        $end = $req->jsonString('subscription_end_date');
        if ($end !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                Response::error('Neplatný dátum konca predplatného (očakáva sa formát YYYY-MM-DD).', 422);
            }
            // With a live Stripe subscription the date is Stripe's period end
            // and the next webhook would overwrite a local edit — refuse
            // instead of pretending. (Changing it would mean changing the
            // subscription in Stripe, which also changes what is charged.)
            $live = self::liveSubscription($id);
            if ($live !== null) {
                Response::error(
                    "Účet má živé predplatné v Stripe (stav {$live['status']}) — dátum určuje Stripe (koniec zaplateného obdobia) a tu sa meniť nedá.",
                    409,
                    ['code' => 'live_subscription'],
                );
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
                Response::error('Počet zahrnutých technikov musí byť celé číslo.', 422);
            }
            $included = (int) $includedRaw;
            if ($included < 1 || $included > 1000) {
                Response::error('Počet zahrnutých technikov musí byť v rozsahu 1–1000.', 422);
            }
            $sets[] = 'included_technicians = ?';
            $bind[] = $included;
            $syncSeats = true;
        }

        if ($sets === []) Response::error('Nie je čo aktualizovať.', 422);

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
            if ($exists->fetchColumn() === false) Response::error('Účet sa nenašiel.', 404);
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
        if ($id <= 0) Response::error('Neplatné ID účtu.', 422);

        // Refuse to nuke the admin's own active tenant — otherwise they'd
        // need to re-login mid-session and the activeAccountId in the
        // session would point at nothing.
        if ($id === Tenant::currentAccountId()) {
            Response::error('Nemôžeš zmazať aktívny účet, prepni sa na iný a skús znova.', 409);
        }

        // A deleted account with a live Stripe subscription would keep being
        // charged, and its payments would find no account to invoice. Refuse
        // unless the admin confirms that the subscription is cancelled in
        // Stripe right away (no refund of the paid period — that is the
        // admin's call, made in the confirmation dialog).
        $live = self::liveSubscription($id);
        if ($live !== null) {
            if (($_GET['cancel_subscription'] ?? '') !== '1') {
                Response::error(
                    "Účet má živé predplatné v Stripe (stav {$live['status']}). Pred zmazaním ho treba zrušiť.",
                    409,
                    ['code' => 'live_subscription', 'stripe_status' => $live['status']],
                );
            }
            try {
                \Firol\Stripe\StripeClient::get()->subscriptions->cancel($live['id'], [
                    'invoice_now' => false,
                    'prorate'     => false,
                ]);
            } catch (\Throwable $e) {
                error_log('[admin.deleteAccount] cancel ' . $live['id'] . ': ' . $e->getMessage());
                Response::error('Predplatné v Stripe sa nepodarilo zrušiť — účet sa nezmazal.', 502);
            }
            AuditLog::record('account.subscription_cancel', 'accounts', $id, $live, ['status' => 'canceled']);
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
        // inspections, items, item photos, documents, sequences,
        // inspector_profiles, trainings, trainees and invoices.
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        AuditLog::record('account.delete', 'accounts', $id, $snapRow ?: null, null);

        // Photo files don't cascade. They're the bulk of an account's storage
        // (change request 2.2), so drop the whole tree rather than leaving
        // gigabytes orphaned behind a deleted tenant.
        Storage::purgeAccountPhotos($id);

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
        if ($id <= 0) Response::error('Neplatné ID používateľa.', 422);

        $sets = [];
        $bind = [];

        $fullname = $req->jsonString('fullname');
        if ($fullname !== null) {
            $fullname = trim($fullname);
            if ($fullname === '') Response::error('Zadaj meno a priezvisko.', 422);
            $sets[] = 'fullname = ?';
            $bind[] = $fullname;
        }

        $email = $req->jsonString('email');
        if ($email !== null) {
            $email = strtolower(trim($email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                Response::error('Neplatná e-mailová adresa.', 422);
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

        if ($sets === []) Response::error('Nie je čo aktualizovať.', 422);

        $beforeStmt = Db::pdo()->prepare('SELECT fullname, email, phone, is_admin FROM users WHERE id = ?');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch() ?: null;
        if ($before === null) Response::error('Používateľ sa nenašiel.', 404);

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
        if ($id <= 0) Response::error('Neplatné ID používateľa.', 422);

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

        // The accounts this user is an active technician of: deleting the
        // user removes the membership (FK cascade), so their seat counts
        // must be re-synced afterwards.
        $memberOf = Db::pdo()->prepare('SELECT account_id FROM account_users WHERE user_id = ? AND is_active = 1');
        $memberOf->execute([$id]);
        $seatAccounts = array_map('intval', $memberOf->fetchAll(\PDO::FETCH_COLUMN));

        // A technician whose identity is printed on already-generated
        // protocols cannot be hard-deleted: inspections.inspector_user_id and
        // the immutable effective_inspector_user_id snapshot both reference
        // users(id) with ON DELETE RESTRICT. Surface that as a friendly 409
        // instead of letting the raw FK violation bubble up as a 500 — the
        // admin should deactivate the technician, not erase signed history.
        try {
            Db::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error(
                    'Tohto používateľa nie je možné zmazať — jeho meno je uvedené na už vystavených protokoloch. Namiesto zmazania ho deaktivuj.',
                    409,
                );
            }
            throw $e;
        }
        AuditLog::record('user.delete', 'users', $id, $snapRow ?: null, null);

        foreach ($seatAccounts as $seatAccountId) {
            SeatSync::recompute($seatAccountId);
        }

        Response::noContent();
    }

    /**
     * Activate / deactivate a technician on a specific account. This is the
     * admin-side counterpart to the main-user-only toggle in
     * {@see TeamController::update}, and the correct alternative to deleteUser
     * for technicians whose identity is already printed on protocols (which
     * the FK refuses to delete). is_active lives on account_users, so the
     * route carries both the account and the user.
     */
    public static function setUserActive(Request $req, array $params): void
    {
        Admin::require();
        Csrf::require($req);

        $accountId = (int) ($params['id'] ?? 0);
        $userId    = (int) ($params['user_id'] ?? 0);
        if ($accountId <= 0 || $userId <= 0) Response::error('Neplatné ID.', 422);

        $isActive = $req->jsonBool('is_active');
        if ($isActive === null) Response::error('Chýba údaj o aktivite (is_active).', 422);

        // The main user owns the account and must stay active — deactivating
        // them would strip the tenant of its only guaranteed member.
        $mainStmt = Db::pdo()->prepare('SELECT main_user_id FROM accounts WHERE id = ?');
        $mainStmt->execute([$accountId]);
        $mainId = $mainStmt->fetchColumn();
        if ($mainId === false) Response::error('Účet sa nenašiel.', 404);
        if (!$isActive && (int) $mainId === $userId) {
            Response::error('Hlavného používateľa účtu nie je možné deaktivovať.', 409);
        }

        $upd = Db::pdo()->prepare(
            'UPDATE account_users SET is_active = ? WHERE account_id = ? AND user_id = ?'
        );
        $upd->execute([$isActive ? 1 : 0, $accountId, $userId]);

        // rowCount() is 0 both when the value was already set and when the
        // row doesn't exist — only the latter is an error.
        if ($upd->rowCount() === 0) {
            $exists = Db::pdo()->prepare(
                'SELECT 1 FROM account_users WHERE account_id = ? AND user_id = ?'
            );
            $exists->execute([$accountId, $userId]);
            if ($exists->fetchColumn() === false) {
                Response::error('Používateľ nie je členom tohto účtu.', 404);
            }
        }

        SeatSync::recompute($accountId);
        AuditLog::record(
            $isActive ? 'user.activate' : 'user.deactivate',
            'account_users',
            $userId,
            null,
            ['account_id' => $accountId, 'is_active' => $isActive],
        );

        Response::json(['ok' => true, 'is_active' => $isActive]);
    }
}
