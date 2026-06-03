import { api, buildUrl } from '@/lib/api';

export const DataApi = {
  purgeCompanies: (csrfToken: string | null) =>
    api<{ deleted: number }>('/api/account/data/companies', {
      method: 'DELETE',
      csrfToken,
      requireOnline: true,
    }),

  purgeInspections: (csrfToken: string | null) =>
    api<{ deleted: number }>('/api/account/data/inspections', {
      method: 'DELETE',
      csrfToken,
      requireOnline: true,
    }),

  purgeTrainings: (csrfToken: string | null) =>
    api<{ deleted: number }>('/api/account/data/trainings', {
      method: 'DELETE',
      csrfToken,
      requireOnline: true,
    }),

  exportUrl: () => buildUrl('/api/account/export'),
};
