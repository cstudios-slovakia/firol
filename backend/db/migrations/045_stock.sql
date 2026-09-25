-- Block 4 / chapter 21 — sklad (materiál a značenie) and the výdajka.
--
-- A count of what the firm has and who has it, nothing more. The spec is
-- explicit that this is NOT a warehouse system: no prices, no batches, no
-- low-stock alerts — so none of those columns exist here.
--
--   stock_items       polozka_skladu. `warehouse_qty` is the balance of the
--                     holder „Sklad"; each technician's balance is a row in
--                     stock_balances. Total = warehouse_qty + SUM(balances),
--                     and every write changes both sides of a move inside one
--                     transaction holding the item row lock, so the sum over
--                     holders can never drift from the total.
--
--   stock_balances    One row per (item, technician). A technician removed
--                     from the team has their rows moved back to Sklad
--                     (recorded as a `presun` movement), so their column
--                     disappears and the journal explains where it went.
--
--   stock_movements   pohyb_skladu — the append-only journal. There is no
--                     endpoint that edits or deletes a row. Names of the item
--                     and of both holders are copied onto the row, so the
--                     history still reads correctly after a technician leaves.
--                     `to_invoice` / `invoiced` / `invoiced_at` are billing
--                     state („Pridať na faktúru" and its check-off), not the
--                     substance of the movement: they are the only columns
--                     that ever change after the insert.
--
--   stock_issues      Výdajka materiálu (VYD-RRRR-NNN). A printable receipt
--                     for material handed to a client, signed vydal / prevzal.
--                     `issued_on` is the date of the movements it lists, never
--                     the date it was generated. The issuer and the
--                     Zhotoviteľ block are frozen at issue so a later
--                     signature re-renders what was printed.
--
-- work_confirmations.stock_issue_ids is chapter 10's `vydajky`: the výdajky
-- of that day, frozen when the potvrdenie is issued so a re-render for a
-- signature prints the same material. NULL on potvrdenia issued before the
-- sklad existed (they had no material section to print).

CREATE TABLE stock_items (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id         INT UNSIGNED  NOT NULL,
    name               VARCHAR(191)  NOT NULL,
    unit               ENUM('ks','bal','m') NOT NULL DEFAULT 'ks',
    warehouse_qty      INT UNSIGNED  NOT NULL DEFAULT 0,
    created_by_user_id INT UNSIGNED  NULL DEFAULT NULL,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_items_name (account_id, name),
    CONSTRAINT fk_stock_items_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_items_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_balances (
    item_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    qty        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (item_id, user_id),
    INDEX idx_stock_balances_holder (account_id, user_id),
    CONSTRAINT fk_stock_balances_item    FOREIGN KEY (item_id)    REFERENCES stock_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_balances_account FOREIGN KEY (account_id) REFERENCES accounts(id)    ON DELETE CASCADE,
    -- RESTRICT on purpose: deleting a user outright must not make stock
    -- vanish from the sum. Removing them from the team returns it first.
    CONSTRAINT fk_stock_balances_user    FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_issues (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id         INT UNSIGNED  NOT NULL,
    company_id         INT UNSIGNED  NOT NULL,
    facility_id        INT UNSIGNED  NULL DEFAULT NULL,
    inspection_id      INT UNSIGNED  NULL DEFAULT NULL,
    issued_on          DATE          NOT NULL,
    issuer_user_id     INT UNSIGNED  NULL DEFAULT NULL,
    issuer_name        VARCHAR(191)  NOT NULL,
    issuer_cert        VARCHAR(64)   NULL DEFAULT NULL,
    contractor         JSON          NULL DEFAULT NULL,
    created_by_user_id INT UNSIGNED  NULL DEFAULT NULL,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_issues_company (company_id, issued_on),
    INDEX idx_stock_issues_account (account_id, issued_on),
    CONSTRAINT fk_stock_issues_account    FOREIGN KEY (account_id)    REFERENCES accounts(id)    ON DELETE CASCADE,
    CONSTRAINT fk_stock_issues_company    FOREIGN KEY (company_id)    REFERENCES companies(id)   ON DELETE CASCADE,
    CONSTRAINT fk_stock_issues_facility   FOREIGN KEY (facility_id)   REFERENCES facilities(id)  ON DELETE SET NULL,
    CONSTRAINT fk_stock_issues_inspection FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_issues_issuer     FOREIGN KEY (issuer_user_id) REFERENCES users(id)      ON DELETE SET NULL,
    CONSTRAINT fk_stock_issues_creator    FOREIGN KEY (created_by_user_id) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id         INT UNSIGNED  NOT NULL,
    item_id            INT UNSIGNED  NOT NULL,
    item_name          VARCHAR(191)  NOT NULL,
    unit               ENUM('ks','bal','m') NOT NULL,
    action             ENUM('nakup','presun','pouzite') NOT NULL,
    -- z / komu: a holder is either „Sklad" or a technician. NULL holder =
    -- no side (a nákup has no source, a použitie no destination).
    from_holder        ENUM('sklad','technik') NULL DEFAULT NULL,
    from_user_id       INT UNSIGNED  NULL DEFAULT NULL,
    from_name          VARCHAR(191)  NULL DEFAULT NULL,
    to_holder          ENUM('sklad','technik') NULL DEFAULT NULL,
    to_user_id         INT UNSIGNED  NULL DEFAULT NULL,
    to_name            VARCHAR(191)  NULL DEFAULT NULL,
    qty                INT UNSIGNED  NOT NULL,
    company_id         INT UNSIGNED  NULL DEFAULT NULL,
    inspection_id      INT UNSIGNED  NULL DEFAULT NULL,
    note               VARCHAR(500)  NULL DEFAULT NULL,
    issue_id           INT UNSIGNED  NULL DEFAULT NULL,
    to_invoice         TINYINT(1)    NOT NULL DEFAULT 0,
    invoiced           TINYINT(1)    NOT NULL DEFAULT 0,
    invoiced_at        DATE          NULL DEFAULT NULL,
    created_by_user_id INT UNSIGNED  NULL DEFAULT NULL,
    created_by_name    VARCHAR(191)  NOT NULL,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_movements_account (account_id, created_at),
    INDEX idx_stock_movements_item (item_id, created_at),
    INDEX idx_stock_movements_company (company_id, created_at),
    INDEX idx_stock_movements_invoice (account_id, to_invoice, invoiced),
    CONSTRAINT fk_stock_movements_account    FOREIGN KEY (account_id)    REFERENCES accounts(id)     ON DELETE CASCADE,
    CONSTRAINT fk_stock_movements_item       FOREIGN KEY (item_id)       REFERENCES stock_items(id)  ON DELETE CASCADE,
    CONSTRAINT fk_stock_movements_from_user  FOREIGN KEY (from_user_id)  REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_stock_movements_to_user    FOREIGN KEY (to_user_id)    REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_stock_movements_company    FOREIGN KEY (company_id)    REFERENCES companies(id)    ON DELETE SET NULL,
    CONSTRAINT fk_stock_movements_inspection FOREIGN KEY (inspection_id) REFERENCES inspections(id)  ON DELETE SET NULL,
    CONSTRAINT fk_stock_movements_issue      FOREIGN KEY (issue_id)      REFERENCES stock_issues(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_movements_creator    FOREIGN KEY (created_by_user_id) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE work_confirmations
    ADD COLUMN stock_issue_ids JSON NULL DEFAULT NULL AFTER inspection_ids;

ALTER TABLE documents
    MODIFY parent_type ENUM('inspection','training','work_confirmation','stock_issue') NOT NULL;
