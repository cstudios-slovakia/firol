import { api } from '@/lib/api';

export type TeamMember = {
  id: number;
  fullname: string;
  email: string;
  phone: string | null;
  role: string;
  is_active: boolean;
  is_main: boolean;
  created_at: string;
};

export type InvitePayload = {
  fullname: string;
  email: string;
  phone?: string | null;
};

export const Team = {
  list: () => api<{ items: TeamMember[] }>('/api/account/users'),
  invite: (body: InvitePayload, csrfToken: string | null) =>
    api<{ item: TeamMember; invite_token: string | null; invited_new: boolean }>('/api/account/users', {
      method: 'POST',
      body,
      csrfToken,
    }),
  setActive: (id: number, isActive: boolean, csrfToken: string | null) =>
    api<{ item: TeamMember }>(`/api/account/users/${id}`, {
      method: 'PATCH',
      body: { is_active: isActive },
      csrfToken,
    }),
  remove: (id: number, csrfToken: string | null) =>
    api<void>(`/api/account/users/${id}`, { method: 'DELETE', csrfToken }),
};
