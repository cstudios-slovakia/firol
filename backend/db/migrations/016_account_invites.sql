-- Team invitations require explicit acceptance by the invitee before
-- the technician appears in account_users. Previously invite() would
-- create the user/link immediately; now it only stores a pending
-- invitation here and the technician confirms via the link in the email.
--
-- The user row is NOT created at invite time — only on accept (for fresh
-- emails). This keeps the users table clean if an invite is never
-- accepted or is declined.

CREATE TABLE account_invites (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id          INT UNSIGNED            NOT NULL,
    email               VARCHAR(191)            NOT NULL,
    fullname            VARCHAR(191)            NOT NULL,
    phone               VARCHAR(64)             NULL,
    token               VARCHAR(64)             NOT NULL,
    invited_by_user_id  INT UNSIGNED            NOT NULL,
    expires_at          DATETIME                NOT NULL,
    accepted_at         DATETIME                NULL,
    declined_at         DATETIME                NULL,
    cancelled_at        DATETIME                NULL,
    created_at          DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_token (token),
    INDEX idx_account (account_id),
    INDEX idx_email (email),
    CONSTRAINT fk_invite_account FOREIGN KEY (account_id)         REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_invite_user    FOREIGN KEY (invited_by_user_id) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
