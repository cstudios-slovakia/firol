<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\TeamIdentity;
use PDOException;

/**
 * Initials and avatar colour of a team member — chapter 11.5.
 *
 * The main user may change both for anyone in the team; a member may change
 * their own initials (they are derived from the name, "dajú sa zmeniť"), but
 * the colour is the main user's call, because keeping colours apart is a
 * property of the whole team.
 */
final class TeamIdentityController
{
    /** The fixed palette the main user picks a colour from. */
    public static function palette(Request $req): void
    {
        Response::json(['palette' => TeamIdentity::PALETTE]);
    }

    /** @param array<string, string> $params */
    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $actorId   = Tenant::currentUserId();
        $userId    = (int) ($params['id'] ?? 0);
        $pdo       = Db::pdo();

        $stmt = $pdo->prepare('SELECT main_user_id FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $isMain = (int) $stmt->fetchColumn() === $actorId;

        $member = $pdo->prepare('SELECT 1 FROM account_users WHERE account_id = ? AND user_id = ?');
        $member->execute([$accountId, $userId]);
        if ($member->fetchColumn() === false) {
            Response::error('Technik nie je členom tímu.', 404);
        }
        if (!$isMain && $userId !== $actorId) {
            Response::error('Iniciály iného technika môže zmeniť len hlavný používateľ.', 403);
        }

        $body = $req->json();

        if (array_key_exists('initials', $body)) {
            $initials = TeamIdentity::normalizeInitials(is_string($body['initials']) ? $body['initials'] : '');
            if ($initials === null) {
                Response::error('Iniciály musia mať 1 až 3 znaky bez medzier.', 422);
            }
            $clash = $pdo->prepare(
                'SELECT 1 FROM account_users
                 WHERE  account_id = ? AND user_id <> ? AND UPPER(initials) = UPPER(?)'
            );
            $clash->execute([$accountId, $userId, $initials]);
            if ($clash->fetchColumn() !== false) {
                Response::error('Tieto iniciály už má iný technik v tíme.', 409);
            }
            $pdo->prepare('UPDATE account_users SET initials = ? WHERE account_id = ? AND user_id = ?')
                ->execute([$initials, $accountId, $userId]);
        }

        if (array_key_exists('avatar_color', $body)) {
            if (!$isMain) {
                Response::error('Farbu technika môže zmeniť len hlavný používateľ.', 403);
            }
            $color = is_string($body['avatar_color']) ? strtoupper(trim($body['avatar_color'])) : '';
            if (!TeamIdentity::isPaletteColor($color)) {
                Response::error('Vyber farbu z palety.', 422);
            }
            try {
                $pdo->prepare('UPDATE account_users SET avatar_color = ? WHERE account_id = ? AND user_id = ?')
                    ->execute([$color, $accountId, $userId]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    Response::error('Túto farbu už má iný technik v tíme.', 409);
                }
                throw $e;
            }
        }

        $roster = TeamIdentity::roster($pdo, $accountId);
        $item = null;
        foreach ($roster as $r) {
            if ($r['id'] === $userId) {
                $item = $r;
            }
        }
        Response::json(['item' => $item, 'team' => $roster]);
    }
}
