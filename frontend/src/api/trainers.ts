import { api } from '@/lib/api';

export type Trainer = {
  id: number;
  fullname: string;
  certification_number: string | null;
  has_signature: boolean;
};

export type TrainerPayload = {
  fullname: string;
  certification_number?: string | null;
};

export const Trainers = {
  list: () => api<{ items: Trainer[] }>('/api/trainers'),
  show: (id: number) => api<{ trainer: Trainer }>(`/api/trainers/${id}`),
  create: (body: TrainerPayload, csrfToken: string | null) =>
    api<{ trainer: Trainer }>('/api/trainers', { method: 'POST', body, csrfToken }),
  update: (id: number, body: TrainerPayload, csrfToken: string | null) =>
    api<{ trainer: Trainer }>(`/api/trainers/${id}`, { method: 'PATCH', body, csrfToken }),
  archive: (id: number, csrfToken: string | null) =>
    api<void>(`/api/trainers/${id}`, { method: 'DELETE', csrfToken }),
  uploadSignature: (id: number, file: File, csrfToken: string | null) => {
    const fd = new FormData();
    fd.append('signature', file);
    return api<{ trainer: Trainer }>(`/api/trainers/${id}/signature`, {
      method: 'POST',
      body: fd,
      csrfToken,
    });
  },
  signatureUrl: (id: number, cacheBuster?: number | string): string => {
    const base = `/api/trainers/${id}/signature`;
    return cacheBuster !== undefined ? `${base}?t=${cacheBuster}` : base;
  },
};
