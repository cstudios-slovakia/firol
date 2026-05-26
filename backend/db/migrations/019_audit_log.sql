-- Append-only audit log for sensitive admin actions.
--
-- Captured: who acted, what they touched, and the JSON before/after
-- snapshot. Intended for admin-panel writes (account updates, user
-- promote/demote, deletes) so we have an audit trail when an account
-- comes back with "who changed our seat count".
--
-- Free-form by design: target_type names the table, target_id its row.
-- Each side records what changed, not the full row, to keep the log
-- compact.

CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NULL,
    action      VARCHAR(64) NOT NULL,
    target_type VARCHAR(32) NOT NULL,
    target_id   INT UNSIGNED NULL,
    before_json JSON NULL,
    after_json  JSON NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_target (target_type, target_id),
    INDEX idx_audit_actor  (actor_user_id, created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
