<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * The three sections the app is split into — block 1 / chapter 2.
 *
 * The one section "Kontroly" becomes Revízie / OPP / BOZP, matching the three
 * paid modules one to one. The section Školenia disappears: a training is an
 * úkon like any other, with its own protocol and its own next term, and
 * putting it in a separate menu meant a technician looking for last year's
 * školenie PO had to look somewhere other than the rest of their PO work.
 *
 * A visit (chapter 9) belongs to no section — it is an activity, not a list,
 * and offers types from every section at once.
 */
final class Sections
{
    public const REVIZIE = 'revizie';
    public const OPP     = 'opp';
    public const BOZP    = 'bozp';

    /**
     * Inspection types per section. Trainings are listed separately (they live
     * in their own table) — see {@see trainingSection()}.
     *
     * The BOZP types arrive with block 2; the section is declared here already
     * so the mapping has one home rather than growing a second copy later.
     *
     * @var array<string, list<string>>
     */
    public const INSPECTION_TYPES = [
        self::REVIZIE => ['php', 'oprava_ts_php', 'vyradenie', 'hydranty', 'ts_hadic'],
        self::OPP     => ['poziarna_kniha', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie'],
        self::BOZP    => [],
    ];

    /** Slovak label of each section, as it reads in the menu. */
    public const LABELS = [
        self::REVIZIE => 'Revízie',
        self::OPP     => 'OPP',
        self::BOZP    => 'BOZP',
    ];

    /**
     * Colour of the section, used for the band and headings on its protocols.
     * Chosen to stay legible on a black-and-white printout — but the colour is
     * never the only signal: every protocol also carries the section's name in
     * a box in the header, which is the only thing that survives that printer.
     */
    public const COLORS = [
        self::REVIZIE => '#C75B45',
        self::OPP     => '#E8433A',
        self::BOZP    => '#3D7FC1',
    ];

    /** Neutral grey for documents that belong to no section at all. */
    public const SHARED_COLOR = '#475569';

    public static function forInspectionType(string $type): ?string
    {
        foreach (self::INSPECTION_TYPES as $section => $types) {
            if (in_array($type, $types, true)) {
                return $section;
            }
        }
        return null;
    }

    /**
     * Section of a training type. The six attendance-based PO trainings and
     * the Pokyn — žatevné práce all belong to OPP; BOZP oboznámenie arrives
     * with block 2.
     */
    public static function forTrainingType(string $type): string
    {
        return self::OPP;
    }
}
