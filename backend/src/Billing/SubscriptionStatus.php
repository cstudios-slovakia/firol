<?php

declare(strict_types=1);

namespace Firol\Billing;

/** Groups of Stripe subscription statuses the billing code branches on. */
final class SubscriptionStatus
{
    /** A subscription that still bills (or tries to bill) the customer. */
    public const LIVE = ['active', 'trialing', 'past_due', 'unpaid', 'paused'];

    /** A subscription that is over for good. */
    public const TERMINAL = ['canceled', 'incomplete_expired'];

    public static function isLive(?string $status): bool
    {
        return in_array((string) $status, self::LIVE, true);
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array((string) $status, self::TERMINAL, true);
    }
}
