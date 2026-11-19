import { api } from '@/lib/api';

export type AdminUser = {
  id: number;
  fullname: string;
  email: string;
  phone: string | null;
  is_admin: boolean;
  is_env_seed: boolean;
  role: string;
  is_active: boolean;
  /** False when the user is an account owner or appears on a generated protocol — a hard delete would fail. */
  deletable: boolean;
  created_at: string;
};

export type AdminAccount = {
  id: number;
  invoice_company_name: string;
  subscription_end_date: string | null;
  main_user_id: number;
  stripe_status: string | null;
  /** A live Stripe subscription controls subscription_end_date (the admin edit is refused). */
  has_live_subscription: boolean;
  billing_period: 'monthly' | 'yearly' | null;
  created_at: string;
  included_technicians: number;
  extra_technicians: number;
  users: AdminUser[];
};

export type AdminAccountsPage = {
  items: AdminAccount[];
  total: number;
  offset: number;
  limit: number;
};

export type AdminAccountUpdate = Partial<{
  invoice_company_name: string;
  subscription_end_date: string;
  included_technicians: number;
}>;

export type AdminUserUpdate = Partial<{
  fullname: string;
  email: string;
  phone: string | null;
  is_admin: boolean;
}>;

export type AdminInvoice = {
  id: number;
  stripe_invoice_id: string;
  idoklad_invoice_id: number | null;
  document_number: string | null;
  amount_cents: number;
  currency: string;
  status: string;
  issued_at: string;
  /** iDoklad PublicHtmlUrl when issued, Stripe invoice PDF otherwise. */
  pdf_url: string | null;
  /** Stripe's numbers — a credit invoice has a negative total. */
  stripe_total_cents: number | null;
  stripe_amount_paid_cents: number | null;
  stripe_livemode: boolean | null;
  stripe_balance: Partial<Record<'subtotal' | 'amount_due' | 'starting_balance' | 'ending_balance', number>> | null;
  /** Stripe's VAT of the invoice (prices are net). */
  stripe_tax_cents: number | null;
  /** Why a row is held (needs_review) or has no document (no_document). */
  review_reason: string | null;
  error_message: string | null;
  attempts: number;
  last_attempt_at: string | null;
  /** „Vystaviť znova" is offered. */
  can_reissue: boolean;
  /** Marking the issued invoice paid in iDoklad: pending | marking | done | error. */
  paid_mark_state: string | null;
  paid_mark_attempts: number;
  paid_marked_at: string | null;
  paid_mark_error: string | null;
  /** iDoklad's e-mail to the customer: pending | sending | sent | error | uncertain. */
  send_state: string | null;
  send_attempts: number;
  sent_at: string | null;
  sent_to: string | null;
  send_error: string | null;
  /** „Poslať znova" is offered. */
  can_resend: boolean;
};

export type SystemCheck = {
  key: string;
  level: 'ok' | 'warn' | 'error';
  message: string;
  /** Items an admin can mark as resolved (gone Stripe events). */
  dismiss?: { id: string; label: string }[];
};

export type AdminInvoicesPage = {
  items: AdminInvoice[];
  /** Years that have at least one invoice, newest first. */
  years: number[];
  /** The year the returned items belong to. */
  year: number;
  account_id: number;
};

export const AdminPanel = {
  listAccounts: (offset: number = 0, search?: string) => {
    const params = new URLSearchParams({ offset: String(offset) });
    if (search) params.set('search', search);
    return api<AdminAccountsPage>(`/api/admin/accounts?${params}`);
  },
  listInvoices: (accountId: number, year?: number) => {
    const qs = year ? `?year=${year}` : '';
    return api<AdminInvoicesPage>(`/api/admin/accounts/${accountId}/invoices${qs}`);
  },
  updateAccount: (id: number, body: AdminAccountUpdate, csrfToken: string | null) =>
    api<{ ok: true }>(`/api/admin/accounts/${id}`, { method: 'PATCH', body, csrfToken }),
  /** With cancelSubscription the account's live Stripe subscription is cancelled at once first. */
  deleteAccount: (id: number, csrfToken: string | null, cancelSubscription = false) =>
    api<void>(`/api/admin/accounts/${id}${cancelSubscription ? '?cancel_subscription=1' : ''}`, { method: 'DELETE', csrfToken }),
  /** Sends the state the admin saw; the server refuses (409) when the row moved on. */
  reissueInvoice: (inv: Pick<AdminInvoice, 'id' | 'status' | 'review_reason'>, csrfToken: string | null) =>
    api<{ ok: boolean; status: string; message: string | null }>(`/api/admin/invoices/${inv.id}/reissue`, {
      method: 'POST',
      body: { expected_status: inv.status, expected_reason: inv.review_reason ?? '' },
      csrfToken,
    }),
  /** „Poslať znova": marking paid and the e-mail to the customer again. */
  resendInvoice: (inv: Pick<AdminInvoice, 'id' | 'send_state' | 'paid_mark_state'>, csrfToken: string | null) =>
    api<{ ok: boolean; send_state: string | null; paid_state: string | null; message: string | null }>(`/api/admin/invoices/${inv.id}/resend`, {
      method: 'POST',
      body: { expected_send_state: inv.send_state ?? '', expected_paid_state: inv.paid_mark_state ?? '' },
      csrfToken,
    }),
  dismissEvent: (eventId: string, csrfToken: string | null) =>
    api<{ ok: true }>(`/api/admin/billing/events/${encodeURIComponent(eventId)}/dismiss`, { method: 'POST', csrfToken }),
  systemCheck: () => api<{ checks: SystemCheck[] }>('/api/admin/system-check'),
  updateUser: (id: number, body: AdminUserUpdate, csrfToken: string | null) =>
    api<{ ok: true }>(`/api/admin/users/${id}`, { method: 'PATCH', body, csrfToken }),
  deleteUser: (id: number, csrfToken: string | null) =>
    api<void>(`/api/admin/users/${id}`, { method: 'DELETE', csrfToken }),
  setUserActive: (
    accountId: number,
    userId: number,
    isActive: boolean,
    csrfToken: string | null,
  ) =>
    api<{ ok: true; is_active: boolean }>(
      `/api/admin/accounts/${accountId}/users/${userId}`,
      { method: 'PATCH', body: { is_active: isActive }, csrfToken },
    ),
};

export type SystemSettings = {
  trial_days: string;
  price_monthly_eur: string;
  price_yearly_eur: string;
  /** Read-only: VAT_RATE_PERCENT from the server's .env. */
  vat_rate_percent: string;
  default_included_technicians: string;
  price_per_extra_technician_cents: string;
  max_self_service_technicians: string;
};

export type SystemSettingsUpdate = Partial<{
  trial_days: number;
  price_monthly_eur: number;
  price_yearly_eur: number;
  default_included_technicians: number;
  price_per_extra_technician_cents: number;
  max_self_service_technicians: number;
}>;

export const Admin = {
  settings: () => api<{ settings: SystemSettings }>('/api/admin/settings'),
  updateSettings: (body: SystemSettingsUpdate, csrfToken: string | null) =>
    api<{ settings: SystemSettings }>('/api/admin/settings', {
      method: 'PATCH',
      body,
      csrfToken,
    }),
};
