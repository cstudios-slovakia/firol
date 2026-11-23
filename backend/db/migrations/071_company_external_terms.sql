-- POapp 26. 10. 2026, phase 6 — termíny cudzích revízií (spec 6.6, 3.1).
--
-- Revisions the client has done by someone else (komín, EPS, elektrická
-- inštalácia, …). The app only remembers them so they print in the
-- „Termíny a kontroly" table of the record and can be offered into a book.
--
--   book          po | bozp — which book (and which record) the term belongs to.
--                 It is a field the technician answers, never derived from the
--                 name (spec 6.6: „pole pri termíne, nie odvodenie z názvu");
--                 the name list in the app only suggests an answer.
--   facility_id   NULL = the term applies to the whole firm (the spec keeps the
--                 list per firma, Q7); set = pinned to one prevádzka, and only
--                 that prevádzka's record prints it.
--   performed_by  who did the revision — optional.
--   done_on       date of the revision — optional, a client often has only the
--                 date it is valid until.
--   valid_until   NULL prints „nedoložené" (sample PDF).
--   archived_at   soft delete — a record issued earlier froze its rows already,
--                 and a restored backup must not resurrect a removed term.

CREATE TABLE IF NOT EXISTS company_external_terms (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED  NOT NULL,
    company_id    INT UNSIGNED  NOT NULL,
    facility_id   INT UNSIGNED  NULL DEFAULT NULL,
    book          ENUM('po', 'bozp') NOT NULL,
    name          VARCHAR(191)  NOT NULL,
    performed_by  VARCHAR(191)  NULL DEFAULT NULL,
    done_on       DATE          NULL DEFAULT NULL,
    valid_until   DATE          NULL DEFAULT NULL,
    note          VARCHAR(500)  NULL DEFAULT NULL,
    archived_at   DATETIME      NULL DEFAULT NULL,
    created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_external_terms_company (company_id, book, archived_at),
    CONSTRAINT fk_external_terms_account  FOREIGN KEY (account_id)         REFERENCES accounts(id)   ON DELETE CASCADE,
    CONSTRAINT fk_external_terms_company  FOREIGN KEY (company_id)         REFERENCES companies(id)  ON DELETE CASCADE,
    CONSTRAINT fk_external_terms_facility FOREIGN KEY (facility_id)        REFERENCES facilities(id) ON DELETE CASCADE,
    CONSTRAINT fk_external_terms_creator  FOREIGN KEY (created_by_user_id) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
