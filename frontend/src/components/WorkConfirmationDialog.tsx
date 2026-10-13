import { useLayoutEffect, useRef, useState } from 'react';
import { FileSignature } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { WorkConfirmations, type WorkConfirmation } from '@/api/workConfirmations';
import { ApiError } from '@/lib/api';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { ConfirmationActions } from '@/components/CompanyWorkConfirmations';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';

/**
 * Potvrdenie o vykonaní práce — block 1 / chapter 10.
 *
 * Not a professional document. The protocol is for the client and for the
 * inspection; this one is for the technician's own EMPLOYER, who does not care
 * what was found — only that their technician was there and how much they got
 * through. Today that is a paper timesheet the client signs.
 *
 * Which is why it lists protocol numbers and nothing else: no nedostatky, no
 * stavy, no verdicts. It covers a whole visit, so four úkony produce one sheet
 * rather than four.
 *
 * The times are optional and the only field on it that is not already recorded
 * elsewhere. Left blank, the row and the summary figure are simply left off —
 * an employer who does not track hours should not be handed a document with a
 * gap where the hours go.
 */
export function WorkConfirmationDialog({
  open,
  onClose,
  visitId,
  companyId,
  facilityId,
  companyName,
  facilityName,
  date,
  actCount,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  /** From a visit… */
  visitId?: number;
  /** …or from a company and a day, which is the "spätne z histórie" case. */
  companyId?: number;
  facilityId?: number;
  companyName: string;
  facilityName?: string | null;
  date: string;
  /** How many finished úkony will go on it, so the technician can sanity-check. */
  actCount?: number;
  /** Fired once the PDF exists, so a list of confirmations can refresh. */
  onCreated?: () => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [confirmedOn, setConfirmedOn] = useState(date);
  const [timeFrom, setTimeFrom] = useState('');
  const [timeTo, setTimeTo] = useState('');
  const [saving, setSaving] = useState(false);
  const [signing, setSigning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<WorkConfirmation | null>(null);
  const dateRef = useRef(date);
  dateRef.current = date;

  // Reset only when the dialog opens. A later parent render (the list
  // refreshing) must not wipe the PDF / e-mail / signature step.
  useLayoutEffect(() => {
    if (!open) return;
    setConfirmedOn(dateRef.current);
    setTimeFrom('');
    setTimeTo('');
    setError(null);
    setCreated(null);
    setSigning(false);
  }, [open]);

  async function handleSubmit() {
    setSaving(true);
    setError(null);
    try {
      const res = await WorkConfirmations.create(
        {
          visit_id: visitId,
          company_id: companyId,
          facility_id: facilityId,
          confirmed_on: confirmedOn,
          time_from: timeFrom || null,
          time_to: timeTo || null,
        },
        csrfToken,
      );
      setCreated(res.confirmation);
      onCreated?.();
      toast.success(`Potvrdenie ${res.document.number} vygenerované.`);
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : offlineMessage(err, 'Potvrdenie sa nepodarilo vygenerovať.'),
      );
    } finally {
      setSaving(false);
    }
  }

  function refreshCreated() {
    if (!created) return;
    onCreated?.();
    WorkConfirmations.list(created.company_id)
      .then((res) => {
        const fresh = res.items.find((item) => item.id === created.id);
        if (fresh) setCreated(fresh);
      })
      .catch(() => {
        // The list on the firm still reloads; the button label can wait.
      });
  }

  return (
    <Dialog
      open={open}
      onClose={() => {
        if (!saving && !signing) onClose();
      }}
      title="Potvrdenie o vykonaní práce"
      description={
        created
          ? 'Potvrdenie je uložené pri firme. Nájdeš ho aj neskôr v záložke Protokoly.'
          : 'Doklad pre tvojho zamestnávateľa — kde si bol a čo si urobil. Neobsahuje žiadne zistenia, len čísla protokolov.'
      }
      dismissible={!saving && !signing}
    >
      {created ? (
        <div className="flex flex-col gap-4">
          <div className="rounded-2xl bg-ink-50 px-3 py-2.5 text-xs text-ink-600">
            <p className="font-mono text-sm font-medium text-ink-900">{created.document_number}</p>
            <p className="mt-0.5">
              <span className="font-medium text-ink-800">{created.company_name}</span>
              {created.facility_name && ` · ${created.facility_name}`}
            </p>
          </div>
          <ConfirmationActions
            item={created}
            companyId={created.company_id}
            readOnly={false}
            onChanged={refreshCreated}
            onSigningChange={setSigning}
          />
          <div className="flex justify-end">
            <Button variant="secondary" onClick={onClose}>
              Zavrieť
            </Button>
          </div>
        </div>
      ) : (
      <div className="flex flex-col gap-4">
        <div className="rounded-2xl bg-ink-50 px-3 py-2.5 text-xs text-ink-600">
          <p>
            <span className="font-medium text-ink-800">{companyName}</span>
            {facilityName && ` · ${facilityName}`}
          </p>
          {actCount !== undefined && (
            <p className="mt-0.5">
              Zahrnuté budú {actCount}{' '}
              {actCount === 1 ? 'dokončený úkon' : actCount < 5 ? 'dokončené úkony' : 'dokončených úkonov'} s
              číslami protokolov.
            </p>
          )}
        </div>

        <Field label="Dátum" required>
          {(p) => (
            <Input
              {...p}
              type="date"
              value={confirmedOn}
              onChange={(e) => setConfirmedOn(e.target.value)}
            />
          )}
        </Field>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Čas od" hint="Voliteľné">
            {(p) => (
              <Input {...p} type="time" value={timeFrom} onChange={(e) => setTimeFrom(e.target.value)} />
            )}
          </Field>
          <Field label="Čas do" hint="Voliteľné">
            {(p) => (
              <Input {...p} type="time" value={timeTo} onChange={(e) => setTimeTo(e.target.value)} />
            )}
          </Field>
        </div>

        <p className="text-xs text-ink-500">
          Ak čas nevyplníš, na potvrdení sa riadok „Čas od – do" ani údaj o čase
          na prevádzke neuvedie.
        </p>

        {error && (
          <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </p>
        )}

        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Zrušiť
          </Button>
          <Button onClick={handleSubmit} loading={saving} leftIcon={<FileSignature className="size-4" />}>
            Vygenerovať potvrdenie
          </Button>
        </div>
      </div>
      )}
    </Dialog>
  );
}
