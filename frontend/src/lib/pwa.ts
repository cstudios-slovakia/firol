/*
 * Service Worker registration + background-sync glue.
 *
 * - Registers the SW emitted by vite-plugin-pwa. In dev (devOptions.enabled
 *   = false) the virtual module resolves to a no-op.
 * - Keeps a long-open tab on the current build (see watchForUpdates and
 *   applyPendingUpdate).
 * - Wires the `online` event to drainQueue() so queued mutations replay
 *   as soon as the device is back on the network.
 */
import { registerSW } from 'virtual:pwa-register';
import { drainQueue } from './queue';
import { initInstallPrompt } from './installPrompt';

/**
 * The browser only looks for a new service worker on a full page load, and a
 * tablet or home-screen app can stay open for days without one. Such a tab
 * keeps running the build it started with — including that build's API
 * handling — long after a deploy has changed what the server expects. So an
 * open tab asks for itself: on this interval, and when it returns to the
 * foreground.
 */
const UPDATE_CHECK_INTERVAL_MS = 60 * 60 * 1000;
/** Floor between foreground-triggered checks, so app switching doesn't spam. */
const FOREGROUND_CHECK_THROTTLE_MS = 5 * 60 * 1000;

/** A new build has taken over the SW but this page is still the old one. */
let updatePending = false;
/** Whether the user has touched this page since it loaded. */
let interacted = false;

export function initPwa(): void {
  // Capture the native install prompt early so it isn't lost before the app
  // shell mounts (see lib/installPrompt).
  initInstallPrompt();

  const markInteracted = () => {
    interacted = true;
  };
  window.addEventListener('pointerdown', markInteracted, { capture: true, once: true, passive: true });
  window.addEventListener('keydown', markInteracted, { capture: true, once: true });

  registerSW({
    immediate: true,
    onRegisteredSW(swUrl, registration) {
      // eslint-disable-next-line no-console
      console.info('[pwa] service worker registered:', swUrl);
      if (registration) watchForUpdates(registration);
    },
    // autoUpdate has already activated the new SW; only this page is stale.
    // Reloading under the user could throw away a half-filled form, so reload
    // straight away only while the page is untouched (the usual case: the
    // update is found right after a cold start) and otherwise wait for the
    // next screen change.
    onNeedReload() {
      if (interacted) {
        updatePending = true;
      } else {
        window.location.reload();
      }
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

/**
 * Called on every screen change. When a newer build is waiting, this is the
 * safe moment to load it: the screen being left is discarded anyway, and
 * every route loads its own data by id, so nothing in memory is lost.
 */
export function applyPendingUpdate(): void {
  if (updatePending) window.location.reload();
}

function watchForUpdates(registration: ServiceWorkerRegistration): void {
  // register() has just checked, so the throttle starts from now.
  let lastCheck = Date.now();

  const check = () => {
    if (!navigator.onLine) return;
    lastCheck = Date.now();
    // Rejects when the SW script can't be fetched — the next check retries.
    registration.update().catch(() => undefined);
  };

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    if (Date.now() - lastCheck >= FOREGROUND_CHECK_THROTTLE_MS) check();
  });
  window.setInterval(() => {
    if (document.visibilityState === 'visible') check();
  }, UPDATE_CHECK_INTERVAL_MS);
}
