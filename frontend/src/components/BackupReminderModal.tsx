import { useState } from 'react';
import { createPortal } from 'react-dom';
import { Download, ShieldAlert, SkipForward, Trash2 } from 'lucide-react';
import { DataApi } from '@/api/data';
import { Button } from '@/components/ui/Button';

type Props = {
    onProceed: () => void;
    onCancel: () => void;
    /**
     * Turns the reminder into the confirmation step itself: the user has to
     * type the keyword here before the action runs. Without it there is no
     * second confirmation box behind the modal to overlook.
     */
    confirm?: {
        keyword: string;
        /** Label of the confirming (destructive) button. */
        label: string;
        /** One-line reminder of what exactly gets deleted. */
        detail?: string;
        busy?: boolean;
    };
};

export function BackupReminderModal({ onProceed, onCancel, confirm }: Props) {
    const [downloading, setDownloading] = useState(false);
    const [downloaded, setDownloaded] = useState(false);
    const [keyword, setKeyword] = useState('');
    const keywordValid = !confirm || keyword.trim() === confirm.keyword;

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
        // With a keyword confirmation the user still has to type it, so the
        // modal stays open; otherwise proceed immediately — the download runs
        // in the background.
        if (!confirm) setTimeout(onProceed, 900);
    }

    return createPortal(
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
                        Stiahni si zálohu účtu — firmy, kontroly a školenia
                        vrátane fotiek a PDF protokolov, v jednom .zip archíve.
                        Ak sa niečo pokazí, obnovíš z neho účet v Nastavenia →
                        Správa dát.
                    </p>
                    {downloaded && (
                        <p className="mt-2 text-xs font-medium text-emerald-700">
                            ✓ Záloha sa sťahuje — skontroluj priečinok Stiahnuté.
                        </p>
                    )}

                    {confirm && (
                        <div className="mt-4 rounded-2xl border border-red-200 bg-red-50/50 px-4 py-3">
                            {confirm.detail && (
                                <p className="text-xs text-red-700/90">
                                    {confirm.detail}
                                </p>
                            )}
                            <label
                                htmlFor="backup-reminder-keyword"
                                className="mt-2 block text-xs text-ink-700"
                            >
                                Pre potvrdenie napíš{' '}
                                <span className="font-mono font-bold text-red-700">
                                    {confirm.keyword}
                                </span>{' '}
                                do poľa nižšie:
                            </label>
                            <input
                                id="backup-reminder-keyword"
                                type="text"
                                value={keyword}
                                onChange={(e) => setKeyword(e.target.value)}
                                placeholder={confirm.keyword}
                                autoComplete="off"
                                spellCheck={false}
                                className="mt-2 w-full rounded-xl border border-red-200 bg-white px-3 py-2 font-mono text-sm text-ink-900 outline-none transition-colors focus:border-red-400 focus:ring-2 focus:ring-red-200"
                            />
                        </div>
                    )}
                </div>

                {/* Actions */}
                {confirm ? (
                    // Three actions never fit on one row at this width — the
                    // destructive one gets its own full-width row.
                    <div className="flex flex-col gap-2 px-5 pb-5">
                        <Button
                            type="button"
                            variant="danger"
                            disabled={!keywordValid}
                            loading={confirm.busy}
                            onClick={() => {
                                if (keywordValid) onProceed();
                            }}
                            leftIcon={<Trash2 className="size-4" />}
                            className="w-full"
                        >
                            {confirm.label}
                        </Button>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={handleBackup}
                                loading={downloading}
                                leftIcon={<Download className="size-4" />}
                                className="min-w-0 flex-1"
                            >
                                Stiahnuť zálohu
                            </Button>
                            <button
                                type="button"
                                onClick={onCancel}
                                className="min-w-0 flex-1 rounded-2xl px-4 py-2.5 text-sm font-semibold text-ink-600 transition-colors hover:bg-ink-100"
                            >
                                Zrušiť
                            </button>
                        </div>
                    </div>
                ) : (
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
                )}
            </div>
        </div>,
        document.body,
    );
}
