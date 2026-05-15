import { api, buildUrl } from '@/lib/api';
import type { SubscriptionState } from '@/auth/AuthContext';

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
