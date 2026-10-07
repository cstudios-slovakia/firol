-- Chapter 9, step 5 — "protokol na konci návštevy".
--
-- A technician working through a visit can finish an úkon without issuing
-- its protocol and have it generated together with the others at the end of
-- the visit ("Generovať všetky protokoly"). The held state is only a marker
-- on a draft úkon: the úkon stays fully editable, and generating (now or in
-- bulk) issues it like any other draft.
--
--   inspections.protocol_deferred_at, trainings.protocol_deferred_at
--       When the technician chose to hold the protocol; NULL = not held.
--       Cleared when an issued protocol is discarded (unlock).

ALTER TABLE inspections
    ADD COLUMN protocol_deferred_at DATETIME NULL DEFAULT NULL;

ALTER TABLE trainings
    ADD COLUMN protocol_deferred_at DATETIME NULL DEFAULT NULL;
