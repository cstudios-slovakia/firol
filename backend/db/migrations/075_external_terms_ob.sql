-- POapp 02. 11. 2026, Session 1/3 — support book = 'ob' (both PO and BOZP) on company_external_terms.

ALTER TABLE company_external_terms
    MODIFY COLUMN book ENUM('po', 'bozp', 'ob') NOT NULL DEFAULT 'po';

