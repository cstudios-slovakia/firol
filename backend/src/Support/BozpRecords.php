<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * The single-record BOZP úkony of block 2 — chapters 5.3 and 7:
 *
 *   kniha_bozp          Kniha kontrol BOZP                     BOZP-RRRR-NNN
 *   pracovisko          Kontrola pracoviska                    PRAC-RRRR-NNN
 *   osamele_pracovisko  Kontrola osamelých pracovísk           OSP-RRRR-NNN
 *   fajcenie            Kontrola dodržiavania zákazu fajčenia  ZF-RRRR-NNN
 *
 * Each is ONE record, like the požiarna kniha: one `inspection_items` row
 * whose `fields` hold everything the úkon records — the workplaces walked,
 * the activities ticked, the areas or rows evaluated, the result and the
 * nedostatky (Firol\Support\Defects). One record keeps the nedostatky's
 * photos on an item (they need one), the carry-over on the item machinery
 * (Firol\Support\CarryOver) and the offline save on the item outbox.
 *
 * Field sets are exactly chapter 7's; `*` there is required here, and the
 * same check runs again before the protocol is issued (a record carried over
 * from last year starts with its results blank). Messages are Slovak because
 * they reach the technician verbatim.
 */
final class BozpRecords
{
    /** @var list<string> */
    public const TYPES = ['kniha_bozp', 'pracovisko', 'osamele_pracovisko', 'fajcenie'];

    /** ciselniky.json `vysledok_ukonu` — the kniha BOZP result. */
    public const KNIHA_RESULTS = [
        'bez_nedostatkov'    => 'Bez nedostatkov',
        'zistene_nedostatky' => 'Zistené nedostatky',
    ];

    /**
     * ciselniky.json `hodnotenie_auditu` — the „celkové hodnotenie" the
     * mockup prints on the pracovisko and osamelé pracoviská protocols
     * („Vyhovujúci s výhradami").
     */
    public const OVERALL = [
        'vyhovujuci'             => 'Vyhovujúci',
        'vyhovujuci_s_vyhradami' => 'Vyhovujúci s výhradami',
        'nevyhovujuci'           => 'Nevyhovujúci',
    ];

    /** Result of one area / row (ciselniky.json `stavy_poloziek.uzaver`). */
    public const PASS_FAIL = [
        'vyhovuje'   => 'Vyhovuje',
        'nevyhovuje' => 'Nevyhovuje',
    ];

    /**
     * The eight areas of chapter 7 („čistota a poriadok · komunikácie a
     * únikové cesty · …"), worded as the binding PRAC mockup prints them.
     */
    public const PRACOVISKO_AREAS = [
        'Čistota a poriadok na pracovisku',
        'Voľné a bezpečné komunikácie a únikové cesty',
        'Stav podláh, schodísk, rámp a zábradlí',
        'Osvetlenie — denné aj umelé',
        'Vetranie, teplota a mikroklíma',
        'Sociálne zariadenia, šatne, pitný režim',
        'Skladovanie a manipulácia s materiálom',
        'Bezpečnostné a zdravotné označenie',
    ];

    private const MAX_LIST = 100;
    private const MAX_LINE = 200;
    private const MAX_NOTE = 500;
    private const MAX_TEXT = 2000;

    public static function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * The 11 predefined activities of the kniha BOZP, verbatim from
     * `05_DATA/checklist_kniha_bozp.json` (copied to data/).
     *
     * @return list<string>
     */
    public static function knihaActivities(): array
    {
        static $list = null;
        if ($list === null) {
            $raw = file_get_contents(__DIR__ . '/data/checklist_kniha_bozp.json');
            $json = $raw !== false ? json_decode($raw, true) : null;
            $list = is_array($json['cinnosti'] ?? null)
                ? array_values(array_filter($json['cinnosti'], 'is_string'))
                : [];
        }
        return $list;
    }

    /**
     * Validate and normalise the record of one of the four types.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function validate(string $type, array $body): array
    {
        return match ($type) {
            'kniha_bozp'         => self::validateKniha($body),
            'pracovisko'         => self::validatePracovisko($body),
            'osamele_pracovisko' => self::validateOsamele($body),
            'fajcenie'           => self::validateFajcenie($body),
            default              => throw new \InvalidArgumentException('Neznámy typ úkonu.'),
        };
    }

    /**
     * Figures for the protocol and the summary screen.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, int|string|null>
     */
    public static function stats(string $type, array $items): array
    {
        $f = (array) ($items[0]['fields'] ?? []);
        $stats = [
            'total'   => count($items),
            'defects' => Defects::count($items),
        ];
        switch ($type) {
            case 'kniha_bozp':
                $stats['workspaces'] = count(self::stringList($f['workspaces'] ?? []));
                $stats['result'] = is_string($f['result'] ?? null) ? $f['result'] : null;
                break;
            case 'pracovisko':
            case 'osamele_pracovisko':
            case 'fajcenie':
                $rows = $type === 'pracovisko' ? ($f['areas'] ?? []) : ($f['rows'] ?? []);
                $stats['rows'] = is_array($rows) ? count($rows) : 0;
                $stats['vyhovuje'] = 0;
                $stats['nevyhovuje'] = 0;
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $r = is_array($row) ? ($row['result'] ?? null) : null;
                    if ($r === 'vyhovuje' || $r === 'nevyhovuje') {
                        $stats[$r]++;
                    }
                }
                $stats['overall'] = is_string($f['overall'] ?? null) ? $f['overall'] : null;
                break;
        }
        return $stats;
    }

    // ── kniha_bozp ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private static function validateKniha(array $body): array
    {
        $workspaces = self::stringList($body['workspaces'] ?? null, 'Prehliadnuté pracoviská');
        if ($workspaces === []) {
            throw new \InvalidArgumentException('Doplň aspoň jedno prehliadnuté pracovisko.');
        }

        $predefined = self::knihaActivities();
        $picked = self::stringList($body['activities'] ?? null, 'Vykonané činnosti');
        foreach ($picked as $a) {
            if (!in_array($a, $predefined, true)) {
                throw new \InvalidArgumentException('Neznáma predpripravená činnosť — vlastnú činnosť pridaj ako vlastnú.');
            }
        }
        // Printed in the order of the checklist, whatever order they were ticked in.
        $activities = array_values(array_filter($predefined, static fn (string $a): bool => in_array($a, $picked, true)));
        $custom = self::stringList($body['custom_activities'] ?? null, 'Vlastné činnosti');
        if ($activities === [] && $custom === []) {
            throw new \InvalidArgumentException('Vyber aspoň jednu vykonanú činnosť alebo pridaj vlastnú.');
        }

        $result = $body['result'] ?? null;
        if (!is_string($result) || !isset(self::KNIHA_RESULTS[$result])) {
            throw new \InvalidArgumentException('Vyber výsledok kontroly — bez nedostatkov alebo zistené nedostatky.');
        }
        $defects = Defects::normalize($body['defects'] ?? null);
        self::assertResultMatchesDefects($result, $defects);

        return [
            'workspaces'        => $workspaces,
            'activities'        => $activities,
            'custom_activities' => $custom,
            'result'            => $result,
            'defects'           => $defects,
        ];
    }

    /**
     * „Bez nedostatkov" with three nedostatky listed below it would be a
     * protocol contradicting itself over the technician's signature.
     *
     * @param list<array<string, mixed>> $defects
     */
    private static function assertResultMatchesDefects(string $result, array $defects): void
    {
        if ($result === 'zistene_nedostatky' && $defects === []) {
            throw new \InvalidArgumentException('Pri výsledku „Zistené nedostatky" zapíš aspoň jeden nedostatok.');
        }
        if ($result === 'bez_nedostatkov' && $defects !== []) {
            throw new \InvalidArgumentException(
                'Pri výsledku „Bez nedostatkov" nemôžu byť zapísané nedostatky — zmeň výsledok alebo nedostatky odstráň.',
            );
        }
    }

    // ── pracovisko ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private static function validatePracovisko(array $body): array
    {
        $spaces = self::stringList($body['spaces'] ?? null, 'Kontrolované priestory');
        if ($spaces === []) {
            throw new \InvalidArgumentException('Doplň kontrolované priestory.');
        }

        $raw = $body['areas'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('Vyhodnoť aspoň jednu oblasť.');
        }
        $areas = [];
        $seen = [];
        foreach ($raw as $i => $row) {
            $n = $i + 1;
            if (!is_array($row)) {
                throw new \InvalidArgumentException("Oblasť č. $n má nesprávny tvar.");
            }
            $name = self::line($row['name'] ?? null, "Oblasť č. $n: názov");
            if ($name === null) {
                throw new \InvalidArgumentException("Oblasť č. $n nemá názov.");
            }
            if (isset($seen[mb_strtolower($name)])) {
                throw new \InvalidArgumentException("Oblasť „{$name}\" je v zozname dvakrát.");
            }
            $seen[mb_strtolower($name)] = true;
            $result = self::passFail($row['result'] ?? null, "Pri oblasti „{$name}\" vyber výsledok.");
            $areas[] = [
                'name'   => $name,
                'result' => $result,
                'note'   => self::text($row['note'] ?? null, self::MAX_NOTE, 'Poznámka k oblasti'),
            ];
        }
        if ($areas === []) {
            throw new \InvalidArgumentException('Vyhodnoť aspoň jednu oblasť.');
        }

        return [
            'spaces'  => $spaces,
            'areas'   => $areas,
            'overall' => self::overall($body['overall'] ?? null),
            'defects' => Defects::normalize($body['defects'] ?? null),
        ];
    }

    // ── osamele_pracovisko ──────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private static function validateOsamele(array $body): array
    {
        $raw = $body['rows'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new \InvalidArgumentException('Pridaj aspoň jedno kontrolované pracovisko.');
        }
        if (count($raw) > self::MAX_LIST) {
            throw new \InvalidArgumentException('Príliš veľa riadkov (najviac ' . self::MAX_LIST . ').');
        }
        $rows = [];
        foreach ($raw as $i => $row) {
            $n = $i + 1;
            if (!is_array($row)) {
                throw new \InvalidArgumentException("Riadok č. $n má nesprávny tvar.");
            }
            $rows[] = [
                'workplace'      => self::required($row['workplace'] ?? null, "Riadok č. $n: doplň pracovisko."),
                'activity'       => self::required($row['activity'] ?? null, "Riadok č. $n: doplň vykonávanú činnosť."),
                'connection'     => self::required($row['connection'] ?? null, "Riadok č. $n: doplň spojenie."),
                'presence_check' => self::required($row['presence_check'] ?? null, "Riadok č. $n: doplň kontrolu prítomnosti."),
                'result'         => self::passFail($row['result'] ?? null, "Riadok č. $n: vyber výsledok."),
            ];
        }

        return [
            'rows'    => $rows,
            'overall' => self::overall($body['overall'] ?? null),
            'defects' => Defects::normalize($body['defects'] ?? null),
        ];
    }

    // ── fajcenie ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private static function validateFajcenie(array $body): array
    {
        $scope = self::text($body['scope'] ?? null, self::MAX_TEXT, 'Rozsah kontroly');
        if ($scope === null) {
            throw new \InvalidArgumentException('Doplň rozsah kontroly — čo sa kontrolovalo.');
        }

        $raw = $body['rows'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new \InvalidArgumentException('Pridaj aspoň jedno zistenie — kontrolovanú oblasť a výsledok.');
        }
        if (count($raw) > self::MAX_LIST) {
            throw new \InvalidArgumentException('Príliš veľa riadkov (najviac ' . self::MAX_LIST . ').');
        }
        $rows = [];
        foreach ($raw as $i => $row) {
            $n = $i + 1;
            if (!is_array($row)) {
                throw new \InvalidArgumentException("Zistenie č. $n má nesprávny tvar.");
            }
            $rows[] = [
                'area'   => self::required($row['area'] ?? null, "Zistenie č. $n: doplň kontrolovanú oblasť."),
                'result' => self::passFail($row['result'] ?? null, "Zistenie č. $n: vyber výsledok."),
                'note'   => self::text($row['note'] ?? null, self::MAX_NOTE, 'Poznámka'),
            ];
        }

        return [
            'scope'    => $scope,
            'rows'     => $rows,
            'measures' => self::text($body['measures'] ?? null, self::MAX_TEXT, 'Opatrenia'),
            'defects'  => Defects::normalize($body['defects'] ?? null),
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * A list of short non-empty lines, de-duplicated. Accepts a list or a
     * newline-separated string (what a textarea sends).
     *
     * @return list<string>
     */
    private static function stringList(mixed $raw, string $label = 'Zoznam'): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\R/u', $raw) ?: [];
        }
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("$label: nesprávny tvar.");
        }
        $out = [];
        foreach ($raw as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '' || in_array($v, $out, true)) {
                continue;
            }
            if (mb_strlen($v) > self::MAX_LINE) {
                throw new \InvalidArgumentException("$label: položka je príliš dlhá (najviac " . self::MAX_LINE . ' znakov).');
            }
            $out[] = $v;
        }
        if (count($out) > self::MAX_LIST) {
            throw new \InvalidArgumentException("$label: príliš veľa položiek (najviac " . self::MAX_LIST . ').');
        }
        return $out;
    }

    private static function line(mixed $raw, string $label): ?string
    {
        return self::text($raw, self::MAX_LINE, $label);
    }

    private static function required(mixed $raw, string $message): string
    {
        $v = self::text($raw, self::MAX_LINE, 'Pole');
        if ($v === null) {
            throw new \InvalidArgumentException($message);
        }
        return $v;
    }

    private static function text(mixed $raw, int $max, string $label): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw new \InvalidArgumentException("$label: text je príliš dlhý (najviac $max znakov).");
        }
        return $v;
    }

    private static function passFail(mixed $raw, string $message): string
    {
        if (!is_string($raw) || !isset(self::PASS_FAIL[$raw])) {
            throw new \InvalidArgumentException($message);
        }
        return $raw;
    }

    private static function overall(mixed $raw): string
    {
        if (!is_string($raw) || !isset(self::OVERALL[$raw])) {
            throw new \InvalidArgumentException('Vyber celkové hodnotenie.');
        }
        return $raw;
    }
}
