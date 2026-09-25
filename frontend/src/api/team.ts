import { api } from '@/lib/api';
import type { TeamIdentity } from '@/auth/AuthContext';

export type TeamMember = {
  id: number;
  fullname: string;
  email: string;
  phone: string | null;
  role: string;
  is_active: boolean;
  is_main: boolean;
  is_default_php: boolean;
  is_default_oprava: boolean;
  cert_php: string | null;
  cert_oprava: string | null;
  cert_general: string | null;
  valid_from_php: string | null;
  valid_to_php: string | null;
  valid_from_oprava: string | null;
  valid_to_oprava: string | null;
  valid_from_general: string | null;
  valid_to_general: string | null;
  /** Chapter 11.5 — initials in the avatar circle (max 3 characters). */
  initials: string;
  /** Chapter 11.5 — avatar colour, unique within the account. */
  avatar_color: string;
  created_at: string;
};

/** Body of PATCH /api/account/users/{id}/identity — either field optional. */
export type MemberIdentityInput = {
  initials?: string;
  avatar_color?: string;
};

export type TeamDefaultKind = 'php' | 'oprava';

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

  /** Chapter 11.5 — the fixed palette avatar colours are picked from. */
  avatarPalette: () => api<{ palette: string[] }>('/api/account/avatar-palette'),

  /**
   * Chapter 11.5 — change a member's initials (main user, or the member
   * themselves) and/or avatar colour (main user only). Returns the updated
   * roster, the same shape as `team` in /api/me.
   */
  setIdentity: (id: number, body: MemberIdentityInput, csrfToken: string | null) =>
    api<{ item: TeamIdentity | null; team: TeamIdentity[] }>(`/api/account/users/${id}/identity`, {
      method: 'PATCH',
      body,
      csrfToken,
    }),

  setDefault: (kind: TeamDefaultKind, userId: number | null, csrfToken: string | null) =>
    api<{ ok: true; kind: TeamDefaultKind; user_id: number | null }>(
      '/api/account/team-defaults',
      { method: 'POST', body: { kind, user_id: userId }, csrfToken },
    ),
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
