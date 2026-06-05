import { useEffect, useState } from 'react';
import { getConnectivity, subscribeConnectivity } from './connectivity';

/**
 * Tracks real connectivity and re-renders when it changes. Backed by the
 * shared connectivity store (navigator.onLine + an active /api/health probe),
 * so it stays correct after a reload while offline — unlike a bare
 * `navigator.onLine`, which often reports a stale `true` on a fresh load.
 *
 * Used by the AppShell indicator and any component that wants to gate an
 * online-only action with a clear message.
 */
export function useOnlineStatus(): boolean {
  const [online, setOnline] = useState(getConnectivity);
  useEffect(() => subscribeConnectivity(setOnline), []);
  return online;
}
