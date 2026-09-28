<?php

declare(strict_types=1);

namespace Firol\Pdf;

/**
 * Slovak name of each úkon as the shared (SPOLOČNÉ) documents print it — the
 * potvrdenie o vykonaní práce lists them, the výdajka names the úkon its
 * material was used in. One map, so the two documents never call the same
 * úkon by two names.
 */
final class ActLabels
{
    public const LABELS = [
        'php'                => 'Kontrola hasiacich prístrojov',
        'oprava_ts_php'      => 'Oprava, plnenie a tlaková skúška hasiacich prístrojov',
        'vyradenie'          => 'Vyradenie hasiacich prístrojov',
        'hydranty'           => 'Kontrola požiarnych hydrantov',
        'ts_hadic'           => 'Tlaková skúška hadíc',
        'poziarna_kniha'     => 'Zápis do požiarnej knihy',
        'pu_akcieschopnost'  => 'Kontrola akcieschopnosti požiarnych uzáverov',
        'pu_udrzba'          => 'Prevádzková údržba požiarnych uzáverov',
        'nudzove_osvetlenie' => 'Kontrola núdzového osvetlenia',
        // Block 2 — single-record BOZP úkony.
        'kniha_bozp'         => 'Kontrola stavu BOZP (kniha kontrol BOZP)',
        'pracovisko'         => 'Kontrola pracoviska a pracovného prostredia',
        'osamele_pracovisko' => 'Kontrola osamelých a odlúčených pracovísk',
        'fajcenie'           => 'Kontrola dodržiavania zákazu fajčenia',
        // Block 2 — the person-list úkony (typy_ukonov.json names).
        'dychova_skuska'     => 'Dychová skúška na alkohol',
        'omamne_latky'       => 'Kontrola omamných a psychotropných látok',
        'skolenie_bozp'      => 'Oboznámenie zamestnancov v oblasti BOZP',
        // A training is an úkon of a visit too (chapter 9); all kinds share
        // the one name, the kind itself is printed on the protocol.
        'skolenie_po'        => 'Školenie o ochrane pred požiarmi',
        // Block 2 — BOZP úkony with a list of rows (Záznam o kontrole … titles).
        'oopp'                 => 'Kontrola osobných ochranných pracovných prostriedkov',
        'pracovne_prostriedky' => 'Kontrola pracovných prostriedkov',
        'rebriky'              => 'Kontrola rebríkov',
        'regale'               => 'Kontrola regálov',
        'oznacenie'            => 'Kontrola bezpečnostného a zdravotného označenia',
    ];

    public static function for(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
