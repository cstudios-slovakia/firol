import { useState } from 'react';
import { Download, ShieldAlert, SkipForward } from 'lucide-react';
import { DataApi } from '@/api/data';
import { Button } from '@/components/ui/Button';

type Props = {
    onProceed: () => void;
    onCancel: () => void;
};

export function BackupReminderModal({ onProceed, onCancel }: Props) {
    const [downloading, setDownloading] = useState(false);
    const [downloaded, setDownloaded] = useState(false);

    function triggerDownload() {
        setDownloading(true);
        const a = document.createElement('a');
        a.href = DataApi.exportUrl();
        a.download = '';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        // Give the browser a moment to start the download
        setTimeout(() => {
            setDownloading(false);
            setDownloaded(true);
        }, 800);
    }

    function handleBackup() {
        triggerDownload();
        // Proceed immediately — the download runs in the background
        setTimeout(onProceed, 900);
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center sm:items-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="backup-reminder-title"
        >
            {/* Backdrop */}
            <div
                className="absolute inset-0 bg-ink-900/40 backdrop-blur-sm"
                onClick={onCancel}
            />

            {/* Panel */}
            <div className="relative w-full max-w-md animate-fade-up rounded-3xl border border-amber-200 bg-white shadow-2xl">
                {/* Header */}
                <div className="flex items-start gap-3 border-b border-amber-100 bg-gradient-to-br from-amber-50 to-transparent px-5 py-4 rounded-t-3xl">
                    <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-amber-100 text-amber-600">
                        <ShieldAlert className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1 pt-0.5">
                        <h2
                            id="backup-reminder-title"
                            className="text-base font-semibold text-ink-900"
                        >
                            Odporúčame zálohu pred pokračovaním
                        </h2>
                        <p className="mt-0.5 text-xs text-ink-500">
                            Táto operácia môže zmeniť alebo vymazať existujúce
                            dáta.
                        </p>
                    </div>
                </div>

                {/* Body */}
                <div className="px-5 py-4">
                    <p className="text-sm text-ink-700">
                        Stiahni si zálohu svojich dát — firmy, kontroly a
                        školenia ako JSON súbor. Ak sa niečo pokazí, vieme z
                        neho dáta obnoviť.
                    </p>
                    {downloaded && (
                        <p className="mt-2 text-xs font-medium text-emerald-700">
                            ✓ Záloha sa sťahuje — skontroluj priečinok Stiahnuté.
                        </p>
                    )}
                </div>

                {/* Actions */}
                <div className="flex flex-col gap-2 px-5 pb-5 sm:flex-row-reverse">
                    <Button
                        type="button"
                        onClick={handleBackup}
                        loading={downloading}
                        leftIcon={<Download className="size-4" />}
                        className="sm:flex-1"
                    >
                        Stiahnuť zálohu
                    </Button>
                    <button
                        type="button"
                        onClick={onProceed}
                        className="flex items-center justify-center gap-2 rounded-2xl border border-ink-200 bg-white px-4 py-2.5 text-sm font-semibold text-ink-600 transition-colors hover:bg-ink-50 sm:flex-1"
                    >
                        <SkipForward className="size-4" />
                        Preskočiť
                    </button>
                </div>
            </div>
        </div>
    );
}
