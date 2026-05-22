-- User-submitted feedback: bug reports and feature requests.
-- Anyone authenticated may submit; only app admins read/delete.
-- account_id + user_id are nullable so a deleted account/user does not
-- erase the report itself; the snapshot fields preserve who sent it.

CREATE TABLE feedback_submissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind            ENUM('bug','feature') NOT NULL,
    message         TEXT                  NOT NULL,
    source_url      VARCHAR(1024)         NULL,
    user_agent      VARCHAR(512)          NULL,
    account_id      INT UNSIGNED          NULL,
    user_id         INT UNSIGNED          NULL,
    submitter_name  VARCHAR(191)          NULL,
    submitter_email VARCHAR(191)          NULL,
    account_name    VARCHAR(191)          NULL,
    created_at      DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_feedback_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_feedback_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE SET NULL,
    INDEX idx_feedback_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
