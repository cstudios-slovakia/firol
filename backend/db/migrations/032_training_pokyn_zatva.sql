-- Pokyn — žatevné práce moves from inspections to trainings.
--
-- Change request 2.3 left the placement open ("k dokumentom/školeniam podľa
-- uváženia"). The Pokyn is not a control of a device: it is an instruction
-- issued once a year to the client's own employees, so it belongs next to the
-- školenia, which are the other documents addressed to those employees. It
-- keeps its own ZAT-RRRR-NNN number series — the SKO series stays reserved
-- for the six attendance-based trainings.
--
-- `fields` carries the per-type document payload for trainings that are not a
-- plain attendee list. For pokyn_zatva it holds
-- { year, approver, sections: [{title, text}] } — the instruction text is
-- stored *with* the document rather than referencing a shared template,
-- because the technician edits it before generating and an issued document
-- must keep saying what it said the day it was issued.
-- NULL for the six classic training types.

ALTER TABLE trainings
    ADD COLUMN fields JSON NULL AFTER topics;

-- Any pokyn_zatva inspection created while the document lived on the
-- inspection side is archived, not deleted: the type no longer exists there,
-- so the row can neither be opened nor generated, but archiving keeps the
-- record (and its already-issued PDF in `documents`) recoverable.
UPDATE inspections
SET    archived_at = NOW()
WHERE  type = 'pokyn_zatva'
  AND  archived_at IS NULL;
