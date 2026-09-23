-- Block 4 / chapter 20 — úlohy.
--
-- A plain to-do list, not a project tool: text, optional firma + prevádzka,
-- optional assignee, optional termín, done / not done. No priorities, tags or
-- subtasks (POKYNY_PRE_AI.md).
--
--   company_id / facility_id   NULL = „všeobecná úloha"
--   assignee_user_id           spec `kto_id`; NULL = nepriradená. Nulled when
--                              the user is deleted, and by TeamController when
--                              a member is removed from the account (that
--                              only deletes the account_users row, so no FK
--                              fires there).
--   done / done_at             spec `splnene` / `splnene_kedy`
--   source_inspection_id +     spec `zdroj_nedostatok_id`. A nedostatok is not
--   source_defect_key          a table: it lives in inspection_items.fields
--                              .defects[] and is identified by its `key`
--                              (the same key its photos carry in
--                              inspection_item_photos.defect_key). The key is
--                              a client-minted UUID, unique within the úkon,
--                              so úkon + key names the nedostatok without
--                              depending on which item it sits on — and the
--                              úkon is what the task links back to and whose
--                              protocol number it prints („vzniklo z
--                              kontroly {cislo}"). One task per nedostatok:
--                              the unique key makes the offer idempotent
--                              across devices and offline replays.
--   created_by_user_id         spec `vytvoril_id`; nullable only so deleting a
--                              user never fails on a task they wrote.
--
-- No ON DELETE SET NULL may be reachable from the account cascade. Deleting
-- an account also cascades through companies → facilities / inspections, and
-- InnoDB rejects a SET NULL on a task row whose account is being deleted in
-- the same statement ("Cannot add or update a child row", fk_tasks_account) —
-- the admin's „zmazať účet" would fail for any account with tasks. Hence:
-- facility_id CASCADEs (a prevádzka is only ever hard-deleted together with
-- its firm, whose tasks go anyway), and source_inspection_id has no FK at all:
-- the only hard delete of an úkon is the „zmazať kontroly" purge, after which
-- the task stays and simply loses its „vzniklo z kontroly" line (the list
-- joins the úkon and finds nothing). Ids are never reused, so a dangling one
-- can't point at a different úkon later.

CREATE TABLE tasks (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id           INT UNSIGNED  NOT NULL,
    text                 VARCHAR(1000) NOT NULL,
    company_id           INT UNSIGNED  NULL DEFAULT NULL,
    facility_id          INT UNSIGNED  NULL DEFAULT NULL,
    assignee_user_id     INT UNSIGNED  NULL DEFAULT NULL,
    due_date             DATE          NULL DEFAULT NULL,
    done                 TINYINT(1)    NOT NULL DEFAULT 0,
    done_at              DATETIME      NULL DEFAULT NULL,
    source_inspection_id INT UNSIGNED  NULL DEFAULT NULL,
    source_defect_key    VARCHAR(40)   NULL DEFAULT NULL,
    created_by_user_id   INT UNSIGNED  NULL DEFAULT NULL,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tasks_account_open (account_id, done, due_date),
    INDEX idx_tasks_assignee (assignee_user_id, done, due_date),
    INDEX idx_tasks_company (company_id),
    UNIQUE KEY uq_tasks_source_defect (account_id, source_inspection_id, source_defect_key),
    CONSTRAINT fk_tasks_account    FOREIGN KEY (account_id)           REFERENCES accounts(id)    ON DELETE CASCADE,
    CONSTRAINT fk_tasks_company    FOREIGN KEY (company_id)           REFERENCES companies(id)   ON DELETE CASCADE,
    CONSTRAINT fk_tasks_facility   FOREIGN KEY (facility_id)          REFERENCES facilities(id)  ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assignee   FOREIGN KEY (assignee_user_id)     REFERENCES users(id)       ON DELETE SET NULL,
    CONSTRAINT fk_tasks_creator    FOREIGN KEY (created_by_user_id)   REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
