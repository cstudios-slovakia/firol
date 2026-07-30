-- Legal consent at registration (change request 3.1).
--
-- The registration form carries a mandatory, never pre-ticked declaration that
-- the user has read the VOP and the privacy policy. What has to survive is
-- *when* they ticked it and *which version of the documents* they were shown —
-- so a later change to the VOP can be detected and re-acknowledged rather than
-- silently applied.
--
-- Stored on the user, not the account: consent is given by a person. Team
-- members invited later accept on their own invite flow.
--
-- Nullable because accounts registered before this change exist and never gave
-- a recorded consent; they are treated as "needs to acknowledge the current
-- version" and get the same in-app notice as users on an outdated version.

ALTER TABLE users
    ADD COLUMN terms_accepted_at DATETIME    NULL DEFAULT NULL,
    ADD COLUMN terms_version     VARCHAR(32) NULL DEFAULT NULL;
