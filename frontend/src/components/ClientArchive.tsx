import { useEffect, useState } from 'react';
import { Archive, ArchiveRestore } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies } from '@/api/companies';
import { Facilities } from '@/api/facilities';
import { offlineMessage } from '@/lib/offline';
import { useConfirm } from '@/lib/confirm';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';

/**
 * Spec 25 — archivácia firmy a prevádzky.
 *
 * A firma or prevádzka is never deleted from the app: its protocols have to be
 * kept after the client leaves. Archiving hides it from the lists, the
 * calendar, the časová os and Dnes, closes its open úlohy as „zrušené" and
 * stops the client notices; restoring brings it back with termíny computed
 * afresh from the last control. Both sit under the members' switch, so the
 * callers render these only when `useMemberRights().canDelete`.
 */

type Kind = 'company' | 'facility';

const COPY: Record<Kind, { title: string; what: string; done: string; failed: string }> = {
  company: {
    title: 'Archivovať firmu?',
    what: 'Firma aj všetky jej prevádzky',
    done: 'Firma archivovaná',
    failed: 'Firmu sa nepodarilo archivovať.',
  },
  facility: {
    title: 'Archivovať prevádzku?',
    what: 'Prevádzka',
    done: 'Prevádzka archivovaná',
    failed: 'Prevádzku sa nepodarilo archivovať.',
  },
};

export function ArchiveDialog({
  open,
  kind,
  id,
  name,
  onClose,
  onArchived,
}: {
  open: boolean;
  kind: Kind;
  id: number;
  name: string;
  onClose: () => void;
  onArchived: () => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const copy = COPY[kind];

  useEffect(() => {
    if (open) {
      setReason('');
      setError(null);
    }
  }, [open]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const value = reason.trim() || null;
      if (kind === 'company') await Companies.archive(id, value, csrfToken);
      else await Facilities.archive(id, value, csrfToken);
      toast.success(copy.done);
      onArchived();
    } catch (err) {
      setError(offlineMessage(err, copy.failed));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog
      open={open}
      onClose={() => {
        if (!saving) onClose();
      }}
      title={copy.title}
      dismissible={!saving}
    >
      <form onSubmit={submit} className="flex flex-col gap-4">
        <div className="flex flex-col gap-2 text-sm text-ink-600">
          <p>
            {copy.what} <span className="font-semibold text-ink-900">„{name}“</span> zmizne zo
            zoznamov, z kalendára, časovej osi aj z obrazovky Dnes.
          </p>
          <ul className="flex list-disc flex-col gap-1 pl-5 text-ink-500">
            <li>Protokoly a história zostávajú — dajú sa otvoriť, stiahnuť aj odoslať.</li>
            <li>Otvorené úlohy sa uzavrú ako zrušené.</li>
            <li>Automatické oznámenia klientovi sa nebudú posielať.</li>
          </ul>
          <p>Archiváciu môžeš kedykoľvek zrušiť.</p>
        </div>

        <Field label="Dôvod" hint="Nepovinné — napríklad „klient ukončil spoluprácu“.">
          {(p) => (
            <textarea
              id={p.id}
              rows={2}
              maxLength={500}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              disabled={saving}
              className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
            />
          )}
        </Field>

        {error && (
          <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={saving}>
            Zrušiť
          </Button>
          <Button
            type="submit"
            variant="warn"
            size="sm"
            loading={saving}
            leftIcon={<Archive className="size-4" />}
          >
            Archivovať
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

/**
 * Asks, then restores. Resolves true once the server has restored it, false
 * when the user backed out or it failed (the toast says why).
 */
export function useRestoreArchived() {
  const { csrfToken } = useAuth();
  const confirm = useConfirm();
  const toast = useToast();

  return async function restore(kind: Kind, id: number, name: string): Promise<boolean> {
    const ok = await confirm({
      title: kind === 'company' ? 'Obnoviť firmu?' : 'Obnoviť prevádzku?',
      description:
        `„${name}“ sa vráti medzi aktívne. Termíny sa vypočítajú nanovo z poslednej vykonanej ` +
        'kontroly a jej periodicity — ak medzitým lehota uplynula, termín bude po termíne.',
      confirmLabel: 'Obnoviť',
      tone: 'primary',
    });
    if (!ok) return false;
    try {
      if (kind === 'company') await Companies.restore(id, csrfToken);
      else await Facilities.restore(id, csrfToken);
      toast.success(kind === 'company' ? 'Firma obnovená' : 'Prevádzka obnovená');
      return true;
    } catch (err) {
      toast.error(
        offlineMessage(
          err,
          kind === 'company' ? 'Firmu sa nepodarilo obnoviť.' : 'Prevádzku sa nepodarilo obnoviť.',
        ),
      );
      return false;
    }
  };
}

/** The „archivovaná" strip on a firm's or prevádzka's detail page. */
export function ArchivedNotice({
  label,
  archivedAt,
  reason,
  hint,
  onRestore,
}: {
  label: string;
  archivedAt: string;
  reason?: string | null;
  /** Shown instead of the button when the restore happens elsewhere. */
  hint?: string;
  onRestore?: () => void;
}) {
  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-ink-200 bg-ink-50 px-4 py-3 sm:flex-row sm:items-center">
      <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-ink-200/70 text-ink-600">
        <Archive className="size-4" />
      </span>
      <div className="min-w-0 flex-1 text-sm">
        <p className="font-semibold text-ink-800">
          {label} {formatDay(archivedAt)}
        </p>
        {reason && <p className="mt-0.5 break-words text-ink-600">Dôvod: {reason}</p>}
        <p className="mt-0.5 text-xs text-ink-500">
          {hint ?? 'Protokoly a história zostávajú. Nové úkony sa tu nedajú začať.'}
        </p>
      </div>
      {onRestore && (
        <Button
          variant="secondary"
          size="sm"
          onClick={onRestore}
          leftIcon={<ArchiveRestore className="size-4" />}
          className="shrink-0 self-start sm:self-center"
        >
          Obnoviť
        </Button>
      )}
    </div>
  );
}

/** „2026-10-08 12:30:00" → „8. 10. 2026". */
export function formatDay(value: string): string {
  const [y, m, d] = value.slice(0, 10).split('-').map(Number);
  return `${d}. ${m}. ${y}`;
}
