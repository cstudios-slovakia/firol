-- Idempotency log for Stripe webhook events.
--
-- Stripe occasionally retries the same event (e.g. when our endpoint
-- returns >= 500). Without an event-id-keyed record we cannot tell a
-- retry from a fresh event, which led to duplicate fallback emails in
-- InvoiceIssuer when iDoklad was unconfigured.
--
-- Insert at the very top of BillingController::webhook BEFORE running
-- any side effects; a duplicate insert raises 23000 which the caller
-- treats as "already processed — ack and skip".

CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    event_id    VARCHAR(64) NOT NULL PRIMARY KEY,
    event_type  VARCHAR(96) NOT NULL,
    received_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
