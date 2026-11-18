<?php

declare(strict_types=1);

namespace Firol\Documents;

/**
 * A book entry cannot take a number: its firma has no code and none can be
 * proposed from the name ({@see BookNumber}). The message is Slovak and goes
 * to the technician as is.
 */
final class BookNumberRefused extends \RuntimeException
{
}
