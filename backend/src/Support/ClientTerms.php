<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Audit\AuditCatalog;
use Firol\Db;

/**
 * „Termíny a kontroly" — the client's own overview printed inside the
 * Záznam o kontrole stavu BOZP (block 2 / chapter 26, README „Prehľad termínov
 * v zázname z kontroly"): every revision, check and training on record — what,
 * when it was done, until when it is valid and in what state.
 *
 * Built from the same data the calendar reads (CalendarController): for each
 * type, the latest finalized úkon on this prevádzka, valid until its own date
 * plus its own periodicity. Two differences, both because this is a dated
 * document rather than a live screen:
 *
 *   - it is computed AS OF the protocol's date, not today — a record dated
 *     12. 8. lists what was on record on 12. 8. and judges „blíži sa termín"
 *     against that day, so a backdated protocol reads the way it would have
 *     read on the day; and
 *   - úkony without a periodicity are listed too, with „podľa potreby" in
 *     place of a date (chapter 5), since they are part of the client's record
 *     even though no deadline follows from them.
 *
 * Trainings come from the training tree (latest per type for the prevádzka or
 * for the whole company); they carry no periodicity of their own.
 */
final class ClientTerms
{
    /** Key under which the issued rows are frozen onto the kniha BOZP record. */
    public const SNAPSHOT_KEY = '_client_terms';

    /** „Blíži sa termín" window — the calendar's „do 30 dní" bucket. */
    private const SOON_DAYS = 30;

    /** Names from typy_ukonov.json for the types the audit map does not name. */
    private const LABELS = [
        'oprava_ts_php'      => 'Oprava, plnenie a tlaková skúška PHP',
        'audit_bozp'         => 'Audit BOZP',
        'audit_opp'          => 'Audit ochrany pred požiarmi',
        'kniha_bozp'         => 'Kniha kontrol BOZP',
        'pracovisko'         => 'Kontrola pracoviska',
        'osamele_pracovisko' => 'Kontrola osamelých pracovísk',
        'omamne_latky'       => 'Kontrola omamných a psychotropných látok',
    ];

    /** Training types, as the app names them (api/trainings.ts). */
    private const TRAINING_LABELS = [
        'vstupne'      => 'Vstupné školenie vedúcich a ostatných zamestnancov',
        'opakovane'    => 'Opakované školenie vedúcich a ostatných zamestnancov',
        'opp_mimo'     => 'Školenie osôb zabezpečujúcich OPP v mimopracovnom čase',
        'zdrzujuca_sa' => 'Školenie osôb zdržujúcich sa na pracovisku',
        'hliadka_oph'  => 'Odborná príprava protipožiarnej hliadky pracoviska',
        'hliadka_opah' => 'Odborná príprava protipožiarnej asistenčnej hliadky',
    ];

    private const MONTHS = [
        1 => 'január', 'február', 'marec', 'apríl', 'máj', 'jún',
        'júl', 'august', 'september', 'október', 'november', 'december',
    ];

    /**
     * @return list<array{label: string, done: string, valid_until: string, state: string|null, state_tone: string|null}>
     */
    public static function forProtocol(
        int $accountId,
        int $companyId,
        int $facilityId,
        string $asOf,
        string $excludeType,
    ): array {
        $rows = [];

        // Latest finalized úkon per type on this prevádzka, up to the date.
        // Plain fire-book entries and one-off documents (vyradenie) carry
        // is_preventive_inspection = 0 and are no „kontrola" of anything.
        $stmt = Db::pdo()->prepare(
            'SELECT i.type, i.executed_on, i.periodicity_value, i.periodicity_unit
             FROM   inspections i
             WHERE  i.account_id = :acct AND i.facility_id = :fac
               AND  i.status = "finalized" AND i.archived_at IS NULL
               AND  i.is_preventive_inspection = 1
               AND  i.executed_on IS NOT NULL AND i.executed_on <= :asof
               AND  i.type <> :excl
               AND  NOT EXISTS (
                      SELECT 1 FROM inspections s
                      WHERE  s.account_id = i.account_id AND s.facility_id = i.facility_id
                        AND  s.type = i.type AND s.status = "finalized"
                        AND  s.archived_at IS NULL AND s.is_preventive_inspection = 1
                        AND  s.executed_on IS NOT NULL AND s.executed_on <= :asof2
                        AND  (s.executed_on, s.id) > (i.executed_on, i.id)
                    )
             ORDER  BY i.executed_on ASC, i.id ASC'
        );
        $stmt->execute([
            'acct' => $accountId, 'fac' => $facilityId, 'asof' => $asOf,
            'asof2' => $asOf, 'excl' => $excludeType,
        ]);
        foreach ($stmt->fetchAll() as $r) {
            $value = $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null;
            $unit = $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null;
            $rows[] = self::row(
                self::inspectionLabel((string) $r['type']),
                (string) $r['executed_on'],
                Periodicity::validUntil((string) $r['executed_on'], $value, $unit),
                $asOf,
            );
        }

        // Latest finalized training per type — for this prevádzka or for the
        // whole company (facility left empty).
        $tStmt = Db::pdo()->prepare(
            'SELECT t.type, t.date
             FROM   trainings t
             WHERE  t.account_id = :acct AND t.company_id = :comp
               AND  (t.facility_id = :fac OR t.facility_id IS NULL)
               AND  t.status = "finalized" AND t.archived_at IS NULL
               AND  t.date IS NOT NULL AND t.date <= :asof
               AND  NOT EXISTS (
                      SELECT 1 FROM trainings s
                      WHERE  s.account_id = t.account_id AND s.company_id = t.company_id
                        AND  (s.facility_id = :fac2 OR s.facility_id IS NULL)
                        AND  s.type = t.type AND s.status = "finalized" AND s.archived_at IS NULL
                        AND  s.date IS NOT NULL AND s.date <= :asof2
                        AND  (s.date, s.id) > (t.date, t.id)
                    )
             ORDER  BY t.date ASC, t.id ASC'
        );
        $tStmt->execute([
            'acct' => $accountId, 'comp' => $companyId, 'fac' => $facilityId, 'fac2' => $facilityId,
            'asof' => $asOf, 'asof2' => $asOf,
        ]);
        foreach ($tStmt->fetchAll() as $r) {
            $type = (string) $r['type'];
            $rows[] = self::row(self::TRAINING_LABELS[$type] ?? $type, (string) $r['date'], null, $asOf);
        }

        return $rows;
    }

    /**
     * @return array{label: string, done: string, valid_until: string, state: string|null, state_tone: string|null}
     */
    private static function row(string $label, string $done, ?string $validUntil, string $asOf): array
    {
        [$state, $tone] = self::state($validUntil, $asOf);
        return [
            'label'       => $label,
            'done'        => self::monthYear($done),
            'valid_until' => $validUntil !== null ? self::monthYear($validUntil) : 'podľa potreby',
            'state'       => $state,
            'state_tone'  => $tone,
        ];
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function state(?string $validUntil, string $asOf): array
    {
        if ($validUntil === null) {
            return [null, null];
        }
        $days = (int) floor((strtotime($validUntil) - strtotime($asOf)) / 86400);
        if ($days < 0) {
            return ['po termíne', 'bad'];
        }
        if ($days <= self::SOON_DAYS) {
            return ['blíži sa termín', 'bad'];
        }
        return ['v platnosti', 'ok'];
    }

    /** „marec 2023", as the mockup prints it. */
    private static function monthYear(string $iso): string
    {
        $ts = strtotime($iso);
        if (!$ts) {
            return $iso;
        }
        return self::MONTHS[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }

    private static function inspectionLabel(string $type): string
    {
        return AuditCatalog::LINKED_TYPE_LABELS[$type] ?? self::LABELS[$type] ?? $type;
    }
}
