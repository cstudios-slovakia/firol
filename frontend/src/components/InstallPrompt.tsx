import { useEffect, useReducer, useState } from 'react';
import { Download, Share, Plus, X } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { BrandMark } from '@/components/Logo';
import {
  type BeforeInstallPromptEvent,
  clearDeferredPrompt,
  getDeferredPrompt,
  isDismissed,
  isIOS,
  isStandalone,
  setDismissed,
  subscribe,
} from '@/lib/installPrompt';

/**
 * Bottom-sheet popup recommending the user install Firol to their home
 * screen. Two delivery paths:
 *
 *  - Native (Chrome/Edge/Android/desktop): shown once the browser has handed
 *    us a `beforeinstallprompt` event. "Inštalovať" triggers the real native
 *    install dialog via that event's `prompt()`.
 *  - iOS Safari: no install API exists, so we show the manual
 *    "Zdieľať → Pridať na plochu" instructions instead.
 *
 * Suppression: "Teraz nie" sets a localStorage flag (see lib/installPrompt).
 * Installing the app clears that flag, so a later uninstall re-surfaces this.
 */
export function InstallPrompt() {
  // Re-render whenever the install store changes (event arrives, dismissed,
  // installed).
  const [, force] = useReducer((n: number) => n + 1, 0);
  useEffect(() => subscribe(force), []);

  const [installing, setInstalling] = useState(false);

  const deferred = getDeferredPrompt();
  const standalone = isStandalone();
  const dismissed = isDismissed();
  const ios = isIOS();

  // Visible when not already installed, not dismissed, and we either have a
  // native prompt to fire or we're on iOS (manual instructions).
  const showNative = !standalone && !dismissed && !!deferred;
  const showIOS = !standalone && !dismissed && ios && !deferred;
  const visible = showNative || showIOS;

  if (!visible) return null;

  async function handleInstall(evt: BeforeInstallPromptEvent) {
    setInstalling(true);
    try {
      await evt.prompt();
      const { outcome } = await evt.userChoice;
      // The event is single-use regardless of outcome.
      clearDeferredPrompt();
      if (outcome === 'dismissed') {
        // User declined the native dialog — don't nag again until they
        // reinstall (appinstalled clears the flag).
        setDismissed();
      }
    } catch {
      clearDeferredPrompt();
    } finally {
      setInstalling(false);
    }
  }

  return (
    <div
      className="fixed inset-x-0 top-0 z-50 flex h-[100dvh] items-end justify-center p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-labelledby="install-prompt-title"
    >
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-ink-900/40 backdrop-blur-sm"
        onClick={() => setDismissed()}
      />

      {/* Panel */}
      <div className="relative w-full max-w-md animate-fade-up rounded-3xl border border-firol-200 bg-white shadow-2xl">
        <button
          type="button"
          onClick={() => setDismissed()}
          aria-label="Zavrieť"
          className="absolute right-3 top-3 grid size-9 place-items-center rounded-2xl text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
        >
          <X className="size-4" />
        </button>

        {/* Header */}
        <div className="flex items-start gap-3 rounded-t-3xl border-b border-firol-100 bg-gradient-to-br from-firol-50 to-transparent px-5 py-4">
          <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-white shadow-sm">
            <BrandMark className="size-7 text-firol-600" />
          </div>
          <div className="min-w-0 flex-1 pt-0.5 pr-6">
            <h2 id="install-prompt-title" className="text-base font-semibold text-ink-900">
              Nainštaluj si POapp na plochu
            </h2>
            <p className="mt-0.5 text-xs text-ink-500">
              Rýchlejší prístup a práca aj bez internetu.
            </p>
          </div>
        </div>

        {/* Body */}
        <div className="px-5 py-4">
          {showIOS ? (
            <div className="space-y-3 text-sm text-ink-700">
              <p>Aplikáciu pridáš na plochu priamo v Safari:</p>
              <ol className="space-y-2">
                <li className="flex items-center gap-2.5">
                  <span className="grid size-7 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
                    <Share className="size-4" />
                  </span>
                  <span>
                    Ťukni na <span className="font-semibold">Zdieľať</span> v spodnej lište.
                  </span>
                </li>
                <li className="flex items-center gap-2.5">
                  <span className="grid size-7 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
                    <Plus className="size-4" />
                  </span>
                  <span>
                    Vyber <span className="font-semibold">Pridať na plochu</span>.
                  </span>
                </li>
              </ol>
            </div>
          ) : (
            <p className="text-sm text-ink-700">
              Pridaj si POapp medzi aplikácie — otvára sa na celú obrazovku ako bežná appka a máš
              ho po ruke jedným ťuknutím.
            </p>
          )}
        </div>

        {/* Actions */}
        <div className="flex flex-col gap-2 px-5 pb-5 sm:flex-row-reverse">
          {showNative && deferred && (
            <Button
              type="button"
              onClick={() => handleInstall(deferred)}
              loading={installing}
              leftIcon={<Download className="size-4" />}
              className="sm:flex-1"
            >
              Inštalovať
            </Button>
          )}
          <button
            type="button"
            onClick={() => setDismissed()}
            className="flex items-center justify-center gap-2 rounded-2xl border border-ink-200 bg-white px-4 py-2.5 text-sm font-semibold text-ink-600 transition-colors hover:bg-ink-50 sm:flex-1"
          >
            Teraz nie
          </button>
        </div>
      </div>
    </div>
  );
}
