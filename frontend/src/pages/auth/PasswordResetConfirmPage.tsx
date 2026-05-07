import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Lock } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { api, ApiError } from '@/lib/api';
import { AuthLayout } from './AuthLayout';

export function PasswordResetConfirmPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';

  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [done, setDone] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await api('/api/auth/password-reset/confirm', {
        method: 'POST',
        body: { token, password },
      });
      setDone(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo, skús to znova.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout
      title="Nové heslo"
      subtitle="Zadaj nové heslo k tvojmu kontu."
      footer={
        <Link to="/login" className="font-medium text-firol-600 hover:text-firol-700">
          ← Späť na prihlásenie
        </Link>
      }
    >
      <Card className="p-6">
        {!token ? (
          <p className="text-sm text-status-bad">
            Chýba token — otvor odkaz z emailu znova.
          </p>
        ) : done ? (
          <div className="flex flex-col gap-3 py-2 text-center">
            <h2 className="text-base font-semibold text-ink-900">Heslo bolo zmenené</h2>
            <p className="text-sm text-ink-500">Teraz sa prihlás novým heslom.</p>
            <Link
              to="/login"
              className="self-center rounded-2xl bg-firol-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-firol-600"
            >
              Prejsť na prihlásenie
            </Link>
          </div>
        ) : (
          <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
            <Field label="Nové heslo" hint="Minimálne 8 znakov" required>
              {(p) => (
                <Input
                  {...p}
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={8}
                  leftIcon={<Lock className="size-4" />}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                />
              )}
            </Field>

            {error && (
              <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                {error}
              </div>
            )}

            <Button type="submit" loading={loading} className="mt-2 w-full">
              Nastaviť nové heslo
            </Button>
          </form>
        )}
      </Card>
    </AuthLayout>
  );
}
