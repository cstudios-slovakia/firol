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
     *   last: ?array{inspection_id: int, executed_on: ?string, periodicity_value: ?int, periodicity_unit: ?string, scope: array{po: bool, bozp: bool}, routine: list<string>, book_entry: bool},
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
            $lastSetup = [
                'inspection_id'     => (int) $lastRow['id'],
                'executed_on'       => $lastRow['executed_on'] !== null ? (string) $lastRow['executed_on'] : null,
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
            ];
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
            'draft_id'    => $draftId !== false ? (int) $draftId : null,
            'last'        => $lastSetup,
            // An empty map must reach the client as {}, not [].
            'open_drafts' => $openDrafts === [] ? new \stdClass() : $openDrafts,
        ];
    }
}
