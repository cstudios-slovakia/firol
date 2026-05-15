import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
  AtSign, Building2, CalendarDays, Check, Copy, FileSignature, GraduationCap, Hash,
  ImagePlus, MailPlus, Palette, Phone, Plus, RotateCcw,
  ShieldCheck, ShieldOff, Trash2, UploadCloud, User, UserCheck, UsersRound,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { AccountApi, type Account } from '@/api/account';
import { InspectorProfileApi, type InspectorProfile } from '@/api/inspectorProfile';
import { Trainers, type Trainer } from '@/api/trainers';
import { Team, type TeamMember } from '@/api/team';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { CardBlockSkeleton, SkeletonList } from '@/components/ui/Skeleton';
import { Badge } from '@/components/ui/Badge';

/**
 * Settings — Phase 3a-1 only renders the Inspector profile section. Other
 * sections (account branding, technicians, billing) land in Phase 5.
 */
export function SettingsPage() {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [profile, setProfile] = useState<InspectorProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [signatureCacheBust, setSignatureCacheBust] = useState<number>(() => Date.now());

  const [certNumber, setCertNumber] = useState('');
  const [validFrom, setValidFrom] = useState('');
  const [validTo, setValidTo] = useState('');

  const fileInputRef = useRef<HTMLInputElement>(null);

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
    return () => {
      cancelled = true;
    };
  }, []);

  function applyProfile(p: InspectorProfile) {
    setProfile(p);
    setCertNumber(p.certification_number ?? '');
    setValidFrom(p.valid_from ?? '');
    setValidTo(p.valid_to ?? '');
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSaving(true);
    try {
      const res = await InspectorProfileApi.update(
        {
          certification_number: certNumber.trim() || null,
          valid_from: validFrom || null,
          valid_to: validTo || null,
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

  async function onSignatureChosen(file: File | undefined) {
    if (!file) return;
    setError(null);
    setSaving(true);
    try {
      const res = await InspectorProfileApi.uploadSignature(file, csrfToken);
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
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">Nastavenia</h1>
        <p className="mt-0.5 text-sm text-ink-500">
          Profil revízneho technika sa použije na PDF protokoloch.
        </p>
      </header>

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
            <input
              ref={fileInputRef}
              type="file"
              accept="image/png"
              className="hidden"
              onChange={(e) => onSignatureChosen(e.target.files?.[0])}
            />
            <Button
              type="button"
              variant="secondary"
              leftIcon={<FileSignature className="size-4" />}
              onClick={() => fileInputRef.current?.click()}
              loading={saving}
            >
              {profile?.has_signature ? 'Nahrať nový podpis' : 'Nahrať podpis'}
            </Button>
            <p className="text-xs text-ink-400">
              PNG s priehľadným pozadím, max 512 KB. Optimálne ~600×200 px.
            </p>
          </div>

          <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
            <Field label="Číslo oprávnenia" hint="Napr. RT-OPP-2024-0123">
              {(p) => (
                <Input
                  {...p}
                  leftIcon={<Hash className="size-4" />}
                  value={certNumber}
                  onChange={(e) => setCertNumber(e.target.value)}
                  placeholder="RT-OPP-2024-0123"
                />
              )}
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Platnosť od">
                {(p) => (
                  <Input
                    {...p}
                    type="date"
                    leftIcon={<CalendarDays className="size-4" />}
                    value={validFrom}
                    onChange={(e) => setValidFrom(e.target.value)}
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
                    onChange={(e) => setValidTo(e.target.value)}
                  />
                )}
              </Field>
            </div>

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

      <BrandingSection />

      <TrainersSection />

      <TeamSection />
    </div>
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
            hint="Zobrazí sa v záhlaví PDF: „<Názov> · Záznam o kontrole RPHP"
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

function LogoPreview({
  hasLogo,
  cacheBuster,
}: {
  hasLogo: boolean;
  cacheBuster: number;
}) {
  if (!hasLogo) {
    return (
      <div className="flex aspect-[3/1] flex-col items-center justify-center gap-1 rounded-2xl border border-dashed border-ink-200 bg-white text-center text-ink-400">
        <ImagePlus className="size-5" />
        <span className="text-xs">Zatiaľ bez loga</span>
      </div>
    );
  }
  return (
    <div className="flex aspect-[3/1] items-center justify-center rounded-2xl border border-ink-200 bg-white p-3">
      <img
        src={AccountApi.logoUrl(cacheBuster)}
        alt="Logo spoločnosti"
        className="max-h-full max-w-full object-contain"
      />
    </div>
  );
}

function TrainersSection() {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [trainers, setTrainers] = useState<Trainer[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const [newName, setNewName] = useState('');
  const [newCert, setNewCert] = useState('');

  const sigInputs = useRef<Record<number, HTMLInputElement | null>>({});

  useEffect(() => {
    let cancelled = false;
    Trainers.list()
      .then((res) => { if (!cancelled) setTrainers(res.items); })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať školiteľov.');
      });
    return () => { cancelled = true; };
  }, []);

  async function onAdd(e: FormEvent) {
    e.preventDefault();
    if (!newName.trim()) {
      setError('Doplň meno školiteľa.');
      return;
    }
    setError(null);
    setAdding(true);
    try {
      const res = await Trainers.create(
        { fullname: newName.trim(), certification_number: newCert.trim() || null },
        csrfToken,
      );
      setTrainers((prev) => prev ? [...prev, res.trainer].sort((a, b) => a.fullname.localeCompare(b.fullname)) : [res.trainer]);
      setNewName('');
      setNewCert('');
      toast.success('Školiteľ pridaný');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Nepodarilo sa pridať školiteľa.';
      setError(msg);
      toast.error(msg);
    } finally {
      setAdding(false);
    }
  }

  async function onArchive(id: number) {
    if (!window.confirm('Naozaj archivovať školiteľa? Existujúce školenia s ním zostanú nezmenené.')) return;
    setBusyId(id);
    try {
      await Trainers.archive(id, csrfToken);
      setTrainers((prev) => prev ? prev.filter((t) => t.id !== id) : prev);
      toast.success('Školiteľ archivovaný');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Archiváciu sa nepodarilo dokončiť.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusyId(null);
    }
  }

  async function onSignatureChosen(id: number, file: File | undefined) {
    if (!file) return;
    setBusyId(id);
    setError(null);
    try {
      const res = await Trainers.uploadSignature(id, file, csrfToken);
      setTrainers((prev) => prev ? prev.map((t) => t.id === id ? res.trainer : t) : prev);
      toast.success('Podpis školiteľa nahraný');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Nahranie podpisu sa nepodarilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusyId(null);
    }
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <GraduationCap className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Školitelia</h2>
          <p className="text-xs text-ink-500">
            Osoby, ktoré vykonávajú školenia. Ich meno a podpis sa objavia na PDF protokole.
          </p>
        </div>
      </div>

      <div className="px-5 py-4">
        {error && (
          <div className="mb-3 rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </div>
        )}

        {trainers === null ? (
          <SkeletonList count={2} />
        ) : trainers.length === 0 ? (
          <p className="mb-3 text-sm text-ink-500">
            Zatiaľ nemáš žiadnych školiteľov. Pridaj prvého nižšie.
          </p>
        ) : (
          <ul className="mb-4 flex flex-col gap-2">
            {trainers.map((t) => (
              <li key={t.id} className="flex items-center gap-3 rounded-2xl border border-ink-100 px-3 py-2.5">
                <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
                  <User className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-ink-900">{t.fullname}</p>
                  <p className="mt-0.5 truncate text-xs text-ink-500">
                    {t.certification_number ?? <span className="italic">— bez čísla oprávnenia —</span>}
                    <span className="mx-1.5 text-ink-300">·</span>
                    <Badge tone={t.has_signature ? 'ok' : 'warn'}>
                      {t.has_signature ? 'Podpis nahraný' : 'Bez podpisu'}
                    </Badge>
                  </p>
                </div>
                <input
                  ref={(el) => { sigInputs.current[t.id] = el; }}
                  type="file"
                  accept="image/png"
                  className="hidden"
                  onChange={(e) => onSignatureChosen(t.id, e.target.files?.[0])}
                />
                <button
                  type="button"
                  onClick={() => sigInputs.current[t.id]?.click()}
                  disabled={busyId === t.id}
                  title="Nahrať podpis (PNG)"
                  className="flex items-center gap-1.5 rounded-xl px-2.5 py-1.5 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 disabled:opacity-50"
                >
                  {busyId === t.id ? <Spinner size="sm" /> : <FileSignature className="size-4 shrink-0" />}
                  <span>Nahrať podpis</span>
                </button>
                <button
                  type="button"
                  onClick={() => onArchive(t.id)}
                  disabled={busyId === t.id}
                  title="Archivovať"
                  className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
                >
                  <Trash2 className="size-4" />
                </button>
              </li>
            ))}
          </ul>
        )}

        <form onSubmit={onAdd} className="flex flex-col gap-3 rounded-2xl border border-dashed border-ink-200 p-3 sm:flex-row sm:items-end">
          <div className="flex-1">
            <Field label="Meno a priezvisko" required>
              {(p) => (
                <Input {...p} required leftIcon={<User className="size-4" />}
                  value={newName} onChange={(e) => setNewName(e.target.value)}
                  placeholder="Mgr. Jana Lektorová" />
              )}
            </Field>
          </div>
          <div className="flex-1">
            <Field label="Číslo oprávnenia">
              {(p) => (
                <Input {...p} leftIcon={<Hash className="size-4" />}
                  value={newCert} onChange={(e) => setNewCert(e.target.value)}
                  placeholder="OPP-SK-2024-0123" />
              )}
            </Field>
          </div>
          <Button type="submit" loading={adding} leftIcon={<Plus className="size-4" />}>
            Pridať
          </Button>
        </form>
      </div>
    </Card>
  );
}

function TeamSection() {
  const { csrfToken, user, accounts, activeAccountId } = useAuth();
  const toast = useToast();

  const activeAccount = accounts.find((a) => a.id === activeAccountId) ?? null;
  const isMain = user !== null && activeAccount !== null && activeAccount.main_user_id === user.id;

  const [members, setMembers] = useState<TeamMember[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [adding, setAdding] = useState(false);

  const [showInvite, setShowInvite] = useState(false);
  const [inviteName, setInviteName] = useState('');
  const [inviteEmail, setInviteEmail] = useState('');
  const [invitePhone, setInvitePhone] = useState('');
  const [inviteLink, setInviteLink] = useState<string | null>(null);
  const [linkCopied, setLinkCopied] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Team.list()
      .then((res) => { if (!cancelled) setMembers(res.items); })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať tím.');
      });
    return () => { cancelled = true; };
  }, []);

  async function onInvite(e: FormEvent) {
    e.preventDefault();
    if (!inviteName.trim() || !inviteEmail.trim()) {
      setError('Doplň meno a e-mail.');
      return;
    }
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
      setMembers((prev) => prev ? [...prev, res.item] : [res.item]);
      toast.success('Pozvánka vytvorená');
      if (res.invite_token) {
        const link = `${window.location.origin}/password-reset/confirm?token=${res.invite_token}`;
        setInviteLink(link);
        setLinkCopied(false);
      } else {
        // Existing user — they already know their password, no link needed.
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
      toast.success('Technik odstránený');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Odstránenie zlyhalo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setBusyId(null);
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
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <UsersRound className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Technici</h2>
          <p className="text-xs text-ink-500">
            Ľudia, ktorí majú prístup do tejto firmy. Spravovať môže len hlavný používateľ.
          </p>
        </div>
        {isMain && (
          <Button
            type="button"
            variant="secondary"
            leftIcon={<MailPlus className="size-4" />}
            onClick={() => { setShowInvite((v) => !v); setInviteLink(null); }}
          >
            {showInvite ? 'Zrušiť' : 'Pozvať technika'}
          </Button>
        )}
      </div>

      <div className="px-5 py-4">
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
              <Field label="Meno a priezvisko" required>
                {(p) => (
                  <Input {...p} required leftIcon={<User className="size-4" />}
                    value={inviteName} onChange={(e) => setInviteName(e.target.value)}
                    placeholder="Ján Technik" />
                )}
              </Field>
              <Field label="E-mail" required>
                {(p) => (
                  <Input {...p} required type="email" leftIcon={<AtSign className="size-4" />}
                    value={inviteEmail} onChange={(e) => setInviteEmail(e.target.value)}
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
              Pozvánkový odkaz
            </p>
            <p className="mb-2 text-xs text-ink-600">
              Skopíruj odkaz a pošli ho technikovi — platí 7 dní. Po jeho otvorení si nastaví heslo.
              (Automatické odosielanie e-mailov pribudne neskôr.)
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

function SignaturePreview({
  hasSignature,
  cacheBuster,
}: {
  hasSignature: boolean;
  cacheBuster: number;
}) {
  if (!hasSignature) {
    return (
      <div className="flex aspect-[3/1] flex-col items-center justify-center gap-1 rounded-2xl border border-dashed border-ink-200 bg-white text-center text-ink-400">
        <ImagePlus className="size-5" />
        <span className="text-xs">Zatiaľ bez podpisu</span>
      </div>
    );
  }
  return (
    <div className="flex aspect-[3/1] items-center justify-center rounded-2xl border border-ink-200 bg-white p-3">
      <img
        src={InspectorProfileApi.signatureUrl(cacheBuster)}
        alt="Podpis revízneho technika"
        className="max-h-full max-w-full object-contain"
      />
    </div>
  );
}

