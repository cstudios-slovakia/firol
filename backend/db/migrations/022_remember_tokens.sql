-- Persistent "remember me" login. The PHP session idles out after a short
-- window (session.gc_maxlifetime) and its cookie dies on browser close; this
-- table backs a long-lived cookie that silently re-establishes the session.
--
-- Selector/validator split: the cookie carries `<selector>:<validator>`. The
-- selector is a public indexed lookup key; only the SHA-256 hash of the
-- validator is stored, so a leaked database can't be turned into forged
-- cookies. The validator is rotated on every use and the expiry slid forward.

CREATE TABLE remember_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    selector        CHAR(18)                NOT NULL,
    validator_hash  CHAR(64)                NOT NULL,
    user_id         INT UNSIGNED            NOT NULL,
    account_id      INT UNSIGNED            NOT NULL,
    expires_at      DATETIME                NOT NULL,
    last_used_at    DATETIME                NULL,
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_selector (selector),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at),
    CONSTRAINT fk_remember_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_remember_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
