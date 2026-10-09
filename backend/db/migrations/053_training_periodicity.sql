-- Chapter 5 — periodicity on trainings, and training terms in the calendar.
--
-- Trainings get the same value + unit pair as inspections (035): both NULL
-- means "bez opakovania", `periodicity_is_custom` records that the technician
-- chose a value outside the recommended one for the subtype.
--
-- NO BACK-FILL. Every training recorded before this migration keeps NULL/NULL
-- and reads as "podľa potreby": the technician never chose a period for it, and
-- inventing one would put terms into the calendar, the client notices and the
-- kniha BOZP that nobody agreed to (owner decision, 7. 10. 2026). Only new
-- trainings, and trainings edited where editing is still allowed, get a term.
--
-- Calendar plans and client notices were keyed by inspection_id alone. A
-- training term needs the same two layers, so each table gets a nullable
-- training_id with its own unique key and inspection_id becomes nullable.
-- The CHECK keeps the invariant that a row belongs to exactly one úkon; unique
-- keys ignore NULLs, so one training and one inspection can both be planned.

ALTER TABLE trainings
    ADD COLUMN periodicity_value SMALLINT UNSIGNED NULL DEFAULT NULL
        AFTER date,
    ADD COLUMN periodicity_unit ENUM('den','tyzden','mesiac') NULL DEFAULT NULL
        AFTER periodicity_value,
    ADD COLUMN periodicity_is_custom TINYINT(1) NOT NULL DEFAULT 0
        AFTER periodicity_unit;

ALTER TABLE calendar_plans
    MODIFY COLUMN inspection_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN training_id INT UNSIGNED NULL DEFAULT NULL
        AFTER inspection_id,
    ADD UNIQUE KEY uq_calendar_plan_training (training_id),
    ADD CONSTRAINT fk_calendar_plans_training
        FOREIGN KEY (training_id) REFERENCES trainings(id) ON DELETE CASCADE,
    ADD CONSTRAINT chk_calendar_plans_one_source
        CHECK ((inspection_id IS NULL) <> (training_id IS NULL));

ALTER TABLE deadline_notices
    MODIFY COLUMN inspection_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN training_id INT UNSIGNED NULL DEFAULT NULL
        AFTER inspection_id,
    ADD UNIQUE KEY uq_deadline_notices_training (training_id),
    ADD CONSTRAINT fk_deadline_notices_training
        FOREIGN KEY (training_id) REFERENCES trainings(id) ON DELETE CASCADE,
    ADD CONSTRAINT chk_deadline_notices_one_source
        CHECK ((inspection_id IS NULL) <> (training_id IS NULL));
