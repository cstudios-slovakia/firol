import { useState } from 'react';
import { Printer } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Book, bookPrintUrl, type BookEntry, type BookView } from '@/api/book';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { clientNouns, isBytoveDruzstvo } from '@/lib/clientKind';

/**
 * The sheets of the book screen (spec 9): write an addition under an entry and
 * print / mark entries as signed. New entries are written in `NewBookEntryModal`.
 * Every write is online-only — the poradové číslo comes from the server.
 */

const TEXTAREA_CLASS =
  'w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 ' +
  'transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';

/** „Dopísať" — the original text stays, the addition is written under it. */
export function AdditionSheet({
  entry,
  onClose,
  onSaved,
}: {
  entry: BookEntry;
  onClose: () => void;
  onSaved: (entry: BookEntry) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [text, setText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!text.trim()) return setError('Napíšte doplnenie.');
    setSaving(true);
    setError(null);
    try {
      const res = await Book.addAddition(entry.id, text.trim(), csrfToken);
      toast.success('Doplnené.');
      onSaved(res.entry);
    } catch (err) {
      setError(offlineMessage(err, 'Doplnenie sa nepodarilo uložiť.'));
      setSaving(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Dopísať k zápisu č. ${entry.seq}`}
      description="Pôvodný text zostáva, dopisuje sa za neho."
      dismissible={!saving}
    >
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <p className="rounded-xl bg-ink-50 px-3 py-2.5 text-xs leading-relaxed text-ink-600">{entry.text}</p>
        <Field label="Doplnenie" required>
          {(p) => (
            <textarea
              {...p}
              rows={4}
              value={text}
              autoFocus
              placeholder="Nedostatok bol odstránený dňa…"
              onChange={(e) => setText(e.target.value)}
              className={TEXTAREA_CLASS}
            />
          )}
        </Field>
        {error && (
          <p role="alert" className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={saving}>
            Zrušiť
          </Button>
          <Button type="submit" size="sm" loading={saving}>
            Dopísať
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

/**
 * „Vytlačiť nepodpísané" and „Označiť ako podpísané" (spec 9.5). The signer is
 * the vedúci zamestnanec — prefilled from the firm, editable. Printing opens
 * the PDF; marking is a separate step after the paper is signed. No tracking
 * of submission, no reminders.
 */
export function SignSheet({
  view,
  onClose,
  onMarked,
}: {
  view: BookView;
  onClose: () => void;
  onMarked: (count: number) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const unsigned = view.entries.filter((e) => !e.signed_manager);
  const [name, setName] = useState(view.signer?.fullname ?? '');
  const [role, setRole] = useState(view.signer?.role_title ?? '');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function print() {
    window.open(
      bookPrintUrl(view.company.id, view.book, {
        facilityId: view.facility_id,
        signerName: name.trim(),
        signerRole: role.trim(),
      }),
      '_blank',
      'noopener',
    );
  }

  async function mark() {
    setSaving(true);
    setError(null);
    try {
      const res = await Book.markSigned(
        view.company.id,
        {
          book: view.book,
          facility_id: view.facility_id,
          entry_ids: unsigned.map((e) => e.id),
          signer_name: name.trim() || undefined,
          signer_role: role.trim() || undefined,
        },
        csrfToken,
      );
      toast.success(`Označené ako podpísané: ${res.marked}.`);
      onMarked(res.marked);
    } catch (err) {
      setError(offlineMessage(err, 'Označenie sa nepodarilo.'));
      setSaving(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Zápisy na podpis"
      description="Podpisy fungujú ako doteraz — zápisy sa vytlačia na jeden list a podpíše ich vedúci zamestnanec. Appka vedie len evidenciu."
      dismissible={!saving}
    >
      <div className="flex flex-col gap-4">
        <ul className="flex max-h-40 flex-col gap-1.5 overflow-y-auto rounded-xl bg-ink-50 p-2.5 text-xs text-ink-600">
          {unsigned.map((e) => (
            <li key={e.id} className="flex gap-2">
              <span className="w-6 shrink-0 text-right font-semibold tabular-nums text-ink-800">{e.seq}</span>
              <span className="min-w-0 truncate">{e.text}</span>
            </li>
          ))}
        </ul>

        <Field
          label="Podpisuje — vedúci zamestnanec"
          hint={`Predvyplnené z ${clientNouns(view.company.client_type).cases.gen}, dá sa prepísať. Kniha nikdy nehovorí „schvaľuje“ — vedúci záznam podpisuje ako oboznámený.`}
        >
          {(p) => (
            <Input {...p} value={name} maxLength={191} placeholder="Meno a priezvisko" onChange={(e) => setName(e.target.value)} />
          )}
        </Field>
        <Field label="Funkcia">
          {(p) => (
            <Input {...p} value={role} maxLength={191} placeholder={isBytoveDruzstvo(view.company.client_type) ? 'správca bytového domu…' : 'riaditeľ pobočky, vedúci prevádzky…'} onChange={(e) => setRole(e.target.value)} />
          )}
        </Field>

        {error && (
          <p role="alert" className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </p>
        )}

        <div className="flex flex-col gap-2">
          <Button type="button" variant="secondary" onClick={print} leftIcon={<Printer className="size-4" />}>
            Vytlačiť ({unsigned.length})
          </Button>
          <Button type="button" onClick={mark} loading={saving} disabled={unsigned.length === 0}>
            Označiť ako podpísané
          </Button>
          <p className="text-center text-xs text-ink-400">
            Označte až po podpise — podpísaný papier zostáva u klienta.
          </p>
        </div>
      </div>
    </Dialog>
  );
}
