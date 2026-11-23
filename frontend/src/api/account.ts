import { api, buildUrl } from '@/lib/api';
import type { PaymentFailed, SubscriptionState } from '@/auth/AuthContext';

export type Account = {
  id: number;
  invoice_company_name: string | null;
  invoice_street: string | null;
  invoice_postal_code: string | null;
  invoice_city: string | null;
  invoice_country: string | null;
  invoice_ico: string | null;
  invoice_dic: string | null;
  invoice_ic_dph: string | null;
  theme_color: string | null;
  has_logo: boolean;
  subscription_end_date: string | null;
  stripe_status: string | null;
  subscription_state: SubscriptionState;
  billing_period: 'monthly' | 'yearly' | null;
  has_stripe_customer: boolean;
  stripe_cancel_at_period_end: boolean;
  included_technicians: number;
  extra_technicians: number;
  active_technicians: number;
  max_self_service_technicians: number;
  price_per_extra_technician_cents: number;
  /** What a yearly subscription charges per extra technician (from the backend, not derived here). */
  price_per_extra_technician_yearly_cents: number;
  /** What one extra technician costs this account per billing period: the unit amount on its Stripe item when one exists, else today's price. */
  extra_unit_amount_cents: number;
  /** True when extra_unit_amount_cents is read from the customer's Stripe item. */
  extra_unit_from_stripe: boolean;
  /** Next regular charge (base + extra technicians, without prorations); null without a subscription. */
  next_charge_cents: number | null;
  /** A promotion code is on the subscription: next_charge_cents is before the discount. */
  next_charge_before_discount?: boolean;
  /** Extra technicians next_charge_cents includes (Stripe's count while nothing is pushed to Stripe). */
  next_charge_extra_technicians?: number;
  /** VAT rate in percent (VAT_RATE_PERCENT): every price above is net, the customer pays it plus VAT. */
  vat_rate_percent: number;
  /** A failed renewal: amount (with VAT) and the first read-only day; null otherwise. */
  payment_failed: PaymentFailed | null;
  main_user_id: number | null;
  admin_owned: boolean;
  default_php_user_id: number | null;
  default_oprava_user_id: number | null;
};

export type AccountUpdate = {
  invoice_company_name?: string | null;
  invoice_street?: string | null;
  invoice_postal_code?: string | null;
  invoice_city?: string | null;
  invoice_country?: string | null;
  invoice_ico?: string | null;
  invoice_dic?: string | null;
  invoice_ic_dph?: string | null;
  theme_color?: string | null;
};

export const AccountApi = {
  show: () => api<{ account: Account }>('/api/account'),
  update: (body: AccountUpdate, csrfToken: string | null) =>
    api<{ account: Account }>('/api/account', {
      method: 'PATCH',
      body,
      csrfToken,
    }),
  uploadLogo: (file: File, csrfToken: string | null) => {
    const fd = new FormData();
    fd.append('logo', file);
    return api<{ account: Account }>('/api/account/logo', {
      method: 'POST',
      body: fd,
      csrfToken,
    });
  },
  deleteLogo: (csrfToken: string | null) =>
    api<{ account: Account }>('/api/account/logo', {
      method: 'DELETE',
      csrfToken,
    }),
  // Logo endpoint streams the file — consume via <img src> with cache-bust.
  logoUrl: (cacheBuster?: number | string): string => {
    const qs = cacheBuster !== undefined ? `?t=${cacheBuster}` : '';
    return buildUrl(`/api/account/logo${qs}`);
  },
};
