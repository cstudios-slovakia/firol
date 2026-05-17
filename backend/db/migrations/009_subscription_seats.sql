-- Phase 6e — per-seat (technician) pricing.
--
-- Subscription model: every account's base plan now includes a
-- configurable number of technician seats (including the account's
-- main user). Any technicians beyond that count are charged as a
-- second recurring line item on the same Stripe subscription
-- (priced from `system_settings.price_per_extra_technician_cents`).
-- Above `max_self_service_technicians`, the UI hides self-service
-- and surfaces a contact-us message instead.
--
-- Columns on `accounts`:
--   - included_technicians:                  per-account override of the
--                                            global default. Admins can
--                                            bump this for negotiated
--                                            contracts.
--   - extra_technicians:                     current quantity of the
--                                            extra-seat Stripe item.
--                                            Mirrors Stripe so the UI
--                                            can render without an API
--                                            round-trip. During the
--                                            local trial (no Stripe sub
--                                            yet) it tracks the count
--                                            we'll pass into checkout.
--   - stripe_extra_subscription_item_id:     Subscription Item ID of the
--                                            extra-seat line. NULL when
--                                            quantity is 0 (we delete
--                                            the item rather than keep
--                                            a zero-quantity row).

ALTER TABLE accounts
    ADD COLUMN included_technicians              INT UNSIGNED NOT NULL DEFAULT 3
        AFTER stripe_cancel_at_period_end,
    ADD COLUMN extra_technicians                 INT UNSIGNED NOT NULL DEFAULT 0
        AFTER included_technicians,
    ADD COLUMN stripe_extra_subscription_item_id VARCHAR(64) NULL
        AFTER extra_technicians;

INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('default_included_technicians',       '3'),
    ('price_per_extra_technician_cents',   '500'),
    ('max_self_service_technicians',       '20')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
