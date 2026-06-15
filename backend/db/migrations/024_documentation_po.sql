-- Phase 7 — Dokumentácia PO. A new third record type next to inspections
-- and trainings: the full fire-protection documentation a company must
-- keep by law. The technician picks a company + facility, fills a short
-- form, and the app fills a Word template (one document per section) and
-- converts it to a single signed PDF.
--
-- Unlike inspections (per-item rows) the whole form payload is stored as
-- one JSON blob on the record (`data`) — the same "open, edit, generate
-- again" principle, but there are no sub-items to manage. The shape of
-- `data` is owned and validated by DocumentationController; the DB does
-- not constrain it.
--
-- Output is filed in the shared `documents` table under the new
-- 'documentation' parent_type, numbered with the DOK prefix
-- (NumberAllocator). Because the documentation ships in two formats, the
-- PDF lives in documents.file_path (canonical) and the editable .docx in
-- the new documents.docx_path column.
--
-- Status lifecycle mirrors inspections/trainings:
--   draft     — created in step 1, form being filled
--   finalized — PDF/DOCX generated; record stays editable for "repeat"

CREATE TABLE documentations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id     INT UNSIGNED               NOT NULL,
    company_id     INT UNSIGNED               NOT NULL,
    -- Optional, but normally set: the prevádzka the documentation covers.
    -- Falls back to the company itself when absent.
    facility_id    INT UNSIGNED               NULL,
    -- Who authored the documentation (spec: "eviduje sa autor").
    author_user_id INT UNSIGNED               NOT NULL,
    -- Free label shown in lists; defaults to the facility/company name.
    title          VARCHAR(191)               NULL,
    -- Dátum vyhotovenia — entered manually, never auto-set to today.
    issued_on      DATE                       NULL,
    -- Whole form payload (konateľ, kontakty, objekty, miesta, prepínače,
    -- vlastné položky zoznamu, atď.) as JSON. Validated in the controller.
    data           JSON                       NOT NULL,
    status         ENUM('draft','finalized')  NOT NULL DEFAULT 'draft',
    archived_at    DATETIME                   NULL,
    created_at     DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_documentations_account  (account_id, archived_at),
    INDEX idx_documentations_company  (company_id, archived_at, issued_on),
    INDEX idx_documentations_facility (facility_id, archived_at, issued_on),
    CONSTRAINT fk_documentations_account  FOREIGN KEY (account_id)     REFERENCES accounts(id)   ON DELETE CASCADE,
    CONSTRAINT fk_documentations_company  FOREIGN KEY (company_id)     REFERENCES companies(id)  ON DELETE CASCADE,
    CONSTRAINT fk_documentations_facility FOREIGN KEY (facility_id)    REFERENCES facilities(id) ON DELETE SET NULL,
    CONSTRAINT fk_documentations_author   FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend the polymorphic documents table to file documentation output and
-- to carry the editable .docx alongside the canonical PDF.
ALTER TABLE documents
    MODIFY parent_type ENUM('inspection','training','documentation') NOT NULL;

ALTER TABLE documents
    ADD COLUMN docx_path VARCHAR(255) NULL AFTER file_path;
