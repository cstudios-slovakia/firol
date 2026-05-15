import { useState, type FormEvent } from 'react';
import { Building2, Hash, MapPin, Phone } from 'lucide-react';
import { Companies, type Company, type CompanyPayload } from '@/api/companies';
import { ApiError } from '@/lib/api';
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
  const [address, setAddress] = useState(initial?.address ?? '');
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
      ico: ico.trim() || undefined,
      address: address.trim() || undefined,
      contact: contact.trim() || undefined,
    };
    try {
      const res = mode === 'edit' && initial
        ? await Companies.update(initial.id, payload, csrfToken)
        : await Companies.create(payload, csrfToken);
      onSaved(res.company);
      toast.success(mode === 'edit' ? 'Firma uložená' : 'Firma vytvorená');
    } catch (err) {
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

      <Field label="Adresa">
        {(p) => (
          <Input
            {...p}
            leftIcon={<MapPin className="size-4" />}
            value={address}
            onChange={(e) => setAddress(e.target.value)}
            placeholder="Hlavná 12, 851 01 Bratislava"
          />
        )}
      </Field>

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
