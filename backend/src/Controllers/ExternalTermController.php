<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\BookLedger;
use Firol\Support\BookMapping;
use Firol\Support\ExternalTerms;

/**
 * Termíny cudzích revízií of a firm (spec 6.6, phase 6).
 *
 *   GET    /api/companies/{id}/external-terms[?book=po|bozp&facility_id=]
 *   POST   /api/companies/{id}/external-terms
 *   PATCH  /api/companies/{id}/external-terms/{term_id}
 *   DELETE /api/companies/{id}/external-terms/{term_id}      (archives)
 *
 * Offline: the list is read like any GET and cached by the client; the writes
 * are online-only, like the book they feed.
 */
final class ExternalTermController
{
    private const MAX_NAME = 191;
    private const MAX_NOTE = 500;

    public static function index(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $company = self::company($accountId, (int) $params['id']);

        $book = $req->query('book');
        if ($book !== null && !BookMapping::isBook($book)) {
            Response::error('Neznáma kniha.', 422);
        }
        $facility = $req->query('facility_id');
        $facilityId = $facility !== null && ctype_digit($facility) ? (int) $facility : null;

        Response::json([
            'items'       => ExternalTerms::list($accountId, $company['id'], $book, $facilityId),
            'suggestions' => ExternalTerms::suggestions(),
        ]);
    }

    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $company = self::writableCompany($accountId, (int) $params['id']);
        $body = $req->json();

        $name = self::name($req->jsonString('name'));
        $book = self::book($req->jsonString('book'));
        $facilityId = self::facilityId($body, $company['id']);

        Db::pdo()->prepare(
            'INSERT INTO company_external_terms
                (account_id, company_id, facility_id, book, name, performed_by, done_on, valid_until, note, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $accountId, $company['id'], $facilityId, $book, $name,
            self::text($body['performed_by'] ?? null, self::MAX_NAME),
            self::date($body['done_on'] ?? null),
            self::date($body['valid_until'] ?? null),
            self::text($body['note'] ?? null, self::MAX_NOTE),
            Tenant::currentUserId(),
        ]);
        $id = (int) Db::pdo()->lastInsertId();

        AuditLog::record('external_term.create', 'company', $company['id'], null, ['term_id' => $id, 'book' => $book]);
        Response::json(['term' => ExternalTerms::find($accountId, $id)], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $company = self::writableCompany($accountId, (int) $params['id']);
        $term = self::term($accountId, $company['id'], (int) $params['term_id']);
        $body = $req->json();

        // Each field changes only when the body mentions it.
        $sets = [];
        $args = [];
        if (array_key_exists('name', $body)) {
            $sets[] = 'name = ?';
            $args[] = self::name($req->jsonString('name'));
        }
        if (array_key_exists('book', $body)) {
            $sets[] = 'book = ?';
            $args[] = self::book($req->jsonString('book'));
        }
        if (array_key_exists('facility_id', $body)) {
            $sets[] = 'facility_id = ?';
            $args[] = self::facilityId($body, $company['id']);
        }
        if (array_key_exists('performed_by', $body)) {
            $sets[] = 'performed_by = ?';
            $args[] = self::text($body['performed_by'], self::MAX_NAME);
        }
        if (array_key_exists('done_on', $body)) {
            $sets[] = 'done_on = ?';
            $args[] = self::date($body['done_on']);
        }
        if (array_key_exists('valid_until', $body)) {
            $sets[] = 'valid_until = ?';
            $args[] = self::date($body['valid_until']);
        }
        if (array_key_exists('note', $body)) {
            $sets[] = 'note = ?';
            $args[] = self::text($body['note'], self::MAX_NOTE);
        }
        if ($sets !== []) {
            // The column names above are this method's own literals, never request keys.
            $args[] = $term['id'];
            $args[] = $accountId;
            Db::pdo()->prepare('UPDATE company_external_terms SET ' . implode(', ', $sets) . ' WHERE id = ? AND account_id = ?')
                ->execute($args);
            AuditLog::record('external_term.update', 'company', $company['id'], null, ['term_id' => $term['id']]);
        }

        Response::json(['term' => ExternalTerms::find($accountId, $term['id'])]);
    }

    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $company = self::writableCompany($accountId, (int) $params['id']);
        $term = self::term($accountId, $company['id'], (int) $params['term_id']);

        Db::pdo()->prepare('UPDATE company_external_terms SET archived_at = NOW() WHERE id = ? AND account_id = ?')
            ->execute([$term['id'], $accountId]);
        AuditLog::record('external_term.archive', 'company', $company['id'], null, ['term_id' => $term['id']]);
        Response::noContent();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{id: int, archived_at: ?string, ...} */
    private static function company(int $accountId, int $companyId): array
    {
        $company = BookLedger::company($accountId, $companyId);
        if ($company === null) {
            Response::error('Firma sa nenašla.', 404);
        }
        return $company;
    }

    /** An archived firm is read-only (spec 25). */
    private static function writableCompany(int $accountId, int $companyId): array
    {
        $company = self::company($accountId, $companyId);
        if ($company['archived_at'] !== null) {
            Response::error('Firma je archivovaná — termíny sa už nemenia.', 409);
        }
        return $company;
    }

    /** @return array<string, mixed> */
    private static function term(int $accountId, int $companyId, int $termId): array
    {
        $term = ExternalTerms::find($accountId, $termId);
        if ($term === null || $term['company_id'] !== $companyId) {
            Response::error('Termín sa nenašiel.', 404);
        }
        return $term;
    }

    private static function name(?string $value): string
    {
        $value = $value !== null ? trim($value) : '';
        if ($value === '') {
            Response::error('Zadaj, o aký termín ide.', 422);
        }
        if (mb_strlen($value) > self::MAX_NAME) {
            Response::error('Názov termínu je príliš dlhý.', 422);
        }
        return $value;
    }

    /** The book is an explicit answer — it is never guessed from the name. */
    private static function book(?string $value): string
    {
        if ($value === null || !BookMapping::isBook($value)) {
            Response::error('Vyber, do ktorej knihy termín patrí.', 422);
        }
        return $value;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            Response::error('Text je príliš dlhý.', 422);
        }
        return $value;
    }

    /** A Y-m-d date, or null when left empty. */
    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            [$y, $m, $d] = array_map('intval', explode('-', $value));
            if (checkdate($m, $d, $y)) {
                return $value;
            }
        }
        Response::error('Neplatný dátum.', 422);
    }

    /** @param array<string, mixed> $body */
    private static function facilityId(array $body, int $companyId): ?int
    {
        $facilityId = $body['facility_id'] ?? null;
        if ($facilityId === null || $facilityId === '') {
            return null;
        }
        if (!is_int($facilityId)) {
            Response::error('Prevádzka sa nenašla.', 404);
        }
        $stmt = Db::pdo()->prepare('SELECT 1 FROM facilities WHERE id = ? AND company_id = ? AND archived_at IS NULL');
        $stmt->execute([$facilityId, $companyId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Prevádzka sa nenašla.', 404);
        }
        return $facilityId;
    }
}
