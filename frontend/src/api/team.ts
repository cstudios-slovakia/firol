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

export type PendingInvite = {
  id: number;
  email: string;
  fullname: string;
  phone: string | null;
  expires_at: string;
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
    api<{ invite: PendingInvite; invite_token: string | null }>('/api/account/users', {
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

  listInvites: () => api<{ items: PendingInvite[] }>('/api/account/invites'),
  cancelInvite: (id: number, csrfToken: string | null) =>
    api<void>(`/api/account/invites/${id}`, { method: 'DELETE', csrfToken }),
};

export type InvitePreview = {
  invite: {
    email: string;
    fullname: string;
    phone: string | null;
    account_name: string;
    inviter_name: string;
    expires_at: string;
  };
  user_exists: boolean;
  session_email: string | null;
  session_user_id: number | null;
};

export type AcceptInvitePayload = {
  password?: string;
  fullname?: string;
  phone?: string | null;
};

export const Invites = {
  preview: (token: string) => api<InvitePreview>(`/api/invites/${encodeURIComponent(token)}`),
  accept: (token: string, body: AcceptInvitePayload) =>
    api<unknown>(`/api/invites/${encodeURIComponent(token)}/accept`, {
      method: 'POST',
      body,
    }),
  decline: (token: string) =>
    api<void>(`/api/invites/${encodeURIComponent(token)}/decline`, { method: 'POST' }),
};
