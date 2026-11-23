<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Content of a preventívna protipožiarna prehliadka for a client of type
 * `firma`: the nine points of § 14 ods. 2 vyhl. MV SR č. 121/2002 Z. z.,
 * verbatim and in the genitive — they read after „Obsahom prehliadky je
 * kontrola:". Used by the prehliadka's PO activity list and by a požiarna
 * kniha entry written as a prehliadka (POapp spec 7. 11. 2026, ch. 6.1).
 *
 * The letters a)–i) of the regulation are not part of the texts: on screen
 * and in documents these are plain bullets.
 */
final class FirePreventionPoints
{
    /** @var list<string> */
    public const FIRMA = [
        'organizačného zabezpečenia ochrany pred požiarmi na pracoviskách',
        'porovnania skutočného stavu s dokumentáciou ochrany pred požiarmi',
        'stavebného riešenia objektov, najmä z prevádzkového hľadiska',
        'zariadení pre protipožiarny zásah',
        'trvalej voľnosti únikových ciest',
        'výroby a skladovania horľavých látok a manipulácie s nimi',
        'funkčnosti požiarnych zariadení',
        'prevádzkovania a stavu technických zariadení a technologických zariadení',
        'označenia a vybavenia pracovísk a priestorov príslušnými príkazmi, zákazmi a pokynmi',
    ];
}
