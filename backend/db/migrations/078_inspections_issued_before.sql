-- Fix list of 16. 11. 2026, round 3: remember for good that an úkon was issued.
--
-- A draft that was never issued may be discarded for good (owner decision of
-- 17. 11. 2026); one that was issued before (opened again with „Upraviť", or a
-- test printed blank and reopened with „Doplniť výsledky") may not. „Upraviť"
-- deletes the documents rows, the audit log is pruned after 365 days, and
-- `details.issued_contractor` exists only since 23. 9. 2026 — so the fact gets
-- a column of its own, set when a protocol is issued and when it is reopened.

ALTER TABLE inspections
    ADD COLUMN IF NOT EXISTS issued_before TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

UPDATE inspections i
SET    i.issued_before = 1
WHERE  i.status = 'finalized'
   OR  JSON_EXISTS(i.details, '$.issued_contractor')
   OR  EXISTS (SELECT 1 FROM documents d
               WHERE  d.account_id = i.account_id
                 AND  d.parent_type = 'inspection'
                 AND  d.parent_id = i.id)
   OR  EXISTS (SELECT 1 FROM audit_log a
               WHERE  a.target_type = 'inspections'
                 AND  a.target_id = i.id
                 AND  a.action IN ('inspection.unlock', 'inspection.fill_results'));
