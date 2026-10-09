-- Spec 25 — archivácia firmy a prevádzky.
--
-- A firma or prevádzka is never deleted from the UI any more, only archived
-- (its protocols must be kept after the client leaves) and it can be restored
-- at any time. `archived_at` (migration 002) already carries the stav and the
-- date; this adds the optional reason and the task state the spec asks for.
--
--   companies.archived_reason / facilities.archived_reason
--       spec `archivovana_dovod`, optional free text. Cleared on restore.
--
--   tasks.cancel_reason
--       Archiving closes the open úlohy of that firma / prevádzka with the
--       stav „zrušené — firma archivovaná" (spec 25). Such a task is done = 1
--       like a ticked one, so every open list and count already leaves it
--       out; this column only tells „zrušená" apart from „splnená".
--       NULL = ticked by a person. Restoring the firma does not reopen them.

ALTER TABLE companies
    ADD COLUMN archived_reason VARCHAR(500) NULL DEFAULT NULL AFTER archived_at;

ALTER TABLE facilities
    ADD COLUMN archived_reason VARCHAR(500) NULL DEFAULT NULL AFTER archived_at;

ALTER TABLE tasks
    ADD COLUMN cancel_reason ENUM('company_archived', 'facility_archived') NULL DEFAULT NULL AFTER done_at;
