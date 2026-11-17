-- Billing fixes of 17. 11. 2026 (docs/prompts/billing-fixes-2026-11.md).
--
-- 1. stripe_webhook_events gets a status, so an event is marked processed
--    only after it was handled. A failed one is handled again when Stripe
--    retries it instead of being skipped as a duplicate. Rows written before
--    this migration were all handled, so they become 'done'.
-- 2. invoices keeps what is needed to issue, retry or review an invoice
--    without asking Stripe again: the Stripe invoice as received (with all
--    lines), the Stripe totals (signed — a credit invoice has a negative
--    total, and amount_cents is UNSIGNED), the iDoklad payload built for it,
--    a review reason and the retry counter.
-- 3. accounts remembers the unit amount of the extra-technician item that is
--    really on the customer's Stripe subscription, so the screens show what
--    the customer pays instead of today's price setting.

ALTER TABLE stripe_webhook_events
    ADD COLUMN status        VARCHAR(16) NOT NULL DEFAULT 'done' AFTER event_type,
    ADD COLUMN attempts      INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN error_message TEXT NULL AFTER attempts,
    ADD COLUMN processed_at  TIMESTAMP NULL DEFAULT NULL AFTER received_at,
    ADD COLUMN updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

UPDATE stripe_webhook_events SET processed_at = received_at WHERE processed_at IS NULL;

ALTER TABLE invoices
    ADD COLUMN stripe_total_cents       INT NULL AFTER amount_cents,
    ADD COLUMN stripe_amount_paid_cents INT NULL AFTER stripe_total_cents,
    ADD COLUMN stripe_livemode          TINYINT(1) NULL AFTER stripe_amount_paid_cents,
    ADD COLUMN review_reason            VARCHAR(32) NULL AFTER status,
    ADD COLUMN stripe_invoice_json      MEDIUMTEXT NULL AFTER error_message,
    ADD COLUMN idoklad_payload_json     MEDIUMTEXT NULL AFTER stripe_invoice_json,
    ADD COLUMN attempts                 INT UNSIGNED NOT NULL DEFAULT 0 AFTER idoklad_payload_json,
    ADD COLUMN last_attempt_at          DATETIME NULL AFTER attempts,
    ADD COLUMN operator_alerted_at      DATETIME NULL AFTER last_attempt_at,
    ADD INDEX idx_invoices_status (status, last_attempt_at);

ALTER TABLE accounts
    ADD COLUMN stripe_extra_unit_amount_cents INT UNSIGNED NULL AFTER stripe_extra_subscription_item_id,
    ADD COLUMN stripe_extra_interval          VARCHAR(8) NULL AFTER stripe_extra_unit_amount_cents,
    ADD COLUMN stripe_base_unit_amount_cents  INT UNSIGNED NULL AFTER stripe_extra_interval;
