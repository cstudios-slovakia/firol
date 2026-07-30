import { useState } from 'react';
import { ExternalLink, ScrollText } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Button } from '@/components/ui/Button';

/**
 * "New version of the terms" notice (change request 3.1).
 *
 * Shown after login whenever the signed-in user's recorded consent doesn't
 * match the currently published version of the VOP and/or the privacy
 * policy — the two are versioned independently, so a revision to either one
 * (or an account that predates consent tracking) triggers it. Acknowledging
 * stores both current versions against the user, so the notice appears
 * exactly once per revision.
 *
 * Deliberately not a hard block: the VOP say a change takes effect after
 * 30 days' notice and that continued use constitutes agreement (čl. 11), so
 * the user is informed rather than locked out. Dismissing without
 * acknowledging leaves it to reappear on the next login.
 */
export function TermsUpdateNotice() {
  const { terms, acceptTerms } = useAuth();
  const [dismissed, setDismissed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!terms || !terms.needs_acceptance || dismissed) return null;

  const firstTime = terms.vop.accepted_version === null && terms.privacy.accepted_version === null;
  const outdatedDocs = [terms.vop, terms.privacy].filter((doc) => doc.needs_acceptance);

  async function handleAccept() {
    setSaving(true);
    setError(null);
    try {
      await acceptTerms();
    } catch {
      setError('Potvrdenie sa nepodarilo uložiť. Skús to znova.');
      setSaving(false);
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-labelledby="terms-update-title"
    >
      <div className="absolute inset-0 bg-ink-900/40 backdrop-blur-sm" />

      <div className="relative w-full max-w-md animate-fade-up rounded-3xl bg-white p-5 shadow-[var(--shadow-lift)]">
        <div className="flex items-start gap-3">
          <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
            <ScrollText className="size-5" />
          </span>
          <div className="min-w-0">
            <h2 id="terms-update-title" className="text-base font-semibold text-ink-900">
              {firstTime ? 'Obchodné podmienky' : 'Nová verzia dokumentov'}
            </h2>
            <p className="mt-1 text-sm text-ink-600">
              {firstTime
                ? 'Prosíme, oboznám sa s aktuálnymi dokumentmi a potvrď to.'
                : outdatedDocs.length > 1
                  ? 'Aktualizovali sme dokumenty nižšie. Prosíme, prečítaj si ich a potvrď oboznámenie.'
                  : 'Aktualizovali sme dokument nižšie. Prosíme, prečítaj si ho a potvrď oboznámenie.'}
            </p>
          </div>
        </div>

        <ul className="mt-4 flex flex-col gap-2">
          {outdatedDocs.map((doc) => (
            <LegalLink key={doc.url} href={doc.url} label={doc.label} />
          ))}
        </ul>

        {error && <p className="mt-3 text-xs text-status-bad">{error}</p>}

        <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
          <Button type="button" variant="ghost" onClick={() => setDismissed(true)} disabled={saving}>
            Neskôr
          </Button>
          <Button type="button" loading={saving} onClick={handleAccept}>
            Beriem na vedomie
          </Button>
        </div>
      </div>
    </div>
  );
}

function LegalLink({ href, label }: { href: string; label: string }) {
  return (
    <li>
      <a
        href={href}
        target="_blank"
        rel="noreferrer"
        className="flex items-center gap-2 rounded-xl border border-ink-200 px-3 py-2.5 text-sm font-medium text-ink-800 transition-all duration-200 hover:border-firol-300 hover:bg-firol-50 hover:text-firol-700"
      >
        <ExternalLink className="size-4 shrink-0 text-ink-400" />
        {label}
      </a>
    </li>
  );
}
