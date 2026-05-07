import { api } from '@/lib/api';

export type InspectorProfile = {
  user_id: number;
  account_id: number;
  has_signature: boolean;
  certification_number: string | null;
  valid_from: string | null;
  valid_to: string | null;
  is_active: boolean;
};

export type InspectorProfileUpdate = {
  certification_number?: string | null;
  valid_from?: string | null;
  valid_to?: string | null;
  is_active?: boolean;
};

export const InspectorProfileApi = {
  show: () => api<{ profile: InspectorProfile }>('/api/me/inspector-profile'),
  update: (body: InspectorProfileUpdate, csrfToken: string | null) =>
    api<{ profile: InspectorProfile }>('/api/me/inspector-profile', {
      method: 'PATCH',
      body,
      csrfToken,
    }),
  uploadSignature: (file: File, csrfToken: string | null) => {
    const fd = new FormData();
    fd.append('signature', file);
    return api<{ profile: InspectorProfile }>('/api/me/inspector-profile/signature', {
      method: 'POST',
      body: fd,
      csrfToken,
    });
  },
  // The signature endpoint streams a PNG — the UI consumes it via a
  // standard <img src> with a cache-busting query param after upload.
  signatureUrl: (cacheBuster?: number | string): string => {
    const base = '/api/me/inspector-profile/signature';
    return cacheBuster !== undefined ? `${base}?t=${cacheBuster}` : base;
  },
};
