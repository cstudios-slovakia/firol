import { useState, type FormEvent } from 'react';
import { Building2, Hash, MapPin, Phone, Mailbox, Map } from 'lucide-react';
import { Companies, type Company, type CompanyPayload } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { companyCreateOptimistic } from '@/lib/offlineEntities';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/auth/AuthContext';

type CompanyFormProps = {
  /** Pass to render an Edit form prefilled with this row. Omit for create. */
  initial?: Company;
  /** Called after a successful save with the resulting company. */
  onSaved: (company: Company) => void;
  /** Optional cancel button — only rendered if provided. */
  onCancel?: () => void;
  /** Override the submit button text. */
  submitLabel?: string;
  /** Render mode — used for label switches. */
  mode?: 'create' | 'edit';
};

/**
 * Shared company create/edit form. Used by both the standalone
 * /companies/new page and the in-dropdown "Pridať firmu" dialog so the
 * validation, error handling and field layout stay in one place.
 */
export function CompanyForm({
  initial,
  onSaved,
  onCancel,
  submitLabel,
  mode = initial ? 'edit' : 'create',
}: CompanyFormProps) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [name, setName] = useState(initial?.name ?? '');
  const [ico, setIco] = useState(initial?.ico ?? '');
  const [street, setStreet] = useState(initial?.street ?? '');
  const [postalCode, setPostalCode] = useState(initial?.postal_code ?? '');
  const [city, setCity] = useState(initial?.city ?? '');
  const [contact, setContact] = useState(initial?.contact ?? '');

  const [submitting, setSubmitting] = useState(false);
  const [nameError, setNameError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!name.trim()) {
      setNameError('Doplň názov firmy.');
      return;
    }
    setNameError(null);
    setError(null);
    setSubmitting(true);
    const payload: CompanyPayload = {
      name: name.trim(),
      ico: ico.replace(/\s+/g, '') || undefined,
      street: street.trim() || undefined,
      postal_code: postalCode.trim() || undefined,
      city: city.trim() || undefined,
      contact: contact.trim() || undefined,
    };
    try {
      const optimistic = companyCreateOptimistic({
        name: payload.name,
        ico: payload.ico ?? null,
        street: payload.street ?? null,
        postal_code: payload.postal_code ?? null,
        city: payload.city ?? null,
        contact: payload.contact ?? null,
      });
      const res = mode === 'edit' && initial
        ? await Companies.update(initial.id, payload, csrfToken)
        : await Companies.create(payload, csrfToken, optimistic);
      onSaved(res.company);
      toast.success(mode === 'edit' ? 'Firma uložená' : 'Firma vytvorená');
    } catch (err) {
      // Offline edit: the PATCH is queued and the local cache already reflects
      // the change — navigate on as if saved; the detail shows the "waiting to
      // save" banner. (Create offline resolves normally via the optimistic spec.)
      if (handleOfflineSave(err, toast)) {
        if (initial) onSaved(initial);
        return;
      }
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
      <Field label="Názov firmy" required error={nameError}>
        {(p) => (
          <Input
            {...p}
            required
            leftIcon={<Building2 className="size-4" />}
            value={name}
            onChange={(e) => { setName(e.target.value); if (nameError) setNameError(null); }}
            placeholder="ALFA Trade s. r. o."
            autoFocus={mode === 'create'}
          />
        )}
      </Field>

      <Field label="IČO">
        {(p) => (
          <Input
            {...p}
            inputMode="numeric"
            leftIcon={<Hash className="size-4" />}
            value={ico}
            onChange={(e) => setIco(e.target.value)}
            placeholder="12345678"
          />
        )}
      </Field>

      <Field label="Ulica a číslo">
        {(p) => (
          <Input
            {...p}
            leftIcon={<MapPin className="size-4" />}
            value={street}
            onChange={(e) => setStreet(e.target.value)}
            placeholder="Hlavná 12"
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

      <Field label="Kontakt" hint="Napríklad email a telefón na kontaktnú osobu">
        {(p) => (
          <Input
            {...p}
            leftIcon={<Phone className="size-4" />}
            value={contact}
            onChange={(e) => setContact(e.target.value)}
            placeholder="info@firma.sk · +421 900 123 456"
          />
        )}
      </Field>

      {error && (
        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          {error}
        </div>
      )}

      <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-end">
        {onCancel && (
          <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
            Zrušiť
          </Button>
        )}
        <Button type="submit" loading={submitting}>
          {submitLabel ?? (mode === 'edit' ? 'Uložiť zmeny' : 'Vytvoriť firmu')}
        </Button>
      </div>
    </form>
  );
}
