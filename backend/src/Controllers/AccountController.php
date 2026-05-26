<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Billing\SeatSync;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;

/**
 * Account-level settings: branding (logo + theme color) and the invoice
 * company name shown on every PDF protocol. Scoped to the active account
 * via Tenant — a user with multiple accounts manages each one separately.
 *
 * The logo PNG/JPG is stored under backend/storage/accounts/ — outside the
 * docroot — and streamed back through an authenticated PHP endpoint.
 */
final class AccountController
{
    /** Hard cap on uploaded logo size (1 MB is plenty for a letterhead). */
    private const MAX_LOGO_BYTES = 1048576;

    public static function show(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        Response::json(['account' => self::shape($accountId)]);
    }

    public static function update(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $invoiceName = $req->jsonString('invoice_company_name');
        $themeColor  = $req->jsonString('theme_color');

        if ($invoiceName !== null && trim($invoiceName) === '') {
            Response::error('invoice_company_name cannot be empty', 422);
        }
        if ($themeColor !== null && $themeColor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) {
            Response::error('Invalid theme_color (expected #RRGGBB)', 422);
        }

        // Empty string clears the override and falls back to Firol red.
        $themeColorValue = ($themeColor === '' || $themeColor === null) ? null : strtolower($themeColor);

        // Optional invoicing details — used by the iDoklad Contact creation
        // on the first paid invoice. Each field is independent: NULL means
        // "don't touch", empty string means "clear".
        $invoiceFields = [
            'invoice_street'      => $req->jsonString('invoice_street'),
            'invoice_postal_code' => $req->jsonString('invoice_postal_code'),
            'invoice_city'        => $req->jsonString('invoice_city'),
            'invoice_country'     => $req->jsonString('invoice_country'),
            'invoice_ico'         => $req->jsonString('invoice_ico'),
            'invoice_dic'         => $req->jsonString('invoice_dic'),
            'invoice_ic_dph'      => $req->jsonString('invoice_ic_dph'),
        ];

        $sets   = [
            'invoice_company_name = COALESCE(?, invoice_company_name)',
            'theme_color = ?',
        ];
        $params = [
            $invoiceName !== null ? trim($invoiceName) : null,
            $themeColorValue,
        ];
        $numericInvoiceFields = ['invoice_ico', 'invoice_dic', 'invoice_ic_dph'];
        foreach ($invoiceFields as $col => $val) {
            if ($val === null) continue;
            $trimmed = trim($val);
            if (in_array($col, $numericInvoiceFields, true)) {
                $trimmed = preg_replace('/\s+/', '', $trimmed);
            }
            // invoice_country is NOT NULL in schema — refuse to clear it.
            if ($col === 'invoice_country' && $trimmed === '') {
                Response::error('invoice_country cannot be empty', 422);
            }
            $sets[]   = "$col = ?";
            $params[] = $trimmed === '' ? null : $trimmed;
        }
        $params[] = $accountId;

        Db::pdo()->prepare(
            'UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($params);

        Response::json(['account' => self::shape($accountId)]);
    }

    public static function uploadLogo(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $file = $_FILES['logo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('No logo file uploaded', 422);
        }
        if (($file['size'] ?? 0) > self::MAX_LOGO_BYTES) {
            Response::error('Logo too large (max 1 MB)', 422);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::error('Upload failed', 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $extByMime = match ($mime) {
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            default      => null,
        };
        if ($extByMime === null) {
            Response::error('Logo must be a PNG or JPEG image', 422);
        }

        // Store under a single canonical filename per extension. If the
        // user re-uploads in a different format, drop the old file so we
        // don't keep both around and accidentally serve the stale one.
        $accountDir = Storage::root() . "/accounts/$accountId";
        Storage::ensureDir($accountDir);
        foreach (['png', 'jpg'] as $oldExt) {
            $old = Storage::accountLogoPath($accountId, $oldExt);
            if (is_file($old) && $oldExt !== $extByMime) {
                @unlink($old);
            }
        }

        $dest = Storage::accountLogoPath($accountId, $extByMime);
        if (!move_uploaded_file($tmp, $dest)) {
            Response::error('Failed to store logo', 500);
        }

        Db::pdo()->prepare('UPDATE accounts SET logo_path = ? WHERE id = ?')
            ->execute([Storage::accountLogoRelative($accountId, $extByMime), $accountId]);

        Response::json(['account' => self::shape($accountId)]);
    }

    public static function deleteLogo(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        foreach (['png', 'jpg'] as $ext) {
            $p = Storage::accountLogoPath($accountId, $ext);
            if (is_file($p)) {
                @unlink($p);
            }
        }

        Db::pdo()->prepare('UPDATE accounts SET logo_path = NULL WHERE id = ?')
            ->execute([$accountId]);

        Response::json(['account' => self::shape($accountId)]);
    }

    public static function downloadLogo(Request $req): void
    {
        $accountId = Tenant::currentAccountId();

        $stmt = Db::pdo()->prepare('SELECT logo_path FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();

        $rel = is_array($row) ? ($row['logo_path'] ?? null) : null;
        if (!is_string($rel) || $rel === '') {
            Response::error('No logo on file', 404);
        }
        // Guard against a stored logo_path containing path-traversal segments.
        // Only the canonical layout (accounts/<id>/logo.png|jpg) is acceptable —
        // anything else (../, absolute paths, …) is treated as tampered data.
        if (!preg_match('#^accounts/\d+/logo\.(png|jpg)$#', $rel)) {
            error_log('[downloadLogo] suspicious logo_path on account ' . (int) $accountId . ': ' . $rel);
            Response::error('No logo on file', 404);
        }
        $abs  = Storage::root() . '/' . $rel;
        $root = realpath(Storage::root());
        $real = realpath($abs);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            Response::error('No logo on file', 404);
        }
        if (!is_file($abs)) {
            Response::error('No logo on file', 404);
        }

        $mime = str_ends_with($rel, '.jpg') ? 'image/jpeg' : 'image/png';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=30');
        readfile($abs);
        exit;
    }

    /** @return array<string, mixed> */
    private static function shape(int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, invoice_company_name, invoice_street, invoice_postal_code,
                    invoice_city, invoice_country, invoice_ico, invoice_dic, invoice_ic_dph,
                    logo_path, theme_color, subscription_end_date,
                    stripe_status, stripe_subscription_id, billing_period, stripe_customer_id,
                    stripe_cancel_at_period_end,
                    included_technicians, extra_technicians,
                    main_user_id, default_php_user_id, default_oprava_user_id
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch() ?: [];

        $status = $row['stripe_status'] ?? null;
        $subId  = $row['stripe_subscription_id'] ?? null;
        $state  = match (true) {
            $status === 'active'     => 'active',
            $status === 'trialing'   => 'trial_paid',
            $status === 'past_due'   => 'past_due',
            $status === 'canceled'   => 'canceled',
            $status === 'incomplete' || $status === 'incomplete_expired' => 'incomplete',
            $subId !== null && $subId !== ''                              => 'has_subscription',
            default                                                       => 'none',
        };

        return [
            'id'                   => (int) ($row['id'] ?? $accountId),
            'invoice_company_name' => $row['invoice_company_name'] ?? null,
            'invoice_street'       => $row['invoice_street']       ?? null,
            'invoice_postal_code'  => $row['invoice_postal_code']  ?? null,
            'invoice_city'         => $row['invoice_city']         ?? null,
            'invoice_country'      => $row['invoice_country']      ?? null,
            'invoice_ico'          => $row['invoice_ico']          ?? null,
            'invoice_dic'          => $row['invoice_dic']          ?? null,
            'invoice_ic_dph'       => $row['invoice_ic_dph']       ?? null,
            'theme_color'          => $row['theme_color'] ?? null,
            'has_logo'             => !empty($row['logo_path'])
                                       && is_file(Storage::root() . '/' . $row['logo_path']),
            'subscription_end_date' => $row['subscription_end_date'] ?? null,
            'stripe_status'         => $row['stripe_status'] ?? null,
            'subscription_state'    => $state,
            'billing_period'        => $row['billing_period'] ?? null,
            'has_stripe_customer'   => !empty($row['stripe_customer_id']),
            'stripe_cancel_at_period_end' => !empty($row['stripe_cancel_at_period_end']),
            // Seat / technician licensing — surfaced so the UI can show
            // "X of N seats used" and gate the invite flow client-side.
            'main_user_id'                   => isset($row['main_user_id']) ? (int) $row['main_user_id'] : null,
            'admin_owned'                    => isset($row['main_user_id'])
                                                  && \Firol\Auth\Admin::isAdmin((int) $row['main_user_id']),
            'default_php_user_id'            => isset($row['default_php_user_id']) ? (int) $row['default_php_user_id'] : null,
            'default_oprava_user_id'         => isset($row['default_oprava_user_id']) ? (int) $row['default_oprava_user_id'] : null,
            'included_technicians'           => (int) ($row['included_technicians'] ?? 2),
            'extra_technicians'              => (int) ($row['extra_technicians'] ?? 0),
            'active_technicians'             => SeatSync::countActiveTechnicians($accountId),
            'max_self_service_technicians'   => SeatSync::maxSelfServiceTechnicians(),
            'price_per_extra_technician_cents' => SeatSync::pricePerExtraCents(),
        ];
    }
}
