-- Phase 3 — inspection records, polymorphic per-item fields, generated
-- PDF documents and the per-account+type+year sequence used to number
-- them. Inspector profile holds the signature + certification data
-- printed on every protocol.
--
-- Type slugs (locked, see Firol base document & roadmap) — used both as
-- inspections.type and as documents.type:
--   rphp              — Hasiace prístroje                   (12/24 mo)
--   hydranty          — Požiarne hydranty                   (12 mo)
--   oprava_ts_rphp    — Oprava + plnenie + TS RPHP          (60 mo)
--   poziarna_kniha    — Požiarna kniha                      (3/6 mo)
--   pu_akcieschopnost — Požiarne uzávery, akcieschopnosť    (3 mo)
--   pu_udrzba         — Požiarne uzávery, prevádzková údržba (12 mo)
--   nudzove_osvetlenie— Núdzové osvetlenie                  (12 mo)
--   ts_hadic          — Tlaková skúška hadíc                (60 mo)
--
-- Status lifecycle:
--   draft     — created via Step 1, items being added in Step 2
--   finalized — PDF generated; the inspection record + items become
--               immutable from a business standpoint (DB still allows
--               edits — controller layer enforces immutability).
--
-- inspection_items.fields holds the per-type payload as JSON. The
-- controller writes/validates the shape per inspection type — DB does
-- not constrain it. Position drives the order shown in Step 3 and on
-- the PDF.

CREATE TABLE inspections (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id          INT UNSIGNED            NOT NULL,
    company_id          INT UNSIGNED            NOT NULL,
    facility_id         INT UNSIGNED            NOT NULL,
    type                VARCHAR(32)             NOT NULL,
    periodicity_months  TINYINT UNSIGNED        NOT NULL,
    executed_on         DATE                    NULL,
    inspector_user_id   INT UNSIGNED            NOT NULL,
    status              ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    notes               TEXT                    NULL,
    archived_at         DATETIME                NULL,
    created_at          DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inspections_account     (account_id, archived_at),
    INDEX idx_inspections_facility    (facility_id, archived_at, executed_on),
    INDEX idx_inspections_company     (company_id, archived_at, executed_on),
    INDEX idx_inspections_type_date   (account_id, type, executed_on),
    CONSTRAINT fk_inspections_account   FOREIGN KEY (account_id)        REFERENCES accounts(id)  ON DELETE CASCADE,
    CONSTRAINT fk_inspections_company   FOREIGN KEY (company_id)        REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_inspections_facility  FOREIGN KEY (facility_id)       REFERENCES facilities(id) ON DELETE CASCADE,
    CONSTRAINT fk_inspections_inspector FOREIGN KEY (inspector_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id   INT UNSIGNED            NOT NULL,
    position        SMALLINT UNSIGNED       NOT NULL,
    fields          JSON                    NOT NULL,
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_items_inspection (inspection_id, position),
    CONSTRAINT fk_items_inspection FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polymorphic parent (inspection or training). parent_type is constrained
-- to the two known values; the controller checks the parent exists and
-- belongs to the active account before writing.
CREATE TABLE documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED            NOT NULL,
    parent_type     ENUM('inspection','training') NOT NULL,
    parent_id       INT UNSIGNED            NOT NULL,
    type            VARCHAR(32)             NOT NULL,
    number          VARCHAR(40)             NOT NULL,
    file_path       VARCHAR(255)            NOT NULL,
    generated_at    DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    signed          TINYINT(1)              NOT NULL DEFAULT 1,
    signed_at       DATETIME                NULL,
    INDEX idx_documents_account (account_id, generated_at),
    INDEX idx_documents_parent  (parent_type, parent_id),
    UNIQUE KEY uq_documents_number (account_id, number),
    CONSTRAINT fk_documents_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-account+type+year counter for document numbering. SELECT ... FOR
-- UPDATE inside a transaction guarantees no two protocols share a seq.
CREATE TABLE document_sequences (
    account_id      INT UNSIGNED            NOT NULL,
    type            VARCHAR(32)             NOT NULL,
    year            SMALLINT UNSIGNED       NOT NULL,
    last_seq        INT UNSIGNED            NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id, type, year),
    CONSTRAINT fk_sequences_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One inspector profile per (user, account). A user attached to multiple
-- accounts can have a different signature / certification per account
-- (different employer, different oprávnenie).
CREATE TABLE inspector_profiles (
    user_id              INT UNSIGNED       NOT NULL,
    account_id           INT UNSIGNED       NOT NULL,
    signature_path       VARCHAR(255)       NULL,
    certification_number VARCHAR(64)        NULL,
    valid_from           DATE               NULL,
    valid_to             DATE               NULL,
    is_active            TINYINT(1)         NOT NULL DEFAULT 1,
    created_at           DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, account_id),
    CONSTRAINT fk_inspector_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_inspector_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
