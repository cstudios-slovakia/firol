<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * „Vykonané činnosti" of a preventívna prehliadka (spec 6.4, plan phase 7b):
 * a plain list of what the technician walked through — no states.
 *
 * Two separate sets, which never mix: PO and BOZP. The defaults are code; the
 * firm's own additions are rows of `company_activities` and are offered again
 * at that firm only. The ticked ones are copied into the prehliadka's
 * `details` (`activities_po`, `activities_bozp`) as plain strings, so an issued
 * record keeps its wording whatever happens to the list afterwards.
 *
 * The wording is the prototype's `KONTR_PO` and the sample record's list; the
 * BOZP set is what the kniha BOZP already offers ({@see BozpRecords::knihaActivities()}).
 */
final class PrehliadkaActivities
{
    public const PO = 'po';
    public const BOZP = 'bozp';

    public const MAX_LABEL = 191;

    /** Default set of the preventívna prehliadka (spec 6.4 „Sada PO"), verbatim. */
    private const PO_DEFAULTS = [
        'Kontrola únikových ciest, únikových východov a zásahových ciest',
        'Kontrola požiarnych uzáverov',
        'Kontrola prenosných hasiacich prístrojov',
        'Kontrola hadicových zariadení (požiarnych vodovodov)',
        'Kontrola núdzového osvetlenia',
        'Kontrola prístupu k uzáverom energií a vody',
        'Kontrola označenia a dokumentácie ochrany pred požiarmi',
        'Kontrola skladovania a manipulácie s horľavými látkami',
        'Kontrola komína a palivových spotrebičov',
        'Kontrola garážových priestorov',
        'Kontrola spoločných priestorov',
    ];

    public static function isKind(string $kind): bool
    {
        return $kind === self::PO || $kind === self::BOZP;
    }

    /** @return list<string> */
    public static function defaults(string $kind): array
    {
        return $kind === self::BOZP ? BozpRecords::knihaActivities() : self::PO_DEFAULTS;
    }

    /**
     * Both sets of a firm: the defaults and its own additions.
     *
     * @return array{po: array{defaults: list<string>, custom: list<array{id: int, label: string}>}, bozp: array{defaults: list<string>, custom: list<array{id: int, label: string}>}}
     */
    public static function forCompany(int $accountId, int $companyId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, kind, label FROM company_activities
             WHERE  account_id = ? AND company_id = ? AND archived_at IS NULL
             ORDER  BY id ASC'
        );
        $stmt->execute([$accountId, $companyId]);
        $custom = [self::PO => [], self::BOZP => []];
        foreach ($stmt->fetchAll() as $row) {
            $custom[(string) $row['kind']][] = ['id' => (int) $row['id'], 'label' => (string) $row['label']];
        }
        return [
            self::PO   => ['defaults' => self::defaults(self::PO), 'custom' => $custom[self::PO]],
            self::BOZP => ['defaults' => self::defaults(self::BOZP), 'custom' => $custom[self::BOZP]],
        ];
    }

    /**
     * Adds an activity to the firm's list. An activity already there (same
     * kind, same text ignoring case) is returned instead of added twice.
     *
     * @return array{id: int, label: string}
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function add(int $accountId, int $companyId, string $kind, string $label, ?int $userId): array
    {
        if (!self::isKind($kind)) {
            throw new \InvalidArgumentException('Neznáma sada činností.');
        }
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Zadaj, čo bolo kontrolované.');
        }
        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new \InvalidArgumentException('Text činnosti je príliš dlhý (najviac ' . self::MAX_LABEL . ' znakov).');
        }

        $pdo = Db::pdo();
        // Already on offer (a default, or an earlier addition) — nothing to add.
        foreach (self::defaults($kind) as $default) {
            if (mb_strtolower($default) === mb_strtolower($label)) {
                throw new \InvalidArgumentException('Táto činnosť je už v základnej sade.');
            }
        }
        $existing = $pdo->prepare(
            'SELECT id, label FROM company_activities
             WHERE  account_id = ? AND company_id = ? AND kind = ? AND archived_at IS NULL
               AND  LOWER(label) = LOWER(?)
             LIMIT  1'
        );
        $existing->execute([$accountId, $companyId, $kind, $label]);
        $row = $existing->fetch();
        if ($row) {
            return ['id' => (int) $row['id'], 'label' => (string) $row['label']];
        }

        $pdo->prepare(
            'INSERT INTO company_activities (account_id, company_id, kind, label, created_by_user_id)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$accountId, $companyId, $kind, $label, $userId]);
        return ['id' => (int) $pdo->lastInsertId(), 'label' => $label];
    }

    /** Takes an activity off the firm's list; issued records keep their text. */
    public static function archive(int $accountId, int $companyId, int $id): bool
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE company_activities SET archived_at = NOW()
             WHERE  id = ? AND account_id = ? AND company_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId, $companyId]);
        return $stmt->rowCount() > 0;
    }
}
