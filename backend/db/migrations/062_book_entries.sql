-- POapp 26. 10. 2026, phase 5 — the books as ledgers (spec 3.6, 9).
--
-- A book is a place at a firm; an entry is one numbered row in it, not a
-- document. Printing entries renders a PDF on the fly (decision D6): there is
-- no `documents` row and no number series, the book has its own numbering.
--
--   book                po = požiarna kniha (§ 29 vyhl. 121/2002),
--                       bozp = kniha BOZP (zák. 124/2006). Two separate books:
--                       separate data, separate numbering, same mechanics.
--   facility_id         set only when the entry was written in a per-prevádzka
--                       book (companies.book_scope = prevadzka); NULL for the
--                       firm-wide book.
--   scope_key           COALESCE(facility_id, 0). NULLs are distinct in a
--                       unique key, so the ledger is identified by this
--                       generated column instead of by facility_id.
--   seq                 poradové číslo within (company, scope_key, book);
--                       allocated under a lock on the company row.
--   source              ukon    offered from a finished úkon or training
--                       cislo   by protocol number (an úkon that is not in the app)
--                       vlastny free text
--                       termin  „Termíny ďalších kontrol" offer (phase 6)
--                       revizia a cudzia revízia term (phase 6)
--   protocol_numbers    the protocol numbers the entry covers — an úkon already
--                       in the book is not offered a second time, also when the
--                       entry merged several prevádzky.
--   inspection_ids,     the úkony / trainings behind the entry (JSON lists of
--   training_ids        ids), for backup remapping and later phases.
--   no_defects_statement  spec 9.7: „V čase kontroly … neboli zistené žiadne
--                       nedostatky"; printed as ZISTENIA.
--   signed_technician_at  set when the entry is written (the technician made it).
--   signed_manager_at   set when the printed entry is marked as signed by the
--                       vedúci zamestnanec; signer_name / signer_role are who.
--
-- An entry is never edited or deleted; later facts go to book_entry_additions
-- (migration 063). Both FKs cascade: an entry means nothing without its firm.

CREATE TABLE IF NOT EXISTS book_entries (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id            INT UNSIGNED  NOT NULL,
    company_id            INT UNSIGNED  NOT NULL,
    facility_id           INT UNSIGNED  NULL DEFAULT NULL,
    scope_key             INT UNSIGNED  AS (COALESCE(facility_id, 0)) STORED,
    book                  ENUM('po', 'bozp') NOT NULL,
    seq                   INT UNSIGNED  NOT NULL,
    entry_date            DATE          NOT NULL,
    `text`                TEXT          NOT NULL,
    source                ENUM('ukon', 'cislo', 'vlastny', 'termin', 'revizia') NOT NULL,
    protocol_numbers      JSON          NULL,
    inspection_ids        JSON          NULL,
    training_ids          JSON          NULL,
    no_defects_statement  TINYINT(1)    NOT NULL DEFAULT 0,
    signed_technician_at  DATETIME      NULL DEFAULT NULL,
    signed_manager_at     DATETIME      NULL DEFAULT NULL,
    signer_name           VARCHAR(191)  NULL DEFAULT NULL,
    signer_role           VARCHAR(191)  NULL DEFAULT NULL,
    created_by_user_id    INT UNSIGNED  NULL DEFAULT NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_book_entries_seq (account_id, company_id, scope_key, book, seq),
    INDEX idx_book_entries_company (company_id, book, entry_date),
    CONSTRAINT fk_book_entries_account  FOREIGN KEY (account_id)         REFERENCES accounts(id)   ON DELETE CASCADE,
    CONSTRAINT fk_book_entries_company  FOREIGN KEY (company_id)         REFERENCES companies(id)  ON DELETE CASCADE,
    CONSTRAINT fk_book_entries_facility FOREIGN KEY (facility_id)        REFERENCES facilities(id) ON DELETE CASCADE,
    CONSTRAINT fk_book_entries_creator  FOREIGN KEY (created_by_user_id) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
