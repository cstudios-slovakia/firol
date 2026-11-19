<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * A refusal of one field of an item, with the field's key — so the 422 can
 * carry `field` the way the fire-protection validators do, and „Skontroluj
 * hodnoty" can tell a field on its screen from one in the item's form
 * (round 6 of the fix list of 16. 11. 2026, rebríky and regály).
 */
final class FieldRefused extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
