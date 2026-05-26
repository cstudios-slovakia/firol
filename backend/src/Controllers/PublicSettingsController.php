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
            "SELECT setting_key, setting_value FROM system_settings
             WHERE  setting_key IN (
                 'trial_days',
                 'default_included_technicians',
                 'price_per_extra_technician_cents',
                 'max_self_service_technicians',
                 'price_monthly_eur',
                 'price_yearly_eur'
             )"
        );
        $stmt->execute();

        $defaults = [
            'trial_days'                       => 14,
            'default_included_technicians'     => 2,
            'price_per_extra_technician_cents' => 1000,
            'max_self_service_technicians'     => 20,
            'price_monthly_eur'                => 19,
            'price_yearly_eur'                 => 199,
        ];
        foreach ($stmt->fetchAll() as $row) {
            $defaults[(string) $row['setting_key']] = (int) $row['setting_value'];
        }

        Response::json($defaults);
    }
}
