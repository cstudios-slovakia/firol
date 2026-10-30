-- POapp 26. 10. 2026, spec 3.7 / 8 — školenia: osobné číslo, hliadky, platnosť.
--
--   trainees.personal_number
--       spec `osobne_cislo`, optional. The protocol prints the column only when
--       at least one attendee of that training has a number.
--
--   trainings.patrol_scope
--       spec `pracovisko` (8.2): the workplace of a protipožiarna hliadka
--       pracoviska, or the activity / event of an asistenčná hliadka. Used by
--       the two hliadka types only; free text, optional.
--
--   trainings.valid_until_override
--       spec 8.1 „Platnosť sa musí dať prepísať rukou". NULL = the validity is
--       date + periodicity, as before. When set it replaces that date in the
--       terms (calendar, časová os, Dnes, client notices, kniha BOZP).
--
-- ADD COLUMN IF NOT EXISTS makes a re-run harmless.

ALTER TABLE trainees
    ADD COLUMN IF NOT EXISTS personal_number VARCHAR(32) NULL DEFAULT NULL AFTER position;

ALTER TABLE trainings
    ADD COLUMN IF NOT EXISTS patrol_scope VARCHAR(255) NULL DEFAULT NULL AFTER topics,
    ADD COLUMN IF NOT EXISTS valid_until_override DATE NULL DEFAULT NULL AFTER periodicity_is_custom;
