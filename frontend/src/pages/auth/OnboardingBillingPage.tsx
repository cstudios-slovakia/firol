import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { CreditCard, Hash, LogOut, MapPin, Receipt, ShieldCheck } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { AccountApi, type Account } from '@/api/account';
import { Billing, type BillingPeriod } from '@/api/billing';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { AuthLayout } from './AuthLayout';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';

/**
 * Hard onboarding gate for users who picked monthly/yearly at registration.
 * Mounted *outside* AppShell so they can't navigate into the app until
 * billing details are saved and Stripe Checkout has been opened.
 */
export function OnboardingBillingPage() {
  const { csrfToken, logout, accounts, activeAccountId } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const activeAccount = accounts.find((a) => a.id === activeAccountId) ?? null;
  // No fallback here — until the account row has loaded with an explicit
  // billing_period, we render a spinner instead of guessing a plan. Otherwise
  // a user who chose monthly at registration would briefly see yearly prices.
  const plan: BillingPeriod | null = (activeAccount?.billing_period as BillingPeriod | null) ?? null;

  const [account, setAccount] = useState<Account | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const [street, setStreet]   = useState('');
  const [postal, setPostal]   = useState('');
  const [city, setCity]       = useState('');
  const [country, setCountry] = useState('Slovensko');
  const [ico, setIco]         = useState('');
  const [dic, setDic]         = useState('');
  const [icDph, setIcDph]     = useState('');

  useEffect(() => {
    let cancelled = false;
    AccountApi.show()
      .then((res) => {
        if (cancelled) return;
        setAccount(res.account);
        setStreet(res.account.invoice_street ?? '');
        setPostal(res.account.invoice_postal_code ?? '');
        setCity(res.account.invoice_city ?? '');
        setCountry(res.account.invoice_country ?? 'Slovensko');
        setIco(res.account.invoice_ico ?? '');
        setDic(res.account.invoice_dic ?? '');
        setIcDph(res.account.invoice_ic_dph ?? '');
      })
      .catch((err: unknown) => {
        toast.error(offlineMessage(err, 'Nepodarilo sa načítať fakturačné údaje.'));
      })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [toast]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!street.trim() || !postal.trim() || !city.trim() || !ico.trim()) {
      toast.error('Vyplň ulicu, PSČ, mesto a IČO.');
      return;
    }
    if (plan === null) {
      toast.error('Predplatné sa ešte načítava, skús to o chvíľu.');
      return;
    }
    setBusy(true);
    try {
      await AccountApi.update({
        invoice_street:      street,
        invoice_postal_code: postal,
        invoice_city:        city,
        invoice_country:     country.trim() || 'Slovensko',
        invoice_ico:         ico.replace(/\s+/g, ''),
        invoice_dic:         dic.replace(/\s+/g, ''),
        invoice_ic_dph:      icDph.replace(/\s+/g, ''),
      }, csrfToken);

      const res = await Billing.checkout(plan, csrfToken);
      window.location.assign(res.url);
    } catch (err) {
      const msg = offlineMessage(err, 'Uloženie alebo spustenie platby zlyhalo.');
      toast.error(msg);
      setBusy(false);
    }
  }

  async function onLogout() {
    // Logout needs the server and isn't queueable; offline it just rejects,
    // so flag it instead of silently leaving the user on this gate.
    if (!navigator.onLine) {
      toast.error('Odhlásenie vyžaduje pripojenie na internet.');
      return;
    }
    try {
      await logout();
      navigate('/login');
    } catch {
      toast.error('Odhlásenie vyžaduje pripojenie na internet.');
    }
  }

  const priceLabel = plan === null ? '—' : plan === 'yearly' ? '199 € / rok' : '19 € / mesiac';
  const planLabel  = plan === null ? 'Načítavam plán…' : plan === 'yearly' ? 'Ročné predplatné' : 'Mesačné predplatné';
  const planHint   = plan === null
    ? ''
    : plan === 'yearly'
      ? 'Ekvivalent ~16,60 € / mesiac, ušetríš 2 mesiace oproti mesačnému.'
      : 'Účtuje sa každý mesiac, môžeš kedykoľvek zrušiť cez Stripe portál.';

  return (
    <AuthLayout
      title="Posledný krok pred platbou"
      subtitle="Pre vystavenie faktúry potrebujeme fakturačné údaje. Potom ťa presmerujeme na bezpečnú platbu cez Stripe."
      footer={
        <button
          type="button"
          onClick={onLogout}
          className="inline-flex items-center gap-1.5 text-ink-500 transition-colors hover:text-ink-700"
        >
          <LogOut className="size-3.5" />
          Odhlásiť sa
        </button>
      }
    >
      <Card className="overflow-hidden">
        <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/80 to-transparent px-5 py-4">
          <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
            <CreditCard className="size-5" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-firol-700">
              Zvolený plán
            </p>
            <p className="text-base font-semibold text-ink-900">{planLabel}</p>
            <p className="text-xs text-ink-500">{planHint}</p>
          </div>
          <div className="text-right">
            <p className="text-base font-semibold text-firol-700">{priceLabel}</p>
            <p className="text-[10px] text-ink-400">platba cez Stripe</p>
          </div>
        </div>

        {loading || !account || plan === null ? (
          <div className="flex justify-center py-10 text-ink-400">
            <Spinner />
          </div>
        ) : (
          <form onSubmit={onSubmit} className="flex flex-col gap-3 px-5 py-5" noValidate>
            <Field label="Ulica a číslo" required>
              {(p) => (
                <Input {...p} required value={street} onChange={(e) => setStreet(e.target.value)}
                       leftIcon={<MapPin className="size-4" />} placeholder="Hlavná 12" />
              )}
            </Field>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <Field label="PSČ" required>
                {(p) => (
                  <Input {...p} required value={postal} onChange={(e) => setPostal(e.target.value)}
                         placeholder="811 01" />
                )}
              </Field>
              <Field label="Mesto" required className="sm:col-span-2">
                {(p) => (
                  <Input {...p} required value={city} onChange={(e) => setCity(e.target.value)}
                         placeholder="Bratislava" />
                )}
              </Field>
            </div>
            <Field label="Krajina">
              {(p) => <Input {...p} value={country} onChange={(e) => setCountry(e.target.value)} />}
            </Field>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <Field label="IČO" required>
                {(p) => (
                  <Input {...p} required value={ico} onChange={(e) => setIco(e.target.value)}
                         leftIcon={<Hash className="size-4" />} placeholder="12345678" />
                )}
              </Field>
              <Field label="DIČ">
                {(p) => <Input {...p} value={dic} onChange={(e) => setDic(e.target.value)} />}
              </Field>
              <Field label="IČ DPH">
                {(p) => <Input {...p} value={icDph} onChange={(e) => setIcDph(e.target.value)} />}
              </Field>
            </div>

            <p className="mt-1 flex items-start gap-2 rounded-xl bg-ink-50 px-3 py-2 text-xs text-ink-500">
              <ShieldCheck className="mt-0.5 size-4 shrink-0 text-firol-600" />
              Po uložení ťa presmerujeme na Stripe Checkout. Kartu zadávaš priamo
              tam — POapp ju nevidí ani neukladá.
            </p>

            <div className="flex justify-end pt-1">
              <Button type="submit" loading={busy} leftIcon={<Receipt className="size-4" />}>
                Uložiť a pokračovať na platbu
              </Button>
            </div>
          </form>
        )}
      </Card>
    </AuthLayout>
  );
}
