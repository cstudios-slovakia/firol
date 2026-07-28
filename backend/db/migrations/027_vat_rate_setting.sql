-- Admin settings: VAT rate applied to the informative monthly/yearly prices
-- shown in the admin panel (does not affect actual Stripe billing amounts).

INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('vat_rate_percent', '20')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
