import { useEffect, useMemo, useState } from 'react';
import { Mail, Plus, Send, X } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Documents, type SendableDocument } from '@/api/documents';
import { INSPECTION_TYPE_LABELS } from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';

/**
 * Several protocols in one e-mail — block 1 / chapter 9.1.
 *
 * Reached from two places on purpose: at the end of a visit, where four
 * protocols should leave as one message rather than four, and from the company
 * history, where a client asking for "everything from last year" is answered by
 * ticking rows and pressing send once.
 *
 * Everything is ticked to begin with — the common case at the end of a visit is
 * "send all of it" — and the technician unticks what they want to keep back.
 */
export function BulkSendDialog({
  open,
  onClose,
  companyId,
  companyName,
  defaultRecipient,
  /** Preselect exactly these protocols (the ones a visit just produced). */
  preselectDocumentIds,
  visitId,
  onSent,
}: {
  open: boolean;
  onClose: () => void;
  companyId: number;
  companyName: string;
  defaultRecipient?: string | null;
  preselectDocumentIds?: number[];
  visitId?: number;
  onSent?: () => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [documents, setDocuments] = useState<SendableDocument[] | null>(null);
  const [maxTotalBytes, setMaxTotalBytes] = useState(20 * 1024 * 1024);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [recipients, setRecipients] = useState<string[]>([]);
  const [recipientDraft, setRecipientDraft] = useState('');
  const [subject, setSubject] = useState('');
  const [note, setNote] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setError(null);
    setRecipients(defaultRecipient ? [defaultRecipient] : []);
    setRecipientDraft('');
    setSubject(`Protokoly z kontroly — ${companyName}, ${new Date().toLocaleDateString('sk-SK')}`);
    setNote('');

    let cancelled = false;
    Documents.sendable(companyId)
      .then((res) => {
        if (cancelled) return;
        setDocuments(res.items);
        setMaxTotalBytes(res.max_total_bytes);
        setSelected(
          new Set(
            preselectDocumentIds && preselectDocumentIds.length > 0
              ? preselectDocumentIds
              : res.items.map((d) => d.id),
          ),
        );
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setDocuments([]);
        setError(
          err instanceof ApiError ? err.message : 'Protokoly sa nepodarilo načítať.',
        );
      });
    return () => {
      cancelled = true;
    };
    // preselectDocumentIds is a fresh array on every render of the parent;
    // depending on it would refetch in a loop.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, companyId, companyName, defaultRecipient]);

  const totalBytes = useMemo(
    () =>
      (documents ?? [])
        .filter((d) => selected.has(d.id))
        .reduce((sum, d) => sum + d.byte_size, 0),
    [documents, selected],
  );
  const overLimit = totalBytes > maxTotalBytes;

  function toggle(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function addRecipient() {
    const value = recipientDraft.trim();
    if (!value) return;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      setError(`Neplatná e-mailová adresa: ${value}`);
      return;
    }
    setError(null);
    setRecipients((prev) => (prev.includes(value) ? prev : [...prev, value]));
    setRecipientDraft('');
  }

  async function handleSend() {
    const ids = [...selected];
    if (ids.length === 0) {
      setError('Vyber aspoň jeden protokol.');
      return;
    }
    // A recipient typed but not yet added with Enter would otherwise be lost.
    const pending = recipientDraft.trim();
    const allRecipients = pending && !recipients.includes(pending)
      ? [...recipients, pending]
      : recipients;
    if (allRecipients.length === 0) {
      setError('Zadaj aspoň jedného príjemcu.');
      return;
    }

    setSending(true);
    setError(null);
    try {
      await Documents.send(
        companyId,
        {
          document_ids: ids,
          recipients: allRecipients,
          subject: subject.trim() || undefined,
          note: note.trim() || null,
          visit_id: visitId,
        },
        csrfToken,
      );
      toast.success(
        `Odoslané: ${ids.length} ${ids.length === 1 ? 'protokol' : ids.length < 5 ? 'protokoly' : 'protokolov'} na ${allRecipients.length} ${allRecipients.length === 1 ? 'adresu' : 'adries'}.`,
      );
      onSent?.();
      onClose();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : offlineMessage(err, 'Odoslanie zlyhalo.'),
      );
    } finally {
      setSending(false);
    }
  }

  return (
    <Dialog
      open={open}
      onClose={() => {
        if (!sending) onClose();
      }}
      title="Odoslať protokoly klientovi"
      description="Vybrané protokoly odídu v jednom e-maile ako prílohy."
      maxWidthClassName="max-w-2xl"
      dismissible={!sending}
    >
      {documents === null ? (
        <div className="flex justify-center py-8 text-ink-400">
          <Spinner />
        </div>
      ) : documents.length === 0 ? (
        <p className="py-4 text-sm text-ink-500">
          Táto firma zatiaľ nemá vystavený žiadny protokol.
        </p>
      ) : (
        <div className="flex flex-col gap-4">
          <div className="max-h-64 overflow-y-auto rounded-2xl border border-ink-200">
            <ul className="divide-y divide-ink-100">
              {documents.map((doc) => (
                <li key={doc.id}>
                  <label className="flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors hover:bg-ink-50">
                    <input
                      type="checkbox"
                      checked={selected.has(doc.id)}
                      onChange={() => toggle(doc.id)}
                      disabled={sending}
                      className="size-4 shrink-0 accent-firol-500"
                    />
                    <span className="min-w-0 flex-1">
                      <span className="block font-mono text-sm text-ink-900">{doc.number}</span>
                      <span className="block text-xs text-ink-500">
                        {INSPECTION_TYPE_LABELS[doc.type] ?? doc.type} · {doc.facility_name}
                        {doc.executed_on &&
                          ` · ${new Date(`${doc.executed_on}T00:00:00`).toLocaleDateString('sk-SK')}`}
                      </span>
                    </span>
                    <span className="shrink-0 text-xs tabular-nums text-ink-400">
                      {formatSize(doc.byte_size)}
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          </div>

          <div className="flex items-center justify-between gap-2 text-xs">
            <span className="text-ink-500">
              Vybrané: {selected.size} · prílohy spolu{' '}
              <span className={cn('tabular-nums', overLimit && 'font-semibold text-status-bad')}>
                {formatSize(totalBytes)}
              </span>
            </span>
            <button
              type="button"
              onClick={() =>
                setSelected(
                  selected.size === documents.length
                    ? new Set()
                    : new Set(documents.map((d) => d.id)),
                )
              }
              className="text-firol-600 hover:underline"
            >
              {selected.size === documents.length ? 'Zrušiť výber' : 'Označiť všetky'}
            </button>
          </div>

          {overLimit && (
            <p className="rounded-xl bg-[var(--color-status-warn-bg)] px-3 py-2 text-xs text-[var(--color-status-warn)]">
              Prílohy presahujú {formatSize(maxTotalBytes)} — väčšina schránok taký
              e-mail odmietne. Odošli protokoly na dvakrát alebo niektorý odznač.
            </p>
          )}

          <Field label="Príjemcovia" required>
            {(p) => (
              <div className="flex flex-col gap-2">
                {recipients.length > 0 && (
                  <ul className="flex flex-wrap gap-1.5">
                    {recipients.map((r) => (
                      <li
                        key={r}
                        className="inline-flex items-center gap-1 rounded-full bg-ink-100 py-1 pl-3 pr-1 text-xs text-ink-700"
                      >
                        {r}
                        <button
                          type="button"
                          aria-label={`Odstrániť ${r}`}
                          onClick={() => setRecipients((prev) => prev.filter((x) => x !== r))}
                          className="grid size-5 place-items-center rounded-full text-ink-400 transition-colors hover:bg-ink-200 hover:text-ink-700"
                        >
                          <X className="size-3" />
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
                <div className="flex gap-2">
                  <Input
                    {...p}
                    type="email"
                    inputMode="email"
                    placeholder="zakaznik@firma.sk"
                    value={recipientDraft}
                    onChange={(e) => setRecipientDraft(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        addRecipient();
                      }
                    }}
                    disabled={sending}
                    className="flex-1"
                  />
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={addRecipient}
                    disabled={sending || !recipientDraft.trim()}
                    leftIcon={<Plus className="size-4" />}
                  >
                    Pridať
                  </Button>
                </div>
              </div>
            )}
          </Field>

          <Field label="Predmet">
            {(p) => (
              <Input
                {...p}
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                disabled={sending}
                leftIcon={<Mail className="size-4" />}
              />
            )}
          </Field>

          <Field label="Poznámka pre príjemcu" hint="Voliteľná — zobrazí sa v tele e-mailu.">
            {(p) => (
              <textarea
                id={p.id}
                rows={2}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                disabled={sending}
                className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
              />
            )}
          </Field>

          {error && (
            <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </p>
          )}

          <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <Button variant="secondary" onClick={onClose} disabled={sending}>
              Zrušiť
            </Button>
            <Button
              onClick={handleSend}
              loading={sending}
              disabled={selected.size === 0}
              leftIcon={<Send className="size-4" />}
            >
              Odoslať
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} kB`;
  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
