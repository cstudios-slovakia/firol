-- Block 2 / chapter 1.3.1 — firemné oprávnenia (company-level certificates).
--
-- Two oprávnenia belong to the technician's FIRM rather than to a person:
--   bts  Bezpečnostnotechnická služba      (§ 22 z. 124/2006)
--   vv   Výchova a vzdelávanie             (§ 27 ods. 3 z. 124/2006)
-- They are entered once per account by its main user, and the same number is
-- printed on the protocols of every technician in the account — which is why
-- they cannot live on inspector_profiles, where each technician keeps their
-- own personal numbers (po_technik, php_kontrola, php_oprava, bt).
--
-- One row per (account, type). Blocking of expired certificates and the
-- module rules around them belong to block 5 and are not modelled here.

CREATE TABLE account_certificates (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED NOT NULL,
    type        ENUM('bts', 'vv') NOT NULL,
    number      VARCHAR(191) NOT NULL,
    valid_from  DATE NULL DEFAULT NULL,
    valid_to    DATE NULL DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_account_certificates (account_id, type),
    CONSTRAINT fk_account_certificates_account
        FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
