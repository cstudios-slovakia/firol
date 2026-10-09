-- Chapter 1.6.2 — new accounts start with `obmedzene` (owner decision
-- 7. 10. 2026, as the spec says): members cannot delete finished úkony,
-- protocols, sklad items or archive a firma / prevádzka until the main user
-- turns „Technici môžu mazať" on in Nastavenia → Technici.
--
-- Only the default changes. Accounts that already exist keep the value they
-- have (migration 051 gave them `plne`), so nobody loses a button overnight.

ALTER TABLE accounts
    ALTER COLUMN member_rights SET DEFAULT 'obmedzene';
