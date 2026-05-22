-- Phase 6e follow-up — adjust the default seat policy.
--
-- New policy:
--   * Base plan includes 2 technicians (incl. account admin), was 3.
--   * Each extra technician costs €10 / month, was €5.
--
-- Both values remain admin-configurable via the system_settings table;
-- this migration only shifts the defaults and bumps existing system-wide
-- settings. Per-account `included_technicians` overrides that previously
-- inherited the old default of 3 are reduced to 2 — anything else was
-- a deliberate admin override and is preserved.

UPDATE system_settings
SET    setting_value = '2'
WHERE  setting_key   = 'default_included_technicians'
  AND  setting_value = '3';

UPDATE system_settings
SET    setting_value = '1000'
WHERE  setting_key   = 'price_per_extra_technician_cents'
  AND  setting_value = '500';

ALTER TABLE accounts
    MODIFY COLUMN included_technicians INT UNSIGNED NOT NULL DEFAULT 2;

UPDATE accounts
SET    included_technicians = 2
WHERE  included_technicians = 3;
