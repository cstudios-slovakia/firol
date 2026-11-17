<?php

declare(strict_types=1);

namespace Firol\Idoklad;

/**
 * iDoklad did not give a definite answer (no response, timeout, HTTP 5xx).
 * After a POST of an invoice this means the invoice may or may not exist in
 * iDoklad, so it must not be sent again blindly.
 */
final class IDokladTransportException extends \RuntimeException
{
}
