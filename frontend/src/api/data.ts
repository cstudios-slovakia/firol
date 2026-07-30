import { api, ApiError, buildUrl } from '@/lib/api';

export type RestoreMode = 'merge' | 'replace';

export type RestoreResult = {
  mode: RestoreMode;
  restored: Record<string, number>;
  skipped: Record<string, number>;
  warnings: string[];
};

export type ExportOptions = {
  photos: boolean;
  documents: boolean;
};

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

  /**
   * The backup download is a plain <a href> rather than fetch+blob: the
   * archive carries every photo and PDF the account owns and can run to
   * hundreds of MB, which a Blob would have to hold in memory in full. A
   * direct navigation streams it to disk instead.
   */
  exportUrl: (opts: ExportOptions = { photos: true, documents: true }) => {
    const params = new URLSearchParams();
    if (!opts.photos) params.set('photos', '0');
    if (!opts.documents) params.set('documents', '0');
    const query = params.toString();
    return buildUrl(`/api/account/export${query ? `?${query}` : ''}`);
  },

  /**
   * Uploads a backup archive and restores it into the active account.
   *
   * XHR rather than fetch, purely for `onProgress`: restoring a full backup
   * means pushing a very large file over what is often a phone connection,
   * and a button that just sits there for four minutes reads as a hang.
   */
  restore(
    file: File,
    mode: RestoreMode,
    csrfToken: string | null,
    onProgress?: (percent: number) => void,
  ): Promise<RestoreResult> {
    const form = new FormData();
    form.append('file', file);
    form.append('mode', mode);

    return new Promise<RestoreResult>((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', buildUrl('/api/account/restore'));
      xhr.withCredentials = true;
      if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable && onProgress) {
          onProgress(Math.round((e.loaded / e.total) * 100));
        }
      };

      xhr.onload = () => {
        const body = safeJson(xhr.responseText);
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve(body as RestoreResult);
          return;
        }
        const message =
          body && typeof body === 'object' && body !== null && 'error' in body
            ? String((body as { error: unknown }).error)
            : `HTTP ${xhr.status}`;
        reject(new ApiError(xhr.status, message, body));
      };

      xhr.onerror = () =>
        reject(new ApiError(0, 'Spojenie so serverom zlyhalo počas nahrávania.', null));
      xhr.onabort = () => reject(new ApiError(0, 'Nahrávanie bolo zrušené.', null));

      xhr.send(form);
    });
  },
};

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
