<?php

declare(strict_types=1);

namespace Firol\Idoklad;

/**
 * Decides whether an invoice is really sent to iDoklad or only prepared.
 *
 * iDoklad has no sandbox and the credentials in a developer's .env may be the
 * real accounting account, so sending is opt-in everywhere except production:
 *
 *  - IDOKLAD_ISSUE_MODE = send | prepare. Default: `send` when
 *    APP_ENV=production, `prepare` anywhere else. In `prepare` mode the
 *    payload is built from our own data, stored on the invoices row
 *    (idoklad_payload_json) and nothing is sent — not even the template or
 *    contact lookups.
 *  - A Stripe invoice from test mode (livemode = false, or unknown) is never
 *    sent unless IDOKLAD_ALLOW_TEST_EVENTS=true as well.
 *
 * IDOKLAD_DRAFT_MODE is no longer read: it only labelled the local row
 * „draft" while a normal, numbered invoice was posted to iDoklad.
 *
 * A live Stripe key deliberately does NOT imply `send` (a developer machine
 * with a live key must still not issue). Instead the mistake is loud:
 * ConfigCheck reports an error for a live key without `send`, and a
 * `prepared` row of a live Stripe invoice is an error state that the admin
 * check shows and the daily run alerts about. Production sets
 * IDOKLAD_ISSUE_MODE=send explicitly (README deploy checklist).
 */
final class IssuePolicy
{
    /**
     * @return array{send: bool, reason: string}
     */
    public static function decide(?bool $livemode): array
    {
        $mode = self::mode();
        if ($mode !== 'send') {
            return ['send' => false, 'reason' => 'IDOKLAD_ISSUE_MODE=prepare (APP_ENV=' . self::appEnv() . ') — faktúra sa len pripravila, neodoslala'];
        }
        if ($livemode !== true && !self::allowTestEvents()) {
            return ['send' => false, 'reason' => 'testovacia platba zo Stripe — do iDokladu sa neposiela (IDOKLAD_ALLOW_TEST_EVENTS je vypnuté)'];
        }
        return ['send' => true, 'reason' => ''];
    }

    /** 'send' or 'prepare'. */
    public static function mode(): string
    {
        $raw = strtolower(trim((string) ($_ENV['IDOKLAD_ISSUE_MODE'] ?? '')));
        if ($raw === 'send' || $raw === 'prepare') {
            return $raw;
        }
        return self::appEnv() === 'production' ? 'send' : 'prepare';
    }

    public static function allowTestEvents(): bool
    {
        $v = strtolower(trim((string) ($_ENV['IDOKLAD_ALLOW_TEST_EVENTS'] ?? '')));
        return in_array($v, ['1', 'true', 'yes'], true);
    }

    private static function appEnv(): string
    {
        return strtolower(trim((string) ($_ENV['APP_ENV'] ?? '')));
    }
}
