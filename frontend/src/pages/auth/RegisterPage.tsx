import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { Building2, Eye, EyeOff, Lock, Mail, Phone, User } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { useAuth } from '@/auth/AuthContext';
import { Billing } from '@/api/billing';
import { AuthLayout } from './AuthLayout';

type Period = 'monthly' | 'yearly';

export function RegisterPage() {
  const { register } = useAuth();

  const [fullname, setFullname] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [companyName, setCompanyName] = useState('');
  const [billingPeriod, setBillingPeriod] = useState<Period>('yearly');

  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  // Surface the mismatch only after the user has typed enough that they're
  // clearly past the "still typing the same thing" stage — saves one
  // distracting red flash while they re-type the same characters.
  const passwordMismatch =
    passwordConfirm.length > 0 && passwordConfirm !== password.slice(0, passwordConfirm.length);
  const passwordsMatch = password.length > 0 && password === passwordConfirm;

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (password !== passwordConfirm) {
      setError('Heslá sa nezhodujú. Skontroluj druhé pole.');
      return;
    }
    setError(null);
    setLoading(true);
    try {
      const csrfToken = await register({
        fullname,
        email,
        phone: phone || undefined,
        password,
        invoice_company_name: companyName,
        billing_period: billingPeriod,
      });
      const checkout = await Billing.checkout(billingPeriod, csrfToken);
      window.location.assign(checkout.url);
      // Page is redirecting — keep loading state, don't reset.
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo, skús to znova.');
      setLoading(false);
    }
  }

  return (
    <AuthLayout
      title="Registrácia"
      subtitle="Vytvor si konto, vyber plán a zaplať bezpečne cez Stripe — prístup získaš okamžite."
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
                type={showPassword ? 'text' : 'password'}
                autoComplete="new-password"
                required
                leftIcon={<Lock className="size-4" />}
                rightSlot={
                  <PasswordToggle
                    visible={showPassword}
                    onClick={() => setShowPassword((v) => !v)}
                  />
                }
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                minLength={8}
              />
            )}
          </Field>

          <Field
            label="Potvrdenie hesla"
            required
            hint={passwordsMatch ? 'Heslá sa zhodujú.' : undefined}
            error={passwordMismatch ? 'Heslá sa nezhodujú.' : undefined}
          >
            {(p) => (
              <Input
                {...p}
                type={showPassword ? 'text' : 'password'}
                autoComplete="new-password"
                required
                leftIcon={<Lock className="size-4" />}
                rightSlot={
                  <PasswordToggle
                    visible={showPassword}
                    onClick={() => setShowPassword((v) => !v)}
                  />
                }
                value={passwordConfirm}
                onChange={(e) => setPasswordConfirm(e.target.value)}
                placeholder="••••••••"
                minLength={8}
                invalid={passwordMismatch}
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

          <div className="flex flex-col gap-1.5" role="group" aria-labelledby="billing-period-label">
            <span
              id="billing-period-label"
              className="text-xs font-semibold uppercase tracking-wide text-ink-500"
            >
              Predplatné
            </span>
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
              Platba prebehne bezpečne cez Stripe. Po úhrade získaš okamžitý plný prístup.
            </p>
          </div>

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}

          <Button
            type="submit"
            loading={loading}
            disabled={password.length === 0 || password !== passwordConfirm}
            className="mt-2 w-full"
          >
            Vytvoriť konto a zaplatiť
          </Button>
        </form>
      </Card>
    </AuthLayout>
  );
}

function PasswordToggle({
  visible,
  onClick,
}: {
  visible: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={visible ? 'Skryť heslo' : 'Zobraziť heslo'}
      title={visible ? 'Skryť heslo' : 'Zobraziť heslo'}
      // tabIndex=-1 keeps the field focus order natural (label → input →
      // next field) instead of stopping on the toggle in between.
      tabIndex={-1}
      className="grid size-9 place-items-center rounded-xl text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
    >
      {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
    </button>
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
