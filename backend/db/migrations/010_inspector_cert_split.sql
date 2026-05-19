-- Split the single certification_number on inspector_profiles into three
-- domain-specific numbers matching Slovak fire-protection licensing reality:
--
--   cert_rphp    – oprávnenie na kontrolu hasiacich prístrojov (RPHP)
--   cert_oprava  – oprávnenie na opravu / plnenie / tlakovú skúšku RPHP
--   cert_general – osvedčenie technika PO — platí pre všetko ostatné
--                  (hydranty, požiarna kniha, požiarne uzávery, núdzové
--                   osvetlenia, tlaková skúška hadíc, školenia)
--
-- Existing data is migrated into cert_general so nothing is lost.
-- The legacy certification_number column is kept for the moment to avoid
-- breaking any external tooling; it is no longer written by the app.

ALTER TABLE inspector_profiles
    ADD COLUMN cert_rphp    VARCHAR(64) NULL AFTER certification_number,
    ADD COLUMN cert_oprava  VARCHAR(64) NULL AFTER cert_rphp,
    ADD COLUMN cert_general VARCHAR(64) NULL AFTER cert_oprava;

UPDATE inspector_profiles
SET    cert_general = certification_number
WHERE  certification_number IS NOT NULL;
