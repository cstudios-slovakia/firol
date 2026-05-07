import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Lock, Mail } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/auth/AuthContext';
import { AuthLayout } from './AuthLayout';

export function LoginPage() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await login(email, password);
      navigate('/', { replace: true });
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
          <Link to="/register" className="font-medium text-firol-600 hover:text-firol-700">
            Zaregistruj sa
          </Link>
        </>
      }
    >
      <Card className="p-6">
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field label="Email" required>
            {(p) => (
              <Input
                {...p}
                type="email"
                autoComplete="email"
                required
                leftIcon={<Mail className="size-4" />}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="ty@firma.sk"
              />
            )}
          </Field>

          <Field label="Heslo" required>
            {(p) => (
              <Input
                {...p}
                type="password"
                autoComplete="current-password"
                required
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
