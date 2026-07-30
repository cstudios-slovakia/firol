<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Session;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Legal\Terms;

final class MeController
{
    public static function show(Request $req): void
    {
        $userId    = Tenant::currentUserId();
        $accountId = Tenant::currentAccountId();

        Response::json(AuthController::meSnapshot(Db::pdo(), $userId, $accountId));
    }

    /**
     * Record that the signed-in user has acknowledged the current version of
     * the legal documents (change request 3.1). Called from the in-app notice
     * shown after login when the VOP and/or privacy policy changed since
     * their last consent — the notice always covers both, so both are
     * stamped as accepted together even if only one of the two changed.
     */
    public static function acceptTerms(Request $req): void
    {
        Csrf::require($req);

        $userId = Tenant::currentUserId();
        Db::pdo()->prepare(
            'UPDATE users SET vop_version = ?, vop_accepted_at = NOW(),
                    privacy_version = ?, privacy_accepted_at = NOW()
             WHERE id = ?'
        )->execute([Terms::VOP_VERSION, Terms::PRIVACY_VERSION, $userId]);

        Response::json(AuthController::meSnapshot(Db::pdo(), $userId, Tenant::currentAccountId()));
    }

    public static function switchAccount(Request $req): void
    {
        Csrf::require($req);

        $userId = Tenant::currentUserId();
        $target = $req->jsonInt('account_id');
        if ($target === null) {
            Response::error('Field required: account_id', 422);
        }

        // Confirm the user actually belongs to the target account and is
        // still active there. Without this check anyone could escalate by
        // pointing the session at someone else's tenant.
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM account_users
             WHERE  account_id = ? AND user_id = ? AND is_active = 1'
        );
        $stmt->execute([$target, $userId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Not a member of that account', 403);
        }

        Session::setActiveAccountId($target);
        Response::json(AuthController::meSnapshot(Db::pdo(), $userId, $target));
    }
}
