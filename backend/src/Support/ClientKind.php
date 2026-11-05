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

    /** What a missing name is called in a 422 — the name of a prevádzka / bytový dom. */
    public static function nameRequired(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Zadaj názov bytového domu.'
            : 'Zadaj názov prevádzky.';
    }

    /** A missing name of the client itself (firma / bytové družstvo). */
    public static function companyNameRequired(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Zadaj názov bytového družstva.'
            : 'Zadaj názov firmy.';
    }

    public static function companyAlreadyArchived(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Bytové družstvo je už archivované.'
            : 'Firma je už archivovaná.';
    }

    public static function companyNotArchived(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Bytové družstvo nie je archivované.'
            : 'Firma nie je archivovaná.';
    }

    public static function facilityAlreadyArchived(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Bytový dom je už archivovaný.'
            : 'Prevádzka je už archivovaná.';
    }

    public static function facilityNotArchived(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Bytový dom nie je archivovaný.'
            : 'Prevádzka nie je archivovaná.';
    }

    /** The client of a facility is archived, so the facility cannot be archived on its own. */
    public static function clientOfFacilityArchived(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Bytové družstvo tohto bytového domu je archivované.'
            : 'Firma tejto prevádzky je archivovaná.';
    }

    public static function restoreClientOfFacilityFirst(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Najprv obnov bytové družstvo tohto bytového domu.'
            : 'Najprv obnov firmu tejto prevádzky.';
    }

    /** @param string $tail what stops, e.g. „činnosti sa už nemenia." */
    public static function clientArchivedReadOnly(string $type, string $tail): string
    {
        return (self::isBytovyDom($type) ? 'Bytové družstvo je archivované — ' : 'Firma je archivovaná — ') . $tail;
    }

    public static function bookArchived(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Bytové družstvo je archivované — do jeho knihy sa už nezapisuje.'
            : 'Firma je archivovaná — do jej knihy sa už nezapisuje.';
    }

    public static function bookNeedsFacility(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Kniha je vedená po bytových domoch — najprv pridaj bytový dom.'
            : 'Kniha je vedená po prevádzkach — najprv pridaj prevádzku.';
    }

    /** E-mail of a prevádzka / bytový dom that is not valid. */
    public static function facilityEmailInvalid(string $type): string
    {
        return self::isBytovyDom($type)
            ? 'Zadaj platný e-mail bytového domu.'
            : 'Zadaj platný e-mail prevádzky.';
    }
}
