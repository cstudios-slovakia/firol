<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * What step 1 of a preventívna prehliadka needs to know about its prevádzka
 * besides the device list (spec 6.1, plan Phase 7a):
 *
 *   draft_id      a prehliadka already in progress here — the start button then
 *                 reads „Prehliadka prebieha · pokračovať"
 *   last          the setup of the latest finished prehliadka („Ako naposledy"),
 *                 derived from its `details`; null on the first visit, when
 *                 nothing is preselected
 *   open_drafts   per riadna kontrola, the unfinished draft of that type at this
 *                 prevádzka. Such a type cannot be ticked: the control the
 *                 walk-through prepares would be a second draft of the same
 *                 úkon.
 */
final class PrehliadkaContext
{
    /**
     * @return array{
     *   draft_id: ?int,
     *   last: ?array{inspection_id: int, executed_on: ?string, defects: int, periodicity_value: ?int, periodicity_unit: ?string, scope: array{po: bool, bozp: bool}, routine: list<string>, book_entry: bool},
     *   open_drafts: array<string, int>|stdClass
     * }
     */
    public static function forFacility(int $accountId, int $facilityId): array
    {
        $pdo = Db::pdo();

        $draft = $pdo->prepare(
            'SELECT id FROM inspections
             WHERE  account_id = ? AND facility_id = ? AND type = ?
               AND  status = "draft" AND archived_at IS NULL
             ORDER  BY id DESC LIMIT 1'
        );
        $draft->execute([$accountId, $facilityId, PrehliadkaDetails::TYPE]);
        $draftId = $draft->fetchColumn();

        $last = $pdo->prepare(
            'SELECT id, executed_on, periodicity_value, periodicity_unit, details
             FROM   inspections
             WHERE  account_id = ? AND facility_id = ? AND type = ?
               AND  status = "finalized" AND archived_at IS NULL
             ORDER  BY COALESCE(executed_on, "1000-01-01") DESC, id DESC LIMIT 1'
        );
        $last->execute([$accountId, $facilityId, PrehliadkaDetails::TYPE]);
        $lastRow = $last->fetch();

        $lastSetup = null;
        if ($lastRow) {
            $details = InspectionDetails::decode($lastRow['details']) ?? [];
            $scope = (array) ($details['scope'] ?? []);
            $count = $pdo->prepare('SELECT COUNT(*) FROM inspection_items WHERE inspection_id = ?');
            $count->execute([(int) $lastRow['id']]);
            $lastSetup = [
                'inspection_id'     => (int) $lastRow['id'],
                'executed_on'       => $lastRow['executed_on'] !== null ? (string) $lastRow['executed_on'] : null,
                // „Posledná preventívna prehliadka · N nedostatkov" on the Objekt tab (plan 8b).
                'defects'           => (int) $count->fetchColumn(),
                'periodicity_value' => $lastRow['periodicity_value'] !== null ? (int) $lastRow['periodicity_value'] : null,
                'periodicity_unit'  => $lastRow['periodicity_unit'] !== null ? (string) $lastRow['periodicity_unit'] : null,
                'scope'             => [
                    'po'   => ($scope['po'] ?? false) === true,
                    'bozp' => ($scope['bozp'] ?? false) === true,
                ],
                'routine'           => array_values(array_filter(
                    (array) ($details['routine'] ?? []),
                    static fn ($t): bool => is_string($t) && in_array($t, FacilityDevices::TYPES, true),
                )),
                'book_entry'        => ($details['book_entry'] ?? false) === true,
                'lehota_zakon'      => isset($details['lehota_zakon']) ? (int) $details['lehota_zakon'] : null,
                'lehota_vlastna'    => isset($details['lehota_vlastna']) && $details['lehota_vlastna'] !== null ? (int) $details['lehota_vlastna'] : null,
            ];
        }

        $facStmt = $pdo->prepare(
            'SELECT f.vchody, f.entrances, c.client_type
             FROM facilities f
             JOIN companies c ON c.id = f.company_id
             WHERE f.id = ? AND f.account_id = ?'
        );
        $facStmt->execute([$facilityId, $accountId]);
        $facRow = $facStmt->fetch();
        $isBytovyDom = ($facRow['client_type'] ?? '') === 'bytove_druzstvo';
        $entrances = [];
        if ($isBytovyDom) {
            $custom = is_string($facRow['entrances'] ?? null)
                ? (json_decode($facRow['entrances'], true) ?: [])
                : [];
            $entrances = self::generateEntrances($facRow['vchody'] ?? null, is_array($custom) ? $custom : []);
        }

        $placeholders = implode(',', array_fill(0, count(FacilityDevices::TYPES), '?'));
        $open = $pdo->prepare(
            "SELECT type, MAX(id) AS id FROM inspections
             WHERE  account_id = ? AND facility_id = ? AND status = 'draft' AND archived_at IS NULL
               AND  type IN ($placeholders)
             GROUP  BY type"
        );
        $open->execute([$accountId, $facilityId, ...FacilityDevices::TYPES]);
        $openDrafts = [];
        foreach ($open->fetchAll() as $row) {
            $openDrafts[(string) $row['type']] = (int) $row['id'];
        }

        return [
            'draft_id'            => $draftId !== false ? (int) $draftId : null,
            'last'                => $lastSetup,
            'open_drafts'         => $openDrafts === [] ? new \stdClass() : $openDrafts,
            'is_bytovy_dom'       => $isBytovyDom,
            'vchody'              => $isBytovyDom ? ($facRow['vchody'] ?? null) : null,
            'generated_entrances' => $entrances,
        ];
    }

    /**
     * Generate list of entrance labels for a bytový dom per spec/prototype `vchodyPre`.
     *
     * @param list<string> $customEntrances
     * @return list<string>
     */
    public static function generateEntrances(?string $vchody, array $customEntrances = []): array
    {
        $out = [];
        $vchody = trim($vchody ?? '');
        if ($vchody !== '') {
            if (ctype_digit($vchody)) {
                $n = max(1, min(40, (int) $vchody));
                for ($i = 1; $i <= $n; $i++) {
                    $out[] = 'Vchod ' . $i;
                }
            } else {
                $parts = preg_split('/[,;]+/', $vchody) ?: [];
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p === '') {
                        continue;
                    }
                    if (preg_match('/^\d+[a-zA-Z]?$/', $p)) {
                        $out[] = 'Vchod ' . $p;
                    } else {
                        $out[] = $p;
                    }
                }
            }
        }
        foreach ($customEntrances as $c) {
            $c = is_string($c) ? trim($c) : '';
            if ($c !== '' && $c !== 'Celý objekt' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }
}
