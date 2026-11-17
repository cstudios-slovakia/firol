/**
 * Second half of `npm run build:safe`: move a finished build from `dist-next/`
 * into place as `dist/` (the document root on the servers).
 *
 * A build the host kills half-way (out of memory) must not leave `dist/`
 * empty — that takes the site down. `build:safe` therefore builds into
 * `dist-next/` and only this script, which runs after Vite has exited with
 * success, swaps it in: two renames on the same disk, so the site is without
 * a document root for a moment only, never for the length of a build. The
 * previous build is kept as `dist-prev/` until the next swap, for a quick
 * manual rollback.
 */
import { existsSync, renameSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const next = join(root, 'dist-next');
const live = join(root, 'dist');
const prev = join(root, 'dist-prev');

if (!existsSync(join(next, 'index.html'))) {
  console.error('swap-dist: dist-next/index.html is missing — the build did not finish; dist/ left as it was.');
  process.exit(1);
}

/**
 * A rename, retried for a while: on Windows a folder Vite has just written is
 * often held open for a moment (indexer, antivirus) and the rename fails with
 * EPERM / EBUSY. On Linux the first try succeeds.
 */
function renameRetrying(from, to) {
  for (let attempt = 1; ; attempt++) {
    try {
      renameSync(from, to);
      return;
    } catch (err) {
      if (attempt >= 20 || !['EPERM', 'EBUSY', 'EACCES'].includes(err.code)) throw err;
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 250);
    }
  }
}

rmSync(prev, { recursive: true, force: true });
const hadLive = existsSync(live);
if (hadLive) renameRetrying(live, prev);
try {
  renameRetrying(next, live);
} catch (err) {
  // Never leave the site without a document root: put the old build back.
  if (hadLive && !existsSync(live)) renameRetrying(prev, live);
  console.error(`swap-dist: could not move dist-next/ into place (${err.code ?? err.message}); dist/ left as it was.`);
  process.exit(1);
}
console.log('swap-dist: the new build is live in dist/ (the previous one is in dist-prev/).');
