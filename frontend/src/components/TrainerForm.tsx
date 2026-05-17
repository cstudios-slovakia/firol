import { useState, type FormEvent } from 'react';
import { Hash, User } from 'lucide-react';
import { Trainers, type Trainer, type TrainerPayload } from '@/api/trainers';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/auth/AuthContext';

type Props = {
  initial?: Trainer;
  onSaved: (trainer: Trainer) => void;
  onCancel?: () => void;
  submitLabel?: string;
  mode?: 'create' | 'edit';
};

export function TrainerForm({
  initial,
  onSaved,
  onCancel,
  submitLabel,
  mode = initial ? 'edit' : 'create',
}: Props) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [fullname, setFullname] = useState(initial?.fullname ?? '');
  const [certNumber, setCertNumber] = useState(initial?.certification_number ?? '');

  const [submitting, setSubmitting] = useState(false);
  const [nameError, setNameError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!fullname.trim()) {
      setNameError('Doplň meno školiteľa.');
      return;
    }
    setNameError(null);
    setError(null);
    setSubmitting(true);
    const payload: TrainerPayload = {
      fullname: fullname.trim(),
      certification_number: certNumber.trim() || null,
    };
    try {
      const res = mode === 'edit' && initial
        ? await Trainers.update(initial.id, payload, csrfToken)
        : await Trainers.create(payload, csrfToken);
      onSaved(res.trainer);
      toast.success(mode === 'edit' ? 'Školiteľ uložený' : 'Školiteľ vytvorený');
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
      <Field label="Meno a priezvisko" required error={nameError}>
        {(p) => (
          <Input
            {...p}
            required
            leftIcon={<User className="size-4" />}
            value={fullname}
            onChange={(e) => { setFullname(e.target.value); if (nameError) setNameError(null); }}
            placeholder="Ján Novák"
            autoFocus={mode === 'create'}
          />
        )}
      </Field>

      <Field label="Číslo oprávnenia" hint="Voliteľné — pôjde do PDF protokolu">
        {(p) => (
          <Input
            {...p}
            leftIcon={<Hash className="size-4" />}
            value={certNumber}
            onChange={(e) => setCertNumber(e.target.value)}
            placeholder="OPP-1234/2024"
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
          {submitLabel ?? (mode === 'edit' ? 'Uložiť zmeny' : 'Vytvoriť školiteľa')}
        </Button>
      </div>
    </form>
  );
}
