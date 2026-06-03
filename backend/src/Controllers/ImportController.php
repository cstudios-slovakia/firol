<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Import\Schema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
        self::sendTemplate('Firol-import-firmy.xlsx', Schema::companies());
    }

    public static function trainingsTemplate(Request $req): void
    {
        self::sendTemplate('Firol-import-skolenia.xlsx', Schema::trainings());
    }

    public static function inspectionsTemplate(Request $req): void
    {
        self::sendTemplate('Firol-import-kontroly.xlsx', Schema::inspections());
    }

    /**
     * @param array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}> $sheets
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
     * @param array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}> $sheets
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
                $ws->getColumnDimensionByColumn($col1)->setWidth(26);

                $range = "{$colLetter}2:{$colLetter}1001";

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
                    if (!empty($col['options_help'])) {
                        // Annotate each value with the types it applies to.
                        $parts = [];
                        foreach ($col['options'] as $opt) {
                            $note = $col['options_help'][$opt] ?? '';
                            $parts[] = $note !== '' ? "$opt ($note)" : $opt;
                        }
                        $dv->setPrompt('Povolené hodnoty: ' . implode(', ', $parts));
                    } else {
                        $dv->setPrompt('Povolené hodnoty: ' . implode(', ', $col['options']));
                    }
                    $dv->setErrorTitle('Neplatná hodnota');
                    $dv->setError('Vyber jednu z hodnôt zo zoznamu (šípka vpravo v bunke).');
                    $dv->setFormula1('"' . implode(',', $col['options']) . '"');
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
     * @param array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string,options?:list<string>,multi_options?:list<array{value:string,label:string}>}>}> $sheets
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
            'Dátumy zadávaj vo formáte RRRR-MM-DD (napr. 2026-01-15).',
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
        foreach ($intro as $line) {
            $ws->getCell("A{$r}")->setValue('•  ' . $line);
            $ws->mergeCells("A{$r}:D{$r}");
            $ws->getStyle("A{$r}")->applyFromArray([
                'font'      => ['color' => ['rgb' => '334155']],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
            ]);
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
                } else {
                    $type    = 'Voľný text';
                    $allowed = !empty($col['hint']) ? 'napr. ' . $col['hint'] : '';
                    $lines   = 1;
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

            $insertCompany = $pdo->prepare(
                'INSERT INTO companies (account_id, name, ico, address, contact)
                 VALUES (?, ?, ?, ?, ?)'
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
                if ($ico !== null && isset($companyIdByIco[$ico])) {
                    // Skip silently — re-uploading a sheet with an existing
                    // IČO should be idempotent on the company itself.
                    continue;
                }

                $insertCompany->execute([$accountId, $name, $ico, $address, $contact]);
                $newId = (int) $pdo->lastInsertId();
                $createdCompanies++;
                if ($ico !== null) {
                    $companyIdByIco[$ico] = $newId;
                }
            }

            $insertFacility = $pdo->prepare(
                'INSERT INTO facilities (account_id, company_id, name, address, contact_person, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
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
                $insertFacility->execute([
                    $accountId,
                    $companyId,
                    $name,
                    self::str($row, 'address'),
                    self::str($row, 'contact_person'),
                    self::str($row, 'notes'),
                ]);
                $createdFacilities++;
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
            error_log('[import.companies] ' . $e->getMessage());
            Response::error('Import zlyhal: ' . $e->getMessage(), 500);
        }

        Response::json([
            'created' => [
                'companies'  => $createdCompanies,
                'facilities' => $createdFacilities,
            ],
            'errors' => [],
        ]);
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
                     trainer_id, topics, duration_min, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft")'
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
                if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $errors[] = ['sheet' => 'Skolenia', 'row' => $rowNum, 'message' => 'Neplatný dátum (formát YYYY-MM-DD).'];
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
                    $trainerId,
                    self::str($row, 'topics'),
                    self::intOrNull($row, 'duration_min'),
                ]);
                $trainingIdByRowNo[$rowNo] = (int) $pdo->lastInsertId();
                $createdTrainings++;
            }

            $insertTrainee = $pdo->prepare(
                'INSERT INTO trainees (training_id, fullname, position)
                 VALUES (?, ?, ?)'
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
                $insertTrainee->execute([$trainingId, $fullname, self::str($row, 'position')]);
                $createdTrainees++;
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

        $rowsBySheet = self::readUpload(Schema::inspections());

        $errors = [];
        $createdInspections = 0;
        $createdItems = 0;
        $createdTechnicians = 0;
        $createdCompanies = 0;
        $createdFacilities = 0;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $companyIdByIco = self::loadCompanyMap($accountId);
            $facilityIdByKey = self::loadFacilityMap($accountId);
            $userIdByEmail = self::loadAccountUserMap($accountId);

            /** @var array<int, array{id:int,type:string}> $inspectionByRowNo */
            $inspectionByRowNo = [];

            // # riadok values that appear on the Kontroly sheet but failed
            // validation (so they never made it into $inspectionByRowNo). Lets
            // the item loop tell "parent is broken — fix it on Kontroly" apart
            // from "parent number was never entered at all".
            /** @var array<int, true> $invalidInspectionRowNos */
            $invalidInspectionRowNos = [];

            $insertInspection = $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, type, periodicity_months,
                     executed_on, inspector_user_id, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );

            foreach ($rowsBySheet['Kontroly'] as $idx => $row) {
                $rowNum = $idx + 2;
                $rowNo = self::intOrNull($row, 'row_no');
                $companyName = self::str($row, 'company_name');
                $ico = self::ico($row, 'company_ico');
                $facilityName = self::str($row, 'facility_name');
                $type = self::str($row, 'type');
                $periodicity = self::intOrNull($row, 'periodicity_months');
                $executedOn = self::str($row, 'executed_on');

                if ($rowNo === null) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => 'Chýba # riadok.'];
                    continue;
                }
                if ($ico === null || $companyName === null || $facilityName === null || $type === null) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => 'Chýba názov firmy / IČO firmy / prevádzka / typ.'];
                    $invalidInspectionRowNos[$rowNo] = true;
                    continue;
                }
                $allowed = Schema::INSPECTION_PERIODICITIES[$type] ?? null;
                if ($allowed === null) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => "Neznámy typ kontroly: $type."];
                    $invalidInspectionRowNos[$rowNo] = true;
                    continue;
                }
                if ($periodicity === null || !in_array($periodicity, $allowed, true)) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => "Neplatná periodicita pre typ $type (povolené: " . implode(',', $allowed) . ')'];
                    $invalidInspectionRowNos[$rowNo] = true;
                    continue;
                }
                if ($executedOn === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $executedOn)) {
                    $errors[] = ['sheet' => 'Kontroly', 'row' => $rowNum, 'message' => 'Neplatný dátum (YYYY-MM-DD).'];
                    $invalidInspectionRowNos[$rowNo] = true;
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
                    $accountId, $companyId, $facilityId, $type, $periodicity,
                    $executedOn, $inspectorId, self::str($row, 'notes'),
                ]);
                $newId = (int) $pdo->lastInsertId();
                $inspectionByRowNo[$rowNo] = ['id' => $newId, 'type' => $type];
                $createdInspections++;
            }

            // Item sheets — each maps to one inspection type. We resolve
            // the parent by # kontrola which must reference a row in the
            // Kontroly sheet whose type matches the item sheet.
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
                        // A parent that exists on the Kontroly sheet but failed
                        // its own validation is a more useful thing to surface
                        // than a bare "doesn't exist" — otherwise the user
                        // chases a phantom missing row instead of the real
                        // error one sheet over.
                        $message = isset($invalidInspectionRowNos[$parentRowNo])
                            ? "Kontrola # $parentRowNo je chybná — oprav ju na hárku Kontroly."
                            : "Kontrola # $parentRowNo neexistuje v sheete Kontroly.";
                        $errors[] = ['sheet' => $sheetCode, 'row' => $rowNum, 'message' => $message];
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
                'inspections' => $createdInspections,
                'items'       => $createdItems,
                'technicians' => $createdTechnicians,
                'companies'   => $createdCompanies,
                'facilities'  => $createdFacilities,
            ],
            'errors' => [],
        ]);
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
     * @param array<string, array{title:string, columns: list<array{header:string,key:string,hint?:string}>}> $schema
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
                    $value = $ws->getCell([$c, $r])->getValue();
                    if ($value instanceof \DateTimeInterface) {
                        $value = $value->format('Y-m-d');
                    } elseif (is_float($value) && $columns[$c - 1]['key'] !== 'q' && $columns[$c - 1]['key'] !== 'hs' && $columns[$c - 1]['key'] !== 'hd' && $columns[$c - 1]['key'] !== 'working_pressure' && $columns[$c - 1]['key'] !== 'test_pressure' && $columns[$c - 1]['key'] !== 'length') {
                        // Excel stores all numbers as floats. Re-flatten
                        // to int for fields that aren't decimal by nature.
                        if (floor($value) === $value) {
                            $value = (int) $value;
                        }
                    }
                    $str = $value === null ? '' : (string) $value;
                    $str = trim($str);
                    if ($str !== '') {
                        $anyValue = true;
                    }
                    $assoc[$columns[$c - 1]['key']] = $str;
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
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['ico']] = (int) $r['id'];
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

    /** @param array<string,string> $row */
    private static function ico(array $row, string $key): ?string
    {
        $v = self::str($row, $key);
        if ($v === null) return null;
        $v = preg_replace('/\s+/', '', $v) ?? '';
        return $v === '' ? null : $v;
    }

    /** @param array<string,string> $row */
    private static function intOrNull(array $row, string $key): ?int
    {
        $v = self::str($row, $key);
        if ($v === null) return null;
        if (!preg_match('/^-?\d+$/', $v)) return null;
        return (int) $v;
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
                        $errors[] = ['sheet' => $sheet, 'row' => $rowNum, 'message' => "Neznáma akcia „$a”."];
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
