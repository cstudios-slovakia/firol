<?php

declare(strict_types=1);

namespace Firol\Backup;

/**
 * Shape of the account backup archive, shared by {@see Writer} and
 * {@see Restorer} so the two can never drift.
 *
 * The archive is a plain .zip:
 *
 *   backup.json                     — the manifest (every DB row, see below)
 *   photos/{photoId}.jpg            — full-size field photo
 *   photos/{photoId}_t.jpg          — its list thumbnail
 *   documents/{documentId}.pdf      — generated protocol
 *   signatures/trainee-{id}.png     — captured attendee signature
 *
 * Entry names are keyed by the *original* row id rather than mirroring the
 * storage tree, because a restore mints new ids and new paths anyway — the
 * manifest is the only thing that maps an entry to the record it belongs to.
 *
 * Why a zip and not the old bare JSON: the JSON listed photos by download
 * URL, so a backup taken before the database was wiped pointed at files that
 * no longer existed. A backup that cannot restore what it describes is not a
 * backup.
 */
final class Archive
{
    public const FORMAT   = 'firol-backup';
    public const VERSION  = 2;
    public const MANIFEST = 'backup.json';

    /** Legacy bare-JSON exports (v1) can still be read back — with no files. */
    public const LEGACY_VERSION = 1;

    public static function photoEntry(int $photoId): string
    {
        return "photos/$photoId.jpg";
    }

    public static function photoThumbEntry(int $photoId): string
    {
        return "photos/{$photoId}_t.jpg";
    }

    public static function documentEntry(int $documentId): string
    {
        return "documents/$documentId.pdf";
    }

    public static function traineeSignatureEntry(int $traineeId): string
    {
        return "signatures/trainee-$traineeId.png";
    }
}
