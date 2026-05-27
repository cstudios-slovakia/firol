import { api, ApiError, buildUrl } from '@/lib/api';

async function parseError(res: Response): Promise<ApiError> {
  const text = await res.text().catch(() => '');
  let body: unknown = text;
  try { body = JSON.parse(text); } catch { /* keep as text */ }
  const message =
    body && typeof body === 'object' && body !== null && 'error' in body
      ? String((body as { error: unknown }).error)
      : `HTTP ${res.status}`;
  return new ApiError(res.status, message, body);
}

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
   * Downloads the .xlsx template via fetch+blob so the request goes through
   * the same auth/cookie path as the rest of the API (a plain <a download>
   * sometimes ends up without the session, depending on browser policy and
   * how the URL is rewritten through /api.php?path=). Errors from the server
   * (401, 500, …) are surfaced as ApiError instead of an opaque
   * "download failed" message in the browser shelf.
   */
  async downloadTemplate(kind: ImportKind): Promise<void> {
    const res = await fetch(buildUrl(`/api/import/${kind}/template`), {
      method: 'GET',
      credentials: 'include',
    });
    if (!res.ok) throw await parseError(res);

    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = KIND_FILENAME[kind];
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    // Revoke after the click handler returns to the event loop.
    setTimeout(() => URL.revokeObjectURL(url), 0);
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
