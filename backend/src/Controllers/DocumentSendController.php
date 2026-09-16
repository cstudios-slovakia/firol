<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Mail\Mailer;
use Firol\Mail\ReplyTo;
use Firol\Mail\Templates\BulkDocumentEmail;
use Firol\Storage\Storage;

/**
 * Sending several protocols in one e-mail — block 1 / chapter 9.1.
 *
 * Reached from two places, deliberately: at the end of a visit, and from the
 * company history, where a client asking for "everything you did for us last
 * year" is answered by ticking the rows and pressing send once.
 *
 * Every send is recorded against the company — when, to whom, which protocols
 * and whether it went through. "Did you send it?" is a question technicians
 * are asked often enough that the app has to be able to answer it.
 */
final class DocumentSendController
{
    /**
     * Mailbox providers reject oversized messages outright, and a protocol
     * that silently never arrives is worse than one that was never sent.
     */
    private const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

    /** History of sends for one company, newest first. */
    public static function index(Request $req, array $params): void
    {
        $companyId = (int) $params['id'];
        $accountId = self::assertCompany($companyId);

        $stmt = Db::pdo()->prepare(
            'SELECT s.id, s.visit_id, s.document_ids, s.recipients, s.subject, s.note,
                    s.status, s.sent_at, s.error_text, s.created_at,
                    u.fullname AS sent_by_name
             FROM   document_sends s
             LEFT   JOIN users u ON u.id = s.sent_by_user_id
             WHERE  s.company_id = ? AND s.account_id = ?
             ORDER  BY s.created_at DESC
             LIMIT  100'
        );
        $stmt->execute([$companyId, $accountId]);

        $rows = $stmt->fetchAll();
        $numbersById = self::documentNumbers($accountId);

        $items = array_map(static function (array $r) use ($numbersById): array {
            $ids = json_decode((string) $r['document_ids'], true) ?: [];
            return [
                'id'         => (int) $r['id'],
                'visit_id'   => $r['visit_id'] !== null ? (int) $r['visit_id'] : null,
                'documents'  => array_values(array_map(
                    static fn (int $id): array => [
                        'id'     => $id,
                        'number' => $numbersById[$id] ?? '—',
                    ],
                    array_map('intval', (array) $ids),
                )),
                'recipients' => json_decode((string) $r['recipients'], true) ?: [],
                'subject'    => (string) $r['subject'],
                'note'       => $r['note'],
                'status'     => (string) $r['status'],
                'sent_at'    => $r['sent_at'],
                'error_text' => $r['error_text'],
                'created_at' => $r['created_at'],
                'sent_by'    => $r['sent_by_name'],
            ];
        }, $rows);

        Response::json(['items' => $items]);
    }

    /**
     * Send the chosen protocols to the chosen recipients.
     *
     * One message per recipient, each carrying every attachment: it keeps one
     * client's address out of another's headers, and a single failing address
     * does not take the rest of the send with it.
     */
    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $companyId = (int) $params['id'];
        $accountId = self::assertCompany($companyId);
        $userId = Tenant::currentUserId();

        $body = $req->json();
        $documentIds = self::readIds($body['document_ids'] ?? null);
        if ($documentIds === []) {
            Response::error('Vyber aspoň jeden protokol na odoslanie.', 422);
        }
        $recipients = self::readRecipients($body['recipients'] ?? null);
        $note = $req->jsonString('note');
        $visitId = $req->jsonInt('visit_id');

        $documents = self::loadDocuments($accountId, $companyId, $documentIds);
        if (count($documents) !== count($documentIds)) {
            Response::error('Niektorý z vybraných protokolov sa nenašiel pri tejto firme.', 404);
        }

        $subject = $req->jsonString('subject');
        if ($subject === null || $subject === '') {
            $subject = self::defaultSubject($accountId, $companyId);
        }

        // Read the files before recording anything: a protocol whose PDF is
        // missing from disk has to stop the send, not produce a history row
        // claiming it went out.
        $attachments = [];
        $totalBytes = 0;
        foreach ($documents as $doc) {
            $abs = Storage::documentAbsolute((string) $doc['file_path']);
            if (!is_file($abs)) {
                error_log('[document-send] file missing: ' . $abs);
                Response::error(
                    'Súbor protokolu ' . $doc['number'] . ' nie je k dispozícii.',
                    410,
                );
            }
            $bytes = file_get_contents($abs);
            if ($bytes === false) {
                Response::error('Protokol ' . $doc['number'] . ' sa nepodarilo načítať.', 500);
            }
            $totalBytes += strlen($bytes);
            $attachments[] = [
                'filename' => $doc['number'] . '.pdf',
                'bytes'    => $bytes,
                'number'   => (string) $doc['number'],
            ];
        }

        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            Response::error(
                'Prílohy majú spolu ' . self::humanSize($totalBytes)
                . ' — to je nad limit 20 MB, ktorý väčšina schránok prijme. '
                . 'Odošli protokoly na dvakrát alebo niektorý odznač.',
                422,
                ['code' => 'attachments_too_large', 'total_bytes' => $totalBytes],
            );
        }

        $brandName = self::brandName($accountId);

        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO document_sends
                (account_id, company_id, visit_id, document_ids, recipients, subject, note,
                 status, sent_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, "caka", ?)'
        )->execute([
            $accountId,
            $companyId,
            $visitId,
            json_encode(array_map('intval', $documentIds)),
            json_encode($recipients, JSON_UNESCAPED_UNICODE),
            $subject,
            $note,
            $userId,
        ]);
        $sendId = (int) $pdo->lastInsertId();

        $replyTo = ReplyTo::forSender($accountId, $userId);

        $failed = [];
        foreach ($recipients as $to) {
            $message = BulkDocumentEmail::build(
                to:         $to,
                subject:    $subject,
                brandName:  $brandName,
                documents:  $attachments,
                note:       $note,
                replyTo:    $replyTo,
            );
            if (!Mailer::send($message)) {
                $failed[] = $to;
            }
        }

        if ($failed === []) {
            $pdo->prepare(
                'UPDATE document_sends SET status = "odoslane", sent_at = NOW() WHERE id = ?'
            )->execute([$sendId]);
            Response::json([
                'send_id'    => $sendId,
                'status'     => 'odoslane',
                'recipients' => $recipients,
                'documents'  => count($documents),
            ], 201);
        }

        $error = 'Odoslanie zlyhalo na adresy: ' . implode(', ', $failed);
        $pdo->prepare(
            'UPDATE document_sends SET status = "chyba", error_text = ? WHERE id = ?'
        )->execute([$error, $sendId]);

        Response::error(
            count($failed) === count($recipients)
                ? 'E-mail sa nepodarilo odoslať. Skús to o chvíľu znova.'
                : $error,
            502,
            ['send_id' => $sendId, 'failed' => $failed],
        );
    }

    /**
     * Protocols of this company that can be attached to a send — the whole
     * history, newest first, so "everything from last year" is a matter of
     * ticking rows.
     */
    public static function available(Request $req, array $params): void
    {
        $companyId = (int) $params['id'];
        $accountId = self::assertCompany($companyId);

        $stmt = Db::pdo()->prepare(
            'SELECT d.id, d.type, d.number, d.generated_at, d.file_path,
                    i.executed_on, i.facility_id, f.name AS facility_name
             FROM   documents d
             JOIN   inspections i ON i.id = d.parent_id AND d.parent_type = "inspection"
             JOIN   facilities  f ON f.id = i.facility_id
             WHERE  d.account_id = ? AND i.company_id = ? AND i.archived_at IS NULL
             ORDER  BY COALESCE(i.executed_on, d.generated_at) DESC, d.id DESC
             LIMIT  300'
        );
        $stmt->execute([$accountId, $companyId]);

        $items = array_map(static function (array $r): array {
            $abs = Storage::documentAbsolute((string) $r['file_path']);
            return [
                'id'            => (int) $r['id'],
                'type'          => (string) $r['type'],
                'number'        => (string) $r['number'],
                'executed_on'   => $r['executed_on'],
                'facility_id'   => (int) $r['facility_id'],
                'facility_name' => (string) $r['facility_name'],
                // Shown next to each row so the technician can see which
                // protocol is pushing the send over the mailbox limit.
                'byte_size'     => is_file($abs) ? (int) filesize($abs) : 0,
            ];
        }, $stmt->fetchAll());

        Response::json([
            'items' => $items,
            'max_total_bytes' => self::MAX_TOTAL_BYTES,
        ]);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function readIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function readRecipients(mixed $raw): array
    {
        if (!is_array($raw)) {
            Response::error('Zadaj aspoň jedného príjemcu.', 422);
        }
        $out = [];
        foreach ($raw as $v) {
            $email = is_string($v) ? trim($v) : '';
            if ($email === '') {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Neplatná e-mailová adresa: ' . $email, 422);
            }
            $out[strtolower($email)] = $email;
        }
        if ($out === []) {
            Response::error('Zadaj aspoň jedného príjemcu.', 422);
        }
        return array_values($out);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function loadDocuments(int $accountId, int $companyId, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT d.id, d.number, d.file_path
             FROM   documents d
             JOIN   inspections i ON i.id = d.parent_id AND d.parent_type = 'inspection'
             WHERE  d.account_id = ? AND i.company_id = ? AND d.id IN ($placeholders)
             ORDER  BY d.id ASC"
        );
        $stmt->execute(array_merge([$accountId, $companyId], $ids));
        return $stmt->fetchAll();
    }

    /** @return array<int, string> document id => number, for history rows */
    private static function documentNumbers(int $accountId): array
    {
        $stmt = Db::pdo()->prepare('SELECT id, number FROM documents WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = (string) $r['number'];
        }
        return $out;
    }

    private static function defaultSubject(int $accountId, int $companyId): string
    {
        $stmt = Db::pdo()->prepare('SELECT name FROM companies WHERE id = ? AND account_id = ?');
        $stmt->execute([$companyId, $accountId]);
        $name = (string) ($stmt->fetchColumn() ?: '');
        return 'Protokoly z kontroly — ' . $name . ', ' . date('j. n. Y');
    }

    private static function brandName(int $accountId): string
    {
        $stmt = Db::pdo()->prepare('SELECT invoice_company_name FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        return (string) ($stmt->fetchColumn() ?: 'POapp');
    }

    private static function humanSize(int $bytes): string
    {
        return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' MB';
    }

    /** Resolve the company and return the account it belongs to. */
    private static function assertCompany(int $companyId): int
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());

        $sql = 'SELECT account_id FROM companies WHERE id = ?';
        $args = [$companyId];
        if (!$isAdmin) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $found = $stmt->fetchColumn();
        if ($found === false) {
            Response::error('Firma sa nenašla.', 404);
        }
        return (int) $found;
    }
}
