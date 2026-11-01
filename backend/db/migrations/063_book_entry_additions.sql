-- POapp 26. 10. 2026, phase 5 — „dopísanie" to a book entry (spec 9.3c).
--
-- A fact that came later (a nedostatok was removed on …) is written under the
-- entry; the original text is never changed. Printed after the entry's own
-- text, in the order written.

CREATE TABLE IF NOT EXISTS book_entry_additions (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id         INT UNSIGNED NOT NULL,
    entry_id           INT UNSIGNED NOT NULL,
    `text`             TEXT         NOT NULL,
    created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_book_entry_additions_entry (entry_id, id),
    CONSTRAINT fk_book_additions_account FOREIGN KEY (account_id)         REFERENCES accounts(id)     ON DELETE CASCADE,
    CONSTRAINT fk_book_additions_entry   FOREIGN KEY (entry_id)           REFERENCES book_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_book_additions_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
