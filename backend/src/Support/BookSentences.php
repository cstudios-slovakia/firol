<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\DocumentController;
use Firol\Pdf\ActLabels;

/**
 * The sentences of a book entry (spec 9.3a, 9.3b, 9.4, 9.7).
 *
 * The app composes the proposed text of an entry from the date, the kind of
 * úkon, the object, the protocol number and the number of items checked, e.g.
 *
 *   Dňa 8. 7. 2026 bola vykonaná kontrola prenosných hasiacich prístrojov
 *   v objekte Výrobná hala a sklad. Protokol č. PHP-2026-013. Skontrolovaných
 *   položiek: 3.
 *
 * It is only a proposal — the technician signs for what is in the book, so the
 * text is always editable before it is added. The fixed sentences (the legal
 * one and the „no defects" statement) are constants here, so the server, the
 * PDF and the screen read the same wording.
 */
final class BookSentences
{
    /** Spec 9.4 — never added by itself; the technician turns it on. */
    public const LEGAL = 'Vykonaná preventívna protipožiarna prehliadka všetkých pracovísk v pôsobnosti '
        . 'spoločnosti v zmysle zákona č. 314/2001 Z. z. v platnom znení a vyhlášky MV SR '
        . 'č. 121/2002 Z. z. v platnom znení.';

    /** Spec 9.7 — printed as the section ZISTENIA. */
    public const NO_DEFECTS = 'V čase kontroly na kontrolovaných pracoviskách neboli zistené žiadne nedostatky';

    private const FEMININE  = 'f';
    private const NEUTER    = 'n';
    private const MASCULINE = 'm';

    /**
     * Inspection type → [phrase, grammatical gender]. The phrases are the
     * prototype's `KN_UKON`; rebríky and regále say „kontrola rebríkov" /
     * „kontrola regálov" — they are BOZP, not fire protection (spec 6.6), so
     * the prototype's „požiarnych rebríkov" does not apply. Types not listed
     * fall back to the úkon's own name ({@see ActLabels}).
     */
    private const UKON = [
        'php'                => ['kontrola prenosných hasiacich prístrojov', self::FEMININE],
        'hydranty'           => ['kontrola hadicových zariadení', self::FEMININE],
        'nudzove_osvetlenie' => ['kontrola núdzového osvetlenia', self::FEMININE],
        'pu_akcieschopnost'  => ['kontrola akcieschopnosti požiarnych uzáverov', self::FEMININE],
        'pu_udrzba'          => ['údržba požiarnych uzáverov', self::FEMININE],
        'rebriky'            => ['kontrola rebríkov', self::FEMININE],
        'regale'             => ['kontrola regálov', self::FEMININE],
        'pracovisko'         => ['kontrola pracoviska', self::FEMININE],
        'preventivna_prehliadka' => ['preventívna protipožiarna prehliadka', self::FEMININE],
        'ts_hadic'           => ['tlaková skúška hadíc', self::FEMININE],
        'oprava_ts_php'      => ['oprava, plnenie a tlaková skúška hasiacich prístrojov', self::FEMININE],
        'vyradenie'          => ['vyradenie hasiacich prístrojov', self::NEUTER],
        'kniha_bozp'         => ['kontrola stavu BOZP', self::FEMININE],
        'osamele_pracovisko' => ['kontrola osamelých a odlúčených pracovísk', self::FEMININE],
        'fajcenie'           => ['kontrola dodržiavania zákazu fajčenia', self::FEMININE],
        'oopp'               => ['kontrola osobných ochranných pracovných prostriedkov', self::FEMININE],
        'pracovne_prostriedky' => ['kontrola pracovných prostriedkov', self::FEMININE],
        'oznacenie'          => ['kontrola bezpečnostného a zdravotného označenia', self::FEMININE],
        'dychova_skuska'     => ['dychová skúška na alkohol', self::FEMININE],
        'omamne_latky'       => ['kontrola omamných a psychotropných látok', self::FEMININE],
        'skolenie_bozp'      => ['oboznámenie zamestnancov v oblasti BOZP', self::NEUTER],
        'poziarna_kniha'     => ['zápis do požiarnej knihy', self::MASCULINE],
    ];

    /**
     * Types whose items are things that were checked one by one, so „Skontrolovaných
     * položiek: N" says something. A single-record úkon or a list of people has
     * one row that is not a count of anything.
     */
    private const COUNTED = [
        'php', 'hydranty', 'oprava_ts_php', 'vyradenie', 'pu_akcieschopnost', 'pu_udrzba',
        'nudzove_osvetlenie', 'ts_hadic', 'rebriky', 'regale', 'oopp', 'pracovne_prostriedky', 'oznacenie',
    ];

    /** Trainings that read „odborná príprava" (feminine), the rest are „školenie" (neuter). */
    private const TRAINING_FEMININE = ['hliadka_oph', 'hliadka_opah'];

    /**
     * „Čo sa vykonalo" for an entry by protocol number (spec 9.3b) — the
     * prototype's `KN_RUCNE`. Each book offers its own kinds: BOZP things do
     * not belong to the fire book and the other way round (spec 9.6).
     *
     * @var array<string, array{0: string, 1: string}> kind → [phrase, gender]
     */
    private const MANUAL = [
        'ppp'     => ['preventívna protipožiarna prehliadka', self::FEMININE],
        'php'     => ['kontrola prenosných hasiacich prístrojov', self::FEMININE],
        'hyd'     => ['kontrola hadicových zariadení', self::FEMININE],
        'osv'     => ['kontrola núdzového osvetlenia', self::FEMININE],
        'uzv'     => ['kontrola akcieschopnosti požiarnych uzáverov', self::FEMININE],
        'unik'    => ['kontrola únikových ciest a východov', self::FEMININE],
        'sko'     => ['školenie o ochrane pred požiarmi', self::NEUTER],
        'dok'     => ['kontrola dokumentácie ochrany pred požiarmi', self::FEMININE],
        'poplach' => ['cvičný požiarny poplach', self::MASCULINE],
        'prac'    => ['kontrola pracoviska', self::FEMININE],
        'ine'     => ['iný úkon', self::MASCULINE],
    ];

    /** @var array<string, list<string>> */
    private const MANUAL_BY_BOOK = [
        BookMapping::PO   => ['ppp', 'php', 'hyd', 'osv', 'uzv', 'unik', 'sko', 'dok', 'poplach', 'ine'],
        BookMapping::BOZP => ['prac', 'ine'],
    ];

    /**
     * @return list<array{kind: string, label: string}>
     */
    public static function manualKinds(string $book): array
    {
        $out = [];
        foreach (self::MANUAL_BY_BOOK[$book] ?? [] as $kind) {
            $out[] = ['kind' => $kind, 'label' => self::MANUAL[$kind][0]];
        }
        return $out;
    }

    public static function isManualKind(string $book, string $kind): bool
    {
        return in_array($kind, self::MANUAL_BY_BOOK[$book] ?? [], true);
    }

    /** Whether an inspection type's protocol states how many items it covered. */
    public static function isCounted(string $type): bool
    {
        return in_array($type, self::COUNTED, true);
    }

    /** Name of an úkon as the offer card titles it. */
    public static function ukonTitle(string $type): string
    {
        return ActLabels::for($type);
    }

    /**
     * Offer text of one or several (merged) úkony of the same type and date.
     *
     * @param list<string> $facilityNames   objects, in the order met
     * @param list<string> $numbers         protocol numbers
     * @param int|null     $itemCount       items of a single úkon; null for merged / uncounted
     */
    public static function ukon(string $type, string $dateIso, array $facilityNames, array $numbers, ?int $itemCount): string
    {
        [$phrase, $gender] = self::UKON[$type] ?? [self::lcfirst(ActLabels::for($type)), self::FEMININE];
        $where = self::where($facilityNames);

        if (count($numbers) > 1) {
            $tail = ' Záznamy č. ' . implode(', ', $numbers) . '.';
        } elseif (count($numbers) === 1) {
            $tail = ' Protokol č. ' . $numbers[0] . '.';
        } else {
            $tail = '';
        }
        if ($itemCount !== null && $itemCount > 0 && self::isCounted($type)) {
            $tail .= ' Skontrolovaných položiek: ' . $itemCount . '.';
        }

        return 'Dňa ' . self::dateSk($dateIso) . ' ' . self::done($gender) . ' ' . $phrase . $where . '.' . $tail;
    }

    /**
     * Offer text of one or several (merged) trainings of the same type and date.
     *
     * @param list<string> $facilityNames
     * @param list<string> $numbers
     */
    public static function training(string $type, string $dateIso, array $facilityNames, array $numbers, int $attendees): string
    {
        $label = self::lcfirst(DocumentController::TRAINING_TYPE_LABELS[$type] ?? $type);
        $where = self::where($facilityNames);
        $date = 'Dňa ' . self::dateSk($dateIso) . ' ';

        if ($type === 'pokyn_zatva') {
            $tail = $numbers !== [] ? ' Záznam č. ' . implode(', ', $numbers) . '.' : '';
            return $date . 'bol vydaný ' . $label . $where . '.' . $tail;
        }

        $verb = in_array($type, self::TRAINING_FEMININE, true) ? 'bola vykonaná' : 'bolo vykonané';
        $who = $attendees > 0 ? ', ' . $attendees . ' ' . self::attendees($attendees) : '';
        if (count($numbers) > 1) {
            $tail = ' Záznamy č. ' . implode(', ', $numbers) . '.';
        } elseif (count($numbers) === 1) {
            $tail = ' Záznam č. ' . $numbers[0] . '.';
        } else {
            $tail = '';
        }
        return $date . $verb . ' ' . $label . $where . $who . '.' . $tail;
    }

    /**
     * The entry a closed preventívna prehliadka writes into a book (prototype
     * `dokonci`): „Dňa 13. 10. 2026 bola vykonaná preventívna protipožiarna
     * prehliadka v objekte X. Záznam č. PPP-2026-004. Zistených nedostatkov: 2."
     * The BOZP side reads „preventívna prehliadka BOZP" and carries the number
     * of the kniha BOZP record, which is its own record (spec 9.5a).
     */
    public static function prehliadka(string $book, string $dateIso, string $facilityName, string $number, int $defects): string
    {
        $what = $book === BookMapping::BOZP ? 'preventívna prehliadka BOZP' : 'preventívna protipožiarna prehliadka';
        return 'Dňa ' . self::dateSk($dateIso) . ' bola vykonaná ' . $what . self::where([$facilityName]) . '.'
            . ' Záznam č. ' . trim($number) . '. Zistených nedostatkov: ' . $defects . '.';
    }

    /** Entry by protocol number (spec 9.3b). */
    public static function byNumber(string $kind, string $dateIso, string $number, string $object): string
    {
        [$phrase, $gender] = self::MANUAL[$kind] ?? self::MANUAL['ine'];
        $where = trim($object) !== '' ? ' v objekte ' . trim($object) : '';
        return 'Dňa ' . self::dateSk($dateIso) . ' ' . self::done($gender) . ' ' . $phrase . $where
            . '. Protokol č. ' . trim($number) . '.';
    }

    /** „8. 7. 2026" of an ISO date. */
    public static function dateSk(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts !== false ? date('j. n. Y', $ts) : $iso;
    }

    private static function done(string $gender): string
    {
        return match ($gender) {
            self::NEUTER    => 'bolo vykonané',
            self::MASCULINE => 'bol vykonaný',
            default         => 'bola vykonaná',
        };
    }

    /** ` v objekte X` / ` v objektoch X, Y`; nothing when no object is known. */
    private static function where(array $facilityNames): string
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $facilityNames), static fn (string $n): bool => $n !== '')));
        if ($names === []) {
            return '';
        }
        return (count($names) === 1 ? ' v objekte ' : ' v objektoch ') . implode(', ', $names);
    }

    private static function attendees(int $n): string
    {
        if ($n === 1) {
            return 'účastník';
        }
        return $n >= 2 && $n <= 4 ? 'účastníci' : 'účastníkov';
    }

    /** Lower-cases the first letter only, so „OPP" in the middle of a label survives. */
    private static function lcfirst(string $s): string
    {
        return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
