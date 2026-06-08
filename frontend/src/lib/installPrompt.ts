/*
 * PWA "add to home screen" install prompt — native browser flow.
 *
 * The browser fires `beforeinstallprompt` when the app meets installability
 * criteria (valid manifest + SW + not already installed). We capture and
 * stash that event at module load — earlier than any React tree mounts —
 * so the deferred prompt isn't lost while the user is still on the login or
 * register page. The InstallPrompt component later reads it and, on user
 * action, calls `prompt()` to trigger the real native install dialog.
 *
 * Re-show after uninstall: the browser re-fires `beforeinstallprompt` the
 * next time the (now uninstalled) app is loaded. We clear the "not now"
 * flag when `appinstalled` fires, so a later uninstall naturally surfaces
 * the prompt again instead of staying suppressed forever.
 */

/** Non-standard event type — not in lib.dom yet. */
export type BeforeInstallPromptEvent = Event & {
  readonly platforms: string[];
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
};

// localStorage flag: user tapped "Teraz nie". Cleared on install so a future
// uninstall re-shows the prompt.
const DISMISS_KEY = 'firol:pwa-install-dismissed';

let deferred: BeforeInstallPromptEvent | null = null;
const listeners = new Set<() => void>();

function emit(): void {
  listeners.forEach((fn) => fn());
}

let initialized = false;

/** Wire the global listeners. Safe to call once, early (from initPwa). */
export function initInstallPrompt(): void {
  if (initialized || typeof window === 'undefined') return;
  initialized = true;

  window.addEventListener('beforeinstallprompt', (e) => {
    // Stop Chrome's mini-infobar so we control when the dialog appears.
    e.preventDefault();
    deferred = e as BeforeInstallPromptEvent;
    emit();
  });

  window.addEventListener('appinstalled', () => {
    deferred = null;
    clearDismissed();
    emit();
  });
}

export function getDeferredPrompt(): BeforeInstallPromptEvent | null {
  return deferred;
}

/** Hand back the stashed event and forget it (it can only be used once). */
export function clearDeferredPrompt(): void {
  deferred = null;
  emit();
}

export function subscribe(fn: () => void): () => void {
  listeners.add(fn);
  return () => {
    listeners.delete(fn);
  };
}

/** Already running as an installed app — never prompt in that case. */
export function isStandalone(): boolean {
  if (typeof window === 'undefined') return false;
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    // iOS Safari exposes standalone on navigator instead of display-mode.
    (navigator as unknown as { standalone?: boolean }).standalone === true
  );
}

export function isIOS(): boolean {
  if (typeof navigator === 'undefined') return false;
  const ua = navigator.userAgent;
  const iOSDevice = /iphone|ipad|ipod/i.test(ua);
  // iPadOS 13+ reports as Mac but has touch — treat as iOS for install hints.
  const iPadOS = /macintosh/i.test(ua) && navigator.maxTouchPoints > 1;
  return iOSDevice || iPadOS;
}

export function isDismissed(): boolean {
  try {
    return localStorage.getItem(DISMISS_KEY) === '1';
  } catch {
    return false;
  }
}

export function setDismissed(): void {
  try {
    localStorage.setItem(DISMISS_KEY, '1');
  } catch {
    /* private mode / storage disabled — just don't persist */
  }
  emit();
}

export function clearDismissed(): void {
  try {
    localStorage.removeItem(DISMISS_KEY);
  } catch {
    /* ignore */
  }
}
