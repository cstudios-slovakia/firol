/*
 * A missing oprávnenie, end to end.
 *
 * The backend refuses an úkon the technician cannot sign with
 * `{ code: 'cert_missing', cert: <which number> }` — on creating a kontrola,
 * repeating one, closing a prehliadka or issuing an oboznámenie BOZP. The
 * plain message alone leaves a technician who has just walked a whole
 * building wondering whether the work is gone. So the refusal becomes a toast
 * that says what is kept, with one button that opens the exact field to fill
 * in, highlighted, and a way back to where they were.
 */
import { useCallback, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { ApiError } from './api';
import { readCache } from './cache';
import { CERT_MISSING_SYNC_EVENT, type CertMissingSyncDetail } from './queue';
import { InspectorProfileApi, type InspectorProfile } from '@/api/inspectorProfile';
import { useToast } from './toast';
import { useIsMainUser } from '@/auth/useIsMainUser';

/** Personal numbers on the Profil technika, plus the firm's own `vv`. */
export type CertKey = 'cert_php' | 'cert_oprava' | 'cert_general' | 'cert_bt' | 'vv';

const CERT_KEYS: readonly CertKey[] = ['cert_php', 'cert_oprava', 'cert_general', 'cert_bt', 'vv'];

/** Query parameter the settings pages read to highlight one field. */
export const CERT_FOCUS_PARAM = 'doplnit';

/** Router state carried to the settings page so it can lead the user back. */
export type CertReturnState = { returnTo: string; returnLabel: string };

export function isCertKey(v: unknown): v is CertKey {
  return typeof v === 'string' && (CERT_KEYS as readonly string[]).includes(v);
}

/** Which number the server says is missing, or null for any other error. */
export function missingCertOf(err: unknown): CertKey | null {
  if (!(err instanceof ApiError) || err.code !== 'cert_missing') return null;
  const cert = (err.body as { cert?: unknown } | null)?.cert;
  return isCertKey(cert) ? cert : null;
}

/** Where a given number is entered. */
export function certSettingsPath(cert: CertKey): string {
  const page = cert === 'vv' ? '/settings/opravnenia' : '/settings/profil';
  return `${page}?${CERT_FOCUS_PARAM}=${cert}`;
}

/** Reads the router state a cert-missing redirect left behind. */
export function certReturnOf(state: unknown): CertReturnState | null {
  const s = state as Partial<CertReturnState> | null;
  return s && typeof s.returnTo === 'string' && typeof s.returnLabel === 'string'
    ? { returnTo: s.returnTo, returnLabel: s.returnLabel }
    : null;
}

type Options = {
  /**
   * What stays saved, shown under the server's message — e.g. „Prehliadka
   * je uložená ako koncept…". Omit when nothing was created yet.
   */
  saved?: string;
  /** Label of the way back from the settings page. */
  returnLabel?: string;
  /** Where that way back leads; defaults to the current screen. */
  returnTo?: string;
};

/**
 * Returns a handler for a caught error: when it is a missing oprávnenie it
 * shows the guided toast and returns true, otherwise it does nothing and
 * returns false so the caller's own error path runs.
 */
export function useCertMissingToast() {
  const toast = useToast();
  const navigate = useNavigate();
  const location = useLocation();
  const isMainUser = useIsMainUser();

  return useCallback(
    (err: unknown, options: Options = {}): boolean => {
      const cert = missingCertOf(err);
      if (cert === null) return false;

      const message = (err as ApiError).message;
      // Firm numbers are kept by the main user alone; anyone else is already
      // told whom to ask, and a button to a page they cannot open would lie.
      const canFix = cert !== 'vv' || isMainUser;
      const state: CertReturnState = {
        returnTo: options.returnTo ?? `${location.pathname}${location.search}`,
        returnLabel: options.returnLabel ?? 'Späť na kontrolu',
      };

      toast.error(message, {
        detail: options.saved,
        action: canFix
          ? {
              label: cert === 'vv' ? 'Doplniť firemné oprávnenie' : 'Doplniť v profile',
              onClick: () => navigate(certSettingsPath(cert), { state }),
            }
          : undefined,
      });
      return true;
    },
    [toast, navigate, location.pathname, location.search, isMainUser],
  );
}

// --- offline -------------------------------------------------------------

const PROFILE_PATH = '/api/me/inspector-profile';

/**
 * Refresh the cached Profil technika — and with it the server's `can_create`
 * verdict — while there is a connection, so an úkon started offline later
 * can be checked. Silent: offline it simply leaves the old copy in place.
 */
export function warmCertProfile(): void {
  if (typeof navigator !== 'undefined' && !navigator.onLine) return;
  InspectorProfileApi.show().catch(() => undefined);
}

/**
 * The refusal the server would give a create, read from the cached profile,
 * for use while offline — so the technician hears it before walking the
 * building, not when the draft syncs. `keys` are `can_create` keys: the úkon
 * type, or `preventivna_prehliadka.po` / `.bozp`.
 *
 * Returns null when allowed, and also when nothing is cached: a draft that
 * cannot be checked is still started, and the sync decides (the outbox keeps
 * it and guides the technician if the server refuses).
 */
export async function cachedCertRefusal(keys: string[]): Promise<ApiError | null> {
  const cached = await readCache<{ profile: InspectorProfile }>(PROFILE_PATH).catch(() => undefined);
  const verdicts = cached?.profile?.can_create;
  if (!verdicts) return null;
  for (const key of keys) {
    const v = verdicts[key];
    if (v && isCertKey(v.cert)) {
      return new ApiError(422, v.error, { error: v.error, code: 'cert_missing', cert: v.cert }, 'cert_missing');
    }
  }
  return null;
}

/**
 * Mounted once (in the header): turns an outbox replay refused with
 * `cert_missing` into the same guided toast the online flow shows. The work
 * stays queued; the next sync after the number is filled in sends it.
 */
export function useCertMissingSyncToast(): void {
  const certMissing = useCertMissingToast();
  useEffect(() => {
    function onRefused(e: Event) {
      const { message, cert } = (e as CustomEvent<CertMissingSyncDetail>).detail;
      certMissing(new ApiError(422, message, { error: message, code: 'cert_missing', cert }, 'cert_missing'), {
        saved: 'Neodoslané zmeny zostávajú uložené v zariadení a odošlú sa, keď číslo doplníš.',
        returnLabel: 'Späť',
      });
    }
    window.addEventListener(CERT_MISSING_SYNC_EVENT, onRefused);
    return () => window.removeEventListener(CERT_MISSING_SYNC_EVENT, onRefused);
  }, [certMissing]);
}
