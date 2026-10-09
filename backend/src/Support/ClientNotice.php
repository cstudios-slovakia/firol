<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Text of the client notice e-mail — chapter 11.3.
 *
 * The PHP twin of `frontend/src/lib/clientNoticeEmail.ts`: the manual button
 * „Oznámiť klientovi e-mailom" builds the message in the browser, the
 * automatic notice builds it here, and the two must read the same. One
 * message covers every termín due at one prevádzka on one day, so a client
 * never gets several mails at once. Keep both files in step.
 */
final class ClientNotice
{
    /**
     * The controls named in „dňa … u vás vykonáme: …", in the accusative the
     * sentence needs. Požiarna kniha is announced as a preventívna
     * protipožiarna prehliadka; every other type by the name of its control.
     */
    public const TYPE_ACCUSATIVE = [
        'php'                  => 'kontrolu hasiacich prístrojov',
        'hydranty'             => 'kontrolu požiarnych hydrantov',
        'oprava_ts_php'        => 'opravu, plnenie a tlakovú skúšku hasiacich prístrojov',
        'poziarna_kniha'       => 'preventívnu protipožiarnu prehliadku',
        'pu_akcieschopnost'    => 'kontrolu akcieschopnosti požiarnych uzáverov',
        'pu_udrzba'            => 'údržbu požiarnych uzáverov',
        'nudzove_osvetlenie'   => 'kontrolu núdzového osvetlenia',
        'ts_hadic'             => 'tlakovú skúšku hadíc',
        'vyradenie'            => 'vyradenie hasiacich prístrojov',
        'kniha_bozp'           => 'kontrolu stavu bezpečnosti a ochrany zdravia pri práci',
        'pracovisko'           => 'kontrolu pracoviska a pracovného prostredia',
        'osamele_pracovisko'   => 'kontrolu osamelých a odlúčených pracovísk',
        'fajcenie'             => 'kontrolu dodržiavania zákazu fajčenia',
        'oopp'                 => 'kontrolu osobných ochranných pracovných prostriedkov',
        'pracovne_prostriedky' => 'kontrolu pracovných prostriedkov',
        'rebriky'              => 'kontrolu rebríkov',
        'regale'               => 'kontrolu regálov',
        'oznacenie'            => 'kontrolu bezpečnostného a zdravotného označenia',
        'dychova_skuska'       => 'dychovú skúšku na alkohol',
        'omamne_latky'         => 'kontrolu na zistenie požitia omamných a psychotropných látok',
        'skolenie_bozp'        => 'oboznámenie zamestnancov v oblasti BOZP',
        // Training subtypes (change request 6) — a training term is announced
        // by its own name, so the key is the training slug, not `skolenie_po`.
        'vstupne'              => 'vstupné školenie vedúcich a ostatných zamestnancov',
        'opakovane'            => 'opakované školenie vedúcich a ostatných zamestnancov',
        'zdrzujuca_sa'         => 'školenie osôb zdržujúcich sa na pracovisku',
        'opp_mimo'             => 'školenie osôb zabezpečujúcich OPP v mimopracovnom čase',
        'hliadka_oph'          => 'odbornú prípravu protipožiarnej hliadky pracoviska',
        'hliadka_opah'         => 'odbornú prípravu protipožiarnej asistenčnej hliadky',
        'pokyn_zatva'          => 'vydanie pokynu na zabezpečenie ochrany pred požiarmi pri žatevných prácach',
    ];

    /** "2026-08-15" → "15. 8. 2026". */
    public static function formatDate(string $isoDate): string
    {
        [$y, $m, $d] = explode('-', $isoDate);
        return (int) $d . '. ' . (int) $m . '. ' . $y;
    }

    /**
     * @param list<string> $types Inspection types (or training subtypes) due at the prevádzka that day.
     * @return array{subject: string, body: string}
     */
    public static function build(string $isoDate, array $types, string $senderName, ?string $senderPhone): array
    {
        $date = self::formatDate($isoDate);
        $controls = [];
        foreach ($types as $type) {
            $label = self::TYPE_ACCUSATIVE[$type] ?? $type;
            if (!in_array($label, $controls, true)) {
                $controls[] = $label;
            }
        }
        $signature = implode(', ', array_values(array_filter(
            [trim($senderName), $senderPhone !== null ? trim($senderPhone) : ''],
            static fn (string $s): bool => $s !== '',
        )));

        return [
            'subject' => 'Oznámenie termínu kontroly — ' . $date,
            'body'    => implode("\r\n", [
                'Dobrý deň,',
                '',
                'dňa ' . $date . ' u vás vykonáme: ' . self::joinSk($controls) . '.',
                '',
                'V prípade potreby zmeny termínu nás prosím kontaktujte.',
                '',
                'S pozdravom,',
                $signature,
            ]),
        ];
    }

    /** "a, b, c" → "a, b a c". @param list<string> $parts */
    private static function joinSk(array $parts): string
    {
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }
        return implode(', ', array_slice($parts, 0, -1)) . ' a ' . $parts[count($parts) - 1];
    }
}
