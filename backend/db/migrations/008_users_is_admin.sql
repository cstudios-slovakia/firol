-- App-level admin flag on users. The agency + the client own the app and
-- are seeded via the ADMIN_EMAIL env variable; further admins are added
-- through the Settings → Admin UI by an existing admin. Admin::isAdmin
-- consults this column together with the env list.

ALTER TABLE users
    ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
