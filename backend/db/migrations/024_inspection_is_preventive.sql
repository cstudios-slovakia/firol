-- Distinguish a preventive fire inspection from a plain fire-book entry.
--
-- Požiarna kniha (fire book) records come in two flavours (change request 1.7):
--   1. A preventive fire inspection ("preventívna protipožiarna prehliadka")
--      — carries the statutory text, supersedes the previous inspection and
--      resets the next-due term from its date.
--   2. A plain entry (e.g. a note about a completed training) — MUST NOT
--      supersede the previous inspection nor shift the next-due term, otherwise
--      it would silently mask a missed statutory inspection (legally unacceptable).
--
-- The flag lives on the inspection (not the item) so the supersession query can
-- filter on it in SQL. All other inspection types are always "real" cycle-
-- advancing inspections, so the default is 1. Existing rows predate the split
-- and were all preventive inspections, so 1 is the correct backfill.

ALTER TABLE inspections
    ADD COLUMN is_preventive_inspection TINYINT(1) NOT NULL DEFAULT 1
    AFTER periodicity_months;
