/**
 * Smooth pen strokes through sparse samples — the signature pad's curve.
 *
 * A finger moving fast on a phone can deliver samples 20–40 px apart,
 * especially in a browser without coalesced pointer events. Drawing straight
 * pieces (or a curve that only uses the samples as control points) between
 * them shows as a line broken into segments. This module draws a centripetal
 * Catmull–Rom spline instead: the curve passes THROUGH every sample, keeps a
 * continuous tangent from one piece to the next, and the centripetal variant
 * (alpha = 0.5) neither overshoots nor loops when the samples are unevenly
 * spaced. Each piece is subdivided so that no straight chord on screen is
 * longer than {@link MAX_CHORD} CSS pixels.
 */
export type StrokePoint = { x: number; y: number };

/** Longest straight chord drawn for one subdivision step, in CSS px. */
export const MAX_CHORD = 2;

/** Upper bound of subdivision steps for one piece (a 96 px gap at 2 px). */
const MAX_STEPS = 48;

/** Centripetal parameterisation. */
const ALPHA = 0.5;

function dist(a: StrokePoint, b: StrokePoint): number {
  return Math.hypot(b.x - a.x, b.y - a.y);
}

/** The phantom point before `a` when the stroke has no earlier sample: `a` mirrored over `b`. */
export function mirror(a: StrokePoint, b: StrokePoint): StrokePoint {
  return { x: 2 * a.x - b.x, y: 2 * a.y - b.y };
}

function lerp(a: StrokePoint, b: StrokePoint, ta: number, tb: number, t: number): StrokePoint {
  const span = tb - ta;
  const u = span === 0 ? 0 : (t - ta) / span;
  return { x: a.x + (b.x - a.x) * u, y: a.y + (b.y - a.y) * u };
}

/**
 * Points on the piece of the spline between `p1` and `p2`, with `p0` and `p3`
 * the neighbouring samples (or phantoms). The first point is `p1` itself,
 * the last `p2`; consecutive points are at most about {@link MAX_CHORD} apart.
 */
export function splinePiece(
  p0: StrokePoint,
  p1: StrokePoint,
  p2: StrokePoint,
  p3: StrokePoint,
): StrokePoint[] {
  const span = dist(p1, p2);
  const steps = Math.max(1, Math.min(MAX_STEPS, Math.ceil(span / MAX_CHORD)));
  // Knot spacing; a zero gap (a repeated sample) is nudged so no division is by zero.
  const knot = (a: StrokePoint, b: StrokePoint) => Math.max(Math.pow(dist(a, b), ALPHA), 1e-4);
  const t0 = 0;
  const t1 = t0 + knot(p0, p1);
  const t2 = t1 + knot(p1, p2);
  const t3 = t2 + knot(p2, p3);

  const out: StrokePoint[] = [{ x: p1.x, y: p1.y }];
  for (let k = 1; k < steps; k++) {
    // Barry–Goldman pyramid evaluation of the centripetal Catmull–Rom piece.
    const t = t1 + ((t2 - t1) * k) / steps;
    const a1 = lerp(p0, p1, t0, t1, t);
    const a2 = lerp(p1, p2, t1, t2, t);
    const a3 = lerp(p2, p3, t2, t3, t);
    const b1 = lerp(a1, a2, t0, t2, t);
    const b2 = lerp(a2, a3, t1, t3, t);
    out.push(lerp(b1, b2, t1, t2, t));
  }
  out.push({ x: p2.x, y: p2.y });
  return out;
}
