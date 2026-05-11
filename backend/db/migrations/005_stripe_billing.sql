-- Phase 6b — Stripe billing columns on accounts.
--
-- `stripe_customer_id` is provisioned during registration so the very
-- first Checkout call can attach the subscription to an existing
-- Customer (instead of creating a fresh one every time). The other
-- columns mirror what we get back from the Subscription object so we
-- can render the current state without round-tripping to Stripe on
-- every page load.

ALTER TABLE accounts
    ADD COLUMN stripe_customer_id      VARCHAR(64) NULL AFTER theme_color,
    ADD COLUMN stripe_subscription_id  VARCHAR(64) NULL AFTER stripe_customer_id,
    ADD COLUMN stripe_status           VARCHAR(32) NULL AFTER stripe_subscription_id,
    ADD COLUMN billing_period          VARCHAR(16) NULL AFTER stripe_status;

CREATE UNIQUE INDEX idx_accounts_stripe_customer
    ON accounts(stripe_customer_id);
