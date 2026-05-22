import { useEffect, useState, type FormEvent } from 'react';
import { useLocation } from 'react-router-dom';
import { Bug, Lightbulb, MessageSquarePlus, Send } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Feedback, type FeedbackKind } from '@/api/feedback';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Dialog } from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { cn } from '@/lib/cn';

/**
 * Persistent, app-wide entry point for bug reports and feature requests.
 *
 * Renders a discreet tab anchored to the right edge of the viewport (mid-
 * height on desktop, just above the bottom tab bar on mobile). The tab
 * sits with only its icon protruding; on hover/focus it slides out to
 * reveal the label. Clicking it opens a modal form that submits via the
 * Feedback API together with the current location.
 */
export function FeedbackFloater() {
  const [open, setOpen] = useState(false);
  const location = useLocation();

  return (
    <>
      <FloatingTab onClick={() => setOpen(true)} />
      {open && (
        <FeedbackDialog
          onClose={() => setOpen(false)}
          sourceUrl={window.location.origin + location.pathname + location.search}
        />
      )}
    </>
  );
}

function FloatingTab({ onClick }: { onClick: () => void }) {
  // Initial nudge — slide fully out for ~1.6s on first mount so the user
  // sees the affordance, then collapse to just the icon. After that it
  // only re-expands on hover/focus.
  const [nudge, setNudge] = useState(true);
  useEffect(() => {
    const t = setTimeout(() => setNudge(false), 1600);
    return () => clearTimeout(t);
  }, []);

  return (
    // Positioning wrapper keeps the layout transforms (vertical centering on
    // desktop) separate from the sliding transform on the inner button, so
    // they don't clobber each other.
    <div
      className={cn(
        'fixed right-0 z-30',
        'bottom-24 sm:bottom-auto sm:top-1/2 sm:-translate-y-1/2',
      )}
    >
      <button
        type="button"
        onClick={onClick}
        aria-label="Nahlásiť chybu alebo navrhnúť funkciu"
        className={cn(
          // Fixed width keeps the collapsed-state translate constant — no
          // calc(), no layout-dependent transform. Sliver of the leading
          // icon stays visible (~40px) when parked off-screen.
          'group flex w-[180px] items-center gap-2 rounded-l-2xl bg-firol-500 text-white',
          'pl-3 pr-3 py-2.5 text-xs font-semibold whitespace-nowrap',
          'shadow-[var(--shadow-lift)] outline-none will-change-transform',
          'transition-transform duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]',
          'hover:translate-x-0 focus-visible:translate-x-0',
          'focus-visible:ring-2 focus-visible:ring-firol-300',
          nudge ? 'translate-x-0' : 'translate-x-[140px]',
        )}
      >
        <MessageSquarePlus className="size-5 shrink-0" />
        <span>Spätná väzba</span>
      </button>
    </div>
  );
}

export function FeedbackDialog({
  onClose,
  sourceUrl,
}: {
  onClose: () => void;
  sourceUrl: string;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [kind, setKind] = useState<FeedbackKind>('bug');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (message.trim() === '') {
      setError('Napíš prosím správu.');
      return;
    }
    setError(null);
    setBusy(true);
    try {
      await Feedback.submit(
        { kind, message: message.trim(), source_url: sourceUrl },
        csrfToken,
      );
      toast.success('Ďakujeme! Tvoja správa bola odoslaná.');
      onClose();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Odoslanie zlyhalo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog
      open
      onClose={busy ? () => undefined : onClose}
      title="Spätná väzba"
      description="Nahlás chybu alebo nám napíš, čo by ti v aplikácii pomohlo."
      dismissible={!busy}
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
        <div>
          <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-ink-500">
            Typ
          </span>
          <div className="grid grid-cols-2 gap-2">
            <KindOption
              active={kind === 'bug'}
              onClick={() => setKind('bug')}
              icon={<Bug className="size-4" />}
              label="Chyba"
              tone="rose"
            />
            <KindOption
              active={kind === 'feature'}
              onClick={() => setKind('feature')}
              icon={<Lightbulb className="size-4" />}
              label="Návrh funkcie"
              tone="amber"
            />
          </div>
        </div>

        <Field
          label="Správa"
          hint={`Pripojí sa aj aktuálna stránka: ${sourceUrl}`}
        >
          {(p) => (
            <textarea
              {...p}
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              rows={5}
              maxLength={5000}
              placeholder={
                kind === 'bug'
                  ? 'Čo si robil(a) a čo sa stalo namiesto očakávaného…'
                  : 'Akú funkciu by si chcel(a) pridať a prečo?'
              }
              className="w-full resize-y rounded-2xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-900 placeholder:text-ink-400 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-400/20 transition-colors"
            />
          )}
        </Field>

        {error && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </div>
        )}

        <div className="flex items-center justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose} disabled={busy}>
            Zrušiť
          </Button>
          <Button type="submit" loading={busy} leftIcon={<Send className="size-4" />}>
            Odoslať
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function KindOption({
  active, onClick, icon, label, tone,
}: {
  active: boolean;
  onClick: () => void;
  icon: React.ReactNode;
  label: string;
  tone: 'rose' | 'amber';
}) {
  const toneClasses = tone === 'rose'
    ? 'border-rose-300 bg-rose-50 text-rose-700'
    : 'border-amber-300 bg-amber-50 text-amber-700';
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'flex items-center justify-center gap-2 rounded-2xl border px-3 py-2.5 text-sm font-medium transition-all',
        active
          ? toneClasses
          : 'border-ink-200 bg-white text-ink-600 hover:bg-ink-50',
      )}
    >
      {icon}
      <span>{label}</span>
    </button>
  );
}
