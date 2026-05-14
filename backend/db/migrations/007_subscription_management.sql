-- Phase 6d — richer subscription management.
--
-- 1. Persist Stripe's `cancel_at_period_end` flag on the account so the
--    UI can show "scheduled to cancel on X" without round-tripping to
--    Stripe on every render. Synced from the
--    customer.subscription.updated webhook.
-- 2. Persist downloadable invoice URLs alongside each invoices row so
--    the billing page can render a per-invoice "Stiahnuť PDF" link
--    without an extra backend hop:
--      - idoklad_public_url   — iDoklad's PublicHtmlUrl (the legal SK
--                                invoice).
--      - stripe_invoice_pdf_url — Stripe-hosted PDF receipt, used as a
--                                fallback when iDoklad is off or the
--                                invoice errored out.

ALTER TABLE accounts
    ADD COLUMN stripe_cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_period;

ALTER TABLE invoices
    ADD COLUMN idoklad_public_url     VARCHAR(1024) NULL AFTER document_number,
    ADD COLUMN stripe_invoice_pdf_url VARCHAR(1024) NULL AFTER idoklad_public_url;
