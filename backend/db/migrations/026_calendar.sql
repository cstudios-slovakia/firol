-- Calendar (change request 2.5).
--
-- Statutory deadlines ("zákonný termín") are NOT stored — they are computed on
-- the fly from each inspection's executed_on + periodicity. Only the two
-- user-editable layers need persistence:
--
--   calendar_plans   — an optional planned visit date attached to the specific
--                      inspection that currently defines a facility+type
--                      deadline. When that inspection is repeated (superseded),
--                      the new inspection has no plan, so the planned date
--                      naturally resets with the moved deadline.
--   calendar_events  — free-standing custom events (meeting, training, site
--                      visit) not tied to any inspection.

CREATE TABLE calendar_plans (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED NOT NULL,
    inspection_id INT UNSIGNED NOT NULL,
    planned_date  DATE         NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_calendar_plan_inspection (inspection_id),
    INDEX idx_calendar_plans_account (account_id),
    CONSTRAINT fk_calendar_plans_account    FOREIGN KEY (account_id)    REFERENCES accounts(id)     ON DELETE CASCADE,
    CONSTRAINT fk_calendar_plans_inspection FOREIGN KEY (inspection_id) REFERENCES inspections(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED NOT NULL,
    title       VARCHAR(191) NOT NULL,
    event_date  DATE         NOT NULL,
    note        TEXT         NULL,
    company_id  INT UNSIGNED NULL DEFAULT NULL,
    facility_id INT UNSIGNED NULL DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_calendar_events_account (account_id, event_date),
    CONSTRAINT fk_calendar_events_account  FOREIGN KEY (account_id)  REFERENCES accounts(id)   ON DELETE CASCADE,
    CONSTRAINT fk_calendar_events_company  FOREIGN KEY (company_id)  REFERENCES companies(id)  ON DELETE SET NULL,
    CONSTRAINT fk_calendar_events_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
