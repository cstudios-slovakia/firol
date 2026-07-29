import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useDelayedMount } from '@/lib/useDelayedMount';

export type LightboxPhoto = { src: string; alt?: string };

type LightboxState = { photos: LightboxPhoto[]; index: number };

/**
 * Owns which photo (if any) is currently expanded for one thumbnail group.
 * Kept per-group rather than global so the summary screen's many items each
 * navigate only their own photos, never spilling into a neighbor's set.
 */
export function usePhotoLightbox() {
  const [state, setState] = useState<LightboxState | null>(null);

  const openAt = useCallback((photos: LightboxPhoto[], index: number) => {
    setState({ photos, index });
  }, []);
  const close = useCallback(() => setState(null), []);
  const next = useCallback(() => {
    setState((s) =>
      s ? { ...s, index: (s.index + 1) % s.photos.length } : s,
    );
  }, []);
  const prev = useCallback(() => {
    setState((s) =>
      s
        ? { ...s, index: (s.index - 1 + s.photos.length) % s.photos.length }
        : s,
    );
  }, []);

  return { state, openAt, close, next, prev };
}

export function PhotoLightbox({
  state,
  onClose,
  onNext,
  onPrev,
}: {
  state: LightboxState | null;
  onClose: () => void;
  onNext: () => void;
  onPrev: () => void;
}) {
  const { mounted, entered } = useDelayedMount(state !== null, 200);
  const touchStart = useRef<{ x: number; y: number } | null>(null);

  const onTouchStart = (e: React.TouchEvent) => {
    const t = e.touches[0];
    touchStart.current = { x: t.clientX, y: t.clientY };
  };

  const onTouchEnd = (e: React.TouchEvent) => {
    const start = touchStart.current;
    touchStart.current = null;
    if (!start) return;
    const t = e.changedTouches[0];
    const dx = t.clientX - start.x;
    const dy = t.clientY - start.y;
    // Require a mostly-horizontal drag so a vertical scroll/tap never gets
    // misread as a swipe.
    if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
    if (dx < 0) onNext();
    else onPrev();
  };

  useEffect(() => {
    if (!state) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (e.key === 'ArrowRight') onNext();
      if (e.key === 'ArrowLeft') onPrev();
    };
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
  }, [state, onClose, onNext, onPrev]);

  if (!mounted || !state) return null;
  const photo = state.photos[state.index];
  const hasMultiple = state.photos.length > 1;

  return createPortal(
    <div
      className={cn(
        'fixed inset-0 z-50 grid place-items-center bg-black/85 p-4 backdrop-blur-sm',
        'transition-opacity duration-200 ease-out',
        entered ? 'opacity-100' : 'opacity-0',
      )}
      onClick={onClose}
      onTouchStart={hasMultiple ? onTouchStart : undefined}
      onTouchEnd={hasMultiple ? onTouchEnd : undefined}
      role="dialog"
      aria-modal="true"
      aria-label="Zväčšená fotka"
    >
      <button
        type="button"
        onClick={onClose}
        aria-label="Zavrieť"
        className="absolute right-3 top-3 grid size-10 place-items-center rounded-full bg-white/10 text-white transition-colors duration-200 hover:bg-white/20"
      >
        <X className="size-5" />
      </button>

      {hasMultiple && (
        <>
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onPrev();
            }}
            aria-label="Predchádzajúca fotka"
            className="absolute left-2 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white transition-colors duration-200 hover:bg-white/20 sm:left-4"
          >
            <ChevronLeft className="size-6" />
          </button>
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onNext();
            }}
            aria-label="Ďalšia fotka"
            className="absolute right-2 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white transition-colors duration-200 hover:bg-white/20 sm:right-4"
          >
            <ChevronRight className="size-6" />
          </button>
        </>
      )}

      <img
        key={photo.src}
        src={photo.src}
        alt={photo.alt ?? ''}
        onClick={(e) => e.stopPropagation()}
        className={cn(
          'max-h-[85vh] max-w-full rounded-2xl object-contain shadow-[var(--shadow-lift)]',
          'transition-[opacity,transform] duration-200 ease-out',
          entered ? 'scale-100 opacity-100' : 'scale-95 opacity-0',
        )}
      />

      {hasMultiple && (
        <div className="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full bg-black/40 px-3 py-1 text-xs tabular-nums text-white">
          {state.index + 1} / {state.photos.length}
        </div>
      )}
    </div>,
    document.body,
  );
}
