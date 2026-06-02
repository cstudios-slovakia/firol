import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useDelayedMount } from '@/lib/useDelayedMount';

type DialogProps = {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children: React.ReactNode;
  /** Override the default `max-w-md` panel width. */
  maxWidthClassName?: string;
  /** Disable backdrop / escape-key dismissal (e.g. while submitting). */
  dismissible?: boolean;
};

/**
 * Lightweight modal dialog. Uses a fixed overlay rather than a portal so
 * it inherits the app's CSS context (theme variables, font) without any
 * extra wiring. Closes on backdrop click and on Escape; locks body
 * scroll while open so a long form doesn't scroll the underlying page.
 */
export function Dialog({
  open,
  onClose,
  title,
  description,
  children,
  maxWidthClassName = 'max-w-md',
  dismissible = true,
}: DialogProps) {
  const panelRef = useRef<HTMLDivElement | null>(null);
  const { mounted, entered } = useDelayedMount(open, 200);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && dismissible) onClose();
    };
    document.addEventListener('keydown', onKey);
    // Prevent the body from scrolling while the dialog is open. The
    // backdrop catches scrolls on its own.
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    // Move focus into the panel so screen readers announce it and
    // keyboard nav starts from a sensible place.
    panelRef.current?.focus();
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [open, dismissible, onClose]);

  if (!mounted) return null;

  return createPortal(
    <div
      className={cn(
        'fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4',
        // Backdrop fades in/out independently of the panel so we get the
        // dimmed-room feel without the panel popping in cold.
        'bg-ink-900/40 backdrop-blur-sm transition-opacity duration-200 ease-out',
        entered ? 'opacity-100' : 'opacity-0',
      )}
      onClick={dismissible ? onClose : undefined}
      aria-hidden={!open}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        // Stop click bubbling so clicking inside the panel doesn't dismiss.
        onClick={(e) => e.stopPropagation()}
        className={cn(
          'relative w-full overflow-hidden rounded-2xl bg-white shadow-[var(--shadow-lift)] outline-none',
          'transition-[opacity,transform] duration-200 ease-out',
          entered ? 'scale-100 opacity-100' : 'scale-95 opacity-0',
          maxWidthClassName,
        )}
      >
        <header className="flex items-start justify-between gap-3 border-b border-ink-100 px-5 py-4">
          <div className="min-w-0">
            <h2 className="text-base font-semibold text-ink-900">{title}</h2>
            {description && (
              <p className="mt-0.5 text-sm text-ink-500">{description}</p>
            )}
          </div>
          {dismissible && (
            <button
              type="button"
              onClick={onClose}
              aria-label="Zavrieť"
              className="grid size-9 shrink-0 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700"
            >
              <X className="size-4" />
            </button>
          )}
        </header>
        <div className="px-5 py-4">{children}</div>
      </div>
    </div>,
    document.body,
  );
}
