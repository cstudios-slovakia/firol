-- Companies & facilities. Both carry account_id for multi-tenancy and use
-- soft-delete (archived_at IS NULL = active). Per locked spec decisions:
--   companies  : name, ico, address (free text), contact (free text)
--   facilities : name, address (free text), contact_person (fullname only),
--                notes
-- Per-facility periodicity defaults are deferred to Phase 5 (Settings →
-- Default periodicities) — no UI for them in Phase 2.

CREATE TABLE companies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED            NOT NULL,
    name            VARCHAR(191)            NOT NULL,
    ico             VARCHAR(32)             NULL,
    address         TEXT                    NULL,
    contact         TEXT                    NULL,
    archived_at     DATETIME                NULL,
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_companies_account (account_id, archived_at),
    INDEX idx_companies_name (account_id, name),
    CONSTRAINT fk_companies_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facilities (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED            NOT NULL,
    company_id      INT UNSIGNED            NOT NULL,
    name            VARCHAR(191)            NOT NULL,
    address         TEXT                    NULL,
    contact_person  VARCHAR(191)            NULL,
    notes           TEXT                    NULL,
    archived_at     DATETIME                NULL,
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_facilities_account (account_id, archived_at),
    INDEX idx_facilities_company (company_id, archived_at),
    CONSTRAINT fk_facilities_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_facilities_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
