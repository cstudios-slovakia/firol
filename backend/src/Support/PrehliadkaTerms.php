<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * The facts step 3 of a preventívna prehliadka (Termíny a činnosti) needs to
 * fill in „Doplnené samy" (spec 6.6, plan phase 7b):
 *
 *   trainings              the firm's trainings on record — the PO ones from the
 *                          trainings table (latest per term chain) and the
 *                          oboznámenie BOZP — each with the date it is valid
 *                          until, trainings with a hand-written validity
 *                          (spec 8.1) already resolved
 *   routine_periodicities  per riadna kontrola, the periodicity its úkon was
 *                          last done with at this prevádzka, else the first
 *                          recommended one; null = „bez opakovania"
 *
 * The next prehliadka and each ticked riadna kontrola are computed by the
 * client from the prehliadka's own date and these periodicities; what the
 * technician overrides lives in `details.term_overrides`.
 */
final class PrehliadkaTerms
{
    /**
     * @return array{
     *   trainings: list<array{key: string, label: string, book: string, done_on: string, valid_until: ?string, overridden: bool}>,
     *   routine_periodicities: array<string, array{value: ?int, unit: ?string}>
     * }
     */
    public static function forFacility(int $accountId, int $facilityId, string $asOf): array
    {
        $pdo = Db::pdo();
        $facility = $pdo->prepare('SELECT company_id FROM facilities WHERE id = ? AND account_id = ?');
        $facility->execute([$facilityId, $accountId]);
        $companyId = $facility->fetchColumn();
        if ($companyId === false) {
            return ['trainings' => [], 'routine_periodicities' => []];
        }
        $companyId = (int) $companyId;

        // PO trainings: latest finalized per term chain, for this prevádzka or the
        // whole firm — the same selection ClientTerms prints.
        $tStmt = $pdo->prepare(
            'SELECT t.type, t.date, t.periodicity_value, t.periodicity_unit, t.valid_until_override
             FROM   trainings t
             WHERE  t.account_id = ? AND t.company_id = ?
               AND  (t.facility_id = ? OR t.facility_id IS NULL)
               AND  t.status = "finalized" AND t.archived_at IS NULL
               AND  t.date IS NOT NULL AND t.date <= ?
             ORDER  BY t.date ASC, t.id ASC'
        );
        $tStmt->execute([$accountId, $companyId, $facilityId, $asOf]);
        $latest = [];
        foreach ($tStmt->fetchAll() as $r) {
            $chain = Periodicity::trainingChain((string) $r['type']);
            unset($latest[$chain]);
            $latest[$chain] = $r;
        }
        $trainings = [];
        foreach ($latest as $chain => $r) {
            $value = $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null;
            $unit = $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null;
            $override = $r['valid_until_override'] !== null ? (string) $r['valid_until_override'] : null;
            $trainings[] = [
                'key'         => 'training:' . $chain,
                'label'       => ClientTerms::TRAINING_LABELS[(string) $r['type']] ?? (string) $r['type'],
                'book'        => BookMapping::PO,
                'done_on'     => (string) $r['date'],
                'valid_until' => $override ?? Periodicity::validUntil((string) $r['date'], $value, $unit),
                'overridden'  => $override !== null,
            ];
        }

        // Oboznámenie BOZP is an úkon of the BOZP section, not a row of `trainings`.
        $bozp = $pdo->prepare(
            'SELECT executed_on, periodicity_value, periodicity_unit
             FROM   inspections
             WHERE  account_id = ? AND facility_id = ? AND type = "skolenie_bozp"
               AND  status = "finalized" AND archived_at IS NULL
               AND  executed_on IS NOT NULL AND executed_on <= ?
             ORDER  BY executed_on DESC, id DESC LIMIT 1'
        );
        $bozp->execute([$accountId, $facilityId, $asOf]);
        $b = $bozp->fetch();
        if ($b) {
            $trainings[] = [
                'key'         => 'training:skolenie_bozp',
                'label'       => ClientTerms::LABELS['skolenie_bozp'],
                'book'        => BookMapping::BOZP,
                'done_on'     => (string) $b['executed_on'],
                'valid_until' => Periodicity::validUntil(
                    (string) $b['executed_on'],
                    $b['periodicity_value'] !== null ? (int) $b['periodicity_value'] : null,
                    $b['periodicity_unit'] !== null ? (string) $b['periodicity_unit'] : null,
                ),
                'overridden'  => false,
            ];
        }

        return [
            'trainings'             => $trainings,
            'routine_periodicities' => self::routinePeriodicities($accountId, $facilityId),
        ];
    }

    /**
     * @return array<string, array{value: ?int, unit: ?string}>
     */
    private static function routinePeriodicities(int $accountId, int $facilityId): array
    {
        $out = [];
        $stmt = Db::pdo()->prepare(
            'SELECT periodicity_value, periodicity_unit FROM inspections
             WHERE  account_id = ? AND facility_id = ? AND type = ?
               AND  status = "finalized" AND archived_at IS NULL
             ORDER  BY COALESCE(executed_on, "1000-01-01") DESC, id DESC LIMIT 1'
        );
        foreach (FacilityDevices::TYPES as $type) {
            $stmt->execute([$accountId, $facilityId, $type]);
            $row = $stmt->fetch();
            if ($row) {
                // The last úkon's own choice wins — also „bez opakovania".
                $out[$type] = [
                    'value' => $row['periodicity_value'] !== null ? (int) $row['periodicity_value'] : null,
                    'unit'  => $row['periodicity_unit'] !== null ? (string) $row['periodicity_unit'] : null,
                ];
                continue;
            }
            $months = Periodicity::RECOMMENDED_MONTHS[$type][0] ?? null;
            $out[$type] = $months !== null
                ? ['value' => $months, 'unit' => 'mesiac']
                : ['value' => null, 'unit' => null];
        }
        return $out;
    }
}
