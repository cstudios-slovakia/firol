import { useState } from 'react';
import { useAuth } from '@/auth/AuthContext';
import {
  ExternalTerms, type ExternalTerm, type ExternalTermBook, type ExternalTermSuggestion,
} from '@/api/externalTerms';
import { BOOK_LABELS, type BookKey } from '@/lib/bookMapping';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';

/** Value of the name picker that opens the free-text field. */
const OWN_NAME = '__own__';

/**
 * Add or edit a termín cudzej revízie (spec 6.6). Which book the term belongs
 * to is a question the technician answers — a picked name only preselects it.
 */
export function ExternalTermSheet({
  companyId,
  term,
  preset,
  currentBook,
  suggestions,
  facilities,
  onClose,
  onSaved,
}: {
  companyId: number;
  /** The term being edited; omitted when adding. */
  term?: ExternalTerm;
  /** Prefill for a new term whose name and book are already known („doplniť školenie"). */
  preset?: { name: string; book: ExternalTermBook };
  /** The book on screen — the answer offered for a name of one's own. */
  currentBook: BookKey;
  suggestions: ExternalTermSuggestion[];
  facilities: { id: number; name: string }[];
  onClose: () => void;
  onSaved: (term: ExternalTerm) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const isSuggested = (n: string) => suggestions.some((s) => s.name === n);
  const [pick, setPick] = useState(
    term
      ? isSuggested(term.name) ? term.name : OWN_NAME
      : preset
        ? isSuggested(preset.name) ? preset.name : OWN_NAME
        : (suggestions[0]?.name ?? OWN_NAME),
  );
  const [ownName, setOwnName] = useState(
    term && !isSuggested(term.name) ? term.name : !term && preset && !isSuggested(preset.name) ? preset.name : '',
  );
  const [book, setBook] = useState<ExternalTermBook>(
    term?.book ?? preset?.book ?? suggestions.find((s) => s.name === pick)?.book ?? currentBook,
  );
  const [performedBy, setPerformedBy] = useState(term?.performed_by ?? '');
  const [doneOn, setDoneOn] = useState(term?.done_on ?? '');
  const [validUntil, setValidUntil] = useState(term?.valid_until ?? '');
  const [facilityId, setFacilityId] = useState(term?.facility_id ? String(term.facility_id) : '');
  const [note, setNote] = useState(term?.note ?? '');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function onPick(next: string) {
    setPick(next);
    // A listed name only suggests the book; the field below stays editable.
    const suggested = suggestions.find((s) => s.name === next)?.book;
    if (suggested && !term) setBook(suggested);
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const name = (pick === OWN_NAME ? ownName : pick).trim();
    if (!name) return setError('Zadajte, o aký termín ide.');

    const body = {
      name,
      book,
      facility_id: facilityId ? Number(facilityId) : null,
      performed_by: performedBy.trim() || null,
      done_on: doneOn || null,
      valid_until: validUntil || null,
      note: note.trim() || null,
    };

    setSaving(true);
    try {
      const res = term
        ? await ExternalTerms.update(companyId, term.id, body, csrfToken)
        : await ExternalTerms.create(companyId, body, csrfToken);
      toast.success(
        (res.term.book as string) === currentBook || res.term.book === 'ob'
          ? 'Termín uložený. Appka si ho pamätá pri tejto firme.'
          : `Termín uložený do: ${BOOK_LABELS[res.term.book as BookKey]}.`,
      );
      onSaved(res.term);
    } catch (err) {
      setError(offlineMessage(err, 'Termín sa nepodarilo uložiť.'));
      setSaving(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={term ? 'Upraviť termín cudzej revízie' : 'Termín cudzej revízie'}
      description="Revízia, ktorú u klienta nerobíte vy. Appka si ju zapamätá a vytlačí v prehľade termínov."
      dismissible={!saving}
    >
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Čo" required>
          {(p) => (
            <Select
              id={p.id}
              value={pick}
              onChange={onPick}
              options={[
                ...suggestions.map((s) => ({ value: s.name, label: s.name })),
                { value: OWN_NAME, label: 'Iný názov…' },
              ]}
            />
          )}
        </Field>
        {pick === OWN_NAME && (
          <Field label="Názov termínu" required>
            {(p) => (
              <Input
                {...p}
                value={ownName}
                maxLength={191}
                autoFocus
                placeholder="Revízia vyhradených technických zariadení"
                onChange={(e) => setOwnName(e.target.value)}
              />
            )}
          </Field>
        )}

        <Field label="Patrí do záznamu" required hint="Určujete vy — názov to nerozhoduje. Termín sa vytlačí v zázname zvolenej oblasti.">
          {(p) => (
            <Select
              id={p.id}
              value={book}
              onChange={(v) => setBook(v as ExternalTermBook)}
              options={[
                { value: 'po', label: 'Ochrane pred požiarmi' },
                { value: 'bozp', label: 'BOZP' },
                { value: 'ob', label: 'Obom — PO aj BOZP' },
              ]}
            />
          )}
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Vykonaná">
            {(p) => <Input {...p} type="date" value={doneOn} onChange={(e) => setDoneOn(e.target.value)} />}
          </Field>
          <Field label="Platnosť do">
            {(p) => <Input {...p} type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />}
          </Field>
        </div>
        <p className="-mt-2 text-xs leading-relaxed text-ink-500">
          Ak klient dátum nemá, nechajte „Platnosť do“ prázdnu — v zázname sa vytlačí „nedoložené“.
        </p>

        <Field label="Vykonal (nepovinné)">
          {(p) => (
            <Input
              {...p}
              value={performedBy}
              maxLength={191}
              placeholder="Firma alebo revízny technik"
              onChange={(e) => setPerformedBy(e.target.value)}
            />
          )}
        </Field>

        {facilities.length > 1 && (
          <Field label="Platí pre" hint="Celá firma sa vytlačí v zázname každej prevádzky.">
            {(p) => (
              <Select
                id={p.id}
                value={facilityId}
                onChange={setFacilityId}
                options={[
                  { value: '', label: 'Celá firma' },
                  ...facilities.map((f) => ({ value: String(f.id), label: f.name })),
                ]}
              />
            )}
          </Field>
        )}

        <Field label="Poznámka (nepovinné)">
          {(p) => <Input {...p} value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />}
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
            Uložiť termín
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
