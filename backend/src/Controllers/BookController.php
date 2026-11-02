<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Pdf\PdfRenderer;
use Firol\Support\BookLedger;
use Firol\Support\BookMapping;
use Firol\Support\BookOffers;
use Firol\Support\BookSentences;
use Firol\Support\Contractor;
use Firol\Support\ResponsiblePerson;

/**
 * Požiarna kniha and kniha BOZP as ledgers (spec 9, phase 5).
 *
 * A book is a place at a firm, an entry is one numbered row in it. Two books
 * (po, bozp) with the same mechanics, one ledger per firm — or per prevádzka
 * when the firm's `book_scope` says so. The entries come from three paths
 * (9.3): offered from a finished úkon, written by protocol number, or typed by
 * hand; a later fact is an addition under the entry, never an edit.
 *
 * Offline: the reads (book, summary) are cached by the client like any GET.
 * Every write here — numbering needs the server — is online-only.
 *
 * Always scoped to the session's active account. Entries are never edited or
 * deleted (Q8), so MemberRights has nothing to guard; the scope switch and the
 * signature marks are written to the audit log.
 */
final class BookController
{
    private const MAX_TEXT = 8000;
    private const MAX_NUMBER = 64;
    private const MAX_NUMBERS = 60;
    private const MAX_PERSON = 191;
    private const SOURCES = ['ukon', 'vlastny', 'hromadny'];

    /**
     * GET /api/companies/{id}/book-summary → counts for the card on the firm.
     */
    public static function summary(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $company = self::company($accountId, (int) $params['id']);

        Response::json([
            'scope'   => $company['book_scope'],
            'summary' => BookLedger::summary($accountId, $company),
        ]);
    }

    /**
     * GET /api/companies/{id}/book?book=po|bozp[&facility_id=]
     *
     * One ledger with everything its screen shows: the entries, what the app
     * offers to add, the entries of the previous scope, the prefilled signer.
     */
    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $company = self::company($accountId, (int) $params['id']);
        $book = self::readBook($req->query('book'));

        $facilities = BookLedger::facilities($accountId, $company['id']);
        $facilityId = BookLedger::resolveFacility(
            $company['book_scope'],
            self::intOrNull($req->query('facility_id')),
            $facilities,
        );
        $perFacility = $company['book_scope'] === BookLedger::SCOPE_PREVADZKA;

        // A per-prevádzka book with no prevádzka has no ledger to read.
        $hasLedger = !$perFacility || $facilityId !== null;
        $entries = $hasLedger ? BookLedger::entries($accountId, $company['id'], $book, $facilityId) : [];
        $offers = $hasLedger ? BookOffers::forLedger($accountId, $company['id'], $book, $facilityId) : [];
        $actions = $hasLedger ? BookOffers::availableActions($accountId, $company['id'], $book, $facilityId) : [];

        $signer = ResponsiblePerson::forFireBook($accountId, $company['id']);

        Response::json([
            'company' => [
                'id'          => $company['id'],
                'name'        => $company['name'],
                'client_type' => $company['client_type'],
                'archived'    => $company['archived_at'] !== null,
            ],
            'book'                   => $book,
            'book_label'             => BookMapping::label($book),
            'scope'                  => $company['book_scope'],
            'facilities'             => $facilities,
            'facility_id'            => $facilityId,
            'can_switch_scope'       => count($facilities) > 1,
            'entries'                => $entries,
            'offers'                 => $offers,
            'available_actions'      => $actions,
            'availableActions'       => $actions,
            'previous_scope_entries' => BookLedger::previousScopeEntries($accountId, $company['id'], $book, $company['book_scope']),
            'unsigned_count'         => count(array_filter($entries, static fn (array $e): bool => !$e['signed_manager'])),
            'signer'                 => $signer,
            'legal_sentence'         => BookSentences::LEGAL,
            'no_defects_sentence'    => BookSentences::NO_DEFECTS,
        ]);
    }

    /**
     * POST /api/companies/{id}/book/entries
     *
     * Body: { book, facility_id?, source: ukon|hromadny|vlastny,
     *         date?, text?, vlastny_text?, defects_text?, legal_sentence?,
     *         no_defects_statement?, protocol_numbers?, inspection_ids?, training_ids? }
     *
     * A protocol number typed by hand is no source of its own (decision D2): it
     * goes into the optional own text. The legal sentence (spec 9.4) is added
     * only on request.
     */
    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $company = self::writableCompany($accountId, (int) $params['id']);
        $body = $req->json();

        $book = self::readBook(is_string($body['book'] ?? null) ? $body['book'] : null);
        $source = is_string($body['source'] ?? null) ? $body['source'] : '';
        if (!in_array($source, self::SOURCES, true)) {
            Response::error('Neznámy zdroj zápisu.', 422);
        }

        $facilities = BookLedger::facilities($accountId, $company['id']);
        $facilityId = BookLedger::resolveFacility(
            $company['book_scope'],
            is_int($body['facility_id'] ?? null) ? $body['facility_id'] : self::intOrNull(is_string($body['facility_id'] ?? null) ? $body['facility_id'] : null),
            $facilities,
        );
        if ($company['book_scope'] === BookLedger::SCOPE_PREVADZKA && $facilityId === null) {
            Response::error('Kniha je vedená po prevádzkach — najprv pridaj prevádzku.', 422);
        }

        $date = self::readDate($body['date'] ?? null);
        $numbers = self::readNumbers($body['protocol_numbers'] ?? []);
        $inspectionIds = self::readIds($body['inspection_ids'] ?? []);
        $trainingIds = self::readIds($body['training_ids'] ?? []);

        $text = is_string($body['text'] ?? null) ? trim($body['text']) : '';
        $vlastny = is_string($body['vlastny_text'] ?? null) ? trim($body['vlastny_text']) : '';
        if ($text === '' && $vlastny !== '') {
            $text = $vlastny;
            $vlastny = '';
        }
        if ($text === '') {
            Response::error('Text zápisu nemôže byť prázdny.', 422);
        }
        if ($vlastny !== '' && !str_contains($text, $vlastny)) {
            $text .= "\n" . $vlastny;
        }

        $defectsText = is_string($body['defects_text'] ?? null) ? trim($body['defects_text']) : (is_string($body['zavady_text'] ?? null) ? trim($body['zavady_text']) : null);
        if ($defectsText === '') {
            $defectsText = null;
        }

        if ($source === 'ukon' || $source === 'hromadny') {
            if ($numbers === [] && $inspectionIds === [] && $trainingIds === []) {
                Response::error('Zápis z úkonu musí niesť číslo protokolu.', 422);
            }
            // The ids must be this firm's own úkony.
            if (count(BookLedger::existingIds('inspections', $accountId, $company['id'], $inspectionIds)) !== count($inspectionIds)
                || count(BookLedger::existingIds('trainings', $accountId, $company['id'], $trainingIds)) !== count($trainingIds)) {
                Response::error('Úkon sa nenašiel.', 404);
            }
        } else {
            $numbers = [];
            $inspectionIds = [];
            $trainingIds = [];
        }

        // An úkon already in the book is never added a second time.
        if ($numbers !== []) {
            $booked = BookLedger::bookedNumbers($accountId, $company['id'], $book);
            foreach ($numbers as $n) {
                if (isset($booked[$n])) {
                    Response::error('Protokol č. ' . $n . ' už v knihe je.', 409);
                }
            }
        }

        // Spec 9.4 — the legal sentence goes in only when the technician asks.
        $legalSentence = ($body['legal_sentence'] ?? false) === true || ($body['pridat_zakonnu_vetu'] ?? false) === true;
        if ($legalSentence && !str_starts_with($text, BookSentences::LEGAL)) {
            $text = BookSentences::LEGAL . ' ' . $text;
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            Response::error('Text zápisu je príliš dlhý.', 422);
        }

        // Spec 9.7 — only the požiarna kniha, and only while no defect is attached.
        $noDefects = ($body['no_defects_statement'] ?? false) === true || ($body['vyhlasenie'] ?? false) === true;
        if ($defectsText !== null && $defectsText !== '') {
            $noDefects = false;
        }
        if ($noDefects) {
            if ($book !== BookMapping::PO) {
                Response::error('Vyhlásenie o nedostatkoch patrí len do požiarnej knihy.', 422);
            }
            if (BookLedger::carriesDefects($accountId, $inspectionIds)) {
                Response::error('Zápis nesie nedostatky — vyhlásenie „bez nedostatkov" sa nedá zaškrtnúť.', 422);
            }
        }

        $id = BookLedger::create(
            $accountId, $company['id'], $facilityId, $book, $date, $text, $source,
            $numbers, $inspectionIds, $trainingIds, $noDefects, $userId, $defectsText,
        );

        AuditLog::record('book_entry.create', 'company', $company['id'], null, [
            'entry_id' => $id,
            'book'     => $book,
        ]);

        $entry = BookLedger::entry($accountId, $id);
        Response::json(['entry' => $entry], 201);
    }

    /**
     * POST /api/book-entries/{id}/additions — „dopísanie". Body: { text }.
     * The entry's own text is never changed; this is written under it.
     */
    public static function addAddition(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $entry = BookLedger::entry($accountId, (int) $params['id']);
        if ($entry === null) {
            Response::error('Zápis sa nenašiel.', 404);
        }
        $companyId = (int) self::companyIdOfEntry($accountId, (int) $entry['id']);
        self::writableCompany($accountId, $companyId);

        $text = $req->jsonString('text') ?? '';
        if ($text === '') {
            Response::error('Napíš doplnenie.', 422);
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            Response::error('Doplnenie je príliš dlhé.', 422);
        }

        BookLedger::addAddition($accountId, (int) $entry['id'], $text, Tenant::currentUserId());
        AuditLog::record('book_entry.addition', 'company', $companyId, null, [
            'entry_id' => (int) $entry['id'],
        ]);
        Response::json(['entry' => BookLedger::entry($accountId, (int) $entry['id'])], 201);
    }

    /**
     * POST /api/companies/{id}/book/mark-signed
     *
     * Body: { book, facility_id?, entry_ids?, signer_name?, signer_role? }.
     * Marks the vedúci zamestnanec's signature on the printed entries — all
     * unsigned entries of the ledger when `entry_ids` is not given. The signer
     * is whoever signed the printout, so the name stays editable.
     */
    public static function markSigned(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $company = self::writableCompany($accountId, (int) $params['id']);
        $body = $req->json();
        $book = self::readBook(is_string($body['book'] ?? null) ? $body['book'] : null);

        $facilities = BookLedger::facilities($accountId, $company['id']);
        $facilityId = BookLedger::resolveFacility(
            $company['book_scope'],
            is_int($body['facility_id'] ?? null) ? $body['facility_id'] : null,
            $facilities,
        );

        $ids = self::readIds($body['entry_ids'] ?? []);
        $ledger = BookLedger::entries($accountId, $company['id'], $book, $facilityId);
        $ledgerIds = array_map(static fn (array $e): int => (int) $e['id'], array_filter(
            $ledger,
            static fn (array $e): bool => !$e['signed_manager'],
        ));
        $target = $ids === [] ? $ledgerIds : array_values(array_intersect($ids, $ledgerIds));

        $name = self::person($body['signer_name'] ?? null);
        $role = self::person($body['signer_role'] ?? null);
        $marked = BookLedger::markSigned($accountId, $company['id'], $target, $name, $role);

        if ($marked > 0) {
            AuditLog::record('book.mark_signed', 'company', $company['id'], null, [
                'book' => $book, 'facility_id' => $facilityId, 'entries' => $target, 'signer' => $name,
            ]);
        }
        Response::json(['marked' => $marked]);
    }

    /**
     * PATCH /api/companies/{id}/book-scope — body { book_scope: firma|prevadzka }.
     * A setting, changeable at any time. Nothing is rewritten: entries keep the
     * scope they were written in.
     */
    public static function setScope(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $company = self::writableCompany($accountId, (int) $params['id']);

        $scope = $req->jsonString('book_scope');
        if ($scope !== BookLedger::SCOPE_FIRMA && $scope !== BookLedger::SCOPE_PREVADZKA) {
            Response::error('Neznámy rozsah knihy.', 422);
        }

        if ($scope !== $company['book_scope']) {
            Db::pdo()->prepare('UPDATE companies SET book_scope = ? WHERE id = ? AND account_id = ?')
                ->execute([$scope, $company['id'], $accountId]);
            AuditLog::record(
                'book.scope',
                'company',
                $company['id'],
                ['book_scope' => $company['book_scope']],
                ['book_scope' => $scope],
            );
        }
        Response::json(['book_scope' => $scope]);
    }

    /**
     * GET /api/companies/{id}/book/print?book=&facility_id=&mode=unsigned|entry|all
     *     [&entry_id=][&signer_name=][&signer_role=]
     *
     * The unsigned entries of the ledger on one sheet with one signature block,
     * a single entry (docZapis), or the full book (docKniha). Rendered on the fly —
     * nothing is stored, no number is taken (decision D6).
     */
    public static function print(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $company = self::company($accountId, (int) $params['id']);
        $book = self::readBook($req->query('book'));

        $facilities = BookLedger::facilities($accountId, $company['id']);
        $facilityId = BookLedger::resolveFacility(
            $company['book_scope'],
            self::intOrNull($req->query('facility_id')),
            $facilities,
        );

        $mode = in_array($req->query('mode'), ['entry', 'all'], true) ? $req->query('mode') : 'unsigned';
        if ($mode === 'entry') {
            $entry = BookLedger::entry($accountId, (int) ($req->query('entry_id') ?? 0));
            if ($entry === null || $entry['book'] !== $book || (int) ($entry['company_id'] ?? 0) !== (int) $company['id']) {
                Response::error('Zápis sa nenašiel.', 404);
            }
            $entries = [$entry];
            // A single entry prints with the book it was written in, whatever the scope is now.
            $facilityId = $entry['facility_id'];
        } elseif ($mode === 'all') {
            $entries = BookLedger::entries($accountId, $company['id'], $book, $facilityId);
            if ($entries === []) {
                Response::error('V knihe zatiaľ nie sú žiadne zápisy.', 404);
            }
        } else {
            $allEntries = BookLedger::entries($accountId, $company['id'], $book, $facilityId);
            $entries = array_values(array_filter(
                $allEntries,
                static fn (array $e): bool => !$e['signed_manager'],
            ));
            if ($entries === []) {
                Response::error(
                    $allEntries === []
                        ? 'V knihe zatiaľ nie sú žiadne zápisy.'
                        : 'Nie je čo tlačiť — všetky zápisy sú podpísané.',
                    404,
                );
            }
        }

        $facilityName = null;
        foreach ($facilities as $f) {
            if ($f['id'] === $facilityId) {
                $facilityName = $f['name'];
            }
        }
        if ($facilityName === null && ($entries[0]['facility_name'] ?? null) !== null) {
            $facilityName = $entries[0]['facility_name'];
        }

        $signer = ResponsiblePerson::forFireBook($accountId, $company['id']);
        $signerName = self::person($req->query('signer_name')) ?? ($signer['fullname'] ?? null);
        $signerRole = $req->query('signer_name') !== null
            ? self::person($req->query('signer_role'))
            : ($signer['role_title'] ?? null);

        $companyRow = Db::pdo()->prepare('SELECT name, ico, street, postal_code, city FROM companies WHERE id = ? AND account_id = ?');
        $companyRow->execute([$company['id'], $accountId]);
        $companyData = $companyRow->fetch() ?: [
            'name'        => $company['name'],
            'ico'         => null,
            'street'      => null,
            'postal_code' => null,
            'city'        => null,
        ];

        $facilityData = null;
        if ($facilityId !== null) {
            $fStmt = Db::pdo()->prepare('SELECT name, street, postal_code, city FROM facilities WHERE id = ? AND account_id = ?');
            $fStmt->execute([$facilityId, $accountId]);
            $facilityData = $fStmt->fetch() ?: null;
        }

        $accStmt = Db::pdo()->prepare('SELECT invoice_company_name, theme_color, logo_path FROM accounts WHERE id = ?');
        $accStmt->execute([$accountId]);

        // An entry a prehliadka wrote carries the defects of its record (phase 8a).
        foreach ($entries as &$entryRow) {
            $entryRow['defect_lines'] = \Firol\Support\PrehliadkaRecord::bookLines($accountId, $entryRow);
        }
        unset($entryRow);

        $allForLedger = ($mode === 'all') ? $entries : BookLedger::entries($accountId, $company['id'], $book, $facilityId);
        $unsignedCount = count(array_filter($allForLedger, static fn (array $e): bool => !$e['signed_manager']));

        if ($mode === 'entry') {
            $title = 'Zápis do požiarnej knihy';
            $filename = 'zapis-' . $entries[0]['seq'] . '.pdf';
        } elseif ($mode === 'all') {
            $title = BookMapping::label($book);
            $filename = ($book === BookMapping::BOZP ? 'kniha-bozp' : 'poziarna-kniha') . '-komplet.pdf';
        } else {
            $title = BookMapping::label($book) . ' — zápisy na podpis';
            $filename = ($book === BookMapping::BOZP ? 'kniha-bozp' : 'poziarna-kniha') . '-na-podpis.pdf';
        }

        $pdf = PdfRenderer::renderBook([
            'title'          => $title,
            'brand'          => DocumentController::buildBrand($accStmt->fetch() ?: []),
            'contractor'     => Contractor::live($accountId, 'kniha'),
            'company'        => [
                'name'        => $companyData['name'],
                'ico'         => $companyData['ico'],
                'street'      => $companyData['street'] ?? null,
                'postal_code' => $companyData['postal_code'] ?? null,
                'city'        => $companyData['city'] ?? null,
            ],
            'facility'       => $facilityData ?? ($facilityName !== null ? ['name' => $facilityName] : null),
            'facilities'     => $facilities,
            'scope'          => $company['book_scope'],
            'book'           => $book,
            'mode'           => $mode,
            'printed_on'     => date('Y-m-d'),
            'entries'        => $entries,
            'unsigned_count' => $unsignedCount,
            'technicians'    => self::technicians($accountId, $userId, $book, $entries),
            'signer'         => ['name' => $signerName, 'role' => $signerRole],
        ]);

        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        echo $pdf;
        exit;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Who made the entries on the sheet, with their oprávnenie and signature.
     * One name per technician, in the order of their first entry.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function technicians(int $accountId, int $fallbackUserId, string $book, array $entries): array
    {
        $ids = [];
        foreach ($entries as $e) {
            $uid = $e['created_by_user_id'] ?? $fallbackUserId;
            $ids[(int) $uid] = true;
        }

        $certColumn = $book === BookMapping::BOZP ? 'cert_bt' : 'cert_general';
        $out = [];
        foreach (array_keys($ids) as $uid) {
            $stmt = Db::pdo()->prepare(
                "SELECT u.fullname, ip.$certColumn AS cert, ip.signature_path
                 FROM   users u
                 LEFT   JOIN inspector_profiles ip ON ip.user_id = u.id AND ip.account_id = ?
                 WHERE  u.id = ?"
            );
            $stmt->execute([$accountId, $uid]);
            $row = $stmt->fetch();
            if (!$row) {
                continue;
            }
            $out[] = [
                'fullname'             => (string) $row['fullname'],
                'certification_number' => $row['cert'] !== null && $row['cert'] !== '' ? (string) $row['cert'] : null,
                'signature_data_uri'   => DocumentController::signatureToDataUri($row['signature_path'] ?? null),
            ];
        }
        return $out;
    }

    /** @return array{id: int, name: string, client_type: string, book_scope: string, archived_at: ?string} */
    private static function company(int $accountId, int $companyId): array
    {
        $company = BookLedger::company($accountId, $companyId);
        if ($company === null) {
            Response::error('Firma sa nenašla.', 404);
        }
        return $company;
    }

    /**
     * An archived firm's book stays readable and printable, but nothing new is
     * written into it (spec 25).
     *
     * @return array{id: int, name: string, client_type: string, book_scope: string, archived_at: ?string}
     */
    private static function writableCompany(int $accountId, int $companyId): array
    {
        $company = self::company($accountId, $companyId);
        if ($company['archived_at'] !== null) {
            Response::error('Firma je archivovaná — do jej knihy sa už nezapisuje.', 409);
        }
        return $company;
    }

    private static function companyIdOfEntry(int $accountId, int $entryId): int
    {
        $stmt = Db::pdo()->prepare('SELECT company_id FROM book_entries WHERE id = ? AND account_id = ?');
        $stmt->execute([$entryId, $accountId]);
        return (int) $stmt->fetchColumn();
    }

    private static function readBook(?string $value): string
    {
        if ($value === null || !BookMapping::isBook($value)) {
            Response::error('Kniha je len jedna — požiarna.', 422);
        }
        return $value;
    }

    /** A Y-m-d date; today when missing. */
    private static function readDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return date('Y-m-d');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            [$y, $m, $d] = array_map('intval', explode('-', $value));
            if (checkdate($m, $d, $y)) {
                return $value;
            }
        }
        Response::error('Neplatný dátum.', 422);
    }

    /** @return list<string> */
    private static function readNumbers(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            $n = is_string($v) ? trim($v) : '';
            if ($n === '') {
                continue;
            }
            if (mb_strlen($n) > self::MAX_NUMBER) {
                Response::error('Číslo protokolu je príliš dlhé.', 422);
            }
            $out[$n] = $n;
        }
        if (count($out) > self::MAX_NUMBERS) {
            Response::error('Zápis pokrýva príliš veľa protokolov.', 422);
        }
        return array_values($out);
    }

    /** @return list<int> */
    private static function readIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_int($v) && $v > 0) {
                $out[$v] = $v;
            }
        }
        if (count($out) > self::MAX_NUMBERS) {
            Response::error('Zápis pokrýva príliš veľa úkonov.', 422);
        }
        return array_values($out);
    }

    private static function person(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' ? mb_substr($value, 0, self::MAX_PERSON) : null;
    }

    private static function intOrNull(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
