-- POapp 26. 10. 2026, spec 4 / 11.1 — a new firma defaults to Paušál.
--
-- Only the column default changes (migration 046 had `na_fakturu`). Existing
-- rows keep the value they have; MODIFY on a column that already carries the
-- new default is a no-op, so re-running this is harmless.

ALTER TABLE companies
    MODIFY COLUMN billing_mode ENUM('pausal', 'na_fakturu') NOT NULL DEFAULT 'pausal';
