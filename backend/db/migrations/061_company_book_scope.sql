-- POapp 26. 10. 2026, phase 5 — scope of the books (spec 9.2).
--
-- companies.book_scope
--     firma (default, every existing row)  one požiarna kniha and one kniha
--                                          BOZP for the whole firm, one numbering.
--     prevadzka                            a book of its own for every prevádzka,
--                                          each with its own numbering.
--
-- It is a setting, not a screen: the technician can change it at any time, and
-- nothing is rewritten when it does. An entry keeps the scope it was written in
-- (book_entries.facility_id NULL = written in the firm-wide book).
--
-- ADD COLUMN IF NOT EXISTS makes a re-run harmless.

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS book_scope ENUM('firma', 'prevadzka') NOT NULL DEFAULT 'firma' AFTER client_type;
