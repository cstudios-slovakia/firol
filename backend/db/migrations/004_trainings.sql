-- Phase 4 — trainings (Školenia). Separate entity tree from inspections
-- because each training has its own attendee list (trainees) with
-- individual signatures captured on the touchscreen. Trainers are a
-- per-account pool of people qualified to deliver training; the same
-- trainer is reused across many trainings.
--
-- Type slugs (locked, see Firol base document):
--   vstupne          — Vstupné školenie vedúcich a ostatných zamestnancov
--   opakovane        — Opakované školenie vedúcich a ostatných zamestnancov
--   opp_mimo         — Školenie osôb zabezpečujúcich OPP v mimopracovnom čase
--   zdrzujuca_sa     — Školenie osôb zdržujúcich sa na pracovisku
--   hliadka_oph      — Odborná príprava protipožiarnych hliadok
--   hliadka_opah     — Odborná príprava protipožiarnej asistenčnej hliadky
--
-- Document numbering: prefix SKO is shared across all training types
-- (per spec — the type is recorded in the body, not the number).

CREATE TABLE trainers (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id           INT UNSIGNED       NOT NULL,
    fullname             VARCHAR(191)       NOT NULL,
    certification_number VARCHAR(64)        NULL,
    signature_path       VARCHAR(255)       NULL,
    archived_at          DATETIME           NULL,
    created_at           DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_trainers_account (account_id, archived_at),
    CONSTRAINT fk_trainers_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trainings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED               NOT NULL,
    company_id    INT UNSIGNED               NOT NULL,
    -- Optional — some trainings cover the whole company without naming a
    -- specific facility (e.g. the OPP training pre celú firmu).
    facility_id   INT UNSIGNED               NULL,
    type          VARCHAR(32)                NOT NULL,
    date          DATE                       NULL,
    trainer_id    INT UNSIGNED               NULL,
    topics        TEXT                       NULL,
    duration_min  SMALLINT UNSIGNED          NULL,
    status        ENUM('draft','finalized')  NOT NULL DEFAULT 'draft',
    archived_at   DATETIME                   NULL,
    created_at    DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_trainings_account  (account_id, archived_at),
    INDEX idx_trainings_company  (company_id, archived_at, date),
    INDEX idx_trainings_facility (facility_id, archived_at, date),
    CONSTRAINT fk_trainings_account  FOREIGN KEY (account_id)  REFERENCES accounts(id)   ON DELETE CASCADE,
    CONSTRAINT fk_trainings_company  FOREIGN KEY (company_id)  REFERENCES companies(id)  ON DELETE CASCADE,
    CONSTRAINT fk_trainings_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL,
    CONSTRAINT fk_trainings_trainer  FOREIGN KEY (trainer_id)  REFERENCES trainers(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trainees are scoped to a single training; deletion cascades when the
-- training is removed. Signature is captured client-side as a PNG and
-- stored under storage/trainings/{training_id}/{trainee_id}.png.
CREATE TABLE trainees (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    training_id    INT UNSIGNED       NOT NULL,
    fullname       VARCHAR(191)       NOT NULL,
    position       VARCHAR(191)       NULL,
    signature_path VARCHAR(255)       NULL,
    signed_at      DATETIME           NULL,
    created_at     DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_trainees_training (training_id),
    CONSTRAINT fk_trainees_training FOREIGN KEY (training_id) REFERENCES trainings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
