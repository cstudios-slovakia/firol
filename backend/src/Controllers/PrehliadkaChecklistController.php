<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\PrehliadkaChecklist;
use Firol\Support\PrehliadkaDetails;

/**
 * The checklist after a closed preventívna prehliadka (spec 12.2, plan 4.3) —
 * see {@see PrehliadkaChecklist} for what it holds and why.
 *
 *   GET  /api/inspections/{id}/prehliadka-checklist
 *        everything the round gives rise to, with what can be ticked
 *   POST /api/inspections/{id}/prehliadka-checklist/prepare    { keys: string[] }
 *        the ticked entries as draft úkony (follow-ups created with their
 *        carried values) → { inspection_ids, refused }
 *   POST /api/inspections/{id}/prehliadka-checklist/create     { key: string }
 *        the blank draft of an entry with nothing to carry (`manual_create`)
 *        → { inspection_id }
 *   GET  /api/inspections/{id}/prehliadka-checklist/review?ids=1,2,3
 *        every value that goes into those protocols, for the review screen
 *   POST /api/inspections/{id}/prehliadka-checklist/generate   { ids: int[], executed_on?: Y-m-d }
 *        issues each protocol on its own → { generated, skipped }
 *
 * Offline: the two reads are cached like any GET; prepare and generate are
 * online-only (they create úkony and take numbers). Edits made on the review
 * screen go through the ordinary item PATCH, which queues.
 */
final class PrehliadkaChecklistController
{
    public static function index(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $prehliadka = self::prehliadka($accountId, (int) $params['id']);

        Response::json([
            'prehliadka' => self::shape($prehliadka),
            'entries'    => PrehliadkaChecklist::entries($accountId, $prehliadka),
        ]);
    }

    public static function prepare(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $prehliadka = self::prehliadka($accountId, (int) $params['id'], true);

        $raw = $req->json()['keys'] ?? null;
        $keys = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
        if ($keys === []) {
            Response::error('Vyber aspoň jeden úkon.', 422);
        }

        Response::json(PrehliadkaChecklist::prepare($accountId, Tenant::currentUserId(), $prehliadka, $keys));
    }

    public static function createManual(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $prehliadka = self::prehliadka($accountId, (int) $params['id'], true);

        $key = $req->jsonString('key');
        if ($key === null || $key === '') {
            Response::error('Vyber úkon.', 422);
        }

        $result = PrehliadkaChecklist::createManual($accountId, Tenant::currentUserId(), $prehliadka, $key);
        if (isset($result['error'])) {
            Response::error((string) $result['error'], (int) $result['status'], Response::errorExtras($result));
        }
        Response::json($result, 201);
    }

    public static function review(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $prehliadka = self::prehliadka($accountId, (int) $params['id']);

        $ids = [];
        foreach (explode(',', (string) ($req->query('ids') ?? '')) as $part) {
            if (ctype_digit(trim($part))) {
                $ids[] = (int) trim($part);
            }
        }

        Response::json([
            'prehliadka' => self::shape($prehliadka),
            'blocks'     => PrehliadkaChecklist::review($accountId, $prehliadka, $ids),
        ]);
    }

    public static function generate(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $prehliadka = self::prehliadka($accountId, (int) $params['id'], true);

        $raw = $req->json()['ids'] ?? null;
        $ids = is_array($raw) ? array_values(array_filter($raw, 'is_int')) : [];
        if ($ids === []) {
            Response::error('Vyber aspoň jeden úkon.', 422);
        }
        $executedOn = $req->jsonString('executed_on');
        if ($executedOn !== null) {
            $parts = explode('-', $executedOn);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $executedOn) !== 1
                || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                Response::error('Neplatný dátum vykonania (očakáva sa formát YYYY-MM-DD).', 422);
            }
        }

        Response::json(PrehliadkaChecklist::generate($accountId, Tenant::currentUserId(), $prehliadka, $ids, $executedOn));
    }

    /**
     * A prehliadka of the caller's account; the writes need it closed.
     *
     * @return array<string, mixed>
     */
    private static function prehliadka(int $accountId, int $id, bool $closed = false): array
    {
        $row = DocumentController::loadInspectionForGenerate($accountId, $id);
        if ((string) $row['type'] !== PrehliadkaDetails::TYPE) {
            Response::error('Toto nie je preventívna prehliadka.', 422);
        }
        if ($closed && $row['status'] !== 'finalized') {
            Response::error('Prehliadka ešte nie je uzavretá.', 409);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'status'        => (string) $row['status'],
            'executed_on'   => $row['executed_on'],
            'company_id'    => (int) $row['company_id'],
            'company_name'  => (string) $row['company_name'],
            'facility_id'   => (int) $row['facility_id'],
            'facility_name' => (string) $row['facility_name'],
        ];
    }
}
