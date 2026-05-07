<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Http\Response;

/**
 * Multi-tenancy guard. Every controller that touches domain data MUST
 * resolve the active account through this — there is no other supported
 * way. Returns the integer account id; sends 401 and exits if the request
 * is not authenticated or the session has no active account context.
 */
final class Tenant
{
    public static function currentUserId(): int
    {
        $id = Session::userId();
        if ($id === null) {
            Response::error('Unauthorized', 401);
        }
        return $id;
    }

    public static function currentAccountId(): int
    {
        // Implicit precondition: an authenticated session always has an
        // active account because login + register both set it.
        self::currentUserId();
        $id = Session::activeAccountId();
        if ($id === null) {
            Response::error('No active account', 401);
        }
        return $id;
    }
}
