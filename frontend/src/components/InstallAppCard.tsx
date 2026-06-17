import { useEffect, useReducer, useState } from 'react';
import { Check, Download, Plus, Share, Smartphone } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import {
  type BeforeInstallPromptEvent,
  clearDeferredPrompt,
  getDeferredPrompt,
  isIOS,
  isStandalone,
  subscribe,
} from '@/lib/installPrompt';

/**
 * Settings card to manually trigger the PWA install. Mirrors the native
 * popup (InstallPrompt) but lives in Settings so the user can install at any
 * time, even after dismissing the popup.
 *
 * The "Inštalovať" button is only active where the browser actually offers a
 * native install (a captured `beforeinstallprompt`). On iOS — which has no
 * install API — the button stays disabled and we show the manual
 * "Zdieľať → Pridať na plochu" instructions instead.
 */
export function InstallAppCard() {
  const [, force] = useReducer((n: number) => n + 1, 0);
  useEffect(() => subscribe(force), []);

  const [installing, setInstalling] = useState(false);
  const [installed, setInstalled] = useState(false);

  const deferred = getDeferredPrompt();
  const standalone = isStandalone();
  const ios = isIOS();

  const alreadyInstalled = standalone || installed;
  const canInstall = !alreadyInstalled && !!deferred;
  const showIOSHelp = !alreadyInstalled && !deferred && ios;
  // Desktop/other browser with no captured prompt and not iOS — installation
  // either isn't supported or was already offered/used this session.
  const unsupported = !alreadyInstalled && !deferred && !ios;

  async function handleInstall(evt: BeforeInstallPromptEvent) {
    setInstalling(true);
    try {
      await evt.prompt();
      const { outcome } = await evt.userChoice;
      clearDeferredPrompt();
      if (outcome === 'accepted') setInstalled(true);
    } catch {
      clearDeferredPrompt();
    } finally {
      setInstalling(false);
    }
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/70 to-transparent px-5 py-4">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <Smartphone className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Inštalácia aplikácie</h2>
          <p className="text-xs text-ink-500">
            Pridaj si POapp na plochu — otvára sa na celú obrazovku a funguje aj bez internetu.
          </p>
        </div>
      </div>

      <div className="px-5 py-5">
        {alreadyInstalled ? (
          <div className="flex items-center gap-2.5 rounded-2xl border border-emerald-200 bg-emerald-50/60 px-4 py-3 text-sm font-medium text-emerald-800">
            <Check className="size-4 shrink-0" />
            POapp je nainštalovaný na tomto zariadení.
          </div>
        ) : (
          <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div className="min-w-0 flex-1 text-sm text-ink-600">
              {showIOSHelp ? (
                <div className="space-y-2">
                  <p>Na iPhone/iPade pridáš aplikáciu na plochu priamo v Safari:</p>
                  <ol className="space-y-1.5">
                    <li className="flex items-center gap-2">
                      <Share className="size-4 shrink-0 text-firol-600" />
                      <span>
                        Ťukni na <span className="font-semibold">Zdieľať</span> v spodnej lište.
                      </span>
                    </li>
                    <li className="flex items-center gap-2">
                      <Plus className="size-4 shrink-0 text-firol-600" />
                      <span>
                        Vyber <span className="font-semibold">Pridať na plochu</span>.
                      </span>
                    </li>
                  </ol>
                </div>
              ) : unsupported ? (
                <p>
                  Inštalácia momentálne nie je v tomto prehliadači dostupná. Skús to v prehliadači
                  Chrome alebo Edge, prípadne aplikáciu pridaj cez ponuku prehliadača.
                </p>
              ) : (
                <p>
                  Nainštaluj si POapp ako bežnú aplikáciu — budeš ho mať po ruke jedným ťuknutím
                  bez otvárania prehliadača.
                </p>
              )}
            </div>

            <Button
              type="button"
              disabled={!canInstall}
              loading={installing}
              onClick={() => deferred && handleInstall(deferred)}
              leftIcon={<Download className="size-4" />}
              className="shrink-0"
            >
              Inštalovať
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
}
