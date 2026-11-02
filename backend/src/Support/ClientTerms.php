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
 * plus its own periodicity. Differences, because this is a dated document
 * rather than a live screen:
 *
 *   - it is computed AS OF the protocol's date, not today — a record dated
 *     12. 8. lists what was on record on 12. 8. and judges „blíži sa termín"
 *     against that day, so a backdated protocol reads the way it would have
 *     read on the day;
 *   - úkony without a periodicity are listed too, with „podľa potreby" in
 *     place of a date (chapter 5), since they are part of the client's record
 *     even though no deadline follows from them; and
 *   - phase 6, spec 6.6: only the terms of the record's own book are listed
 *     ({@see BookMapping}), the firm's external terms ({@see ExternalTerms}) join
 *     them in the same table, and everything is sorted by validity.
 *
 * Trainings come from the training tree: the latest per term chain for the
 * prevádzka or for the whole company, valid until their own date plus the
 * periodicity stored with them (change request 6). Vstupné and Opakované are
 * one chain ({@see Periodicity::trainingChain()}), so they print as one row,
 * named after the latest of the two. A training recorded without recurrence
 * reads „podľa potreby", like an inspection. Every training is PO.
 */
final class ClientTerms
{
    /** Key under which the issued rows are frozen onto the kniha BOZP record. */
    public const SNAPSHOT_KEY = '_client_terms';

    /** „Blíži sa termín" window — the calendar's „do 30 dní" bucket. */
    private const SOON_DAYS = 30;

    /** Names of the inspection types, from typy_ukonov.json. */
    public const LABELS = [
        'php'                  => 'Kontrola hasiacich prístrojov',
        'hydranty'             => 'Kontrola požiarnych hydrantov',
        'ts_hadic'             => 'Tlaková skúška hadíc',
        'poziarna_kniha'       => 'Preventívna protipožiarna prehliadka',
        'preventivna_prehliadka' => 'Preventívna protipožiarna prehliadka',
        'pu_akcieschopnost'    => 'Požiarne uzávery — akcieschopnosť',
        'pu_udrzba'            => 'Požiarne uzávery — prevádzková údržba',
        'nudzove_osvetlenie'   => 'Kontrola núdzového osvetlenia',
        'skolenie_po'          => 'Školenie o ochrane pred požiarmi',
        'skolenie_bozp'        => 'Oboznámenie zamestnancov v oblasti BOZP',
        'oopp'                 => 'Kontrola OOPP',
        'oznacenie'            => 'Kontrola bezpečnostného označenia',
        'pracovne_prostriedky' => 'Kontrola pracovných prostriedkov',
        'rebriky'              => 'Rebríky (pracovné)',
        'regale'               => 'Kontrola regálov',
        'fajcenie'             => 'Kontrola dodržiavania zákazu fajčenia',
        'dychova_skuska'       => 'Dychová skúška',
        'oprava_ts_php'        => 'Oprava, plnenie a tlaková skúška PHP',
        'kniha_bozp'           => 'Preventívna prehliadka BOZP',
        'pracovisko'           => 'Kontrola pracoviska',
        'osamele_pracovisko'   => 'Kontrola osamelých pracovísk',
        'omamne_latky'         => 'Kontrola omamných a psychotropných látok',
    ];

    /** Training types, as the app names them (api/trainings.ts). */
    public const TRAINING_LABELS = [
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
     * The rows of one record's „Termíny a kontroly" table, as of the record's
     * date, for the record's own book. A kniha BOZP therefore shows rebríky,
     * regále and the other BOZP úkony, never the fire-protection ones.
     *
     * @return list<array{label: string, done: string, valid_until: string, state: string|null, state_tone: string|null}>
     */
    public static function forProtocol(
        int $accountId,
        int $companyId,
        int $facilityId,
        string $asOf,
        string $excludeType,
        string $book = BookMapping::BOZP,
    ): array {
        $raw = self::ownRaw($accountId, $companyId, $facilityId, $asOf, $book, $excludeType);

        foreach (ExternalTerms::list($accountId, $companyId, $book, $facilityId) as $t) {
            // As of the record's date: a revision done after it was not on file yet.
            if ($t['done_on'] !== null && $t['done_on'] > $asOf) {
                continue;
            }
            $raw[] = [
                'label'    => (string) $t['name'],
                'done'     => $t['done_on'],
                'valid'    => $t['valid_until'],
                'external' => true,
            ];
        }

        // Soonest validity first; a row without a date goes last.
        usort($raw, static fn (array $a, array $b): int =>
            [$a['valid'] === null ? 1 : 0, $a['valid'] ?? '', $a['label']]
            <=> [$b['valid'] === null ? 1 : 0, $b['valid'] ?? '', $b['label']]);

        return array_map(static fn (array $r): array => self::row($r, $asOf), $raw);
    }

    /**
     * The „Termíny" table of a preventívna prehliadka record (spec 6.6, plan 8a):
     * what the walk-through itself puts in — the next prehliadka, every ticked
     * riadna kontrola, each with the hand-written date when the technician
     * overrode it — on top of what is on record (úkony of the book not ticked
     * today, the book's trainings) and the firm's external terms.
     *
     * `$auto` rows replace the on-record row with the same key (see {@see ownRaw()}
     * for the key scheme, it is the one step 3 of the prehliadka uses), and the
     * prehliadka chain itself is never read from the record: the new row stands
     * for it, so the table never lists this prehliadka's predecessor.
     *
     * @param list<array{key: string, label: string, done: ?string, valid: ?string, overridden: bool}> $auto
     * @return list<array{label: string, done: string, valid_until: string, state: string|null, state_tone: string|null, overridden: bool}>
     */
    public static function forPrehliadka(
        int $accountId,
        int $companyId,
        int $facilityId,
        string $asOf,
        string $book,
        array $auto,
    ): array {
        $autoKeys = array_column($auto, 'key');
        $raw = [];
        // The BOZP side's own term chain is the kniha_bozp úkon; the „Preventívna
        // prehliadka BOZP" row of $auto stands for it, as the PO row does for its chain.
        $exclude = $book === BookMapping::BOZP ? 'kniha_bozp' : null;
        foreach (self::ownRaw($accountId, $companyId, $facilityId, $asOf, $book, $exclude) as $r) {
            if (Periodicity::inspectionChain((string) $r['type']) === 'preventivna_prehliadka') {
                continue;
            }
            if (in_array($r['key'], $autoKeys, true)) {
                continue;
            }
            $raw[] = $r;
        }
        foreach ($auto as $a) {
            $raw[] = [
                'key' => $a['key'], 'type' => '', 'label' => $a['label'],
                'done' => $a['done'], 'valid' => $a['valid'],
                'external' => false, 'overridden' => $a['overridden'],
            ];
        }
        foreach (ExternalTerms::list($accountId, $companyId, $book, $facilityId) as $t) {
            $raw[] = [
                'key' => 'external:' . (int) ($t['id'] ?? 0), 'type' => '', 'label' => (string) $t['name'],
                'done' => $t['done_on'], 'valid' => $t['valid_until'], 'external' => true, 'overridden' => false,
            ];
        }

        usort($raw, static fn (array $a, array $b): int =>
            [$a['valid'] === null ? 1 : 0, $a['valid'] ?? '', $a['label']]
            <=> [$b['valid'] === null ? 1 : 0, $b['valid'] ?? '', $b['label']]);

        return array_map(static function (array $r) use ($asOf): array {
            $row = self::row($r, $asOf);
            // A date the technician wrote by hand prints in full — a month and
            // year would hide the very precision they typed.
            if (!empty($r['overridden']) && $r['valid'] !== null) {
                $row['valid_until'] = ExternalTerms::skDate((string) $r['valid']);
            }
            $row['overridden'] = !empty($r['overridden']);
            return $row;
        }, $raw);
    }

    /**
     * What is still to come, for the „Termíny ďalších kontrol" offer of the
     * požiarna kniha: the own úkony of the book with the date each is next due.
     * Company-wide when `$facilityId` is null.
     *
     * A term the technician overrode by hand in a closed prehliadka (spec 6.6)
     * is offered with that date — for this offer only. The calendar and the
     * úkon's own periodicity are untouched, and a newer úkon of the same kind
     * brings the automatic term back (see {@see prehliadkaOverrides()}).
     *
     * @return list<array{label: string, valid: string}> soonest first
     */
    public static function upcoming(int $accountId, int $companyId, ?int $facilityId, string $asOf, string $book): array
    {
        $out = [];
        // Úkony only: a training is no „kontrola" (the prototype's `knihaTerminy`).
        foreach (self::ownRaw($accountId, $companyId, $facilityId, $asOf, $book, null, false, true, true) as $r) {
            if ($r['valid'] !== null) {
                $out[] = ['label' => $r['label'], 'valid' => $r['valid']];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['valid'], $a['label']] <=> [$b['valid'], $b['label']]);
        return $out;
    }

    /**
     * The úkony and trainings on record, with their raw dates.
     *
     * @return list<array{label: string, done: ?string, valid: ?string, external: bool}>
     */
    private static function ownRaw(
        int $accountId,
        int $companyId,
        ?int $facilityId,
        string $asOf,
        string $book,
        ?string $excludeType,
        bool $withTrainings = true,
        bool $earliestDue = false,
        bool $followOverrides = false,
    ): array {
        $rows = [];
        $overrides = $followOverrides
            ? self::prehliadkaOverrides($accountId, $companyId, $facilityId, $asOf)
            : [];

        // Latest finalized úkon per type per prevádzka, up to the date.
        // Plain fire-book entries and one-off documents (vyradenie) carry
        // is_preventive_inspection = 0 and are no „kontrola" of anything.
        $facilitySql = $facilityId !== null ? ' AND i.facility_id = :fac' : '';
        $stmt = Db::pdo()->prepare(
            'SELECT i.id, i.type, i.facility_id, i.executed_on, i.periodicity_value, i.periodicity_unit
             FROM   inspections i
             WHERE  i.account_id = :acct AND i.company_id = :comp' . $facilitySql . '
               AND  i.status = "finalized" AND i.archived_at IS NULL
               AND  i.is_preventive_inspection = 1
               AND  i.executed_on IS NOT NULL AND i.executed_on <= :asof
               AND  i.type <> :excl
               AND  NOT EXISTS (
                      SELECT 1 FROM inspections s
                      WHERE  s.account_id = i.account_id AND s.facility_id = i.facility_id
                        AND  (s.type = i.type
                              OR (s.type IN ("poziarna_kniha", "preventivna_prehliadka")
                                  AND i.type IN ("poziarna_kniha", "preventivna_prehliadka")))
                        AND  s.status = "finalized"
                        AND  s.archived_at IS NULL AND s.is_preventive_inspection = 1
                        AND  s.executed_on IS NOT NULL AND s.executed_on <= :asof2
                        AND  (s.executed_on, s.id) > (i.executed_on, i.id)
                    )
             ORDER  BY i.executed_on ASC, i.id ASC'
        );
        $args = [
            'acct' => $accountId, 'comp' => $companyId, 'asof' => $asOf,
            'asof2' => $asOf, 'excl' => $excludeType ?? '',
        ];
        if ($facilityId !== null) {
            $args['fac'] = $facilityId;
        }
        $stmt->execute($args);

        // The same úkon at several prevádzky is one row — the latest of them
        // (the query is walked oldest first). Keyed by the printed label, not the
        // type: a legacy `poziarna_kniha` and a `preventivna_prehliadka` read the
        // same and must not print twice.
        // With `$earliestDue` the row kept is the one due soonest instead (the
        // „Termíny ďalších kontrol" offer: an overdue prevádzka must not hide
        // behind a newer úkon at another one).
        $byType = [];
        foreach ($stmt->fetchAll() as $r) {
            if (BookMapping::forInspectionType((string) $r['type']) !== $book) {
                continue;
            }
            $label = self::inspectionLabel((string) $r['type']);
            $r['due'] = self::dueOf($r, $overrides);
            if ($earliestDue && isset($byType[$label])) {
                $dueNew = $r['due'];
                $dueOld = $byType[$label]['due'];
                if ($dueNew === null || ($dueOld !== null && $dueNew >= $dueOld)) {
                    continue;
                }
            }
            unset($byType[$label]);
            $byType[$label] = $r;
        }
        foreach ($byType as $r) {
            $rows[] = [
                // `key` is the one step 3 of the prehliadka overrides by
                // (PrehliadkaTerms): routine:<type>, training:skolenie_bozp, …
                'key'      => self::termKey((string) $r['type']),
                'type'     => (string) $r['type'],
                'label'    => self::inspectionLabel((string) $r['type']),
                'done'     => (string) $r['executed_on'],
                'valid'    => $r['due'],
                'external' => false,
            ];
        }

        // Every training of the trainings table is PO ({@see BookMapping::forTraining()}).
        if ($withTrainings && $book === BookMapping::PO) {
            // Latest finalized training per term chain — for this prevádzka or
            // for the whole company (facility left empty). Walked oldest first,
            // so the last one seen for a chain is its latest; Vstupné and
            // Opakované share a chain and print one row, named after the later.
            $trainingFacilitySql = $facilityId !== null ? ' AND (t.facility_id = :fac OR t.facility_id IS NULL)' : '';
            $tStmt = Db::pdo()->prepare(
                'SELECT t.type, t.date, t.periodicity_value, t.periodicity_unit, t.valid_until_override
                 FROM   trainings t
                 WHERE  t.account_id = :acct AND t.company_id = :comp' . $trainingFacilitySql . '
                   AND  t.status = "finalized" AND t.archived_at IS NULL
                   AND  t.date IS NOT NULL AND t.date <= :asof
                 ORDER  BY t.date ASC, t.id ASC'
            );
            $tArgs = ['acct' => $accountId, 'comp' => $companyId, 'asof' => $asOf];
            if ($facilityId !== null) {
                $tArgs['fac'] = $facilityId;
            }
            $tStmt->execute($tArgs);
            $latest = [];
            foreach ($tStmt->fetchAll() as $r) {
                unset($latest[Periodicity::trainingChain((string) $r['type'])]);
                $latest[Periodicity::trainingChain((string) $r['type'])] = $r;
            }
            foreach ($latest as $r) {
                $type = (string) $r['type'];
                $value = $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null;
                $unit = $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null;
                $rows[] = [
                    'key'      => 'training:' . Periodicity::trainingChain($type),
                    'type'     => '',
                    'label'    => self::TRAINING_LABELS[$type] ?? $type,
                    'done'     => (string) $r['date'],
                    // The validity written by hand (spec 8.1) wins over date + periodicity.
                    'valid'    => $r['valid_until_override'] ?? Periodicity::validUntil((string) $r['date'], $value, $unit),
                    'external' => false,
                ];
            }
        }

        return $rows;
    }

    /**
     * When an úkon is next due: its date plus its own periodicity, or the date
     * the technician wrote over it in a prehliadka that is not older than the
     * úkon (`$overrides` from {@see prehliadkaOverrides()}, empty = ignore them).
     *
     * @param array<string, mixed> $inspection a row with type, facility_id, executed_on and the periodicity columns
     * @param array<int, array<string, array{on: string, valid: string}>> $overrides
     */
    private static function dueOf(array $inspection, array $overrides = []): ?string
    {
        $type = (string) $inspection['type'];
        // The prehliadka is its own term chain; its override key is `prehliadka_po`.
        $key = Periodicity::inspectionChain($type) === 'preventivna_prehliadka' ? 'prehliadka_po' : self::termKey($type);
        $override = $overrides[(int) ($inspection['facility_id'] ?? 0)][$key] ?? null;
        // An úkon done after the prehliadka replaces its override with its own term.
        // On the very day of the prehliadka the order they were written in decides:
        // a routine control finalized after it (the concept the prehliadka created)
        // keeps its own periodicity. The prehliadka's own term is governed by its
        // own override, so that chain never yields to it.
        if ($override !== null) {
            $executedOn = (string) $inspection['executed_on'];
            if ($key === 'prehliadka_po') {
                $overrideHolds = $override['on'] >= $executedOn;
            } else {
                $overrideHolds = $override['on'] > $executedOn
                    || ($override['on'] === $executedOn && (int) $override['id'] > (int) ($inspection['id'] ?? 0));
            }
            if ($overrideHolds) {
                return $override['valid'];
            }
        }
        $value = $inspection['periodicity_value'] !== null ? (int) $inspection['periodicity_value'] : null;
        $unit = $inspection['periodicity_unit'] !== null ? (string) $inspection['periodicity_unit'] : null;
        return Periodicity::validUntil((string) $inspection['executed_on'], $value, $unit);
    }

    /**
     * The hand-written terms of the closed prehliadky (`details.term_overrides`,
     * spec 6.6) that are still the last word, per prevádzka and term key.
     *
     * Prehliadky are walked oldest first and each decides, for every term it
     * governs — its own `prehliadka_po` and each riadna kontrola it ticked —
     * whether the term is overridden (set) or automatic (cleared), so a later
     * prehliadka that ticks the control again without an override takes the
     * earlier override back. Only the PO record counts: a BOZP-only prehliadka
     * governs nothing of the požiarna kniha. Nothing is written anywhere — the
     * override never reaches the úkon's periodicity, so the next inspection
     * starts from the plain interval again.
     *
     * @return array<int, array<string, array{on: string, valid: string}>> facility id → key → override
     */
    private static function prehliadkaOverrides(int $accountId, int $companyId, ?int $facilityId, string $asOf): array
    {
        $facilitySql = $facilityId !== null ? ' AND facility_id = :fac' : '';
        $stmt = Db::pdo()->prepare(
            'SELECT id, facility_id, executed_on, details
             FROM   inspections
             WHERE  account_id = :acct AND company_id = :comp' . $facilitySql . '
               AND  type = "preventivna_prehliadka"
               AND  status = "finalized" AND archived_at IS NULL
               AND  executed_on IS NOT NULL AND executed_on <= :asof
             ORDER  BY executed_on ASC, id ASC'
        );
        $args = ['acct' => $accountId, 'comp' => $companyId, 'asof' => $asOf];
        if ($facilityId !== null) {
            $args['fac'] = $facilityId;
        }
        $stmt->execute($args);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $details = InspectionDetails::decode($r['details']) ?? [];
            if (($details['scope']['po'] ?? false) !== true) {
                continue;
            }
            $fac = (int) $r['facility_id'];
            $written = is_array($details['term_overrides'] ?? null) ? $details['term_overrides'] : [];
            $governed = ['prehliadka_po'];
            foreach ((array) ($details['routine'] ?? []) as $type) {
                if (is_string($type)) {
                    $governed[] = 'routine:' . $type;
                }
            }
            foreach ($governed as $key) {
                $date = $written[$key] ?? null;
                if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                    $out[$fac][$key] = ['on' => (string) $r['executed_on'], 'id' => (int) $r['id'], 'valid' => $date];
                } else {
                    unset($out[$fac][$key]);
                }
            }
        }
        return $out;
    }

    /**
     * One printed row. Own úkony print month and year, external terms the full
     * date (sample PDF). With no validity: „podľa potreby" for an úkon without a
     * periodicity, „nedoložené" for an external term the client has no date for.
     *
     * @param array{label: string, done: ?string, valid: ?string, external: bool} $r
     * @return array{label: string, done: string, valid_until: string, state: string|null, state_tone: string|null}
     */
    private static function row(array $r, string $asOf): array
    {
        [$state, $tone] = self::state($r['valid'], $asOf);
        $format = static fn (string $iso): string => $r['external'] ? ExternalTerms::skDate($iso) : self::monthYear($iso);
        return [
            'label'       => $r['label'],
            'done'        => $r['done'] !== null ? $format($r['done']) : '—',
            'valid_until' => $r['valid'] !== null ? $format($r['valid']) : ($r['external'] ? 'nedoložené' : 'podľa potreby'),
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

    /**
     * Key of an úkon's row — `routine:<type>` for the seven riadne kontroly,
     * `training:skolenie_bozp` for the oboznámenie BOZP (a training of the BOZP
     * book), otherwise `type:<type>`. Matches routineTermKey() / the training
     * keys of lib/prehliadka.ts, so a term override finds its row.
     */
    private static function termKey(string $type): string
    {
        if ($type === 'skolenie_bozp') {
            return 'training:skolenie_bozp';
        }
        return in_array($type, FacilityDevices::TYPES, true) ? 'routine:' . $type : 'type:' . $type;
    }
}
