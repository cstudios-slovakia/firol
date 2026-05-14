import { useEffect, useState } from 'react';

/**
 * Tracks navigator.onLine and re-renders on online/offline events.
 * Used by the AppShell indicator and any component that wants to gate
 * an online-only action with a clear message.
 */
export function useOnlineStatus(): boolean {
  const [online, setOnline] = useState(
    typeof navigator === 'undefined' ? true : navigator.onLine,
  );
  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => {
      window.removeEventListener('online', on);
      window.removeEventListener('offline', off);
    };
  }, []);
  return online;
}
