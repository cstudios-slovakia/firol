import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Building2, Lock, Mail, Phone, User } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { useAuth } from '@/auth/AuthContext';
import { AuthLayout } from './AuthLayout';

type Period = 'monthly' | 'yearly';

export function RegisterPage() {
  const navigate = useNavigate();
  const { register } = useAuth();

  const [fullname, setFullname] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [companyName, setCompanyName] = useState('');
  const [billingPeriod, setBillingPeriod] = useState<Period>('yearly');

  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await register({
        fullname,
        email,
        phone: phone || undefined,
        password,
        invoice_company_name: companyName,
        billing_period: billingPeriod,
      });
      navigate('/', { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo, skús to znova.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout
      title="Registrácia"
      subtitle="Vytvor si konto a začni s 14-dňovou skúšobnou dobou bez obmedzení."
      footer={
        <>
          Už máš konto?{' '}
          <Link to="/login" className="font-medium text-firol-600 hover:text-firol-700">
            Prihlás sa
          </Link>
        </>
      }
    >
      <Card className="p-6">
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field label="Meno a priezvisko" required>
            {(p) => (
              <Input
                {...p}
                autoComplete="name"
                required
                leftIcon={<User className="size-4" />}
                value={fullname}
                onChange={(e) => setFullname(e.target.value)}
                placeholder="Ján Novák"
              />
            )}
          </Field>

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

          <Field label="Telefón" hint="Voliteľné">
            {(p) => (
              <Input
                {...p}
                type="tel"
                autoComplete="tel"
                leftIcon={<Phone className="size-4" />}
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="+421 901 123 456"
              />
            )}
          </Field>

          <Field label="Heslo" hint="Minimálne 8 znakov" required>
            {(p) => (
              <Input
                {...p}
                type="password"
                autoComplete="new-password"
                required
                leftIcon={<Lock className="size-4" />}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                minLength={8}
              />
            )}
          </Field>

          <Field label="Fakturačné meno spoločnosti" required>
            {(p) => (
              <Input
                {...p}
                required
                leftIcon={<Building2 className="size-4" />}
                value={companyName}
                onChange={(e) => setCompanyName(e.target.value)}
                placeholder="Firol s. r. o."
              />
            )}
          </Field>

          <fieldset className="flex flex-col gap-1.5">
            <legend className="text-xs font-semibold uppercase tracking-wide text-ink-500">
              Predplatné
            </legend>
            <div className="grid grid-cols-2 gap-2">
              <PeriodOption
                active={billingPeriod === 'yearly'}
                onClick={() => setBillingPeriod('yearly')}
                label="Ročné"
                price="199 €"
                badge="−13 %"
              />
              <PeriodOption
                active={billingPeriod === 'monthly'}
                onClick={() => setBillingPeriod('monthly')}
                label="Mesačné"
                price="19 €"
              />
            </div>
            <p className="text-xs text-ink-400">
              Najprv 14 dní zadarmo. Až po skončení skúšobnej doby ti bude účtovaná zvolená suma.
            </p>
          </fieldset>

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}

          <Button type="submit" loading={loading} className="mt-2 w-full">
            Vytvoriť konto
          </Button>
        </form>
      </Card>
    </AuthLayout>
  );
}

function PeriodOption({
  active,
  onClick,
  label,
  price,
  badge,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  price: string;
  badge?: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'relative flex flex-col items-start gap-0.5 rounded-2xl border-2 px-4 py-3 text-left transition-all duration-200',
        active
          ? 'border-firol-500 bg-firol-50/70 shadow-[var(--shadow-soft)]'
          : 'border-ink-200 bg-white hover:border-ink-300',
      )}
      aria-pressed={active}
    >
      <span
        className={cn(
          'text-sm font-medium',
          active ? 'text-firol-700' : 'text-ink-700',
        )}
      >
        {label}
      </span>
      <span className="text-base font-semibold text-ink-900">{price}</span>
      {badge && (
        <span className="absolute right-2 top-2 rounded-full bg-firol-100 px-1.5 py-0.5 text-[10px] font-semibold text-firol-700">
          {badge}
        </span>
      )}
    </button>
  );
}
