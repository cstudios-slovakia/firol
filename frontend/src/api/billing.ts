import { api } from '@/lib/api';

export type BillingPeriod = 'monthly' | 'yearly';

export const Billing = {
  /** Creates a Stripe Checkout Session and returns the URL to redirect to. */
  checkout: (period: BillingPeriod, csrfToken: string | null) =>
    api<{ url: string; id: string }>('/api/billing/checkout', {
      method: 'POST',
      body: { billing_period: period },
      csrfToken,
    }),
  /** Returns a Stripe Customer Portal URL (change card, see invoices, cancel). */
  portal: (csrfToken: string | null) =>
    api<{ url: string }>('/api/billing/portal', { method: 'POST', csrfToken }),
};
