-- Per-nedostatok photo documentation for Požiarna kniha.
--
-- Every other inspection type keeps attaching photos to the whole item
-- (defect_key stays NULL for them, so their behaviour is unchanged). Požiarna
-- kniha nedostatky are a JSON list inside inspection_items.fields with no
-- row/id of their own, so each defect gets a stable client-generated `key`
-- string (persisted alongside its description/deadline) and photos carry the
-- same key here to say which nedostatok they document.

ALTER TABLE inspection_item_photos
    ADD COLUMN defect_key VARCHAR(40) NULL AFTER item_id,
    ADD INDEX idx_item_photos_defect (item_id, defect_key);
