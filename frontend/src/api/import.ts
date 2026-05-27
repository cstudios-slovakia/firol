import { api, ApiError, buildUrl } from '@/lib/api';

export type ImportKind = 'companies' | 'inspections' | 'trainings';

export type ImportError = { sheet: string; row: number; message: string };

export type ImportResult = {
  created: Record<string, number> | null;
  errors: ImportError[];
};

const KIND_FILENAME: Record<ImportKind, string> = {
  companies:   'Firol-import-firmy.xlsx',
  inspections: 'Firol-import-kontroly.xlsx',
  trainings:   'Firol-import-skolenia.xlsx',
};

export const ImportApi = {
  /**
   * Downloads the .xlsx template for the given kind. We can't use fetch +
   * <a download> trick across the proxy reliably, so we trigger a normal
   * navigation through a hidden anchor — the browser handles save dialog
   * and Content-Disposition picks up the filename.
   */
  downloadTemplate(kind: ImportKind): void {
    const a = document.createElement('a');
    a.href = buildUrl(`/api/import/${kind}/template`);
    a.download = KIND_FILENAME[kind];
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  },

  async upload(
    kind: ImportKind,
    file: File,
    csrfToken: string | null,
  ): Promise<ImportResult> {
    const form = new FormData();
    form.append('file', file);
    try {
      return await api<ImportResult>(`/api/import/${kind}`, {
        method: 'POST',
        body: form,
        csrfToken,
        requireOnline: true,
      });
    } catch (err) {
      // 422 with a row-level error list is the "validation failed" path —
      // surface it to the UI rather than as a generic error.
      if (err instanceof ApiError && err.status === 422 && err.body && typeof err.body === 'object') {
        const body = err.body as Partial<ImportResult>;
        if (Array.isArray(body.errors)) {
          return { created: null, errors: body.errors };
        }
      }
      throw err;
    }
  },
};
