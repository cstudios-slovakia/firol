<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
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

        Db::pdo()->prepare(
            'UPDATE accounts
             SET    invoice_company_name = COALESCE(?, invoice_company_name),
                    theme_color          = ?
             WHERE  id = ?'
        )->execute([
            $invoiceName !== null ? trim($invoiceName) : null,
            $themeColorValue,
            $accountId,
        ]);

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
        if (!$rel) {
            Response::error('No logo on file', 404);
        }
        $abs = Storage::root() . '/' . $rel;
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
            'SELECT id, invoice_company_name, logo_path, theme_color, subscription_end_date
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch() ?: [];

        return [
            'id'                   => (int) ($row['id'] ?? $accountId),
            'invoice_company_name' => $row['invoice_company_name'] ?? null,
            'theme_color'          => $row['theme_color'] ?? null,
            'has_logo'             => !empty($row['logo_path'])
                                       && is_file(Storage::root() . '/' . $row['logo_path']),
            'subscription_end_date' => $row['subscription_end_date'] ?? null,
        ];
    }
}
