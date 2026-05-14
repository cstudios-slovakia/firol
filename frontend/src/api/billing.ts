import { api } from '@/lib/api';

export type BillingPeriod = 'monthly' | 'yearly';

export type Invoice = {
  id: number;
  stripe_invoice_id: string;
  idoklad_invoice_id: number | null;
  document_number: string | null;
  amount_cents: number;
  currency: string;
  status: 'paid' | 'pending' | 'issued' | 'draft' | 'skipped' | 'error' | string;
  issued_at: string;
  /** Direct download link — iDoklad PublicHtmlUrl when available, Stripe invoice_pdf otherwise. */
  pdf_url: string | null;
};

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
  /** Schedule cancellation at the end of the current period. */
  cancel: (csrfToken: string | null) =>
    api<{ ok: true }>('/api/billing/cancel', { method: 'POST', csrfToken }),
  /** Revoke a scheduled cancellation while the period is still active. */
  resume: (csrfToken: string | null) =>
    api<{ ok: true }>('/api/billing/resume', { method: 'POST', csrfToken }),
  /** Local invoice history — issued by iDoklad off Stripe payments. */
  invoices: () => api<{ items: Invoice[] }>('/api/billing/invoices'),
};
