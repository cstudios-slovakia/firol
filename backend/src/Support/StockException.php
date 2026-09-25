<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * A stock operation refused for a reason the technician should read — the
 * message is Slovak and shown as is. The code is the HTTP status to answer
 * with (422 by default, 404 for something that does not exist).
 */
final class StockException extends \RuntimeException
{
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        $code = $this->getCode();
        return $code >= 400 && $code < 600 ? $code : 422;
    }
}
