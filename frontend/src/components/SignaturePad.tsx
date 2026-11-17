import {
  forwardRef, useEffect, useImperativeHandle, useRef, useState,
} from 'react';
import { Eraser } from 'lucide-react';
import { cn } from '@/lib/cn';
import { mirror, splinePiece, type StrokePoint } from '@/lib/strokeSpline';

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

type Point = StrokePoint;

/** Stroke width in CSS pixels — reads like a ballpoint once the PNG is printed small. */
const LINE_WIDTH = 2.5;

/** Samples closer than this (CSS px) to the previous one add only jitter. */
const MIN_STEP = 0.75;

/** Browsers that deliver pointer samples as they arrive, not once per frame (Chromium). */
const HAS_RAW_UPDATE = typeof window !== 'undefined' && 'onpointerrawupdate' in window;

/**
 * Touchscreen + mouse signature capture. Single canvas with PointerEvent
 * handlers so touch and mouse share one code path. The element auto-
 * resizes for HiDPI displays so the exported PNG is sharp on retina.
 *
 * Every sample the browser has is used: `pointerrawupdate` where it exists
 * (samples as they arrive instead of one per frame), and the coalesced
 * events of each move. The line is a centripetal Catmull–Rom spline through
 * the samples ({@link splinePiece}), subdivided into chords of 2 px at most,
 * so a fast stroke whose samples are 20–40 px apart — or a browser without
 * coalesced events — still draws one smooth curve through every sample
 * rather than straight pieces. A piece is drawn once the sample after it is
 * known (its tangent needs it); the last piece is drawn on lift.
 */
export const SignaturePad = forwardRef<SignaturePadHandle, Props>(function SignaturePad(
  { className, heightPx = 180, color = '#1a1a1f', onEmptyChange },
  ref,
) {
  const wrapperRef = useRef<HTMLDivElement | null>(null);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const ctxRef = useRef<CanvasRenderingContext2D | null>(null);

  /** Pointer drawing the current stroke; a second finger is ignored. */
  const pointerRef = useRef<number | null>(null);
  /** The last (up to three) samples of the stroke in progress. */
  const pointsRef = useRef<Point[]>([]);
  /** Size the backing store was last laid out for. */
  const sizeRef = useRef<{ w: number; h: number; dpr: number } | null>(null);

  const [empty, setEmpty] = useState(true);
  const setEmptyAndNotify = (next: boolean) => {
    setEmpty((cur) => {
      if (cur !== next) onEmptyChange?.(next);
      return next;
    });
  };

  // Size the canvas backing store to the wrapper width and the device pixel
  // ratio. Resetting the backing store wipes what is drawn, so it happens
  // only when the drawable area really changed size — a ResizeObserver also
  // fires right after observe() and for changes that leave the size alone,
  // and those must not erase a signature in progress.
  useEffect(() => {
    const wrapper = wrapperRef.current;
    const canvas = canvasRef.current;
    if (!wrapper || !canvas) return;

    const resize = () => {
      const dpr = window.devicePixelRatio || 1;
      const cssWidth = wrapper.clientWidth;
      const cssHeight = heightPx;
      const prev = sizeRef.current;
      if (ctxRef.current && prev && prev.w === cssWidth && prev.h === cssHeight && prev.dpr === dpr) {
        return;
      }
      sizeRef.current = { w: cssWidth, h: cssHeight, dpr };
      canvas.style.width = cssWidth + 'px';
      canvas.style.height = cssHeight + 'px';
      canvas.width = Math.round(cssWidth * dpr);
      canvas.height = Math.round(cssHeight * dpr);
      const ctx = canvas.getContext('2d');
      if (!ctx) return;
      ctx.scale(dpr, dpr);
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.lineWidth = LINE_WIDTH;
      ctxRef.current = ctx;
      pointerRef.current = null;
      pointsRef.current = [];
      setEmptyAndNotify(true);
    };

    resize();
    const ro = new ResizeObserver(resize);
    ro.observe(wrapper);
    return () => ro.disconnect();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [heightPx]);

  function wipe() {
    const canvas = canvasRef.current;
    const ctx = ctxRef.current;
    if (!canvas || !ctx) return;
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.restore();
    pointerRef.current = null;
    pointsRef.current = [];
    setEmptyAndNotify(true);
  }

  function localPoint(ev: { clientX: number; clientY: number }, rect: DOMRect): Point {
    return { x: ev.clientX - rect.left, y: ev.clientY - rect.top };
  }

  /** Stroke the spline piece from `p1` to `p2`. */
  function drawPiece(p0: Point, p1: Point, p2: Point, p3: Point) {
    const ctx = ctxRef.current;
    if (!ctx) return;
    const path = splinePiece(p0, p1, p2, p3);
    ctx.beginPath();
    ctx.moveTo(path[0].x, path[0].y);
    for (let k = 1; k < path.length; k++) ctx.lineTo(path[k].x, path[k].y);
    ctx.stroke();
  }

  /**
   * Append one sample and draw the piece it completes: with samples
   * … a, b, c, p the piece b → c is drawn now, its tangent at c taken from p.
   */
  function addPoint(p: Point) {
    const pts = pointsRef.current;
    const last = pts[pts.length - 1];
    if (!ctxRef.current || !last) return;
    if (Math.hypot(p.x - last.x, p.y - last.y) < MIN_STEP) return;

    pts.push(p);
    const n = pts.length;
    if (n >= 3) {
      const b = pts[n - 3];
      const c = pts[n - 2];
      // The stroke's first piece has nothing before it: a phantom mirrors c over b.
      const a = n >= 4 ? pts[n - 4] : mirror(b, c);
      drawPiece(a, b, c, p);
    }
    if (pts.length > 3) pts.shift();
  }

  /** The samples of one pointer event: its coalesced ones when the browser has them. */
  function samplesOf(native: PointerEvent): PointerEvent[] {
    const coalesced = typeof native.getCoalescedEvents === 'function'
      ? native.getCoalescedEvents()
      : [];
    return coalesced.length > 0 ? coalesced : [native];
  }

  function takeSamples(native: PointerEvent, canvas: HTMLCanvasElement) {
    if (native.pointerId !== pointerRef.current) return;
    const rect = canvas.getBoundingClientRect();
    for (const s of samplesOf(native)) addPoint(localPoint(s, rect));
  }

  // Where the browser has pointerrawupdate, the samples come from it (as they
  // arrive) and pointermove is ignored, so no sample is taken twice. React has
  // no prop for the event, hence the native listener; the handler reads refs
  // only, through a ref that always points at the current render's function.
  const takeSamplesRef = useRef(takeSamples);
  takeSamplesRef.current = takeSamples;
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas || !HAS_RAW_UPDATE) return;
    const onRaw = (ev: Event) => takeSamplesRef.current(ev as PointerEvent, canvas);
    canvas.addEventListener('pointerrawupdate', onRaw);
    return () => canvas.removeEventListener('pointerrawupdate', onRaw);
  }, []);

  function onPointerDown(e: React.PointerEvent<HTMLCanvasElement>) {
    const ctx = ctxRef.current;
    if (!ctx || pointerRef.current !== null) return;
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    pointerRef.current = e.pointerId;

    const p = localPoint(e, e.currentTarget.getBoundingClientRect());
    pointsRef.current = [p];
    ctx.strokeStyle = color;
    ctx.fillStyle = color;
    // A tap leaves a dot the size of the line's round cap.
    ctx.beginPath();
    ctx.arc(p.x, p.y, LINE_WIDTH / 2, 0, Math.PI * 2);
    ctx.fill();
    setEmptyAndNotify(false);
  }

  function onPointerMove(e: React.PointerEvent<HTMLCanvasElement>) {
    if (HAS_RAW_UPDATE) return; // taken from pointerrawupdate instead
    takeSamples(e.nativeEvent, e.currentTarget);
  }

  function endStroke(e: React.PointerEvent<HTMLCanvasElement>) {
    if (e.pointerId !== pointerRef.current) return;
    const pts = pointsRef.current;
    const n = pts.length;
    if (ctxRef.current && n >= 2) {
      // Closing piece: up to where the pointer lifted, with a phantom after it.
      const b = pts[n - 2];
      const c = pts[n - 1];
      const a = n >= 3 ? pts[n - 3] : mirror(b, c);
      drawPiece(a, b, c, mirror(c, b));
    }
    pointerRef.current = null;
    pointsRef.current = [];
  }

  useImperativeHandle(ref, () => ({
    isEmpty: () => empty,
    clear: wipe,
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
        onLostPointerCapture={endStroke}
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
          onClick={wipe}
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
