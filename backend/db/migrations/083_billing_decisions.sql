-- Billing: the owner's decisions of 18. 11. 2026 and the leftovers of round 3
-- (docs/prompts/billing-decisions-2026-11.md). Everything IF NOT EXISTS.
--
-- invoices
--   stripe_tax_cents         Stripe's VAT of the invoice (prices are net, VAT
--                            is added by Stripe's tax rate).
--   operator_alerted_state   the state the operator was last alerted about
--                            (status/reason, or send:… / paid:…): the alert
--                            memory is per row AND state, so a row that
--                            moves to another state needing a person is
--                            alerted again. Rows alerted before get their
--                            current state.
--   snapshot_read_failures   failed reads of a row's Stripe invoice; at 3 (or
--                            a 404) the row becomes needs_review /
--                            snapshot_unreadable, no longer fetched.
--   paid_mark_*              marking the issued invoice paid in iDoklad
--                            (state pending → marking → done | error).
--   send_*, sent_at, sent_to iDoklad e-mailing the invoice to the customer
--                            (pending → sending → sent | error | uncertain).
-- accounts
--   idoklad_contact_email    the e-mail the app last put on the account's
--                            iDoklad contact (where iDoklad sends invoices).
--   stripe_tax_rate_ids      the default tax rates of the subscription as last
--                            read (ConfigCheck: a live subscription without
--                            STRIPE_TAX_RATE_ID is an error).
-- stripe_webhook_events
--   dismissed_at             an admin dismissed a `gone` event (a gone
--                            invoice.* event is an error until then).
-- billing_payment_failures   one row per Stripe invoice whose payment failed:
--                            when (the grace period of PAYMENT_GRACE_DAYS
--                            counts from failed_at), the amount, whether the
--                            main user was e-mailed, and when it was resolved
--                            (paid, or the subscription paid up again).

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS stripe_tax_cents        INT NULL AFTER stripe_amount_paid_cents,
    ADD COLUMN IF NOT EXISTS operator_alerted_state  VARCHAR(64) NULL AFTER operator_alerted_at,
    ADD COLUMN IF NOT EXISTS snapshot_read_failures  INT UNSIGNED NOT NULL DEFAULT 0 AFTER operator_alerted_state,
    ADD COLUMN IF NOT EXISTS paid_mark_state         VARCHAR(16) NULL AFTER snapshot_read_failures,
    ADD COLUMN IF NOT EXISTS paid_mark_attempts      INT UNSIGNED NOT NULL DEFAULT 0 AFTER paid_mark_state,
    ADD COLUMN IF NOT EXISTS paid_marked_at          DATETIME NULL AFTER paid_mark_attempts,
    ADD COLUMN IF NOT EXISTS paid_mark_error         TEXT NULL AFTER paid_marked_at,
    ADD COLUMN IF NOT EXISTS send_state              VARCHAR(16) NULL AFTER paid_mark_error,
    ADD COLUMN IF NOT EXISTS send_attempts           INT UNSIGNED NOT NULL DEFAULT 0 AFTER send_state,
    ADD COLUMN IF NOT EXISTS sent_at                 DATETIME NULL AFTER send_attempts,
    ADD COLUMN IF NOT EXISTS sent_to                 VARCHAR(254) NULL AFTER sent_at,
    ADD COLUMN IF NOT EXISTS send_error              TEXT NULL AFTER sent_to;

UPDATE invoices
SET    operator_alerted_state = IF(review_reason IS NULL OR review_reason = '', status, CONCAT(status, '/', review_reason))
WHERE  operator_alerted_at IS NOT NULL AND operator_alerted_state IS NULL;

ALTER TABLE accounts
    ADD COLUMN IF NOT EXISTS idoklad_contact_email VARCHAR(254) NULL AFTER idoklad_contact_id,
    ADD COLUMN IF NOT EXISTS stripe_tax_rate_ids   VARCHAR(255) NULL AFTER stripe_other_subscriptions;

ALTER TABLE stripe_webhook_events
    ADD COLUMN IF NOT EXISTS dismissed_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS billing_payment_failures (
    stripe_invoice_id       VARCHAR(64)  NOT NULL PRIMARY KEY,
    account_id              INT UNSIGNED NOT NULL,
    stripe_subscription_id  VARCHAR(64)  NULL,
    amount_due_cents        INT          NOT NULL DEFAULT 0,
    currency                VARCHAR(8)   NOT NULL DEFAULT 'EUR',
    hosted_invoice_url      TEXT         NULL,
    livemode                TINYINT(1)   NULL,
    failed_at               DATETIME     NOT NULL,
    emailed_at              DATETIME     NULL,
    resolved_at             DATETIME     NULL,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_failures_account (account_id, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
