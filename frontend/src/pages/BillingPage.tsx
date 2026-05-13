import { useEffect, useState, type FormEvent } from 'react';
import {
  AlertCircle, Check, CreditCard, ExternalLink, Hash, MapPin, Receipt,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { AccountApi, type Account } from '@/api/account';
import { Billing, type BillingPeriod, type Invoice } from '@/api/billing';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';

export function BillingPage() {
  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">Predplatné a fakturácia</h1>
        <p className="mt-0.5 text-sm text-ink-500">
          Spravuj predplatné, platobné údaje a stiahni si faktúry.
        </p>
      </header>

      <BillingSection />
      <InvoiceDetailsSection />
    </div>
  );
}

function hasBillingDetails(account: Account): boolean {
  return !!(
    account.invoice_street?.trim() &&
    account.invoice_postal_code?.trim() &&
    account.invoice_city?.trim() &&
    account.invoice_ico?.trim()
  );
}

function BillingSection() {
  const { csrfToken, refresh } = useAuth();
  const toast = useToast();
  const [account, setAccount] = useState<Account | null>(null);
  const [period, setPeriod] = useState<BillingPeriod>('monthly');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [invoices, setInvoices] = useState<Invoice[]>([]);

  useEffect(() => {
    Billing.invoices()
      .then((res) => setInvoices(res.items))
      .catch(() => { /* invoices are optional — don't block UI */ });
  }, []);

  useEffect(() => {
    let cancelled = false;
    AccountApi.show()
      .then((res) => {
        if (cancelled) return;
        setAccount(res.account);
        if (res.account.billing_period) setPeriod(res.account.billing_period);
      })
      .catch(() => { /* surfaced elsewhere */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const status = params.get('checkout');
    if (!status) return;
    if (status === 'success') {
      toast.success('Predplatné aktivované — ďakujeme!');
      refresh();
      AccountApi.show().then((res) => setAccount(res.account)).catch(() => {});
    } else if (status === 'cancel') {
      toast.error('Platba zrušená.');
    }
    params.delete('checkout');
    params.delete('session_id');
    const qs = params.toString();
    window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
  }, [refresh, toast]);

  async function onCheckout() {
    if (account && !hasBillingDetails(account)) {
      document.getElementById('invoice-details')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    setBusy(true);
    try {
      const res = await Billing.checkout(period, csrfToken);
      window.location.assign(res.url);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Stripe Checkout sa nepodarilo spustiť.';
      toast.error(msg);
      setBusy(false);
    }
  }

  async function onPortal() {
    setBusy(true);
    try {
      const res = await Billing.portal(csrfToken);
      window.location.assign(res.url);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Customer Portal sa nepodarilo otvoriť.';
      toast.error(msg);
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <Card className="flex justify-center py-8 text-ink-400"><Spinner /></Card>
    );
  }
  if (!account) return null;

  const end = account.subscription_end_date
    ? new Date(`${account.subscription_end_date}T00:00:00`)
    : null;
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const expired = end !== null && end < today;
  const isTrialing = account.stripe_status === 'trialing';
  const isActive = account.stripe_status === 'active';
  const hasSub = isActive || isTrialing;
  const humanEnd = end ? end.toLocaleDateString('sk-SK', { day: 'numeric', month: 'long', year: 'numeric' }) : '—';

  const periodPrice = account.billing_period === 'yearly'
    ? '199 € / rok'
    : '19 € / mesiac';

  const statusBadge = expired
    ? <Badge tone="warn">Vypršalo</Badge>
    : isActive
      ? <Badge tone="ok">Aktívne</Badge>
      : isTrialing
        ? <Badge tone="ok">Skúška + predplatené</Badge>
        : account.stripe_status === 'canceled'
          ? <Badge tone="warn">Zrušené</Badge>
          : <Badge tone="neutral">Skúšobné obdobie</Badge>;

  const subtitle = isTrialing
    ? `Skúšobné obdobie do ${humanEnd}. Potom sa predplatné (${periodPrice}) automaticky aktivuje — kartu sme uložili na Stripe.`
    : isActive
      ? `Predplatné je aktívne. Ďalšia fakturácia ${humanEnd} (${periodPrice}).`
      : account.stripe_status === 'canceled'
        ? `Predplatné zrušené, prístup máš ešte do ${humanEnd}.`
        : `Účet má prístup do ${humanEnd}. Potom sa prepne do režimu len na čítanie, kým si nezakúpiš predplatné.`;

  const billingReady = hasBillingDetails(account);
  const locked = !billingReady && !hasSub;

  return (
    <Card id="billing-section" className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <CreditCard className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Predplatné a fakturácia</h2>
          <p className="text-xs text-ink-500">{subtitle}</p>
        </div>
        {statusBadge}
      </div>

      <div className="flex flex-col gap-4 px-5 py-5">
        {locked && (
          <div className="pointer-events-auto flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-900">
            <AlertCircle className="mt-0.5 size-4 shrink-0 text-amber-600" />
            <div className="min-w-0 flex-1">
              <p className="font-semibold">Predplatné je možné aktivovať až po vyplnení fakturačných údajov</p>
              <p className="mt-0.5 text-xs text-amber-800">
                Doplň IČO a adresu v sekcii <strong>Fakturačné údaje</strong> nižšie — potrebujeme ich pre vystavenie faktúry.
              </p>
            </div>
            <button
              type="button"
              onClick={() => document.getElementById('invoice-details')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
              className="shrink-0 rounded-xl bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-amber-700"
            >
              Vyplniť údaje
            </button>
          </div>
        )}
        {hasSub ? (
          <>
            <p className="text-sm text-ink-700">
              Platobnú kartu, faktúry a zmenu fakturačného obdobia spravuje
              priamo Stripe v zabezpečenom portáli.
            </p>
            <div className="flex justify-end">
              <Button
                type="button"
                onClick={onPortal}
                loading={busy}
                leftIcon={<ExternalLink className="size-4" />}
              >
                Spravovať predplatné
              </Button>
            </div>
          </>
        ) : (
          <div
            className={cn(
              'flex flex-col gap-4 transition-opacity',
              locked && 'pointer-events-none select-none opacity-50',
            )}
            aria-disabled={locked || undefined}
          >
            <div>
              <label className="mb-2 block text-sm font-medium text-ink-800">Fakturačné obdobie</label>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <PeriodCard
                  active={period === 'monthly'}
                  title="Mesačne"
                  price="19 € / mesiac"
                  hint="Flexibilita, môžeš kedykoľvek zrušiť."
                  onClick={() => setPeriod('monthly')}
                />
                <PeriodCard
                  active={period === 'yearly'}
                  title="Ročne"
                  price="199 € / rok"
                  hint="Ekvivalent ~16,60 € / mesiac — ušetríš 2 mesiace."
                  onClick={() => setPeriod('yearly')}
                />
              </div>
            </div>
            <div className="flex justify-end">
              <Button
                type="button"
                onClick={onCheckout}
                loading={busy}
                leftIcon={<CreditCard className="size-4" />}
              >
                {expired ? 'Obnoviť predplatné' : 'Aktivovať predplatné'}
              </Button>
            </div>
          </div>
        )}

        {invoices.length > 0 && (
          <div className="mt-2 border-t border-ink-100 pt-4">
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-500">
              História faktúr
            </h3>
            <ul className="flex flex-col gap-1.5">
              {invoices.map((inv) => (
                <li
                  key={inv.id}
                  className="flex items-center gap-3 rounded-xl border border-ink-100 px-3 py-2 text-sm"
                >
                  <span className="font-mono text-xs text-ink-500">
                    {new Date(inv.issued_at).toLocaleDateString('sk-SK')}
                  </span>
                  <span className="min-w-0 flex-1 truncate font-medium text-ink-800">
                    {inv.document_number ?? <span className="italic text-ink-400">(spracováva sa)</span>}
                  </span>
                  <span className="font-semibold text-ink-900">
                    {(inv.amount_cents / 100).toFixed(2)} {inv.currency}
                  </span>
                  <Badge tone={
                    inv.status === 'issued' || inv.status === 'paid' ? 'ok'
                    : inv.status === 'error' ? 'warn'
                    : 'neutral'
                  }>
                    {invoiceStatusLabel(inv.status)}
                  </Badge>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-xs text-ink-400">
              Faktúry vystavuje iDoklad — kompletné PDF a história je k dispozícii v Stripe portáli.
            </p>
          </div>
        )}
      </div>
    </Card>
  );
}

function invoiceStatusLabel(status: string): string {
  switch (status) {
    case 'issued': return 'Vystavená';
    case 'draft':  return 'Koncept';
    case 'paid':   return 'Zaplatená';
    case 'pending': return 'Spracováva sa';
    case 'skipped': return 'iDoklad off';
    case 'error':  return 'Chyba';
    default:       return status;
  }
}

function PeriodCard({
  active, title, price, hint, onClick,
}: {
  active: boolean;
  title: string;
  price: string;
  hint: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'group flex flex-col items-start gap-1 rounded-2xl border px-4 py-3 text-left transition-all',
        active
          ? 'border-firol-400 bg-firol-50/60 shadow-[inset_0_0_0_1px_var(--color-firol-200)]'
          : 'border-ink-200 bg-white hover:border-ink-300',
      )}
    >
      <span className="flex w-full items-center justify-between">
        <span className="text-sm font-semibold text-ink-900">{title}</span>
        <span
          className={cn(
            'grid size-5 place-items-center rounded-full border',
            active ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
          )}
        >
          {active && <Check className="size-3" />}
        </span>
      </span>
      <span className="text-base font-semibold text-firol-700">{price}</span>
      <span className="text-xs text-ink-500">{hint}</span>
    </button>
  );
}

function InvoiceDetailsSection() {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [account, setAccount] = useState<Account | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [street, setStreet]     = useState('');
  const [postal, setPostal]     = useState('');
  const [city, setCity]         = useState('');
  const [country, setCountry]   = useState('Slovensko');
  const [ico, setIco]           = useState('');
  const [dic, setDic]           = useState('');
  const [icDph, setIcDph]       = useState('');

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
      .catch(() => { /* surfaced elsewhere */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const res = await AccountApi.update({
        invoice_street:      street,
        invoice_postal_code: postal,
        invoice_city:        city,
        invoice_country:     country.trim() || 'Slovensko',
        invoice_ico:         ico,
        invoice_dic:         dic,
        invoice_ic_dph:      icDph,
      }, csrfToken);
      setAccount(res.account);
      const onboarding = new URLSearchParams(window.location.search).get('onboarding') === 'billing';
      if (onboarding) {
        toast.success('Fakturačné údaje uložené. Teraz aktivuj predplatné.');
        document.getElementById('billing-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        toast.success('Fakturačné údaje uložené.');
      }
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Uloženie zlyhalo.';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <Card className="flex justify-center py-8 text-ink-400"><Spinner /></Card>;
  }
  if (!account) return null;

  return (
    <Card id="invoice-details" className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <Receipt className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Fakturačné údaje</h2>
          <p className="text-xs text-ink-500">
            Použijú sa pri vystavovaní faktúr za predplatné. Doplň ich pred prvou platbou.
          </p>
        </div>
      </div>

      <form onSubmit={onSubmit} className="flex flex-col gap-3 px-5 py-5">
        <Field label="Ulica a číslo">
          {(p) => (
            <Input {...p} value={street} onChange={(e) => setStreet(e.target.value)}
                   leftIcon={<MapPin className="size-4" />} />
          )}
        </Field>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="PSČ">
            {(p) => <Input {...p} value={postal} onChange={(e) => setPostal(e.target.value)} />}
          </Field>
          <Field label="Mesto" className="sm:col-span-2">
            {(p) => <Input {...p} value={city} onChange={(e) => setCity(e.target.value)} />}
          </Field>
        </div>
        <Field label="Krajina">
          {(p) => <Input {...p} value={country} onChange={(e) => setCountry(e.target.value)} />}
        </Field>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="IČO">
            {(p) => (
              <Input {...p} value={ico} onChange={(e) => setIco(e.target.value)}
                     leftIcon={<Hash className="size-4" />} />
            )}
          </Field>
          <Field label="DIČ">
            {(p) => <Input {...p} value={dic} onChange={(e) => setDic(e.target.value)} />}
          </Field>
          <Field label="IČ DPH">
            {(p) => <Input {...p} value={icDph} onChange={(e) => setIcDph(e.target.value)} />}
          </Field>
        </div>

        <div className="flex justify-end pt-1">
          <Button type="submit" loading={saving} leftIcon={<Receipt className="size-4" />}>
            Uložiť fakturačné údaje
          </Button>
        </div>
      </form>
    </Card>
  );
}
