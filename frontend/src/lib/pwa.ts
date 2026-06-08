/*
 * Service Worker registration + background-sync glue.
 *
 * - Registers the SW emitted by vite-plugin-pwa. In dev (devOptions.enabled
 *   = false) the virtual module resolves to a no-op.
 * - Wires the `online` event to drainQueue() so queued mutations replay
 *   as soon as the device is back on the network.
 */
import { registerSW } from 'virtual:pwa-register';
import { drainQueue } from './queue';
import { initInstallPrompt } from './installPrompt';

export function initPwa(): void {
  // Capture the native install prompt early so it isn't lost before the app
  // shell mounts (see lib/installPrompt).
  initInstallPrompt();

  registerSW({
    immediate: true,
    onRegisteredSW(swUrl) {
      // eslint-disable-next-line no-console
      console.info('[pwa] service worker registered:', swUrl);
    },
    onRegisterError(error) {
      // eslint-disable-next-line no-console
      console.warn('[pwa] service worker registration failed:', error);
    },
  });

  window.addEventListener('online', () => {
    drainQueue().catch(() => undefined);
  });
  // Try once on load too, in case we already have queued mutations and
  // the connection is up.
  if (navigator.onLine) {
    drainQueue().catch(() => undefined);
  }
}
