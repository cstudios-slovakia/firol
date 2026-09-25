-- Block 1 / chapter 13 — handing a protocol over for signature.
--
-- Two things the spec asks for:
--
--   company_persons     A company may name SEVERAL people entitled to sign.
--                       A large client has a konateľ at the registered seat
--                       and a vedúci zamestnanec at each prevádzka — and for
--                       the požiarna kniha it is the vedúci who signs, not
--                       the konateľ. facility_id NULL = valid company-wide.
--
--   document_handovers  The captured signature itself, one row per document.
--                       Signing happens AFTER the PDF exists, so it belongs
--                       to the document, not to the inspection: re-signing a
--                       protocol re-renders that same document number as a
--                       new version, it never issues a new number.
--
-- document_versions keeps the superseded PDF files. The spec is explicit that
-- adding a signature produces a new version of the same number and that the
-- original stays — a customer may already hold a copy of version 1, so it
-- must remain retrievable.

CREATE TABLE company_persons (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED  NOT NULL,
    company_id  INT UNSIGNED  NOT NULL,
    facility_id INT UNSIGNED  NULL DEFAULT NULL,
    fullname    VARCHAR(191)  NOT NULL,
    role_title  VARCHAR(191)  NOT NULL,
    email       VARCHAR(191)  NULL DEFAULT NULL,
    is_default  TINYINT(1)    NOT NULL DEFAULT 0,
    archived_at DATETIME      NULL DEFAULT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company_persons_company (company_id, archived_at),
    INDEX idx_company_persons_account (account_id, archived_at),
    CONSTRAINT fk_company_persons_account  FOREIGN KEY (account_id)  REFERENCES accounts(id)   ON DELETE CASCADE,
    CONSTRAINT fk_company_persons_company  FOREIGN KEY (company_id)  REFERENCES companies(id)  ON DELETE CASCADE,
    CONSTRAINT fk_company_persons_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_handovers (
    document_id    INT UNSIGNED  NOT NULL PRIMARY KEY,
    account_id     INT UNSIGNED  NOT NULL,
    person_id      INT UNSIGNED  NULL DEFAULT NULL,
    fullname       VARCHAR(191)  NOT NULL,
    role_title     VARCHAR(191)  NOT NULL,
    signature_path VARCHAR(255)  NOT NULL,
    place          VARCHAR(191)  NOT NULL,
    signed_on      DATE          NOT NULL,
    signed_at_time TIME          NOT NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_handovers_account (account_id),
    CONSTRAINT fk_handovers_document FOREIGN KEY (document_id) REFERENCES documents(id)       ON DELETE CASCADE,
    CONSTRAINT fk_handovers_account  FOREIGN KEY (account_id)  REFERENCES accounts(id)        ON DELETE CASCADE,
    CONSTRAINT fk_handovers_person   FOREIGN KEY (person_id)   REFERENCES company_persons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- include_photos records the choice the technician made when the protocol was
-- first issued. Re-rendering it with a signature has to reproduce the same
-- document, and "does it carry the photo appendix" is the one thing about it
-- that cannot be derived from the data afterwards.
ALTER TABLE documents
    ADD COLUMN version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER number,
    ADD COLUMN include_photos TINYINT(1) NOT NULL DEFAULT 1 AFTER version;

CREATE TABLE document_versions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id  INT UNSIGNED       NOT NULL,
    version      SMALLINT UNSIGNED  NOT NULL,
    file_path    VARCHAR(255)       NOT NULL,
    created_at   DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_version (document_id, version),
    CONSTRAINT fk_document_versions_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
