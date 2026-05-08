import { useEffect, useState } from 'react';

/**
 * Two-phase mount/unmount for transitioning popovers and dialogs.
 *
 * Returns:
 *   mounted — whether the element should be rendered at all. Stays true
 *             during the leave animation so the user actually sees it.
 *   entered — flips to true on the next animation frame after mount and
 *             back to false the moment `open` flips to false. Use it
 *             to swap initial / final classes (scale-95 opacity-0 → scale-100 opacity-100).
 *
 * `durationMs` should match the Tailwind transition duration on the
 * outermost animated element so we don't unmount mid-fade.
 */
export function useDelayedMount(open: boolean, durationMs = 180): {
  mounted: boolean;
  entered: boolean;
} {
  const [mounted, setMounted] = useState(open);
  const [entered, setEntered] = useState(false);

  useEffect(() => {
    if (open) {
      setMounted(true);
      // Wait one rAF so the browser commits the initial (closed) styles
      // before we apply the open ones — otherwise the transition just
      // jumps to the end with no animation.
      const raf = requestAnimationFrame(() => setEntered(true));
      return () => cancelAnimationFrame(raf);
    }
    setEntered(false);
    const t = window.setTimeout(() => setMounted(false), durationMs);
    return () => window.clearTimeout(t);
  }, [open, durationMs]);

  return { mounted, entered };
}
