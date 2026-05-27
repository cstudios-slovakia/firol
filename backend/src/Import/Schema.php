<?php

declare(strict_types=1);

namespace Firol\Import;

/**
 * Column maps for the Excel import templates. The Schema is the single
 * source of truth shared between template generation (download) and
 * row parsing (upload) so the two sides can never drift.
 *
 * Each sheet definition is a list of [header, key, hint?] tuples.
 * `key` is the field name the controller layer reads with array access.
 * `hint` is an example value rendered as the first data row of the
 * template to help the user understand the expected format.
 */
final class Schema
{
    /**
     * @return array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string}>}>
     */
    public static function companies(): array
    {
        return [
            'Firmy' => [
                'title' => 'Firmy',
                'columns' => [
                    ['header' => 'Názov firmy *',  'key' => 'name',    'hint' => 'Acme s.r.o.'],
                    ['header' => 'IČO',            'key' => 'ico',     'hint' => '12345678'],
                    ['header' => 'Adresa',         'key' => 'address', 'hint' => 'Hlavná 1, 010 01 Žilina'],
                    ['header' => 'Kontakt',        'key' => 'contact', 'hint' => 'Ján Vedúci · +421 900 000 000 · jan@acme.sk'],
                ],
            ],
            'Prevadzky' => [
                'title' => 'Prevádzky',
                'columns' => [
                    ['header' => 'IČO firmy *',         'key' => 'company_ico',    'hint' => '12345678'],
                    ['header' => 'Názov prevádzky *',   'key' => 'name',           'hint' => 'Sklad Žilina'],
                    ['header' => 'Adresa prevádzky',    'key' => 'address',        'hint' => 'Priemyselná 5, Žilina'],
                    ['header' => 'Kontaktná osoba',     'key' => 'contact_person', 'hint' => 'Peter Skladník'],
                    ['header' => 'Poznámky',            'key' => 'notes',          'hint' => 'Vstup z dvora.'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string}>}>
     */
    public static function trainings(): array
    {
        return [
            'Skolenia' => [
                'title' => 'Školenia',
                'columns' => [
                    ['header' => '# riadok *',        'key' => 'row_no',        'hint' => '1'],
                    ['header' => 'IČO firmy *',       'key' => 'company_ico',   'hint' => '12345678'],
                    ['header' => 'Prevádzka',         'key' => 'facility_name', 'hint' => 'Sklad Žilina'],
                    ['header' => 'Typ školenia *',    'key' => 'type',          'hint' => 'vstupne'],
                    ['header' => 'Dátum (YYYY-MM-DD) *', 'key' => 'date',       'hint' => '2026-01-15'],
                    ['header' => 'Trvanie (min)',     'key' => 'duration_min',  'hint' => '60'],
                    ['header' => 'Témy',              'key' => 'topics',        'hint' => 'Základné predpisy OPP, evakuácia.'],
                    ['header' => 'E-mail lektora',    'key' => 'trainer_email', 'hint' => 'lektor@firma.sk'],
                ],
            ],
            'Ucastnici' => [
                'title' => 'Účastníci',
                'columns' => [
                    ['header' => '# riadok školenia *', 'key' => 'training_row_no', 'hint' => '1'],
                    ['header' => 'Meno a priezvisko *', 'key' => 'fullname',        'hint' => 'Ján Účastník'],
                    ['header' => 'Pozícia',             'key' => 'position',        'hint' => 'Skladník'],
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
        'vstupne', 'opakovane', 'opp_mimo',
        'zdrzujuca_sa', 'hliadka_oph', 'hliadka_opah',
    ];

    /**
     * @return array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string}>}>
     */
    public static function inspections(): array
    {
        $sheets = [
            'Kontroly' => [
                'title' => 'Kontroly',
                'columns' => [
                    ['header' => '# riadok *',        'key' => 'row_no',           'hint' => '1'],
                    ['header' => 'IČO firmy *',       'key' => 'company_ico',      'hint' => '12345678'],
                    ['header' => 'Prevádzka *',       'key' => 'facility_name',    'hint' => 'Sklad Žilina'],
                    ['header' => 'Typ kontroly *',    'key' => 'type',             'hint' => 'php'],
                    ['header' => 'Periodicita (mes) *', 'key' => 'periodicity_months', 'hint' => '12'],
                    ['header' => 'Vykonané (YYYY-MM-DD) *', 'key' => 'executed_on', 'hint' => '2026-01-10'],
                    ['header' => 'E-mail technika',   'key' => 'inspector_email',  'hint' => 'technik@firma.sk'],
                    ['header' => 'Poznámka',          'key' => 'notes',            'hint' => ''],
                ],
            ],
            'Polozky_php' => [
                'title' => 'Položky — PHP',
                'columns' => [
                    ['header' => '# kontrola *',    'key' => 'row_no',       'hint' => '1'],
                    ['header' => 'Výrobca *',       'key' => 'manufacturer', 'hint' => 'Gloria'],
                    ['header' => 'Typ *',           'key' => 'type',         'hint' => 'P6'],
                    ['header' => 'Výrobné číslo *', 'key' => 'serial',       'hint' => 'GLR-2024-001'],
                    ['header' => 'Rok výroby *',    'key' => 'year',         'hint' => '2024'],
                    ['header' => 'Umiestnenie *',   'key' => 'location',     'hint' => 'Hala A'],
                    ['header' => 'Stav * (A/TS/O/V)', 'key' => 'status',     'hint' => 'A'],
                    ['header' => 'Poznámky',        'key' => 'notes',        'hint' => ''],
                ],
            ],
            'Polozky_hydranty' => [
                'title' => 'Položky — Hydranty',
                'columns' => [
                    ['header' => '# kontrola *',   'key' => 'row_no',     'hint' => '1'],
                    ['header' => 'Typ * (DN25/DN33/DN52/C52/other)', 'key' => 'type', 'hint' => 'DN25'],
                    ['header' => 'Typ — iný',      'key' => 'type_other', 'hint' => ''],
                    ['header' => 'Umiestnenie *',  'key' => 'location',   'hint' => 'Hala A'],
                    ['header' => 'Počet hadíc *',  'key' => 'hose_count', 'hint' => '1'],
                    ['header' => 'Hs *',           'key' => 'hs',         'hint' => '0.4'],
                    ['header' => 'Hd *',           'key' => 'hd',         'hint' => '0.2'],
                    ['header' => 'Q *',            'key' => 'q',          'hint' => '52'],
                    ['header' => 'Závady',         'key' => 'defects',    'hint' => ''],
                    ['header' => 'Výsledok * (vyhovuje/nevyhovuje)', 'key' => 'result', 'hint' => 'vyhovuje'],
                ],
            ],
            'Polozky_oprava_ts_php' => [
                'title' => 'Položky — Oprava/TS PHP',
                'columns' => [
                    ['header' => '# kontrola *',    'key' => 'row_no',       'hint' => '1'],
                    ['header' => 'Výrobca *',       'key' => 'manufacturer', 'hint' => 'Gloria'],
                    ['header' => 'Typ *',           'key' => 'type',         'hint' => 'P6'],
                    ['header' => 'Výrobné číslo *', 'key' => 'serial',       'hint' => 'GLR-2020-001'],
                    ['header' => 'Rok výroby *',    'key' => 'year',         'hint' => '2020'],
                    ['header' => 'Umiestnenie *',   'key' => 'location',     'hint' => 'Hala A'],
                    ['header' => 'Akcie * (čiarkou: tlakova_skuska,oprava,plnenie)', 'key' => 'actions', 'hint' => 'tlakova_skuska,plnenie'],
                    ['header' => 'Poznámky',        'key' => 'notes',        'hint' => ''],
                ],
            ],
            'Polozky_poziarna_kniha' => [
                'title' => 'Položky — Požiarna kniha',
                'columns' => [
                    ['header' => '# kontrola *',  'key' => 'row_no',         'hint' => '1'],
                    ['header' => 'Pracoviská *',  'key' => 'workspaces',     'hint' => 'Hala A, sklad'],
                    ['header' => 'Aktivity (čiarkou) *', 'key' => 'activities', 'hint' => 'visual_check,php_check'],
                    ['header' => 'Vlastné aktivity (|)', 'key' => 'custom_activities', 'hint' => ''],
                    ['header' => 'Výsledok * (bez_nedostatkov/zistene_nedostatky)', 'key' => 'result', 'hint' => 'bez_nedostatkov'],
                    ['header' => 'Závada — popis', 'key' => 'defect_description', 'hint' => ''],
                    ['header' => 'Závada — termín (YYYY-MM-DD)', 'key' => 'defect_deadline', 'hint' => ''],
                    ['header' => 'Poznámky',      'key' => 'notes',          'hint' => ''],
                ],
            ],
            'Polozky_pu_akcieschopnost' => [
                'title' => 'Položky — PU akcieschopnosť',
                'columns' => [
                    ['header' => '# kontrola *',  'key' => 'row_no',       'hint' => '1'],
                    ['header' => 'Druh * (dvere/okno/klapka)', 'key' => 'kind', 'hint' => 'dvere'],
                    ['header' => 'Označenie *',   'key' => 'identifier',   'hint' => 'PD-01'],
                    ['header' => 'Výrobca *',     'key' => 'manufacturer', 'hint' => 'Hörmann'],
                    ['header' => 'Umiestnenie *', 'key' => 'location',     'hint' => 'Chodba A1'],
                    ['header' => 'Výsledok * (vyhovuje/nevyhovuje)', 'key' => 'result', 'hint' => 'vyhovuje'],
                    ['header' => 'Poznámky',      'key' => 'notes',        'hint' => ''],
                ],
            ],
            'Polozky_pu_udrzba' => [
                'title' => 'Položky — PU údržba',
                'columns' => [
                    ['header' => '# kontrola *',  'key' => 'row_no',         'hint' => '1'],
                    ['header' => 'Druh * (dvere/okno/klapka)', 'key' => 'kind', 'hint' => 'dvere'],
                    ['header' => 'Označenie *',   'key' => 'identifier',     'hint' => 'PD-01'],
                    ['header' => 'Výrobca *',     'key' => 'manufacturer',   'hint' => 'Hörmann'],
                    ['header' => 'Umiestnenie *', 'key' => 'location',       'hint' => 'Chodba A1'],
                    ['header' => 'Údržba *',      'key' => 'maintenance_work', 'hint' => 'Mazanie pántov, kontrola tesnení'],
                    ['header' => 'Výsledok * (vyhovuje/nevyhovuje)', 'key' => 'result', 'hint' => 'vyhovuje'],
                    ['header' => 'Poznámky',      'key' => 'notes',          'hint' => ''],
                ],
            ],
            'Polozky_nudzove_osvetlenie' => [
                'title' => 'Položky — Núdzové osvetlenie',
                'columns' => [
                    ['header' => '# kontrola *',  'key' => 'row_no',         'hint' => '1'],
                    ['header' => 'Evid. číslo *', 'key' => 'evid_number',    'hint' => 'NO-001'],
                    ['header' => 'Podlažie *',    'key' => 'floor',          'hint' => '1.NP'],
                    ['header' => 'Typ svietidla *', 'key' => 'luminaire_type', 'hint' => 'LED nástenné'],
                    ['header' => 'Výrobca *',     'key' => 'manufacturer',   'hint' => 'Philips'],
                    ['header' => 'Umiestnenie *', 'key' => 'location',       'hint' => 'Chodba A1'],
                    ['header' => 'Trvanie (min) *', 'key' => 'duration_min', 'hint' => '60'],
                    ['header' => 'Výsledok * (vyhovuje/nevyhovuje)', 'key' => 'result', 'hint' => 'vyhovuje'],
                    ['header' => 'Poznámky',      'key' => 'notes',          'hint' => ''],
                ],
            ],
            'Polozky_ts_hadic' => [
                'title' => 'Položky — TS hadíc',
                'columns' => [
                    ['header' => '# kontrola *',     'key' => 'row_no',           'hint' => '1'],
                    ['header' => 'Typ hadice *',     'key' => 'hose_type',        'hint' => 'C52'],
                    ['header' => 'Umiestnenie *',    'key' => 'location',         'hint' => 'Hala A'],
                    ['header' => 'Výrobca *',        'key' => 'manufacturer',     'hint' => 'PavLiš'],
                    ['header' => 'Prac. tlak (MPa) *', 'key' => 'working_pressure', 'hint' => '0.6'],
                    ['header' => 'Skúš. tlak (MPa) *', 'key' => 'test_pressure',    'hint' => '1.2'],
                    ['header' => 'Dĺžka (m) *',      'key' => 'length',           'hint' => '20'],
                    ['header' => 'Rok výroby *',     'key' => 'year_of_manufacture', 'hint' => '2020'],
                    ['header' => 'Výsledok * (vyhovuje/nevyhovuje)', 'key' => 'result', 'hint' => 'vyhovuje'],
                    ['header' => 'Poznámky',         'key' => 'notes',            'hint' => ''],
                ],
            ],
        ];

        return $sheets;
    }
}
