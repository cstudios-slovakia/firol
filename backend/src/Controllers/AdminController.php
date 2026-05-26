<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * Admin-only knobs that affect every account: trial length and the
 * default subscription prices shown on the registration / billing
 * screens. Backed by the `system_settings` key/value table.
 *
 * We expose only a fixed allow-list of keys — everything else is
 * configuration that lives in code or env, not in the DB.
 */
final class AdminController
{
    /** Settings the admin UI is allowed to read/write, with their validators. */
    private const ALLOWED = [
        // 0 disables trials entirely; otherwise minimum is 3 days because
        // Stripe Checkout requires `trial_end` to be ≥ 48 h in the future,
        // and BillingController::trialEndUnix silently drops the trial when
        // the remaining window is shorter than that.
        'trial_days'                       => ['type' => 'int', 'min' => 0, 'max' => 365, 'forbid_range' => [1, 2]],
        'price_monthly_eur'                => ['type' => 'int', 'min' => 0, 'max' => 9999],
        'price_yearly_eur'                 => ['type' => 'int', 'min' => 0, 'max' => 99999],
        // Default number of technician seats (incl. the account admin) included
        // in the base plan for fresh accounts. Existing accounts keep their
        // per-account value; admins override individually if needed.
        'default_included_technicians'     => ['type' => 'int', 'min' => 1, 'max' => 100],
        // Per-extra-technician monthly price in cents. Yearly = ×12.
        'price_per_extra_technician_cents' => ['type' => 'int', 'min' => 0, 'max' => 100000],
        // Above this total technician count the account is steered toward a
        // custom quote — UI hides self-service and shows a contact-us hint.
        'max_self_service_technicians'     => ['type' => 'int', 'min' => 1, 'max' => 1000],
    ];

    public static function settings(Request $req): void
    {
        Admin::require();
        Response::json(['settings' => self::loadAll()]);
    }

    public static function updateSettings(Request $req): void
    {
        Admin::require();
        Csrf::require($req);

        $body = $req->json();
        $stmt = Db::pdo()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach (self::ALLOWED as $key => $rule) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];
            if ($rule['type'] === 'int') {
                if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                    Response::error("$key must be an integer", 422);
                }
                $intVal = (int) $value;
                if ($intVal < $rule['min'] || $intVal > $rule['max']) {
                    Response::error("$key out of range ({$rule['min']}–{$rule['max']})", 422);
                }
                if (isset($rule['forbid_range']) && in_array($intVal, $rule['forbid_range'], true)) {
                    Response::error("$key: trial dní musí byť 0 alebo aspoň 3 (Stripe vyžaduje minimálne 48 h)", 422);
                }
                $stmt->execute([$key, (string) $intVal]);
            }
        }

        Response::json(['settings' => self::loadAll()]);
    }

    /** @return array<string, string> */
    private static function loadAll(): array
    {
        $keys = array_keys(self::ALLOWED);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)"
        );
        $stmt->execute($keys);
        $result = array_fill_keys($keys, '');
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $result;
    }
}
