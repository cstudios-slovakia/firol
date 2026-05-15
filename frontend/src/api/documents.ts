import { api } from '@/lib/api';

export const Documents = {
  email: (
    documentId: number,
    payload: { email: string; note?: string | null },
    csrfToken: string | null,
  ) =>
    api<{ sent: true; to: string }>(`/api/documents/${documentId}/email`, {
      method: 'POST',
      body: payload,
      csrfToken,
    }),
};
