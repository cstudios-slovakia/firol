<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Client type lives on the company (owner decision D1). A prevádzka under a
 * bytové družstvo is a bytový dom; nothing else in the logic differs except
 * the floor fields on that form. The Slovak nouns live here so a server
 * message names the same thing the screen does (frontend/src/lib/clientKind.ts).
 */
final class ClientKind
{
    public const FIRMA = 'firma';

    public const BYTOVE_DRUZSTVO = 'bytove_druzstvo';

    public static function isValid(string $type): bool
    {
        return $type === self::FIRMA || $type === self::BYTOVE_DRUZSTVO;
    }

    public static function isBytovyDom(string $type): bool
    {
        return $type === self::BYTOVE_DRUZSTVO;
    }

    /** What a missing name is called in a 422. */
    public static function nameRequired(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Zadaj názov bytového domu.'
            : 'Zadaj názov prevádzky.';
    }
}
