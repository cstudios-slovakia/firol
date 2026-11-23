<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\DocumentController;
use Firol\Pdf\ActLabels;

/**
 * The sentences a požiarna kniha entry composes (POapp 7. 11. 2026, spec 7, 9).
 *
 * An attached record prints as one paragraph, in one of two forms:
 *
 *   full       „Dňa 7. 11. 2026 bola vykonaná kontrola prenosných hasiacich
 *              prístrojov v prevádzke Výrobná hala pekárne. Skontrolovaných
 *              5 prístrojov, z toho 3 akcieschopné, 1 odobratý na tlakovú
 *              skúšku a 1 na opravu. Záznam č. PHP-2026-013."
 *   reference  „Kontrola hasiacich prístrojov — viď záznam č. PHP-2026-013."
 *
 * and an entry written as a prehliadka prints the one fixed sentence of spec 9:
 * „Dňa 7. 11. 2026 bola vykonaná prehliadka v prevádzke X." The technician
 * does not rewrite any of them; what they want to add goes into their own texts.
 *
 * A record passed to {@see full()} is a row of {@see BookRecords}: `druh` (the
 * úkon or training type), `date`, `number`, and either `items` (the úkon's item
 * fields) or `attendees` (a training's head count).
 */
final class BookSentences
{
    /** Training types that read „odborná príprava" (feminine); the others are „školenie". */
    private const TRAINING_FEMININE = ['hliadka_oph', 'hliadka_opah'];

    /**
     * @param array<string, mixed> $rec   a BookRecords row with its internal `items` / `attendees`
     * @param string|null          $facilityName null for a training of the whole organisation
     * @param bool                 $bytoveDruzstvo the place reads „v bytovom dome" (round 6, as {@see fixed()})
     */
    public static function full(array $rec, ?string $facilityName, bool $bytoveDruzstvo = false): string
    {
        $where = $facilityName !== null && trim($facilityName) !== ''
            ? ($bytoveDruzstvo ? ' v bytovom dome ' : ' v prevádzke ') . trim($facilityName)
            : '';
        $summary = self::summary($rec);
        return 'Dňa ' . self::dateSk((string) $rec['date']) . ' ' . self::verb($rec) . $where . '.'
            . ($summary !== '' ? ' ' . $summary : '')
            . ' Záznam č. ' . (string) $rec['number'] . '.';
    }

    /** @param array<string, mixed> $rec */
    public static function reference(array $rec): string
    {
        return self::heading($rec) . ' — viď záznam č. ' . (string) $rec['number'] . '.';
    }

    /**
     * The fixed sentence of an entry written as a prehliadka. A bytové družstvo
     * reads „v bytovom dome / v bytových domoch" (customer's answer 9b of
     * 18. 11. 2026), a firma „v prevádzke / v prevádzkach".
     *
     * @param list<string> $names the selected prevádzky / bytové domy
     */
    public static function fixed(string $dateIso, array $names, bool $bytoveDruzstvo = false): string
    {
        $names = array_values(array_filter(array_map('trim', $names), static fn (string $n): bool => $n !== ''));
        $plural = count($names) > 1;
        $where = $bytoveDruzstvo
            ? ($plural ? 'v bytových domoch ' : 'v bytovom dome ')
            : ($plural ? 'v prevádzkach ' : 'v prevádzke ');
        return 'Dňa ' . self::dateSk($dateIso) . ' bola vykonaná prehliadka ' . $where . implode(', ', $names) . '.';
    }

    /** Title of a record — the name of its úkon or training. */
    public static function heading(array $rec): string
    {
        $druh = (string) $rec['druh'];
        return ($rec['kind'] ?? 'inspection') === 'training'
            ? (DocumentController::TRAINING_TYPE_LABELS[$druh] ?? $druh)
            : ActLabels::for($druh);
    }

    /** „8. 7. 2026" of an ISO date. */
    public static function dateSk(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts !== false ? date('j. n. Y', $ts) : $iso;
    }

    /** Slovak plural: one for 1, few for 2–4, many otherwise (0 included). */
    public static function sk3(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) {
            return $one;
        }
        return $n >= 2 && $n <= 4 ? $few : $many;
    }

    /**
     * The short count shown beside a record on the screen („5 prístrojov").
     *
     * @param array<string, mixed> $rec
     */
    public static function meta(array $rec): string
    {
        $druh = (string) $rec['druh'];
        if (($rec['kind'] ?? 'inspection') === 'training') {
            if ($druh === 'pokyn_zatva') {
                return '';
            }
            $n = (int) ($rec['attendees'] ?? 0);
            return $n . ' ' . self::sk3($n, 'zamestnanec', 'zamestnanci', 'zamestnancov');
        }
        $items = (array) ($rec['items'] ?? []);
        $n = count($items);
        return match ($druh) {
            'php', 'oprava_ts_php', 'vyradenie' => $n . ' ' . self::sk3($n, 'prístroj', 'prístroje', 'prístrojov'),
            'hydranty'           => $n . ' ' . self::sk3($n, 'hydrant', 'hydranty', 'hydrantov'),
            'nudzove_osvetlenie' => $n . ' ' . self::sk3($n, 'svietidlo', 'svietidlá', 'svietidiel'),
            'pu_akcieschopnost', 'pu_udrzba' => $n . ' ' . self::sk3($n, 'uzáver', 'uzávery', 'uzáverov'),
            'ts_hadic'           => $n . ' ' . self::sk3($n, 'hadica', 'hadice', 'hadíc'),
            'preventivna_prehliadka' => (function () use ($items): string {
                $d = count(PrehliadkaRecord::points($items, PrehliadkaRecord::PO));
                return $d . ' ' . self::sk3($d, 'nedostatok', 'nedostatky', 'nedostatkov');
            })(),
            default => '',
        };
    }

    /** „bola vykonaná kontrola …" — what was done, after the date. */
    private static function verb(array $rec): string
    {
        $druh = (string) $rec['druh'];
        if (($rec['kind'] ?? 'inspection') === 'training') {
            $label = self::lcfirst(DocumentController::TRAINING_TYPE_LABELS[$druh] ?? $druh);
            if ($druh === 'pokyn_zatva') {
                return 'bol vydaný ' . $label;
            }
            return (in_array($druh, self::TRAINING_FEMININE, true) ? 'bola vykonaná ' : 'bolo vykonané ') . $label;
        }
        return match ($druh) {
            'php'                    => 'bola vykonaná kontrola prenosných hasiacich prístrojov',
            'hydranty'               => 'bola vykonaná kontrola požiarnych hydrantov',
            'nudzove_osvetlenie'     => 'bola vykonaná kontrola núdzového osvetlenia',
            'pu_akcieschopnost'      => 'bola vykonaná kontrola akcieschopnosti požiarnych uzáverov',
            'pu_udrzba'              => 'bola vykonaná prevádzková údržba požiarnych uzáverov',
            'ts_hadic'               => 'bola vykonaná tlaková skúška hadíc',
            'oprava_ts_php'          => 'bola vykonaná oprava, plnenie a tlaková skúška hasiacich prístrojov',
            'vyradenie'              => 'bolo vykonané vyradenie hasiacich prístrojov',
            'preventivna_prehliadka' => 'bola vykonaná preventívna protipožiarna prehliadka',
            default                  => 'bola vykonaná ' . self::lcfirst(ActLabels::for($druh)),
        };
    }

    /** The second sentence of a full paragraph; '' when the record has nothing to count. */
    private static function summary(array $rec): string
    {
        $druh = (string) $rec['druh'];
        if (($rec['kind'] ?? 'inspection') === 'training') {
            $n = (int) ($rec['attendees'] ?? 0);
            if ($druh === 'pokyn_zatva' || $n <= 0) {
                return '';
            }
            $who = $n . ' ' . self::sk3($n, 'zamestnanec', 'zamestnanci', 'zamestnancov');
            $verb = self::sk3($n, 'zúčastnil', 'zúčastnili', 'zúčastnilo');
            return in_array($druh, self::TRAINING_FEMININE, true)
                ? 'Odbornej prípravy sa ' . $verb . ' ' . $who . '.'
                : 'Školenia sa ' . $verb . ' ' . $who . '.';
        }

        $items = array_values(array_map(static fn ($f): array => is_array($f) ? $f : [], (array) ($rec['items'] ?? [])));
        $n = count($items);
        if ($n === 0 && $druh !== 'preventivna_prehliadka') {
            return '';
        }
        $failed = count(array_filter($items, static fn (array $f): bool => ($f['result'] ?? null) === 'nevyhovuje'));

        return match ($druh) {
            'php'                => self::phpSummary($items),
            'hydranty'           => self::sk3($n, 'Skontrolovaný', 'Skontrolované', 'Skontrolovaných') . ' ' . $n . ' '
                . self::sk3($n, 'hydrant', 'hydranty', 'hydrantov') . self::hydrantType($items)
                . self::passTail($n, $failed, ['vyhovel', 'nevyhovel'], ['nevyhovel', 'nevyhoveli', 'nevyhovelo']),
            'nudzove_osvetlenie' => self::sk3($n, 'Skontrolované', 'Skontrolované', 'Skontrolovaných') . ' ' . $n . ' '
                . self::sk3($n, 'svietidlo', 'svietidlá', 'svietidiel')
                . self::passTail($n, $failed, ['vyhovelo', 'nevyhovelo'], ['nevyhovelo', 'nevyhoveli', 'nevyhovelo']),
            'pu_akcieschopnost'  => self::sk3($n, 'Skontrolovaný', 'Skontrolované', 'Skontrolovaných') . ' ' . $n . ' '
                . self::sk3($n, 'požiarny uzáver', 'požiarne uzávery', 'požiarnych uzáverov')
                . self::passTail($n, $failed, ['vyhovel', 'nevyhovel'], ['nevyhovel', 'nevyhoveli', 'nevyhovelo']),
            'pu_udrzba'          => ($n === 1 ? 'Údržba vykonaná na 1 požiarnom uzávere' : 'Údržba vykonaná na ' . $n . ' požiarnych uzáveroch')
                . ($failed === 0 ? '.' : ($n === 1 ? ', nevyhovel.' : ', z toho ' . $failed . ' ' . self::sk3($failed, 'nevyhovel', 'nevyhoveli', 'nevyhovelo') . '.')),
            'ts_hadic'           => self::sk3($n, 'Odskúšaná', 'Odskúšané', 'Odskúšaných') . ' ' . $n . ' '
                . self::sk3($n, 'hadica', 'hadice', 'hadíc')
                . self::passTail($n, $failed, ['vyhovela', 'nevyhovela'], ['nevyhovela', 'nevyhoveli', 'nevyhovelo']),
            'oprava_ts_php'      => 'Spolu ' . $n . ' ' . self::sk3($n, 'prístroj', 'prístroje', 'prístrojov') . '.',
            'vyradenie'          => self::sk3($n, 'Vyradený', 'Vyradené', 'Vyradených') . ' ' . $n . ' '
                . self::sk3($n, 'prístroj', 'prístroje', 'prístrojov') . '.',
            'preventivna_prehliadka' => self::prehliadkaSummary($items),
            default              => '',
        };
    }

    /** @param list<array<string, mixed>> $items */
    private static function phpSummary(array $items): string
    {
        $n = count($items);
        $count = ['A' => 0, 'TS' => 0, 'O' => 0, 'V' => 0];
        foreach ($items as $f) {
            $st = (string) ($f['status'] ?? '');
            if (isset($count[$st])) {
                $count[$st]++;
            }
        }
        $head = self::sk3($n, 'Skontrolovaný', 'Skontrolované', 'Skontrolovaných') . ' ' . $n . ' '
            . self::sk3($n, 'prístroj', 'prístroje', 'prístrojov');
        if ($count['A'] === $n) {
            return $head . ($n === 1 ? ', akcieschopný.' : ', všetky akcieschopné.');
        }
        if ($n === 1) {
            // One device needs no „z toho 1" — as passTail() does for the others.
            $state = match (true) {
                $count['TS'] === 1 => 'odobratý na tlakovú skúšku',
                $count['O'] === 1  => 'odobratý na opravu',
                $count['V'] === 1  => 'vyradený',
                default            => '',
            };
            return $head . ($state !== '' ? ', ' . $state : '') . '.';
        }
        $parts = [];
        if ($count['A'] > 0) {
            $parts[] = $count['A'] . ' ' . self::sk3($count['A'], 'akcieschopný', 'akcieschopné', 'akcieschopných');
        }
        if ($count['TS'] > 0) {
            $parts[] = $count['TS'] . ' ' . self::sk3($count['TS'], 'odobratý', 'odobraté', 'odobratých') . ' na tlakovú skúšku';
        }
        if ($count['O'] > 0) {
            $parts[] = $count['O'] . ' '
                . ($count['TS'] > 0 ? '' : self::sk3($count['O'], 'odobratý', 'odobraté', 'odobratých') . ' ')
                . 'na opravu';
        }
        if ($count['V'] > 0) {
            $parts[] = $count['V'] . ' ' . self::sk3($count['V'], 'vyradený', 'vyradené', 'vyradených');
        }
        if ($parts === []) {
            return $head . '.';
        }
        $last = array_pop($parts);
        return $head . ', z toho ' . ($parts !== [] ? implode(', ', $parts) . ' a ' : '') . $last . '.';
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function prehliadkaSummary(array $items): string
    {
        $points = PrehliadkaRecord::points($items, PrehliadkaRecord::PO);
        $d = count($points);
        if ($d === 0) {
            return 'Neboli zistené žiadne nedostatky.';
        }
        $dates = array_values(array_unique(array_map(static fn (array $p): ?string => $p['deadline_date'] ?? null, $points)));
        $same = count($dates) === 1 && is_string($dates[0]) ? ' s termínom odstránenia do ' . self::dateSk($dates[0]) : '';
        return self::sk3($d, 'Zistený', 'Zistené', 'Zistených') . ' ' . $d . ' '
            . self::sk3($d, 'nedostatok', 'nedostatky', 'nedostatkov') . $same . '.';
    }

    /** „ DN25" when every hydrant has the same type, else nothing. */
    private static function hydrantType(array $items): string
    {
        $types = [];
        foreach ($items as $f) {
            $t = (string) ($f['type'] ?? '');
            if ($t === 'other') {
                $t = trim((string) ($f['type_other'] ?? ''));
            }
            $types[$t] = true;
        }
        $keys = array_keys($types);
        return count($keys) === 1 && $keys[0] !== '' ? ' ' . $keys[0] : '';
    }

    /**
     * „, vyhovel." / „, všetky vyhoveli." / „, z toho 2 nevyhoveli."
     *
     * @param array{0: string, 1: string} $single  pass / fail word for one item
     * @param array{0: string, 1: string, 2: string} $failedWords fail word by count
     */
    private static function passTail(int $n, int $failed, array $single, array $failedWords): string
    {
        if ($n === 1) {
            return ', ' . ($failed === 0 ? $single[0] : $single[1]) . '.';
        }
        if ($failed === 0) {
            return ', všetky vyhoveli.';
        }
        return ', z toho ' . $failed . ' ' . self::sk3($failed, ...$failedWords) . '.';
    }

    /** Lower-cases the first letter only, so „OPP" in the middle of a label survives. */
    private static function lcfirst(string $s): string
    {
        return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
