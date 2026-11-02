-- POapp 26. 10. 2026, phase 7b — the firm's own „vykonané činnosti" (spec 6.4).
--
-- The list of what the technician walked through during a preventívna
-- prehliadka. The two default sets (PO and BOZP) live in code; this table holds
-- only the activities a technician added for ONE firm. They are offered again
-- at that firm and nowhere else (unlike a custom defect, which belongs to the
-- whole account).
--
--   kind         po | bozp — the two sets never mix.
--   archived_at  soft delete: „Odstrániť" takes the activity off the firm's
--                list; records already issued carry the text they were written
--                with and are not touched.

CREATE TABLE IF NOT EXISTS company_activities (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED  NOT NULL,
    company_id    INT UNSIGNED  NOT NULL,
    kind          ENUM('po', 'bozp') NOT NULL,
    label         VARCHAR(191)  NOT NULL,
    archived_at   DATETIME      NULL DEFAULT NULL,
    created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company_activities_company (company_id, kind, archived_at),
    CONSTRAINT fk_company_activities_account FOREIGN KEY (account_id)         REFERENCES accounts(id)  ON DELETE CASCADE,
    CONSTRAINT fk_company_activities_company FOREIGN KEY (company_id)         REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_company_activities_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
