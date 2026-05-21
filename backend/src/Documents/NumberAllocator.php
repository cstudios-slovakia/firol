<?php

declare(strict_types=1);

namespace Firol\Documents;

use Firol\Db;
use PDO;

/**
 * Allocates the next sequential document number for an
 * (account, inspection-type, year) triple. Uses the document_sequences
 * table with a row-level FOR UPDATE lock so concurrent PDF generations
 * never collide. Number format is `<PREFIX>-<YEAR>-<SEQ>` with seq
 * left-padded to 3 digits (PHP-2026-001).
 *
 * Caller MUST be inside a transaction (or the lock is released
 * immediately on UPDATE commit boundary). The InspectionController
 * starts a transaction around generate-pdf and calls this from there.
 */
final class NumberAllocator
{
    /** type slug → PDF number prefix. Locked, see roadmap. */
    public const TYPE_PREFIXES = [
        'php'                => 'PHP',
        'hydranty'           => 'HYD',
        'oprava_ts_php'      => 'OPR-PHP',
        'poziarna_kniha'     => 'PK',
        'pu_akcieschopnost'  => 'PU-AK',
        'pu_udrzba'          => 'PU-UD',
        'nudzove_osvetlenie' => 'NO',
        'ts_hadic'           => 'TS-HAD',
        // Trainings: per spec, all 6 training types share the SKO prefix
        // and a single per-account+year sequence. The training type
        // itself is stored in the body, not encoded in the number.
        'skolenie'           => 'SKO',
    ];

    /**
     * Reserve the next sequence and return the formatted document number.
     * The accompanying integer SEQ is also returned so callers can store
     * it separately if needed (we don't, currently).
     *
     * @return array{number: string, seq: int}
     */
    public static function allocate(int $accountId, string $type, int $year): array
    {
        if (!isset(self::TYPE_PREFIXES[$type])) {
            throw new \InvalidArgumentException("Unknown inspection type: $type");
        }

        $pdo = Db::pdo();
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) {
            $pdo->beginTransaction();
        }

        try {
            // Make sure the row exists; INSERT IGNORE leaves an existing
            // counter intact and creates a 0-row when the year just rolled
            // over.
            $pdo->prepare(
                'INSERT IGNORE INTO document_sequences
                    (account_id, type, year, last_seq)
                 VALUES (?, ?, ?, 0)'
            )->execute([$accountId, $type, $year]);

            // Lock and read.
            $sel = $pdo->prepare(
                'SELECT last_seq FROM document_sequences
                 WHERE account_id = ? AND type = ? AND year = ? FOR UPDATE'
            );
            $sel->execute([$accountId, $type, $year]);
            $current = (int) $sel->fetchColumn();
            $next = $current + 1;

            $pdo->prepare(
                'UPDATE document_sequences SET last_seq = ?
                 WHERE account_id = ? AND type = ? AND year = ?'
            )->execute([$next, $accountId, $type, $year]);

            if ($startedHere) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $prefix = self::TYPE_PREFIXES[$type];
        $number = sprintf('%s-%04d-%03d', $prefix, $year, $next);
        return ['number' => $number, 'seq' => $next];
    }
}
