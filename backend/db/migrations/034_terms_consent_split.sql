-- Split combined terms consent into independent VOP / privacy policy
-- versions (follow-up to change request 3.1).
--
-- The VOP and the Zásady ochrany osobných údajov are separate legal
-- instruments that can be revised on independent schedules (e.g. the privacy
-- policy changing because a new subprocessor was added, with the VOP
-- untouched). A single shared version could not tell the two apart, so each
-- document now gets its own accepted version + timestamp.
--
-- Existing consents were given under the combined versioning scheme, so both
-- new pairs are backfilled from the old columns before those are dropped —
-- a user who accepted "VOP v1.0" accepted both documents at that version and
-- at that time.

ALTER TABLE users
    ADD COLUMN vop_version         VARCHAR(32) NULL DEFAULT NULL,
    ADD COLUMN vop_accepted_at     DATETIME    NULL DEFAULT NULL,
    ADD COLUMN privacy_version     VARCHAR(32) NULL DEFAULT NULL,
    ADD COLUMN privacy_accepted_at DATETIME    NULL DEFAULT NULL;

UPDATE users
SET vop_version         = terms_version,
    vop_accepted_at     = terms_accepted_at,
    privacy_version     = terms_version,
    privacy_accepted_at = terms_accepted_at
WHERE terms_version IS NOT NULL;

ALTER TABLE users
    DROP COLUMN terms_version,
    DROP COLUMN terms_accepted_at;
