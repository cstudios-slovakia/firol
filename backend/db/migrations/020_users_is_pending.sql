-- Pending (unclaimed) users.
--
-- Inspection imports may reference a technician by email who has no Firol
-- account yet. Rather than rejecting the row, the import pre-creates a
-- placeholder user so the inspection can be attributed immediately and links
-- it to the importing account as an inactive technician. The placeholder has
-- an empty password_hash (login/verify always fail) and is flagged pending.
--
-- When the person later registers under the same email, register() claims the
-- placeholder: it sets the password, clears is_pending and activates the
-- membership. The imported inspections are then already theirs.

ALTER TABLE users
    ADD COLUMN is_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
