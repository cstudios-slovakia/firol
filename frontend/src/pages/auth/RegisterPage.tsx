import { useState, useEffect, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Building2, Mail, Phone, Sparkles, User } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { ApiError, buildUrl } from '@/lib/api';
import { cn } from '@/lib/cn';
import { LEGAL_PRIVACY_URL, LEGAL_VOP_URL } from '@/lib/legal';
import { useAuth, type RegistrationPlan } from '@/auth/AuthContext';
import { AuthLayout } from './AuthLayout';

function formatEur(n: number): string {
  return n.toLocaleString('sk-SK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [fullname, setFullname] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [companyName, setCompanyName] = useState('');
  const [plan, setPlan] = useState<RegistrationPlan>('trial');

  const [trialDays, setTrialDays] = useState<number | null>(null);
  const [priceMonthly, setPriceMonthly] = useState<number | null>(null);
  const [priceYearly, setPriceYearly] = useState<number | null>(null);
  const [vatPercent, setVatPercent] = useState<number | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{ fullname?: string; email?: string; password?: string; passwordConfirm?: string; companyName?: string; terms?: string }>({});
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    fetch(buildUrl('/api/public/settings'))
      .then((r) => r.json())
      .then((data) => {
        if (typeof data.trial_days === 'number') setTrialDays(data.trial_days);
        if (typeof data.price_monthly_eur === 'number') setPriceMonthly(data.price_monthly_eur);
        if (typeof data.price_yearly_eur === 'number') setPriceYearly(data.price_yearly_eur);
        if (typeof data.vat_rate_percent === 'number') setVatPercent(data.vat_rate_percent);
      })
      .catch(() => {});
  }, []);

  const priceMonthlyWithVat =
    priceMonthly !== null && vatPercent !== null ? priceMonthly * (1 + vatPercent / 100) : null;
  const priceYearlyWithVat =
    priceYearly !== null && vatPercent !== null ? priceYearly * (1 + vatPercent / 100) : null;

  // Surface the mismatch only after the user has typed enough that they're
  // clearly past the "still typing the same thing" stage — saves one
  // distracting red flash while they re-type the same characters.
  const passwordMismatch =
    passwordConfirm.length > 0 && passwordConfirm !== password.slice(0, passwordConfirm.length);
  const passwordsMatch = password.length > 0 && password === passwordConfirm;
  // Mandatory legal declaration (change request 3.1). Never pre-ticked, and
  // the submit button stays disabled until it is checked.
  const [termsAccepted, setTermsAccepted] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    const errs: typeof fieldErrors = {};
    if (!fullname.trim()) errs.fullname = 'Zadaj meno a priezvisko.';
    if (!email.trim()) errs.email = 'Zadaj e-mailovú adresu.';
    if (!password) errs.password = 'Zadaj heslo.';
    if (password !== passwordConfirm) errs.passwordConfirm = 'Heslá sa nezhodujú. Skontroluj druhé pole.';
    if (!companyName.trim()) errs.companyName = 'Zadaj fakturačné meno spoločnosti.';
    if (!termsAccepted) errs.terms = 'Bez potvrdenia vyhlásenia nie je možné vytvoriť účet.';
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs);
      return;
    }
    setFieldErrors({});
    setError(null);
    setLoading(true);
    try {
      await register({
        fullname,
        email,
        phone: phone || undefined,
        password,
        invoice_company_name: companyName,
        billing_period: plan,
        terms_accepted: true,
      });
      // Trial users go straight into the app. Paid-plan users land on a dedicated
      // billing-details page that gates access to the app until they
      // complete checkout on Stripe.
      if (plan === 'trial') {
        navigate('/');
      } else {
        navigate('/onboarding/billing');
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo, skús to znova.');
      setLoading(false);
    }
  }

  return (
    <AuthLayout
      wide
      title="Registrácia"
      subtitle={
        <>
          Vytvor si konto a vyber si plán. <strong className="font-semibold text-ink-700">Skúšobnú verziu spustíš hneď a bez platby</strong> — pri ročnom či mesačnom predplatnom zaplatíš cez Stripe a okamžite získaš plný prístup.
        </>
      }
      footer={
        <>
          Už máš konto?{' '}
          <Link to="/login" className="font-semibold text-firol-600 underline-offset-2 hover:text-firol-700 hover:underline">
            Prihlás sa
          </Link>
        </>
      }
    >
      <Card className="p-6">
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field label="Meno a priezvisko" required error={fieldErrors.fullname}>
            {(p) => (
              <Input
                {...p}
                autoComplete="name"
                required
                leftIcon={<User className="size-4" />}
                value={fullname}
                onChange={(e) => { setFullname(e.target.value); if (fieldErrors.fullname) setFieldErrors((prev) => ({ ...prev, fullname: undefined })); }}
                placeholder="Ján Novák"
              />
            )}
          </Field>

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

          <Field label="Heslo" hint={fieldErrors.password ? undefined : 'Minimálne 8 znakov'} required error={fieldErrors.password}>
            {(p) => (
              <PasswordInput
                {...p}
                autoComplete="new-password"
                required
                value={password}
                onChange={(e) => { setPassword(e.target.value); if (fieldErrors.password) setFieldErrors((prev) => ({ ...prev, password: undefined })); }}
                placeholder="••••••••"
                minLength={8}
              />
            )}
          </Field>

          <Field
            label="Potvrdenie hesla"
            required
            hint={passwordsMatch ? 'Heslá sa zhodujú.' : undefined}
            error={fieldErrors.passwordConfirm ?? (passwordMismatch ? 'Heslá sa nezhodujú.' : undefined)}
          >
            {(p) => (
              <PasswordInput
                {...p}
                autoComplete="new-password"
                required
                value={passwordConfirm}
                onChange={(e) => { setPasswordConfirm(e.target.value); if (fieldErrors.passwordConfirm) setFieldErrors((prev) => ({ ...prev, passwordConfirm: undefined })); }}
                placeholder="••••••••"
                minLength={8}
                invalid={passwordMismatch}
              />
            )}
          </Field>

          <Field label="Fakturačné meno spoločnosti" required error={fieldErrors.companyName}>
            {(p) => (
              <Input
                {...p}
                required
                leftIcon={<Building2 className="size-4" />}
                value={companyName}
                onChange={(e) => { setCompanyName(e.target.value); if (fieldErrors.companyName) setFieldErrors((prev) => ({ ...prev, companyName: undefined })); }}
                placeholder="Vaša firma s. r. o."
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
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
              <PeriodOption
                active={plan === 'trial'}
                onClick={() => setPlan('trial')}
                label="Skúšobná verzia"
                price={trialDays !== null ? `${trialDays} dní zdarma` : '— dní zdarma'}
                badge="Štart"
                icon={<Sparkles className="size-3.5" />}
              />
              <PeriodOption
                active={plan === 'yearly'}
                onClick={() => setPlan('yearly')}
                label="Ročné"
                price={priceYearly !== null ? `${formatEur(priceYearly)} €` : '— €'}
                priceWithVat={priceYearlyWithVat !== null ? `${formatEur(priceYearlyWithVat)} €` : null}
                badge="−13 %"
              />
              <PeriodOption
                active={plan === 'monthly'}
                onClick={() => setPlan('monthly')}
                label="Mesačné"
                price={priceMonthly !== null ? `${formatEur(priceMonthly)} €` : '— €'}
                priceWithVat={priceMonthlyWithVat !== null ? `${formatEur(priceMonthlyWithVat)} €` : null}
              />
            </div>
            <p className="text-xs text-ink-400">
              {plan === 'trial'
                ? `Skúšobné obdobie je ${trialDays ?? 14} dní bez platby. Predplatné si vieš aktivovať aj neskôr v nastaveniach.`
                : 'V ďalšom kroku doplníš fakturačné údaje a presmerujeme ťa na bezpečnú platbu cez Stripe.'}
            </p>
          </div>

          <TermsDeclaration
            checked={termsAccepted}
            error={fieldErrors.terms}
            onChange={(v) => {
              setTermsAccepted(v);
              if (v) setFieldErrors((prev) => ({ ...prev, terms: undefined }));
            }}
          />

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}
          {Object.values(fieldErrors).some(Boolean) && (
            <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              Formulár obsahuje nevyplnené povinné polia.
            </p>
          )}

          <Button
            type="submit"
            loading={loading}
            disabled={password.length === 0 || password !== passwordConfirm || !termsAccepted}
            className="mt-2 w-full"
          >
            {plan === 'trial' ? 'Spustiť skúšobnú verziu' : 'Pokračovať na fakturáciu'}
          </Button>
        </form>
      </Card>
    </AuthLayout>
  );
}

/**
 * The declaration required above the registration CTA (change request 3.1).
 *
 * Wording is fixed by the client's legal counsel — do not paraphrase. The two
 * document names are links that open in a new tab so the form state survives
 * the user going off to read them.
 */
function TermsDeclaration({
  checked,
  error,
  onChange,
}: {
  checked: boolean;
  error?: string;
  onChange: (value: boolean) => void;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label
        className={cn(
          'flex cursor-pointer items-start gap-3 rounded-2xl border p-3.5 transition-all duration-200',
          checked ? 'border-firol-300 bg-firol-50/60' : 'border-ink-200 bg-white hover:border-ink-300',
          error && !checked && 'border-[var(--color-status-bad)]',
        )}
      >
        <input
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          required
          aria-describedby="terms-declaration-text"
          className="mt-0.5 size-4 shrink-0 accent-firol-500"
        />
        <span id="terms-declaration-text" className="text-xs leading-relaxed text-ink-700">
          Vyhlasujem, že všetky mnou uvedené údaje sú pravdivé, a potvrdzujem, že som sa oboznámil so{' '}
          <a
            href={LEGAL_VOP_URL}
            target="_blank"
            rel="noreferrer"
            onClick={(e) => e.stopPropagation()}
            className="font-medium text-firol-600 underline underline-offset-2 hover:text-firol-700"
          >
            Všeobecnými obchodnými podmienkami
          </a>{' '}
          a so{' '}
          <a
            href={LEGAL_PRIVACY_URL}
            target="_blank"
            rel="noreferrer"
            onClick={(e) => e.stopPropagation()}
            className="font-medium text-firol-600 underline underline-offset-2 hover:text-firol-700"
          >
            Zásadami ochrany osobných údajov
          </a>{' '}
          a beriem ich na vedomie.
        </span>
      </label>
      {error && <p className="text-xs text-status-bad">{error}</p>}
    </div>
  );
}

function PeriodOption({
  active,
  onClick,
  label,
  price,
  priceWithVat,
  badge,
  icon,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  price: string;
  priceWithVat?: string | null;
  badge?: string;
  icon?: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'flex flex-col items-start gap-0.5 rounded-2xl border-2 px-4 py-3 text-left transition-all duration-200',
        active
          ? 'border-firol-500 bg-firol-50/70 shadow-[var(--shadow-soft)]'
          : 'border-ink-200 bg-white hover:border-ink-300',
      )}
      aria-pressed={active}
    >
      <span className={cn(
        'mb-1 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
        badge ? 'bg-firol-100 text-firol-700' : 'invisible',
      )}>
        {icon}
        {badge ?? ' '}
      </span>
      <span
        className={cn(
          'text-sm font-medium',
          active ? 'text-firol-700' : 'text-ink-700',
        )}
      >
        {label}
      </span>
      <span className="text-base font-semibold text-ink-900">
        {price}
        {priceWithVat && (
          <span className="ml-1 text-xs font-normal text-ink-400">bez DPH</span>
        )}
      </span>
      {priceWithVat && (
        <span className="mt-0.5 inline-flex items-center gap-1 rounded-full bg-firol-100 px-1.5 py-0.5 text-[11px] font-semibold text-firol-700">
          s DPH {priceWithVat}
        </span>
      )}
    </button>
  );
}
