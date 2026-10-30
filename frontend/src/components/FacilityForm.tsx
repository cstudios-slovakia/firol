import { useState, type FormEvent } from 'react';
import { Building, Map, Mailbox, MapPin, NotebookPen, User, Warehouse } from 'lucide-react';
import { Facilities, type Facility, type FacilityPayload } from '@/api/facilities';
import { ApiError } from '@/lib/api';
import { facilityCreateOptimistic } from '@/lib/offlineEntities';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/auth/AuthContext';
import { formatAddress } from '@/lib/address';

/** The firm's seat, as far as the "Rovnaká adresa ako sídlo firmy" button needs it. */
export type CompanyAddress = {
  street: string | null;
  postal_code: string | null;
  city: string | null;
};

type FacilityFormProps = {
  /** Pre-fill an existing facility for edit mode. */
  initial?: Facility;
  /** When creating a new facility, the company it should attach to. */
  companyId?: number;
  /** The firm's address; without one the "same address as the seat" button is hidden. */
  companyAddress?: CompanyAddress | null;
  /** Called after a successful save with the resulting facility. */
  onSaved: (facility: Facility) => void;
  /** Optional cancel button — only rendered if provided. */
  onCancel?: () => void;
  /** Override the submit button text. */
  submitLabel?: string;
  /** Render mode — used for label switches. */
  mode?: 'create' | 'edit';
};

/**
 * Shared facility create/edit form. Used by the in-dropdown
 * "Pridať prevádzku" dialog so the validation, error handling and field
 * layout stay in one place. The standalone /facilities/:id/edit screen
 * keeps its own copy because it also handles archive — that's a
 * different surface, and inlining a destructive action into a quick-add
 * dialog would be a footgun.
 */
export function FacilityForm({
  initial,
  companyId,
  companyAddress,
  onSaved,
  onCancel,
  submitLabel,
  mode = initial ? 'edit' : 'create',
}: FacilityFormProps) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [name, setName] = useState(initial?.name ?? '');
  const [street, setStreet] = useState(initial?.street ?? '');
  const [postalCode, setPostalCode] = useState(initial?.postal_code ?? '');
  const [city, setCity] = useState(initial?.city ?? '');
  const [contactPerson, setContactPerson] = useState(initial?.contact_person ?? '');
  const [notes, setNotes] = useState(initial?.notes ?? '');

  const [submitting, setSubmitting] = useState(false);
  const [nameError, setNameError] = useState<string | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);

  // Spec 5 — offered only when the firm has an address to take over (street
  // or obec, like the prototype).
  const seatAddress = formatAddress(companyAddress?.street, companyAddress?.postal_code, companyAddress?.city);
  const canTakeSeat = mode === 'create' && Boolean(companyAddress?.street?.trim() || companyAddress?.city?.trim());

  function takeSeatAddress() {
    setStreet(companyAddress?.street ?? '');
    setPostalCode(companyAddress?.postal_code ?? '');
    setCity(companyAddress?.city ?? '');
    toast.success('Adresa prevzatá zo sídla firmy');
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!name.trim()) {
      setNameError('Doplň názov prevádzky.');
      return;
    }
    if (mode === 'create' && companyId === undefined) {
      setApiError('Najprv vyber firmu.');
      return;
    }
    setNameError(null);
    setApiError(null);
    setSubmitting(true);
    const payload: FacilityPayload = {
      name: name.trim(),
      street: street.trim() || undefined,
      postal_code: postalCode.trim() || undefined,
      city: city.trim() || undefined,
      contact_person: contactPerson.trim() || undefined,
      notes: notes.trim() || undefined,
    };
    try {
      const optimistic = facilityCreateOptimistic({
        companyId: companyId!,
        name: payload.name,
        street: payload.street ?? null,
        postal_code: payload.postal_code ?? null,
        city: payload.city ?? null,
        contact_person: payload.contact_person ?? null,
        notes: payload.notes ?? null,
      });
      const res = mode === 'edit' && initial
        ? await Facilities.update(initial.id, payload, csrfToken)
        : await Facilities.createUnderCompany(companyId!, payload, csrfToken, optimistic);
      onSaved(res.facility);
      toast.success(mode === 'edit' ? 'Prevádzka uložená' : 'Prevádzka vytvorená');
    } catch (err) {
      // Offline edit: queued + cache patched, navigate on as if saved.
      if (handleOfflineSave(err, toast)) {
        if (initial) onSaved(initial);
        return;
      }
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setApiError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
      <Field label="Názov prevádzky" required error={nameError}>
        {(p) => (
          <Input
            {...p}
            required
            leftIcon={<Warehouse className="size-4" />}
            value={name}
            onChange={(e) => { setName(e.target.value); if (nameError) setNameError(null); }}
            placeholder="Sklad A"
            autoFocus={mode === 'create'}
          />
        )}
      </Field>

      {canTakeSeat && (
        <div className="flex flex-col gap-1.5">
          <button
            type="button"
            onClick={takeSeatAddress}
            className="flex items-start gap-3 rounded-2xl border border-dashed border-firol-300 bg-firol-50/40 px-3.5 py-3 text-left transition-all duration-200 hover:border-firol-400 hover:bg-firol-50 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
          >
            <Building className="mt-0.5 size-4 shrink-0 text-firol-600" />
            <span className="min-w-0">
              <span className="block text-sm font-medium text-ink-800">Rovnaká adresa ako sídlo firmy</span>
              <span className="mt-0.5 block break-words text-xs text-ink-500">{seatAddress}</span>
            </span>
          </button>
          <p className="text-xs text-ink-400">Doplní ulicu, PSČ aj obec. Potom sa to dá prepísať.</p>
        </div>
      )}

      <Field label="Ulica a číslo">
        {(p) => (
          <Input
            {...p}
            leftIcon={<MapPin className="size-4" />}
            value={street}
            onChange={(e) => setStreet(e.target.value)}
            placeholder="Priemyselná 5"
          />
        )}
      </Field>

      <div className="grid grid-cols-3 gap-3">
        <Field label="PSČ" className="col-span-1">
          {(p) => (
            <Input
              {...p}
              leftIcon={<Mailbox className="size-4" />}
              value={postalCode}
              onChange={(e) => setPostalCode(e.target.value)}
              placeholder="851 01"
            />
          )}
        </Field>

        <Field label="Obec" className="col-span-2">
          {(p) => (
            <Input
              {...p}
              leftIcon={<Map className="size-4" />}
              value={city}
              onChange={(e) => setCity(e.target.value)}
              placeholder="Bratislava"
            />
          )}
        </Field>
      </div>

      <Field label="Kontaktná osoba">
        {(p) => (
          <Input
            {...p}
            leftIcon={<User className="size-4" />}
            value={contactPerson}
            onChange={(e) => setContactPerson(e.target.value)}
            placeholder="Peter Vedúci"
          />
        )}
      </Field>

      <Field label="Poznámky" hint="Napríklad prístup, otváracie hodiny, špecifiká">
        {(p) => (
          <div className="relative">
            <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
              <NotebookPen className="size-4" />
            </span>
            <textarea
              id={p.id}
              aria-invalid={p['aria-invalid']}
              rows={3}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Vstup len cez recepciu."
              className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
            />
          </div>
        )}
      </Field>

      {apiError && (
        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          {apiError}
        </div>
      )}

      <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-end">
        {onCancel && (
          <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
            Zrušiť
          </Button>
        )}
        <Button type="submit" loading={submitting}>
          {submitLabel ?? (mode === 'edit' ? 'Uložiť zmeny' : 'Vytvoriť prevádzku')}
        </Button>
      </div>
    </form>
  );
}
