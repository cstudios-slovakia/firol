<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * The shipped číselník nedostatkov (spec 3.4): the 27 items of FIROL's
 * `6_Ciselnik_nedostatkov_strojovo.md`, kept verbatim in
 * `data/defect_catalog.json` and mapped here onto the `defect_catalog` columns.
 * Used by migration 059 only; texts are never edited in code.
 */
final class DefectCatalogSeed
{
    /** JSON `tyka_sa` → `defect_catalog.scope`. */
    private const SCOPES = [
        'ochrana pred požiarmi'         => 'po',
        'BOZP'                          => 'bo',
        'ochrana pred požiarmi aj BOZP' => 'ob',
    ];

    /**
     * @return list<array{
     *   id: int, title: string, scope: string, area: string, default_deadline: string,
     *   description: string, regulation: string, justification: string, measure: string
     * }>
     */
    public static function rows(): array
    {
        $raw = file_get_contents(__DIR__ . '/data/defect_catalog.json');
        $json = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            throw new \RuntimeException('defect_catalog.json is missing or not valid JSON.');
        }

        $rows = [];
        foreach ($json as $item) {
            $scope = self::SCOPES[$item['tyka_sa'] ?? ''] ?? null;
            if ($scope === null) {
                throw new \RuntimeException('Unknown tyka_sa in defect_catalog.json: ' . json_encode($item['tyka_sa'] ?? null));
            }
            $rows[] = [
                'id'               => (int) $item['id'],
                'title'            => (string) $item['nazov'],
                'scope'            => $scope,
                'area'             => (string) $item['oblast'],
                'default_deadline' => (string) $item['lehota'],
                'description'      => (string) $item['popis'],
                'regulation'       => (string) $item['predpis'],
                'justification'    => (string) $item['odovodnenie'],
                'measure'          => (string) $item['opatrenie'],
            ];
        }
        return $rows;
    }
}
