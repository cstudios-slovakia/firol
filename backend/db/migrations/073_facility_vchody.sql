-- POapp 02. 11. 2026, Decision D1 — optional helper string `vchody` for bytove_druzstvo facilities.
-- Vchod is NEVER a separate entity. One bytový dom = one facility = one record.
-- `vchody` is only an optional text note/helper field on a bytový dom
-- (client_type === 'bytove_druzstvo', e.g. "3" or "2, 4, 6" or "A, B") to generate location tiles.

ALTER TABLE facilities
    ADD COLUMN vchody VARCHAR(255) NULL DEFAULT NULL AFTER description;

