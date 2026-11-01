<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Which of the two books an úkon belongs to (spec 6.6, 9.6).
 *
 *   po    — požiarna kniha (§ 29 vyhl. 121/2002): fire extinguishers, hydrants,
 *           požiarne uzávery (akcieschopnosť and údržba), núdzové osvetlenie,
 *           TS hadíc, oprava / plnenie / TS PHP, vyradenie, the preventívna
 *           prehliadka (its PO record), the old `poziarna_kniha` records and
 *           every training of the PO tree plus the Pokyn — žatevné práce.
 *   bozp  — kniha BOZP (zák. 124/2006): every úkon of the BOZP section —
 *           rebríky and regále included, they are not ochrana pred požiarmi —
 *           and the oboznámenie BOZP.
 *
 * Two separate books, the same mechanics. The TypeScript twin is
 * frontend/src/lib/bookMapping.ts; the two lists must stay identical.
 */
final class BookMapping
{
    public const PO   = 'po';
    public const BOZP = 'bozp';

    public const BOOKS = [self::PO, self::BOZP];

    /**
     * Inspection types of the požiarna kniha. `preventivna_prehliadka` is the
     * type phase 7a introduces; listing it here keeps the mapping in one place.
     *
     * @var list<string>
     */
    public const PO_INSPECTION_TYPES = [
        'php', 'hydranty', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie',
        'ts_hadic', 'oprava_ts_php', 'vyradenie',
        'preventivna_prehliadka', 'poziarna_kniha',
    ];

    public static function isBook(string $book): bool
    {
        return in_array($book, self::BOOKS, true);
    }

    /** The book an inspection type is written into, or null when it has none. */
    public static function forInspectionType(string $type): ?string
    {
        if (in_array($type, self::PO_INSPECTION_TYPES, true)) {
            return self::PO;
        }
        return Sections::forInspectionType($type) === Sections::BOZP ? self::BOZP : null;
    }

    /**
     * Trainings of the trainings table are all PO: the six attendance-based
     * ones and the Pokyn. The oboznámenie BOZP is the inspection type
     * `skolenie_bozp`, which {@see forInspectionType()} sends to the BOZP book.
     */
    public static function forTraining(string $trainingType): string
    {
        return self::PO;
    }

    /** @return list<string> inspection types that belong to the book */
    public static function inspectionTypesOf(string $book): array
    {
        if ($book === self::PO) {
            return self::PO_INSPECTION_TYPES;
        }
        return Sections::INSPECTION_TYPES[Sections::BOZP];
    }

    public static function label(string $book): string
    {
        return $book === self::BOZP ? 'Kniha BOZP' : 'Požiarna kniha';
    }
}
