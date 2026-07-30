<?php

declare(strict_types=1);

namespace Firol\Legal;

/**
 * The currently published legal documents (change request 3.1).
 *
 * Single source of truth for the version strings stored with a user's
 * consent and compared against on every login. The VOP and the privacy
 * policy are versioned independently — they are separate legal instruments
 * and can be revised on independent schedules (e.g. a new subprocessor only
 * touches the privacy policy). Publishing a revision means bumping the
 * relevant *_VERSION/*_EFFECTIVE_FROM pair here and replacing the matching
 * file under frontend/public/legal/ — every user whose recorded consent for
 * that document no longer matches then gets the "new version" notice.
 *
 * The documents themselves are static pages in the frontend's public/ dir, so
 * they are served straight from the docroot and remain readable without a
 * session (the registration form links to them before an account exists).
 */
final class Terms
{
    /** Bump on every published revision of the VOP. */
    public const VOP_VERSION = '1.0';
    public const VOP_EFFECTIVE_FROM = '2026-07-01';
    public const VOP_URL = '/legal/vseobecne-obchodne-podmienky.html';

    /** Bump on every published revision of the privacy policy. */
    public const PRIVACY_VERSION = '1.0';
    public const PRIVACY_EFFECTIVE_FROM = '2026-07-01';
    public const PRIVACY_URL = '/legal/zasady-ochrany-osobnych-udajov.html';

    /** Human-readable label, e.g. "Všeobecné obchodné podmienky v1.0, účinné 1. 7. 2026". */
    public static function vopLabel(): string
    {
        return self::label('Všeobecné obchodné podmienky', self::VOP_VERSION, self::VOP_EFFECTIVE_FROM);
    }

    /** Human-readable label, e.g. "Zásady ochrany osobných údajov v1.0, účinné 1. 7. 2026". */
    public static function privacyLabel(): string
    {
        return self::label('Zásady ochrany osobných údajov', self::PRIVACY_VERSION, self::PRIVACY_EFFECTIVE_FROM);
    }

    private static function label(string $name, string $version, string $effectiveFrom): string
    {
        $ts = strtotime($effectiveFrom);
        $date = $ts ? date('j. n. Y', $ts) : $effectiveFrom;
        return $name . ' v' . $version . ', účinné ' . $date;
    }

    /**
     * True when this user still has to acknowledge the current version of
     * either document — either they never gave consent (registered before
     * 3.1) or they consented to an older revision of at least one of them.
     */
    public static function needsAcceptance(?string $acceptedVop, ?string $acceptedPrivacy): bool
    {
        return $acceptedVop !== self::VOP_VERSION || $acceptedPrivacy !== self::PRIVACY_VERSION;
    }

    /** @return array<string, mixed> The block exposed on /api/me. */
    public static function snapshot(
        ?string $acceptedVopVersion,
        ?string $acceptedVopAt,
        ?string $acceptedPrivacyVersion,
        ?string $acceptedPrivacyAt,
    ): array {
        return [
            'needs_acceptance' => self::needsAcceptance($acceptedVopVersion, $acceptedPrivacyVersion),
            'vop' => [
                'version'          => self::VOP_VERSION,
                'effective_from'   => self::VOP_EFFECTIVE_FROM,
                'label'            => self::vopLabel(),
                'url'              => self::VOP_URL,
                'accepted_version' => $acceptedVopVersion,
                'accepted_at'      => $acceptedVopAt,
                'needs_acceptance' => $acceptedVopVersion !== self::VOP_VERSION,
            ],
            'privacy' => [
                'version'          => self::PRIVACY_VERSION,
                'effective_from'   => self::PRIVACY_EFFECTIVE_FROM,
                'label'            => self::privacyLabel(),
                'url'              => self::PRIVACY_URL,
                'accepted_version' => $acceptedPrivacyVersion,
                'accepted_at'      => $acceptedPrivacyAt,
                'needs_acceptance' => $acceptedPrivacyVersion !== self::PRIVACY_VERSION,
            ],
        ];
    }
}
