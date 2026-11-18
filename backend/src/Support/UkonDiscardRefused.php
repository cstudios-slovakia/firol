<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * A permanent delete that {@see UkonDiscard::delete()} refused under its row
 * lock: the úkon was issued, reopened, followed up, archived or deleted
 * between the check and the lock. The message is the Slovak reason decided
 * under the lock and is shown as is (409). Deliberately not a
 * \RuntimeException, so a database error is never mistaken for a refusal.
 */
final class UkonDiscardRefused extends \Exception
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }
}
