import { api } from '@/lib/api';

export type FeedbackKind = 'bug' | 'feature';

export type FeedbackSubmission = {
  id: number;
  kind: FeedbackKind;
  message: string;
  source_url: string | null;
  user_agent: string | null;
  account_id: number | null;
  user_id: number | null;
  submitter_name: string | null;
  submitter_email: string | null;
  account_name: string | null;
  created_at: string;
};

export const Feedback = {
  submit: (
    body: { kind: FeedbackKind; message: string; source_url?: string | null },
    csrfToken: string | null,
  ) =>
    api<{ ok: true }>('/api/feedback', {
      method: 'POST',
      body,
      csrfToken,
      requireOnline: true,
    }),

  list: () => api<{ items: FeedbackSubmission[] }>('/api/admin/feedback'),

  remove: (id: number, csrfToken: string | null) =>
    api<void>(`/api/admin/feedback/${id}`, { method: 'DELETE', csrfToken }),
};
