import { useEffect } from 'react';
import { useToast } from '@/lib/toast';
import type { UnauthorizedDetail } from '@/lib/api';

/**
 * Turns the api layer's `firol:unauthorized` event into a Slovak toast.
 *
 * Lives as its own component because AuthContext — which also listens for the
 * event, to drop the app back to the login screen — sits *above* ToastProvider
 * and so can't call useToast(). Mounted once, inside the provider.
 *
 * The message itself comes from the backend (`Prihlásenie vypršalo, prihláste
 * sa znova.`); before this existed the user got the raw "invalid token".
 */
export function SessionExpiredNotice() {
  const toast = useToast();

  useEffect(() => {
    const handler = (event: Event) => {
      const detail = (event as CustomEvent<UnauthorizedDetail>).detail;
      if (!detail?.announce || !detail.message) return;
      toast.error(
        detail.draftSaved
          ? `${detail.message} Rozpracovaný zápis je uložený ako koncept a odošle sa po prihlásení.`
          : detail.message,
      );
    };
    window.addEventListener('firol:unauthorized', handler);
    return () => window.removeEventListener('firol:unauthorized', handler);
  }, [toast]);

  return null;
}
