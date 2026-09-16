-- Block 1 / chapter 5 — periodicity becomes a value + unit pair and is
-- optional on every inspection type.
--
-- Until now periodicity was a fixed number of months chosen from a closed
-- per-type whitelist, and two types carried it as a statutory constant. The
-- spec makes it a technician's decision on every type: any value in days,
-- weeks or months, or "bez opakovania" (no recurrence at all).
--
--   periodicity_value + periodicity_unit  — both NULL means "bez opakovania"
--   periodicity_is_custom                 — the technician typed a value
--                                           outside the recommended list
--
-- periodicity_months stays as the legacy column only for the duration of this
-- migration's back-fill and is dropped at the end: keeping two sources of
-- truth for the same fact is what makes deadline math drift.
--
-- Back-fill: every existing inspection recorded months, so unit = 'mesiac'
-- and value = periodicity_months. The one exception is the vyraďovací
-- protokol, stored as 0 months to mean "one-off" — that becomes NULL/NULL,
-- which is the same statement in the new vocabulary.

ALTER TABLE inspections
    ADD COLUMN periodicity_value SMALLINT UNSIGNED NULL DEFAULT NULL
        AFTER type,
    ADD COLUMN periodicity_unit ENUM('den','tyzden','mesiac') NULL DEFAULT NULL
        AFTER periodicity_value,
    ADD COLUMN periodicity_is_custom TINYINT(1) NOT NULL DEFAULT 0
        AFTER periodicity_unit;

UPDATE inspections
   SET periodicity_value = periodicity_months,
       periodicity_unit  = 'mesiac'
 WHERE periodicity_months > 0;

ALTER TABLE inspections DROP COLUMN periodicity_months;
