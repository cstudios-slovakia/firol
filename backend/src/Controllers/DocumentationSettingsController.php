<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * Module settings for Dokumentácia PO (spec §9), backed by system_settings:
 *
 *   doc_signer_functions  — JSON list of signer-function labels offered in
 *                           the konateľ "funkcia" dropdown.
 *   doc_water_utilities   — JSON list of { region, phone } used to prefill
 *                           the water-utility emergency number by region.
 *
 * Reading is allowed for any authenticated technician (the wizard needs
 * the options); writing is admin-only — these are global, FIROL-managed
 * values, not per-account.
 */
final class DocumentationSettingsController
{
    private const KEY_SIGNERS = 'doc_signer_functions';
    private const KEY_WATER   = 'doc_water_utilities';

    /** @var list<string> */
    private const DEFAULT_SIGNERS = [
        'konateľ spoločnosti',
        'riaditeľ',
        'prokurista',
        'majiteľ',
        'štatutárny zástupca',
    ];

    /** Slovak self-governing regions; phones left blank for FIROL to fill. */
    private const DEFAULT_WATER = [
        ['region' => 'Bratislavský kraj',    'phone' => ''],
        ['region' => 'Trnavský kraj',        'phone' => ''],
        ['region' => 'Trenčiansky kraj',     'phone' => ''],
        ['region' => 'Nitriansky kraj',      'phone' => ''],
        ['region' => 'Žilinský kraj',        'phone' => ''],
        ['region' => 'Banskobystrický kraj', 'phone' => ''],
        ['region' => 'Prešovský kraj',       'phone' => ''],
        ['region' => 'Košický kraj',         'phone' => ''],
    ];

    // system_settings.setting_value is VARCHAR(512); keep payloads safely
    // under that so nothing is silently truncated.
    private const MAX_VALUE_LEN = 500;

    public static function show(Request $req): void
    {
        // Any authenticated user may read the options.
        Tenant::currentUserId();
        Response::json(self::loadAll());
    }

    public static function update(Request $req): void
    {
        Admin::require();
        Csrf::require($req);

        $body = $req->json();

        if (array_key_exists('signer_functions', $body)) {
            $signers = self::sanitizeStringList($body['signer_functions']);
            if (count($signers) === 0) {
                Response::error('signer_functions must contain at least one entry', 422);
            }
            self::save(self::KEY_SIGNERS, $signers);
        }

        if (array_key_exists('water_utilities', $body)) {
            $water = self::sanitizeWaterList($body['water_utilities']);
            self::save(self::KEY_WATER, $water);
        }

        Response::json(self::loadAll());
    }

    /** @return array{signer_functions: list<string>, water_utilities: list<array{region:string,phone:string}>} */
    private static function loadAll(): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?)'
        );
        $stmt->execute([self::KEY_SIGNERS, self::KEY_WATER]);
        $raw = [];
        foreach ($stmt->fetchAll() as $row) {
            $raw[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        $signers = self::decodeList($raw[self::KEY_SIGNERS] ?? null);
        $water   = self::decodeList($raw[self::KEY_WATER] ?? null);

        return [
            'signer_functions' => $signers !== null ? self::sanitizeStringList($signers) : self::DEFAULT_SIGNERS,
            'water_utilities'  => $water !== null ? self::sanitizeWaterList($water) : self::DEFAULT_WATER,
        ];
    }

    /**
     * @param mixed $value
     * @return array<mixed>|null
     */
    private static function decodeList($value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function sanitizeStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '' && !in_array($trimmed, $out, true)) {
                $out[] = mb_substr($trimmed, 0, 120);
            }
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @return list<array{region: string, phone: string}>
     */
    private static function sanitizeWaterList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $region = trim((string) ($item['region'] ?? ''));
            $phone  = trim((string) ($item['phone'] ?? ''));
            if ($region === '') {
                continue;
            }
            $out[] = [
                'region' => mb_substr($region, 0, 60),
                'phone'  => mb_substr($phone, 0, 40),
            ];
        }
        return $out;
    }

    /** @param array<mixed> $value */
    private static function save(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            Response::error('Failed to encode settings', 500);
        }
        if (strlen($json) > self::MAX_VALUE_LEN) {
            Response::error('Príliš veľa položiek — skráť zoznam.', 422);
        }
        Db::pdo()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $json]);
    }
}
