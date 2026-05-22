-- Phase 13 — merge trainers into users.
--
-- Originally trainers were a separate per-account pool of named people used
-- as the "Vykonal školenie" entry on the generated training PDF. Per
-- product decision, every registered user can act both as inspector and as
-- trainer: the signature and certification on the PDF come from the user's
-- inspector_profile (signature_path + cert_general). The trainers table is
-- dropped and trainings.trainer_id now references users.id directly.

ALTER TABLE trainings DROP FOREIGN KEY fk_trainings_trainer;

UPDATE trainings SET trainer_id = NULL;

DROP TABLE trainers;

ALTER TABLE trainings
    ADD CONSTRAINT fk_trainings_trainer
        FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE SET NULL;
