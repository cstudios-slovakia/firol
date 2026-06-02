-- Fix training type slugs written by the Excel import. The import schema used
-- wrong slugs (zdrzujuce_sa / priprava_oph / priprava_opah) that the rest of
-- the app does not recognise, so imported trainings of those types showed an
-- empty "Typ" everywhere. Remap them to the canonical slugs used by the
-- TrainingController, PDF template and frontend.

UPDATE trainings SET type = 'zdrzujuca_sa' WHERE type = 'zdrzujuce_sa';
UPDATE trainings SET type = 'hliadka_oph'  WHERE type = 'priprava_oph';
UPDATE trainings SET type = 'hliadka_opah' WHERE type = 'priprava_opah';
