import { useEffect, useRef } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Hash } from 'lucide-react';
import {
  CERT_FOCUS_PARAM,
  certReturnOf,
  isCertKey,
  type CertKey,
  type CertReturnState,
} from '@/lib/certMissing';

/** Names as the settings pages title each number. */
export const CERT_LABELS: Record<CertKey, string> = {
  cert_php: 'Kontrola PHP',
  cert_oprava: 'Oprava / plnenie / TS PHP',
  cert_general: 'Technik PO',
  cert_bt: 'Bezpečnostný technik',
  vv: 'Výchova a vzdelávanie',
};

/**
 * What a cert-missing redirect asked of a settings page: the number to
 * highlight (`?doplnit=`) and the screen to lead back to.
 */
export function useCertFocus(): { focus: CertKey | null; back: CertReturnState | null } {
  const [params] = useSearchParams();
  const location = useLocation();
  const raw = params.get(CERT_FOCUS_PARAM);
  return { focus: isCertKey(raw) ? raw : null, back: certReturnOf(location.state) };
}

/**
 * Scrolls the highlighted block into view and puts the cursor in its number
 * field once the page has rendered it.
 */
export function useCertFocusScroll<T extends HTMLElement>(active: boolean) {
  const blockRef = useRef<T>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (!active) return;
    blockRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    // After the smooth scroll has settled — focusing first would jump.
    const t = window.setTimeout(() => inputRef.current?.focus({ preventScroll: true }), 450);
    return () => window.clearTimeout(t);
  }, [active]);
  return { blockRef, inputRef };
}

/** Ring that marks the block the user was sent to fill in. */
export const CERT_HIGHLIGHT_CLASS =
  'ring-2 ring-firol-500 ring-offset-2 ring-offset-white shadow-[var(--shadow-glow)]';

/** Top-of-page note: what to do here, and the way back. */
export function CertFocusBanner({ cert, back }: { cert: CertKey; back: CertReturnState | null }) {
  return (
    <div className="animate-fade-up flex flex-col gap-2.5 rounded-2xl border border-firol-200 bg-firol-50/70 px-4 py-3 sm:flex-row sm:items-center">
      <span className="grid size-8 shrink-0 place-items-center rounded-xl bg-firol-500 text-white">
        <Hash className="size-4" />
      </span>
      <p className="min-w-0 flex-1 text-sm text-ink-700">
        Doplň zvýraznené číslo oprávnenia <strong className="font-semibold">{CERT_LABELS[cert]}</strong> a ulož.
      </p>
      {back && (
        <Link
          to={back.returnTo}
          className="inline-flex shrink-0 items-center gap-1.5 self-start rounded-xl px-3 py-2 text-sm font-semibold text-firol-600 transition-all duration-200 hover:bg-firol-100 active:scale-[0.97] sm:self-auto"
        >
          <ArrowLeft className="size-4" />
          {back.returnLabel}
        </Link>
      )}
    </div>
  );
}
