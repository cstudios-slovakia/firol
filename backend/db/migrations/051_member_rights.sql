-- Chapter 1.6 — práva členov (client report after the update, point 2).
--
--   accounts.member_rights
--       One switch per account, set by the main user. `plne` = every member
--       may delete finished úkony / protocols (the behaviour so far, hence the
--       default); `obmedzene` = only the main user may, and members don't see
--       the buttons. Covers deleting a finished inspection or training,
--       „Upraviť" on an inspection (it discards the issued protocol), deleting
--       someone else's draft and deleting a visit.
--
--   inspections.created_by_user_id, trainings.created_by_user_id
--       Who created the úkon — a member may always delete their own draft
--       (chapter 1.6.1), whatever the switch says. NULL on rows created before
--       this migration (and on imported / restored rows); those fall back to
--       the assigned technician.

ALTER TABLE accounts
    ADD COLUMN member_rights ENUM('plne','obmedzene') NOT NULL DEFAULT 'plne';

ALTER TABLE inspections
    ADD COLUMN created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    ADD CONSTRAINT fk_inspections_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE trainings
    ADD COLUMN created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    ADD CONSTRAINT fk_trainings_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
