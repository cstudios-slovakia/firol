import { useState, useEffect, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Mail, Phone, User, X } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { ApiError } from '@/lib/api';
import { Invites, type InvitePreview } from '@/api/team';
import { useAuth } from '@/auth/AuthContext';
import { AuthLayout } from './AuthLayout';

export function InviteAcceptPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const navigate = useNavigate();
  const { refresh, user } = useAuth();

  const [preview, setPreview] = useState<InvitePreview | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  // Form state for fresh-user path.
  const [fullname, setFullname] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [fieldErrors, setFieldErrors] = useState<{ fullname?: string; password?: string; passwordConfirm?: string }>({});

  const [submitting, setSubmitting] = useState(false);
  const [declining, setDeclining] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [declined, setDeclined] = useState(false);

  useEffect(() => {
    if (!token) {
      setLoadError('Chýba token — otvor odkaz z emailu znova.');
      setLoading(false);
      return;
    }
    let cancelled = false;
    Invites.preview(token)
      .then((data) => {
        if (cancelled) return;
        setPreview(data);
        setFullname(data.invite.fullname);
        setPhone(data.invite.phone ?? '');
      })
      .catch((err) => {
        if (cancelled) return;
        setLoadError(err instanceof ApiError ? err.message : 'Pozvánku sa nepodarilo načítať.');
      })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [token]);

  async function onAccept(e?: FormEvent) {
    e?.preventDefault();
    if (!preview) return;

    if (!preview.user_exists) {
      const errs: typeof fieldErrors = {};
      if (!fullname.trim()) errs.fullname = 'Zadaj meno a priezvisko.';
      if (password.length < 8) errs.password = 'Heslo musí mať aspoň 8 znakov.';
      if (password !== passwordConfirm) errs.passwordConfirm = 'Heslá sa nezhodujú.';
      if (Object.keys(errs).length > 0) { setFieldErrors(errs); return; }
      setFieldErrors({});
    }

    setError(null);
    setSubmitting(true);
    try {
      await Invites.accept(token, preview.user_exists
        ? {}
        : { fullname: fullname.trim(), phone: phone.trim() || null, password });
      // The backend logs us in and sets the new account as active. Re-fetch
      // /api/me so the context picks it up, then land on the dashboard.
      await refresh();
      navigate('/');
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        // Login required — let the user log in and come back.
        const next = encodeURIComponent(`/invite/accept?token=${token}`);
        navigate(`/login?next=${next}`);
        return;
      }
      setError(err instanceof ApiError ? err.message : 'Pozvánku sa nepodarilo prijať.');
    } finally {
      setSubmitting(false);
    }
  }

  async function onDecline() {
    setDeclining(true);
    setError(null);
    try {
      await Invites.decline(token);
      setDeclined(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Pozvánku sa nepodarilo odmietnuť.');
    } finally {
      setDeclining(false);
    }
  }

  if (loading) {
    return (
      <AuthLayout title="Pozvánka" subtitle="Načítavam pozvánku…">
        <Card className="p-6 text-sm text-ink-500">Moment…</Card>
      </AuthLayout>
    );
  }

  if (loadError || !preview) {
    return (
      <AuthLayout
        title="Pozvánka"
        subtitle="Tento odkaz nie je platný."
        footer={
          <Link to="/login" className="font-medium text-firol-600 hover:text-firol-700">
            ← Späť na prihlásenie
          </Link>
        }
      >
        <Card className="p-6 text-sm text-status-bad">
          {loadError ?? 'Pozvánku sa nepodarilo načítať.'}
        </Card>
      </AuthLayout>
    );
  }

  if (declined) {
    return (
      <AuthLayout title="Pozvánka odmietnutá" subtitle="Nikto ťa do tímu nepridá.">
        <Card className="p-6 text-sm text-ink-500">
          Tvoje rozhodnutie sme zaznamenali. Túto stránku môžeš zavrieť.
        </Card>
      </AuthLayout>
    );
  }

  const inv = preview.invite;
  const wrongSession = preview.user_exists && user !== null && preview.session_email !== inv.email;

  return (
    <AuthLayout
      wide
      title="Pozvánka do tímu"
      subtitle={`${inv.inviter_name} ťa pozval/a do firmy ${inv.account_name}.`}
      footer={
        <Link to="/login" className="font-medium text-firol-600 hover:text-firol-700">
          ← Späť na prihlásenie
        </Link>
      }
    >
      <Card className="p-6">
        <dl className="mb-4 grid grid-cols-1 gap-2 rounded-2xl bg-ink-50 px-4 py-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-500">Firma</dt>
            <dd className="text-ink-900">{inv.account_name}</dd>
          </div>
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-500">Pozval/a</dt>
            <dd className="text-ink-900">{inv.inviter_name}</dd>
          </div>
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-500">Email</dt>
            <dd className="text-ink-900">{inv.email}</dd>
          </div>
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-500">Platí do</dt>
            <dd className="text-ink-900">{new Date(inv.expires_at).toLocaleString('sk-SK')}</dd>
          </div>
        </dl>

        {wrongSession ? (
          <div className="flex flex-col gap-3">
            <p className="text-sm text-ink-700">
              Si prihlásený/á ako <strong>{preview.session_email}</strong>, ale pozvánka je pre{' '}
              <strong>{inv.email}</strong>. Najprv sa odhlás a prihlás sa pod správnym emailom.
            </p>
            <Link
              to="/login"
              className="self-start rounded-2xl bg-firol-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-firol-600"
            >
              Prejsť na prihlásenie
            </Link>
          </div>
        ) : preview.user_exists ? (
          <div className="flex flex-col gap-4">
            <p className="text-sm text-ink-700">
              Tvoj účet pod emailom <strong>{inv.email}</strong> už existuje. Ak pozvánku prijmeš,
              firma <strong>{inv.account_name}</strong> sa ti pridá do prepínača účtov.
            </p>
            {error && (
              <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                {error}
              </div>
            )}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <Button onClick={onAccept} loading={submitting} disabled={declining} className="sm:flex-1">
                Prijať pozvánku
              </Button>
              <Button
                variant="ghost"
                onClick={onDecline}
                loading={declining}
                disabled={submitting}
                className="sm:flex-1"
              >
                <X className="mr-1 size-4" />
                Odmietnuť
              </Button>
            </div>
          </div>
        ) : (
          <form onSubmit={onAccept} className="flex flex-col gap-4" noValidate>
            <p className="text-sm text-ink-700">
              Vytvor si konto pre Firol — heslo si nastavíš teraz a budeš mať okamžite prístup.
            </p>

            <Field label="Email">
              {(p) => (
                <Input
                  {...p}
                  type="email"
                  autoComplete="username"
                  value={inv.email}
                  readOnly
                  leftIcon={<Mail className="size-4" />}
                />
              )}
            </Field>

            <Field label="Meno a priezvisko" required error={fieldErrors.fullname}>
              {(p) => (
                <Input
                  {...p}
                  autoComplete="name"
                  required
                  leftIcon={<User className="size-4" />}
                  value={fullname}
                  onChange={(e) => { setFullname(e.target.value); if (fieldErrors.fullname) setFieldErrors((prev) => ({ ...prev, fullname: undefined })); }}
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

            <Field label="Heslo" hint="Minimálne 8 znakov" required error={fieldErrors.password}>
              {(p) => (
                <PasswordInput
                  {...p}
                  autoComplete="new-password"
                  required
                  minLength={8}
                  value={password}
                  onChange={(e) => { setPassword(e.target.value); if (fieldErrors.password) setFieldErrors((prev) => ({ ...prev, password: undefined })); }}
                  placeholder="••••••••"
                />
              )}
            </Field>

            <Field label="Potvrdenie hesla" required error={fieldErrors.passwordConfirm}>
              {(p) => (
                <PasswordInput
                  {...p}
                  autoComplete="new-password"
                  required
                  minLength={8}
                  value={passwordConfirm}
                  onChange={(e) => { setPasswordConfirm(e.target.value); if (fieldErrors.passwordConfirm) setFieldErrors((prev) => ({ ...prev, passwordConfirm: undefined })); }}
                  placeholder="••••••••"
                />
              )}
            </Field>

            {error && (
              <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                {error}
              </div>
            )}

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <Button type="submit" loading={submitting} disabled={declining} className="sm:flex-1">
                Vytvoriť konto a prijať pozvánku
              </Button>
              <Button
                type="button"
                variant="ghost"
                onClick={onDecline}
                loading={declining}
                disabled={submitting}
                className="sm:flex-1"
              >
                <X className="mr-1 size-4" />
                Odmietnuť
              </Button>
            </div>
          </form>
        )}
      </Card>
    </AuthLayout>
  );
}
