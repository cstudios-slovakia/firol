-- Customer's answer 3 of 18. 11. 2026: požiarna kniha entries get their own
-- series per firma — PK-<KÓD>-NNN, counted from 001, never reset by year, no
-- gap from „Upraviť". Every other document keeps PREFIX-RRRR-NNN.
--
--   companies.book_code      the firma's code (2–4 letters or digits, upper
--                            case, at least one letter), unique within the
--                            account so two firms never share a number;
--                            locked once the first entry is issued
--   companies.book_last_seq  the firma's counter; the row is locked FOR UPDATE
--                            inside the issue transaction
--   inspections.book_number  the number an entry took at its first issue; it
--                            survives „Upraviť", so the entry is issued again
--                            under the same number as a new version
--   inspections.book_number_version  the version last issued under it
--
-- Re-runnable (IF NOT EXISTS). Entries already issued keep the number they
-- were issued with (PK-RRRR-NNN): it is copied onto the entry, so a later
-- „Upraviť" + issue keeps it too.

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS book_code VARCHAR(4) NULL AFTER billing_mode,
    ADD COLUMN IF NOT EXISTS book_last_seq INT UNSIGNED NOT NULL DEFAULT 0 AFTER book_code;

CREATE UNIQUE INDEX IF NOT EXISTS uq_companies_book_code ON companies (account_id, book_code);

ALTER TABLE inspections
    ADD COLUMN IF NOT EXISTS book_number VARCHAR(40) NULL AFTER issued_before,
    ADD COLUMN IF NOT EXISTS book_number_version SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER book_number;

CREATE UNIQUE INDEX IF NOT EXISTS uq_inspections_book_number ON inspections (account_id, book_number);

UPDATE inspections i
JOIN   documents d
       ON  d.account_id = i.account_id
       AND d.parent_type = 'inspection'
       AND d.parent_id = i.id
SET    i.book_number = d.number,
       i.book_number_version = d.version
WHERE  i.type = 'poziarna_kniha'
  AND  i.book_number IS NULL;
