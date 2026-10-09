<?php

declare(strict_types=1);

namespace Firol\Support;

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
 * Trainings come from the training tree: the latest per term chain for the
 * prevádzka or for the whole company, valid until their own date plus the
 * periodicity stored with them (change request 6). Vstupné and Opakované are
 * one chain ({@see Periodicity::trainingChain()}), so they print as one row,
 * named after the latest of the two. A training recorded without recurrence
 * reads „podľa potreby", like an inspection.
 */
final class ClientTerms
{
    /** Key under which the issued rows are frozen onto the kniha BOZP record. */
    public const SNAPSHOT_KEY = '_client_terms';

    /** „Blíži sa termín" window — the calendar's „do 30 dní" bucket. */
    private const SOON_DAYS = 30;

    /** Names of the inspection types, from typy_ukonov.json. */
    private const LABELS = [
        'php'                  => 'Kontrola hasiacich prístrojov',
        'hydranty'             => 'Kontrola požiarnych hydrantov',
        'ts_hadic'             => 'Tlaková skúška hadíc',
        'poziarna_kniha'       => 'Preventívna protipožiarna prehliadka',
        'pu_akcieschopnost'    => 'Požiarne uzávery — akcieschopnosť',
        'pu_udrzba'            => 'Požiarne uzávery — prevádzková údržba',
        'nudzove_osvetlenie'   => 'Kontrola núdzového osvetlenia',
        'skolenie_po'          => 'Školenie o ochrane pred požiarmi',
        'skolenie_bozp'        => 'Oboznámenie zamestnancov v oblasti BOZP',
        'oopp'                 => 'Kontrola OOPP',
        'oznacenie'            => 'Kontrola bezpečnostného označenia',
        'pracovne_prostriedky' => 'Kontrola pracovných prostriedkov',
        'rebriky'              => 'Kontrola rebríkov',
        'regale'               => 'Kontrola regálov',
        'fajcenie'             => 'Kontrola dodržiavania zákazu fajčenia',
        'dychova_skuska'       => 'Dychová skúška',
        'oprava_ts_php'        => 'Oprava, plnenie a tlaková skúška PHP',
        'kniha_bozp'           => 'Kniha kontrol BOZP',
        'pracovisko'           => 'Kontrola pracoviska',
        'osamele_pracovisko'   => 'Kontrola osamelých pracovísk',
        'omamne_latky'         => 'Kontrola omamných a psychotropných látok',
    ];

    /** Training types, as the app names them (api/trainings.ts). */
    private const TRAINING_LABELS = [
        'vstupne'      => 'Vstupné školenie vedúcich a ostatných zamestnancov',
        'opakovane'    => 'Opakované školenie vedúcich a ostatných zamestnancov',
        'opp_mimo'     => 'Školenie osôb zabezpečujúcich OPP v mimopracovnom čase',
        'zdrzujuca_sa' => 'Školenie osôb zdržujúcich sa na pracovisku',
        'hliadka_oph'  => 'Odborná príprava protipožiarnej hliadky pracoviska',
        'hliadka_opah' => 'Odborná príprava protipožiarnej asistenčnej hliadky',
        'pokyn_zatva'  => 'Pokyn na zabezpečenie ochrany pred požiarmi pri žatevných prácach, '
            . 'pri zbere a skladovaní objemových krmovín',
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

        // Latest finalized training per term chain — for this prevádzka or for
        // the whole company (facility left empty). Walked oldest first, so the
        // last one seen for a chain is its latest; Vstupné and Opakované share
        // a chain and print one row, named after the later one.
        $tStmt = Db::pdo()->prepare(
            'SELECT t.type, t.date, t.periodicity_value, t.periodicity_unit
             FROM   trainings t
             WHERE  t.account_id = :acct AND t.company_id = :comp
               AND  (t.facility_id = :fac OR t.facility_id IS NULL)
               AND  t.status = "finalized" AND t.archived_at IS NULL
               AND  t.date IS NOT NULL AND t.date <= :asof
             ORDER  BY t.date ASC, t.id ASC'
        );
        $tStmt->execute(['acct' => $accountId, 'comp' => $companyId, 'fac' => $facilityId, 'asof' => $asOf]);
        $latest = [];
        foreach ($tStmt->fetchAll() as $r) {
            unset($latest[Periodicity::trainingChain((string) $r['type'])]);
            $latest[Periodicity::trainingChain((string) $r['type'])] = $r;
        }
        // Re-inserting on every hit keeps the array in date order, as the
        // inspection rows above are.
        foreach ($latest as $r) {
            $type = (string) $r['type'];
            $value = $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null;
            $unit = $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null;
            $rows[] = self::row(
                self::TRAINING_LABELS[$type] ?? $type,
                (string) $r['date'],
                Periodicity::validUntil((string) $r['date'], $value, $unit),
                $asOf,
            );
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
        return self::LABELS[$type] ?? $type;
    }
}
