-- Linked protocols — follow-up draft provenance (change request 2.1).
--
-- When a PHP inspection finds prístroje "na tlakovú skúšku", or a hydrant
-- inspection is completed, the app offers to create a pre-filled DRAFT of the
-- follow-up control (Oprava/plnenie/TS PHP, resp. Tlaková skúška hadíc). The
-- draft records which inspection it grew out of so both sides can show the
-- link and so re-triggering the offer for the same source doesn't create a
-- duplicate draft.
--
-- Nullable: the vast majority of inspections have no source. ON DELETE SET NULL
-- keeps a follow-up draft intact if the original is later archived/removed.

ALTER TABLE inspections
    ADD COLUMN source_inspection_id INT UNSIGNED NULL DEFAULT NULL
        AFTER facility_id,
    ADD INDEX idx_inspections_source (source_inspection_id),
    ADD CONSTRAINT fk_inspections_source
        FOREIGN KEY (source_inspection_id) REFERENCES inspections (id)
        ON DELETE SET NULL;
