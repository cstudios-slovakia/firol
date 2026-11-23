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
 *   text    — the cells are formatted as text, so Excel keeps „1,3,5" as typed
 *             instead of reading it as a (Slovak) decimal number
 *   date_or_text — a fixed word or a date (the book's „Lehota"): a date cell is
 *                  normalized to Y-m-d like a date column, a word stays as typed
 *   pokyny_type / pokyny_values — what the Pokyny reference shows for a column
 *                  whose allowed values the generic rendering cannot express
 *   pokyny_note — an extra line under the allowed values in the Pokyny reference
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
                    ['header' => 'IČO *', 'key' => 'ico', 'hint' => '12345678', 'text' => true, 'pokyny_note' => self::ICO_NOTE],
                    ['header' => 'Adresa', 'key' => 'address', 'hint' => 'Hlavná 1, 010 01 Žilina'],
                    ['header' => 'Kontakt', 'key' => 'contact', 'hint' => 'Ján Vedúci · +421 900 000 000 · jan@acme.sk'],
                    // Appended last: the importer reads columns by position, so a
                    // sheet downloaded before the column existed still lines up
                    // (and reads as a firma). The client type decides which list
                    // of activities a požiarna kniha entry offers.
                    [
                        'header' => 'Druh klienta',
                        'key' => 'client_type',
                        'hint' => 'firma',
                        'options' => self::CLIENT_TYPES,
                        'option_labels' => [
                            'firma' => 'Firma',
                            'bytove_druzstvo' => 'Bytové družstvo',
                        ],
                    ],
                ],
            ],
            'Prevadzky' => [
                'title' => 'Prevádzky',
                'columns' => [
                    ['header' => 'IČO firmy *', 'key' => 'company_ico', 'hint' => '12345678', 'text' => true, 'pokyny_note' => self::ICO_NOTE],
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
                    ['header' => 'IČO firmy *', 'key' => 'company_ico', 'hint' => '12345678', 'text' => true, 'pokyny_note' => self::ICO_NOTE],
                    ['header' => 'Prevádzka', 'key' => 'facility_name', 'hint' => 'Sklad Žilina'],
                    [
                        'header' => 'Typ školenia *',
                        'key' => 'type',
                        'hint' => 'vstupne',
                        'options' => self::TRAINING_TYPES,
                    ],
                    ['header' => 'Dátum (DD-MM-RRRR) *', 'key' => 'date', 'hint' => '15-01-2026', 'date' => true],
                    ['header' => 'E-mail lektora', 'key' => 'trainer_email', 'hint' => 'lektor@firma.sk'],
                    // Appended last: the importer reads columns by position, so a sheet
                    // downloaded before periodicity existed still lines up (and reads
                    // as „bez opakovania").
                    [
                        'header' => 'Periodicita — počet',
                        'key' => 'periodicity_value',
                        'hint' => '24',
                        'options' => self::PERIODICITY_SUGGESTIONS,
                        'prompt_title' => 'Periodicita',
                        'prompt' => 'Koľko dní / týždňov / mesiacov platí školenie. Nechaj prázdne, ak sa školenie neopakuje.',
                    ],
                    [
                        'header' => 'Periodicita — jednotka',
                        'key' => 'periodicity_unit',
                        'hint' => 'mesiac',
                        'options' => self::PERIODICITY_UNITS,
                        'prompt_title' => 'Jednotka periodicity',
                        'prompt' => 'den, tyzden alebo mesiac. Prázdne pole znamená mesiac.',
                    ],
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
                    // Appended last: the importer reads columns by position, so a sheet
                    // downloaded before the osobné číslo existed still lines up.
                    ['header' => 'Osobné číslo', 'key' => 'personal_number', 'hint' => '1042'],
                ],
            ],
        ];
    }

    /**
     * Inspection type slugs the importer accepts, with the periodicity in
     * months the app recommends for each. Since block 1 (chapter 5) the
     * recommendation is a default, not a constraint: an import may carry any
     * period, in any unit, or none at all.
     *
     * @var array<string, int|null>
     */
    public const INSPECTION_RECOMMENDED_MONTHS = [
        'php' => 24,
        'hydranty' => 12,
        'oprava_ts_php' => 60,
        'poziarna_kniha' => 12,
        'pu_akcieschopnost' => 3,
        'pu_udrzba' => 12,
        'nudzove_osvetlenie' => 12,
        'ts_hadic' => 12,
    ];

    public const TRAINING_TYPES = [
        'vstupne',
        'opakovane',
        'opp_mimo',
        'zdrzujuca_sa',
        'hliadka_oph',
        'hliadka_opah',
    ];

    /** Values offered in the periodicity dropdown. Anything else is accepted too. */
    private const PERIODICITY_SUGGESTIONS = ['1', '3', '6', '12', '24', '36'];

    /** Units a period can be expressed in (chapter 5). */
    public const PERIODICITY_UNITS = ['mesiac', 'tyzden', 'den'];

    /**
     * Pokyny line of every IČO column. The cells are text, so leading zeros
     * stay; a number Excel already stripped is padded back to 8 digits.
     */
    private const ICO_NOTE = 'Zadaj všetkých 8 číslic vrátane núl na začiatku (napr. 00169803). Kratšie IČO z číslic sa doplní nulami zľava.';

    /** Values of the optional „Druh klienta" column (companies.client_type); empty = firma. */
    public const CLIENT_TYPES = ['firma', 'bytove_druzstvo'];

    /** Values of the „Stav" column of Kniha_nedostatky (PrehliadkaDefects::STATUSES) with the screen's labels. */
    public const BOOK_DEFECT_STATUS_LABELS = [
        'zisteny'    => 'zistený',
        'odstraneny' => 'odstránený',
        'trva'       => 'trvá',
        'zanik'      => 'dôvod zanikol',
    ];

    /**
     * Pokyny line of the Kniha_zapisy columns the app shows only in spôsob 1
     * (step 2 of the entry): an import stores them only for a spôsob-1 entry.
     */
    private const SPOSOB_1_ONLY = 'Len pri spôsobe 1 — pri spôsobe 2 sa neuloží.';

    /** Fixed deadline choices of the „Lehota" column; a date is the fifth possibility. */
    public const BOOK_DEADLINE_CHOICES = ['bezodkladne', '30d', '3m', '6m'];

    /**
     * Sheets of the inspections workbook that only an upload still reads: a
     * file filled in before the book's new format keeps importing as it did.
     * The generated template no longer contains them.
     */
    public const LEGACY_INSPECTION_SHEETS = ['Polozky_poziarna_kniha'];

    /** Predefined activity slugs for Požiarna kniha entries (the legacy sheet Polozky_poziarna_kniha). */
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
        ['value' => 'documentation_check', 'label' => 'Kontrola aktuálnosti dokumentácie ochrany pred požiarmi'],
        ['value' => 'employee_list_check', 'label' => 'Kontrola aktuálneho zoznamu zamestnancov a ich školení'],
        ['value' => 'fire_drill', 'label' => 'Cvičný požiarny poplach'],
    ];

    /**
     * @return array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,date?:bool,prompt?:string,prompt_title?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}>
     */
    public static function inspections(): array
    {
        return self::inspectionSheets(false);
    }

    /**
     * What an upload of the inspections workbook reads: the template's sheets
     * plus {@see LEGACY_INSPECTION_SHEETS}, so a file filled in on an older
     * template imports exactly as it did.
     *
     * @return array<string, array{title:string, columns: list<array<string, mixed>>}>
     */
    public static function inspectionsUpload(): array
    {
        return self::inspectionSheets(true);
    }

    /**
     * @return array<string, array{title:string, columns: list<array<string, mixed>>}>
     */
    private static function inspectionSheets(bool $withLegacy): array
    {
        $inspectionTypes = array_keys(self::INSPECTION_RECOMMENDED_MONTHS);
        // A new book entry has its own sheets (Kniha_*); the template no longer
        // offers poziarna_kniha on Kontroly. The importer still accepts it, for
        // the files that pair it with the legacy sheet.
        if (!$withLegacy) {
            $inspectionTypes = array_values(array_diff($inspectionTypes, ['poziarna_kniha']));
        }

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
                    ['header' => 'IČO firmy *', 'key' => 'company_ico', 'hint' => '12345678', 'text' => true, 'pokyny_note' => self::ICO_NOTE],
                    ['header' => 'Prevádzka *', 'key' => 'facility_name', 'hint' => 'Sklad Žilina'],
                    [
                        'header' => 'Typ kontroly *',
                        'key' => 'type',
                        'hint' => 'php',
                        'options' => $inspectionTypes,
                    ],
                    [
                        'header' => 'Periodicita — počet',
                        'key' => 'periodicity_value',
                        'hint' => '12',
                        'options' => self::PERIODICITY_SUGGESTIONS,
                        'prompt_title' => 'Periodicita',
                        'prompt' => 'Koľko dní / týždňov / mesiacov platí kontrola. Nechaj prázdne, ak sa kontrola neopakuje.',
                    ],
                    [
                        'header' => 'Periodicita — jednotka',
                        'key' => 'periodicity_unit',
                        'hint' => 'mesiac',
                        'options' => self::PERIODICITY_UNITS,
                        'prompt_title' => 'Jednotka periodicity',
                        'prompt' => 'den, tyzden alebo mesiac. Prázdne pole znamená mesiac.',
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
                        // Spell out each cryptic code in the dropdown value
                        // itself, e.g. "TS (Tlaková skúška)". The importer
                        // strips the parenthetical back to the bare code, so a
                        // file with plain "TS" still imports unchanged.
                        'option_labels' => [
                            'A'  => 'Akcieschopný',
                            'TS' => 'Tlaková skúška',
                            'O'  => 'Vyžaduje opravu',
                            'V'  => 'Vyradený',
                        ],
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
                    ['header' => 'Hs (statický tlak, MPa) *', 'key' => 'hs', 'hint' => '0.4'],
                    ['header' => 'Hd (dynamický tlak, MPa) *', 'key' => 'hd', 'hint' => '0.2'],
                    ['header' => 'Q (prietok, l/s) *', 'key' => 'q', 'hint' => '52'],
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

        if (!$withLegacy) {
            foreach (self::LEGACY_INSPECTION_SHEETS as $legacy) {
                unset($sheets[$legacy]);
            }
        }

        return $sheets + self::bookSheets();
    }

    /**
     * The požiarna kniha in its new format (`details.format` = pk2, BookEntry):
     * one row per entry, one per prevádzka of an entry, one per defect. Linked
     * by the number of the entry, like Kontroly and their items.
     *
     * @return array<string, array{title:string, columns: list<array<string, mixed>>}>
     */
    private static function bookSheets(): array
    {
        $entryLink = [
            'header' => 'Číslo zápisu z hárku Kniha_zapisy *',
            'key' => 'row_no',
            'hint' => '1',
            'prompt_title' => 'Číslo zápisu',
            'prompt' => 'Napíš číslo zápisu z hárku „Kniha_zapisy" (stĺpec „Číslo zápisu"). Riadok sa priradí k danému zápisu.',
        ];

        return [
            'Kniha_zapisy' => [
                'title' => 'Požiarna kniha — zápisy',
                'columns' => [
                    [
                        'header' => 'Číslo zápisu (ľubovoľné, na spárovanie) *',
                        'key' => 'row_no',
                        'hint' => '1',
                        'prompt_title' => 'Číslo zápisu',
                        'prompt' => 'Zadaj ľubovoľné číslo (napr. 1, 2, 3…). To isté číslo zopakuj na hárkoch „Kniha_prevadzky" a „Kniha_nedostatky", aby sa prevádzky a nedostatky priradili k tomuto zápisu.',
                    ],
                    ['header' => 'Názov firmy *', 'key' => 'company_name', 'hint' => 'Acme s.r.o.'],
                    ['header' => 'IČO firmy *', 'key' => 'company_ico', 'hint' => '12345678', 'text' => true, 'pokyny_note' => self::ICO_NOTE],
                    ['header' => 'Dátum zápisu (DD-MM-RRRR) *', 'key' => 'executed_on', 'hint' => '10-01-2026', 'date' => true],
                    [
                        'header' => 'Spôsob zápisu',
                        'key' => 'sposob',
                        'hint' => '1',
                        'options' => ['1', '2'],
                        'option_labels' => [
                            '1' => 'Zápis je zároveň preventívkou',
                            '2' => 'Preventívky sú priložené',
                        ],
                        'pokyny_note' => 'Prázdne pole: 1 pri jednej prevádzke, 2 pri viacerých.',
                    ],
                    [
                        'header' => 'Vykonané činnosti — spôsob 1 (čísla bodov, oddeľ čiarkou)',
                        'key' => 'cinnosti',
                        'hint' => '1,3,5',
                        'text' => true,
                        'prompt_title' => 'Čísla bodov',
                        'prompt' => 'Čísla bodov zo zoznamu činností oddelené čiarkou (napr. 1,3,5). Firma má zoznam s 9 bodmi, bytové družstvo s 13 bodmi — oba nájdeš na hárku „Pokyny".',
                        'pokyny_type' => 'Čísla bodov (oddeľ čiarkou)',
                        'pokyny_values' => self::activityLegend(),
                        'pokyny_note' => self::SPOSOB_1_ONLY,
                    ],
                    ['header' => 'Vlastné činnosti — spôsob 1 (oddeľ |)', 'key' => 'vlastne_cinnosti', 'hint' => 'Kontrola skladu | Kontrola kotolne', 'pokyny_note' => self::SPOSOB_1_ONLY],
                    ['header' => 'Úvod — spôsob 1 (prázdne = zákonná veta)', 'key' => 'uvod', 'hint' => '', 'pokyny_note' => self::SPOSOB_1_ONLY . ' Pri spôsobe 2 zostane zákonná veta, ako v aplikácii.'],
                    ['header' => 'Text zápisu — spôsob 2', 'key' => 'text_zapisu', 'hint' => '', 'pokyny_note' => 'Len pri spôsobe 2 — pri spôsobe 1 sa neuloží.'],
                    ['header' => 'Doplňujúci text — spôsob 1', 'key' => 'doplnujuci_text', 'hint' => '', 'pokyny_note' => self::SPOSOB_1_ONLY],
                    ['header' => 'Na záver', 'key' => 'zaver', 'hint' => \Firol\Support\BookEntry::NA_ZAVER[0], 'pokyny_note' => 'Pri oboch spôsoboch.'],
                    ['header' => 'Schválil za klienta', 'key' => 'schvalil', 'hint' => 'Ján Vedúci, konateľ', 'pokyny_note' => 'Pri oboch spôsoboch.'],
                    ['header' => 'E-mail technika', 'key' => 'inspector_email', 'hint' => 'technik@firma.sk'],
                ],
            ],
            'Kniha_prevadzky' => [
                'title' => 'Požiarna kniha — prevádzky zápisu',
                'columns' => [
                    $entryLink,
                    ['header' => 'Prevádzka *', 'key' => 'facility_name', 'hint' => 'Sklad Žilina'],
                    [
                        'header' => 'Periodicita (mesiace)',
                        'key' => 'periodicity',
                        'hint' => '12',
                        'options' => array_map('strval', \Firol\Support\BookEntry::PERIODICITY_MONTHS),
                        'pokyny_note' => 'Pri spôsobe 1 povinné pre každú prevádzku; pri spôsobe 2 môže ostať prázdne.',
                    ],
                ],
            ],
            'Kniha_nedostatky' => [
                'title' => 'Požiarna kniha — nedostatky',
                'columns' => [
                    $entryLink,
                    [
                        'header' => 'Prevádzka',
                        'key' => 'facility_name',
                        'hint' => 'Sklad Žilina',
                        'prompt_title' => 'Prevádzka',
                        'prompt' => 'Povinné, keď má zápis viac prevádzok: napíš jednu z prevádzok zápisu z hárku „Kniha_prevadzky".',
                    ],
                    ['header' => 'Názov nedostatku *', 'key' => 'title', 'hint' => 'Zablokovaná úniková cesta'],
                    ['header' => 'Popis zisteného stavu *', 'key' => 'description', 'hint' => 'Na únikovej ceste sú uložené palety.'],
                    ['header' => 'Miesto *', 'key' => 'place', 'hint' => 'Chodba pri sklade'],
                    [
                        'header' => 'Lehota',
                        'key' => 'deadline',
                        'hint' => '30d',
                        'date_or_text' => true,
                        'prompt_title' => 'Lehota na odstránenie',
                        'prompt' => 'bezodkladne, 30d, 3m, 6m alebo dátum vo formáte DD-MM-RRRR. Prázdne pole = bez lehoty.',
                        'pokyny_type' => 'Výber alebo dátum',
                        'pokyny_values' => "bezodkladne  —  bezodkladne\n30d  —  do 30 dní od dátumu zápisu\n3m  —  do 3 mesiacov\n6m  —  do 6 mesiacov\nalebo dátum DD-MM-RRRR (napr. 30-06-2026)\nprázdne  —  bez lehoty",
                    ],
                    [
                        'header' => 'Stav',
                        'key' => 'status',
                        'hint' => 'zisteny',
                        'options' => array_keys(self::BOOK_DEFECT_STATUS_LABELS),
                        'option_labels' => self::BOOK_DEFECT_STATUS_LABELS,
                        'pokyny_note' => 'Prázdne pole = zistený.',
                    ],
                    ['header' => 'Dôvod (pri stave „dôvod zanikol")', 'key' => 'reason', 'hint' => ''],
                    ['header' => 'Zariadenie', 'key' => 'device_manual', 'hint' => 'PHP P6, v. č. 1234'],
                    ['header' => 'Predpis', 'key' => 'regulation', 'hint' => ''],
                    ['header' => 'Odôvodnenie', 'key' => 'justification', 'hint' => ''],
                    ['header' => 'Opatrenie', 'key' => 'measure', 'hint' => ''],
                ],
            ],
        ];
    }

    /** Both activity lists of a book entry, numbered, for the Pokyny sheet. */
    private static function activityLegend(): string
    {
        $lines = ['Firma (Obsahom prehliadky bola kontrola):'];
        foreach (\Firol\Support\BookEntry::FIRMA_ACTIVITIES as $i => $label) {
            $lines[] = ($i + 1) . '  —  ' . $label;
        }
        $lines[] = 'Bytové družstvo:';
        foreach (\Firol\Support\BookEntry::DOM_ACTIVITIES as $i => $label) {
            $lines[] = ($i + 1) . '  —  ' . $label;
        }
        return implode("\n", $lines);
    }
}
