<?php

declare(strict_types=1);

namespace Firol\Legal;

/**
 * The currently published legal documents (change request 3.1).
 *
 * Single source of truth for the version string stored with a user's consent
 * and compared against on every login. Publishing a revision means bumping
 * VERSION and EFFECTIVE_FROM here and replacing the files under
 * frontend/public/legal/ — every user whose recorded consent no longer matches
 * then gets the "new version" notice, exactly as the spec requires.
 *
 * The documents themselves are static pages in the frontend's public/ dir, so
 * they are served straight from the docroot and remain readable without a
 * session (the registration form links to them before an account exists).
 */
final class Terms
{
    /** Bump on every published revision of either document. */
    public const VERSION = '1.0';

    /** Date the current version takes effect (as printed in the documents). */
    public const EFFECTIVE_FROM = '2026-07-01';

    public const VOP_URL     = '/legal/vseobecne-obchodne-podmienky.html';
    public const PRIVACY_URL = '/legal/zasady-ochrany-osobnych-udajov.html';

    /**
     * Human-readable label used in the UI, e.g. "VOP v1.0, účinné 1. 7. 2026".
     */
    public static function label(): string
    {
        $ts = strtotime(self::EFFECTIVE_FROM);
        $date = $ts ? date('j. n. Y', $ts) : self::EFFECTIVE_FROM;
        return 'VOP v' . self::VERSION . ', účinné ' . $date;
    }

    /**
     * True when this user still has to acknowledge the current version —
     * either they never gave consent (registered before 3.1) or they consented
     * to an older revision.
     */
    public static function needsAcceptance(?string $acceptedVersion): bool
    {
        return $acceptedVersion !== self::VERSION;
    }

    /** @return array<string, mixed> The block exposed on /api/me. */
    public static function snapshot(?string $acceptedVersion, ?string $acceptedAt): array
    {
        return [
            'version'          => self::VERSION,
            'effective_from'   => self::EFFECTIVE_FROM,
            'label'            => self::label(),
            'vop_url'          => self::VOP_URL,
            'privacy_url'      => self::PRIVACY_URL,
            'accepted_version' => $acceptedVersion,
            'accepted_at'      => $acceptedAt,
            'needs_acceptance' => self::needsAcceptance($acceptedVersion),
        ];
    }
}
