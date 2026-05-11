import { api } from '@/lib/api';

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
