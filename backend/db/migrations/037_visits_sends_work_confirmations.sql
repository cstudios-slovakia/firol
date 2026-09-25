-- Block 1 / chapters 9, 10 and 12 — a visit, its bulk e-mail and the work
-- confirmation that comes out of it.
--
--   visits               One trip to a client. The technician picks company,
--                        prevádzka and date ONCE, then walks through the
--                        úkony one after another. Every inspection created in
--                        the visit points back at it.
--
--   document_sends       One e-mail carrying several protocols. Recorded so
--                        the company history can answer "what did we send
--                        them, when, and to whom" — the spec asks for that
--                        explicitly, and a failed send has to say why.
--
--   work_confirmations   Potvrdenie o vykonaní práce (chapter 10). Not a
--                        professional document: it proves to the technician's
--                        EMPLOYER where they were and how much they did, so
--                        it names protocols but never their findings.
--
-- inspections.carried_over_from_id is chapter 12's zdroj_ukon_id. It is kept
-- apart from source_inspection_id (which links a follow-up protocol to the
-- inspection that spawned it) so the two graphs don't blur: one says "this
-- protocol continues that one", the other says "these items were typed once,
-- a year ago".

ALTER TABLE inspections
    ADD COLUMN visit_id INT UNSIGNED NULL DEFAULT NULL AFTER facility_id,
    ADD COLUMN carried_over_from_id INT UNSIGNED NULL DEFAULT NULL AFTER source_inspection_id,
    ADD INDEX idx_inspections_visit (visit_id),
    ADD INDEX idx_inspections_carried_over (carried_over_from_id);

CREATE TABLE visits (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED  NOT NULL,
    company_id    INT UNSIGNED  NOT NULL,
    facility_id   INT UNSIGNED  NOT NULL,
    visit_date    DATE          NOT NULL,
    technician_user_id INT UNSIGNED NOT NULL,
    planned_types JSON          NOT NULL,
    status        ENUM('prebieha','dokoncena') NOT NULL DEFAULT 'prebieha',
    archived_at   DATETIME      NULL DEFAULT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_visits_account (account_id, archived_at, visit_date),
    INDEX idx_visits_company (company_id, visit_date),
    CONSTRAINT fk_visits_account    FOREIGN KEY (account_id)  REFERENCES accounts(id)   ON DELETE CASCADE,
    CONSTRAINT fk_visits_company    FOREIGN KEY (company_id)  REFERENCES companies(id)  ON DELETE CASCADE,
    CONSTRAINT fk_visits_facility   FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE,
    CONSTRAINT fk_visits_technician FOREIGN KEY (technician_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE inspections
    ADD CONSTRAINT fk_inspections_visit FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_inspections_carried_over FOREIGN KEY (carried_over_from_id) REFERENCES inspections(id) ON DELETE SET NULL;

CREATE TABLE document_sends (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id   INT UNSIGNED  NOT NULL,
    company_id   INT UNSIGNED  NOT NULL,
    visit_id     INT UNSIGNED  NULL DEFAULT NULL,
    document_ids JSON          NOT NULL,
    recipients   JSON          NOT NULL,
    subject      VARCHAR(255)  NOT NULL,
    note         TEXT          NULL DEFAULT NULL,
    status       ENUM('caka','odoslane','chyba') NOT NULL DEFAULT 'caka',
    sent_at      DATETIME      NULL DEFAULT NULL,
    error_text   TEXT          NULL DEFAULT NULL,
    sent_by_user_id INT UNSIGNED NOT NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_sends_company (company_id, created_at),
    INDEX idx_document_sends_account (account_id, created_at),
    CONSTRAINT fk_document_sends_account FOREIGN KEY (account_id) REFERENCES accounts(id)  ON DELETE CASCADE,
    CONSTRAINT fk_document_sends_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_sends_visit   FOREIGN KEY (visit_id)   REFERENCES visits(id)    ON DELETE SET NULL,
    CONSTRAINT fk_document_sends_user    FOREIGN KEY (sent_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_confirmations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id     INT UNSIGNED  NOT NULL,
    company_id     INT UNSIGNED  NOT NULL,
    facility_id    INT UNSIGNED  NULL DEFAULT NULL,
    visit_id       INT UNSIGNED  NULL DEFAULT NULL,
    confirmed_on   DATE          NOT NULL,
    time_from      TIME          NULL DEFAULT NULL,
    time_to        TIME          NULL DEFAULT NULL,
    technician_user_id INT UNSIGNED NOT NULL,
    inspection_ids JSON          NOT NULL,
    archived_at    DATETIME      NULL DEFAULT NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_work_confirmations_account (account_id, archived_at, confirmed_on),
    INDEX idx_work_confirmations_company (company_id, confirmed_on),
    CONSTRAINT fk_work_conf_account    FOREIGN KEY (account_id)  REFERENCES accounts(id)   ON DELETE CASCADE,
    CONSTRAINT fk_work_conf_company    FOREIGN KEY (company_id)  REFERENCES companies(id)  ON DELETE CASCADE,
    CONSTRAINT fk_work_conf_facility   FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_conf_visit      FOREIGN KEY (visit_id)    REFERENCES visits(id)     ON DELETE SET NULL,
    CONSTRAINT fk_work_conf_technician FOREIGN KEY (technician_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documents
    MODIFY parent_type ENUM('inspection','training','work_confirmation') NOT NULL;
