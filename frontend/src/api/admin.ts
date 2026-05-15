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
  created_at: string;
};

export type AdminAccount = {
  id: number;
  invoice_company_name: string;
  subscription_end_date: string | null;
  main_user_id: number;
  stripe_status: string | null;
  billing_period: 'monthly' | 'yearly' | null;
  created_at: string;
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
}>;

export type AdminUserUpdate = Partial<{
  fullname: string;
  email: string;
  phone: string | null;
  is_admin: boolean;
}>;

export const AdminPanel = {
  listAccounts: (offset: number = 0, search?: string) => {
    const params = new URLSearchParams({ offset: String(offset) });
    if (search) params.set('search', search);
    return api<AdminAccountsPage>(`/api/admin/accounts?${params}`);
  },
  updateAccount: (id: number, body: AdminAccountUpdate, csrfToken: string | null) =>
    api<{ ok: true }>(`/api/admin/accounts/${id}`, { method: 'PATCH', body, csrfToken }),
  deleteAccount: (id: number, csrfToken: string | null) =>
    api<void>(`/api/admin/accounts/${id}`, { method: 'DELETE', csrfToken }),
  updateUser: (id: number, body: AdminUserUpdate, csrfToken: string | null) =>
    api<{ ok: true }>(`/api/admin/users/${id}`, { method: 'PATCH', body, csrfToken }),
  deleteUser: (id: number, csrfToken: string | null) =>
    api<void>(`/api/admin/users/${id}`, { method: 'DELETE', csrfToken }),
};

export type SystemSettings = {
  trial_days: string;
  price_monthly_eur: string;
  price_yearly_eur: string;
};

export type SystemSettingsUpdate = Partial<{
  trial_days: number;
  price_monthly_eur: number;
  price_yearly_eur: number;
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
