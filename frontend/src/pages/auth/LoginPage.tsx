import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Mail } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/auth/AuthContext';
import { AuthLayout } from './AuthLayout';

export function LoginPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { login } = useAuth();
  // `?next=` lets flows like /invite/accept bounce through login and
  // return to the original page once the user is authed. Only allow
  // same-origin relative paths so a leaked link cannot redirect off-site.
  const rawNext = searchParams.get('next');
  const nextPath = rawNext && rawNext.startsWith('/') && !rawNext.startsWith('//') ? rawNext : '/';
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; password?: string }>({});
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    const errs: typeof fieldErrors = {};
    if (!email.trim()) errs.email = 'Zadaj e-mailovú adresu.';
    if (!password) errs.password = 'Zadaj heslo.';
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs);
      return;
    }
    setFieldErrors({});
    setError(null);
    setLoading(true);
    try {
      await login(email, password);
      navigate(nextPath, { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo, skús to znova.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout
      title="Prihlásenie"
      subtitle="Vitaj späť. Zadaj prístupové údaje pre pokračovanie."
      footer={
        <>
          Ešte nemáš účet?{' '}
          <Link to="/register" className="font-semibold text-firol-600 underline-offset-2 hover:text-firol-700 hover:underline">
            Zaregistruj sa
          </Link>
        </>
      }
    >
      <Card className="p-6">
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field label="Email" required error={fieldErrors.email}>
            {(p) => (
              <Input
                {...p}
                type="email"
                autoComplete="email"
                required
                leftIcon={<Mail className="size-4" />}
                value={email}
                onChange={(e) => { setEmail(e.target.value); if (fieldErrors.email) setFieldErrors((prev) => ({ ...prev, email: undefined })); }}
                placeholder="ty@firma.sk"
              />
            )}
          </Field>

          <Field label="Heslo" required error={fieldErrors.password}>
            {(p) => (
              <PasswordInput
                {...p}
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => { setPassword(e.target.value); if (fieldErrors.password) setFieldErrors((prev) => ({ ...prev, password: undefined })); }}
                placeholder="••••••••"
              />
            )}
          </Field>

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}
          {Object.keys(fieldErrors).length > 0 && (
            <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              Formulár obsahuje nevyplnené povinné polia.
            </p>
          )}

          <Button type="submit" loading={loading} className="mt-2 w-full">
            Prihlásiť sa
          </Button>

          <Link
            to="/password-reset"
            className="self-center text-sm text-ink-500 hover:text-ink-700"
          >
            Zabudol som heslo
          </Link>
        </form>
      </Card>
    </AuthLayout>
  );
}
