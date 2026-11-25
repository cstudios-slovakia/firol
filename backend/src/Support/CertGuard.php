<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * Whose oprávnenie signs an úkon, and whether it is there.
 *
 * One home for the rules, so the refusal on create / repeat / follow-up /
 * scope change and the verdict the profile hands to the offline client can
 * never disagree. Each check returns null when the technician may proceed,
 * or the refusal: the message plus which number is missing (`cert`), ready
 * for `Response::error(..., 422, ['code' => 'cert_missing', 'cert' => ...])`.
 *
 * @phpstan-type Missing array{error: string, cert: string}
 */
final class CertGuard
{
    private const PO_MESSAGE = 'Protipožiarna prehliadka vyžaduje platné číslo oprávnenia Technik PO. Doplň ho v Profile technika.';
    private const BOZP_MESSAGE = 'Prehliadka BOZP vyžaduje číslo oprávnenia bezpečnostného technika. Doplň ho v Profile technika.';

    /**
     * Technik PO types (everything except php and oprava_ts_php) require the
     * executor's own cert_general — no borrowing. For PHP / Oprava the
     * executor may borrow the account-default technician's cert; only when
     * neither own nor default cert is available is the úkon refused.
     *
     * @return array{error: string, cert: string}|null
     */
    public static function missingForType(int $accountId, int $inspectorUserId, string $type): ?array
    {
        // Oboznámenie BOZP is signed under the account's own oprávnenie na
        // výchovu a vzdelávanie (`vv`, chapter 5.3) — a company certificate,
        // not a personal one — so nothing on the technician's profile is
        // required to create it. Its presence is checked when the protocol is
        // issued (DocumentController / PersonProtocol).
        if ($type === 'skolenie_bozp') {
            return null;
        }

        $own = self::ownCerts($accountId, $inspectorUserId);

        // Every other BOZP úkon is signed as a bezpečnostný technik, not as a
        // technik PO — chapter 26, „číslo oprávnenia na protokole MUSÍ
        // zodpovedať typu úkonu". No borrowing: the oprávnenie is personal,
        // and the protocol says who did the work.
        if (Sections::forInspectionType($type) === Sections::BOZP) {
            return self::has($own['cert_bt'] ?? null) ? null : [
                'error' => 'Tento úkon BOZP vyžaduje číslo oprávnenia bezpečnostného technika. Doplň ho v Profile technika.',
                'cert'  => 'cert_bt',
            ];
        }

        if ($type === 'php' || $type === 'oprava_ts_php') {
            $certKey = $type === 'php' ? 'cert_php' : 'cert_oprava';
            if (self::has($own[$certKey] ?? null) || self::defaultHas($accountId, $certKey)) {
                return null;
            }
            $label = $type === 'php' ? 'Kontrola PHP' : 'Oprava / plnenie / TS PHP';
            return [
                'error' => 'Pre ' . $label . ' potrebuješ vlastné číslo oprávnenia, alebo nech správca účtu nastaví predvoleného technika so zadaným číslom.',
                'cert'  => $certKey,
            ];
        }

        // All other types print cert_general (Technik PO). No fallback.
        return self::has($own['cert_general'] ?? null) ? null : [
            'error' => 'Tento typ kontroly vyžaduje platné číslo oprávnenia Technik PO. Doplň ho v Profile revízneho technika.',
            'cert'  => 'cert_general',
        ];
    }

    /**
     * A prehliadka is signed twice over: the PO record by a technik PO
     * (cert_general), the BOZP record by a bezpečnostný technik (cert_bt).
     * Only what the chosen scope needs is required. No borrowing, as for the
     * single úkony of those sections.
     *
     * @return array{error: string, cert: string}|null
     */
    public static function missingForPrehliadka(int $accountId, int $inspectorUserId, bool $po, bool $bozp): ?array
    {
        if (!$po && !$bozp) {
            return null;
        }
        $own = self::ownCerts($accountId, $inspectorUserId);
        if ($po && !self::has($own['cert_general'] ?? null)) {
            return ['error' => self::PO_MESSAGE, 'cert' => 'cert_general'];
        }
        if ($bozp && !self::has($own['cert_bt'] ?? null)) {
            return ['error' => self::BOZP_MESSAGE, 'cert' => 'cert_bt'];
        }
        return null;
    }

    /**
     * The verdict for every úkon the technician could start, keyed by type —
     * the prehliadka split into its two scopes (`preventivna_prehliadka.po`,
     * `preventivna_prehliadka.bozp`). Cached by the client with the profile,
     * so an úkon started offline is refused up front exactly as the server
     * would refuse it on sync.
     *
     * @param list<string> $types
     * @return array<string, array{error: string, cert: string}|null>
     */
    public static function verdicts(int $accountId, int $inspectorUserId, array $types): array
    {
        $out = [];
        foreach ($types as $type) {
            if ($type === PrehliadkaDetails::TYPE) {
                $out[$type . '.po'] = self::missingForPrehliadka($accountId, $inspectorUserId, true, false);
                $out[$type . '.bozp'] = self::missingForPrehliadka($accountId, $inspectorUserId, false, true);
                continue;
            }
            $out[$type] = self::missingForType($accountId, $inspectorUserId, $type);
        }
        return $out;
    }

    /**
     * The technician's own numbers for an úkon in this account. For a
     * platform admin in a client account they are completed from the admin's
     * home profile (see InspectorProfiles), exactly as the protocol prints them.
     *
     * @return array<string, mixed>
     */
    private static function ownCerts(int $accountId, int $userId): array
    {
        return InspectorProfiles::forAccount($userId, $accountId);
    }

    private static function defaultHas(int $accountId, string $certKey): bool
    {
        $defaultCol = $certKey === 'cert_php' ? 'default_php_user_id' : 'default_oprava_user_id';
        $stmt = Db::pdo()->prepare(
            'SELECT ip.' . $certKey . '
             FROM   accounts a
             LEFT JOIN inspector_profiles ip
                    ON ip.user_id = a.' . $defaultCol . ' AND ip.account_id = a.id
             WHERE  a.id = ?'
        );
        $stmt->execute([$accountId]);
        return self::has($stmt->fetchColumn());
    }

    private static function has(mixed $v): bool
    {
        return is_string($v) && trim($v) !== '';
    }
}
