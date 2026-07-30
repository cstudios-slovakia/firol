-- Photo documentation (change request 2.2).
--
-- Photos hang off a single inspection item (hasiaci prístroj, hydrant,
-- požiarny uzáver, or a požiarna kniha nedostatok — all of them are rows in
-- inspection_items, so one table covers every inspection type).
--
-- Both a full-size and a thumbnail derivative are stored. The full size is
-- what lands in the PDF appendix; the thumbnail is what the item list loads,
-- so opening a protocol with 60 photos doesn't pull 20 MB over mobile data.
-- Files live under storage/photos/{account_id}/{inspection_id}/ — outside the
-- document root, served only through the authenticated download endpoint.
--
-- inspection_id is denormalised (derivable via item_id) so the PDF generator
-- and the per-account storage accounting can filter without joining items.
--
-- No account-level quota column: at ~300 KB per photo the 300 GB hosting
-- plan absorbs years of growth, and the per-item cap below is the only limit
-- the spec asks for (a fat-finger guard, not a storage policy).

CREATE TABLE inspection_item_photos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED      NOT NULL,
    inspection_id INT UNSIGNED      NOT NULL,
    item_id       INT UNSIGNED      NOT NULL,
    position      SMALLINT UNSIGNED NOT NULL,
    file_path     VARCHAR(255)      NOT NULL,
    thumb_path    VARCHAR(255)      NOT NULL,
    byte_size     INT UNSIGNED      NOT NULL DEFAULT 0,
    width         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    height        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item_photos_item       (item_id, position),
    INDEX idx_item_photos_inspection (inspection_id),
    INDEX idx_item_photos_account    (account_id),
    CONSTRAINT fk_item_photos_account
        FOREIGN KEY (account_id)    REFERENCES accounts(id)         ON DELETE CASCADE,
    CONSTRAINT fk_item_photos_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id)      ON DELETE CASCADE,
    CONSTRAINT fk_item_photos_item
        FOREIGN KEY (item_id)       REFERENCES inspection_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
