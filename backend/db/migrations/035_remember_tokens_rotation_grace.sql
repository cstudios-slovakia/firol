-- Grace window for "remember me" validator rotation.
--
-- The validator is rotated on every resume. When the session has idled out
-- and the SPA fires several requests at once (the dashboard sends four), all
-- of them carry the same cookie: the first one rotates it and the rest arrive
-- with the value that was just replaced. Without a grace window those were
-- treated as a stolen token — the row was deleted and the user logged out.
--
-- Keep the hash of the previous validator plus the moment it was replaced, so
-- a request presenting it within a few seconds of the rotation is accepted
-- instead of being mistaken for replay.

ALTER TABLE remember_tokens
    ADD COLUMN prev_validator_hash CHAR(64) NULL DEFAULT NULL AFTER validator_hash,
    ADD COLUMN rotated_at          DATETIME NULL DEFAULT NULL AFTER prev_validator_hash;
