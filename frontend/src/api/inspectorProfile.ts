import { api, buildUrl } from '@/lib/api';

export type InspectorProfile = {
  user_id: number;
  account_id: number;
  has_signature: boolean;
  cert_php: string | null;
  cert_oprava: string | null;
  cert_general: string | null;
  valid_from_php: string | null;
  valid_to_php: string | null;
  valid_from_oprava: string | null;
  valid_to_oprava: string | null;
  valid_from_general: string | null;
  valid_to_general: string | null;
  is_active: boolean;
};

export type InspectorProfileUpdate = {
  cert_php?: string | null;
  cert_oprava?: string | null;
  cert_general?: string | null;
  valid_from_php?: string | null;
  valid_to_php?: string | null;
  valid_from_oprava?: string | null;
  valid_to_oprava?: string | null;
  valid_from_general?: string | null;
  valid_to_general?: string | null;
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
  uploadSignature: (file: Blob | File, csrfToken: string | null) => {
    const fd = new FormData();
    fd.append('signature', file, 'signature.png');
    return api<{ profile: InspectorProfile }>('/api/me/inspector-profile/signature', {
      method: 'POST',
      body: fd,
      csrfToken,
    });
  },
  signatureUrl: (cacheBuster?: number | string): string => {
    const qs = cacheBuster !== undefined ? `?t=${cacheBuster}` : '';
    return buildUrl(`/api/me/inspector-profile/signature${qs}`);
  },
};
