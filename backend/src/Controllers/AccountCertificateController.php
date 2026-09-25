<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\AccountCertificates;

/**
 * Firemné oprávnenia (BTS, výchova a vzdelávanie) — chapter 1.3.1.
 *
 *   GET /api/account/certificates   the account's company certificates
 *   PUT /api/account/certificates   replace them — main user only
 *
 * They belong to the account, so they live in the account settings and only
 * its main user may change them („spravuje ich hlavný používateľ v
 * nastaveniach účtu"). A member never sees them in their own profile and
 * cannot change them; reading is allowed because the number ends up on the
 * member's protocols anyway.
 */
final class AccountCertificateController
{
    public static function show(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        Response::json(['certificates' => self::shape($accountId)]);
    }

    public static function update(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        self::requireMainUser($accountId);

        $body = $req->json();
        $rows = $body['certificates'] ?? null;
        if (!is_array($rows)) {
            Response::error('Chýbajú údaje o oprávneniach.', 422);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach (AccountCertificates::TYPES as $type) {
                if (!array_key_exists($type, $rows)) {
                    continue;
                }
                $row = is_array($rows[$type]) ? $rows[$type] : [];
                AccountCertificates::save(
                    $accountId,
                    $type,
                    isset($row['number']) ? (string) $row['number'] : null,
                    isset($row['valid_from']) ? (string) $row['valid_from'] : null,
                    isset($row['valid_to']) ? (string) $row['valid_to'] : null,
                );
            }
            $pdo->commit();
        } catch (\InvalidArgumentException $e) {
            $pdo->rollBack();
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['certificates' => self::shape($accountId)]);
    }

    /** @return list<array<string, mixed>> one entry per type, entered or not */
    private static function shape(int $accountId): array
    {
        $stored = AccountCertificates::forAccount($accountId);
        $out = [];
        foreach (AccountCertificates::TYPES as $type) {
            $out[] = [
                'type'        => $type,
                'label'       => AccountCertificates::LABELS[$type],
                'legal_basis' => AccountCertificates::LEGAL_BASIS[$type],
                'number'      => $stored[$type]['number'] ?? null,
                'valid_from'  => $stored[$type]['valid_from'] ?? null,
                'valid_to'    => $stored[$type]['valid_to'] ?? null,
            ];
        }
        return $out;
    }

    private static function requireMainUser(int $accountId): void
    {
        $userId = Tenant::currentUserId();
        if (Admin::isAdmin($userId)) {
            return;
        }
        $stmt = Db::pdo()->prepare('SELECT main_user_id FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        if ((int) $stmt->fetchColumn() !== $userId) {
            Response::error('Firemné oprávnenia môže meniť len hlavný používateľ účtu.', 403);
        }
    }
}
