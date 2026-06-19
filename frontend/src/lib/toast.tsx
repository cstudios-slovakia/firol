import {
  createContext, useCallback, useContext, useEffect, useMemo, useRef, useState,
} from 'react';
import { CheckCircle2, XCircle } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useDelayedMount } from '@/lib/useDelayedMount';

type ToastTone = 'success' | 'error';

type ToastItem = {
  id: number;
  tone: ToastTone;
  message: string;
};

type ToastApi = {
  success: (message: string) => void;
  error: (message: string) => void;
};

const ToastContext = createContext<ToastApi | null>(null);

const TOAST_DURATION_MS = 4000;

/**
 * Bottom-left transient notification system. Used across the app from
 * any save handler to confirm whether the action succeeded or not. The
 * actual error message comes from the catch-block's `ApiError.message`
 * so users see something meaningful, not a generic "failed".
 */
export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  // Stable id generator scoped to the provider instance.
  const counter = useRef(0);

  const dismiss = useCallback((id: number) => {
    setItems((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const push = useCallback((tone: ToastTone, message: string) => {
    counter.current += 1;
    const id = counter.current;
    setItems((prev) => [...prev, { id, tone, message }]);
    window.setTimeout(() => dismiss(id), TOAST_DURATION_MS);
  }, [dismiss]);

  const api = useMemo<ToastApi>(() => ({
    success: (m) => push('success', m),
    error:   (m) => push('error',   m),
  }), [push]);

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div
        // Bottom-left so it doesn't collide with the bottom-right CTAs
        // (e.g. "Uložiť" buttons in forms align to the right). z-index sits
        // above modal overlays (all z-50) so an error toast triggered from
        // inside a dialog stays visible instead of hiding behind its blurred
        // backdrop.
        className="pointer-events-none fixed bottom-6 left-4 right-4 z-[60] flex max-w-md flex-col gap-3 sm:left-6 sm:right-auto sm:w-full"
        role="region"
        aria-label="Oznámenia"
      >
        {items.map((t) => (
          <ToastView key={t.id} item={t} onDismiss={() => dismiss(t.id)} />
        ))}
      </div>
    </ToastContext.Provider>
  );
}

function ToastView({ item, onDismiss }: { item: ToastItem; onDismiss: () => void }) {
  const [open, setOpen] = useState(true);
  const { mounted, entered } = useDelayedMount(open, 200);

  // Schedule the leave animation a beat before the actual unmount so the
  // user sees the toast slide out instead of just popping.
  useEffect(() => {
    const t = window.setTimeout(() => setOpen(false), TOAST_DURATION_MS - 220);
    return () => window.clearTimeout(t);
  }, []);

  // After the leave animation finishes, ask the provider to drop us.
  useEffect(() => {
    if (!mounted && !open) onDismiss();
  }, [mounted, open, onDismiss]);

  if (!mounted) return null;

  const isOk = item.tone === 'success';
  return (
    <div
      role={isOk ? 'status' : 'alert'}
      className={cn(
        'pointer-events-auto flex items-center gap-3.5 rounded-2xl border-2 px-5 py-4 shadow-[0_18px_40px_-12px_rgba(0,0,0,0.25),0_8px_16px_-8px_rgba(0,0,0,0.15)]',
        'transition-[opacity,transform] duration-200 ease-out',
        entered ? 'translate-x-0 opacity-100' : '-translate-x-4 opacity-0',
        isOk
          ? 'border-[hsl(140_50%_55%)] bg-[hsl(140_60%_97%)] text-[hsl(140_55%_22%)]'
          : 'border-[hsl(0_70%_60%)] bg-[hsl(0_70%_97%)] text-[hsl(0_65%_32%)]',
      )}
    >
      <span
        className={cn(
          'grid size-9 shrink-0 place-items-center rounded-full text-white',
          isOk ? 'bg-[hsl(140_55%_42%)]' : 'bg-[hsl(0_70%_52%)]',
        )}
      >
        {isOk ? <CheckCircle2 className="size-5" /> : <XCircle className="size-5" />}
      </span>
      <span className="text-base font-medium leading-snug">{item.message}</span>
    </div>
  );
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext);
  if (!ctx) {
    throw new Error('useToast must be used inside <ToastProvider>');
  }
  return ctx;
}
