-- Phase 6c — iDoklad invoice tracking.
--
-- We mirror just enough of each iDoklad invoice to render the user's
-- billing history without re-querying iDoklad on every page load. The
-- canonical source of truth for the invoice (line items, PDF, payment
-- status, etc.) stays in iDoklad — we only care about ID, number, and
-- amount on our side.

ALTER TABLE accounts
    ADD COLUMN idoklad_contact_id INT UNSIGNED NULL AFTER stripe_subscription_id;

CREATE TABLE invoices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id          INT UNSIGNED  NOT NULL,
    stripe_invoice_id   VARCHAR(64)   NOT NULL,
    idoklad_invoice_id  INT UNSIGNED  NULL,
    document_number     VARCHAR(64)   NULL,
    amount_cents        INT UNSIGNED  NOT NULL,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'EUR',
    status              VARCHAR(32)   NOT NULL DEFAULT 'paid',
    issued_at           DATETIME      NOT NULL,
    error_message       TEXT          NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_invoices_stripe (stripe_invoice_id),
    INDEX idx_invoices_account (account_id, issued_at DESC),
    CONSTRAINT fk_invoices_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
