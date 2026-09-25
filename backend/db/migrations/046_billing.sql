-- Block 4 / chapter 22 — fakturácia úkonu.
--
-- Not the SaaS subscription (accounts.stripe_*, BillingController): this is
-- the technician's own record of which úkony they still have to invoice to
-- their clients. The spec's model is
--
--     fakturacia_ukonu { rezim, vyfakturovane, vyfakturovane_kedy, poznamka }
--
-- and it sits on every úkon — inspections (revízne, PO and BOZP types) as
-- well as trainings. There is deliberately no column for payment: the spec
-- forbids tracking whether an invoice was paid.
--
-- companies.billing_mode — what a new úkon for this client is prefilled with.
-- The spec offers only paušál / na faktúru here. Existing clients start on
-- `na_fakturu`: an úkon nobody invoices costs money, a client that is on a
-- paušál is a one-time switch on the firm, and history is not affected (see
-- below), so the default floods nothing.
--
-- inspections / trainings.billing_mode — the úkon's own režim. NULL means
-- "recorded before the app tracked invoicing": every úkon that exists when
-- this migration runs stays NULL, which keeps years of already-invoiced work
-- out of the „Nevyfakturované" list. New úkony take the firm's setting at
-- creation (Firol\Support\Invoicing::companyMode); the technician can change
-- it on the úkon at any time, including on an issued protocol.
--
-- invoiced / invoiced_at — the check-off. Only meaningful with `na_fakturu`;
-- the API clears both when the režim is anything else.

ALTER TABLE companies
    ADD COLUMN billing_mode ENUM('pausal', 'na_fakturu') NOT NULL DEFAULT 'na_fakturu' AFTER approver;

ALTER TABLE inspections
    ADD COLUMN billing_mode ENUM('pausal', 'na_fakturu', 'nefakturuje_sa') NULL DEFAULT NULL AFTER details,
    ADD COLUMN invoiced     TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_mode,
    ADD COLUMN invoiced_at  DATE NULL DEFAULT NULL AFTER invoiced,
    ADD COLUMN billing_note VARCHAR(1000) NULL DEFAULT NULL AFTER invoiced_at,
    ADD KEY idx_inspections_uninvoiced (account_id, billing_mode, invoiced);

ALTER TABLE trainings
    ADD COLUMN billing_mode ENUM('pausal', 'na_fakturu', 'nefakturuje_sa') NULL DEFAULT NULL AFTER status,
    ADD COLUMN invoiced     TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_mode,
    ADD COLUMN invoiced_at  DATE NULL DEFAULT NULL AFTER invoiced,
    ADD COLUMN billing_note VARCHAR(1000) NULL DEFAULT NULL AFTER invoiced_at,
    ADD KEY idx_trainings_uninvoiced (account_id, billing_mode, invoiced);
