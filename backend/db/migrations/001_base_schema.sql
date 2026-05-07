-- Base schema: users, accounts, account_users, password_resets, system_settings.
-- Multi-tenancy: every domain table created later MUST carry account_id and
-- queries MUST filter on the active account at the data layer.

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fullname        VARCHAR(191)            NOT NULL,
    email           VARCHAR(191)            NOT NULL UNIQUE,
    phone           VARCHAR(64)             NULL,
    password_hash   VARCHAR(255)            NOT NULL,
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_company_name    VARCHAR(191)            NOT NULL,
    invoice_street          VARCHAR(191)            NULL,
    invoice_postal_code     VARCHAR(32)             NULL,
    invoice_city            VARCHAR(128)            NULL,
    invoice_country         VARCHAR(64)             NOT NULL DEFAULT 'Slovensko',
    invoice_ico             VARCHAR(32)             NULL,
    invoice_dic             VARCHAR(32)             NULL,
    invoice_ic_dph          VARCHAR(32)             NULL,
    logo_path               VARCHAR(255)            NULL,
    theme_color             VARCHAR(7)              NULL,
    subscription_end_date   DATE                    NOT NULL,
    main_user_id            INT UNSIGNED            NOT NULL,
    created_at              DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_accounts_main_user FOREIGN KEY (main_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE account_users (
    account_id      INT UNSIGNED            NOT NULL,
    user_id         INT UNSIGNED            NOT NULL,
    role            VARCHAR(32)             NOT NULL DEFAULT 'technician',
    is_active       TINYINT(1)              NOT NULL DEFAULT 1,
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id, user_id),
    CONSTRAINT fk_au_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_au_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    token           CHAR(64)                NOT NULL PRIMARY KEY,
    user_id         INT UNSIGNED            NOT NULL,
    expires_at      DATETIME                NOT NULL,
    used_at         DATETIME                NULL,
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_user (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
    setting_key     VARCHAR(64)             NOT NULL PRIMARY KEY,
    setting_value   VARCHAR(512)            NOT NULL,
    updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('trial_days',          '14'),
    ('price_monthly_eur',   '19'),
    ('price_yearly_eur',    '199');
