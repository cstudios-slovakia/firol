-- POapp 26. 10. 2026, phase 2 — client type and prevádzka data (spec 3.1, 3.2, 5).
--
-- companies.client_type
--     firma (default, existing rows) or bytove_druzstvo. A prevádzka's kind
--     follows the company: under a družstvo every prevádzka is a bytový dom.
--
-- facilities
--     email                  the prevádzka's own address; it wins over the firm's
--     main_*_location        where the main power switch and the gas and water
--                            shut-offs are (§ 20 ods. 1 písm. c)
--     description            popis objektu, printed later when filled
--     floors_above/below     bytový dom only (1–40 / 0–5); NULL everywhere else
--     entrances, places      remembered by the walk-through (phase 7a); not
--                            edited on the form

ALTER TABLE companies
    ADD COLUMN client_type ENUM('firma', 'bytove_druzstvo') NOT NULL DEFAULT 'firma' AFTER name;

ALTER TABLE facilities
    ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL AFTER city,
    ADD COLUMN main_power_location VARCHAR(255) NULL DEFAULT NULL AFTER notes,
    ADD COLUMN main_gas_location VARCHAR(255) NULL DEFAULT NULL AFTER main_power_location,
    ADD COLUMN main_water_location VARCHAR(255) NULL DEFAULT NULL AFTER main_gas_location,
    ADD COLUMN description TEXT NULL DEFAULT NULL AFTER main_water_location,
    ADD COLUMN floors_above TINYINT UNSIGNED NULL DEFAULT NULL AFTER description,
    ADD COLUMN floors_below TINYINT UNSIGNED NULL DEFAULT NULL AFTER floors_above,
    ADD COLUMN entrances JSON NULL DEFAULT NULL AFTER floors_below,
    ADD COLUMN places JSON NULL DEFAULT NULL AFTER entrances;
