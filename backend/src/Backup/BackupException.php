<?php

declare(strict_types=1);

namespace Firol\Backup;

/**
 * A backup file the user can do something about: wrong file, wrong format,
 * a newer archive version than this build understands. The message is written
 * for the technician and is surfaced verbatim as a 422 — unlike an unexpected
 * failure mid-restore, which stays a logged 500.
 */
final class BackupException extends \RuntimeException
{
}
