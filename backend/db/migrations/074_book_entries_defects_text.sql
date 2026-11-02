-- POapp 02. 11. 2026, Session 1/2 — optional defects_text on book_entries and support for 'hromadny' source.

ALTER TABLE book_entries
    MODIFY COLUMN source ENUM('ukon', 'cislo', 'vlastny', 'termin', 'revizia', 'hromadny') NOT NULL,
    ADD COLUMN defects_text TEXT NULL DEFAULT NULL AFTER no_defects_statement;

