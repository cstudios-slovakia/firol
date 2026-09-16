-- Block 3 / chapters 15, 16 and 17 — audits (BOZP and OPP).
--
-- An audit is an úkon like any other: it has a company, a prevádzka, a date
-- the technician typed, a periodicity they chose, one protocol with its own
-- number and one signature. So it is stored as an `inspections` row of type
-- `audit_bozp` / `audit_opp`, and each of its 75–109 evaluated items is an
-- `inspection_items` row. Nothing about photos, numbering, handover,
-- carry-over, the calendar, a visit, the bulk e-mail, export or purge then
-- needs to learn what an audit is — it already knows what an úkon is.
--
-- What IS new is the checklist behind it. Chapter 17 makes the delivered
-- checklists a DEFAULT, not a fixed list: the technician may reword an item,
-- delete one, add their own, add a whole section, reorder, and keep several
-- checklists side by side („Audit BOZP — výroba", „… — kancelárie"). That
-- needs first-class template tables, which is what the three below are.
--
--   audit_templates          One checklist. `is_custom = 0` marks one of the
--                            two delivered with the app — those can be
--                            restored to their original state at any time,
--                            which is why `source_key` remembers which file
--                            they came from.
--   audit_template_sections  A — Dokumentácia, B — Posudzovanie rizík, …
--   audit_template_items     The individual questions, with their legal basis
--                            and the scope (V / R / VR) that decides whether
--                            they appear in an entry audit, a yearly review
--                            or both.
--
-- An audit COPIES the template it was created from into its own items. A
-- protocol issued last March must keep reading the way it read in March even
-- after the technician rewords a question, so the template is a starting
-- point, never a join target.
--
-- `linked_type` is chapter 16: the type of úkon that covers the same ground
-- as this question. It names types from block 2 that do not exist in the app
-- yet (oopp, rebriky, regale, …) — deliberately. The resolver simply finds no
-- such úkon and offers nothing, and the day block 2 ships, the link starts
-- working with no template change.

ALTER TABLE inspector_profiles
    ADD COLUMN cert_bt       VARCHAR(64) NULL AFTER cert_general,
    ADD COLUMN valid_from_bt DATE        NULL AFTER valid_to_general,
    ADD COLUMN valid_to_bt   DATE        NULL AFTER valid_from_bt;

ALTER TABLE inspections
    -- Which checklist the snapshot came from. ON DELETE SET NULL: deleting a
    -- template must never touch the audits already issued from it.
    ADD COLUMN audit_template_id INT UNSIGNED NULL DEFAULT NULL AFTER type,
    ADD COLUMN audit_scope ENUM('vstupny','rocny') NULL DEFAULT NULL AFTER audit_template_id;

CREATE TABLE audit_templates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED NOT NULL,
    kind        ENUM('bozp','opp') NOT NULL,
    name        VARCHAR(120) NOT NULL,
    -- 0 = delivered with the app and restorable to its original state.
    is_custom   TINYINT(1)   NOT NULL DEFAULT 1,
    -- Which delivered checklist this is a materialisation of, for restore.
    source_key  VARCHAR(32)  NULL DEFAULT NULL,
    archived_at DATETIME     NULL DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_audit_templates_account (account_id, kind, archived_at),
    CONSTRAINT fk_audit_templates_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_template_sections (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    code        VARCHAR(8)   NOT NULL,
    name        VARCHAR(160) NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL,
    INDEX idx_audit_sections_template (template_id, position),
    CONSTRAINT fk_audit_sections_template FOREIGN KEY (template_id) REFERENCES audit_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_template_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id  INT UNSIGNED NOT NULL,
    text        VARCHAR(400) NOT NULL,
    -- Optional on a technician's own item: chapter 17 says the column is left
    -- blank on the protocol rather than invented.
    legal_basis VARCHAR(160) NULL DEFAULT NULL,
    scope       ENUM('V','R','VR') NOT NULL DEFAULT 'VR',
    linked_type VARCHAR(32)  NULL DEFAULT NULL,
    position    SMALLINT UNSIGNED NOT NULL,
    is_custom   TINYINT(1)   NOT NULL DEFAULT 1,
    INDEX idx_audit_items_section (section_id, position),
    CONSTRAINT fk_audit_items_section FOREIGN KEY (section_id) REFERENCES audit_template_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE inspections
    ADD CONSTRAINT fk_inspections_audit_template
        FOREIGN KEY (audit_template_id) REFERENCES audit_templates(id) ON DELETE SET NULL;
