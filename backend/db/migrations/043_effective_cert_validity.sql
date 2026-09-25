-- The personal certificate printed on a protocol is a snapshot.
--
-- Migration 017 already stores the number (`effective_cert_number`) and the
-- technician it belonged to. The validity dates were still read live from
-- inspector_profiles whenever a protocol was re-rendered (a signature on
-- screen produces a new version of the same number), so a date edited in
-- the profile rewrote a document that had already been issued.
--
-- These two columns are the dates that were printed at issue time. Null
-- means the profile had no date then — a later date in the profile must
-- not appear on the re-render either.

ALTER TABLE inspections
    ADD COLUMN effective_cert_valid_from DATE NULL AFTER effective_cert_number,
    ADD COLUMN effective_cert_valid_to   DATE NULL AFTER effective_cert_valid_from;
