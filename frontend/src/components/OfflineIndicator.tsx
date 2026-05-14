import { useEffect, useRef, useState } from 'react';
import { CloudOff, Cloud, RotateCw, Trash2, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useOnlineStatus } from '@/lib/useOnlineStatus';
import { usePendingMutations } from '@/lib/usePendingMutations';
import { drainQueue, retryMutation, discardMutation } from '@/lib/queue';

/**
 * Chip in the AppShell header showing offline state + queued mutations.
 *
 * - Hidden entirely when online with an empty queue.
 * - Amber chip with count when there are pending/failed mutations.
 * - Red chip when offline (regardless of queue).
 *
 * Clicking opens a dropdown panel listing each queued mutation with
 * retry / discard buttons. Sync is auto-triggered on every `online`
 * event already (see lib/pwa.ts) so the manual retry button is mostly
 * for permanently-failed entries (4xx/5xx).
 */
export function OfflineIndicator() {
  const online = useOnlineStatus();
  const pending = usePendingMutations();
  const [open, setOpen] = useState(false);
  const wrapperRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    function onClick(e: MouseEvent) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  const hasQueue = pending.length > 0;
  const failedCount = pending.filter((m) => m.status === 'failed').length;
  if (online && !hasQueue) return null;

  const tone = online ? 'amber' : 'red';
  return (
    <div ref={wrapperRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={cn(
          'inline-flex items-center gap-1.5 rounded-2xl px-2.5 py-1.5 text-xs font-medium transition-colors',
          tone === 'red'
            ? 'bg-red-50 text-red-700 hover:bg-red-100'
            : 'bg-amber-50 text-amber-700 hover:bg-amber-100',
        )}
        aria-label={online ? 'Čakajúce zmeny' : 'Offline'}
      >
        {online ? <Cloud className="size-3.5" /> : <CloudOff className="size-3.5" />}
        <span>{online ? `${pending.length} čaká` : 'Offline'}</span>
        {failedCount > 0 && (
          <span className="ml-1 inline-flex size-4 items-center justify-center rounded-full bg-red-500 text-[10px] text-white">
            !
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-30 mt-2 w-80 rounded-2xl border border-ink-100 bg-white p-3 shadow-2xl">
          <div className="mb-2 flex items-center justify-between">
            <span className="text-sm font-semibold text-ink-900">
              {online ? 'Čakajúce zmeny' : 'Si offline'}
            </span>
            {hasQueue && (
              <button
                type="button"
                onClick={() => drainQueue().catch(() => undefined)}
                disabled={!online}
                className="text-xs font-medium text-firol-700 hover:text-firol-800 disabled:opacity-40"
              >
                Synchronizovať
              </button>
            )}
          </div>

          {!hasQueue && (
            <p className="text-xs leading-relaxed text-ink-500">
              Bez signálu sa zmeny uložia lokálne a odošlú sa keď budeš online. PDF a fakturácia
              vyžadujú pripojenie.
            </p>
          )}

          {hasQueue && (
            <ul className="flex max-h-80 flex-col gap-2 overflow-y-auto">
              {pending.map((m) => (
                <li
                  key={m.id}
                  className={cn(
                    'rounded-xl border px-3 py-2 text-xs',
                    m.status === 'failed'
                      ? 'border-red-200 bg-red-50'
                      : 'border-ink-100 bg-ink-50/50',
                  )}
                >
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1 font-medium text-ink-900">
                        {m.status === 'failed' && (
                          <AlertTriangle className="size-3.5 text-red-600" />
                        )}
                        <span className="truncate">{m.label}</span>
                      </div>
                      <div className="mt-0.5 text-[11px] text-ink-500">
                        {new Date(m.createdAt).toLocaleTimeString('sk', {
                          hour: '2-digit',
                          minute: '2-digit',
                        })}
                        {m.attempts > 0 && ` · ${m.attempts} pokus${m.attempts > 1 ? 'y' : ''}`}
                      </div>
                      {m.lastError && (
                        <div className="mt-1 text-[11px] text-red-700">{m.lastError}</div>
                      )}
                    </div>
                    <div className="flex shrink-0 gap-1">
                      <button
                        type="button"
                        onClick={() => retryMutation(m.id!).catch(() => undefined)}
                        disabled={!online}
                        className="grid size-7 place-items-center rounded-lg text-ink-500 hover:bg-white hover:text-ink-700 disabled:opacity-40"
                        aria-label="Opakovať"
                      >
                        <RotateCw className="size-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => discardMutation(m.id!).catch(() => undefined)}
                        className="grid size-7 place-items-center rounded-lg text-ink-500 hover:bg-white hover:text-red-600"
                        aria-label="Zahodiť"
                      >
                        <Trash2 className="size-3.5" />
                      </button>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
