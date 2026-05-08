import {
  forwardRef, useEffect, useImperativeHandle, useRef, useState,
} from 'react';
import { Eraser } from 'lucide-react';
import { cn } from '@/lib/cn';

export type SignaturePadHandle = {
  /** True when the user has actually drawn something. */
  isEmpty: () => boolean;
  /** Wipe the canvas back to blank. */
  clear: () => void;
  /**
   * Export current strokes as a transparent-background PNG. Resolves to
   * null if the canvas is still blank (so callers can validate before
   * sending a meaningless signature).
   */
  toBlob: () => Promise<Blob | null>;
};

type Props = {
  className?: string;
  /** Pixel height of the drawable area; width fills the parent. */
  heightPx?: number;
  /** Stroke colour — defaults to ink-900. */
  color?: string;
  /** Optional callback when the empty/non-empty state flips. */
  onEmptyChange?: (empty: boolean) => void;
};

/**
 * Touchscreen + mouse signature capture. Single canvas with PointerEvent
 * handlers so touch and mouse share one code path. The element auto-
 * resizes for HiDPI displays so the exported PNG is sharp on retina.
 *
 * Strokes are drawn with quadratic curves between samples, which gives a
 * natural pen feel even on slow trackpads where samples come in chunky.
 */
export const SignaturePad = forwardRef<SignaturePadHandle, Props>(function SignaturePad(
  { className, heightPx = 180, color = '#1a1a1f', onEmptyChange },
  ref,
) {
  const wrapperRef = useRef<HTMLDivElement | null>(null);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const ctxRef = useRef<CanvasRenderingContext2D | null>(null);

  const drawingRef = useRef(false);
  const lastPointRef = useRef<{ x: number; y: number } | null>(null);
  const dprRef = useRef(1);

  const [empty, setEmpty] = useState(true);
  const setEmptyAndNotify = (next: boolean) => {
    setEmpty((cur) => {
      if (cur !== next) onEmptyChange?.(next);
      return next;
    });
  };

  // Re-size the canvas backing store to match the wrapper width and the
  // device pixel ratio. Called on mount and on window resize. We
  // intentionally re-paint as blank on resize; preserving strokes across
  // a layout change would require sampling and re-stroking the path.
  useEffect(() => {
    const wrapper = wrapperRef.current;
    const canvas = canvasRef.current;
    if (!wrapper || !canvas) return;

    const resize = () => {
      const dpr = window.devicePixelRatio || 1;
      dprRef.current = dpr;
      const cssWidth = wrapper.clientWidth;
      const cssHeight = heightPx;
      canvas.style.width = cssWidth + 'px';
      canvas.style.height = cssHeight + 'px';
      canvas.width = Math.round(cssWidth * dpr);
      canvas.height = Math.round(cssHeight * dpr);
      const ctx = canvas.getContext('2d');
      if (!ctx) return;
      ctx.scale(dpr, dpr);
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.lineWidth = 2;
      ctx.strokeStyle = color;
      ctxRef.current = ctx;
      setEmptyAndNotify(true);
    };

    resize();
    const ro = new ResizeObserver(resize);
    ro.observe(wrapper);
    return () => ro.disconnect();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [heightPx, color]);

  function localPoint(e: React.PointerEvent<HTMLCanvasElement>): { x: number; y: number } {
    const canvas = canvasRef.current!;
    const rect = canvas.getBoundingClientRect();
    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
  }

  function onPointerDown(e: React.PointerEvent<HTMLCanvasElement>) {
    if (!ctxRef.current) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    drawingRef.current = true;
    const p = localPoint(e);
    lastPointRef.current = p;
    // Dot tap leaves a small mark even without movement.
    const ctx = ctxRef.current;
    ctx.beginPath();
    ctx.arc(p.x, p.y, 1, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.fill();
    setEmptyAndNotify(false);
  }

  function onPointerMove(e: React.PointerEvent<HTMLCanvasElement>) {
    if (!drawingRef.current || !ctxRef.current || !lastPointRef.current) return;
    const ctx = ctxRef.current;
    const p = localPoint(e);
    const last = lastPointRef.current;
    // Quadratic curve for natural-feeling stroke; the midpoint becomes
    // the next anchor so the curve threads smoothly through samples.
    const midX = (last.x + p.x) / 2;
    const midY = (last.y + p.y) / 2;
    ctx.beginPath();
    ctx.moveTo(last.x, last.y);
    ctx.quadraticCurveTo(last.x, last.y, midX, midY);
    ctx.stroke();
    lastPointRef.current = p;
  }

  function endStroke() {
    drawingRef.current = false;
    lastPointRef.current = null;
  }

  useImperativeHandle(ref, () => ({
    isEmpty: () => empty,
    clear: () => {
      const canvas = canvasRef.current;
      const ctx = ctxRef.current;
      if (!canvas || !ctx) return;
      ctx.save();
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.restore();
      setEmptyAndNotify(true);
    },
    toBlob: async () => {
      const canvas = canvasRef.current;
      if (!canvas || empty) return null;
      return await new Promise<Blob | null>((resolve) => {
        canvas.toBlob((b) => resolve(b), 'image/png');
      });
    },
  }), [empty]);

  return (
    <div ref={wrapperRef} className={cn('relative w-full', className)}>
      <canvas
        ref={canvasRef}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={endStroke}
        onPointerCancel={endStroke}
        onPointerLeave={endStroke}
        // touch-none prevents the browser from interpreting touches as
        // scroll/zoom while the user is drawing.
        className="block w-full rounded-2xl border border-ink-200 bg-white touch-none"
      />
      {empty && (
        <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-xs text-ink-400">
          <span className="rounded-full bg-white/70 px-2 py-0.5 backdrop-blur">
            Podpíšte sa na obrazovku
          </span>
        </div>
      )}
      <div className="absolute right-2 top-2">
        <button
          type="button"
          onClick={() => {
            const canvas = canvasRef.current;
            const ctx = ctxRef.current;
            if (!canvas || !ctx) return;
            ctx.save();
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.restore();
            setEmptyAndNotify(true);
          }}
          aria-label="Vyčistiť"
          title="Vyčistiť"
          className="grid size-8 place-items-center rounded-full bg-white/85 text-ink-500 shadow-sm backdrop-blur transition-colors hover:bg-white hover:text-ink-700"
        >
          <Eraser className="size-4" />
        </button>
      </div>
    </div>
  );
});
