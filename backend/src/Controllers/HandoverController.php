<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;

/**
 * Handing a protocol over for signature on the technician's screen —
 * block 1 / chapter 13.
 *
 * What is being signed is RECEIPT of the protocol, not agreement with what it
 * says; the findings are the technician's and stay theirs. The wording above
 * the signature line follows the document type and is decided in
 * {@see \Firol\Support\Handover}.
 *
 * Signing re-renders the PDF as a new VERSION of the same number. A new number
 * is never issued: the client may already hold version 1, and one number
 * resolving to two different documents is worse than a spare file on disk.
 *
 * Signing is optional by design. A protocol printed and signed by hand
 * afterwards is ordinary practice, so an unsigned protocol is a finished
 * protocol — the empty line on the page is there for exactly that.
 */
final class HandoverController
{
    /** A canvas signature is a few KB; anything this large is not one. */
    private const MAX_SIGNATURE_BYTES = 524288;

    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $documentId = (int) $params['id'];

        $doc = self::loadDocument($isAdmin ? null : $accountId, $documentId);
        if ($doc === null) {
            Response::error('Dokument sa nenašiel.', 404);
        }
        $accountId = (int) $doc['account_id'];

        if ($doc['parent_type'] === 'training') {
            Response::error('Školenie sa podpisuje na prezenčnej listine, nie tu.', 422);
        }

        [$fullname, $roleTitle, $personId] = self::resolveSignatory($req, $accountId, $doc);

        $place = $req->jsonString('place');
        if ($place === null || $place === '') {
            Response::error('Zadaj miesto podpisu.', 422);
        }
        $signedOn = $req->jsonString('signed_on');
        if ($signedOn === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $signedOn)) {
            Response::error('Zadaj dátum podpisu.', 422);
        }
        $signedTime = $req->jsonString('signed_at_time');
        if ($signedTime === null || !preg_match('/^\d{2}:\d{2}$/', $signedTime)) {
            Response::error('Zadaj čas podpisu.', 422);
        }

        $png = self::decodeSignature($req->jsonString('signature'));

        $relSig = Storage::handoverSignatureRelative($accountId, $documentId);
        $absSig = Storage::absolute($relSig);
        Storage::ensureDir(dirname($absSig));
        if (file_put_contents($absSig, $png) === false) {
            Response::error('Podpis sa nepodarilo uložiť.', 500);
        }

        $handover = [
            'fullname'           => $fullname,
            'role_title'         => $roleTitle,
            'place'              => $place,
            'signed_on'          => $signedOn,
            'signature_data_uri' => 'data:image/png;base64,' . base64_encode($png),
        ];

        try {
            $newPath = DocumentController::rerenderWithHandover($doc, $handover);
        } catch (\Throwable $e) {
            error_log('[handover] rerender failed: ' . $e::class . ': ' . $e->getMessage());
            Response::error('Protokol s podpisom sa nepodarilo vygenerovať.', 500);
        }

        $version = (int) $doc['version'] + 1;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // REPLACE, not INSERT: re-signing a protocol (wrong person, unclear
            // signature) overwrites the record and produces one more version
            // rather than stacking rows nobody can tell apart.
            $pdo->prepare(
                'REPLACE INTO document_handovers
                    (document_id, account_id, person_id, fullname, role_title,
                     signature_path, place, signed_on, signed_at_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $documentId, $accountId, $personId, $fullname, $roleTitle,
                $relSig, $place, $signedOn, $signedTime,
            ]);

            $pdo->prepare(
                'INSERT INTO document_versions (document_id, version, file_path)
                 VALUES (?, ?, ?)'
            )->execute([$documentId, $version, $newPath]);

            $pdo->prepare(
                'UPDATE documents SET version = ?, file_path = ? WHERE id = ?'
            )->execute([$version, $newPath, $documentId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if (is_file(Storage::documentAbsolute($newPath))) {
                @unlink(Storage::documentAbsolute($newPath));
            }
            throw $e;
        }

        AuditLog::record(
            'document.handover',
            'documents',
            $documentId,
            ['version' => (int) $doc['version']],
            ['version' => $version, 'signed_by' => $fullname],
        );

        Response::json([
            'document' => [
                'id'           => $documentId,
                'number'       => (string) $doc['number'],
                'version'      => $version,
                'download_url' => '/api/documents/' . $documentId . '/download',
            ],
            'handover' => [
                'fullname'   => $fullname,
                'role_title' => $roleTitle,
                'place'      => $place,
                'signed_on'  => $signedOn,
            ],
        ], 201);
    }

    /**
     * Who is signing: either a person saved on the company, or a name and role
     * typed on the spot. The stored person's name and role are copied onto the
     * handover rather than referenced, so a protocol keeps saying who signed it
     * even after that person is edited or leaves.
     *
     * @param array<string, mixed> $doc
     * @return array{0: string, 1: string, 2: int|null} [fullname, role, person_id]
     */
    private static function resolveSignatory(Request $req, int $accountId, array $doc): array
    {
        $personId = $req->jsonInt('person_id');
        if ($personId !== null) {
            $stmt = Db::pdo()->prepare(
                'SELECT p.fullname, p.role_title
                 FROM   company_persons p
                 WHERE  p.id = ? AND p.account_id = ? AND p.archived_at IS NULL'
            );
            $stmt->execute([$personId, $accountId]);
            $person = $stmt->fetch();
            if (!$person) {
                Response::error('Vybraná osoba sa nenašla.', 404);
            }
            return [(string) $person['fullname'], (string) $person['role_title'], $personId];
        }

        $fullname = $req->jsonString('fullname');
        $roleTitle = $req->jsonString('role_title');
        if ($fullname === null || $fullname === '') {
            Response::error('Zadaj meno osoby, ktorá protokol preberá.', 422);
        }
        if ($roleTitle === null || $roleTitle === '') {
            Response::error('Zadaj funkciu osoby (napr. konateľ).', 422);
        }
        return [$fullname, $roleTitle, null];
    }

    /** Decode the canvas PNG data URI, refusing anything that is not one. */
    private static function decodeSignature(?string $dataUri): string
    {
        if ($dataUri === null || $dataUri === '') {
            Response::error('Podpis chýba — nechaj klienta podpísať sa na displeji.', 422);
        }
        if (!preg_match('#^data:image/png;base64,#', $dataUri)) {
            Response::error('Podpis musí byť PNG obrázok.', 422);
        }
        $binary = base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true);
        if ($binary === false || $binary === '') {
            Response::error('Podpis sa nepodarilo prečítať.', 422);
        }
        if (strlen($binary) > self::MAX_SIGNATURE_BYTES) {
            Response::error('Podpis je príliš veľký (max 512 KB).', 422);
        }
        // Magic bytes, not just the declared mime — the data URI prefix is
        // whatever the caller typed.
        if (!str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            Response::error('Podpis musí byť PNG obrázok.', 422);
        }
        return $binary;
    }

    /** @return array<string, mixed>|null */
    private static function loadDocument(?int $accountId, int $documentId): ?array
    {
        $sql = 'SELECT id, account_id, parent_type, parent_id, type, number, version,
                       include_photos, file_path, generated_at
                FROM   documents WHERE id = ?';
        $args = [$documentId];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
