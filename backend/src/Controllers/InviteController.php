<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Password;
use Firol\Auth\Session;
use Firol\Billing\SeatSync;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use PDO;

/**
 * Invitation acceptance flow.
 *
 * The technician must explicitly confirm via the link in the invite
 * email before they appear on the account. Until that point the
 * account_invites row is the only trace; users / account_users are not
 * touched.
 *
 * Endpoints are public so the invitee can land on the accept page
 * without an existing session — token possession is the proof of intent.
 * For existing users we additionally require that they be logged in as
 * the invited email so a leaked link cannot attach a stranger's account.
 */
final class InviteController
{
    /**
     * Public preview of an invite — the accept page calls this first to
     * decide which form to render (set-password vs. confirm).
     *
     * @param array<string, string> $params
     */
    public static function show(Request $req, array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $invite = self::loadByToken($token);
        if ($invite === null) {
            Response::error('Pozvánka neexistuje, alebo už nie je platná.', 404);
        }

        // Has the invitee already got a Firol user account under this email?
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, fullname FROM users WHERE email = ?');
        $stmt->execute([$invite['email']]);
        $user = $stmt->fetch();

        $sessionUserId = Session::userId();
        $sessionEmail  = null;
        if ($sessionUserId !== null) {
            $s = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $s->execute([$sessionUserId]);
            $sessionEmail = $s->fetchColumn() ?: null;
        }

        Response::json([
            'invite' => [
                'email'        => $invite['email'],
                'fullname'     => $invite['fullname'],
                'phone'        => $invite['phone'],
                'account_name' => $invite['account_name'],
                'inviter_name' => $invite['inviter_name'],
                'expires_at'   => $invite['expires_at'],
            ],
            'user_exists'        => $user !== false,
            'session_email'      => $sessionEmail,
            'session_user_id'    => $sessionUserId,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public static function accept(Request $req, array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $invite = self::loadByToken($token);
        if ($invite === null) {
            Response::error('Pozvánka neexistuje, alebo už nie je platná.', 404);
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$invite['email']]);
        $user = $stmt->fetch();
        $userExists = $user !== false;

        // Seat cap is enforced at accept time — the invite itself doesn't
        // hold a seat. An admin can over-invite and the first N to accept
        // will take the seats; the rest will get the cap message.
        $maxSelfService = SeatSync::maxSelfServiceTechnicians();
        $activeNow      = SeatSync::countActiveTechnicians((int) $invite['account_id']);
        if ($activeNow + 1 > $maxSelfService) {
            Response::error(
                'Predplatné je obmedzené na ' . $maxSelfService . ' technikov. Kontaktuj administrátora účtu.',
                422,
                ['code' => 'seat_cap_exceeded', 'max' => $maxSelfService],
            );
        }

        if ($userExists) {
            // Existing user: must be logged in as the invited email. This
            // prevents a leaked link from attaching a stranger's account.
            $sessionUserId = Session::userId();
            if ($sessionUserId === null || (int) $sessionUserId !== (int) $user['id']) {
                Response::error(
                    'Pre prijatie pozvánky sa najprv prihlás ako ' . $invite['email'] . '.',
                    401,
                    ['code' => 'login_required', 'email' => $invite['email']],
                );
            }
            $userId = (int) $user['id'];
        } else {
            // Fresh user: the accept request must include a password (and
            // optionally an override of the suggested fullname/phone). The
            // token itself proves the invitee controls the email.
            $password = $req->jsonString('password');
            $fullname = $req->jsonString('fullname') ?? $invite['fullname'];
            $phone    = $req->jsonString('phone');
            if ($phone === null) {
                $phone = $invite['phone'];
            }
            if ($password === null || !Password::isStrongEnough($password)) {
                Response::error(
                    'Heslo musí mať aspoň ' . Password::MIN_LENGTH . ' znakov.',
                    422,
                );
            }
            if (trim($fullname) === '') {
                Response::error('Meno a priezvisko je povinné.', 422);
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO users (fullname, email, phone, password_hash) VALUES (?, ?, ?, ?)'
                )->execute([trim($fullname), $invite['email'], $phone, Password::hash($password)]);
                $userId = (int) $pdo->lastInsertId();
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // Attach the user to the account and mark the invite accepted.
        // If the link already exists (e.g. user is already on this account)
        // we treat that as already-accepted and short-circuit.
        $linkStmt = $pdo->prepare(
            'SELECT is_active FROM account_users WHERE account_id = ? AND user_id = ?'
        );
        $linkStmt->execute([$invite['account_id'], $userId]);
        $existing = $linkStmt->fetchColumn();

        $pdo->beginTransaction();
        try {
            if ($existing === false) {
                $pdo->prepare(
                    'INSERT INTO account_users (account_id, user_id, role, is_active)
                     VALUES (?, ?, ?, 1)'
                )->execute([$invite['account_id'], $userId, 'technician']);
            } elseif ((int) $existing === 0) {
                $pdo->prepare(
                    'UPDATE account_users SET is_active = 1
                     WHERE  account_id = ? AND user_id = ?'
                )->execute([$invite['account_id'], $userId]);
            }
            $pdo->prepare(
                'UPDATE account_invites SET accepted_at = NOW() WHERE id = ?'
            )->execute([$invite['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        SeatSync::recompute((int) $invite['account_id']);

        // Log the user in so they land on the dashboard with the new
        // account active. For an already-logged-in existing user we also
        // switch their active account to the freshly accepted one.
        Session::setUserId($userId);
        Session::setActiveAccountId((int) $invite['account_id']);

        Response::json(
            AuthController::meSnapshot($pdo, $userId, (int) $invite['account_id'])
        );
    }

    /**
     * @param array<string, string> $params
     */
    public static function decline(Request $req, array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $invite = self::loadByToken($token);
        if ($invite === null) {
            Response::error('Pozvánka neexistuje, alebo už nie je platná.', 404);
        }

        Db::pdo()->prepare(
            'UPDATE account_invites SET declined_at = NOW() WHERE id = ?'
        )->execute([$invite['id']]);

        Response::noContent();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT i.id, i.account_id, i.email, i.fullname, i.phone, i.expires_at,
                    i.accepted_at, i.declined_at, i.cancelled_at,
                    a.invoice_company_name AS account_name,
                    u.fullname              AS inviter_name
             FROM   account_invites i
             JOIN   accounts a ON a.id = i.account_id
             JOIN   users    u ON u.id = i.invited_by_user_id
             WHERE  i.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($row['accepted_at'] !== null || $row['declined_at'] !== null || $row['cancelled_at'] !== null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        return $row;
    }
}
