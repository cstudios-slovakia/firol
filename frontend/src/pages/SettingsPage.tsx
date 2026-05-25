import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
  AlertTriangle, AtSign, Building2, CalendarDays, Check, ChevronLeft, ChevronRight,
  Copy, CreditCard, FileSignature, Hash, ImagePlus, MailPlus, MessageSquarePlus, Palette,
  Phone, RotateCcw, Shield, ShieldCheck, ShieldOff, Trash2, UploadCloud, User,
  UserCheck, UsersRound,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { AccountApi, type Account } from '@/api/account';
import { InspectorProfileApi, type InspectorProfile } from '@/api/inspectorProfile';
import { Team, type TeamMember, type PendingInvite } from '@/api/team';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { CardBlockSkeleton, SkeletonList } from '@/components/ui/Skeleton';
import { Badge } from '@/components/ui/Badge';
import { SignaturePickerModal } from '@/components/SignaturePickerModal';
import { FeedbackDialog } from '@/components/FeedbackFloater';
import { cn } from '@/lib/cn';

// ─── Tab definitions ─────────────────────────────────────────────────────────

const SECTION_TABS = [
  { to: '/settings/profil',      label: 'Profil technika', icon: ShieldCheck },
  { to: '/settings/branding',    label: 'Branding PDF',    icon: Palette },
  { to: '/settings/technici',    label: 'Technici',        icon: UsersRound },
] as const;

const MENU_ITEMS = [
  {
    to: '/billing',
    label: 'Predplatné',
    description: 'Správa predplatného a fakturačné údaje',
    icon: CreditCard,
    color: 'text-firol-600',
    bg: 'bg-firol-50',
  },
  {
    to: '/settings/profil',
    label: 'Profil revízneho technika',
    description: 'Podpis, číslo oprávnenia a platnosť',
    icon: ShieldCheck,
    color: 'text-violet-600',
    bg: 'bg-violet-50',
  },
  {
    to: '/settings/branding',
    label: 'Branding na PDF',
    description: 'Logo, farba a názov spoločnosti',
    icon: Palette,
    color: 'text-blue-600',
    bg: 'bg-blue-50',
  },
  {
    to: '/settings/technici',
    label: 'Technici',
    description: 'Ľudia s prístupom do vašej firmy. Môžu vykonávať revízie aj školenia.',
    icon: UsersRound,
    color: 'text-orange-600',
    bg: 'bg-orange-50',
  },
] as const;

const ADMIN_MENU_ITEM = {
  to: '/admin',
  label: 'Admin',
  description: 'Správa účtov a systémové nastavenia',
  icon: Shield,
  color: 'text-rose-600',
  bg: 'bg-rose-50',
} as const;

// ─── Layout ───────────────────────────────────────────────────────────────────

export function SettingsLayout() {
  const { isAdmin } = useAuth();
  const location = useLocation();
  const isIndex = location.pathname === '/settings' || location.pathname === '/settings/';

  return (
    <div className="flex flex-col gap-5">
      {/* Header: always on desktop, only on index on mobile */}
      <header className={cn(isIndex ? 'block' : 'hidden sm:block')}>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">Nastavenia</h1>
        <p className="mt-0.5 text-sm text-ink-500">
          Profil, branding, tím a administrácia vášho účtu.
        </p>
      </header>

      {/* Desktop tabs */}
      <div className="relative hidden sm:block">
        <nav
          aria-label="Sekcie nastavení"
          className="flex items-center gap-0.5 overflow-x-auto border-b border-ink-100 [&::-webkit-scrollbar]:hidden"
        >
          {SECTION_TABS.map((tab) => (
            <NavLink
              key={tab.to}
              to={tab.to}
              className={({ isActive }) =>
                cn(
                  'flex shrink-0 items-center gap-2 px-3.5 py-2.5 text-sm font-medium rounded-t-xl border-b-2 -mb-px transition-all duration-150',
                  isActive
                    ? 'border-firol-500 text-firol-700 bg-firol-50/60'
                    : 'border-transparent text-ink-500 hover:text-ink-800 hover:bg-ink-50',
                )
              }
            >
              {({ isActive }) => (
                <>
                  <tab.icon className={cn('size-4 shrink-0 transition-transform duration-150', isActive && 'scale-110')} />
                  <span>{tab.label}</span>
                </>
              )}
            </NavLink>
          ))}
          {isAdmin && (
            <NavLink
              to="/admin"
              className={({ isActive }) =>
                cn(
                  'flex shrink-0 items-center gap-2 px-3.5 py-2.5 text-sm font-medium rounded-t-xl border-b-2 -mb-px transition-all duration-150',
                  isActive
                    ? 'border-rose-500 text-rose-700 bg-rose-50/60'
                    : 'border-transparent text-ink-500 hover:text-ink-800 hover:bg-ink-50',
                )
              }
            >
              {({ isActive }) => (
                <>
                  <Shield className={cn('size-4 shrink-0 transition-transform duration-150', isActive && 'scale-110')} />
                  <span>Admin</span>
                </>
              )}
            </NavLink>
          )}
        </nav>
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-white to-transparent"
        />
      </div>

      <div key={location.pathname} className="animate-fade-up">
        <Outlet />
      </div>

      <p className="hidden sm:block text-center text-[11px] text-ink-300 tabular-nums pt-2">
        Firol {__APP_VERSION__}
      </p>
    </div>
  );
}

// ─── Index (mobile menu / desktop redirect) ───────────────────────────────────

export function SettingsIndexPage() {
  const { isAdmin } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [feedbackOpen, setFeedbackOpen] = useState(false);

  useEffect(() => {
    const mq = window.matchMedia('(min-width: 640px)');
    if (mq.matches) {
      navigate('/settings/profil', { replace: true });
    }
  }, [navigate]);

  const items = isAdmin ? [...MENU_ITEMS, ADMIN_MENU_ITEM] : [...MENU_ITEMS];

  return (
    <div className="flex flex-col gap-2 sm:hidden">
      {items.map((item, i) => (
        <Link
          key={item.to}
          to={item.to}
          className="group animate-fade-up flex items-center gap-3.5 rounded-2xl border border-ink-100 bg-white px-4 py-3.5 transition-[background-color,transform] duration-150 hover:bg-ink-50 hover:-translate-y-px active:bg-ink-100 active:translate-y-0"
          style={{ animationDelay: `${i * 45}ms` }}
        >
          <span className={cn('grid size-11 shrink-0 place-items-center rounded-2xl', item.bg)}>
            <item.icon className={cn('size-5', item.color)} />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-ink-900">{item.label}</p>
            <p className="mt-0.5 text-xs text-ink-500">{item.description}</p>
          </div>
          <ChevronRight className="size-4 shrink-0 text-ink-300 transition-transform duration-150 group-hover:translate-x-0.5" />
        </Link>
      ))}

      {/* Feedback entry — visually separated, lives at the bottom. */}
      <div className="mt-4 border-t border-dashed border-ink-200 pt-4">
        <button
          type="button"
          onClick={() => setFeedbackOpen(true)}
          className="group animate-fade-up flex w-full items-center gap-3.5 rounded-2xl border border-firol-200 bg-firol-50/60 px-4 py-3.5 text-left transition-[background-color,transform] duration-150 hover:bg-firol-50 hover:-translate-y-px active:translate-y-0"
          style={{ animationDelay: `${items.length * 45}ms` }}
        >
          <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-100 text-firol-600">
            <MessageSquarePlus className="size-5" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-firol-800">Nahlásiť chybu / nápad</p>
            <p className="mt-0.5 text-xs text-firol-700/70">
              Daj nám vedieť, čo nefunguje alebo čo by ti pomohlo.
            </p>
          </div>
          <ChevronRight className="size-4 shrink-0 text-firol-400 transition-transform duration-150 group-hover:translate-x-0.5" />
        </button>
      </div>

      <p className="mt-6 text-center text-[11px] text-ink-300 tabular-nums">
        Firol {__APP_VERSION__}
      </p>

      {feedbackOpen && (
        <FeedbackDialog
          onClose={() => setFeedbackOpen(false)}
          sourceUrl={window.location.origin + location.pathname + location.search}
        />
      )}
    </div>
  );
}

// ─── Section back link (mobile only) ─────────────────────────────────────────

function SectionBack({ label }: { label: string }) {
  return (
    <div className="sm:hidden">
      <Link
        to="/settings"
        className="inline-flex items-center gap-1.5 rounded-xl py-1 text-sm font-medium text-ink-500 transition-colors hover:text-ink-800"
      >
        <ChevronLeft className="size-4" />
        Nastavenia
      </Link>
      <h2 className="mt-1 text-lg font-semibold tracking-tight text-ink-900">{label}</h2>
    </div>
  );
}

// ─── Inspector Profile Page ───────────────────────────────────────────────────

export function InspectorProfilePage() {
  return (
    <>
      <SectionBack label="Profil revízneho technika" />
      <InspectorProfileSection />
    </>
  );
}

function InspectorProfileSection() {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [profile, setProfile] = useState<InspectorProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [signatureCacheBust, setSignatureCacheBust] = useState<number>(() => Date.now());

  const [certPhp, setCertPhp] = useState('');
  const [validFromPhp, setValidFromPhp] = useState('');
  const [validToPhp, setValidToPhp] = useState('');
  const [certOprava, setCertOprava] = useState('');
  const [validFromOprava, setValidFromOprava] = useState('');
  const [validToOprava, setValidToOprava] = useState('');
  const [certGeneral, setCertGeneral] = useState('');
  const [validFromGeneral, setValidFromGeneral] = useState('');
  const [validToGeneral, setValidToGeneral] = useState('');

  const [showSigPicker, setShowSigPicker] = useState(false);

  useEffect(() => {
    let cancelled = false;
    InspectorProfileApi.show()
      .then((res) => {
        if (cancelled) return;
        applyProfile(res.profile);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať profil.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  function applyProfile(p: InspectorProfile) {
    setProfile(p);
    setCertPhp(p.cert_php ?? '');
    setValidFromPhp(p.valid_from_php ?? '');
    setValidToPhp(p.valid_to_php ?? '');
    setCertOprava(p.cert_oprava ?? '');
    setValidFromOprava(p.valid_from_oprava ?? '');
    setValidToOprava(p.valid_to_oprava ?? '');
    setCertGeneral(p.cert_general ?? '');
    setValidFromGeneral(p.valid_from_general ?? '');
    setValidToGeneral(p.valid_to_general ?? '');
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSaving(true);
    try {
      const res = await InspectorProfileApi.update(
        {
          cert_php:    certPhp.trim() || null,
          cert_oprava:  certOprava.trim() || null,
          cert_general: certGeneral.trim() || null,
          valid_from_php:    validFromPhp || null,
          valid_to_php:      validToPhp || null,
          valid_from_oprava:  validFromOprava || null,
          valid_to_oprava:    validToOprava || null,
          valid_from_general: validFromGeneral || null,
          valid_to_general:   validToGeneral || null,
        },
        csrfToken,
      );
      applyProfile(res.profile);
      toast.success('Profil revízneho technika uložený');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  }

  async function onSignatureChosen(blob: Blob) {
    setShowSigPicker(false);
    setError(null);
    setSaving(true);
    try {
      const res = await InspectorProfileApi.uploadSignature(blob, csrfToken);
      applyProfile(res.profile);
      setSignatureCacheBust(Date.now());
      toast.success('Podpis nahraný');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Nahranie podpisu sa nepodarilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <div className="flex flex-col gap-4">
        <CardBlockSkeleton rows={4} />
        <CardBlockSkeleton rows={4} />
      </div>
    );
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <ShieldCheck className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Profil revízneho technika</h2>
          <p className="text-xs text-ink-500">
            Podpis, číslo oprávnenia a platnosť — všetko pôjde do hlavičky a päty PDF.
          </p>
        </div>
        {profile && (
          <Badge tone={profile.is_active ? 'ok' : 'neutral'}>
            {profile.is_active ? 'Aktívny' : 'Neaktívny'}
          </Badge>
        )}
      </div>

      <div className="grid gap-5 px-5 py-5 lg:grid-cols-[260px_1fr]">
        <div className="flex flex-col gap-3">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">
            Podpis
          </span>
          <SignaturePreview
            hasSignature={profile?.has_signature ?? false}
            cacheBuster={signatureCacheBust}
          />
          <Button
            type="button"
            variant="secondary"
            leftIcon={<FileSignature className="size-4" />}
            onClick={() => setShowSigPicker(true)}
            loading={saving}
          >
            {profile?.has_signature ? 'Zmeniť podpis' : 'Pridať podpis'}
          </Button>
          {showSigPicker && (
            <SignaturePickerModal
              onClose={() => setShowSigPicker(false)}
              onSave={onSignatureChosen}
              saving={saving}
            />
          )}
        </div>

        <form onSubmit={onSubmit} className="flex flex-col gap-3" noValidate>
          <p className="rounded-xl border border-ink-100 bg-ink-50/40 px-3 py-2 text-xs text-ink-500">
            Každý typ kontroly vyžaduje iné oprávnenie. Vypíš čísla, ktoré máš — do každého PDF pôjde to správne.
          </p>

          <CertCard
            color="firol"
            title="Kontrola PHP"
            subtitle="Oprávnenie na kontrolu hasiacich prístrojov"
            certValue={certPhp}
            certPlaceholder="napr. RT-PHP-2024-0123"
            onCertChange={setCertPhp}
            validFrom={validFromPhp}
            validTo={validToPhp}
            onValidFromChange={setValidFromPhp}
            onValidToChange={setValidToPhp}
          />

          <CertCard
            color="violet"
            title="Oprava / plnenie / TS PHP"
            subtitle="Oprávnenie na opravu, plnenie a tlakovú skúšku PHP"
            certValue={certOprava}
            certPlaceholder="napr. RT-TS-2024-0456"
            onCertChange={setCertOprava}
            validFrom={validFromOprava}
            validTo={validToOprava}
            onValidFromChange={setValidFromOprava}
            onValidToChange={setValidToOprava}
          />

          <CertCard
            color="blue"
            title="Technik PO"
            subtitle="Hydranty, požiarna kniha, PU, núdzové osvetlenia, TS hadíc, školenia"
            certValue={certGeneral}
            certPlaceholder="napr. TPO-2024-0789"
            onCertChange={setCertGeneral}
            validFrom={validFromGeneral}
            validTo={validToGeneral}
            onValidFromChange={setValidFromGeneral}
            onValidToChange={setValidToGeneral}
          />

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}

          <div className="flex justify-end pt-1">
            <Button type="submit" loading={saving} leftIcon={<FileSignature className="size-4" />}>
              Uložiť profil
            </Button>
          </div>
        </form>
      </div>
    </Card>
  );
}

// ─── Branding Page ────────────────────────────────────────────────────────────

export function BrandingPage() {
  return (
    <>
      <SectionBack label="Branding na PDF" />
      <BrandingSection />
    </>
  );
}

const FIROL_DEFAULT_COLOR = '#e8433a';

function BrandingSection() {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [account, setAccount] = useState<Account | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [logoCacheBust, setLogoCacheBust] = useState<number>(() => Date.now());

  const [companyName, setCompanyName] = useState('');
  const [themeColor, setThemeColor] = useState(FIROL_DEFAULT_COLOR);
  const [usingCustomColor, setUsingCustomColor] = useState(false);

  const logoInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    let cancelled = false;
    AccountApi.show()
      .then((res) => {
        if (cancelled) return;
        applyAccount(res.account);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať brand.');
      })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  function applyAccount(a: Account) {
    setAccount(a);
    setCompanyName(a.invoice_company_name ?? '');
    if (a.theme_color) {
      setThemeColor(a.theme_color);
      setUsingCustomColor(true);
    } else {
      setThemeColor(FIROL_DEFAULT_COLOR);
      setUsingCustomColor(false);
    }
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSaving(true);
    try {
      const res = await AccountApi.update(
        {
          invoice_company_name: companyName.trim() || null,
          theme_color: usingCustomColor ? themeColor : '',
        },
        csrfToken,
      );
      applyAccount(res.account);
      toast.success('Brand uložený');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  }

  async function onLogoChosen(file: File | undefined) {
    if (!file) return;
    setError(null);
    setBusy(true);
    try {
      const res = await AccountApi.uploadLogo(file, csrfToken);
      applyAccount(res.account);
      setLogoCacheBust(Date.now());
      toast.success('Logo nahrané');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Nahranie loga sa nepodarilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusy(false);
    }
  }

  async function onLogoDelete() {
    if (!window.confirm('Naozaj odstrániť logo z PDF protokolov?')) return;
    setError(null);
    setBusy(true);
    try {
      const res = await AccountApi.deleteLogo(csrfToken);
      applyAccount(res.account);
      setLogoCacheBust(Date.now());
      toast.success('Logo odstránené');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Odstránenie loga zlyhalo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return <CardBlockSkeleton rows={5} />;
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
        <div
          className="grid size-11 place-items-center rounded-2xl text-white shadow-[var(--shadow-glow)]"
          style={{ background: usingCustomColor ? themeColor : 'var(--color-firol-500)' }}
        >
          <Palette className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Branding na PDF</h2>
          <p className="text-xs text-ink-500">
            Logo, farba a názov spoločnosti — objavia sa v hlavičke každého protokolu.
          </p>
        </div>
        {account?.has_logo && <Badge tone="ok">Logo nahrané</Badge>}
      </div>

      <div className="grid gap-5 px-5 py-5 lg:grid-cols-[260px_1fr]">
        <div className="flex flex-col gap-3">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">
            Logo
          </span>
          <LogoPreview
            hasLogo={account?.has_logo ?? false}
            cacheBuster={logoCacheBust}
          />
          <input
            ref={logoInputRef}
            type="file"
            accept="image/png,image/jpeg"
            className="hidden"
            onChange={(e) => onLogoChosen(e.target.files?.[0])}
          />
          <div className="flex gap-2">
            <Button
              type="button"
              variant="secondary"
              leftIcon={<UploadCloud className="size-4" />}
              onClick={() => logoInputRef.current?.click()}
              loading={busy}
              className="flex-1"
            >
              {account?.has_logo ? 'Nahrať nové logo' : 'Nahrať logo'}
            </Button>
            {account?.has_logo && (
              <button
                type="button"
                onClick={onLogoDelete}
                disabled={busy}
                title="Odstrániť logo"
                className="grid size-10 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
              >
                <Trash2 className="size-4" />
              </button>
            )}
          </div>
          <p className="text-xs text-ink-400">
            PNG alebo JPG, max 1 MB. Odporúčame priehľadné PNG.
          </p>
        </div>

        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field
            label="Názov spoločnosti"
            hint={'Zobrazí sa v záhlaví PDF: „<Názov> · Záznam o kontrole PHP”'}
          >
            {(p) => (
              <Input
                {...p}
                leftIcon={<Building2 className="size-4" />}
                value={companyName}
                onChange={(e) => setCompanyName(e.target.value)}
                placeholder="Firol s.r.o."
              />
            )}
          </Field>

          <div>
            <label className="mb-1.5 block text-sm font-medium text-ink-800">
              Hlavná farba
            </label>
            <div className="flex items-center gap-2">
              <input
                type="color"
                value={themeColor}
                onChange={(e) => {
                  setThemeColor(e.target.value);
                  setUsingCustomColor(true);
                }}
                className="size-11 cursor-pointer rounded-xl border border-ink-200 bg-white p-1"
                aria-label="Vybrať hlavnú farbu"
              />
              <Input
                value={themeColor}
                onChange={(e) => {
                  setThemeColor(e.target.value);
                  setUsingCustomColor(true);
                }}
                placeholder="#E8433A"
                className="font-mono uppercase"
              />
              <button
                type="button"
                onClick={() => {
                  setThemeColor(FIROL_DEFAULT_COLOR);
                  setUsingCustomColor(false);
                }}
                disabled={!usingCustomColor}
                title="Obnoviť pôvodnú Firol farbu"
                className="grid size-10 shrink-0 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 disabled:opacity-40"
              >
                <RotateCcw className="size-4" />
              </button>
            </div>
            <p className="mt-1.5 text-xs text-ink-400">
              Použije sa na hlavičkový pruh a nadpisy v PDF.
            </p>
          </div>

          {error && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}

          <div className="flex justify-end pt-1">
            <Button type="submit" loading={saving} leftIcon={<FileSignature className="size-4" />}>
              Uložiť brand
            </Button>
          </div>
        </form>
      </div>
    </Card>
  );
}

function LogoPreview({ hasLogo, cacheBuster }: { hasLogo: boolean; cacheBuster: number }) {
  if (!hasLogo) {
    return (
      <div className="relative w-full overflow-hidden rounded-2xl border border-dashed border-ink-200 bg-white" style={{ paddingTop: 'calc(100% / 3)' }}>
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 text-center text-ink-400">
          <ImagePlus className="size-5" />
          <span className="text-xs">Zatiaľ bez loga</span>
        </div>
      </div>
    );
  }
  return (
    <div className="relative w-full overflow-hidden rounded-2xl border border-ink-200 bg-white" style={{ paddingTop: 'calc(100% / 3)' }}>
      <div className="absolute inset-0 flex items-center justify-center p-3">
        <img
          src={AccountApi.logoUrl(cacheBuster)}
          alt="Logo spoločnosti"
          className="max-h-full max-w-full object-contain"
        />
      </div>
    </div>
  );
}

// Trainers section was removed — any active technician can act as a trainer
// on a training PDF (see migration 013_drop_trainers_use_users).

// ─── Team Page ────────────────────────────────────────────────────────────────

export function TeamPage() {
  return (
    <>
      <SectionBack label="Technici" />
      <TeamSection />
    </>
  );
}

function TeamSection() {
  const { csrfToken, user, accounts, activeAccountId } = useAuth();
  const toast = useToast();

  const activeAccount = accounts.find((a) => a.id === activeAccountId) ?? null;
  const isMain = user !== null && activeAccount !== null && activeAccount.main_user_id === user.id;

  const [members, setMembers] = useState<TeamMember[] | null>(null);
  const [invites, setInvites] = useState<PendingInvite[] | null>(null);
  const [seatInfo, setSeatInfo] = useState<Account | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [busyInviteId, setBusyInviteId] = useState<number | null>(null);
  const [adding, setAdding] = useState(false);

  const [showInvite, setShowInvite] = useState(false);
  const [inviteName, setInviteName] = useState('');
  const [inviteEmail, setInviteEmail] = useState('');
  const [invitePhone, setInvitePhone] = useState('');
  const [inviteLink, setInviteLink] = useState<string | null>(null);
  const [linkCopied, setLinkCopied] = useState(false);
  const [inviteFieldErrors, setInviteFieldErrors] = useState<{ name?: string; email?: string }>({});

  function reloadSeats() {
    AccountApi.show().then((res) => setSeatInfo(res.account)).catch(() => {});
  }

  function reloadInvites() {
    Team.listInvites().then((res) => setInvites(res.items)).catch(() => {});
  }

  useEffect(() => {
    let cancelled = false;
    Team.list()
      .then((res) => { if (!cancelled) setMembers(res.items); })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať tím.');
      });
    reloadSeats();
    reloadInvites();
    return () => { cancelled = true; };
  }, []);

  const included = seatInfo?.included_technicians ?? 0;
  const active   = seatInfo?.active_technicians   ?? (members?.filter((m) => m.is_active).length ?? 0);
  const extra    = Math.max(0, active - included);
  const max      = seatInfo?.max_self_service_technicians ?? 20;
  const perExtra = (seatInfo?.price_per_extra_technician_cents ?? 0) / 100;
  const atCap    = active >= max;

  async function onInvite(e: FormEvent) {
    e.preventDefault();
    const errs: typeof inviteFieldErrors = {};
    if (!inviteName.trim()) errs.name = 'Doplň meno a priezvisko.';
    if (!inviteEmail.trim()) errs.email = 'Doplň e-mail.';
    if (Object.keys(errs).length > 0) { setInviteFieldErrors(errs); return; }
    setInviteFieldErrors({});
    setError(null);
    setAdding(true);
    try {
      const res = await Team.invite(
        {
          fullname: inviteName.trim(),
          email: inviteEmail.trim(),
          phone: invitePhone.trim() || null,
        },
        csrfToken,
      );
      reloadInvites();
      toast.success('Pozvánka odoslaná. Technik sa pridá do tímu po jej potvrdení.');
      if (res.invite_token) {
        // Fallback link — let the inviter copy & forward if the email
        // bounces or the technician can't find it.
        const link = `${window.location.origin}/invite/accept?token=${res.invite_token}`;
        setInviteLink(link);
        setLinkCopied(false);
      } else {
        setShowInvite(false);
      }
      setInviteName('');
      setInviteEmail('');
      setInvitePhone('');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Pozvánku sa nepodarilo vytvoriť.';
      setError(msg);
      toast.error(msg);
    } finally {
      setAdding(false);
    }
  }

  async function onToggleActive(m: TeamMember) {
    setBusyId(m.id);
    try {
      const res = await Team.setActive(m.id, !m.is_active, csrfToken);
      setMembers((prev) => prev ? prev.map((x) => x.id === m.id ? res.item : x) : prev);
      reloadSeats();
      toast.success(res.item.is_active ? 'Technik aktivovaný' : 'Technik deaktivovaný');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Operácia zlyhala.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusyId(null);
    }
  }

  async function onRemove(m: TeamMember) {
    if (!window.confirm(`Naozaj odstrániť technika ${m.fullname} z tímu? Jeho účet zostane, len ho odpojíme od tejto firmy.`)) return;
    setBusyId(m.id);
    try {
      await Team.remove(m.id, csrfToken);
      setMembers((prev) => prev ? prev.filter((x) => x.id !== m.id) : prev);
      reloadSeats();
      toast.success('Technik odstránený');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Odstránenie zlyhalo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusyId(null);
    }
  }

  async function onCancelInvite(invite: PendingInvite) {
    if (!window.confirm(`Naozaj zrušiť pozvánku pre ${invite.email}? Odkaz prestane platiť.`)) return;
    setBusyInviteId(invite.id);
    try {
      await Team.cancelInvite(invite.id, csrfToken);
      setInvites((prev) => prev ? prev.filter((x) => x.id !== invite.id) : prev);
      toast.success('Pozvánka zrušená');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Zrušenie zlyhalo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusyInviteId(null);
    }
  }

  async function copyLink() {
    if (!inviteLink) return;
    try {
      await navigator.clipboard.writeText(inviteLink);
      setLinkCopied(true);
      toast.success('Odkaz skopírovaný');
    } catch {
      toast.error('Kopírovanie zlyhalo, vyber odkaz manuálne.');
    }
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex items-start gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
        <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <UsersRound className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div className="min-w-0 flex-1">
              <h2 className="text-base font-semibold text-ink-900">Technici</h2>
              <p className="text-xs text-ink-500">
                Ľudia, ktorí majú prístup do tejto firmy. Spravovať môže len hlavný používateľ.
              </p>
            </div>
            {isMain && !atCap && (
              <Button
                type="button"
                variant="secondary"
                leftIcon={<MailPlus className="size-4" />}
                onClick={() => { setShowInvite((v) => !v); setInviteLink(null); setInviteFieldErrors({}); }}
                className="self-start shrink-0"
              >
                {showInvite ? 'Zrušiť' : 'Pozvať technika'}
              </Button>
            )}
          </div>
        </div>
      </div>

      <div className="px-5 py-4">
        {seatInfo && (
          <div className="mb-3 rounded-2xl border border-ink-100 bg-ink-50/40 px-3 py-2.5 text-xs text-ink-600">
            <span className="font-semibold text-ink-800">Predplatné</span> zahŕňa {included} technikov
            (vrátane teba ako admina). Aktívnych v tíme: <span className="font-semibold">{active}</span>
            {extra > 0 && (
              <> · extra <span className="font-semibold">{extra}</span> × {perExtra.toFixed(2)} €/mes</>
            )}
            . {isMain && !atCap && (
              <>Každý ďalší technik nad zahrnutý počet stojí {perExtra.toFixed(2)} € / mes a pripočíta sa
              proratovane k najbližšej fakture.</>
            )}
          </div>
        )}

        {isMain && atCap && (
          <div className="mb-3 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50/80 px-3 py-2.5 text-sm text-amber-900">
            <UsersRound className="mt-0.5 size-4 shrink-0 text-amber-600" />
            <div className="min-w-0 flex-1">
              <p className="font-semibold">Si na hranici self-service plánu ({max} technikov).</p>
              <p className="mt-0.5 text-xs text-amber-800">
                Pre väčší tím nám napíš — pripravíme individuálnu cenovú ponuku. Po dohode admin Firolu
                zvýši zahrnutý počet technikov pre tvoj účet.
              </p>
            </div>
          </div>
        )}

        {error && (
          <div className="mb-3 rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </div>
        )}

        {isMain && showInvite && (
          <form
            onSubmit={onInvite}
            className="mb-4 flex flex-col gap-3 rounded-2xl border border-dashed border-ink-200 p-3"
          >
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Meno a priezvisko" required error={inviteFieldErrors.name}>
                {(p) => (
                  <Input {...p} required leftIcon={<User className="size-4" />}
                    value={inviteName} onChange={(e) => { setInviteName(e.target.value); if (inviteFieldErrors.name) setInviteFieldErrors((prev) => ({ ...prev, name: undefined })); }}
                    placeholder="Ján Technik" />
                )}
              </Field>
              <Field label="E-mail" required error={inviteFieldErrors.email}>
                {(p) => (
                  <Input {...p} required type="email" leftIcon={<AtSign className="size-4" />}
                    value={inviteEmail} onChange={(e) => { setInviteEmail(e.target.value); if (inviteFieldErrors.email) setInviteFieldErrors((prev) => ({ ...prev, email: undefined })); }}
                    placeholder="jan@firma.sk" />
                )}
              </Field>
            </div>
            <Field label="Telefón">
              {(p) => (
                <Input {...p} leftIcon={<Phone className="size-4" />}
                  value={invitePhone} onChange={(e) => setInvitePhone(e.target.value)}
                  placeholder="+421 900 000 000" />
              )}
            </Field>
            {Object.keys(inviteFieldErrors).length > 0 && (
              <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                Formulár obsahuje nevyplnené povinné polia.
              </p>
            )}
            <div className="flex items-center justify-end gap-2">
              <Button type="submit" loading={adding} leftIcon={<MailPlus className="size-4" />}>
                Vytvoriť pozvánku
              </Button>
            </div>
          </form>
        )}

        {inviteLink && (
          <div className="mb-4 rounded-2xl border border-firol-200 bg-firol-50/60 p-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-firol-700">
              Záložný odkaz na potvrdenie
            </p>
            <p className="mb-2 text-xs text-ink-600">
              Pozvánka bola odoslaná emailom. Ak nedorazí, pošli technikovi tento odkaz — platí 7 dní.
            </p>
            <div className="flex gap-2">
              <Input value={inviteLink} readOnly className="font-mono text-xs" onFocus={(e) => e.currentTarget.select()} />
              <Button
                type="button"
                variant="secondary"
                onClick={copyLink}
                leftIcon={linkCopied ? <Check className="size-4" /> : <Copy className="size-4" />}
              >
                {linkCopied ? 'Skopírované' : 'Kopírovať'}
              </Button>
            </div>
          </div>
        )}

        {invites !== null && invites.length > 0 && (
          <div className="mb-4">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-500">
              Čakajúce pozvánky ({invites.length})
            </p>
            <ul className="flex flex-col gap-2">
              {invites.map((inv) => (
                <li key={inv.id} className="flex items-center gap-3 rounded-2xl border border-dashed border-amber-200 bg-amber-50/40 px-3 py-2.5">
                  <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-700">
                    <MailPlus className="size-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-ink-900">{inv.fullname}</p>
                    <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-ink-500">
                      <span className="truncate">{inv.email}</span>
                      <span className="text-ink-300">·</span>
                      <Badge tone="warn">Čaká na potvrdenie</Badge>
                    </p>
                  </div>
                  {isMain && (
                    <button
                      type="button"
                      onClick={() => onCancelInvite(inv)}
                      disabled={busyInviteId === inv.id}
                      title="Zrušiť pozvánku"
                      className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
                    >
                      {busyInviteId === inv.id ? <Spinner size="sm" /> : <Trash2 className="size-4" />}
                    </button>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}

        {members === null ? (
          <SkeletonList count={2} />
        ) : (
          <ul className="flex flex-col gap-2">
            {members.map((m) => {
              const isSelf = user !== null && m.id === user.id;
              const canMutate = isMain && !m.is_main && !isSelf;
              return (
                <li key={m.id} className="flex items-center gap-3 rounded-2xl border border-ink-100 px-3 py-2.5">
                  <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
                    <User className="size-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-ink-900">
                      {m.fullname}
                      {isSelf && <span className="ml-1.5 text-xs font-normal text-ink-400">(ty)</span>}
                    </p>
                    <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-ink-500">
                      <span className="truncate">{m.email}</span>
                      <span className="text-ink-300">·</span>
                      {m.is_main ? (
                        <Badge tone="ok">Hlavný</Badge>
                      ) : m.is_active ? (
                        <Badge tone="neutral">Technik</Badge>
                      ) : (
                        <Badge tone="warn">Neaktívny</Badge>
                      )}
                    </p>
                  </div>
                  {canMutate && (
                    <>
                      <button
                        type="button"
                        onClick={() => onToggleActive(m)}
                        disabled={busyId === m.id}
                        title={m.is_active ? 'Deaktivovať' : 'Aktivovať'}
                        className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 disabled:opacity-50"
                      >
                        {busyId === m.id ? (
                          <Spinner size="sm" />
                        ) : m.is_active ? (
                          <ShieldOff className="size-4" />
                        ) : (
                          <UserCheck className="size-4" />
                        )}
                      </button>
                      <button
                        type="button"
                        onClick={() => onRemove(m)}
                        disabled={busyId === m.id}
                        title="Odstrániť z tímu"
                        className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    </>
                  )}
                </li>
              );
            })}
          </ul>
        )}

        {!isMain && (
          <p className="mt-3 text-xs text-ink-400">
            Si prihlásený ako technik. Správu tímu robí hlavný používateľ účtu.
          </p>
        )}
      </div>
    </Card>
  );
}

// ─── Cert Card ────────────────────────────────────────────────────────────────

type CertCardColor = 'firol' | 'violet' | 'blue';

const CERT_CARD_STYLES: Record<CertCardColor, { border: string; bg: string; iconBg: string; iconColor: string }> = {
  firol:  { border: 'border-firol-200',  bg: 'bg-firol-50/40',  iconBg: 'bg-firol-100',  iconColor: 'text-firol-600' },
  violet: { border: 'border-violet-200', bg: 'bg-violet-50/40', iconBg: 'bg-violet-100', iconColor: 'text-violet-600' },
  blue:   { border: 'border-blue-200',   bg: 'bg-blue-50/40',   iconBg: 'bg-blue-100',   iconColor: 'text-blue-600' },
};

function certDaysLeft(validTo: string): number | null {
  if (!validTo) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  // Parse as local date — "YYYY-MM-DD" without time is treated as UTC by the
  // Date constructor, which causes off-by-one errors in timezones ahead of UTC.
  const [y, m, d] = validTo.split('-').map(Number);
  const expiry = new Date(y, m - 1, d);
  return Math.ceil((expiry.getTime() - today.getTime()) / 86_400_000);
}


function CertCard({
  color,
  title,
  subtitle,
  certValue,
  certPlaceholder,
  onCertChange,
  validFrom,
  validTo,
  onValidFromChange,
  onValidToChange,
}: {
  color: CertCardColor;
  title: string;
  subtitle: string;
  certValue: string;
  certPlaceholder: string;
  onCertChange: (v: string) => void;
  validFrom: string;
  validTo: string;
  onValidFromChange: (v: string) => void;
  onValidToChange: (v: string) => void;
}) {
  const s = CERT_CARD_STYLES[color];
  const days = certDaysLeft(validTo);
  const isExpired = days !== null && days < 0;
  const isExpiringSoon = days !== null && days >= 0 && days <= 30;

  return (
    <div className={cn('rounded-2xl border p-4 flex flex-col gap-3', s.border, s.bg)}>
      <div className="flex items-start gap-2.5">
        <div className={cn('mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl', s.iconBg)}>
          <Hash className={cn('size-4', s.iconColor)} />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-ink-900">{title}</p>
          <p className="mt-0.5 text-xs text-ink-500">{subtitle}</p>
        </div>
      </div>

      <Field label="Číslo oprávnenia / osvedčenia">
        {(p) => (
          <Input
            {...p}
            leftIcon={<Hash className="size-4" />}
            value={certValue}
            onChange={(e) => onCertChange(e.target.value)}
            placeholder={certPlaceholder}
          />
        )}
      </Field>

      <div className="grid gap-3 grid-cols-2">
        <Field label="Platnosť od">
          {(p) => (
            <Input
              {...p}
              type="date"
              leftIcon={<CalendarDays className="size-4" />}
              value={validFrom}
              onChange={(e) => onValidFromChange(e.target.value)}
            />
          )}
        </Field>
        <Field label="Platnosť do">
          {(p) => (
            <Input
              {...p}
              type="date"
              leftIcon={<CalendarDays className="size-4" />}
              value={validTo}
              onChange={(e) => onValidToChange(e.target.value)}
            />
          )}
        </Field>
      </div>

      {isExpired && (
        <p className="flex items-center gap-1.5 text-xs font-medium text-[var(--color-status-bad)]">
          <AlertTriangle className="size-3.5 shrink-0" />
          Neplatné
        </p>
      )}
      {isExpiringSoon && (
        <p className="flex items-center gap-1.5 text-xs font-medium text-[var(--color-status-warn)]">
          <AlertTriangle className="size-3.5 shrink-0" />
          Blíži sa koniec platnosti
        </p>
      )}
    </div>
  );
}

// ─── Shared helpers ───────────────────────────────────────────────────────────

function SignaturePreview({ hasSignature, cacheBuster }: { hasSignature: boolean; cacheBuster: number }) {
  if (!hasSignature) {
    return (
      <div className="relative w-full overflow-hidden rounded-2xl border border-dashed border-ink-200 bg-white" style={{ paddingTop: 'calc(100% / 3)' }}>
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 text-center text-ink-400">
          <ImagePlus className="size-5" />
          <span className="text-xs">Zatiaľ bez podpisu</span>
        </div>
      </div>
    );
  }
  return (
    <div className="relative w-full overflow-hidden rounded-2xl border border-ink-200 bg-white" style={{ paddingTop: 'calc(100% / 3)' }}>
      <div className="absolute inset-0 flex items-center justify-center p-3">
        <img
          src={InspectorProfileApi.signatureUrl(cacheBuster)}
          alt="Podpis revízneho technika"
          className="max-h-full max-w-full object-contain"
        />
      </div>
    </div>
  );
}
