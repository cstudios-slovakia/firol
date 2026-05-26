-- Cert-number fallback between technicians on the same account.
--
-- Domain rules (from product spec):
--   * Technik PO cert is mandatory per technician — no fallback. Every
--     inspection type that prints cert_general (hydranty, požiarna kniha,
--     PU akcieschopnosť / údržba, núdzové osvetlenie, TS hadíc, školenia)
--     requires the executing technician to own cert_general.
--   * Kontrola PHP (cert_php) and Oprava / plnenie / TS PHP (cert_oprava)
--     may be borrowed: if the executing technician has no own number, the
--     protocol uses the account-default technician's name + cert.
--
-- The default technician is chosen by the account's main user from the
-- team roster. Each cert kind has its own default slot.
ALTER TABLE accounts
    ADD COLUMN default_php_user_id    INT UNSIGNED NULL AFTER main_user_id,
    ADD COLUMN default_oprava_user_id INT UNSIGNED NULL AFTER default_php_user_id,
    ADD CONSTRAINT fk_accounts_default_php_user
        FOREIGN KEY (default_php_user_id)    REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_accounts_default_oprava_user
        FOREIGN KEY (default_oprava_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Snapshot of the inspector identity and cert number that ended up on
-- the generated PDF. Filled in by DocumentController at render time and
-- never recomputed afterwards — so changing the default technician later
-- does not silently rewrite history.
--
--   effective_inspector_user_id — user shown in the inspector block on
--     the protocol (executing tech for PO-type inspections, or the
--     default tech when the executor borrowed their cert).
--   effective_cert_number       — exact cert string printed on the PDF.
ALTER TABLE inspections
    ADD COLUMN effective_inspector_user_id INT UNSIGNED   NULL AFTER inspector_user_id,
    ADD COLUMN effective_cert_number       VARCHAR(191)   NULL AFTER effective_inspector_user_id,
    ADD CONSTRAINT fk_inspections_effective_inspector
        FOREIGN KEY (effective_inspector_user_id) REFERENCES users(id);
