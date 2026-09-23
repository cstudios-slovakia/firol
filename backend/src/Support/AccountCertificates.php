<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * Firemné oprávnenia — chapter 1.3.1 (migration 041).
 *
 * Bezpečnostnotechnická služba (`bts`) and výchova a vzdelávanie (`vv`) are
 * held by the technician's FIRM, not by a person: entered once per account by
 * its main user, printed identically on the protocols of every technician in
 * it. The personal oprávnenia (po_technik, php_kontrola, php_oprava, bt) stay
 * on inspector_profiles.
 *
 * Which úkon prints which one: `skolenie_bozp` is signed under `vv`
 * (chapter 5.3). No block 2 type requires `bts`; it is stored and editable so
 * it is in place when a type needs it. Blocking an expired certificate is
 * block 5 and is deliberately not done here.
 */
final class AccountCertificates
{
    public const TYPES = ['bts', 'vv'];

    /** opravnenia.json — názov and právny základ of each company certificate. */
    public const LABELS = [
        'bts' => 'Bezpečnostnotechnická služba',
        'vv'  => 'Výchova a vzdelávanie',
    ];

    public const LEGAL_BASIS = [
        'bts' => '§ 22 z. 124/2006',
        'vv'  => '§ 27 ods. 3 z. 124/2006',
    ];

    /**
     * How the certificate is named on a protocol — chapter 1.3.3,
     * „oprávnenie na výchovu a vzdelávanie: VVZ-0456/2021".
     */
    public const PROTOCOL_LABELS = [
        'bts' => 'oprávnenie na bezpečnostnotechnickú službu',
        'vv'  => 'oprávnenie na výchovu a vzdelávanie',
    ];

    /** The same, in front of a number — „č. oprávnenia na výchovu a vzdelávanie: …" (mockup). */
    public const NUMBER_LABELS = [
        'bts' => 'č. oprávnenia na bezpečnostnotechnickú službu',
        'vv'  => 'č. oprávnenia na výchovu a vzdelávanie',
    ];

    /**
     * The account's certificates keyed by type; a type not entered is absent.
     *
     * @return array<string, array{type: string, number: string, valid_from: ?string, valid_to: ?string}>
     */
    public static function forAccount(int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT type, number, valid_from, valid_to
             FROM   account_certificates WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['type']] = [
                'type'       => (string) $row['type'],
                'number'     => (string) $row['number'],
                'valid_from' => $row['valid_from'] !== null ? (string) $row['valid_from'] : null,
                'valid_to'   => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
            ];
        }
        return $out;
    }

    /** The certificate of one type, or null when the account has not entered it. */
    public static function get(int $accountId, string $type): ?array
    {
        return self::forAccount($accountId)[$type] ?? null;
    }

    /**
     * Upsert one certificate; an empty number removes it.
     *
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function save(int $accountId, string $type, ?string $number, ?string $validFrom, ?string $validTo): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neznámy typ firemného oprávnenia.');
        }
        $number = $number !== null ? trim($number) : '';
        if ($number === '') {
            Db::pdo()->prepare('DELETE FROM account_certificates WHERE account_id = ? AND type = ?')
                ->execute([$accountId, $type]);
            return;
        }
        if (mb_strlen($number) > 191) {
            throw new \InvalidArgumentException('Číslo oprávnenia je príliš dlhé.');
        }
        $validFrom = self::date($validFrom, 'Platnosť od');
        $validTo   = self::date($validTo, 'Platnosť do');
        if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Platnosť do nemôže byť skôr ako platnosť od.');
        }

        Db::pdo()->prepare(
            'INSERT INTO account_certificates (account_id, type, number, valid_from, valid_to)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE number = VALUES(number),
                                     valid_from = VALUES(valid_from),
                                     valid_to = VALUES(valid_to)'
        )->execute([$accountId, $type, $number, $validFrom, $validTo]);
    }

    private static function date(?string $value, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($label . ' musí byť dátum.');
        }
        return $value;
    }
}
