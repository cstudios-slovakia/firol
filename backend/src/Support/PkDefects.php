<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Derives the numbered "Zistené nedostatky" rows of a Požiarna kniha record
 * from the item payload.
 *
 * Shared by the PDF body template (which prints the table) and by the photo
 * appendix builder (which captions each photo with the number of the
 * nedostatok it documents) so both always agree on the numbering.
 */
final class PkDefects
{
    /**
     * New shape: `defects` is a list of {description, deadline?, key?}. Legacy
     * records carry a single `defect_deadline` plus free-text `notes`; those
     * are split line-by-line and reuse the single deadline so old records still
     * render predictably. `notes_used` reports that the free-text note was
     * consumed as defect rows, so the caller doesn't print it twice.
     *
     * @param array<string, mixed> $record Item payload (inspection_items.fields).
     * @return array{
     *     rows: list<array{description: string, deadline: ?string, key: ?string}>,
     *     notes_used: bool,
     * }
     */
    public static function rows(array $record): array
    {
        if (isset($record['defects']) && is_array($record['defects']) && $record['defects']) {
            $rows = [];
            foreach ($record['defects'] as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $desc = isset($d['description']) && is_string($d['description'])
                    ? trim($d['description'])
                    : '';
                if ($desc === '') {
                    continue;
                }
                $rows[] = [
                    'description' => $desc,
                    'deadline'    => isset($d['deadline']) && is_string($d['deadline']) ? $d['deadline'] : null,
                    'key'         => isset($d['key']) && is_string($d['key']) ? $d['key'] : null,
                ];
            }
            return ['rows' => $rows, 'notes_used' => false];
        }

        if (!self::hasDefects($record)) {
            return ['rows' => [], 'notes_used' => false];
        }

        $notes = isset($record['notes']) && is_string($record['notes']) ? $record['notes'] : '';
        $deadline = isset($record['defect_deadline']) && is_string($record['defect_deadline'])
            ? $record['defect_deadline']
            : null;

        $rows = [];
        foreach (array_filter(array_map('trim', explode("\n", $notes))) as $line) {
            $rows[] = ['description' => $line, 'deadline' => $deadline, 'key' => null];
        }
        if (!$rows && $notes !== '') {
            $rows[] = ['description' => $notes, 'deadline' => $deadline, 'key' => null];
        }

        return ['rows' => $rows, 'notes_used' => $rows !== []];
    }

    /**
     * True when the record's result is "zistené nedostatky".
     *
     * @param array<string, mixed> $record
     */
    public static function hasDefects(array $record): bool
    {
        return (string) ($record['result'] ?? '') === 'zistene_nedostatky';
    }
}
