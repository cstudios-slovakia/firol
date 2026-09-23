import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { applyPendingUpdate } from '@/lib/pwa';

/**
 * Loads a newly deployed build at the first screen change after it arrived,
 * so a tab that has been open for days doesn't keep talking to the server
 * with the old build's code. See lib/pwa for when an update counts as pending.
 *
 * Keyed on the pathname only: a query-string change (search, filters) stays on
 * the same screen and may sit next to unsaved input.
 */
export function ApplyUpdateOnNavigate() {
  const { pathname } = useLocation();

  useEffect(() => {
    applyPendingUpdate();
  }, [pathname]);

  return null;
}
