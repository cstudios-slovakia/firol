import { useState } from 'react';
import { Printer } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Book, bookPrintUrl, type BookEntry, type BookOffer, type BookView } from '@/api/book';
import { todayIso } from '@/lib/dates';
import { offlineMessage } from '@/lib/offline';
import { cn } from '@/lib/cn';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';

/**
 * The sheets of the book screen (spec 9): add an entry (offered, by protocol
 * number, own), write an addition under one, and print / mark as signed.
 * Every write is online-only — the poradové číslo comes from the server.
 */

const TEXTAREA_CLASS =
  'w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 ' +
  'transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';

export type EntrySheetMode =
  | { kind: 'offer'; offer: BookOffer }
  | { kind: 'own' }
  | { kind: 'number' };

function Check({
  checked,
  onChange,
  disabled,
  label,
  hint,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  disabled?: boolean;
  label: string;
  hint?: string;
}) {
  return (
    <label
      className={cn(
        'flex cursor-pointer items-start gap-3 rounded-2xl border px-3.5 py-3 transition-colors duration-150',
        disabled ? 'cursor-not-allowed border-ink-100 bg-ink-50 opacity-70' : 'border-ink-200 bg-white hover:border-ink-300',
      )}
    >
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
        className="mt-0.5 size-4 shrink-0 accent-[var(--color-firol-500)]"
      />
      <span className="min-w-0">
        <span className="block text-sm font-medium text-ink-900">{label}</span>
        {hint && <span className="mt-0.5 block text-xs leading-relaxed text-ink-500">{hint}</span>}
      </span>
    </label>
  );
}

/** Add an entry: from an offer (edited), by protocol number, or written by hand. */
export function EntrySheet({
  view,
  mode,
  onClose,
  onSaved,
}: {
  view: BookView;
  mode: EntrySheetMode;
  onClose: () => void;
  onSaved: (entry: BookEntry) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const isPo = view.book === 'po';

  const [text, setText] = useState(mode.kind === 'offer' ? mode.offer.text : '');
  const [kind, setKind] = useState(view.manual_kinds[0]?.kind ?? '');
  const [number, setNumber] = useState('');
  const [date, setDate] = useState(todayIso());
  const [object, setObject] = useState('');
  const [legal, setLegal] = useState(false);
  const [noDefects, setNoDefects] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const carriesDefects = mode.kind === 'offer' && mode.offer.has_defects;
  const title =
    mode.kind === 'offer' ? 'Zápis do knihy' : mode.kind === 'number' ? 'Zápis podľa čísla protokolu' : 'Vlastný zápis';
  const description =
    mode.kind === 'offer'
      ? 'Text sa dá prepísať — do knihy ide to, čo tu zostane.'
      : mode.kind === 'number'
        ? 'Funguje aj pre úkon, ktorý v appke vôbec nie je — školenie od niekoho iného, kontrola kolegu, starší protokol z papiera.'
        : 'Čokoľvek, čo úkon nepokrýva — cvičný poplach, kontrola dokumentácie ochrany pred požiarmi, pokyn vedúceho.';

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (mode.kind === 'number') {
      if (!number.trim()) return setError('Zadajte číslo protokolu.');
    } else if (!text.trim()) {
      return setError(mode.kind === 'own' ? 'Napíšte text zápisu.' : 'Text zápisu nemôže byť prázdny.');
    }

    setSaving(true);
    try {
      const common = {
        book: view.book,
        facility_id: view.facility_id,
        legal_sentence: legal,
        no_defects_statement: isPo && noDefects && !carriesDefects,
      };
      const res =
        mode.kind === 'offer'
          ? await Book.createEntry(
              view.company.id,
              {
                ...common,
                source: 'ukon',
                date: mode.offer.date,
                text: text.trim(),
                protocol_numbers: mode.offer.protocol_numbers,
                inspection_ids: mode.offer.inspection_ids,
                training_ids: mode.offer.training_ids,
              },
              csrfToken,
            )
          : mode.kind === 'number'
            ? await Book.createEntry(
                view.company.id,
                { ...common, source: 'cislo', kind, number: number.trim(), date, object: object.trim() },
                csrfToken,
              )
            : await Book.createEntry(
                view.company.id,
                { ...common, source: 'vlastny', text: text.trim(), date },
                csrfToken,
              );
      toast.success('Zapísané do knihy. Čaká na podpis vedúceho.');
      onSaved(res.entry);
    } catch (err) {
      setError(offlineMessage(err, 'Zápis sa nepodarilo uložiť.'));
      setSaving(false);
    }
  }

  return (
    <Dialog open onClose={onClose} title={title} description={description} dismissible={!saving}>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {mode.kind === 'number' && (
          <>
            <Field label="Čo sa vykonalo">
              {(p) => (
                <Select
                  id={p.id}
                  value={kind}
                  onChange={setKind}
                  options={view.manual_kinds.map((k) => ({ value: k.kind, label: k.label }))}
                />
              )}
            </Field>
            <Field label="Číslo protokolu" required>
              {(p) => (
                <Input
                  {...p}
                  value={number}
                  maxLength={64}
                  autoFocus
                  placeholder="PHP-2026-041"
                  onChange={(e) => setNumber(e.target.value)}
                />
              )}
            </Field>
            <Field label="Dátum vykonania">
              {(p) => <Input {...p} type="date" value={date} onChange={(e) => setDate(e.target.value)} />}
            </Field>
            <Field label="Objekt (nepovinné)">
              {(p) => (
                <Input
                  {...p}
                  value={object}
                  maxLength={191}
                  placeholder={view.facilities.find((f) => f.id === view.facility_id)?.name ?? ''}
                  onChange={(e) => setObject(e.target.value)}
                />
              )}
            </Field>
          </>
        )}

        {mode.kind !== 'number' && (
          <>
            {mode.kind === 'own' && (
              <Field label="Dátum zápisu">
                {(p) => <Input {...p} type="date" value={date} onChange={(e) => setDate(e.target.value)} />}
              </Field>
            )}
            <Field label="Text zápisu" required>
              {(p) => (
                <textarea
                  {...p}
                  rows={mode.kind === 'offer' ? 6 : 5}
                  value={text}
                  autoFocus
                  placeholder={mode.kind === 'own' ? 'Vykonaný cvičný požiarny poplach…' : undefined}
                  onChange={(e) => setText(e.target.value)}
                  className={TEXTAREA_CLASS}
                />
              )}
            </Field>
          </>
        )}

        <Check
          checked={legal}
          onChange={setLegal}
          label="Pridať zákonnú vetu"
          hint={
            legal
              ? `Pred text sa doplní: „${view.legal_sentence}“`
              : 'Nedopĺňa sa sama — zapnite ju, keď ju tam chcete. Niekedy je zápis zároveň záznamom o prehliadke, inokedy nie.'
          }
        />

        {isPo && (
          <Check
            checked={noDefects && !carriesDefects}
            onChange={setNoDefects}
            disabled={carriesDefects}
            label={view.no_defects_sentence}
            hint={
              carriesDefects
                ? 'Zápis nesie nedostatky, preto sa vyhlásenie nedá zaškrtnúť.'
                : 'Vytlačí sa v sekcii ZISTENIA namiesto zistených nedostatkov.'
            }
          />
        )}

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
            Zapísať do knihy
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

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
          hint="Predvyplnené z firmy, dá sa prepísať. Kniha nikdy nehovorí „schvaľuje“ — vedúci záznam podpisuje ako oboznámený."
        >
          {(p) => (
            <Input {...p} value={name} maxLength={191} placeholder="Meno a priezvisko" onChange={(e) => setName(e.target.value)} />
          )}
        </Field>
        <Field label="Funkcia">
          {(p) => (
            <Input {...p} value={role} maxLength={191} placeholder="riaditeľ pobočky, vedúci prevádzky…" onChange={(e) => setRole(e.target.value)} />
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
