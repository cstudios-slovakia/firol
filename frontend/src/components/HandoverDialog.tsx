import { useEffect, useRef, useState } from 'react';
import { PenLine, UserCheck } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type CompanyPerson } from '@/api/companies';
import { Documents } from '@/api/documents';
import { ApiError } from '@/lib/api';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { SignaturePad, type SignaturePadHandle } from '@/components/SignaturePad';
import { HANDOVER_ACTION_LABELS } from '@/lib/handover';

/**
 * Handing a protocol over for signature on the screen — block 1 / chapter 13.
 *
 * What is being signed is RECEIPT of the protocol, not agreement with what it
 * says: the findings are the technician's and stay theirs. The wording above
 * the line follows the document type — a požiarna kniha entry is „Schválil",
 * because under § 29 vyhl. 121/2002 the vedúci zamestnanec approves the record
 * rather than merely acknowledging it.
 *
 * Signing is optional. Handing over an unsigned protocol and collecting the
 * signature on paper afterwards is ordinary practice, which is why the PDF
 * always carries an empty line for it and this dialog can simply be closed.
 */
export function HandoverDialog({
  open,
  onClose,
  documentId,
  documentNumber,
  documentType,
  companyId,
  facilityId,
  /** Prefilled from the prevádzka's obec — where the protocol changed hands. */
  defaultPlace,
  /** Prefilled from the úkon's date, not today's: documents are back-datable. */
  defaultDate,
  onSigned,
}: {
  open: boolean;
  onClose: () => void;
  documentId: number;
  documentNumber: string;
  documentType: string;
  companyId: number;
  facilityId?: number;
  defaultPlace?: string | null;
  defaultDate?: string | null;
  onSigned: () => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const padRef = useRef<SignaturePadHandle | null>(null);

  const [persons, setPersons] = useState<CompanyPerson[] | null>(null);
  const [roleSuggestions, setRoleSuggestions] = useState<string[]>([]);
  const [personId, setPersonId] = useState<number | null>(null);
  const [fullname, setFullname] = useState('');
  const [roleTitle, setRoleTitle] = useState('');
  const [place, setPlace] = useState('');
  const [signedOn, setSignedOn] = useState('');
  const [signedTime, setSignedTime] = useState('');
  const [padEmpty, setPadEmpty] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setError(null);
    setPlace(defaultPlace ?? '');
    setSignedOn(defaultDate ?? todayIso());
    setSignedTime(nowHhMm());

    let cancelled = false;
    Companies.persons(companyId, facilityId)
      .then((res) => {
        if (cancelled) return;
        setPersons(res.items);
        setRoleSuggestions(res.role_suggestions);
        // The company's default signatory is offered first; a large client
        // has a konateľ at the seat and a vedúci at each prevádzka, and the
        // list is already ordered so the more specific one wins.
        const preferred = res.items[0];
        if (preferred) setPersonId(preferred.id);
      })
      .catch(() => {
        if (!cancelled) setPersons([]);
      });
    return () => {
      cancelled = true;
    };
  }, [open, companyId, facilityId, defaultPlace, defaultDate]);

  async function handleSubmit() {
    setError(null);
    const typingOwn = personId === null;
    if (typingOwn && !fullname.trim()) {
      setError('Zadaj meno osoby, ktorá protokol preberá.');
      return;
    }
    if (typingOwn && !roleTitle.trim()) {
      setError('Zadaj funkciu osoby — napríklad konateľ alebo vedúci zamestnanec.');
      return;
    }
    if (!place.trim()) {
      setError('Zadaj miesto podpisu.');
      return;
    }
    const blob = await padRef.current?.toBlob();
    if (!blob) {
      setError('Nechaj klienta podpísať sa na displeji.');
      return;
    }

    setSaving(true);
    try {
      const signature = await blobToDataUri(blob);
      const res = await Documents.handover(
        documentId,
        {
          ...(typingOwn
            ? { fullname: fullname.trim(), role_title: roleTitle.trim() }
            : { person_id: personId as number }),
          place: place.trim(),
          signed_on: signedOn,
          signed_at_time: signedTime,
          signature,
        },
        csrfToken,
      );
      toast.success(
        `Protokol ${documentNumber} podpísaný — vznikla verzia ${res.document.version}.`,
      );
      onSigned();
      onClose();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : offlineMessage(err, 'Podpis sa nepodarilo uložiť.'),
      );
    } finally {
      setSaving(false);
    }
  }

  const action = HANDOVER_ACTION_LABELS[documentType] ?? 'Prevzal na vedomie';

  return (
    <Dialog
      open={open}
      onClose={() => {
        if (!saving) onClose();
      }}
      title={`Prevzatie protokolu ${documentNumber}`}
      description={`Za organizáciu — ${action.toLowerCase()}. Podpisuje sa prevzatie protokolu, nie súhlas s jeho obsahom.`}
      maxWidthClassName="max-w-lg"
      dismissible={!saving}
    >
      <div className="flex flex-col gap-4">
        {persons === null ? (
          <div className="flex justify-center py-6 text-ink-400">
            <Spinner />
          </div>
        ) : (
          <>
            <Field label="Kto preberá" required>
              {(p) => (
                <Select
                  id={p.id}
                  value={personId === null ? '' : String(personId)}
                  onChange={(v) => setPersonId(v ? Number(v) : null)}
                  placeholder="— zadať meno ručne —"
                  leftIcon={<UserCheck className="size-4" />}
                  options={persons.map((person) => ({
                    value: String(person.id),
                    label: person.fullname,
                    description: person.role_title,
                  }))}
                />
              )}
            </Field>

            {personId === null && (
              <div className="flex animate-fade-up flex-col gap-3">
                <Field label="Meno a priezvisko" required>
                  {(p) => (
                    <Input
                      {...p}
                      value={fullname}
                      onChange={(e) => setFullname(e.target.value)}
                      placeholder="Ján Novák"
                    />
                  )}
                </Field>
                <Field
                  label="Funkcia"
                  required
                  hint="Klientom sú aj školy, obce a združenia — vyber alebo napíš vlastnú."
                >
                  {(p) => (
                    <>
                      <Input
                        {...p}
                        list="handover-roles"
                        value={roleTitle}
                        onChange={(e) => setRoleTitle(e.target.value)}
                        placeholder="konateľ"
                      />
                      <datalist id="handover-roles">
                        {roleSuggestions.map((r) => (
                          <option key={r} value={r} />
                        ))}
                      </datalist>
                    </>
                  )}
                </Field>
              </div>
            )}

            <div className="grid gap-3 sm:grid-cols-3">
              <Field label="Miesto" required className="sm:col-span-1">
                {(p) => (
                  <Input
                    {...p}
                    value={place}
                    onChange={(e) => setPlace(e.target.value)}
                    placeholder="Žilina"
                  />
                )}
              </Field>
              <Field label="Dátum" required>
                {(p) => (
                  <Input
                    {...p}
                    type="date"
                    value={signedOn}
                    onChange={(e) => setSignedOn(e.target.value)}
                  />
                )}
              </Field>
              <Field label="Čas" required>
                {(p) => (
                  <Input
                    {...p}
                    type="time"
                    value={signedTime}
                    onChange={(e) => setSignedTime(e.target.value)}
                  />
                )}
              </Field>
            </div>

            <Field label={action}>
              {() => (
                <SignaturePad
                  ref={padRef}
                  heightPx={170}
                  onEmptyChange={setPadEmpty}
                  className="rounded-xl border border-ink-200"
                />
              )}
            </Field>

            {error && (
              <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                {error}
              </p>
            )}

            <p className="text-xs text-ink-500">
              Protokol sa po podpise vygeneruje znova pod rovnakým číslom ako nová
              verzia. Ak klient podpisuje až na vytlačenom protokole, toto okno
              zavri — podpisové pole zostane prázdne.
            </p>

            <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
              <Button variant="secondary" onClick={onClose} disabled={saving}>
                Zavrieť
              </Button>
              <Button
                onClick={handleSubmit}
                loading={saving}
                disabled={padEmpty}
                leftIcon={<PenLine className="size-4" />}
              >
                Uložiť podpis
              </Button>
            </div>
          </>
        )}
      </div>
    </Dialog>
  );
}

function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function nowHhMm(): string {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function blobToDataUri(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}
