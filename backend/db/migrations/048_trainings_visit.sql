-- Chapter 9 — a školenie PO on a visit.
--
-- `skolenie_po` is an OPP úkon like any other, so it can be planned on a
-- visit. Trainings are their own entity (`trainings`, 004), so the visit
-- thread has to reach them the same way it reaches inspections in 037:
--
--   trainings.visit_id                the visit the training was recorded
--                                     under. SET NULL on delete: like the
--                                     inspections, a training is a protocol
--                                     in its own right and outlives the visit.
--
--   work_confirmations.training_ids   the trainings listed on the potvrdenie
--                                     o vykonaní práce, frozen beside
--                                     inspection_ids (a confirmation restates
--                                     work already recorded and is re-rendered
--                                     from these when the client signs).

ALTER TABLE trainings
    ADD COLUMN visit_id INT UNSIGNED NULL DEFAULT NULL AFTER facility_id,
    ADD INDEX idx_trainings_visit (visit_id),
    ADD CONSTRAINT fk_trainings_visit FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE SET NULL;

ALTER TABLE work_confirmations
    ADD COLUMN training_ids JSON NULL DEFAULT NULL AFTER inspection_ids;
