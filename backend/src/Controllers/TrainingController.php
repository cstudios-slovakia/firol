<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\MemberRights;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;
use Firol\Support\Periodicity;
use Firol\Support\PokynZatva;

final class TrainingController
{
    /** Locked enum slugs from docs/Firol base document. */
    private const TYPES = [
        'vstupne',
        'opakovane',
        'opp_mimo',
        'zdrzujuca_sa',
        'hliadka_oph',
        'hliadka_opah',
        // Change request 2.3 — not a školenie with an attendee list but a
        // document issued to the client's employees once a year, so it lives
        // here rather than among the inspection protocols. Its payload (year,
        // schvaľujúca osoba, instruction text) is stored in `trainings.fields`.
        self::TYPE_POKYN,
    ];

    /** The one training type that carries a `fields` payload instead of trainees. */
    public const TYPE_POKYN = 'pokyn_zatva';

    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());

        $companyId  = self::queryInt($req, 'company_id');
        $facilityId = self::queryInt($req, 'facility_id');
        $type       = $req->query('type');

        // The list never carries the Pokyn's full text — only the harvest year
        // it covers, which is all a row needs to identify itself. The body is
        // fetched with the detail.
        $sql = 'SELECT t.id, t.type, t.date, t.duration_min, t.topics, t.status,
                       t.periodicity_value, t.periodicity_unit, t.periodicity_is_custom,
                       t.created_at,
                       JSON_UNQUOTE(JSON_EXTRACT(t.fields, \'$.year\')) AS pokyn_year,
                       t.company_id, c.name AS company_name,
                       t.facility_id, f.name AS facility_name, t.visit_id,
                       t.trainer_id, tr.fullname AS trainer_name,
                       t.created_by_user_id,
                       t.billing_mode, t.invoiced, t.invoiced_at, t.billing_note,
                       (SELECT COUNT(*) FROM trainees WHERE training_id = t.id) AS trainees_count
                FROM   trainings t
                JOIN   companies  c  ON c.id = t.company_id
                LEFT JOIN facilities f  ON f.id = t.facility_id
                LEFT JOIN users      tr ON tr.id = t.trainer_id
                WHERE  t.archived_at IS NULL';
        $params = [];
        // Chapter 22 — „Nevyfakturované", filtered server-side so the row cap
        // below never hides an older training still waiting to be invoiced.
        if ($req->query('uninvoiced') === '1') {
            $sql .= ' AND ' . \Firol\Support\Invoicing::uninvoicedCondition('t');
        }
        if (!$isAdmin) {
            $sql .= ' AND t.account_id = :account_id';
            $params['account_id'] = $accountId;
        }

        if ($companyId !== null) {
            $sql .= ' AND t.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($facilityId !== null) {
            $sql .= ' AND t.facility_id = :facility_id';
            $params['facility_id'] = $facilityId;
        }
        if ($type !== null) {
            $sql .= ' AND t.type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY COALESCE(t.date, t.created_at) DESC LIMIT 200';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'shape'], $stmt->fetchAll());
        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);

        // A Pokyn has no attendee list — the trainees query would always come
        // back empty, and the detail page renders the instruction text instead.
        if ($row['type'] === self::TYPE_POKYN) {
            Response::json([
                'training' => self::shape($row),
                'trainees' => [],
            ]);
        }

        $tStmt = Db::pdo()->prepare(
            'SELECT id, fullname, position, signature_path, signed_at,
                    created_at, updated_at
             FROM   trainees
             WHERE  training_id = ?
             ORDER  BY id ASC'
        );
        $tStmt->execute([$id]);
        $trainees = array_map(static function (array $r): array {
            return [
                'id'             => (int) $r['id'],
                'fullname'       => $r['fullname'],
                'position'       => $r['position'],
                'has_signature'  => !empty($r['signature_path']),
                'signed_at'      => $r['signed_at'],
                'created_at'     => $r['created_at'],
                'updated_at'     => $r['updated_at'],
            ];
        }, $tStmt->fetchAll());

        Response::json([
            'training' => self::shape($row),
            'trainees' => $trainees,
        ]);
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $type        = $req->jsonString('type');
        $companyId   = $req->jsonInt('company_id');
        $facilityId  = $req->jsonInt('facility_id');
        $date        = $req->jsonString('date');
        $trainerId   = $req->jsonInt('trainer_id');
        $topics      = $req->jsonString('topics');
        $durationMin = $req->jsonInt('duration_min');
        $visitId     = $req->jsonInt('visit_id');

        if ($type === null || !in_array($type, self::TYPES, true)) {
            Response::error('Neplatný typ školenia.', 422);
        }
        // Inside a visit (chapter 9) the company, prevádzka and date were chosen
        // once, when the visit started: they are the visit's, not the request's.
        // Overriding is friendlier offline than rejecting a queued draft. The
        // Pokyn is a document for the client's employees, not a školenie PO, so
        // it is never recorded under a visit.
        if ($visitId !== null) {
            if ($type === self::TYPE_POKYN) {
                Response::error('Pokyn sa nezapisuje v návšteve — nie je to školenie PO.', 422);
            }
            $visit = VisitController::findForUkon(
                $visitId,
                Admin::isAdmin(Tenant::currentUserId()),
                $accountId,
            );
            if ($visit === null) {
                Response::error('Návšteva sa nenašla.', 404);
            }
            $companyId  = $visit['company_id'];
            $facilityId = $visit['facility_id'];
            $date       = $visit['visit_date'];
        }
        // The Pokyn's text may be supplied at creation (the UI seeds it from
        // the template) or filled in afterwards on the detail page — it is only
        // required once the PDF is generated.
        $fields = self::fieldsForType($req, $type, required: false);
        [$periodicityValue, $periodicityUnit] = self::readPeriodicity($req);
        if ($companyId === null) {
            Response::error('Vyber firmu.', 422);
        }
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Neplatný dátum (očakáva sa formát YYYY-MM-DD).', 422);
        }

        // Verify company belongs to the account; facility (optional) must
        // belong to the same company. Trainer (optional) must belong to
        // the account. Admins may create trainings for companies from any
        // account — the training is stored under the company's account_id.
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        if ($isAdmin) {
            $check = Db::pdo()->prepare(
                'SELECT account_id FROM companies WHERE id = ? AND archived_at IS NULL'
            );
            $check->execute([$companyId]);
            $companyAccountId = $check->fetchColumn();
            if ($companyAccountId === false) {
                Response::error('Firma sa nenašla.', 404);
            }
            $accountId = (int) $companyAccountId;
        } else {
            $check = Db::pdo()->prepare(
                'SELECT 1 FROM companies WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $check->execute([$companyId, $accountId]);
            if ($check->fetchColumn() === false) {
                Response::error('Firma sa nenašla.', 404);
            }
        }

        if ($facilityId !== null) {
            // Facility must belong to the chosen company; tenant scope on
            // the facility itself is implied by the company check above.
            // Admins are not constrained by account here.
            $fc = Db::pdo()->prepare(
                'SELECT 1 FROM facilities
                 WHERE id = ? AND company_id = ? AND archived_at IS NULL'
            );
            $fc->execute([$facilityId, $companyId]);
            if ($fc->fetchColumn() === false) {
                Response::error('Prevádzka nepatrí k vybranej firme.', 422);
            }
        }

        if ($trainerId !== null) {
            if ($isAdmin) {
                $tc = Db::pdo()->prepare('SELECT 1 FROM users WHERE id = ?');
                $tc->execute([$trainerId]);
            } else {
                $tc = Db::pdo()->prepare(
                    'SELECT 1 FROM account_users
                     WHERE user_id = ? AND account_id = ? AND is_active = 1'
                );
                $tc->execute([$trainerId, $accountId]);
            }
            if ($tc->fetchColumn() === false) {
                Response::error('Školiteľ sa nenašiel.', 422);
            }
        }

        Db::pdo()->prepare(
            'INSERT INTO trainings
                (account_id, company_id, facility_id, visit_id, type, date,
                 periodicity_value, periodicity_unit, periodicity_is_custom,
                 trainer_id, topics, duration_min, fields, status, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
        )->execute([
            $accountId, $companyId, $facilityId, $visitId, $type, $date,
            $periodicityValue, $periodicityUnit,
            Periodicity::isCustomForTraining($type, $periodicityValue, $periodicityUnit) ? 1 : 0,
            $trainerId, $topics, $durationMin,
            $fields !== null ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null,
            Tenant::currentUserId(),
        ]);
        $id = (int) Db::pdo()->lastInsertId();

        // Chapter 22 — the režim starts from the firm's setting.
        Db::pdo()->prepare('UPDATE trainings SET billing_mode = ? WHERE id = ?')
            ->execute([\Firol\Support\Invoicing::companyMode($companyId), $id]);

        Response::json(['training' => self::shape(self::loadOrFail($accountId, $id))], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];
        $existing  = self::loadOrFail($isAdmin ? null : $accountId, $id);
        // For admins, persist the update under the training's own account
        // rather than the admin's session account.
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;

        $date        = $req->jsonString('date');
        $trainerId   = $req->jsonInt('trainer_id');
        $topics      = $req->jsonString('topics');
        $durationMin = $req->jsonInt('duration_min');
        $fields      = self::fieldsForType($req, (string) $existing['type'], required: false);

        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Neplatný dátum (očakáva sa formát YYYY-MM-DD).', 422);
        }
        // The date of a training in a visit is the visit's (chapter 9) —
        // ignored rather than rejected, so an edit queued offline still saves
        // the rest.
        if ($existing['visit_id'] !== null) {
            $date = null;
        }
        // Changing a finalized training (date, trainer, topics, duration) is the
        // training's counterpart of „Upraviť" on a locked inspection, so it
        // follows the same members' switch (chapter 1.6).
        if ($existing['status'] === 'finalized') {
            MemberRights::requireDelete($scopeAccountId);
        }
        // The periodicity is printed on the protocol, so it sits behind the same
        // guard as the date above. It is edited as a whole — „bez opakovania"
        // has to be expressible, and a COALESCE over two columns cannot tell
        // „leave it alone" from „clear it" — so sending either key means the
        // technician touched the field (same as on inspections).
        $body = $req->json();
        $touchesPeriodicity = array_key_exists('periodicity_value', $body)
            || array_key_exists('periodicity_unit', $body);
        if ($touchesPeriodicity) {
            [$periodicityValue, $periodicityUnit] = self::readPeriodicity($req);
        }
        // A finalized training is the record of an issued document — its text
        // must keep matching the PDF that carries its number.
        if ($fields !== null && $existing['status'] === 'finalized') {
            Response::error('Pokyn už je vystavený — jeho text sa nedá meniť.', 409);
        }
        if ($trainerId !== null) {
            if ($isAdmin) {
                $tc = Db::pdo()->prepare('SELECT 1 FROM users WHERE id = ?');
                $tc->execute([$trainerId]);
            } else {
                $tc = Db::pdo()->prepare(
                    'SELECT 1 FROM account_users
                     WHERE user_id = ? AND account_id = ? AND is_active = 1'
                );
                $tc->execute([$trainerId, $accountId]);
            }
            if ($tc->fetchColumn() === false) {
                Response::error('Školiteľ sa nenašiel.', 422);
            }
        }

        Db::pdo()->prepare(
            'UPDATE trainings
             SET    date         = COALESCE(?, date),
                    trainer_id   = COALESCE(?, trainer_id),
                    topics       = COALESCE(?, topics),
                    duration_min = COALESCE(?, duration_min),
                    fields       = COALESCE(?, fields)
             WHERE  id = ? AND account_id = ?'
        )->execute([
            $date, $trainerId, $topics, $durationMin,
            $fields !== null ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null,
            $id, $scopeAccountId,
        ]);
        if ($touchesPeriodicity) {
            Db::pdo()->prepare(
                'UPDATE trainings
                 SET    periodicity_value     = ?,
                        periodicity_unit      = ?,
                        periodicity_is_custom = ?
                 WHERE  id = ? AND account_id = ?'
            )->execute([
                $periodicityValue,
                $periodicityUnit,
                Periodicity::isCustomForTraining((string) $existing['type'], $periodicityValue, $periodicityUnit) ? 1 : 0,
                $id,
                $scopeAccountId,
            ]);
        }
        unset($existing);

        Response::json(['training' => self::shape(self::loadOrFail($isAdmin ? null : $accountId, $id))]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];
        $existing  = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;
        MemberRights::requireDeleteUkon(
            $existing,
            $existing['trainer_id'] !== null ? (int) $existing['trainer_id'] : null,
        );

        // Remove trainee signature files from disk before the DB row is gone.
        $dir = Storage::root() . "/trainings/$id";
        if (is_dir($dir)) {
            foreach (glob("$dir/*.png") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        Db::pdo()->prepare(
            'DELETE FROM trainings WHERE id = ? AND account_id = ?'
        )->execute([$id, $scopeAccountId]);

        Response::noContent();
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(?int $accountId, int $id): array
    {
        $sql = 'SELECT t.id, t.account_id, t.type, t.date, t.duration_min, t.topics,
                       t.periodicity_value, t.periodicity_unit, t.periodicity_is_custom,
                       t.fields, t.status, t.created_at, t.updated_at,
                       t.company_id, c.name AS company_name, c.ico AS company_ico,
                       c.approver AS company_approver,
                       t.facility_id, f.name AS facility_name, t.visit_id,
                       t.trainer_id, tr.fullname AS trainer_name,
                       t.created_by_user_id,
                       ip.cert_general AS trainer_certification_number,
                       t.billing_mode, t.invoiced, t.invoiced_at, t.billing_note,
                       (SELECT COUNT(*) FROM trainees WHERE training_id = t.id) AS trainees_count
                FROM   trainings t
                JOIN   companies   c  ON c.id = t.company_id
                LEFT JOIN facilities f  ON f.id = t.facility_id
                LEFT JOIN users      tr ON tr.id = t.trainer_id
                LEFT JOIN inspector_profiles ip
                       ON ip.user_id = t.trainer_id AND ip.account_id = t.account_id
                WHERE  t.id = ? AND t.archived_at IS NULL';
        $params = [$id];
        if ($accountId !== null) {
            $sql .= ' AND t.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Školenie sa nenašlo.', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $row['id']             = (int) $row['id'];
        $row['company_id']     = (int) $row['company_id'];
        $row['facility_id']    = $row['facility_id'] !== null ? (int) $row['facility_id'] : null;
        $row['visit_id']       = isset($row['visit_id']) ? (int) $row['visit_id'] : null;
        $row['trainer_id']     = $row['trainer_id'] !== null ? (int) $row['trainer_id'] : null;
        $row['created_by_user_id'] = isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null;
        $row['duration_min']   = $row['duration_min'] !== null ? (int) $row['duration_min'] : null;
        $row['trainees_count'] = isset($row['trainees_count']) ? (int) $row['trainees_count'] : 0;
        $row['periodicity_value'] = isset($row['periodicity_value'])
            ? (int) $row['periodicity_value']
            : null;
        $row['periodicity_unit'] = $row['periodicity_unit'] ?? null;
        $row['periodicity_is_custom'] = (bool) ($row['periodicity_is_custom'] ?? false);
        // Derived, never stored — null without recurrence or without a date.
        $row['valid_until'] = Periodicity::validUntil(
            $row['date'] ?? null,
            $row['periodicity_value'],
            $row['periodicity_unit'],
        );
        // `fields` reaches the client decoded (detail) — the list only carries
        // the year it extracted, so the Pokyn's text never rides along there.
        if (array_key_exists('fields', $row)) {
            $row['fields'] = PokynZatva::decode($row['fields']);
        }
        if (array_key_exists('pokyn_year', $row)) {
            $row['pokyn_year'] = $row['pokyn_year'] !== null ? (int) $row['pokyn_year'] : null;
        }
        // Chapter 22 — fakturácia úkonu.
        $row = \Firol\Support\Invoicing::shape($row);
        unset($row['account_id']);
        return $row;
    }

    /**
     * Reads the per-type document payload off the request. Only pokyn_zatva
     * has one; sending `fields` for a training with an attendee list is a
     * client bug worth reporting rather than silently dropping.
     *
     * @return array<string, mixed>|null null when the request carries no payload
     */
    private static function fieldsForType(Request $req, string $type, bool $required): ?array
    {
        $raw = $req->json()['fields'] ?? null;

        if ($type !== self::TYPE_POKYN) {
            if ($raw !== null) {
                Response::error('Tento typ školenia nemá text dokumentu.', 422);
            }
            return null;
        }
        if ($raw === null) {
            if ($required) {
                Response::error('Doplň text pokynu.', 422);
            }
            return null;
        }
        if (!is_array($raw)) {
            Response::error('Pole „fields“ musí byť objekt.', 422);
        }

        try {
            return PokynZatva::validate($raw);
        } catch (\DomainException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * Read and validate the periodicity pair off the request body, turning a
     * bad pair into a 422 with a Slovak message (same as on inspections).
     *
     * @return array{0: int|null, 1: string|null}
     */
    private static function readPeriodicity(Request $req): array
    {
        $body = $req->json();
        try {
            return Periodicity::normalize(
                $body['periodicity_value'] ?? null,
                $body['periodicity_unit'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $v = $req->query($key);
        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }
}
