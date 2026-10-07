let locks = 0;
let original = '';

/**
 * Reference-counted body scroll lock. Overlapping dialogs each take a lock;
 * the original `overflow` value is saved by the first one and restored only
 * when the last one is released, so closing in any order can't leave the page
 * stuck at `hidden`. The returned release function is idempotent.
 */
export function lockScroll(): () => void {
  if (locks === 0) {
    original = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }
  locks += 1;

  let released = false;
  return () => {
    if (released) return;
    released = true;
    locks -= 1;
    if (locks === 0) document.body.style.overflow = original;
  };
}
