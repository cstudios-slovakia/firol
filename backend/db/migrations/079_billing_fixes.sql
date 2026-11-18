-- Billing fixes of 17. 11. 2026 (docs/prompts/billing-fixes-2026-11.md) and
-- round 2 of 18. 11. 2026 (docs/prompts/billing-fixes-2026-11-round-2.md).
--
-- Every column and index is added IF NOT EXISTS, so a run that failed half
-- way can simply be run again.
--
-- 1. stripe_webhook_events gets a status, so an event is marked processed
--    only after it was handled. A failed one is handled again when Stripe
--    retries it (or by the daily run) instead of being skipped as a
--    duplicate. Rows written before this migration were all handled, so they
--    become 'done'. operator_alerted_at remembers that the daily run told the
--    operator about a failed event.
-- 2. invoices keeps what is needed to issue, retry or review an invoice
--    without asking Stripe again: the Stripe invoice as received (with all
--    lines), the Stripe totals (signed — a credit invoice has a negative
--    total, and amount_cents is UNSIGNED), the iDoklad payload built for it,
--    a review reason and the retry counter.
-- 3. accounts remembers the unit amount of the extra-technician item that is
--    really on the customer's Stripe subscription, so the screens show what
--    the customer pays instead of today's price setting, and whether a
--    promotion code is on the subscription (the next charge is then shown
--    as „pred zľavou").

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

-- Round 3 of 18. 11. 2026 (docs/prompts/billing-fixes-2026-11-round-3.md):
--
-- 4. accounts remembers the plan item of the subscription (so a changed base
--    price cannot make an unrelated item the plan item), the extra quantity
--    really on Stripe (the next charge is built from it while the reconcile
--    switch is off or the account is left for a person), the last seat
--    reconcile result (ok / would_change / manual / error, with its note) and
--    the customer's other live subscriptions as the daily run found them
--    (JSON, NULL when there is none).
-- 5. billing_operator_alerts remembers which one-off operator alerts were
--    mailed (an account left for a person, a second live subscription, a
--    take-over, a refused daily run, a webhook event Stripe no longer has),
--    so each is sent once; a key is written only after the mail went out, so
--    a failed send is repeated by the next daily run.

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
