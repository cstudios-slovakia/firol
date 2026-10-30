import { useState, type FormEvent } from 'react';
// SIGNATURE DISABLED — import { useRef, useState, type FormEvent } from 'react';
import { Briefcase, CheckCircle2, ClipboardPaste, CopyPlus, Hash, User } from 'lucide-react';
import { TRAINING_TYPE_LABELS, type TraineeSource } from '@/api/trainings';
import { ApiError } from '@/lib/api';
import { parseAttendeeLines, peopleCount } from '@/lib/attendeeLines';
import { peopleAccusative, skDate } from '@/api/personList';
import { useConfirm } from '@/lib/confirm';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
// SIGNATURE DISABLED — import { SignaturePad, type SignaturePadHandle } from '@/components/SignaturePad';

/**
 * The three ways of getting attendees onto a training (spec 8.3): one person
 * (also used to correct one), many pasted at once, and the list of the previous
 * training. The pasted-list form is shared with the oboznámenie BOZP person
 * list, which has the same need.
 */

export type TraineeFormValue = {
  fullname: string;
  position: string;
  personal_number: string;
};

/**
 * One attendee — new (`initial` empty) or being corrected. Throws from
 * `onSubmit` to keep the typed values on screen when saving fails.
 */
export function TraineeForm({
  initial,
  title,
  submitLabel,
  submitting,
  onCancel,
  onSubmit,
}: {
  initial?: Partial<TraineeFormValue>;
  title?: string;
  submitLabel: string;
  submitting: boolean;
  onCancel: () => void;
  onSubmit: (value: TraineeFormValue) => Promise<void>;
}) {
  // SIGNATURE DISABLED — const padRef = useRef<SignaturePadHandle>(null);
  const confirm = useConfirm();
  const [fullname, setFullname] = useState(initial?.fullname ?? '');
  const [position, setPosition] = useState(initial?.position ?? '');
  const [personalNumber, setPersonalNumber] = useState(initial?.personal_number ?? '');
  // SIGNATURE DISABLED — const [empty, setEmpty] = useState(true);
  const [fullnameError, setFullnameError] = useState<string | null>(null);
  // SIGNATURE DISABLED — const [signatureError, setSignatureError] = useState<string | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);

  const dirty =
    fullname.trim() !== (initial?.fullname ?? '')
    || position.trim() !== (initial?.position ?? '')
    || personalNumber.trim() !== (initial?.personal_number ?? '');

  async function handleCancel() {
    if (dirty) {
      const ok = await confirm({
        title: 'Zavrieť formulár?',
        description: 'Formulár obsahuje neuložené údaje, ktoré sa stratia.',
        confirmLabel: 'Zavrieť bez uloženia',
      });
      if (!ok) return;
    }
    onCancel();
  }

  async function handle(e: FormEvent) {
    e.preventDefault();
    // SIGNATURE DISABLED — const blob = await padRef.current?.toBlob();
    // SIGNATURE DISABLED — if (!blob) { setSignatureError('Účastník sa musí podpísať na obrazovku.'); return; }
    if (!fullname.trim()) {
      setFullnameError('Doplň meno a priezvisko účastníka.');
      return;
    }
    setFullnameError(null);
    // SIGNATURE DISABLED — setSignatureError(null);
    setApiError(null);
    try {
      // SIGNATURE DISABLED — add `signature: blob!` to the payload (and to the API call)
      await onSubmit({
        fullname: fullname.trim(),
        position: position.trim(),
        personal_number: personalNumber.trim(),
      });
    } catch (err) {
      setApiError(err instanceof ApiError ? err.message : 'Účastníka sa nepodarilo uložiť.');
    }
  }

  return (
    <Card className="mb-3 animate-fade-up p-5">
      <form onSubmit={handle} className="flex flex-col gap-4" noValidate>
        {title && <p className="text-sm font-semibold text-ink-900">{title}</p>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Meno a priezvisko" required error={fullnameError}>
            {(p) => (
              <Input {...p} required autoFocus leftIcon={<User className="size-4" />} autoComplete="off"
                value={fullname}
                onChange={(e) => { setFullname(e.target.value); if (fullnameError) setFullnameError(null); }}
                placeholder="Mária Novotná" />
            )}
          </Field>
          <Field label="Pracovné zaradenie" hint={'Voliteľné — napr. „Vedúci skladu".'}>
            {(p) => (
              <Input {...p} leftIcon={<Briefcase className="size-4" />} autoComplete="off"
                value={position} onChange={(e) => setPosition(e.target.value)}
                placeholder="Vedúci zmeny" />
            )}
          </Field>
        </div>
        <Field
          label="Osobné číslo"
          hint="Voliteľné. Keď ho nemá nikto, stĺpec sa na protokole nevytlačí."
        >
          {(p) => (
            <Input {...p} leftIcon={<Hash className="size-4" />} autoComplete="off" maxLength={32}
              value={personalNumber} onChange={(e) => setPersonalNumber(e.target.value)}
              placeholder="1042" />
          )}
        </Field>

        {/* SIGNATURE DISABLED — on-screen pad; restore by uncommenting and re-enabling all SIGNATURE DISABLED markers
        <Field label="Podpis" required error={signatureError} hint={signatureError ? undefined : 'Podpíšte sa prstom alebo myšou. Rovnaký podpis pôjde na PDF.'}>
          {() => <SignaturePad ref={padRef} heightPx={180} onEmptyChange={(isEmpty) => { setEmpty(isEmpty); if (!isEmpty && signatureError) setSignatureError(null); }} />}
        </Field>
        */}

        {apiError && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {apiError}
          </div>
        )}

        <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-end">
          <Button type="button" variant="ghost" onClick={handleCancel}>
            Zrušiť
          </Button>
          {/* SIGNATURE DISABLED — when restoring, replace disabled with: disabled={empty || !fullname.trim()} and add: title={empty ? 'Najprv sa podpíšte' : ''} */}
          <Button
            type="submit"
            loading={submitting}
            leftIcon={<CheckCircle2 className="size-4" />}
            disabled={!fullname.trim() || (initial !== undefined && !dirty)}
          >
            {submitLabel}
          </Button>
        </div>
      </form>
    </Card>
  );
}

/**
 * „Viacerí naraz" — one textarea, one person per line, `meno; zaradenie;
 * osobné číslo` separated by a semicolon or a TAB (a range pasted out of Excel
 * arrives with TABs). The count shown under the box is what gets saved.
 * Throws from `onSubmit` to keep the pasted text when saving fails.
 */
export function BulkAttendeesForm({
  submitting,
  noun = 'účastníkov',
  warnMissingPosition = false,
  onCancel,
  onSubmit,
}: {
  submitting: boolean;
  /** Genitive plural, used in the fallback error message. */
  noun?: string;
  /** Oboznámenie BOZP needs a pracovné zaradenie on every row before it can be issued. */
  warnMissingPosition?: boolean;
  onCancel: () => void;
  onSubmit: (rows: ReturnType<typeof parseAttendeeLines>['rows']) => Promise<void>;
}) {
  const confirm = useConfirm();
  const [text, setText] = useState('');
  const [apiError, setApiError] = useState<string | null>(null);

  const { rows, skipped } = parseAttendeeLines(text);
  const withNumber = rows.filter((r) => r.personalNumber).length;
  const withoutPosition = rows.filter((r) => !r.position).length;

  async function handleCancel() {
    if (text.trim()) {
      const ok = await confirm({
        title: 'Zavrieť formulár?',
        description: 'Nalepený zoznam sa stratí.',
        confirmLabel: 'Zavrieť bez uloženia',
      });
      if (!ok) return;
    }
    onCancel();
  }

  async function handle(e: FormEvent) {
    e.preventDefault();
    if (rows.length === 0) return;
    setApiError(null);
    try {
      await onSubmit(rows);
    } catch (err) {
      setApiError(err instanceof ApiError ? err.message : `Nepodarilo sa pridať ${noun}.`);
    }
  }

  return (
    <Card className="mb-3 animate-fade-up p-5">
      <form onSubmit={handle} className="flex flex-col gap-4" noValidate>
        <Field
          label="Zoznam ľudí"
          hint="Jeden človek na riadok: meno; zaradenie; osobné číslo. Oddeľ bodkočiarkou alebo tabulátorom — dá sa nalepiť priamo z Excelu."
        >
          {(p) => (
            <textarea
              {...p}
              rows={8}
              autoFocus
              value={text}
              onChange={(e) => setText(e.target.value)}
              placeholder={'Ján Novák; skladník; 1042\nMária Kováčová; účtovníčka; 1043\nPeter Horváth'}
              className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 transition-colors placeholder:text-ink-300 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
            />
          )}
        </Field>

        <div
          aria-live="polite"
          className="flex flex-col gap-0.5 rounded-xl bg-ink-50 px-3 py-2 text-xs text-ink-600"
        >
          {rows.length === 0 ? (
            <span>Zatiaľ nič nerozpoznané — nalep aspoň jedno meno.</span>
          ) : (
            <>
              <span className="font-semibold text-ink-800">
                Rozpoznaných: {peopleCount(rows.length)}
                {withNumber > 0 && ` · ${withNumber} s osobným číslom`}
              </span>
              {warnMissingPosition && withoutPosition > 0 && (
                <span className="text-status-warn">
                  {withoutPosition} bez pracovného zaradenia — doplníš ho v zozname.
                </span>
              )}
            </>
          )}
          {skipped > 0 && (
            <span className="text-status-warn">
              {skipped} {skipped === 1 ? 'riadok bez mena sa vynechá' : 'riadkov bez mena sa vynechá'}.
            </span>
          )}
        </div>

        {apiError && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {apiError}
          </div>
        )}

        <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-end">
          <Button type="button" variant="ghost" onClick={handleCancel}>
            Zrušiť
          </Button>
          <Button
            type="submit"
            loading={submitting}
            disabled={rows.length === 0}
            leftIcon={<ClipboardPaste className="size-4" />}
          >
            {rows.length > 0 ? `Pridať ${peopleCount(rows.length)}` : 'Pridať'}
          </Button>
        </div>
      </form>
    </Card>
  );
}

/**
 * „Prevziať 8 osôb z minulého školenia (14. 5. 2026)?" — the list of the
 * firm's previous training, osobné čísla included (spec 8.3).
 */
export function TraineeSourceOffer({
  source,
  busy,
  error,
  onTake,
}: {
  source: TraineeSource;
  busy: boolean;
  error: string | null;
  onTake: () => void;
}) {
  return (
    <Card className="mb-3 flex flex-col gap-3 border-firol-200 bg-firol-50/60 p-4">
      <div className="flex items-start gap-3">
        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-white text-firol-600">
          <CopyPlus className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-ink-900">
            Prevziať {peopleAccusative(source.people)} z minulého školenia
            {source.date ? ` (${skDate(source.date)})` : ''}?
          </p>
          <p className="mt-1 text-xs text-ink-600">
            {TRAINING_TYPE_LABELS[source.type]}
            {source.facility_name ? ` · ${source.facility_name}` : ''}. Prenesú sa mená, pracovné zaradenia
            a osobné čísla — podpisy nie. Kto už v zozname je, sa nepridá druhýkrát.
          </p>
        </div>
      </div>
      {error && <p className="text-xs text-status-bad">{error}</p>}
      <Button type="button" className="self-start" loading={busy} onClick={onTake}
        leftIcon={<CopyPlus className="size-4" />}>
        Prevziať
      </Button>
    </Card>
  );
}
