<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Session;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * In-app feedback: bug reports and feature requests submitted from the
 * floating widget or settings entry. Any authenticated user may submit;
 * only app admins can list/delete.
 *
 * We snapshot submitter name/email and account name at write time so a
 * deleted user/account doesn't lose attribution.
 */
final class FeedbackController
{
    private const KINDS = ['bug', 'feature'];
    private const MAX_MESSAGE = 5000;

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $userId    = Tenant::currentUserId();
        $accountId = Session::activeAccountId();

        $kind    = $req->jsonString('kind');
        $message = $req->jsonString('message');
        $sourceUrl = $req->jsonString('source_url');

        if ($kind === null || !in_array($kind, self::KINDS, true)) {
            Response::error('Invalid kind', 422);
        }
        if ($message === null || $message === '') {
            Response::error('Message required', 422);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            Response::error('Message too long', 422);
        }
        if ($sourceUrl !== null && mb_strlen($sourceUrl) > 1024) {
            $sourceUrl = mb_substr($sourceUrl, 0, 1024);
        }

        $userAgent = $req->header('User-Agent');
        if ($userAgent !== null && strlen($userAgent) > 512) {
            $userAgent = substr($userAgent, 0, 512);
        }

        $pdo = Db::pdo();

        $uStmt = $pdo->prepare('SELECT fullname, email FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $u = $uStmt->fetch() ?: ['fullname' => null, 'email' => null];

        $accountName = null;
        if ($accountId !== null) {
            $aStmt = $pdo->prepare('SELECT invoice_company_name FROM accounts WHERE id = ?');
            $aStmt->execute([$accountId]);
            $accountName = $aStmt->fetchColumn() ?: null;
        }

        $pdo->prepare(
            'INSERT INTO feedback_submissions
                (kind, message, source_url, user_agent,
                 account_id, user_id, submitter_name, submitter_email, account_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $kind,
            $message,
            $sourceUrl,
            $userAgent,
            $accountId,
            $userId,
            $u['fullname'] ?? null,
            $u['email'] ?? null,
            $accountName,
        ]);

        Response::json(['ok' => true], 201);
    }

    public static function index(Request $req): void
    {
        Admin::require();

        $stmt = Db::pdo()->query(
            'SELECT id, kind, message, source_url, user_agent,
                    account_id, user_id, submitter_name, submitter_email,
                    account_name, created_at
             FROM   feedback_submissions
             ORDER  BY created_at DESC, id DESC'
        );
        $rows = $stmt->fetchAll();

        $items = array_map(static fn(array $r) => [
            'id'              => (int) $r['id'],
            'kind'            => (string) $r['kind'],
            'message'         => (string) $r['message'],
            'source_url'      => $r['source_url'],
            'user_agent'      => $r['user_agent'],
            'account_id'      => $r['account_id'] !== null ? (int) $r['account_id'] : null,
            'user_id'         => $r['user_id']    !== null ? (int) $r['user_id']    : null,
            'submitter_name'  => $r['submitter_name'],
            'submitter_email' => $r['submitter_email'],
            'account_name'    => $r['account_name'],
            'created_at'      => $r['created_at'],
        ], $rows);

        Response::json(['items' => $items]);
    }

    /** @param array<string, string> $params */
    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        Admin::require();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid id', 422);
        }
        Db::pdo()->prepare('DELETE FROM feedback_submissions WHERE id = ?')->execute([$id]);
        Response::noContent();
    }
}
