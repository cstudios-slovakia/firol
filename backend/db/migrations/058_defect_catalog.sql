-- Phase 4 (POapp 26. 10. 2026) — číselník nedostatkov (spec 3.4).
--
-- One table for both halves of the catalogue:
--
--   account_id NULL      a shipped item, seeded by migration 059 from
--                        src/Support/data/defect_catalog.json. Read-only in
--                        the app, the same for every account.
--   account_id = X       a vlastný nedostatok a technician of that account
--                        added. Spec: „globálny pre účet" — it is offered at
--                        every firm of the account, not just where it was
--                        written, and an edit or a removal shows everywhere.
--
--   scope                po | bo | ob (požiarny / BOZP / oboje) — the JSON's
--                        `tyka_sa`. The picker of a PO prehliadka hides `bo`,
--                        a BOZP one hides `po`.
--   area                 spec `obl` — only sorts/labels the picker.
--   default_deadline     the catalogue's lehota text („do 30 dní"); the app
--                        turns it into a concrete date from the prehliadka date.
--   description          popis zisteného stavu
--   regulation           predpis — its own field, never part of a sentence
--   justification        odôvodnenie, one sentence
--   measure              navrhované opatrenie
--   created_by_user_id   who wrote an own item (NULL for shipped ones, and
--                        after that user is deleted)
--   archived_at          „odstránenie" is an archive: the item vanishes from
--                        every list, but a defect already written keeps its
--                        copied texts and its catalog_id (used only to count
--                        „Najčastejšie") — nothing is ever deleted.
--
-- Shipped rows take the JSON's own ids (1–27) so a `fields.catalog_id` in a
-- defect means the same item on every installation and in every backup; own
-- rows continue from 28 via AUTO_INCREMENT.
--
-- The FK to accounts cascades; a NULL account_id (shipped) never matches it.
-- created_by_user_id is SET NULL on user delete (same as tasks).

CREATE TABLE IF NOT EXISTS defect_catalog (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id         INT UNSIGNED  NULL DEFAULT NULL,
    title              VARCHAR(255)  NOT NULL,
    scope              ENUM('po', 'bo', 'ob') NOT NULL DEFAULT 'po',
    area               VARCHAR(255)  NOT NULL DEFAULT '',
    default_deadline   VARCHAR(40)   NOT NULL DEFAULT 'do 30 dní',
    description        TEXT          NOT NULL,
    regulation         VARCHAR(500)  NOT NULL DEFAULT '',
    justification      TEXT          NOT NULL,
    measure            TEXT          NOT NULL,
    created_by_user_id INT UNSIGNED  NULL DEFAULT NULL,
    archived_at        DATETIME      NULL DEFAULT NULL,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_defect_catalog_account (account_id, archived_at),
    CONSTRAINT fk_defect_catalog_account FOREIGN KEY (account_id)         REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_defect_catalog_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
