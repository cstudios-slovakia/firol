-- POapp 7. 11. 2026 (zadanie požiarna kniha) — the book ledger is withdrawn.
--
-- The požiarna kniha is an ordinary úkon (`poziarna_kniha`) again. The ledger
-- tables of migrations 062, 063 and 074 and the firm's book scope of 061 go
-- away; their rows (test server only) are discarded, nothing is carried over.
-- book_entry_additions first: it references book_entries.
--
-- IF EXISTS makes a re-run harmless.

DROP TABLE IF EXISTS book_entry_additions;

DROP TABLE IF EXISTS book_entries;

ALTER TABLE companies DROP COLUMN IF EXISTS book_scope;
