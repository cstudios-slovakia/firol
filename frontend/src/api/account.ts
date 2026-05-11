import { api } from '@/lib/api';

export type Account = {
  id: number;
  invoice_company_name: string | null;
  theme_color: string | null;
  has_logo: boolean;
  subscription_end_date: string | null;
  stripe_status: string | null;
  billing_period: 'monthly' | 'yearly' | null;
  has_stripe_customer: boolean;
};

export type AccountUpdate = {
  invoice_company_name?: string | null;
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
    const base = '/api/account/logo';
    return cacheBuster !== undefined ? `${base}?t=${cacheBuster}` : base;
  },
};
