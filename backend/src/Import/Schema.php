<?php

declare(strict_types=1);

namespace Firol\Import;

/**
 * Column maps for the Excel import templates. The Schema is the single
 * source of truth shared between template generation (download) and
 * row parsing (upload) so the two sides can never drift.
 *
 * Each sheet definition is a list of column descriptors:
 *   header  — visible column heading
 *   key     — field name the controller reads with array access
 *   hint    — example value rendered in the first data row
 *   options — for single-value enum fields: generates an Excel dropdown
 *   options_help — optional value→note map rendered alongside each
 *                  dropdown option in the Pokyny reference (the raw
 *                  `options` stay clean so validation still matches)
 *   multi_options — for multi-value fields (comma-separated): generates
 *                   a legend block below the data area; each entry is
 *                   ['value' => slug, 'label' => Slovak description]
 */
final class Schema
{
    /**
     * @return array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,date?:bool,prompt?:string,prompt_title?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}>
     */
    public static function companies(): array
    {
        return [
            'Firmy' => [
                'title' => 'Firmy',
                'columns' => [
                    ['header' => 'Názov firmy *', 'key' => 'name', 'hint' => 'Acme s.r.o.'],
                    ['header' => 'IČO', 'key' => 'ico', 'hint' => '12345678'],
                    ['header' => 'Adresa', 'key' => 'address', 'hint' => 'Hlavná 1, 010 01 Žilina'],
                    ['header' => 'Kontakt', 'key' => 'contact', 'hint' => 'Ján Vedúci · +421 900 000 000 · jan@acme.sk'],
                ],
            ],
            'Prevadzky' => [
                'title' => 'Prevádzky',
                'columns' => [
                    ['header' => 'IČO firmy *', 'key' => 'company_ico', 'hint' => '12345678'],
                    ['header' => 'Názov prevádzky *', 'key' => 'name', 'hint' => 'Sklad Žilina'],
                    ['header' => 'Adresa prevádzky', 'key' => 'address', 'hint' => 'Priemyselná 5, Žilina'],
                    ['header' => 'Kontaktná osoba', 'key' => 'contact_person', 'hint' => 'Peter Skladník'],
                    ['header' => 'Poznámky', 'key' => 'notes', 'hint' => 'Vstup z dvora.'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,date?:bool,prompt?:string,prompt_title?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}>
     */
    public static function trainings(): array
    {
        return [
            'Skolenia' => [
                'title' => 'Školenia',
                'columns' => [
                    [
                        'header' => 'Číslo školenia (ľubovoľné, na spárovanie s účastníkmi) *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo školenia',
                        'prompt' => 'Zadaj ľubovoľné číslo (napr. 1, 2, 3…). To isté číslo zopakuj na hárku „Účastníci" v stĺpci „Číslo školenia z hárku Školenia", aby sa účastníci priradili k tomuto školeniu.',
                    ],
                    ['header' => 'Názov firmy *', 'key' => 'company_name', 'hint' => 'Acme s.r.o.'],
                    ['header' => 'IČO firmy *', 'key' => 'company_ico', 'hint' => '12345678'],
                    ['header' => 'Prevádzka', 'key' => 'facility_name', 'hint' => 'Sklad Žilina'],
                    [
                        'header' => 'Typ školenia *',
                        'key' => 'type',
                        'hint' => 'vstupne',
                        'options' => self::TRAINING_TYPES,
                    ],
                    ['header' => 'Dátum (DD-MM-RRRR) *', 'key' => 'date', 'hint' => '15-01-2026', 'date' => true],
                    ['header' => 'E-mail lektora', 'key' => 'trainer_email', 'hint' => 'lektor@firma.sk'],
                ],
            ],
            'Ucastnici' => [
                'title' => 'Účastníci',
                'columns' => [
                    [
                        'header' => 'Číslo školenia z hárku Školenia *',
                        'key' => 'training_row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo školenia',
                        'prompt' => 'Napíš číslo školenia z hárku „Školenia" (stĺpec „Číslo školenia"). Účastník v tomto riadku sa priradí k danému školeniu.',
                    ],
                    ['header' => 'Meno a priezvisko *', 'key' => 'fullname', 'hint' => 'Ján Účastník'],
                    ['header' => 'Pracovné zaradenie', 'key' => 'position', 'hint' => 'Skladník'],
                ],
            ],
        ];
    }

    /** Locked inspection type slugs and per-type allowed periodicities. */
    public const INSPECTION_PERIODICITIES = [
        'php' => [12, 24],
        'hydranty' => [12],
        'oprava_ts_php' => [60],
        'poziarna_kniha' => [3, 6],
        'pu_akcieschopnost' => [3],
        'pu_udrzba' => [12],
        'nudzove_osvetlenie' => [12],
        'ts_hadic' => [60],
    ];

    public const TRAINING_TYPES = [
        'vstupne',
        'opakovane',
        'opp_mimo',
        'zdrzujuca_sa',
        'hliadka_oph',
        'hliadka_opah',
    ];

    /** All valid periodicities across all inspection types (for the dropdown). */
    private const ALL_PERIODICITIES = ['3', '6', '12', '24', '60'];

    /**
     * Reverse of {@see INSPECTION_PERIODICITIES}: for each periodicity value
     * the list of inspection types that accept it, as a comma-separated note
     * keyed by the (string) periodicity. Used to annotate the periodicity
     * dropdown in the import template.
     *
     * @return array<string,string>
     */
    public static function periodicityHelp(): array
    {
        $typesByPeriod = [];
        foreach (self::INSPECTION_PERIODICITIES as $type => $periods) {
            foreach ($periods as $p) {
                $typesByPeriod[(string) $p][] = $type;
            }
        }
        $out = [];
        foreach (self::ALL_PERIODICITIES as $p) {
            $out[$p] = implode(', ', $typesByPeriod[$p] ?? []);
        }
        return $out;
    }

    /** Predefined activity slugs for Požiarna kniha entries. */
    public const PK_ACTIVITIES = [
        ['value' => 'visual_check', 'label' => 'Vizuálna kontrola priestorov spoločnosti'],
        ['value' => 'php_check', 'label' => 'Kontrola stavu, označenia a dostupnosti PHP'],
        ['value' => 'hydranty_check', 'label' => 'Kontrola stavu, označenia a dostupnosti požiarnych hydrantov'],
        ['value' => 'escape_routes_check', 'label' => 'Kontrola stavu, označenia a voľnosti únikových ciest'],
        ['value' => 'pu_check', 'label' => 'Kontrola akcieschopnosti požiarnych uzáverov'],
        ['value' => 'training_initial', 'label' => 'Vykonané vstupné školenie z predpisov OPP'],
        ['value' => 'training_repeated', 'label' => 'Vykonané opakované školenie vedúcich a ostatných zamestnancov'],
        ['value' => 'electrical_equipment_check', 'label' => 'Kontrola stavu používaných elektrických zariadení'],
        ['value' => 'technical_equipment_check', 'label' => 'Kontrola stavu používaných technických zariadení'],
        ['value' => 'electrical_appliances_check', 'label' => 'Kontrola stavu používaných elektrických spotrebičov'],
        ['value' => 'documentation_check', 'label' => 'Kontrola aktuálnosti dokumentácie požiarnej ochrany'],
        ['value' => 'employee_list_check', 'label' => 'Kontrola aktuálneho zoznamu zamestnancov a ich školení'],
        ['value' => 'fire_drill', 'label' => 'Cvičný požiarny poplach'],
    ];

    /**
     * @return array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,date?:bool,prompt?:string,prompt_title?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}>
     */
    public static function inspections(): array
    {
        $inspectionTypes = array_keys(self::INSPECTION_PERIODICITIES);

        $sheets = [
            'Kontroly' => [
                'title' => 'Kontroly',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly (ľubovoľné, na spárovanie s položkami) *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Zadaj ľubovoľné číslo (napr. 1, 2, 3…). To isté číslo zopakuj na hárkoch s položkami v stĺpci „Číslo kontroly z hárku Kontroly", aby sa položky priradili k tejto kontrole.',
                    ],
                    ['header' => 'Názov firmy *', 'key' => 'company_name', 'hint' => 'Acme s.r.o.'],
                    ['header' => 'IČO firmy *', 'key' => 'company_ico', 'hint' => '12345678'],
                    ['header' => 'Prevádzka *', 'key' => 'facility_name', 'hint' => 'Sklad Žilina'],
                    [
                        'header' => 'Typ kontroly *',
                        'key' => 'type',
                        'hint' => 'php',
                        'options' => $inspectionTypes,
                    ],
                    [
                        'header' => 'Periodicita (mes) *',
                        'key' => 'periodicity_months',
                        'hint' => '12',
                        'options' => self::ALL_PERIODICITIES,
                        // Each periodicity is only valid for some inspection
                        // types — annotate the option list so the user knows
                        // which type to pair it with.
                        'options_help' => self::periodicityHelp(),
                    ],
                    ['header' => 'Vykonané (DD-MM-RRRR) *', 'key' => 'executed_on', 'hint' => '10-01-2026', 'date' => true],
                    ['header' => 'E-mail technika', 'key' => 'inspector_email', 'hint' => 'technik@firma.sk'],
                    ['header' => 'Poznámka', 'key' => 'notes', 'hint' => ''],
                ],
            ],
            'Polozky_php' => [
                'title' => 'Položky — PHP',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly z hárku Kontroly *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Napíš číslo kontroly z hárku „Kontroly" (stĺpec „Číslo kontroly"). Položka v tomto riadku sa priradí k danej kontrole.',
                    ],
                    ['header' => 'Výrobca *', 'key' => 'manufacturer', 'hint' => 'Gloria'],
                    ['header' => 'Typ *', 'key' => 'type', 'hint' => 'P6'],
                    ['header' => 'Výrobné číslo / séria *', 'key' => 'serial', 'hint' => 'GLR-2024-001'],
                    ['header' => 'Rok výroby *', 'key' => 'year', 'hint' => '2024'],
                    ['header' => 'Umiestnenie *', 'key' => 'location', 'hint' => 'Hala A'],
                    [
                        'header' => 'Stav *',
                        'key' => 'status',
                        'hint' => 'A',
                        'options' => ['A', 'TS', 'O', 'V'],
                    ],
                    ['header' => 'Poznámky', 'key' => 'notes', 'hint' => ''],
                ],
            ],
            'Polozky_hydranty' => [
                'title' => 'Položky — Hydranty',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly z hárku Kontroly *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Napíš číslo kontroly z hárku „Kontroly" (stĺpec „Číslo kontroly"). Položka v tomto riadku sa priradí k danej kontrole.',
                    ],
                    [
                        'header' => 'Typ hydrantu *',
                        'key' => 'type',
                        'hint' => 'DN25',
                        'options' => ['DN25', 'DN33', 'DN52', 'C52', 'other'],
                    ],
                    ['header' => 'Typ — iný', 'key' => 'type_other', 'hint' => ''],
                    ['header' => 'Umiestnenie *', 'key' => 'location', 'hint' => 'Hala A'],
                    ['header' => 'Počet hadíc *', 'key' => 'hose_count', 'hint' => '1'],
                    ['header' => 'Hs *', 'key' => 'hs', 'hint' => '0.4'],
                    ['header' => 'Hd *', 'key' => 'hd', 'hint' => '0.2'],
                    ['header' => 'Q *', 'key' => 'q', 'hint' => '52'],
                    ['header' => 'Závady', 'key' => 'defects', 'hint' => ''],
                    [
                        'header' => 'Výsledok *',
                        'key' => 'result',
                        'hint' => 'vyhovuje',
                        'options' => ['vyhovuje', 'nevyhovuje'],
                    ],
                ],
            ],
            'Polozky_oprava_ts_php' => [
                'title' => 'Položky — Oprava/TS PHP',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly z hárku Kontroly *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Napíš číslo kontroly z hárku „Kontroly" (stĺpec „Číslo kontroly"). Položka v tomto riadku sa priradí k danej kontrole.',
                    ],
                    ['header' => 'Výrobca *', 'key' => 'manufacturer', 'hint' => 'Gloria'],
                    ['header' => 'Typ *', 'key' => 'type', 'hint' => 'P6'],
                    ['header' => 'Výrobné číslo / séria *', 'key' => 'serial', 'hint' => 'GLR-2020-001'],
                    ['header' => 'Rok výroby *', 'key' => 'year', 'hint' => '2020'],
                    ['header' => 'Umiestnenie *', 'key' => 'location', 'hint' => 'Hala A'],
                    [
                        'header' => 'Vykonané úkony * (oddeľ čiarkou)',
                        'key' => 'actions',
                        'hint' => 'tlakova_skuska,plnenie',
                        'multi_options' => [
                            ['value' => 'tlakova_skuska', 'label' => 'Tlaková skúška'],
                            ['value' => 'oprava', 'label' => 'Oprava'],
                            ['value' => 'plnenie', 'label' => 'Plnenie'],
                        ],
                    ],
                    ['header' => 'Poznámky', 'key' => 'notes', 'hint' => ''],
                ],
            ],
            'Polozky_poziarna_kniha' => [
                'title' => 'Položky — Požiarna kniha',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly z hárku Kontroly *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Napíš číslo kontroly z hárku „Kontroly" (stĺpec „Číslo kontroly"). Položka v tomto riadku sa priradí k danej kontrole.',
                    ],
                    ['header' => 'Prehliadnuté pracoviská *', 'key' => 'workspaces', 'hint' => 'Hala A, sklad'],
                    [
                        'header' => 'Vykonané činnosti (oddeľ čiarkou) *',
                        'key' => 'activities',
                        'hint' => 'visual_check,php_check',
                        'multi_options' => self::PK_ACTIVITIES,
                    ],
                    ['header' => 'Vlastné aktivity (oddeľ |)', 'key' => 'custom_activities', 'hint' => ''],
                    [
                        'header' => 'Výsledok *',
                        'key' => 'result',
                        'hint' => 'bez_nedostatkov',
                        'options' => ['bez_nedostatkov', 'zistene_nedostatky'],
                    ],
                    ['header' => 'Závada — popis', 'key' => 'defect_description', 'hint' => ''],
                    ['header' => 'Závada — termín (DD-MM-RRRR)', 'key' => 'defect_deadline', 'hint' => '30-06-2026', 'date' => true],
                    ['header' => 'Poznámky', 'key' => 'notes', 'hint' => ''],
                ],
            ],
            'Polozky_pu_akcieschopnost' => [
                'title' => 'Položky — PU akcieschopnosť',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly z hárku Kontroly *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Napíš číslo kontroly z hárku „Kontroly" (stĺpec „Číslo kontroly"). Položka v tomto riadku sa priradí k danej kontrole.',
                    ],
                    [
                        'header' => 'Druh *',
                        'key' => 'kind',
                        'hint' => 'dvere',
                        'options' => ['dvere', 'okno', 'klapka'],
                    ],
                    ['header' => 'Označenie *', 'key' => 'identifier', 'hint' => 'PD-01'],
                    ['header' => 'Výrobca *', 'key' => 'manufacturer', 'hint' => 'Hörmann'],
                    ['header' => 'Umiestnenie *', 'key' => 'location', 'hint' => 'Chodba A1'],
                    [
                        'header' => 'Výsledok *',
                        'key' => 'result',
                        'hint' => 'vyhovuje',
                        'options' => ['vyhovuje', 'nevyhovuje'],
                    ],
                    ['header' => 'Poznámky', 'key' => 'notes', 'hint' => ''],
                ],
            ],
            'Polozky_pu_udrzba' => [
                'title' => 'Položky — PU údržba',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly z hárku Kontroly *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Napíš číslo kontroly z hárku „Kontroly" (stĺpec „Číslo kontroly"). Položka v tomto riadku sa priradí k danej kontrole.',
                    ],
                    [
                        'header' => 'Druh uzáveru*',
                        'key' => 'kind',
                        'hint' => 'dvere',
                        'options' => ['dvere', 'okno', 'klapka'],
                    ],
                    ['header' => 'Číslo / označenie *', 'key' => 'identifier', 'hint' => 'PD-01'],
                    ['header' => 'Výrobca *', 'key' => 'manufacturer', 'hint' => 'Hörmann'],
                    ['header' => 'Umiestnenie *', 'key' => 'location', 'hint' => 'Chodba A1'],
                    ['header' => 'Vykonané práce *', 'key' => 'maintenance_work', 'hint' => 'Mazanie pántov, kontrola tesnení'],
                    [
                        'header' => 'Výsledok *',
                        'key' => 'result',
                        'hint' => 'vyhovuje',
                        'options' => ['vyhovuje', 'nevyhovuje'],
                    ],
                    ['header' => 'Poznámky / závady', 'key' => 'notes', 'hint' => ''],
                ],
            ],
            'Polozky_nudzove_osvetlenie' => [
                'title' => 'Položky — Núdzové osvetlenie',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly z hárku Kontroly *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Napíš číslo kontroly z hárku „Kontroly" (stĺpec „Číslo kontroly"). Položka v tomto riadku sa priradí k danej kontrole.',
                    ],
                    ['header' => 'Evid. č. svietidla *', 'key' => 'evid_number', 'hint' => 'NO-001'],
                    ['header' => 'Podlažie *', 'key' => 'floor', 'hint' => '1.NP'],
                    ['header' => 'Druh / typ *', 'key' => 'luminaire_type', 'hint' => 'LED nástenné'],
                    ['header' => 'Výrobca *', 'key' => 'manufacturer', 'hint' => 'Philips'],
                    ['header' => 'Umiestnenie *', 'key' => 'location', 'hint' => 'Chodba A1'],
                    ['header' => 'Doba svietenia v núdzovom režime (min) *', 'key' => 'duration_min', 'hint' => '60'],
                    [
                        'header' => 'Výsledok *',
                        'key' => 'result',
                        'hint' => 'vyhovuje',
                        'options' => ['vyhovuje', 'nevyhovuje'],
                    ],
                    ['header' => 'Poznámky', 'key' => 'notes', 'hint' => ''],
                ],
            ],
            'Polozky_ts_hadic' => [
                'title' => 'Položky — TS hadíc',
                'columns' => [
                    [
                        'header' => 'Číslo kontroly z hárku Kontroly *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo kontroly',
                        'prompt' => 'Napíš číslo kontroly z hárku „Kontroly" (stĺpec „Číslo kontroly"). Položka v tomto riadku sa priradí k danej kontrole.',
                    ],
                    ['header' => 'Typ / priemer hadice *', 'key' => 'hose_type', 'hint' => 'C52'],
                    ['header' => 'Umiestnenie *', 'key' => 'location', 'hint' => 'Hala A'],
                    ['header' => 'Výrobca *', 'key' => 'manufacturer', 'hint' => 'PavLiš'],
                    ['header' => 'Prac. pretlak (MPa) *', 'key' => 'working_pressure', 'hint' => '0.6'],
                    ['header' => 'Skúš. pretlak (MPa) *', 'key' => 'test_pressure', 'hint' => '1.2'],
                    ['header' => 'Dĺžka (m) *', 'key' => 'length', 'hint' => '20'],
                    ['header' => 'Rok výroby *', 'key' => 'year_of_manufacture', 'hint' => '2020'],
                    [
                        'header' => 'Výsledok *',
                        'key' => 'result',
                        'hint' => 'vyhovuje',
                        'options' => ['vyhovuje', 'nevyhovuje'],
                    ],
                    ['header' => 'Poznámky', 'key' => 'notes', 'hint' => ''],
                ],
            ],
        ];

        return $sheets;
    }
}
