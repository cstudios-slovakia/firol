import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { Mail } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { api, ApiError } from '@/lib/api';
import { AuthLayout } from './AuthLayout';

export function PasswordResetRequestPage() {
  const [email, setEmail] = useState('');
  const [emailError, setEmailError] = useState<string | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!email.trim()) { setEmailError('Doplň e-mailovú adresu.'); return; }
    setEmailError(null);
    setApiError(null);
    setLoading(true);
    try {
      await api('/api/auth/password-reset/request', {
        method: 'POST',
        body: { email },
      });
      setSent(true);
    } catch (err) {
      setApiError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo, skús to znova.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout
      title="Obnova hesla"
      subtitle="Pošleme ti odkaz na nastavenie nového hesla."
      footer={
        <Link to="/login" className="font-medium text-firol-600 hover:text-firol-700">
          ← Späť na prihlásenie
        </Link>
      }
    >
      <Card className="p-6">
        {sent ? (
          <div className="flex flex-col items-center gap-3 py-3 text-center">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-100 text-firol-600">
              <Mail className="size-6" />
            </div>
            <h2 className="text-base font-semibold text-ink-900">Skontroluj email</h2>
            <p className="text-sm text-ink-500">
              Ak je tvoj email v systéme, dostaneš odkaz na obnovu hesla v priebehu pár minút.
            </p>
          </div>
        ) : (
          <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
            <Field label="Email" required error={emailError}>
              {(p) => (
                <Input
                  {...p}
                  type="email"
                  autoComplete="email"
                  required
                  leftIcon={<Mail className="size-4" />}
                  value={email}
                  onChange={(e) => { setEmail(e.target.value); if (emailError) setEmailError(null); }}
                  placeholder="ty@firma.sk"
                />
              )}
            </Field>

            {apiError && (
              <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                {apiError}
              </div>
            )}

            <Button type="submit" loading={loading} className="mt-2 w-full">
              Poslať odkaz
            </Button>
          </form>
        )}
      </Card>
    </AuthLayout>
  );
}
