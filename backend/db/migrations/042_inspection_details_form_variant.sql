-- Block 2 / chapters 7, 8 and 8.1 — header data of an úkon and the two
-- printed variants of a breath / drug test.
--
-- inspections.details
--   Data that belongs to the úkon as a whole rather than to one of its rows:
--   the device of a dychová skúška (typ prístroja, výrobné číslo, platnosť
--   kalibrácie), the test kit of a kontrola omamných látok (typ testu, šarža,
--   exspirácia), their opatrenia, and the druh / časový rozsah / obsah of an
--   oboznámenie BOZP. The rows themselves (the tested persons, the
--   participants) stay in inspection_items like every other úkon's items.
--   Validated per type by Firol\Support\InspectionDetails; NULL for every type
--   that has no header data.
--
-- documents.form_variant / document_versions.form_variant
--   Chapter 8.1: a dychová skúška and a kontrola omamných látok can be printed
--   as a blank form for handwriting („prazdny") or as the filled record
--   („vyplneny"). Both are the SAME protocol — one number — and the filled one
--   is issued as the next version of it once the results are typed in, so the
--   blank sheet the client signed and the legible record in the app share the
--   number printed on both. The column records which variant each version is
--   so a re-render (a signature added on screen, chapter 13) reproduces the
--   variant it replaces. NULL for every other document type.

ALTER TABLE inspections
    ADD COLUMN details LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL
        CHECK (details IS NULL OR JSON_VALID(details))
        AFTER notes;

ALTER TABLE documents
    ADD COLUMN form_variant ENUM('vyplneny', 'prazdny') NULL DEFAULT NULL AFTER version;

ALTER TABLE document_versions
    ADD COLUMN form_variant ENUM('vyplneny', 'prazdny') NULL DEFAULT NULL AFTER version;
