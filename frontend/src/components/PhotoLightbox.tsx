import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useDelayedMount } from '@/lib/useDelayedMount';

export type LightboxPhoto = { src: string; alt?: string };

/** How long the track takes to settle once the finger lets go. */
const SETTLE_MS = 300;
/** Drag distance that commits to the neighbouring photo instead of snapping back. */
const SWIPE_THRESHOLD_PX = 60;

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
    setState((s) => (s ? { ...s, index: (s.index + 1) % s.photos.length } : s));
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

/**
 * Photo viewer with a drag-following carousel.
 *
 * The photos sit on a track of three slides — previous, current, next —
 * pinned one viewport apart. Dragging offsets the whole track live, so the
 * photo tracks the finger (or cursor) instead of waiting for the gesture to
 * finish. Releasing either carries the track on to the neighbour or springs
 * it back, and buttons/keys/trackpad reuse that same settle so every route
 * to the next photo looks identical.
 */
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
  const stageRef = useRef<HTMLDivElement>(null);
  const dragStart = useRef<{ x: number; y: number } | null>(null);
  const settleTimer = useRef<number | undefined>(undefined);
  // A finished drag still ends in a click on the backdrop, whose handler
  // closes the lightbox — without this the swipe would dismiss what it just
  // navigated to.
  const suppressClick = useRef(false);

  /** Live horizontal offset of the track, in pixels. */
  const [dragX, setDragX] = useState(0);
  /** True while the track animates to its resting place after release. */
  const [settling, setSettling] = useState(false);

  const stageWidth = () => stageRef.current?.offsetWidth || window.innerWidth;

  /** Whether a settle is currently in flight, and what it owes on arrival. */
  const settleActive = useRef(false);
  const pendingCommit = useRef<(() => void) | null>(null);

  /**
   * End the in-flight settle now: swap the index and re-centre the track in
   * the same render, so the newly-centred photo lands exactly where the old
   * track position pointed and nothing visibly jumps.
   */
  const finishSettle = useCallback(() => {
    window.clearTimeout(settleTimer.current);
    settleActive.current = false;
    const commit = pendingCommit.current;
    pendingCommit.current = null;
    setSettling(false);
    setDragX(0);
    commit?.();
  }, []);

  /** Glide the track to `toX`, then hand over to `commit`. */
  const settleTo = useCallback(
    (toX: number, commit?: () => void) => {
      // A settle already running is completed rather than blocking the new
      // gesture — otherwise a quick second swipe would be silently dropped.
      if (settleActive.current) finishSettle();
      settleActive.current = true;
      pendingCommit.current = commit ?? null;
      setSettling(true);
      setDragX(toX);
      settleTimer.current = window.setTimeout(finishSettle, SETTLE_MS);
    },
    [finishSettle],
  );

  const navigate = useCallback(
    (direction: number) => {
      settleTo(-direction * stageWidth(), direction > 0 ? onNext : onPrev);
    },
    [settleTo, onNext, onPrev],
  );

  const onPointerDown = (e: React.PointerEvent) => {
    // Ignore right/middle mouse buttons; they don't start a swipe.
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    // Grabbing mid-settle snaps it to its destination so the drag starts from
    // a centred track rather than fighting the animation.
    if (settleActive.current) finishSettle();
    dragStart.current = { x: e.clientX, y: e.clientY };
    // Capture so the drag keeps tracking even if the pointer leaves the stage.
    e.currentTarget.setPointerCapture(e.pointerId);
  };

  const onPointerMove = (e: React.PointerEvent) => {
    // Guarded by the ref rather than state: the ref is set synchronously in
    // pointerdown, so the very first move is tracked without waiting for a
    // re-render to attach this handler.
    if (!dragStart.current) return;
    setDragX(e.clientX - dragStart.current.x);
  };

  const onPointerUp = (e: React.PointerEvent) => {
    const start = dragStart.current;
    dragStart.current = null;
    if (!start) return;
    const dx = e.clientX - start.x;
    const dy = e.clientY - start.y;
    // Any real horizontal travel means this was a drag, not a tap to close.
    if (Math.abs(dx) > 10) {
      suppressClick.current = true;
      setTimeout(() => {
        suppressClick.current = false;
      }, SETTLE_MS);
    }
    const isSwipe =
      Math.abs(dx) >= SWIPE_THRESHOLD_PX && Math.abs(dx) > Math.abs(dy);
    if (isSwipe) navigate(dx < 0 ? 1 : -1);
    else settleTo(0); // Short of the threshold — spring back to centre.
  };

  // Trackpad two-finger swipe arrives as horizontal wheel deltas rather than a
  // drag, so it can't follow the finger; it just triggers the same settle.
  const onWheel = (e: React.WheelEvent) => {
    if (Math.abs(e.deltaX) < Math.abs(e.deltaY) || Math.abs(e.deltaX) < 30)
      return;
    navigate(e.deltaX > 0 ? 1 : -1);
  };

  const onBackdropClick = () => {
    if (suppressClick.current) return;
    onClose();
  };

  // Leave the track centred and idle for the next open.
  useEffect(() => {
    if (state) return;
    window.clearTimeout(settleTimer.current);
    settleActive.current = false;
    pendingCommit.current = null;
    setDragX(0);
    setSettling(false);
  }, [state]);

  useEffect(() => () => window.clearTimeout(settleTimer.current), []);

  useEffect(() => {
    if (!state) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (e.key === 'ArrowRight') navigate(1);
      if (e.key === 'ArrowLeft') navigate(-1);
    };
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
  }, [state, onClose, navigate]);

  if (!mounted || !state) return null;
  const count = state.photos.length;
  const hasMultiple = count > 1;
  // Only the visible photo and its two neighbours are mounted — a 20-photo
  // protocol should not pull 20 full-size images over mobile data to show one.
  const offsets = hasMultiple ? [-1, 0, 1] : [0];

  return createPortal(
    <div
      className={cn(
        'fixed inset-0 z-50 bg-black/85 backdrop-blur-sm',
        'transition-opacity duration-200 ease-out',
        entered ? 'opacity-100' : 'opacity-0',
      )}
      onClick={onBackdropClick}
      role="dialog"
      aria-modal="true"
      aria-label="Zväčšená fotka"
    >
      {/* The track. Absolutely filling the overlay gives the slides a definite
          width for the 100% offsets to resolve against, and clips whatever is
          currently off to the side. */}
      <div
        ref={stageRef}
        className={cn(
          'absolute inset-0 overflow-hidden',
          // touch-none stops the browser claiming the horizontal gesture
          // before the pointer handlers see it; select-none keeps a mouse drag
          // from turning into a text selection mid-swipe.
          hasMultiple && 'touch-none select-none',
          'transition-[opacity,transform] duration-200 ease-out',
          entered ? 'scale-100 opacity-100' : 'scale-95 opacity-0',
        )}
        onPointerDown={hasMultiple ? onPointerDown : undefined}
        onPointerMove={hasMultiple ? onPointerMove : undefined}
        onPointerUp={hasMultiple ? onPointerUp : undefined}
        onPointerCancel={hasMultiple ? onPointerUp : undefined}
        onWheel={hasMultiple ? onWheel : undefined}
      >
        {offsets.map((offset) => {
          const photo =
            state.photos[(((state.index + offset) % count) + count) % count];
          return (
            <div
              key={offset}
              className={cn(
                'absolute inset-0 flex items-center justify-center p-4',
                'will-change-transform',
                // No transition while the finger is down, so the photo sits
                // exactly under it rather than lagging behind.
                settling && 'transition-transform duration-300 ease-out',
              )}
              style={{
                transform: `translate3d(calc(${dragX}px + ${offset * 100}%), 0, 0)`,
              }}
            >
              <img
                src={photo.src}
                alt={photo.alt ?? ''}
                // Native image dragging would swallow the swipe.
                draggable={false}
                onClick={(e) => e.stopPropagation()}
                className="max-h-[85vh] max-w-full rounded-2xl object-contain shadow-[var(--shadow-lift)]"
              />
            </div>
          );
        })}
      </div>

      <button
        type="button"
        onClick={onClose}
        aria-label="Zavrieť"
        className="absolute right-3 top-3 z-10 grid size-10 place-items-center rounded-full bg-white/10 text-white transition-colors duration-200 hover:bg-white/20"
      >
        <X className="size-5" />
      </button>

      {hasMultiple && (
        <>
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              navigate(-1);
            }}
            aria-label="Predchádzajúca fotka"
            className="absolute left-2 top-1/2 z-10 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white transition-colors duration-200 hover:bg-white/20 sm:left-4"
          >
            <ChevronLeft className="size-6" />
          </button>
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              navigate(1);
            }}
            aria-label="Ďalšia fotka"
            className="absolute right-2 top-1/2 z-10 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white transition-colors duration-200 hover:bg-white/20 sm:right-4"
          >
            <ChevronRight className="size-6" />
          </button>
        </>
      )}

      {hasMultiple && (
        <div className="absolute bottom-4 left-1/2 z-10 -translate-x-1/2 rounded-full bg-black/40 px-3 py-1 text-xs tabular-nums text-white">
          {state.index + 1} / {count}
        </div>
      )}
    </div>,
    document.body,
  );
}
