-- 079 again, for databases that ran an earlier version of it.
--
-- 079_billing_fixes.sql was edited in rounds 2 and 3 of the billing fixes
-- (18. 11. 2026) while it was not deployed anywhere. migrate.php skips a
-- migration by its name, so a database that ran an earlier 079 (a developer
-- machine) never got the later columns, and every login answered 500. This
-- migration repeats every statement of the final 079 — all IF NOT EXISTS, so
-- on a database that has them nothing changes. 079 itself is not edited
-- again: from 19. 11. 2026 no migration that was pushed is edited (078–080
-- count as pushed).

ALTER TABLE stripe_webhook_events
    ADD COLUMN IF NOT EXISTS status              VARCHAR(16) NOT NULL DEFAULT 'done' AFTER event_type,
    ADD COLUMN IF NOT EXISTS attempts            INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN IF NOT EXISTS error_message       TEXT NULL AFTER attempts,
    ADD COLUMN IF NOT EXISTS processed_at        TIMESTAMP NULL DEFAULT NULL AFTER received_at,
    ADD COLUMN IF NOT EXISTS updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS operator_alerted_at DATETIME NULL;

UPDATE stripe_webhook_events SET processed_at = received_at WHERE processed_at IS NULL AND status = 'done';

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS stripe_total_cents       INT NULL AFTER amount_cents,
    ADD COLUMN IF NOT EXISTS stripe_amount_paid_cents INT NULL AFTER stripe_total_cents,
    ADD COLUMN IF NOT EXISTS stripe_livemode          TINYINT(1) NULL AFTER stripe_amount_paid_cents,
    ADD COLUMN IF NOT EXISTS review_reason            VARCHAR(32) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS stripe_invoice_json      MEDIUMTEXT NULL AFTER error_message,
    ADD COLUMN IF NOT EXISTS idoklad_payload_json     MEDIUMTEXT NULL AFTER stripe_invoice_json,
    ADD COLUMN IF NOT EXISTS attempts                 INT UNSIGNED NOT NULL DEFAULT 0 AFTER idoklad_payload_json,
    ADD COLUMN IF NOT EXISTS last_attempt_at          DATETIME NULL AFTER attempts,
    ADD COLUMN IF NOT EXISTS operator_alerted_at      DATETIME NULL AFTER last_attempt_at,
    ADD INDEX IF NOT EXISTS idx_invoices_status (status, last_attempt_at);

ALTER TABLE accounts
    ADD COLUMN IF NOT EXISTS stripe_extra_unit_amount_cents INT UNSIGNED NULL AFTER stripe_extra_subscription_item_id,
    ADD COLUMN IF NOT EXISTS stripe_extra_interval          VARCHAR(8) NULL AFTER stripe_extra_unit_amount_cents,
    ADD COLUMN IF NOT EXISTS stripe_base_unit_amount_cents  INT UNSIGNED NULL AFTER stripe_extra_interval,
    ADD COLUMN IF NOT EXISTS stripe_has_discount            TINYINT(1) NOT NULL DEFAULT 0 AFTER stripe_base_unit_amount_cents;


ALTER TABLE accounts
    ADD COLUMN IF NOT EXISTS stripe_base_subscription_item_id VARCHAR(64) NULL AFTER stripe_has_discount,
    ADD COLUMN IF NOT EXISTS stripe_extra_quantity            INT UNSIGNED NULL AFTER stripe_base_subscription_item_id,
    ADD COLUMN IF NOT EXISTS seat_sync_state                  VARCHAR(16) NULL AFTER stripe_extra_quantity,
    ADD COLUMN IF NOT EXISTS seat_sync_note                   TEXT NULL AFTER seat_sync_state,
    ADD COLUMN IF NOT EXISTS seat_sync_at                     DATETIME NULL AFTER seat_sync_note,
    ADD COLUMN IF NOT EXISTS stripe_other_subscriptions       TEXT NULL AFTER seat_sync_at;

CREATE TABLE IF NOT EXISTS billing_operator_alerts (
    alert_key  VARCHAR(191) NOT NULL PRIMARY KEY,
    sent_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
