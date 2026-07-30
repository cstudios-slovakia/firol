/*
 * Published legal documents (change request 3.1).
 *
 * These are static pages under public/legal/, served straight from the
 * docroot — the registration form has to link to them before any session
 * exists, so the paths cannot come from /api/me.
 *
 * Keep in step with `Firol\Legal\Terms` on the backend, which holds the same
 * paths plus the version string stored with each user's recorded consent.
 * Signed-in screens should prefer the URLs from the `terms` block on /api/me;
 * these constants are the pre-auth fallback.
 */

export const LEGAL_VOP_URL = '/legal/vseobecne-obchodne-podmienky.html';
export const LEGAL_PRIVACY_URL = '/legal/zasady-ochrany-osobnych-udajov.html';

export const LEGAL_VOP_LABEL = 'Všeobecné obchodné podmienky';
export const LEGAL_PRIVACY_LABEL = 'Zásady ochrany osobných údajov';
