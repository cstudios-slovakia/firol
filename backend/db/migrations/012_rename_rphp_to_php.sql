-- Rename RPHP → PHP throughout: inspection type slugs, document type slugs,
-- document number prefixes, inspector_profiles columns, and PK activity slugs
-- stored in inspection_items.fields JSON.

-- Rename inspector_profiles columns
ALTER TABLE inspector_profiles
    RENAME COLUMN cert_rphp       TO cert_php,
    RENAME COLUMN valid_from_rphp TO valid_from_php,
    RENAME COLUMN valid_to_rphp   TO valid_to_php;

-- Migrate inspection type slugs
UPDATE inspections SET type = 'php'           WHERE type = 'rphp';
UPDATE inspections SET type = 'oprava_ts_php' WHERE type = 'oprava_ts_rphp';

-- Migrate document type slugs
UPDATE documents SET type = 'php'           WHERE type = 'rphp';
UPDATE documents SET type = 'oprava_ts_php' WHERE type = 'oprava_ts_rphp';

-- Migrate document_sequences type slugs
UPDATE document_sequences SET type = 'php'           WHERE type = 'rphp';
UPDATE document_sequences SET type = 'oprava_ts_php' WHERE type = 'oprava_ts_rphp';

-- Migrate rphp_check → php_check inside inspection_items.fields JSON arrays
UPDATE inspection_items
SET    fields = REPLACE(fields, '"rphp_check"', '"php_check"')
WHERE  fields LIKE '%"rphp_check"%';
