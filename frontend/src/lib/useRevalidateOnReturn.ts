import { useEffect, useRef } from 'react';

/**
 * Screens load their data once, when they mount, and `lib/api.ts` only falls
 * back to the offline cache when the network fails — so a screen that stays
 * open (a tablet, a home-screen app, a tab in the background) keeps showing
 * what it loaded even after another technician, another device or a background
 * job changed the data. This hook closes that gap: it runs `reload` when the
 * page comes back to the foreground and when the device comes back online.
 *
 * - `reload` must refresh silently — keep the current data on screen while it
 *   runs and ignore a failure (the existing view stays), never reset to a
 *   loader.
 * - Throttled by `minGapMs`, so quick app switching does not fire a request
 *   every time. The `online` event ignores the throttle: it follows a gap in
 *   connectivity, which is exactly when the view may be out of date.
 * - Never runs while the page is hidden or the browser reports offline.
 */
export function useRevalidateOnReturn(reload: () => unknown, minGapMs = 30_000): void {
  const reloadRef = useRef(reload);
  reloadRef.current = reload;

  useEffect(() => {
    let last = Date.now();

    const run = (force: boolean) => {
      if (document.visibilityState !== 'visible' || !navigator.onLine) return;
      if (!force && Date.now() - last < minGapMs) return;
      last = Date.now();
      try {
        const result = reloadRef.current();
        if (result instanceof Promise) result.catch(() => undefined);
      } catch {
        // A silent refresh must never break the screen.
      }
    };

    const onVisible = () => run(false);
    const onOnline = () => run(true);
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('online', onOnline);
    return () => {
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('online', onOnline);
    };
  }, [minGapMs]);
}
