-- Chapter 1.6 / client report after the update, point 4 — vyradenie zo skladu.
--
--   stock_items.retired_at
--       A položka that already stands on a výdajka can't be deleted (the issued
--       document lists that material), so it is retired instead: it leaves the
--       Položky list and can no longer be bought, moved or used, while its
--       movements, the výdajky and the journal keep naming it. NULL = in the sklad.

ALTER TABLE stock_items
    ADD COLUMN retired_at DATETIME NULL DEFAULT NULL;
