-- Bezpečnostný technik oprávnenie on the inspector profile.
--
-- Every BOZP úkon is signed as a bezpečnostný technik, not as a technik PO
-- (chapter 26: the number on the protocol must match the type of úkon), so
-- the profile gets its own personal BT number and validity next to the PO
-- ones. The legacy single cert column is never used as a fallback for it.

ALTER TABLE inspector_profiles
    ADD COLUMN cert_bt       VARCHAR(64) NULL AFTER cert_general,
    ADD COLUMN valid_from_bt DATE        NULL AFTER valid_to_general,
    ADD COLUMN valid_to_bt   DATE        NULL AFTER valid_from_bt;
