<?php

declare(strict_types=1);

namespace Firol\Audit;

use Firol\Db;
use Firol\Support\Periodicity;

/**
 * Chapter 16 — the audit items a separate úkon already answers.
 *
 * Part of an audit overlaps with work the technician does under its own
 * protocol. If they checked the OOPP this morning, asking them to answer
 * „Stav OOPP — nepoškodené, funkčné" again in the afternoon is asking them to
 * certify the same fact twice.
 *
 * What decides whether the answer may be carried over is the úkon's VALIDITY,
 * not its age. That is the whole point: a monthly OOPP check from three weeks
 * ago still holds, a twelve-monthly regál check from eleven months ago still
 * holds, and neither rule would work for the other one. So the comparison is
 * always `platnost_do` against the date of the audit.
 *
 * Four outcomes, and only the first one writes anything by itself:
 *
 *   same_day   the úkon is from the audit's own day or its own visit — the
 *              item is answered `vyhovuje` and the protocol number goes in the
 *              note.
 *   offer      still valid, but from an earlier day — the app OFFERS it and
 *              the technician accepts or declines. It must not answer for
 *              them: they are the one signing the audit, and a signature under
 *              something they did not look at is the one thing this feature
 *              must not manufacture.
 *   expired    the úkon exists but its validity has run out — nothing is
 *              filled in, and the finding is the technician's to record.
 *   never      no such úkon at all. Same treatment.
 *   no_period  the úkon does not recur, so it has no validity to compare
 *              against. Only the date of the last one is shown.
 */
final class LinkedWork
{
    /**
     * Resolve every linked type an audit's items point at.
     *
     * Returned per linked type, not per item, because several items can point
     * at the same úkon and the answer for all of them is the same one.
     *
     * @return array<string, array<string, mixed>>  linked_type => state
     */
    public static function resolve(
        int $accountId,
        int $facilityId,
        int $companyId,
        ?string $auditDate,
        ?int $visitId,
        array $linkedTypes,
    ): array {
        $out = [];
        foreach (array_unique($linkedTypes) as $type) {
            if (!is_string($type) || $type === '') {
                continue;
            }
            $out[$type] = $type === 'skolenie_po'
                ? self::resolveTraining($accountId, $facilityId, $companyId, $type)
                : self::resolveInspection($accountId, $facilityId, $auditDate, $visitId, $type);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function resolveInspection(
        int $accountId,
        int $facilityId,
        ?string $auditDate,
        ?int $visitId,
        string $type,
    ): array {
        $stmt = Db::pdo()->prepare(
            'SELECT i.id, i.executed_on, i.visit_id,
                    i.periodicity_value, i.periodicity_unit,
                    d.number AS document_number
             FROM   inspections i
             LEFT   JOIN documents d
                    ON  d.parent_type = "inspection" AND d.parent_id = i.id
                    AND d.id = (SELECT MAX(d2.id) FROM documents d2
                                WHERE d2.parent_type = "inspection" AND d2.parent_id = i.id)
             WHERE  i.account_id  = ?
               AND  i.facility_id = ?
               AND  i.type        = ?
               AND  i.status      = "finalized"
               AND  i.archived_at IS NULL
             ORDER  BY i.executed_on DESC, i.id DESC
             LIMIT  1'
        );
        $stmt->execute([$accountId, $facilityId, $type]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::shape($type, null, 'never');
        }

        $value = $row['periodicity_value'] !== null ? (int) $row['periodicity_value'] : null;
        $unit  = $row['periodicity_unit'] !== null ? (string) $row['periodicity_unit'] : null;
        $validUntil = Periodicity::validUntil(
            $row['executed_on'] !== null ? (string) $row['executed_on'] : null,
            $value,
            $unit,
        );

        $found = [
            'inspection_id'     => (int) $row['id'],
            'executed_on'       => $row['executed_on'],
            'document_number'   => $row['document_number'],
            'periodicity_label' => Periodicity::label($value, $unit),
            'valid_until'       => $validUntil,
        ];

        $sameVisit = $visitId !== null && $row['visit_id'] !== null && (int) $row['visit_id'] === $visitId;
        $sameDay   = $auditDate !== null && $row['executed_on'] !== null
            && (string) $row['executed_on'] === $auditDate;

        if ($sameVisit || $sameDay) {
            return self::shape($type, $found, 'same_day');
        }
        if ($validUntil === null) {
            return self::shape($type, $found, 'no_period');
        }
        if ($auditDate !== null && $validUntil >= $auditDate) {
            return self::shape($type, $found, 'offer');
        }
        return self::shape($type, $found, 'expired');
    }

    /**
     * Trainings live in their own table and carry no periodicity of their own,
     * so there is no `platnost_do` to compare against. That lands them in
     * `no_period` by the rule above: the date of the last one is shown and
     * nothing is filled in.
     *
     * @return array<string, mixed>
     */
    private static function resolveTraining(
        int $accountId,
        int $facilityId,
        int $companyId,
        string $type,
    ): array {
        $stmt = Db::pdo()->prepare(
            'SELECT t.id, t.date AS executed_on,
                    d.number AS document_number
             FROM   trainings t
             LEFT   JOIN documents d
                    ON  d.parent_type = "training" AND d.parent_id = t.id
                    AND d.id = (SELECT MAX(d2.id) FROM documents d2
                                WHERE d2.parent_type = "training" AND d2.parent_id = t.id)
             WHERE  t.account_id = ?
               AND  (t.facility_id = ? OR (t.facility_id IS NULL AND t.company_id = ?))
               AND  t.type   IN ("vstupne", "opakovane")
               AND  t.status = "finalized"
               AND  t.archived_at IS NULL
             ORDER  BY t.date DESC, t.id DESC
             LIMIT  1'
        );
        $stmt->execute([$accountId, $facilityId, $companyId]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::shape($type, null, 'never');
        }
        return self::shape($type, [
            'inspection_id'     => null,
            'training_id'       => (int) $row['id'],
            'executed_on'       => $row['executed_on'],
            'document_number'   => $row['document_number'],
            'periodicity_label' => null,
            'valid_until'       => null,
        ], 'no_period');
    }

    /**
     * Note printed on the protocol and shown on screen when an item's answer
     * came from a separate protocol.
     *
     * @param array<string, mixed> $takenFrom
     */
    public static function takenFromNote(array $takenFrom): string
    {
        $number = (string) ($takenFrom['document_number'] ?? '');
        $date   = (string) ($takenFrom['executed_on'] ?? '');
        $ts     = $date !== '' ? strtotime($date) : false;
        $shown  = $ts ? date('j. n. Y', $ts) : $date;

        if ($number === '') {
            return 'Vykonaná samostatná kontrola z ' . $shown . '.';
        }
        return 'Vykonaná samostatná kontrola — protokol ' . $number . ' z ' . $shown . '.';
    }

    /**
     * @param array<string, mixed>|null $found
     * @return array<string, mixed>
     */
    private static function shape(string $type, ?array $found, string $state): array
    {
        return [
            'type'  => $type,
            'label' => AuditCatalog::LINKED_TYPE_LABELS[$type] ?? $type,
            'state' => $state,
            'found' => $found,
        ];
    }
}
