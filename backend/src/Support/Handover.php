<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Wording of the client-side signature block — block 1 / chapter 13.1.
 *
 * The column is headed „Za organizáciu" on every document. Never „Za
 * spoločnosť": a third of the clients are schools, obce and združenia, and
 * calling a starosta's office a spoločnosť reads as a mistake on a document
 * that ends up in an inspection file.
 *
 * What the signatory is confirming DOES differ by document, so the line above
 * the signature changes with it. A požiarna kniha entry says „Schválil",
 * because under § 29 vyhl. 121/2002 the record is approved by the vedúci
 * zamestnanec — approval, not acknowledgement. Everything else is handed over:
 * the technician's findings are the technician's, and the client acknowledges
 * receiving them rather than agreeing with them.
 */
final class Handover
{
    /** Column heading above the client's signature. Same on every document. */
    public const COLUMN_TITLE = 'Za organizáciu';

    /**
     * Line printed above the signature line, by document type. `$type` is an
     * inspection/training type slug or one of the standalone document kinds
     * (odovzdavaci, vydajka, rocny_plan, potvrdenie_prace).
     */
    public static function actionFor(string $type): string
    {
        return match ($type) {
            'poziarna_kniha'    => 'Schválil',
            'odovzdavaci',
            'vydajka'           => 'Prevzal',
            'rocny_plan'        => 'Odsúhlasil',
            'potvrdenie_prace'  => 'Potvrdil vykonanie práce',
            default             => 'Prevzal na vedomie',
        };
    }

    /**
     * Full heading for the signature column, e.g.
     * „Za organizáciu — prevzal na vedomie". The action is lower-cased after
     * the dash so the two halves read as one sentence.
     */
    public static function headingFor(string $type): string
    {
        $action = self::actionFor($type);
        return self::COLUMN_TITLE . ' — ' . mb_strtolower(mb_substr($action, 0, 1)) . mb_substr($action, 1);
    }

    /**
     * Roles offered when picking who signs. The technician may always type
     * their own — this is a shortcut, not a closed list.
     *
     * @var list<string>
     */
    public const ROLE_SUGGESTIONS = [
        'konateľ',
        'konateľka',
        'štatutárny zástupca',
        'riaditeľ',
        'riaditeľka',
        'vedúci zamestnanec',
        'vedúca zamestnankyňa',
        'starosta obce',
        'starostka obce',
        'správca objektu',
        'poverená osoba',
        'predseda združenia',
    ];
}
