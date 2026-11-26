<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Import\Schema;
use Firol\Support\Periodicity;
use Firol\Support\Address;
use Firol\Support\BookEntry;
use Firol\Support\PrehliadkaActivities;
use Firol\Support\PrehliadkaContext;
use Firol\Support\PrehliadkaDefects;
use Firol\Support\PrehliadkaDetails;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PDO;
use Throwable;

/**
 * Excel-based bulk import of companies (+facilities), trainings
 * (+trainees) and inspections (+items). Templates are generated from
 * the same {@see Schema} the upload parser reads, so headers can never
 * drift between download and upload.
 *
 * Each import endpoint runs inside a single transaction: any validation
 * error aborts the whole sheet group so the user retries with a clean
 * file rather than chasing half-applied state.
 */
final class ImportController
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    // ─── Template downloads ──────────────────────────────────────────

    public static function companiesTemplate(Request $req): void
    {
        self::sendTemplate('POapp-import-firmy.xlsx', Schema::companies());
    }

    public static function trainingsTemplate(Request $req): void
    {
        self::sendTemplate('POapp-import-skolenia.xlsx', Schema::trainings());
    }

    public static function inspectionsTemplate(Request $req): void
    {
        self::sendTemplate('POapp-import-kontroly.xlsx', Schema::inspections());
    }

    /**
     * @param array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,date?:bool,prompt?:string,prompt_title?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}> $sheets
     */
    private static function sendTemplate(string $filename, array $sheets): void
    {
        // Ensure we have an authenticated tenant context; templates are
        // per-tenant only in the sense that downloads require login.
        Tenant::currentUserId();

        $spreadsheet = self::buildWorkbook($sheets);

        // Stream the workbook to stdout.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Builds the in-memory template workbook (instructions tab + one data
     * sheet per schema entry, with dropdowns and click-prompts). Kept
     * separate from {@see sendTemplate} so it can be exercised without the
     * HTTP/streaming layer.
     *
     * @param array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,date?:bool,prompt?:string,prompt_title?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}> $sheets
     */
    private static function buildWorkbook(array $sheets): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        // Sheet 0 is a human-readable reference of every column and its
        // allowed values. It lives on its own tab so it can never be
        // overwritten by data entry or scrolled out of existence.
        self::buildInstructionsSheet($spreadsheet, $sheets);

        $sheetIndex = 1; // 0 is the "Pokyny" reference sheet
        foreach ($sheets as $sheetCode => $sheet) {
            $ws = $spreadsheet->createSheet($sheetIndex++);
            // Sheet names are user-visible in Excel and must not contain
            // ":\\/?*[]" — our schema codes are all ASCII-safe.
            $ws->setTitle(substr($sheetCode, 0, 31));

            foreach ($sheet['columns'] as $colIdx => $col) {
                $col1      = $colIdx + 1;
                $colLetter = Coordinate::stringFromColumnIndex($col1);

                // Row 1 — column header. Data starts directly at row 2 so
                // the user is never tempted to overwrite an example/help row.
                $ws->getCell([$col1, 1])->setValue($col['header']);
                $ws->getStyle([$col1, 1, $col1, 1])->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F1F5F9'],
                    ],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
                ]);
                // Widen to fit longer, more descriptive headers (e.g. the
                // linking-number columns) while capping so a single column
                // never dominates the sheet.
                $ws->getColumnDimensionByColumn($col1)->setWidth(max(26, min(mb_strlen($col['header']) + 2, 50)));

                $range = "{$colLetter}2:{$colLetter}1001";

                if (!empty($col['text'])) {
                    // Text cells: „1,3,5" must not turn into a Slovak decimal.
                    $ws->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                } elseif (!empty($col['date_or_text'])) {
                    // A typed date renders as DD-MM-RRRR; a word stays a word.
                    $ws->getStyle($range)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
                }

                if (!empty($col['options'])) {
                    // Single-value enum → real dropdown + click-prompt + hard
                    // reject of anything off-list.
                    $dv = $ws->getCell("{$colLetter}2")->getDataValidation();
                    $dv->setType(DataValidation::TYPE_LIST);
                    $dv->setErrorStyle(DataValidation::STYLE_STOP);
                    $dv->setAllowBlank(true);
                    // PhpSpreadsheet inverts this attribute on write — true
                    // is what actually renders the in-cell dropdown arrow.
                    $dv->setShowDropDown(true);
                    $dv->setShowInputMessage(true);
                    $dv->setShowErrorMessage(true);
                    $dv->setPromptTitle('Vyber zo zoznamu');
                    // option_labels embed the human meaning into each dropdown
                    // value (e.g. "TS (Tlaková skúška)"); the importer strips
                    // the parenthetical back to the bare code on read. Without
                    // labels the offered values are the raw codes unchanged.
                    $optionValues = empty($col['option_labels'])
                        ? $col['options']
                        : array_map(
                            fn(string $opt): string => isset($col['option_labels'][$opt])
                                ? $opt . ' (' . $col['option_labels'][$opt] . ')'
                                : $opt,
                            $col['options'],
                        );
                    if (!empty($col['options_help'])) {
                        // Annotate each value with the types it applies to.
                        $parts = [];
                        foreach ($col['options'] as $opt) {
                            $note = $col['options_help'][$opt] ?? '';
                            $parts[] = $note !== '' ? "$opt ($note)" : $opt;
                        }
                        $dv->setPrompt('Povolené hodnoty: ' . implode(', ', $parts));
                    } else {
                        $dv->setPrompt('Povolené hodnoty: ' . implode(', ', $optionValues));
                    }
                    $dv->setErrorTitle('Neplatná hodnota');
                    $dv->setError('Vyber jednu z hodnôt zo zoznamu (šípka vpravo v bunke).');
                    $dv->setFormula1('"' . implode(',', $optionValues) . '"');
                    $dv->setSqref($range);
                } elseif (!empty($col['multi_options'])) {
                    // Multi-value → no native multi-select in Excel, so guide
                    // with a persistent click-prompt and the Pokyny tab.
                    $slugs = array_column($col['multi_options'], 'value');
                    $dv = $ws->getCell("{$colLetter}2")->getDataValidation();
                    $dv->setType(DataValidation::TYPE_NONE);
                    $dv->setShowInputMessage(true);
                    $dv->setPromptTitle('Viac hodnôt — oddeľ čiarkou');
                    // Prompt text is capped by Excel (~255 chars); keep it
                    // short for long lists and defer to the Pokyny sheet.
                    $prompt = count($slugs) <= 4
                        ? 'Povolené hodnoty (oddeľ čiarkou): ' . implode(', ', $slugs)
                        : 'Zadaj jednu alebo viac hodnôt oddelených čiarkou. Úplný zoznam povolených hodnôt nájdeš na hárku „Pokyny".';
                    $dv->setPrompt($prompt);
                    $dv->setSqref($range);
                } elseif (!empty($col['date'])) {
                    // Force a real date number format so the cells render as
                    // DD-MM-RRRR in (Slovak) Excel and typed values are parsed
                    // consistently as dates rather than left as ambiguous text.
                    $ws->getStyle($range)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
                    $dv = $ws->getCell("{$colLetter}2")->getDataValidation();
                    $dv->setType(DataValidation::TYPE_NONE);
                    $dv->setShowInputMessage(true);
                    $dv->setPromptTitle('Dátum');
                    $dv->setPrompt('Zadaj dátum vo formáte DD-MM-RRRR (napr. 15-01-2026).');
                    $dv->setSqref($range);
                } elseif (!empty($col['prompt'])) {
                    // Plain text column with an explanatory in-cell tooltip —
                    // used by the linking-number columns that tie items to a
                    // parent inspection, so the relationship is obvious right
                    // where the user types.
                    $dv = $ws->getCell("{$colLetter}2")->getDataValidation();
                    $dv->setType(DataValidation::TYPE_NONE);
                    $dv->setShowInputMessage(true);
                    $dv->setPromptTitle($col['prompt_title'] ?? 'Pomôcka');
                    $dv->setPrompt($col['prompt']);
                    $dv->setSqref($range);
                }
            }

            $ws->freezePane('A2');
        }

        // Open on the instructions tab so the user reads it first.
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Renders the always-visible "Pokyny" reference tab: for every data
     * sheet it lists each column, whether it is required, the kind of
     * input expected and — crucially — the exact set of allowed values
     * for dropdown and multi-value columns. This is the canonical place
     * the user can look up valid inputs; it cannot be hidden or erased by
     * filling in the data sheets.
     *
     * @param array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,date?:bool,prompt?:string,prompt_title?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}> $sheets
     */
    private static function buildInstructionsSheet(Spreadsheet $spreadsheet, array $sheets): void
    {
        $ws = $spreadsheet->createSheet(0);
        $ws->setTitle('Pokyny');
        $ws->getColumnDimensionByColumn(1)->setWidth(34); // Stĺpec
        $ws->getColumnDimensionByColumn(2)->setWidth(12); // Povinné
        $ws->getColumnDimensionByColumn(3)->setWidth(26); // Typ vstupu
        $ws->getColumnDimensionByColumn(4)->setWidth(70); // Povolené hodnoty

        $r = 1;
        $ws->getCell("A{$r}")->setValue('Pokyny pre vyplnenie a import');
        $ws->mergeCells("A{$r}:D{$r}");
        $ws->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '0F172A']],
        ]);
        $ws->getRowDimension($r)->setRowHeight(24);
        $r += 2;

        $intro = [
            'Prvý riadok každého hárka je hlavička — needituj ho. Dáta zadávaj od druhého riadka.',
            'Stĺpce označené hviezdičkou (*) sú povinné.',
            'Stĺpce typu „Výber zo zoznamu“: klikni na bunku a vyber hodnotu zo šípky vpravo. Iná hodnota sa odmietne.',
            'Stĺpce typu „Viac hodnôt“: zadaj povolené hodnoty oddelené čiarkou (napr. tlakova_skuska,plnenie).',
            'Dátumy zadávaj vo formáte DD-MM-RRRR (napr. 15-01-2026).',
        ];

        // Sheets that reference a company by IČO can create that company (and
        // its prevádzka) automatically, so the user does not have to import
        // firmy first. Surface this only on the relevant templates.
        $hasCompanyRef = false;
        foreach ($sheets as $sheet) {
            foreach ($sheet['columns'] as $col) {
                if ($col['key'] === 'company_ico') {
                    $hasCompanyRef = true;
                    break 2;
                }
            }
        }
        if ($hasCompanyRef) {
            $intro[] = 'Firma sa hľadá podľa IČO. Ak firma s daným IČO ešte v systéme neexistuje, vytvorí sa automaticky pod zadaným názvom — nemusíš ju importovať vopred.';
            $intro[] = 'IČO má prednosť pred názvom: ak firma s daným IČO už existuje, použije sa existujúca (aj keď je názov napísaný inak, napr. bez diakritiky) a nevytvorí sa duplicitná.';
            $intro[] = 'Prevádzka sa hľadá podľa názvu v rámci firmy. Ak ešte neexistuje, vytvorí sa automaticky.';
        }

        // Inspection templates split the data across the "Kontroly" sheet and
        // one "Položky" sheet per type. Spell out the linking number up front,
        // because it is the single most common point of confusion.
        if (isset($sheets['Kontroly'])) {
            $intro[] = 'Hárok „Kontroly" a hárky s položkami sa prepájajú cez číslo kontroly: na hárku „Kontroly" zadaj do stĺpca „Číslo kontroly" ľubovoľné číslo (napr. 1, 2, 3…) a to isté číslo potom uveď v stĺpci „Číslo kontroly z hárku Kontroly" pri každej položke, ktorá k danej kontrole patrí.';
            $intro[] = 'Príklad: kontrola s číslom 1 na hárku „Kontroly" → všetky jej položky majú v stĺpci „Číslo kontroly z hárku Kontroly" tiež 1.';
            $intro[] = 'Toto číslo slúži len na spárovanie v rámci tohto súboru — môžeš si ho zvoliť ľubovoľne a po importe sa nikde neukladá.';
        }

        // The book in its new format has its own three sheets, linked by the
        // number of the entry the same way.
        if (isset($sheets['Kniha_zapisy'])) {
            $intro[] = 'Zápisy do požiarnej knihy sa nezadávajú na hárok „Kontroly", ale na tri vlastné hárky: „Kniha_zapisy" (jeden riadok = jeden zápis), „Kniha_prevadzky" (jeden riadok = jedna prevádzka zápisu) a „Kniha_nedostatky" (jeden riadok = jeden nedostatok). Spájajú sa cez číslo zápisu, rovnako ako kontroly a ich položky.';
            $intro[] = 'Kniha_zapisy: spôsob 1 = zápis je zároveň preventívkou (posúva termín prevádzok), spôsob 2 = preventívky sú priložené (termín sa nemení). Prázdny spôsob = 1 pri jednej prevádzke, 2 pri viacerých. Ako v aplikácii, úvod, vykonané činnosti, vlastné činnosti a doplňujúci text patria len k spôsobu 1 a text zápisu len k spôsobu 2 — stĺpce druhého spôsobu sa neuložia (pri spôsobe 2 zostane úvod zákonná veta). Na záver a Schválil platia pri oboch. Vykonané činnosti zadaj ako čísla bodov zo zoznamu nižšie — firma a bytové družstvo majú každý svoj zoznam (druh klienta sa nastavuje pri firme; firma vytvorená importom je firma). Prázdny úvod = zákonná veta, prázdne „Schválil" = zodpovedná osoba nastavená pri firme.';
            $intro[] = 'Kniha_prevadzky: každý zápis potrebuje aspoň jednu prevádzku. Pri spôsobe 1 vyber pri každej prevádzke periodicitu 12, 6 alebo 3 mesiace. Prvá prevádzka zápisu je jeho hlavná prevádzka.';
            $intro[] = 'Kniha_nedostatky: prevádzka je povinná, keď má zápis viac prevádzok. Lehota je bezodkladne, 30d, 3m, 6m, dátum alebo prázdna (bez lehoty). Stav je predvolene „zistený"; pri „dôvod zanikol" napíš dôvod.';
            $intro[] = 'Zápisy sa naimportujú ako rozpracované, bez čísla a bez dokumentu. Priložené záznamy, fotky ani väzby na číselník sa z Excelu nedajú preniesť — doplníš ich v aplikácii.';
        }

        // Preventívne prehliadky: two sheets of their own, the app's rules.
        if (isset($sheets['Prehliadky'])) {
            $intro[] = 'Preventívne prehliadky sa nezadávajú na hárok „Kontroly", ale na dva vlastné hárky: „Prehliadky" (jeden riadok = jedna prehliadka) a „Prehliadky_nedostatky" (jeden riadok = jeden nedostatok). Spájajú sa cez číslo prehliadky, rovnako ako kontroly a ich položky.';
            $intro[] = 'Rozsah určuje, ktoré záznamy z prehliadky vzniknú: po = záznam z preventívnej protipožiarnej prehliadky, bozp = preventívna prehliadka BOZP, po_bozp = oba naraz. Pri rozsahu po sa činnosti BOZP neuložia a naopak. Vykonané činnosti zadaj ako čísla bodov zo zoznamov nižšie — sú to tie isté zoznamy, ktoré ponúka aplikácia (firma a bytové družstvo majú každý svoj zoznam PO).';
            $intro[] = 'Platia pravidlá aplikácie: na jednej prevádzke môže byť len jedna rozpracovaná prehliadka, dohodnutá lehota musí byť kratšia než zákonná a každý nedostatok potrebuje lehotu. Prehliadky sa naimportujú ako rozpracované, bez čísla a bez dokumentu; riadne kontroly popri prehliadke, prevzaté nedostatky, fotky ani väzby na číselník sa z Excelu preniesť nedajú — doplníš ich v aplikácii.';
        }

        // A bytový dom is a prevádzka of a bytové družstvo on every sheet.
        $hasFacilityRef = false;
        foreach ($sheets as $sheet) {
            foreach ($sheet['columns'] as $col) {
                if ($col['key'] === 'facility_name') {
                    $hasFacilityRef = true;
                    break 2;
                }
            }
        }
        if ($hasFacilityRef) {
            $intro[] = isset($sheets['Kontroly'])
                ? 'Stĺpec „Prevádzka" znamená prevádzku aj bytový dom — pri klientovi s druhom klienta bytove_druzstvo doň zadaj názov bytového domu (napr. Jilemnického 5 – 7). Platí to na hárku „Kontroly", na všetkých hárkoch s položkami, v požiarnej knihe aj pri prehliadkach. Ak dom ešte neexistuje, vytvorí sa — podlažia, vchody a hlavné uzávery mu doplníš na hárku „Bytove_domy" v šablóne firiem alebo priamo v aplikácii.'
                : 'Stĺpec „Prevádzka" znamená prevádzku aj bytový dom — pri klientovi s druhom klienta bytove_druzstvo doň zadaj názov bytového domu (napr. Jilemnického 5 – 7).';
        }

        if (isset($sheets['Bytove_domy'])) {
            $intro[] = 'Bytové domy sa zadávajú na vlastný hárok „Bytove_domy" a patria len klientovi, ktorý má druh klienta bytove_druzstvo. Pri takom klientovi sa popis objektu a hlavné vypínače a uzávery tlačia do záznamu z preventívnej prehliadky bytového domu podľa § 14 ods. 3 vyhlášky MV SR č. 121/2002 Z. z. Dom sa hľadá podľa názvu v rámci družstva; ak ešte neexistuje, vytvorí sa. Domu, ktorý už v aplikácii je, sa doplnia len prázdne údaje — nič vyplnené sa neprepíše.';
        }

        // Training templates split the data across the "Školenia" sheet and the
        // "Účastníci" sheet, linked the same way as inspections and their items.
        if (isset($sheets['Skolenia'])) {
            $intro[] = 'Hárok „Školenia" a hárok „Účastníci" sa prepájajú cez číslo školenia: na hárku „Školenia" zadaj do stĺpca „Číslo školenia" ľubovoľné číslo (napr. 1, 2, 3…) a to isté číslo potom uveď v stĺpci „Číslo školenia z hárku Školenia" pri každom účastníkovi daného školenia.';
            $intro[] = 'Príklad: školenie s číslom 1 na hárku „Školenia" → všetci jeho účastníci majú v stĺpci „Číslo školenia z hárku Školenia" tiež 1.';
            $intro[] = 'Toto číslo slúži len na spárovanie v rámci tohto súboru — môžeš si ho zvoliť ľubovoľne a po importe sa nikde neukladá.';
        }
        foreach ($intro as $line) {
            $ws->getCell("A{$r}")->setValue('•  ' . $line);
            $ws->mergeCells("A{$r}:D{$r}");
            $ws->getStyle("A{$r}")->applyFromArray([
                'font'      => ['color' => ['rgb' => '334155']],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
            ]);
            // Excel does not grow a merged row to its wrapped text; the merged
            // A:D is ~140 characters wide.
            $wrapped = (int) ceil(mb_strlen($line) / 130);
            if ($wrapped > 1) {
                $ws->getRowDimension($r)->setRowHeight(15 * $wrapped);
            }
            $r++;
        }
        $r++;

        foreach ($sheets as $sheet) {
            // Sheet banner.
            $ws->getCell("A{$r}")->setValue('Hárok: ' . $sheet['title']);
            $ws->mergeCells("A{$r}:D{$r}");
            $ws->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
            ]);
            $ws->getRowDimension($r)->setRowHeight(20);
            $r++;

            // Column table header.
            $headers = ['Stĺpec', 'Povinné', 'Typ vstupu', 'Povolené hodnoty / formát'];
            foreach ($headers as $i => $h) {
                $cell = Coordinate::stringFromColumnIndex($i + 1) . $r;
                $ws->getCell($cell)->setValue($h);
                $ws->getStyle($cell)->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                ]);
            }
            $r++;

            foreach ($sheet['columns'] as $col) {
                $required = str_ends_with(rtrim($col['header']), '*') ? 'áno' : '—';
                $name     = trim(preg_replace('/\s*\*\s*$/u', '', $col['header']) ?? $col['header']);

                if (!empty($col['options'])) {
                    $type = 'Výber zo zoznamu';
                    if (!empty($col['options_help'])) {
                        // One annotated value per line so the type mapping
                        // for each periodicity is easy to scan.
                        $parts = [];
                        foreach ($col['options'] as $opt) {
                            $note    = $col['options_help'][$opt] ?? '';
                            $parts[] = $note !== '' ? "$opt  —  $note" : $opt;
                        }
                        $allowed = implode("\n", $parts);
                        $lines   = count($parts);
                    } elseif (!empty($col['option_labels'])) {
                        // One code per line with its meaning spelled out.
                        $parts = [];
                        foreach ($col['options'] as $opt) {
                            $label   = $col['option_labels'][$opt] ?? '';
                            $parts[] = $label !== '' ? "$opt  —  $label" : $opt;
                        }
                        $allowed = implode("\n", $parts);
                        $lines   = count($parts);
                    } else {
                        $allowed = implode(', ', $col['options']);
                        $lines   = 1;
                    }
                } elseif (!empty($col['multi_options'])) {
                    $type  = 'Viac hodnôt (oddeľ čiarkou)';
                    $parts = [];
                    foreach ($col['multi_options'] as $opt) {
                        $parts[] = $opt['value'] . '  —  ' . $opt['label'];
                    }
                    $allowed = implode("\n", $parts);
                    $lines   = count($parts);
                } elseif (!empty($col['date'])) {
                    $type    = 'Dátum (DD-MM-RRRR)';
                    $allowed = !empty($col['hint']) ? 'napr. ' . $col['hint'] : 'napr. 15-01-2026';
                    $lines   = 1;
                } else {
                    $type    = 'Voľný text';
                    $allowed = !empty($col['hint']) ? 'napr. ' . $col['hint'] : '';
                    $lines   = 1;
                }
                // A column whose allowed values the rules above cannot express
                // (the book's numbered activity lists, a word or a date).
                if (!empty($col['pokyny_values'])) {
                    $type    = $col['pokyny_type'] ?? $type;
                    $allowed = $col['pokyny_values'];
                }
                if (!empty($col['pokyny_note'])) {
                    $allowed = ($allowed !== '' ? $allowed . "\n" : '') . $col['pokyny_note'];
                }
                if (!empty($col['pokyny_values']) || !empty($col['pokyny_note'])) {
                    // Count wrapped lines too: the column is ~70 characters wide.
                    $lines = 0;
                    foreach (explode("\n", $allowed) as $line) {
                        $lines += max(1, (int) ceil(mb_strlen($line) / 70));
                    }
                }

                $ws->getCell("A{$r}")->setValue($name);
                $ws->getCell("B{$r}")->setValue($required);
                $ws->getCell("C{$r}")->setValue($type);
                $ws->getCell("D{$r}")->setValueExplicit(
                    $allowed,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
                $ws->getStyle("A{$r}:D{$r}")->applyFromArray([
                    'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
                    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
                ]);
                if ($lines > 1) {
                    $ws->getRowDimension($r)->setRowHeight(14 * $lines + 2);
                }
                $r++;
            }
            $r += 2; // spacing before next sheet
        }

        $ws->freezePane('A2');
    }

    // ─── Imports ─────────────────────────────────────────────────────

    public static function importCompanies(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $rowsBySheet = self::readUpload(Schema::companies());

        $errors = [];
        $createdCompanies = 0;
        $createdFacilities = 0;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Build a name → id map so the Prevadzky sheet can link by
            // company IČO. The map is seeded from existing companies in
            // the tenant and extended as we insert new ones.
            $companyIdByIco = self::loadCompanyMap($accountId);
            $facilityIdByKey = self::loadFacilityMap($accountId);
            $clientTypeById = self::loadClientTypeMap($accountId);

            $insertCompany = $pdo->prepare(
                'INSERT INTO companies (account_id, name, client_type, ico, street, postal_code, city, contact)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($rowsBySheet['Firmy'] as $idx => $row) {
                $rowNum = $idx + 2; // header + 0-based offset
                $name    = self::str($row, 'name');
                $ico     = self::ico($row, 'ico');
                $address = self::str($row, 'address');
                $contact = self::str($row, 'contact');

                if ($name === null) {
                    $errors[] = ['sheet' => 'Firmy', 'row' => $rowNum, 'message' => 'Chýba názov firmy.'];
                    continue;
                }
                if ($ico === null) {
                    // IČO is the key the Prevadzky sheet links facilities by, so
                    // a company without one can never be referenced.
                    $errors[] = ['sheet' => 'Firmy', 'row' => $rowNum, 'message' => 'Chýba IČO firmy.'];
                    continue;
                }
                // Optional last column (added with the book's new format): empty
                // means a firma, as every sheet without the column meant.
                $clientTypeCell = self::str($row, 'client_type');
                $clientType = $clientTypeCell ?? \Firol\Support\ClientKind::FIRMA;
                if (!in_array($clientType, Schema::CLIENT_TYPES, true)) {
                    $errors[] = ['sheet' => 'Firmy', 'row' => $rowNum, 'message' => 'Neplatný druh klienta (firma / bytove_druzstvo).'];
                    continue;
                }
                if (isset($companyIdByIco[$ico])) {
                    // A filled type that differs from the stored one is not
                    // applied (the import never changes an existing client):
                    // say so instead of skipping the row silently.
                    $stored = $clientTypeById[$companyIdByIco[$ico]] ?? \Firol\Support\ClientKind::FIRMA;
                    if ($clientTypeCell !== null && $clientTypeCell !== $stored) {
                        $as = \Firol\Support\ClientKind::isBytovyDom($stored) ? 'bytové družstvo' : 'firma';
                        $errors[] = ['sheet' => 'Firmy', 'row' => $rowNum, 'message' => "Firma s IČO $ico už existuje ako $as — druh klienta zmeň v aplikácii."];
                        continue;
                    }
                    // Skip silently — re-uploading a sheet with an existing
                    // IČO should be idempotent on the company itself.
                    continue;
                }

                $addr = Address::parse($address);
                $insertCompany->execute([$accountId, $name, $clientType, $ico, $addr['street'], $addr['postal_code'], $addr['city'], $contact]);
                $newId = (int) $pdo->lastInsertId();
                $createdCompanies++;
                $companyIdByIco[$ico] = $newId;
                $clientTypeById[$newId] = $clientType;
                // The code of its požiarna kniha numbers, as for a firma created in the app.
                \Firol\Documents\BookNumber::assignProposal($accountId, $newId, $name);
            }

            $insertFacility = $pdo->prepare(
                'INSERT INTO facilities (account_id, company_id, name, street, postal_code, city, contact_person, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($rowsBySheet['Prevadzky'] as $idx => $row) {
                $rowNum = $idx + 2;
                $ico = self::ico($row, 'company_ico');
                $name = self::str($row, 'name');
                if ($ico === null) {
                    $errors[] = ['sheet' => 'Prevadzky', 'row' => $rowNum, 'message' => 'Chýba IČO firmy.'];
                    continue;
                }
                if ($name === null) {
                    $errors[] = ['sheet' => 'Prevadzky', 'row' => $rowNum, 'message' => 'Chýba názov prevádzky.'];
                    continue;
                }
                $companyId = $companyIdByIco[$ico] ?? null;
                if ($companyId === null) {
                    $errors[] = ['sheet' => 'Prevadzky', 'row' => $rowNum, 'message' => "Firma s IČO $ico neexistuje."];
                    continue;
                }
                $facKey = $companyId . '|' . mb_strtolower($name);
                if (isset($facilityIdByKey[$facKey])) {
                    // Same as for the company: re-uploading the sheet must not
                    // double a prevádzka that is already there.
                    continue;
                }
                $facAddr = Address::parse(self::str($row, 'address'));
                $insertFacility->execute([
                    $accountId,
                    $companyId,
                    $name,
                    $facAddr['street'],
                    $facAddr['postal_code'],
                    $facAddr['city'],
                    self::str($row, 'contact_person'),
                    self::str($row, 'notes'),
                ]);
                $facilityIdByKey[$facKey] = (int) $pdo->lastInsertId();
                $createdFacilities++;
            }

            [$createdHouses, $filledHouses] = self::importHouses(
                $pdo, $accountId, $rowsBySheet['Bytove_domy'], $companyIdByIco, $clientTypeById, $facilityIdByKey, $errors,
            );

            if ($errors !== []) {
                $pdo->rollBack();
                Response::json(['errors' => $errors, 'created' => null], 422);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[import.companies] ' . $e->getMessage());
            Response::error('Import zlyhal: ' . $e->getMessage(), 500);
        }

        Response::json([
            'created' => [
                'companies'     => $createdCompanies,
                'facilities'    => $createdFacilities,
                'houses'        => $createdHouses,
                'houses_filled' => $filledHouses,
            ],
            'errors' => [],
        ]);
    }

    /** Columns of Bytove_domy stored as plain text, with their length limits (facilities). */
    private const HOUSE_TEXT_COLUMNS = [
        'contact_person'      => ['Kontaktná osoba', 191],
        'notes'               => ['Poznámky', 4000],
        'email'               => ['E-mail správcu domu', 255],
        'main_power_location' => ['Hlavný vypínač elektriny', 255],
        'main_gas_location'   => ['Hlavný uzáver plynu', 255],
        'main_water_location' => ['Hlavný uzáver vody', 255],
        'description'         => ['Popis objektu', 4000],
        'vchody'              => ['Vchody', 255],
    ];

    /**
     * The sheet Bytove_domy: the houses of a bytové družstvo with what the
     * bytový dom form holds. The rules are the form's (FacilityController):
     * only a bytové družstvo has houses, a new house needs both floor counts,
     * a správca e-mail must be valid.
     *
     * What the app already holds wins: a house that exists (by name within the
     * družstvo, e.g. created by an earlier import of Kontroly) gets only its
     * empty fields filled; nothing stored is overwritten.
     *
     * @param list<array<string, string>> $rows
     * @param array<string,int> $companyIdByIco
     * @param array<int,string> $clientTypeById
     * @param array<string,int> $facilityIdByKey
     * @param list<array{sheet:string,row:int,message:string}> $errors
     * @return array{0:int, 1:int} houses created and existing houses filled in
     */
    private static function importHouses(
        PDO $pdo,
        int $accountId,
        array $rows,
        array $companyIdByIco,
        array $clientTypeById,
        array &$facilityIdByKey,
        array &$errors,
    ): array {
        $sheet = 'Bytove_domy';
        $created = 0;
        $filled = 0;
        $insert = $pdo->prepare(
            'INSERT INTO facilities
                (account_id, company_id, name, street, postal_code, city, email, contact_person, notes,
                 main_power_location, main_gas_location, main_water_location, description,
                 floors_above, floors_below, entrances, places, vchody)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "[]", "[]", ?)'
        );
        $load = $pdo->prepare(
            'SELECT street, postal_code, city, email, contact_person, notes,
                    main_power_location, main_gas_location, main_water_location, description,
                    floors_above, floors_below, vchody
             FROM   facilities WHERE id = ? AND account_id = ?'
        );

        foreach ($rows as $idx => $row) {
            $rowNum = $idx + 2;
            $fail = static function (string $message) use (&$errors, $sheet, $rowNum): void {
                $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => $message];
            };
            $ico = self::ico($row, 'company_ico');
            $name = self::str($row, 'name');
            if ($ico === null) {
                $fail('Chýba IČO bytového družstva.');
                continue;
            }
            if ($name === null) {
                $fail('Chýba názov bytového domu.');
                continue;
            }
            if (mb_strlen($name) > 191) {
                $fail('Názov bytového domu je príliš dlhý (najviac 191 znakov).');
                continue;
            }
            $companyId = $companyIdByIco[$ico] ?? null;
            if ($companyId === null) {
                $fail("Bytové družstvo s IČO $ico neexistuje — zadaj ho na hárku Firmy s druhom klienta bytove_druzstvo.");
                continue;
            }
            if (!\Firol\Support\ClientKind::isBytovyDom($clientTypeById[$companyId] ?? \Firol\Support\ClientKind::FIRMA)) {
                $fail("Klient s IČO $ico je firma — bytové domy má len bytové družstvo. Druh klienta zmeň v aplikácii.");
                continue;
            }

            // Floors: a number in the form's range, or empty.
            $floors = [];
            foreach (['floors_above' => [1, 40, 'nadzemných'], 'floors_below' => [0, 5, 'podzemných']] as $key => [$min, $max, $what]) {
                $raw = self::str($row, $key);
                $n = self::intOrNull($row, $key);
                if ($raw !== null && ($n === null || $n < $min || $n > $max)) {
                    $fail("Počet $what podlaží musí byť číslo od $min do $max.");
                    continue 2;
                }
                $floors[$key] = $n;
            }
            $text = [];
            foreach (self::HOUSE_TEXT_COLUMNS as $key => [$label, $max]) {
                $value = self::str($row, $key);
                if ($value !== null && mb_strlen($value) > $max) {
                    $fail("$label: text je príliš dlhý (najviac $max znakov).");
                    continue 2;
                }
                $text[$key] = $value;
            }
            if ($text['email'] !== null && !filter_var($text['email'], FILTER_VALIDATE_EMAIL)) {
                $fail('Neplatný e-mail správcu domu.');
                continue;
            }
            $addr = Address::parse(self::str($row, 'address'));

            $key = $companyId . '|' . mb_strtolower($name);
            $existingId = $facilityIdByKey[$key] ?? null;
            if ($existingId === null) {
                if ($floors['floors_above'] === null || $floors['floors_below'] === null) {
                    $fail('Pri novom bytovom dome zadaj počet nadzemných (1–40) a podzemných (0–5) podlaží.');
                    continue;
                }
                $insert->execute([
                    $accountId, $companyId, $name,
                    $addr['street'], $addr['postal_code'], $addr['city'],
                    $text['email'], $text['contact_person'], $text['notes'],
                    $text['main_power_location'], $text['main_gas_location'], $text['main_water_location'],
                    $text['description'], $floors['floors_above'], $floors['floors_below'], $text['vchody'],
                ]);
                $facilityIdByKey[$key] = (int) $pdo->lastInsertId();
                $created++;
                continue;
            }

            // An existing house: fill what is empty, keep what is stored.
            $load->execute([$existingId, $accountId]);
            $stored = $load->fetch();
            if ($stored === false) {
                continue;
            }
            $isEmpty = static fn (mixed $v): bool => $v === null || $v === '';
            $set = [];
            foreach ($text + $floors as $col => $value) {
                if ($value !== null && $isEmpty($stored[$col])) {
                    $set[$col] = $value;
                }
            }
            // The address is one value in the sheet: only into a house with none.
            if ($isEmpty($stored['street']) && $isEmpty($stored['postal_code']) && $isEmpty($stored['city'])) {
                foreach (['street', 'postal_code', 'city'] as $col) {
                    if ($addr[$col] !== null) {
                        $set[$col] = $addr[$col];
                    }
                }
            }
            if ($set === []) {
                continue;
            }
            $assign = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($set)));
            $pdo->prepare("UPDATE facilities SET $assign WHERE id = ? AND account_id = ?")
                ->execute([...array_values($set), $existingId, $accountId]);
            $filled++;
        }

        return [$created, $filled];
    }

    public static function importTrainings(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $currentUserId = Tenant::currentUserId();

        $rowsBySheet = self::readUpload(Schema::trainings());

        $errors = [];
        $createdTrainings = 0;
        $createdTrainees = 0;
        $createdTrainers = 0;
        $createdCompanies = 0;
        $createdFacilities = 0;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $companyIdByIco = self::loadCompanyMap($accountId);
            $facilityIdByKey = self::loadFacilityMap($accountId);
            $userIdByEmail = self::loadAccountUserMap($accountId);

            // Maps user "# riadok" → newly-inserted training id, so the
            // Ucastnici sheet can link rows by the human row number.
            $trainingIdByRowNo = [];

            $insertTraining = $pdo->prepare(
                'INSERT INTO trainings
                    (account_id, company_id, facility_id, type, date,
                     periodicity_value, periodicity_unit, periodicity_is_custom,
                     trainer_id, topics, duration_min, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")'
            );

            foreach ($rowsBySheet['Skolenia'] as $idx => $row) {
                $rowNum = $idx + 2;
                $rowNo = self::intOrNull($row, 'row_no');
                $companyName = self::str($row, 'company_name');
                $ico = self::ico($row, 'company_ico');
                $type = self::str($row, 'type');
                $date = self::str($row, 'date');
                if ($rowNo === null) {
                    $errors[] = ['sheet' => 'Skolenia', 'row' => $rowNum, 'message' => 'Chýba # riadok.'];
                    continue;
                }
                if ($ico === null) {
                    $errors[] = ['sheet' => 'Skolenia', 'row' => $rowNum, 'message' => 'Chýba IČO firmy.'];
                    continue;
                }
                if ($companyName === null) {
                    $errors[] = ['sheet' => 'Skolenia', 'row' => $rowNum, 'message' => 'Chýba názov firmy.'];
                    continue;
                }
                if ($type === null || !in_array($type, Schema::TRAINING_TYPES, true)) {
                    $errors[] = ['sheet' => 'Skolenia', 'row' => $rowNum, 'message' => 'Neplatný typ školenia.'];
                    continue;
                }
                if (!self::isDate($date)) {
                    $errors[] = ['sheet' => 'Skolenia', 'row' => $rowNum, 'message' => 'Neplatný dátum (formát DD-MM-RRRR).'];
                    continue;
                }
                // Same rule as the inspections sheet: any value in any unit, an
                // empty cell is „bez opakovania", a blank unit beside a count
                // means months.
                $unitCell = self::str($row, 'periodicity_unit');
                try {
                    [$periodicityValue, $periodicityUnit] = Periodicity::normalize(
                        self::intOrNull($row, 'periodicity_value'),
                        $unitCell ?? (self::intOrNull($row, 'periodicity_value') !== null ? 'mesiac' : null),
                    );
                } catch (\InvalidArgumentException $e) {
                    $errors[] = ['sheet' => 'Skolenia', 'row' => $rowNum, 'message' => $e->getMessage()];
                    continue;
                }
                // Match by IČO; create the company when it does not exist yet.
                $companyId = self::resolveOrCreateCompany(
                    $pdo, $accountId, $ico, $companyName, $companyIdByIco, $createdCompanies,
                );
                $facilityId = null;
                $facilityName = self::str($row, 'facility_name');
                if ($facilityName !== null) {
                    $facilityId = self::resolveOrCreateFacility(
                        $pdo, $accountId, $companyId, $facilityName, $facilityIdByKey, $createdFacilities,
                    );
                }
                $trainerId = null;
                $trainerEmail = self::str($row, 'trainer_email');
                if ($trainerEmail !== null) {
                    // Same as inspectors: an unknown trainer email no longer
                    // fails the import — pre-create a pending placeholder user
                    // attached to the importing account; they claim it when
                    // they register.
                    $trainerId = self::resolveOrCreateMember(
                        $pdo,
                        $accountId,
                        $trainerEmail,
                        $userIdByEmail,
                        $createdTrainers,
                    );
                }

                $insertTraining->execute([
                    $accountId, $companyId, $facilityId, $type, $date,
                    $periodicityValue, $periodicityUnit,
                    Periodicity::isCustomForTraining($type, $periodicityValue, $periodicityUnit) ? 1 : 0,
                    $trainerId,
                    self::str($row, 'topics'),
                    self::intOrNull($row, 'duration_min'),
                ]);
                $trainingIdByRowNo[$rowNo] = (int) $pdo->lastInsertId();
                $createdTrainings++;
            }

            // Účastníci are only validated once the Školenia sheet is fully
            // clean — otherwise a participant pointing at a training that
            // failed its own validation would raise a second, cascading
            // "Školenie s # N neexistuje" on top of the real error one sheet
            // over. Since the whole import is one transaction, item-level
            // errors are surfaced only after the parent sheet is sound.
            if ($errors === []) {
                $insertTrainee = $pdo->prepare(
                    'INSERT INTO trainees (training_id, fullname, position, personal_number)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($rowsBySheet['Ucastnici'] as $idx => $row) {
                    $rowNum = $idx + 2;
                    $trainingRowNo = self::intOrNull($row, 'training_row_no');
                    $fullname = self::str($row, 'fullname');
                    if ($trainingRowNo === null || $fullname === null) {
                        $errors[] = ['sheet' => 'Ucastnici', 'row' => $rowNum, 'message' => 'Chýba # riadok školenia alebo meno.'];
                        continue;
                    }
                    $trainingId = $trainingIdByRowNo[$trainingRowNo] ?? null;
                    if ($trainingId === null) {
                        $errors[] = ['sheet' => 'Ucastnici', 'row' => $rowNum, 'message' => "Školenie s # $trainingRowNo neexistuje v sheete Školenia."];
                        continue;
                    }
                    $personalNumber = self::str($row, 'personal_number');
                    if ($personalNumber !== null && mb_strlen($personalNumber) > 32) {
                        $errors[] = ['sheet' => 'Ucastnici', 'row' => $rowNum, 'message' => 'Osobné číslo môže mať najviac 32 znakov.'];
                        continue;
                    }
                    $insertTrainee->execute([
                        $trainingId, $fullname, self::str($row, 'position'), $personalNumber,
                    ]);
                    $createdTrainees++;
                }
            }

            if ($errors !== []) {
                $pdo->rollBack();
                Response::json(['errors' => $errors, 'created' => null], 422);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[import.trainings] ' . $e->getMessage());
            Response::error('Import zlyhal: ' . $e->getMessage(), 500);
        }

        // Suppress unused warning — kept for future scoping.
        unset($currentUserId);

        Response::json([
            'created' => [
                'trainings'  => $createdTrainings,
                'trainees'   => $createdTrainees,
                'trainers'   => $createdTrainers,
                'companies'  => $createdCompanies,
                'facilities' => $createdFacilities,
            ],
            'errors' => [],
        ]);
    }

    public static function importInspections(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $currentUserId = Tenant::currentUserId();

        // The upload schema also reads the legacy book sheet, so a file filled
        // in on an older template keeps importing as it did.
        $rowsBySheet = self::readUpload(Schema::inspectionsUpload());

        $errors = [];
        $createdInspections = 0;
        $createdItems = 0;
        $createdTechnicians = 0;
        $createdCompanies = 0;
        $createdFacilities = 0;
        $createdBookEntries = 0;
        $createdBookDefects = 0;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $companyIdByIco = self::loadCompanyMap($accountId);
            $facilityIdByKey = self::loadFacilityMap($accountId);
            $userIdByEmail = self::loadAccountUserMap($accountId);

            /** @var array<int, array{id:int,type:string}> $inspectionByRowNo */
            $inspectionByRowNo = [];

            $insertInspection = $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, type,
                     periodicity_value, periodicity_unit, periodicity_is_custom,
                     executed_on, inspector_user_id, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );

            foreach ($rowsBySheet['Kontroly'] as $idx => $row) {
                $rowNum = $idx + 2;
                $rowNo = self::intOrNull($row, 'row_no');
                $companyName = self::str($row, 'company_name');
                $ico = self::ico($row, 'company_ico');
                $facilityName = self::str($row, 'facility_name');
                $type = self::str($row, 'type');
                $executedOn = self::str($row, 'executed_on');

                if ($rowNo === null) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => 'Chýba # riadok.'];
                    continue;
                }
                if ($ico === null || $companyName === null || $facilityName === null || $type === null) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => 'Chýba názov firmy / IČO firmy / prevádzka / typ.'];
                    continue;
                }
                if (!array_key_exists($type, Schema::INSPECTION_RECOMMENDED_MONTHS)) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => "Neznámy typ kontroly: $type."];
                    continue;
                }
                // Periodicity is the technician's decision, so the importer
                // accepts any value in any unit — and an empty cell as "bez
                // opakovania". A blank unit beside a filled count means months,
                // which is what every sheet written before block 1 meant.
                $unitCell = self::str($row, 'periodicity_unit');
                try {
                    [$periodicityValue, $periodicityUnit] = Periodicity::normalize(
                        self::intOrNull($row, 'periodicity_value'),
                        $unitCell ?? (self::intOrNull($row, 'periodicity_value') !== null ? 'mesiac' : null),
                    );
                } catch (\InvalidArgumentException $e) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => $e->getMessage()];
                    continue;
                }
                if (!self::isDate($executedOn)) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => 'Neplatný dátum (formát DD-MM-RRRR).'];
                    continue;
                }
                // Match by IČO; create the company/facility when missing.
                $companyId = self::resolveOrCreateCompany(
                    $pdo, $accountId, $ico, $companyName, $companyIdByIco, $createdCompanies,
                );
                $facilityId = self::resolveOrCreateFacility(
                    $pdo, $accountId, $companyId, $facilityName, $facilityIdByKey, $createdFacilities,
                );

                $inspectorId = $currentUserId;
                $inspectorEmail = self::str($row, 'inspector_email');
                if ($inspectorEmail !== null) {
                    // Unknown technician emails no longer fail the import. We
                    // pre-create a pending placeholder user and attach it to
                    // the importing account so the inspection is attributed
                    // straight away; the person claims it when they register.
                    $inspectorId = self::resolveOrCreateMember(
                        $pdo,
                        $accountId,
                        $inspectorEmail,
                        $userIdByEmail,
                        $createdTechnicians,
                    );
                }

                $insertInspection->execute([
                    $accountId, $companyId, $facilityId, $type,
                    $periodicityValue, $periodicityUnit,
                    Periodicity::isCustom($type, $periodicityValue, $periodicityUnit) ? 1 : 0,
                    $executedOn, $inspectorId, self::str($row, 'notes'),
                ]);
                $newId = (int) $pdo->lastInsertId();
                $inspectionByRowNo[$rowNo] = ['id' => $newId, 'type' => $type];
                $createdInspections++;
            }

            // Item sheets are only validated once the Kontroly sheet is fully
            // clean. An item that references a broken parent would otherwise
            // raise a second, cascading error ("Kontrola # N je chybná") on
            // top of the real one on Kontroly — two messages for one cause,
            // which reads as two separate problems. Since the whole import is
            // one transaction (nothing is saved while any error stands), we
            // surface item-level errors only after the parent sheet is sound.
            if ($errors === []) {
                // Each sheet maps to one inspection type. We resolve the parent
                // by # kontrola which must reference a row in the Kontroly sheet
                // whose type matches the item sheet.
                $itemSheets = [
                    'Polozky_php'                => 'php',
                    'Polozky_hydranty'           => 'hydranty',
                    'Polozky_oprava_ts_php'      => 'oprava_ts_php',
                    'Polozky_poziarna_kniha'     => 'poziarna_kniha',
                    'Polozky_pu_akcieschopnost'  => 'pu_akcieschopnost',
                    'Polozky_pu_udrzba'          => 'pu_udrzba',
                    'Polozky_nudzove_osvetlenie' => 'nudzove_osvetlenie',
                    'Polozky_ts_hadic'           => 'ts_hadic',
                ];

                $insertItem = $pdo->prepare(
                    'INSERT INTO inspection_items (inspection_id, position, fields)
                     SELECT ?, COALESCE(MAX(position), 0) + 1, ?
                     FROM   inspection_items WHERE inspection_id = ?'
                );

                foreach ($itemSheets as $sheetCode => $expectedType) {
                    foreach ($rowsBySheet[$sheetCode] ?? [] as $idx => $row) {
                        $rowNum = $idx + 2;
                        $parentRowNo = self::intOrNull($row, 'row_no');
                        if ($parentRowNo === null) {
                            $errors[] = ['sheet' => $sheetCode, 'row' => $rowNum, 'message' => 'Chýba # kontrola.'];
                            continue;
                        }
                        $parent = $inspectionByRowNo[$parentRowNo] ?? null;
                        if ($parent === null) {
                            // The Kontroly sheet validated cleanly, so this can
                            // only be a number that was never entered there.
                            $errors[] = ['sheet' => $sheetCode, 'row' => $rowNum, 'message' => "Kontrola # $parentRowNo neexistuje v sheete Kontroly."];
                            continue;
                        }
                        if ($parent['type'] !== $expectedType) {
                            $errors[] = ['sheet' => $sheetCode, 'row' => $rowNum, 'message' => "Kontrola # $parentRowNo má typ {$parent['type']}, nie $expectedType."];
                            continue;
                        }
                        $fields = self::buildItemFields($expectedType, $row, $sheetCode, $rowNum, $errors);
                        if ($fields === null) {
                            continue;
                        }
                        $json = json_encode($fields, JSON_UNESCAPED_UNICODE);
                        if ($json === false) {
                            $errors[] = ['sheet' => $sheetCode, 'row' => $rowNum, 'message' => 'Položku sa nepodarilo serializovať.'];
                            continue;
                        }
                        $insertItem->execute([$parent['id'], $json, $parent['id']]);
                        $createdItems++;
                    }
                }
            }

            // The book in its new format: independent of Kontroly, so its
            // sheets are checked whatever Kontroly holds.
            [$createdBookEntries, $createdBookDefects] = self::importBook(
                $pdo,
                $accountId,
                $currentUserId,
                $rowsBySheet,
                $companyIdByIco,
                $facilityIdByKey,
                $userIdByEmail,
                $createdCompanies,
                $createdFacilities,
                $createdTechnicians,
                $errors,
            );

            // Preventívne prehliadky: their own two sheets, independent of the rest.
            [$createdPrehliadky, $createdPrehliadkaDefects] = self::importPrehliadky(
                $pdo,
                $accountId,
                $currentUserId,
                $rowsBySheet,
                $companyIdByIco,
                $facilityIdByKey,
                $userIdByEmail,
                $createdCompanies,
                $createdFacilities,
                $createdTechnicians,
                $errors,
            );

            if ($errors !== []) {
                $pdo->rollBack();
                Response::json(['errors' => $errors, 'created' => null], 422);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[import.inspections] ' . $e->getMessage());
            Response::error('Import zlyhal: ' . $e->getMessage(), 500);
        }

        Response::json([
            'created' => [
                'inspections'  => $createdInspections,
                'items'        => $createdItems,
                'prehliadky'   => $createdPrehliadky,
                'prehliadka_defects' => $createdPrehliadkaDefects,
                'book_entries' => $createdBookEntries,
                'book_defects' => $createdBookDefects,
                'technicians'  => $createdTechnicians,
                'companies'    => $createdCompanies,
                'facilities'   => $createdFacilities,
            ],
            'errors' => [],
        ]);
    }

    /**
     * Imports the sheets Kniha_zapisy / Kniha_prevadzky / Kniha_nedostatky as
     * požiarna kniha entries in the new format ({@see BookEntry}): drafts with
     * no number and no document, like every imported úkon. Every value passes
     * the validation of the app's own save — the header through
     * {@see BookEntry::validateShape()} and {@see BookEntry::applyContext()},
     * the prevádzky through {@see BookEntry::readFacilities()}, each defect
     * through {@see PrehliadkaDefects::validate()} with the book's options —
     * and a failing row is reported with its sheet and row.
     *
     * As with Kontroly and their items, a child sheet is checked only once its
     * parent is clean, so one mistake is not reported twice. Attached records,
     * photos and catalogue links cannot come from Excel and stay empty.
     *
     * @param array<string, list<array<string, string>>> $rowsBySheet
     * @param array<string,int> $companyIdByIco
     * @param array<string,int> $facilityIdByKey
     * @param array<string,int> $userIdByEmail
     * @param list<array{sheet:string,row:int,message:string}> $errors
     * @return array{0:int, 1:int} entries and defects created
     */
    private static function importBook(
        PDO $pdo,
        int $accountId,
        int $currentUserId,
        array $rowsBySheet,
        array &$companyIdByIco,
        array &$facilityIdByKey,
        array &$userIdByEmail,
        int &$createdCompanies,
        int &$createdFacilities,
        int &$createdTechnicians,
        array &$errors,
    ): array {
        $entryRows = $rowsBySheet['Kniha_zapisy'] ?? [];
        $placeRows = $rowsBySheet['Kniha_prevadzky'] ?? [];
        $defectRows = $rowsBySheet['Kniha_nedostatky'] ?? [];
        if ($entryRows === [] && $placeRows === [] && $defectRows === []) {
            return [0, 0];
        }
        $errorsBefore = count($errors);
        $fail = static function (string $sheet, int $row, string $message) use (&$errors): void {
            $errors[] = ['sheet' => $sheet, 'row' => $row, 'message' => $message];
        };

        // ── Kniha_zapisy ────────────────────────────────────────────────
        /** @var array<int, array<string, mixed>> $entries number of the entry → parsed row */
        $entries = [];
        /** @var array<int, true> $usedNumbers every number seen so far, valid row or not */
        $usedNumbers = [];
        foreach ($entryRows as $idx => $row) {
            $sheet = 'Kniha_zapisy';
            $rowNum = $idx + 2;
            $rowNo = self::intOrNull($row, 'row_no');
            if ($rowNo === null) {
                $fail($sheet, $rowNum, 'Chýba číslo zápisu.');
                continue;
            }
            // Recorded before the row's other checks, so a second row with the
            // number of a row that failed is reported too.
            if (isset($usedNumbers[$rowNo])) {
                $fail($sheet, $rowNum, "Číslo zápisu $rowNo je na hárku Kniha_zapisy už použité.");
                continue;
            }
            $usedNumbers[$rowNo] = true;
            $companyName = self::str($row, 'company_name');
            $ico = self::ico($row, 'company_ico');
            if ($ico === null || $companyName === null) {
                $fail($sheet, $rowNum, 'Chýba názov firmy / IČO firmy.');
                continue;
            }
            $executedOn = self::str($row, 'executed_on');
            if (!self::isDate($executedOn)) {
                $fail($sheet, $rowNum, 'Neplatný dátum (formát DD-MM-RRRR).');
                continue;
            }
            $sposobCell = self::str($row, 'sposob');
            if ($sposobCell !== null && !in_array($sposobCell, ['1', '2'], true)) {
                $fail($sheet, $rowNum, 'Spôsob zápisu môže byť 1 alebo 2 (alebo prázdny).');
                continue;
            }
            $numbersCell = self::str($row, 'cinnosti') ?? '';
            $numbers = $numbersCell === '' ? [] : preg_split('/[\s,;]+/u', $numbersCell, -1, PREG_SPLIT_NO_EMPTY);
            $numbers = is_array($numbers) ? $numbers : [];
            $badNumber = false;
            foreach ($numbers as $n) {
                if (!ctype_digit($n)) {
                    $badNumber = true;
                    break;
                }
            }
            if ($badNumber) {
                $fail($sheet, $rowNum, 'Vykonané činnosti zadaj ako čísla bodov oddelené čiarkou (napr. 1,3,5).');
                continue;
            }

            $companyId = self::resolveOrCreateCompany(
                $pdo, $accountId, $ico, $companyName, $companyIdByIco, $createdCompanies,
            );
            $clientType = self::companyClientType($pdo, $accountId, $companyId);
            // The numbers point into the list of the client type (spec 6.1 /
            // 6.2); the texts themselves contain commas, so they are not typed.
            $list = BookEntry::activitiesFor($clientType);
            $labels = [];
            foreach ($numbers as $n) {
                $i = (int) $n;
                if ($i < 1 || $i > count($list)) {
                    $who = $clientType === 'bytove_druzstvo' ? 'bytové družstvo' : 'firmu';
                    $fail($sheet, $rowNum, "Bod č. $i nie je v zozname činností pre $who (1–" . count($list) . ').');
                    continue 2;
                }
                $labels[] = $list[$i - 1];
            }
            $ownCell = self::str($row, 'vlastne_cinnosti') ?? '';
            $own = $ownCell === '' ? [] : array_values(array_filter(array_map('trim', explode('|', $ownCell)), static fn (string $a): bool => $a !== ''));

            $inspectorId = $currentUserId;
            $inspectorEmail = self::str($row, 'inspector_email');
            if ($inspectorEmail !== null) {
                $inspectorId = self::resolveOrCreateMember(
                    $pdo, $accountId, $inspectorEmail, $userIdByEmail, $createdTechnicians,
                );
            }

            $entries[$rowNo] = [
                'row'         => $rowNum,
                'company_id'  => $companyId,
                'client_type' => $clientType,
                'executed_on' => $executedOn,
                'sposob'      => $sposobCell !== null ? (int) $sposobCell : null,
                'inspector'   => $inspectorId,
                // Empty úvod / schválil stay null: applyContext() fills the
                // legal sentence and the client's person, as on a new entry.
                'raw'         => [
                    'uvod'             => self::str($row, 'uvod'),
                    'cinnosti'         => $labels,
                    'vlastne_cinnosti' => $own,
                    'text_zapisu'      => self::str($row, 'text_zapisu') ?? '',
                    'doplnujuci_text'  => self::str($row, 'doplnujuci_text') ?? '',
                    'zaver'            => self::str($row, 'zaver') ?? '',
                    'schvalil'         => self::str($row, 'schvalil'),
                ],
                'places'      => [],
            ];
        }
        if (count($errors) > $errorsBefore) {
            return [0, 0];
        }

        // ── Kniha_prevadzky ─────────────────────────────────────────────
        foreach ($placeRows as $idx => $row) {
            $sheet = 'Kniha_prevadzky';
            $rowNum = $idx + 2;
            $rowNo = self::intOrNull($row, 'row_no');
            if ($rowNo === null) {
                $fail($sheet, $rowNum, 'Chýba číslo zápisu.');
                continue;
            }
            if (!isset($entries[$rowNo])) {
                $fail($sheet, $rowNum, "Zápis č. $rowNo neexistuje na hárku Kniha_zapisy.");
                continue;
            }
            $name = self::str($row, 'facility_name');
            if ($name === null) {
                $fail($sheet, $rowNum, 'Chýba názov prevádzky.');
                continue;
            }
            $months = self::str($row, 'periodicity');
            try {
                [$value, $unit] = BookEntry::periodicity($months, $months !== null ? 'mesiac' : null);
            } catch (\InvalidArgumentException $e) {
                $fail($sheet, $rowNum, $e->getMessage());
                continue;
            }
            $facilityId = self::resolveOrCreateFacility(
                $pdo, $accountId, (int) $entries[$rowNo]['company_id'], $name, $facilityIdByKey, $createdFacilities,
            );
            $entries[$rowNo]['places'][] = [
                'facility_id'       => $facilityId,
                'periodicity_value' => $value,
                'periodicity_unit'  => $unit,
                'row'               => $rowNum,
                'name'              => $name,
            ];
        }
        if (count($errors) > $errorsBefore) {
            return [0, 0];
        }

        // ── The entries ─────────────────────────────────────────────────
        $insertEntry = $pdo->prepare(
            'INSERT INTO inspections
                (account_id, company_id, facility_id, type,
                 periodicity_value, periodicity_unit, periodicity_is_custom,
                 executed_on, inspector_user_id, status, notes,
                 is_preventive_inspection, details)
             VALUES (?, ?, ?, ?, NULL, NULL, 0, ?, ?, "draft", NULL, ?, ?)'
        );
        $created = 0;
        foreach ($entries as $rowNo => $entry) {
            $sheet = 'Kniha_zapisy';
            if ($entry['places'] === []) {
                $fail($sheet, $entry['row'], "Zápis č. $rowNo nemá žiadnu prevádzku — doplň ju na hárku Kniha_prevadzky.");
                continue;
            }
            try {
                // Same rules as the app: deduplicated, the first row wins.
                $rows = BookEntry::readFacilities(array_map(static fn (array $p): array => [
                    'facility_id'       => $p['facility_id'],
                    'periodicity_value' => $p['periodicity_value'],
                    'periodicity_unit'  => $p['periodicity_unit'],
                ], $entry['places']));
            } catch (\InvalidArgumentException $e) {
                $fail($sheet, $entry['row'], $e->getMessage());
                continue;
            }
            $ids = array_map(static fn (array $r): int => $r['facility_id'], $rows);
            $sposob = $entry['sposob'] ?? BookEntry::defaultSposob(count($rows));
            // Spôsob 1 moves the term of every prevádzka, so each needs one —
            // the app refuses step 1 and the PDF without it.
            if ($sposob === 1) {
                $missing = false;
                // Only the rows readFacilities() keeps (the first per prevádzka):
                // a repeated prevádzka row is ignored there, so here as well.
                $kept = [];
                foreach ($entry['places'] as $p) {
                    $kept[$p['facility_id']] ??= $p;
                }
                foreach ($kept as $p) {
                    if ($p['periodicity_value'] === null) {
                        $fail('Kniha_prevadzky', $p['row'], "Zápis č. $rowNo je spôsobom 1 — vyber periodicitu 12, 6 alebo 3 mesiace.");
                        $missing = true;
                    }
                }
                if ($missing) {
                    continue;
                }
            }
            if (BookEntry::facilityNames($accountId, (int) $entry['company_id'], $ids) === null) {
                $fail($sheet, $entry['row'], 'Firma alebo prevádzka sa nenašla.');
                continue;
            }
            // What an entry created in the app holds: step 1 stores the legal
            // Úvod, no activities and empty texts; step 2 then edits only the
            // fields of the spôsob in force (spôsob 1: Úvod, činnosti, vlastné
            // činnosti, doplňujúci text; spôsob 2: text zápisu). The other
            // spôsob's columns are left out, so nothing hidden is stored.
            $raw = $entry['raw'];
            if ($sposob === 1) {
                $raw['text_zapisu'] = '';
            } else {
                $raw['uvod'] = null;
                $raw['cinnosti'] = [];
                $raw['vlastne_cinnosti'] = [];
                $raw['doplnujuci_text'] = '';
            }
            try {
                $details = BookEntry::validateShape([
                    'format'       => BookEntry::FORMAT,
                    'sposob'       => $sposob,
                    // An explicit spôsob in the file is the technician's choice,
                    // as a tapped card is on step 1.
                    'sposob_rucne' => $entry['sposob'] !== null,
                    'zaznamy'      => [],
                ] + $raw);
                $details = BookEntry::applyContext($details, [
                    'account_id'   => $accountId,
                    'company_id'   => (int) $entry['company_id'],
                    'client_type'  => $entry['client_type'],
                    'facility_ids' => $ids,
                    'stored'       => null,
                    'creating'     => true,
                ]);
            } catch (\InvalidArgumentException $e) {
                $fail($sheet, $entry['row'], $e->getMessage());
                continue;
            }

            // The first prevádzka of the entry is its primary one.
            $insertEntry->execute([
                $accountId,
                (int) $entry['company_id'],
                $ids[0],
                BookEntry::TYPE,
                $entry['executed_on'],
                (int) $entry['inspector'],
                $sposob === 1 ? 1 : 0,
                \Firol\Support\InspectionDetails::encode($details),
            ]);
            $inspectionId = (int) $pdo->lastInsertId();
            BookEntry::writeFacilities($pdo, $accountId, $inspectionId, $rows);
            BookEntry::syncHeader($pdo, $inspectionId);
            $entries[$rowNo]['inspection_id'] = $inspectionId;
            $entries[$rowNo]['facility_ids'] = $ids;
            $created++;
        }
        if (count($errors) > $errorsBefore) {
            return [0, 0];
        }

        // ── Kniha_nedostatky ────────────────────────────────────────────
        $insertDefect = $pdo->prepare(
            'INSERT INTO inspection_items (inspection_id, position, fields)
             SELECT ?, COALESCE(MAX(position), 0) + 1, ?
             FROM   inspection_items WHERE inspection_id = ?'
        );
        $defects = 0;
        foreach ($defectRows as $idx => $row) {
            $sheet = 'Kniha_nedostatky';
            $rowNum = $idx + 2;
            $rowNo = self::intOrNull($row, 'row_no');
            if ($rowNo === null) {
                $fail($sheet, $rowNum, 'Chýba číslo zápisu.');
                continue;
            }
            $entry = $entries[$rowNo] ?? null;
            if ($entry === null) {
                $fail($sheet, $rowNum, "Zápis č. $rowNo neexistuje na hárku Kniha_zapisy.");
                continue;
            }
            /** @var list<int> $ids */
            $ids = $entry['facility_ids'];

            // The prevádzka by name among the entry's own.
            $facilityId = null;
            $name = self::str($row, 'facility_name');
            if ($name !== null) {
                foreach ($entry['places'] as $p) {
                    if (mb_strtolower($p['name']) === mb_strtolower($name) && in_array($p['facility_id'], $ids, true)) {
                        $facilityId = $p['facility_id'];
                        break;
                    }
                }
                if ($facilityId === null) {
                    $fail($sheet, $rowNum, "Prevádzka „{$name}\" nie je medzi prevádzkami zápisu č. $rowNo.");
                    continue;
                }
            }

            $body = [
                'title'         => self::str($row, 'title'),
                'description'   => self::str($row, 'description'),
                // Free text from Excel is the „vlastný popis" of the place.
                'where'         => ['detail' => self::str($row, 'place')],
                'device_manual' => self::str($row, 'device_manual'),
                'regulation'    => self::str($row, 'regulation'),
                'justification' => self::str($row, 'justification'),
                'measure'       => self::str($row, 'measure'),
                'reason'        => self::str($row, 'reason'),
            ];
            if ($facilityId !== null) {
                $body['facility_id'] = $facilityId;
            }

            $deadline = self::str($row, 'deadline');
            if ($deadline !== null) {
                $choice = mb_strtolower($deadline);
                if (in_array($choice, Schema::BOOK_DEADLINE_CHOICES, true)) {
                    $body['deadline_choice'] = $choice;
                } elseif (self::isDate($deadline)) {
                    $body['deadline_choice'] = 'custom';
                    $body['deadline_date'] = $deadline;
                } elseif (self::looksLikeDate($deadline)) {
                    $fail($sheet, $rowNum, 'Neplatný dátum (formát DD-MM-RRRR).');
                    continue;
                } else {
                    $fail($sheet, $rowNum, 'Lehota môže byť bezodkladne, 30d, 3m, 6m alebo dátum DD-MM-RRRR (alebo prázdna).');
                    continue;
                }
            }

            $status = self::str($row, 'status');
            if ($status !== null) {
                // The code or the screen's label (zistený, odstránený, …).
                $code = array_search(mb_strtolower($status), Schema::BOOK_DEFECT_STATUS_LABELS, true);
                $status = is_string($code) ? $code : $status;
                if (!in_array($status, PrehliadkaDefects::STATUSES, true)) {
                    $fail($sheet, $rowNum, 'Stav môže byť zisteny, odstraneny, trva alebo zanik (alebo prázdny).');
                    continue;
                }
                $body['status'] = $status;
            }

            try {
                $fields = PrehliadkaDefects::validate(
                    $body,
                    (string) $entry['executed_on'],
                    $entry['client_type'] === 'bytove_druzstvo',
                    // Historical book defects: „zanik" stays accepted — it is
                    // history, not a new defect entered in the app (answer 6 B).
                    ['deadline_optional' => true, 'facility_ids' => $ids, 'allow_new_zanik' => true],
                );
            } catch (\InvalidArgumentException $e) {
                $fail($sheet, $rowNum, $e->getMessage());
                continue;
            }
            // As InspectionItemController stores a book defect: scope po,
            // nothing carried over, the prevádzka stamped when there is one.
            $fields['scope'] = 'po';
            $fields['persists_from'] = null;
            if (count($ids) === 1) {
                $fields['facility_id'] = $ids[0];
            }
            $json = json_encode($fields, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $fail($sheet, $rowNum, 'Nedostatok sa nepodarilo uložiť.');
                continue;
            }
            $insertDefect->execute([(int) $entry['inspection_id'], $json, (int) $entry['inspection_id']]);
            $defects++;
        }

        return [$created, $defects];
    }

    /**
     * Imports the sheets Prehliadky / Prehliadky_nedostatky as preventívne
     * prehliadky: drafts with no number and no document, like every imported
     * úkon. Wherever the app has a rule, the app's rule applies:
     *
     *  - the header passes {@see PrehliadkaDetails::validate()}; the activities
     *    are the numbers of the screen's own lists
     *    ({@see PrehliadkaActivities::defaults()}), vlastné činnosti join the
     *    firm's list as on the screen;
     *  - an empty zákonná lehota is what step 1 presets: the last prehliadka of
     *    the prevádzka (with its dohodnutá lehota), else 12 for a bytový dom and
     *    3 for a firma; a dohodnutá lehota must be shorter than the zákonná;
     *  - one draft prehliadka per prevádzka — step 1 offers only to continue
     *    a draft that exists;
     *  - each defect passes {@see PrehliadkaDefects::validate()} with the
     *    prehliadka's options: the lehota is required, a new defect cannot be
     *    „dôvod zanikol", and only the records of the prehliadka's scope are
     *    offered.
     *
     * A child sheet is checked only once its parent is clean, so one mistake
     * is not reported twice. Riadne kontroly, carried-over defects, photos and
     * catalogue links cannot come from Excel and stay empty.
     *
     * @param array<string, list<array<string, string>>> $rowsBySheet
     * @param array<string,int> $companyIdByIco
     * @param array<string,int> $facilityIdByKey
     * @param array<string,int> $userIdByEmail
     * @param list<array{sheet:string,row:int,message:string}> $errors
     * @return array{0:int, 1:int} prehliadky and defects created
     */
    private static function importPrehliadky(
        PDO $pdo,
        int $accountId,
        int $currentUserId,
        array $rowsBySheet,
        array &$companyIdByIco,
        array &$facilityIdByKey,
        array &$userIdByEmail,
        int &$createdCompanies,
        int &$createdFacilities,
        int &$createdTechnicians,
        array &$errors,
    ): array {
        $headRows = $rowsBySheet['Prehliadky'] ?? [];
        $defectRows = $rowsBySheet['Prehliadky_nedostatky'] ?? [];
        if ($headRows === [] && $defectRows === []) {
            return [0, 0];
        }
        $errorsBefore = count($errors);
        $fail = static function (string $sheet, int $row, string $message) use (&$errors): void {
            $errors[] = ['sheet' => $sheet, 'row' => $row, 'message' => $message];
        };

        // ── Prehliadky ──────────────────────────────────────────────────
        $insert = $pdo->prepare(
            'INSERT INTO inspections
                (account_id, company_id, facility_id, type,
                 periodicity_value, periodicity_unit, periodicity_is_custom,
                 executed_on, inspector_user_id, status, notes,
                 is_preventive_inspection, details, billing_mode, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, "mesiac", ?, ?, ?, "draft", NULL, 1, ?, ?, ?)'
        );
        $draftAt = $pdo->prepare(
            'SELECT 1 FROM inspections
             WHERE  account_id = ? AND facility_id = ? AND type = ?
               AND  status = "draft" AND archived_at IS NULL
             LIMIT  1'
        );
        /** @var array<int, array<string, mixed>> $prehliadky number of the prehliadka → what its defects need */
        $prehliadky = [];
        /** @var array<int, true> $usedNumbers */
        $usedNumbers = [];
        /** @var array<int, true> $facilitiesInFile prevádzky that got a prehliadka from this file */
        $facilitiesInFile = [];
        $created = 0;
        foreach ($headRows as $idx => $row) {
            $sheet = 'Prehliadky';
            $rowNum = $idx + 2;
            $rowNo = self::intOrNull($row, 'row_no');
            if ($rowNo === null) {
                $fail($sheet, $rowNum, 'Chýba číslo prehliadky.');
                continue;
            }
            if (isset($usedNumbers[$rowNo])) {
                $fail($sheet, $rowNum, "Číslo prehliadky $rowNo je na hárku Prehliadky už použité.");
                continue;
            }
            $usedNumbers[$rowNo] = true;

            $companyName = self::str($row, 'company_name');
            $ico = self::ico($row, 'company_ico');
            $facilityName = self::str($row, 'facility_name');
            if ($ico === null || $companyName === null || $facilityName === null) {
                $fail($sheet, $rowNum, 'Chýba názov firmy / IČO firmy / prevádzka.');
                continue;
            }
            $executedOn = self::str($row, 'executed_on');
            if (!self::isDate($executedOn)) {
                $fail($sheet, $rowNum, 'Neplatný dátum (formát DD-MM-RRRR).');
                continue;
            }
            $scopeCell = self::str($row, 'scope') ?? 'po';
            if (!isset(Schema::PREHLIADKA_SCOPE_LABELS[$scopeCell])) {
                $fail($sheet, $rowNum, 'Rozsah prehliadky môže byť po, bozp alebo po_bozp (alebo prázdny).');
                continue;
            }
            $scope = ['po' => $scopeCell !== 'bozp', 'bozp' => $scopeCell !== 'po'];

            $zakonCell = self::str($row, 'lehota_zakon');
            if ($zakonCell !== null && !in_array($zakonCell, ['12', '6', '3'], true)) {
                $fail($sheet, $rowNum, 'Zákonná lehota môže byť 12, 6 alebo 3 mesiace (alebo prázdna).');
                continue;
            }
            $vlastnaCell = self::str($row, 'lehota_vlastna');
            $vlastna = self::intOrNull($row, 'lehota_vlastna');
            if ($vlastnaCell !== null && ($vlastna === null || $vlastna < 1)) {
                $fail($sheet, $rowNum, 'Dohodnutá kratšia lehota je počet mesiacov (napr. 2).');
                continue;
            }
            $numbersPo = self::pointNumbers(self::str($row, 'activities_po'));
            $numbersBozp = self::pointNumbers(self::str($row, 'activities_bozp'));
            if ($numbersPo === null || $numbersBozp === null) {
                $fail($sheet, $rowNum, 'Vykonané činnosti zadaj ako čísla bodov oddelené čiarkou (napr. 1,3,5).');
                continue;
            }

            $companyId = self::resolveOrCreateCompany(
                $pdo, $accountId, $ico, $companyName, $companyIdByIco, $createdCompanies,
            );
            $clientType = self::companyClientType($pdo, $accountId, $companyId) ?? \Firol\Support\ClientKind::FIRMA;
            $isDom = \Firol\Support\ClientKind::isBytovyDom($clientType);
            $facilityId = self::resolveOrCreateFacility(
                $pdo, $accountId, $companyId, $facilityName, $facilityIdByKey, $createdFacilities,
            );

            // One draft per prevádzka, as step 1 allows. The file's own repeat
            // first: its earlier row already stored a draft here.
            if (isset($facilitiesInFile[$facilityId])) {
                $fail($sheet, $rowNum, "Prevádzka „{$facilityName}\" má na hárku Prehliadky viac prehliadok — rozpracovaná môže byť len jedna, ako v aplikácii.");
                continue;
            }
            $draftAt->execute([$accountId, $facilityId, PrehliadkaDetails::TYPE]);
            if ($draftAt->fetchColumn() !== false) {
                $where = $isDom ? "V bytovom dome „{$facilityName}\"" : "Na prevádzke „{$facilityName}\"";
                $fail($sheet, $rowNum, "$where už je rozpracovaná preventívna prehliadka — dokonči ju alebo zahoď v aplikácii.");
                continue;
            }
            $facilitiesInFile[$facilityId] = true;

            // Lehoty: an empty zákonná is step 1's preset.
            if ($zakonCell !== null) {
                $zakon = (int) $zakonCell;
            } else {
                $last = PrehliadkaContext::forFacility($accountId, $facilityId)['last'];
                $lastZakon = $last['lehota_zakon'] ?? null;
                $zakon = in_array($lastZakon, [3, 6, 12], true) ? (int) $lastZakon : ($isDom ? 12 : 3);
                if ($vlastnaCell === null && is_int($last['lehota_vlastna'] ?? null)) {
                    $vlastna = (int) $last['lehota_vlastna'];
                }
            }
            if ($vlastna !== null && $vlastna >= $zakon) {
                $fail($sheet, $rowNum, "Dohodnutá lehota ($vlastna mes.) nie je kratšia než zákonná ($zakon mes.). Dlhšiu lehotu než zákonnú nie je možné dohodnúť.");
                continue;
            }

            // Activities: the numbers of the screen's lists, then the own ones.
            $activities = [];
            foreach ([PrehliadkaActivities::PO => $numbersPo, PrehliadkaActivities::BOZP => $numbersBozp] as $kind => $numbers) {
                $activities[$kind] = [];
                if (!$scope[$kind === PrehliadkaActivities::PO ? 'po' : 'bozp']) {
                    // Not part of this prehliadka: ignored, as the setup drops them.
                    continue;
                }
                $list = PrehliadkaActivities::defaults($kind, $clientType);
                foreach ($numbers as $i) {
                    if ($i < 1 || $i > count($list)) {
                        $set = $kind === PrehliadkaActivities::PO ? 'PO' : 'BOZP';
                        $who = $kind === PrehliadkaActivities::PO ? ($isDom ? ' pre bytové družstvo' : ' pre firmu') : '';
                        $fail($sheet, $rowNum, "Bod č. $i nie je v zozname činností $set$who (1–" . count($list) . ').');
                        continue 3;
                    }
                    $activities[$kind][] = $list[$i - 1];
                }
                // „Vlastné činnosti" belong to the PO set when PO is done, else
                // to BOZP; the BOZP ones of a po_bozp prehliadka have the last column.
                $ownKey = $kind === PrehliadkaActivities::PO || !$scope['po'] ? 'vlastne' : 'vlastne_bozp';
                $ownCell = self::str($row, $ownKey) ?? '';
                foreach (explode('|', $ownCell) as $label) {
                    $label = trim($label);
                    if ($label === '') {
                        continue;
                    }
                    // A text of the default list is that point, not an addition.
                    $default = null;
                    foreach ($list as $d) {
                        if (mb_strtolower($d) === mb_strtolower($label)) {
                            $default = $d;
                            break;
                        }
                    }
                    if ($default !== null) {
                        $activities[$kind][] = $default;
                        continue;
                    }
                    try {
                        $added = PrehliadkaActivities::add($accountId, $companyId, $kind, $label, $currentUserId);
                    } catch (\InvalidArgumentException $e) {
                        $fail($sheet, $rowNum, $e->getMessage());
                        continue 3;
                    }
                    $activities[$kind][] = $added['label'];
                }
            }

            try {
                $details = PrehliadkaDetails::validate([
                    'scope'              => $scope,
                    'routine'            => [],
                    'lehota_zakon'       => $zakon,
                    'lehota_vlastna'     => $vlastna,
                    'poznamka'           => self::str($row, 'poznamka'),
                    'oboznameny_meno'    => self::str($row, 'oboznameny_meno'),
                    'oboznameny_funkcia' => self::str($row, 'oboznameny_funkcia'),
                    'activities_po'      => $activities[PrehliadkaActivities::PO],
                    'activities_bozp'    => $activities[PrehliadkaActivities::BOZP],
                ]);
            } catch (\InvalidArgumentException $e) {
                $fail($sheet, $rowNum, $e->getMessage());
                continue;
            }

            $inspectorId = $currentUserId;
            $inspectorEmail = self::str($row, 'inspector_email');
            if ($inspectorEmail !== null) {
                $inspectorId = self::resolveOrCreateMember(
                    $pdo, $accountId, $inspectorEmail, $userIdByEmail, $createdTechnicians,
                );
            }

            // As step 1 creates it: the periodicity is the dohodnutá lehota,
            // else the zákonná, in months; the režim is the firm's.
            $months = $vlastna ?? $zakon;
            $insert->execute([
                $accountId,
                $companyId,
                $facilityId,
                PrehliadkaDetails::TYPE,
                $months,
                Periodicity::isCustom(PrehliadkaDetails::TYPE, $months, 'mesiac') ? 1 : 0,
                $executedOn,
                $inspectorId,
                \Firol\Support\InspectionDetails::encode($details),
                \Firol\Support\Invoicing::companyMode($companyId),
                $currentUserId,
            ]);
            $prehliadky[$rowNo] = [
                'inspection_id' => (int) $pdo->lastInsertId(),
                'executed_on'   => $executedOn,
                'is_dom'        => $isDom,
                'scope'         => $scope,
                'scope_cell'    => $scopeCell,
            ];
            $created++;
        }
        if (count($errors) > $errorsBefore) {
            return [0, 0];
        }

        // ── Prehliadky_nedostatky ───────────────────────────────────────
        $insertDefect = $pdo->prepare(
            'INSERT INTO inspection_items (inspection_id, position, fields)
             SELECT ?, COALESCE(MAX(position), 0) + 1, ?
             FROM   inspection_items WHERE inspection_id = ?'
        );
        $statusLabels = ['zisteny' => 'zistený', 'odstraneny' => 'odstránený', 'trva' => 'trvá', 'zanik' => 'dôvod zanikol'];
        $defects = 0;
        foreach ($defectRows as $idx => $row) {
            $sheet = 'Prehliadky_nedostatky';
            $rowNum = $idx + 2;
            $rowNo = self::intOrNull($row, 'row_no');
            if ($rowNo === null) {
                $fail($sheet, $rowNum, 'Chýba číslo prehliadky.');
                continue;
            }
            $parent = $prehliadky[$rowNo] ?? null;
            if ($parent === null) {
                $fail($sheet, $rowNum, "Prehliadka č. $rowNo neexistuje na hárku Prehliadky.");
                continue;
            }

            // The record: only those of the prehliadka's scope, as the screen offers.
            $scopeCell = self::str($row, 'scope');
            if ($scopeCell === null) {
                $defectScope = $parent['scope']['po'] ? 'po' : 'bo';
            } else {
                $defectScope = Schema::PREHLIADKA_DEFECT_SCOPES[mb_strtolower($scopeCell)] ?? null;
                if ($defectScope === null) {
                    $fail($sheet, $rowNum, 'Do ktorého záznamu patrí: po, bozp alebo oboje (alebo prázdne).');
                    continue;
                }
                $offered = match ($defectScope) {
                    'po'    => $parent['scope']['po'],
                    'bo'    => $parent['scope']['bozp'],
                    default => $parent['scope']['po'] && $parent['scope']['bozp'],
                };
                if (!$offered) {
                    $fail($sheet, $rowNum, "Prehliadka č. $rowNo má rozsah {$parent['scope_cell']} — nedostatok nemôže patriť do záznamu „$scopeCell\".");
                    continue;
                }
            }

            $body = [
                'title'         => self::str($row, 'title'),
                'description'   => self::str($row, 'description'),
                'scope'         => $defectScope,
                // Free text from Excel is the „vlastný popis" of the place.
                'where'         => ['detail' => self::str($row, 'place')],
                'device_manual' => self::str($row, 'device_manual'),
                'responsible'   => self::str($row, 'responsible'),
                'regulation'    => self::str($row, 'regulation'),
                'justification' => self::str($row, 'justification'),
                'measure'       => self::str($row, 'measure'),
                'reason'        => self::str($row, 'reason'),
            ];

            $deadline = self::str($row, 'deadline');
            if ($deadline === null) {
                $fail($sheet, $rowNum, 'Chýba lehota — bezodkladne, 30d, 3m, 6m alebo dátum DD-MM-RRRR.');
                continue;
            }
            $choice = mb_strtolower($deadline);
            if (in_array($choice, Schema::BOOK_DEADLINE_CHOICES, true)) {
                $body['deadline_choice'] = $choice;
            } elseif (self::isDate($deadline)) {
                $body['deadline_choice'] = 'custom';
                $body['deadline_date'] = $deadline;
            } elseif (self::looksLikeDate($deadline)) {
                $fail($sheet, $rowNum, 'Neplatný dátum (formát DD-MM-RRRR).');
                continue;
            } else {
                $fail($sheet, $rowNum, 'Lehota môže byť bezodkladne, 30d, 3m, 6m alebo dátum DD-MM-RRRR.');
                continue;
            }

            $status = self::str($row, 'status');
            if ($status !== null) {
                // The code or the screen's label (zistený, odstránený, …).
                $code = array_search(mb_strtolower($status), $statusLabels, true);
                $body['status'] = is_string($code) ? $code : $status;
            }

            try {
                // The prehliadka's own rules: no options, as the defect screen saves it.
                $fields = PrehliadkaDefects::validate($body, (string) $parent['executed_on'], (bool) $parent['is_dom']);
            } catch (\InvalidArgumentException $e) {
                $fail($sheet, $rowNum, $e->getMessage());
                continue;
            }
            $json = json_encode($fields, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $fail($sheet, $rowNum, 'Nedostatok sa nepodarilo uložiť.');
                continue;
            }
            $insertDefect->execute([(int) $parent['inspection_id'], $json, (int) $parent['inspection_id']]);
            $defects++;
        }

        return [$created, $defects];
    }

    /**
     * „1,3,5" → [1, 3, 5]; an empty cell → []; anything that is not a list of
     * numbers → null.
     *
     * @return list<int>|null
     */
    private static function pointNumbers(?string $cell): ?array
    {
        if ($cell === null) {
            return [];
        }
        $parts = preg_split('/[\s,;]+/u', $cell, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach (is_array($parts) ? $parts : [] as $n) {
            if (!ctype_digit($n)) {
                return null;
            }
            $out[] = (int) $n;
        }
        return $out;
    }

    private static function companyClientType(PDO $pdo, int $accountId, int $companyId): ?string
    {
        $stmt = $pdo->prepare('SELECT client_type FROM companies WHERE id = ? AND account_id = ?');
        $stmt->execute([$companyId, $accountId]);
        $type = $stmt->fetchColumn();
        return is_string($type) ? $type : null;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Resolves the user an imported record is attributed to by email — an
     * inspection's inspector or a training's trainer.
     *
     * Active members are served from the pre-loaded $userIdByEmail map. For
     * any other email we ensure a Firol user exists — reusing a row if the
     * email already belongs to someone (e.g. a member of another account),
     * otherwise creating a "pending" placeholder (empty password, cannot log
     * in) — and attach that user to the importing account as an *inactive*
     * technician so it stays off the seat count until claimed. When the
     * person registers under the same email, {@see AuthController::register}
     * sets their password, flips is_pending off and activates the membership;
     * the imported records are already attributed to them.
     *
     * Mutates $userIdByEmail (so repeated rows resolve to one user) and
     * increments $createdCount for each newly created placeholder.
     *
     * @param array<string,int> $userIdByEmail
     */
    private static function resolveOrCreateMember(
        PDO $pdo,
        int $accountId,
        string $email,
        array &$userIdByEmail,
        int &$createdCount,
    ): int {
        $key = mb_strtolower($email);
        if (isset($userIdByEmail[$key])) {
            return $userIdByEmail[$key];
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$key]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $userId = (int) $existingId;
        } else {
            $pdo->prepare(
                'INSERT INTO users (fullname, email, password_hash, is_pending)
                 VALUES (?, ?, "", 1)'
            )->execute([$email, $key]);
            $userId = (int) $pdo->lastInsertId();
            $createdCount++;
        }

        // Link to the importing account if not already a member. Inactive so
        // it doesn't consume a paid seat before the person registers.
        $link = $pdo->prepare(
            'SELECT 1 FROM account_users WHERE account_id = ? AND user_id = ?'
        );
        $link->execute([$accountId, $userId]);
        if ($link->fetchColumn() === false) {
            $pdo->prepare(
                'INSERT INTO account_users (account_id, user_id, role, is_active)
                 VALUES (?, ?, "technician", 0)'
            )->execute([$accountId, $userId]);
        }

        $userIdByEmail[$key] = $userId;
        return $userId;
    }

    /**
     * Resolves the company an imported record belongs to by IČO, creating
     * it on the fly when no company with that IČO exists yet in the tenant.
     *
     * IČO is the match key and takes precedence over the name: if a company
     * with this IČO already exists it is reused regardless of how the name
     * is spelled in the sheet (e.g. with vs. without diacritics), so an
     * existing record is never duplicated. The name is only used when a new
     * company has to be created.
     *
     * Mutates $companyIdByIco (so repeated rows resolve to one company) and
     * increments $createdCount for each newly created company.
     *
     * @param array<string,int> $companyIdByIco
     */
    private static function resolveOrCreateCompany(
        PDO $pdo,
        int $accountId,
        string $ico,
        string $name,
        array &$companyIdByIco,
        int &$createdCount,
    ): int {
        if (isset($companyIdByIco[$ico])) {
            return $companyIdByIco[$ico];
        }

        $pdo->prepare(
            'INSERT INTO companies (account_id, name, ico) VALUES (?, ?, ?)'
        )->execute([$accountId, $name, $ico]);
        $id = (int) $pdo->lastInsertId();
        \Firol\Documents\BookNumber::assignProposal($accountId, $id, $name);
        $companyIdByIco[$ico] = $id;
        $createdCount++;
        return $id;
    }

    /**
     * Resolves a facility under a given company by name, creating it on the
     * fly when no facility with that (case-insensitive) name exists yet.
     *
     * Mutates $facilityIdByKey (so repeated rows resolve to one facility)
     * and increments $createdCount for each newly created facility.
     *
     * @param array<string,int> $facilityIdByKey "company_id|lower(name)" → id
     */
    private static function resolveOrCreateFacility(
        PDO $pdo,
        int $accountId,
        int $companyId,
        string $name,
        array &$facilityIdByKey,
        int &$createdCount,
    ): int {
        $key = $companyId . '|' . mb_strtolower($name);
        if (isset($facilityIdByKey[$key])) {
            return $facilityIdByKey[$key];
        }

        $pdo->prepare(
            'INSERT INTO facilities (account_id, company_id, name) VALUES (?, ?, ?)'
        )->execute([$accountId, $companyId, $name]);
        $id = (int) $pdo->lastInsertId();
        $facilityIdByKey[$key] = $id;
        $createdCount++;
        return $id;
    }

    /**
     * Reads the uploaded xlsx, maps each known sheet to a list of rows
     * keyed by schema field name. Sheets missing from the upload are
     * returned as empty arrays so callers can iterate without null checks.
     *
     * @param array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,date?:bool}>}> $schema
     * @return array<string, list<array<string, string>>>
     */
    private static function readUpload(array $schema): array
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Nahraj .xlsx súbor.', 422);
        }
        if (($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            Response::error('Súbor je príliš veľký (max 5 MB).', 422);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::error('Nahrávanie zlyhalo.', 422);
        }

        try {
            $reader = IOFactory::createReaderForFile($tmp);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmp);
        } catch (Throwable $e) {
            Response::error('Súbor sa nepodarilo načítať: ' . $e->getMessage(), 422);
        }

        $byKey = [];
        foreach ($schema as $sheetCode => $sheet) {
            $byKey[$sheetCode] = [];
            $sheetTitle = substr($sheetCode, 0, 31);
            $ws = $spreadsheet->getSheetByName($sheetTitle);
            if ($ws === null) {
                continue;
            }
            $columns = $sheet['columns'];
            $colCount = count($columns);
            $highestRow = $ws->getHighestDataRow();
            for ($r = 2; $r <= $highestRow; $r++) {
                $assoc = [];
                $anyValue = false;
                for ($c = 1; $c <= $colCount; $c++) {
                    $col   = $columns[$c - 1];
                    $value = $ws->getCell([$c, $r])->getValue();
                    if (!empty($col['date'])) {
                        // Normalize every date representation Excel can produce
                        // (a serial number when read data-only, a DateTime, or
                        // DD-MM-RRRR / DD.MM.RRRR text) to the canonical Y-m-d
                        // the importers and the DB expect.
                        $str = self::normalizeImportDate($value);
                    } elseif (!empty($col['date_or_text'])) {
                        // A word (bezodkladne, 30d, …) stays as typed; a date in
                        // any shape Excel delivers becomes Y-m-d. A small bare
                        // number („30") is no date: it stays text and fails
                        // validation instead of landing in January 1900.
                        $isSerial = (is_int($value) || is_float($value)) && $value >= 1000;
                        $str = $isSerial || $value instanceof \DateTimeInterface
                            || (is_string($value) && preg_match('#^\d{1,2}[.\-/]\d{1,2}[.\-/]\d{4}$#', trim($value)))
                            ? self::normalizeImportDate($value)
                            : ($value === null ? '' : (string) $value);
                    } elseif ($value instanceof \DateTimeInterface) {
                        $str = $value->format('Y-m-d');
                    } elseif (is_float($value) && !in_array($col['key'], ['q', 'hs', 'hd', 'working_pressure', 'test_pressure', 'length'], true)) {
                        // Excel stores all numbers as floats. Re-flatten
                        // to int for fields that aren't decimal by nature.
                        $str = floor($value) === $value ? (string) (int) $value : (string) $value;
                    } else {
                        $str = $value === null ? '' : (string) $value;
                    }
                    $str = trim($str);
                    if (!empty($col['option_labels'])) {
                        // Dropdown values carry their meaning in parentheses
                        // (e.g. "TS (Tlaková skúška)"); reduce back to the bare
                        // code so it matches the same as a hand-typed "TS".
                        $str = self::stripOptionLabel($str);
                    }
                    if ($str !== '') {
                        $anyValue = true;
                    }
                    $assoc[$col['key']] = $str;
                }
                if (!$anyValue) {
                    continue;
                }
                $byKey[$sheetCode][] = $assoc;
            }
        }
        return $byKey;
    }

    /** @return array<string,int> ICO → company id */
    private static function loadCompanyMap(int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, ico FROM companies
             WHERE account_id = ? AND archived_at IS NULL AND ico IS NOT NULL'
        );
        $stmt->execute([$accountId]);
        $out = [];
        $padded = [];
        foreach ($stmt->fetchAll() as $r) {
            $ico = (string) $r['ico'];
            $out[$ico] = (int) $r['id'];
            $padded[self::padIco($ico)] = (int) $r['id'];
        }
        return $out + $padded;
    }

    /** @return array<int,string> company id → client_type, the account's companies */
    private static function loadClientTypeMap(int $accountId): array
    {
        $stmt = Db::pdo()->prepare('SELECT id, client_type FROM companies WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = (string) $r['client_type'];
        }
        return $out;
    }

    /** @return array<string,int> "company_id|lower(name)" → facility id */
    private static function loadFacilityMap(int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, company_id, name FROM facilities
             WHERE account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$accountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $key = $r['company_id'] . '|' . mb_strtolower((string) $r['name']);
            $out[$key] = (int) $r['id'];
        }
        return $out;
    }

    /** @return array<string,int> lower(email) → user id, active members only */
    private static function loadAccountUserMap(int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT u.id, u.email FROM users u
             JOIN account_users au ON au.user_id = u.id
             WHERE au.account_id = ? AND au.is_active = 1'
        );
        $stmt->execute([$accountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[mb_strtolower((string) $r['email'])] = (int) $r['id'];
        }
        return $out;
    }

    /** @param array<string,string> $row */
    private static function str(array $row, string $key): ?string
    {
        $v = $row[$key] ?? '';
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /**
     * Reduces a labelled dropdown value back to its bare code: "TS (Tlaková
     * skúška)" → "TS". Leaves bare codes and free text untouched, so a file
     * filled with plain codes imports exactly as before.
     */
    private static function stripOptionLabel(string $value): string
    {
        if (preg_match('/^(.*?)\s+\(.*\)$/u', $value, $m)) {
            return trim($m[1]);
        }
        return $value;
    }

    /** @param array<string,string> $row */
    private static function ico(array $row, string $key): ?string
    {
        $v = self::str($row, $key);
        if ($v === null) return null;
        $v = preg_replace('/\s+/', '', $v) ?? '';
        return $v === '' ? null : self::padIco($v);
    }

    /**
     * An IČO has 8 digits. A cell Excel read as a number lost its leading
     * zeros („00169803" → 169803), so an all-digit value shorter than 8 is
     * padded back; anything else stays as typed.
     */
    private static function padIco(string $ico): string
    {
        return ctype_digit($ico) && strlen($ico) < 8 ? str_pad($ico, 8, '0', STR_PAD_LEFT) : $ico;
    }

    /** A real calendar date in the canonical Y-m-d (2026-02-31 is not). */
    private static function isDate(?string $value): bool
    {
        return $value !== null
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Typed as a date (DD-MM-RRRR or RRRR-MM-DD), whether the day exists or not. */
    private static function looksLikeDate(string $value): bool
    {
        return preg_match('#^\d{1,2}[.\-/]\d{1,2}[.\-/]\d{4}$#', $value) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /** @param array<string,string> $row */
    private static function intOrNull(array $row, string $key): ?int
    {
        $v = self::str($row, $key);
        if ($v === null) return null;
        if (!preg_match('/^-?\d+$/', $v)) return null;
        return (int) $v;
    }

    /**
     * Normalizes a raw date cell value to the canonical `Y-m-d` string the
     * importers validate and store, or returns the trimmed input unchanged
     * when it can't be parsed (so per-row validation surfaces a clean
     * "Neplatný dátum" rather than a silently wrong date).
     *
     * Handles the three shapes Excel can deliver for a date entered in the
     * template's `DD-MM-RRRR` cells:
     *   - a serial number (the common case when reading data-only, because
     *     styles aren't loaded and the cell is a real Excel date),
     *   - a DateTime (when the reader does surface one),
     *   - text in DD-MM-RRRR / DD.MM.RRRR / DD/MM/RRRR, or already-canonical
     *     YYYY-MM-DD for backward compatibility.
     */
    private static function normalizeImportDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return (string) $value;
            }
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $s = trim((string) $value);
        if ($s === '') {
            return '';
        }
        // Day-first text (the format the template asks for). An impossible
        // day („31-02-2026") stays as typed, so the row fails validation with
        // its sheet and row instead of the INSERT failing in the database.
        if (preg_match('#^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{4})$#', $s, $m)) {
            if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return $s;
            }
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        // Already canonical — passed through; the validators' isDate() still
        // rejects an impossible one (2026-02-31).
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        // Unrecognized — hand back as-is so validation rejects it clearly.
        return $s;
    }

    /** @param array<string,string> $row */
    private static function floatOrNull(array $row, string $key): ?float
    {
        $v = self::str($row, $key);
        if ($v === null) return null;
        $v = str_replace(',', '.', $v);
        if (!is_numeric($v)) return null;
        return (float) $v;
    }

    /**
     * Builds the JSON-encodable per-item payload for the given inspection
     * type. Returns null and appends to $errors when the row is malformed.
     *
     * @param array<string,string> $row
     * @param list<array{sheet:string,row:int,message:string}> $errors
     * @return array<string,mixed>|null
     */
    private static function buildItemFields(string $type, array $row, string $sheet, int $rowNum, array &$errors): ?array
    {
        switch ($type) {
            case 'php': {
                $status = self::str($row, 'status');
                if ($status === null || !in_array($status, ['A','TS','O','V'], true)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný stav (A/TS/O/V).'];
                    return null;
                }
                $year = self::intOrNull($row, 'year');
                if ($year === null) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný rok výroby.'];
                    return null;
                }
                return [
                    'manufacturer' => self::str($row, 'manufacturer') ?? '',
                    'type'         => self::str($row, 'type') ?? '',
                    'serial'       => self::str($row, 'serial') ?? '',
                    'year'         => $year,
                    'location'     => self::str($row, 'location') ?? '',
                    'status'       => $status,
                    'notes'        => self::str($row, 'notes'),
                ];
            }
            case 'hydranty': {
                $kind = self::str($row, 'type');
                if ($kind === null || !in_array($kind, ['DN25','DN33','DN52','C52','other'], true)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný typ (DN25/DN33/DN52/C52/other).'];
                    return null;
                }
                $result = self::str($row, 'result');
                if ($result === null || !in_array($result, ['vyhovuje','nevyhovuje'], true)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný výsledok.'];
                    return null;
                }
                return [
                    'type'       => $kind,
                    'type_other' => self::str($row, 'type_other'),
                    'location'   => self::str($row, 'location') ?? '',
                    'hose_count' => self::intOrNull($row, 'hose_count') ?? 0,
                    'hs'         => self::floatOrNull($row, 'hs') ?? 0.0,
                    'hd'         => self::floatOrNull($row, 'hd') ?? 0.0,
                    'q'          => self::floatOrNull($row, 'q') ?? 0.0,
                    'defects'    => self::str($row, 'defects'),
                    'result'     => $result,
                ];
            }
            case 'oprava_ts_php': {
                $year = self::intOrNull($row, 'year');
                if ($year === null) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný rok výroby.'];
                    return null;
                }
                $actionsRaw = self::str($row, 'actions') ?? '';
                $actions = array_values(array_filter(array_map('trim', explode(',', $actionsRaw)), fn($a) => $a !== ''));
                foreach ($actions as $a) {
                    if (!in_array($a, ['tlakova_skuska','oprava','plnenie'], true)) {
                        $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => "Neznáma akcia „{$a}”."];
                        return null;
                    }
                }
                if ($actions === []) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Aspoň jedna akcia je povinná.'];
                    return null;
                }
                return [
                    'manufacturer' => self::str($row, 'manufacturer') ?? '',
                    'type'         => self::str($row, 'type') ?? '',
                    'serial'       => self::str($row, 'serial') ?? '',
                    'year'         => $year,
                    'location'     => self::str($row, 'location') ?? '',
                    'actions'      => $actions,
                    'notes'        => self::str($row, 'notes'),
                ];
            }
            case 'poziarna_kniha': {
                $result = self::str($row, 'result');
                if ($result === null || !in_array($result, ['bez_nedostatkov','zistene_nedostatky'], true)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný výsledok.'];
                    return null;
                }
                $activitiesRaw = self::str($row, 'activities') ?? '';
                $activities = array_values(array_filter(array_map('trim', explode(',', $activitiesRaw)), fn($a) => $a !== ''));
                $customRaw = self::str($row, 'custom_activities') ?? '';
                $custom = $customRaw === '' ? [] : array_values(array_filter(array_map('trim', explode('|', $customRaw)), fn($a) => $a !== ''));
                $defects = [];
                $defDeadline = self::str($row, 'defect_deadline');
                if ($defDeadline !== null && !self::isDate($defDeadline) && self::looksLikeDate($defDeadline)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný dátum (formát DD-MM-RRRR).'];
                    return null;
                }
                $defDesc = self::str($row, 'defect_description');
                if ($defDesc !== null) {
                    $defects[] = [
                        'description' => $defDesc,
                        'deadline'    => self::str($row, 'defect_deadline'),
                    ];
                }
                return [
                    'workspaces'        => self::str($row, 'workspaces') ?? '',
                    'activities'        => $activities,
                    'custom_activities' => $custom,
                    'result'            => $result,
                    'defects'           => $defects,
                    'notes'             => self::str($row, 'notes'),
                ];
            }
            case 'pu_akcieschopnost':
            case 'pu_udrzba': {
                $kind = self::str($row, 'kind');
                if ($kind === null || !in_array($kind, ['dvere','okno','klapka'], true)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný druh (dvere/okno/klapka).'];
                    return null;
                }
                $result = self::str($row, 'result');
                if ($result === null || !in_array($result, ['vyhovuje','nevyhovuje'], true)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný výsledok.'];
                    return null;
                }
                $out = [
                    'kind'         => $kind,
                    'identifier'   => self::str($row, 'identifier') ?? '',
                    'manufacturer' => self::str($row, 'manufacturer') ?? '',
                    'location'     => self::str($row, 'location') ?? '',
                    'result'       => $result,
                    'notes'        => self::str($row, 'notes'),
                ];
                if ($type === 'pu_udrzba') {
                    $out['maintenance_work'] = self::str($row, 'maintenance_work') ?? '';
                }
                return $out;
            }
            case 'nudzove_osvetlenie': {
                $result = self::str($row, 'result');
                if ($result === null || !in_array($result, ['vyhovuje','nevyhovuje'], true)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný výsledok.'];
                    return null;
                }
                $duration = self::intOrNull($row, 'duration_min');
                if ($duration === null) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatné trvanie (min).'];
                    return null;
                }
                return [
                    'evid_number'    => self::str($row, 'evid_number') ?? '',
                    'floor'          => self::str($row, 'floor') ?? '',
                    'luminaire_type' => self::str($row, 'luminaire_type') ?? '',
                    'manufacturer'   => self::str($row, 'manufacturer') ?? '',
                    'location'       => self::str($row, 'location') ?? '',
                    'duration_min'   => $duration,
                    'result'         => $result,
                    'notes'          => self::str($row, 'notes'),
                ];
            }
            case 'ts_hadic': {
                $result = self::str($row, 'result');
                if ($result === null || !in_array($result, ['vyhovuje','nevyhovuje'], true)) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný výsledok.'];
                    return null;
                }
                $year = self::intOrNull($row, 'year_of_manufacture');
                if ($year === null) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => 'Neplatný rok výroby.'];
                    return null;
                }
                return [
                    'hose_type'           => self::str($row, 'hose_type') ?? '',
                    'location'            => self::str($row, 'location') ?? '',
                    'manufacturer'        => self::str($row, 'manufacturer') ?? '',
                    'working_pressure'    => self::floatOrNull($row, 'working_pressure') ?? 0.0,
                    'test_pressure'       => self::floatOrNull($row, 'test_pressure') ?? 0.0,
                    'length'              => self::floatOrNull($row, 'length') ?? 0.0,
                    'year_of_manufacture' => $year,
                    'result'              => $result,
                    'notes'               => self::str($row, 'notes'),
                ];
            }
        }
        $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => "Neznámy typ kontroly: $type."];
        return null;
    }
}
