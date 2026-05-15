<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Db;
use Firol\Http\Response;

/**
 * Public (unauthenticated) endpoint that exposes a small subset of
 * system_settings needed before the user has an account — currently
 * only trial_days so the registration screen can show the correct value.
 */
final class PublicSettingsController
{
    public static function show(): void
    {
        $stmt = Db::pdo()->prepare(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'trial_days' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetchColumn();

        Response::json([
            'trial_days' => $row !== false ? (int) $row : 14,
        ]);
    }
}
