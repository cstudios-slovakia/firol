<?php

declare(strict_types=1);

namespace Firol\Support\Documentation;

use Firol\Support\Address;

/**
 * Maps a stored Dokumentácia PO record (company/facility row + the form
 * `data` blob + the author's profile) into the flat payload the Word
 * template expects (docxtemplater placeholders, see the template under
 * backend/templates/dokumentacia_po.docx and spec §5).
 *
 * Defaults from §5.1/§5.2 are applied here so a generation still produces
 * a sensible document even when an optional field was left blank, and so
 * the booleans the template branches on (ma_evakuacny_plan,
 * ma_zvysene_nebezpecenstvo) are derived consistently.
 */
final class DocumentationPayload
{
    /** Fixed documents that are always part of the bundle (before/after the
     *  conditional evacuation-plan and high-risk sections). Order matches
     *  the physical order of sections in the Word template. */
    private const FIXED_BEFORE = [
        'Požiarna kniha',
        'Požiarna identifikačná karta',
        'Zoznam dôležitých telefónnych čísel',
        'Požiarne poplachové smernice',
    ];

    private const EVAC_ITEMS = [
        'Požiarny evakuačný plán — textová časť',
        'Požiarny evakuačný plán — grafická časť',
    ];

    private const FIXED_AFTER = [
        'Zriadenie ohlasovne požiarov',
        'Dokumentácia ohlasovne požiarov',
        'Pokyn na zabezpečenie ochrany pred požiarmi v mimopracovnom čase',
        'Tematický plán vstupného školenia vedúcich a ostatných zamestnancov',
        'Tematický plán opakovaného školenia vedúcich a ostatných zamestnancov',
        'Tematický plán školenia novoprijatých zamestnancov',
        'Tematický plán školenia osôb zdržujúcich sa v objekte',
    ];

    private const HIGH_RISK_ITEM = 'Určenie miest so zvýšeným nebezpečenstvom vzniku požiaru';

    /**
     * @param array<string, mixed> $doc    documentation row joined with company/facility
     * @param array<string, mixed> $data   the stored form payload
     * @param array<string, mixed> $author users row {fullname, phone}
     * @return array<string, mixed>
     */
    public static function build(array $doc, array $data, array $author): array
    {
        $get = static function (string $key, string $default = '') use ($data): string {
            $v = trim((string) ($data[$key] ?? ''));
            return $v !== '' ? $v : $default;
        };

        // Predvyplnené (§5.1) — the stored snapshot wins (it may have been
        // edited), otherwise fall back to the live company/facility values.
        $firmaNazov = $get('firma_nazov', (string) $doc['company_name']);
        $firmaIco   = $get('firma_ico', (string) ($doc['company_ico'] ?? ''));
        $firmaSidlo = $get('firma_sidlo', (string) (Address::format(
            $doc['company_street'] ?? null,
            $doc['company_postal_code'] ?? null,
            $doc['company_city'] ?? null,
        ) ?? ''));

        $facilityAddress = (string) (Address::format(
            $doc['facility_street'] ?? null,
            $doc['facility_postal_code'] ?? null,
            $doc['facility_city'] ?? null,
        ) ?? '');
        $prevadzkaAdresa = $get('prevadzka_adresa', $facilityAddress !== '' ? $facilityAddress : $firmaSidlo);
        $prevadzkaNazov  = $get('prevadzka_nazov', (string) ($doc['facility_name'] ?? '') ?: $firmaNazov);

        $mesto = $get('mesto', (string) ($doc['facility_city'] ?? '') ?: (string) ($doc['company_city'] ?? ''));
        $datum = self::formatDate((string) $doc['issued_on']);

        // Technik PO — from the author's profile (§5.1).
        [$technikMeno, $technikPriezvisko] = self::splitName((string) ($author['fullname'] ?? ''));
        $technikTel = (string) ($author['phone'] ?? '');

        // Konateľ a kontakty (§5.2).
        $konatelMeno       = $get('konatel_meno');
        $konatelPriezvisko = $get('konatel_priezvisko');
        $konatelFunkcia    = $get('konatel_funkcia', 'konateľ spoločnosti');
        $konatelTel        = $get('konatel_tel');
        $konatelFull       = trim($konatelMeno . ' ' . $konatelPriezvisko);

        $ohlasovnaTel = $get('ohlasovna_tel', $konatelTel);
        $vodarneTel   = $get('vodarne_tel');
        $mimopracovnySposob = $get('mimopracovny_sposob');
        $zodpovednaOsoba = $get(
            'zodpovedna_osoba',
            $konatelFull . ($konatelFunkcia !== '' ? ', ' . $konatelFunkcia : ''),
        );
        $knihaObjekty      = $get('kniha_objekty', $prevadzkaAdresa);
        $knihaUlozenaOsoba = $get('kniha_ulozena_osoba', $konatelFull);

        // Opakovacie zoznamy (§5.3).
        $osobyZapisy = self::mapList($data['osoby_zapisy'] ?? null, static function (array $r, int $i): array {
            return [
                'poradie' => $i + 1,
                'meno'    => trim((string) ($r['meno'] ?? '')),
                'funkcia' => trim((string) ($r['funkcia'] ?? '')),
            ];
        });
        $dalsieKontakty = self::mapList($data['dalsie_kontakty'] ?? null, static function (array $r): array {
            return [
                'meno'    => trim((string) ($r['meno'] ?? '')),
                'funkcia' => trim((string) ($r['funkcia'] ?? '')),
                'telefon' => trim((string) ($r['telefon'] ?? '')),
            ];
        });
        $objekty = self::mapList($data['objekty'] ?? null, static function (array $r): array {
            return [
                'nazov_objektu'        => trim((string) ($r['nazov_objektu'] ?? '')),
                'vztah'                => trim((string) ($r['vztah'] ?? '')),
                'ma_zvysene'           => self::yesNo($r['ma_zvysene'] ?? false),
                'jednoducha_evakuacia' => self::yesNo($r['jednoducha_evakuacia'] ?? false),
            ];
        });
        $miesta = self::mapList($data['miesta'] ?? null, static function (array $r, int $i): array {
            return [
                'poradie'        => $i + 1,
                'subjekt_miesta' => trim((string) ($r['subjekt_miesta'] ?? '')),
                'objekt_miesta'  => trim((string) ($r['objekt_miesta'] ?? '')),
                'nazov_miesta'   => trim((string) ($r['nazov_miesta'] ?? '')),
            ];
        });

        // Prepínače (§5.4). High-risk auto-enables when at least one place
        // is listed (§4.3).
        $maEvak    = !empty($data['ma_evakuacny_plan']);
        $maZvysene = count($miesta) > 0;

        $custom = [];
        if (is_array($data['custom_zoznam'] ?? null)) {
            foreach ($data['custom_zoznam'] as $c) {
                $name = is_array($c) ? trim((string) ($c['nazov'] ?? '')) : trim((string) $c);
                if ($name !== '') {
                    $custom[] = $name;
                }
            }
        }

        return [
            'firma_nazov'      => $firmaNazov,
            'firma_ico'        => $firmaIco,
            'firma_sidlo'      => $firmaSidlo,
            'prevadzka_adresa' => $prevadzkaAdresa,
            'prevadzka_nazov'  => $prevadzkaNazov,
            'mesto'            => $mesto,
            'datum'            => $datum,
            'technik_meno'       => $technikMeno,
            'technik_priezvisko' => $technikPriezvisko,
            'technik_tel'        => $technikTel,
            'konatel_meno'       => $konatelMeno,
            'konatel_priezvisko' => $konatelPriezvisko,
            'konatel_funkcia'    => $konatelFunkcia,
            'konatel_tel'        => $konatelTel,
            'ohlasovna_tel'        => $ohlasovnaTel,
            'vodarne_tel'          => $vodarneTel,
            'mimopracovny_sposob'  => $mimopracovnySposob,
            'zodpovedna_osoba'     => $zodpovednaOsoba,
            'kniha_objekty'        => $knihaObjekty,
            'kniha_ulozena_osoba'  => $knihaUlozenaOsoba,
            'ma_evakuacny_plan'         => $maEvak,
            'ma_zvysene_nebezpecenstvo' => $maZvysene,
            'zoznam'          => self::buildZoznam($maEvak, $maZvysene, $custom),
            'osoby_zapisy'    => $osobyZapisy,
            'dalsie_kontakty' => $dalsieKontakty,
            'objekty'         => $objekty,
            'miesta'          => $miesta,
        ];
    }

    /**
     * The numbered list printed on the title page. The app assembles it
     * (§4.1): fixed documents + evacuation plan (text + graphic, when on)
     * + high-risk places (when present) + the technician's free-text
     * extras. Numbering is always continuous.
     *
     * @param list<string> $custom
     * @return list<array{cislo: int, nazov: string}>
     */
    private static function buildZoznam(bool $evac, bool $highRisk, array $custom): array
    {
        $names = self::FIXED_BEFORE;
        if ($evac) {
            $names = array_merge($names, self::EVAC_ITEMS);
        }
        $names = array_merge($names, self::FIXED_AFTER);
        if ($highRisk) {
            $names[] = self::HIGH_RISK_ITEM;
        }
        $names = array_merge($names, $custom);

        $out = [];
        foreach ($names as $i => $name) {
            $out[] = ['cislo' => $i + 1, 'nazov' => $name];
        }
        return $out;
    }

    /**
     * @param mixed $list
     * @param callable(array<string,mixed>, int): array<string,mixed> $fn
     * @return list<array<string,mixed>>
     */
    private static function mapList(mixed $list, callable $fn): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        $i = 0;
        foreach ($list as $row) {
            if (is_array($row)) {
                $out[] = $fn($row, $i);
                $i++;
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string} [meno, priezvisko] */
    private static function splitName(string $fullname): array
    {
        $fullname = trim($fullname);
        if ($fullname === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/u', $fullname, 2) ?: [$fullname];
        return [$parts[0], $parts[1] ?? ''];
    }

    private static function yesNo(mixed $value): string
    {
        if (is_string($value)) {
            $v = strtolower(trim($value));
            $truthy = in_array($v, ['áno', 'ano', 'yes', 'true', '1'], true);
            return $truthy ? 'áno' : 'nie';
        }
        return $value ? 'áno' : 'nie';
    }

    private static function formatDate(string $iso): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        // Slovak short style, e.g. "15. 6. 2026".
        return date('j. n. Y', $ts);
    }
}
