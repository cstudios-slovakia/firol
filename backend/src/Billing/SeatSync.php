<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Db;
use Firol\Stripe\StripeClient;

/**
 * Keeps the Stripe subscription's extra-seat line item in sync with the
 * number of active technicians on the account.
 *
 * Model:
 *  - Base plan is paid via the env-configured Stripe Price (monthly/yearly)
 *    and includes `accounts.included_technicians` technician seats — the
 *    account admin (main user) counts as one of those.
 *  - Anything beyond is charged as a second recurring line item priced
 *    from `system_settings.price_per_extra_technician_cents`. We only
 *    create that line item when needed and remove it again when the
 *    overage drops to zero.
 *  - During the local trial (no Stripe subscription yet) we just update
 *    `accounts.extra_technicians` in the DB; the checkout flow reads the
 *    current value when it builds the line items.
 *
 * `recompute` is safe to call after any change to the technician roster
 * (invite / re-activate / remove / per-account included override). It
 * trusts the active-technician count from the DB and applies proration
 * via Stripe's default behaviour.
 */
final class SeatSync
{
    /**
     * Re-sync the extra-seat line item for one account.
     *
     * Non-fatal on Stripe errors — the DB row is the source of truth for
     * the UI; the webhook will reconcile if the API call fails.
     */
    public static function recompute(int $accountId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, included_technicians, extra_technicians,
                    stripe_subscription_id, stripe_extra_subscription_item_id,
                    billing_period, stripe_status
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            return;
        }

        $active   = self::countActiveTechnicians($accountId);
        $included = (int) $account['included_technicians'];
        $target   = max(0, $active - $included);

        // Persist the desired quantity eagerly so the UI reflects it even
        // before Stripe acks.
        $pdo->prepare('UPDATE accounts SET extra_technicians = ? WHERE id = ?')
            ->execute([$target, $accountId]);

        $subId = (string) ($account['stripe_subscription_id'] ?? '');
        if ($subId === '') {
            // No Stripe subscription yet (trial path) — checkout will pick
            // the current `extra_technicians` value up.
            return;
        }

        try {
            self::applyToStripe(
                $subId,
                (string) ($account['stripe_extra_subscription_item_id'] ?? ''),
                $target,
                (string) ($account['billing_period'] ?? 'monthly'),
                $accountId,
            );
        } catch (\Throwable $e) {
            error_log('[seat-sync] account=' . $accountId . ' err=' . $e->getMessage());
        }
    }

    /**
     * Counts active technicians on the account (the main user is always
     * active and is counted).
     */
    public static function countActiveTechnicians(int $accountId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM account_users
             WHERE  account_id = ? AND is_active = 1'
        );
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Current extra-technician monthly price in cents, from
     * `system_settings`. Yearly callers multiply by 12.
     */
    public static function pricePerExtraCents(): int
    {
        $stmt = Db::pdo()->prepare(
            "SELECT setting_value FROM system_settings
             WHERE  setting_key = 'price_per_extra_technician_cents' LIMIT 1"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : 1000;
    }

    public static function defaultIncludedTechnicians(): int
    {
        $stmt = Db::pdo()->prepare(
            "SELECT setting_value FROM system_settings
             WHERE  setting_key = 'default_included_technicians' LIMIT 1"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? max(1, (int) $val) : 2;
    }

    public static function maxSelfServiceTechnicians(): int
    {
        $stmt = Db::pdo()->prepare(
            "SELECT setting_value FROM system_settings
             WHERE  setting_key = 'max_self_service_technicians' LIMIT 1"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? max(1, (int) $val) : 20;
    }

    /**
     * Returns a `price_data` blob ready to plug into a Stripe line item
     * for the given billing period. Yearly = monthly × 12.
     *
     * @return array<string, mixed>
     */
    public static function extraPriceData(string $billingPeriod): array
    {
        $monthlyCents = self::pricePerExtraCents();
        $interval     = $billingPeriod === 'yearly' ? 'year' : 'month';
        $unitAmount   = $billingPeriod === 'yearly' ? $monthlyCents * 12 : $monthlyCents;

        return [
            'currency'    => 'eur',
            'unit_amount' => $unitAmount,
            'recurring'   => ['interval' => $interval],
            'product_data' => [
                'name' => 'Firol — extra technik',
            ],
        ];
    }

    /**
     * Create, update or delete the Stripe Subscription Item that carries
     * the extra-seat charge. Stripe handles proration automatically.
     */
    private static function applyToStripe(
        string $subId,
        string $existingItemId,
        int $targetQuantity,
        string $billingPeriod,
        int $accountId,
    ): void {
        $stripe = StripeClient::get();

        if ($targetQuantity <= 0) {
            if ($existingItemId !== '') {
                $stripe->subscriptionItems->delete($existingItemId, [
                    'proration_behavior' => 'create_prorations',
                ]);
                Db::pdo()->prepare(
                    'UPDATE accounts SET stripe_extra_subscription_item_id = NULL WHERE id = ?'
                )->execute([$accountId]);
            }
            return;
        }

        if ($existingItemId !== '') {
            $stripe->subscriptionItems->update($existingItemId, [
                'quantity'           => $targetQuantity,
                'proration_behavior' => 'create_prorations',
            ]);
            return;
        }

        $item = $stripe->subscriptionItems->create([
            'subscription'       => $subId,
            'price_data'         => self::extraPriceData($billingPeriod),
            'quantity'           => $targetQuantity,
            'proration_behavior' => 'create_prorations',
            'metadata'           => [
                'firol_account_id' => (string) $accountId,
                'firol_line_kind'  => 'extra_technician',
            ],
        ]);
        Db::pdo()->prepare(
            'UPDATE accounts SET stripe_extra_subscription_item_id = ? WHERE id = ?'
        )->execute([(string) $item->id, $accountId]);
    }
}
