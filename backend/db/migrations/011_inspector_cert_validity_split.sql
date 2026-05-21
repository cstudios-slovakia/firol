-- Add per-cert validity date columns to inspector_profiles.
-- Each certification type now has its own validity period instead of sharing
-- a single pair. Existing values are copied into all three new column pairs
-- so no data is lost.

ALTER TABLE inspector_profiles
    ADD COLUMN valid_from_rphp    DATE NULL AFTER valid_to,
    ADD COLUMN valid_to_rphp      DATE NULL AFTER valid_from_rphp,
    ADD COLUMN valid_from_oprava  DATE NULL AFTER valid_to_rphp,
    ADD COLUMN valid_to_oprava    DATE NULL AFTER valid_from_oprava,
    ADD COLUMN valid_from_general DATE NULL AFTER valid_to_oprava,
    ADD COLUMN valid_to_general   DATE NULL AFTER valid_from_general;

UPDATE inspector_profiles
SET    valid_from_rphp    = valid_from,
       valid_to_rphp      = valid_to,
       valid_from_oprava  = valid_from,
       valid_to_oprava    = valid_to,
       valid_from_general = valid_from,
       valid_to_general   = valid_to
WHERE  valid_from IS NOT NULL OR valid_to IS NOT NULL;
