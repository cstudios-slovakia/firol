import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
  CalendarDays, FileSignature, GraduationCap, Hash, ImagePlus, Plus, ShieldCheck,
  Sparkles, Trash2, UploadCloud, User,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { InspectorProfileApi, type InspectorProfile } from '@/api/inspectorProfile';
import { Trainers, type Trainer } from '@/api/trainers';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { Badge } from '@/components/ui/Badge';

/**
 * Settings — Phase 3a-1 only renders the Inspector profile section. Other
 * sections (account branding, technicians, billing) land in Phase 5.
 */
export function SettingsPage() {
  const { csrfToken } = useAuth();

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
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
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
    } catch (err) {
      setError(
        err instanceof ApiError ? err.message : 'Nahranie podpisu sa nepodarilo.',
      );
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
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
              leftIcon={<UploadCloud className="size-4" />}
              onClick={() => fileInputRef.current?.click()}
              loading={saving}
            >
              {profile?.has_signature ? 'Nahrať nový podpis' : 'Nahrať podpis (PNG)'}
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

      <TrainersSection />

      <Card className="flex items-start gap-3 px-4 py-4 bg-firol-50/40">
        <div className="grid size-9 shrink-0 place-items-center rounded-xl bg-white text-firol-500">
          <Sparkles className="size-4" />
        </div>
        <div className="flex-1">
          <h3 className="text-sm font-semibold text-ink-900">
            Ostatné nastavenia pripravujeme
          </h3>
          <p className="mt-0.5 text-xs text-ink-500">
            Branding (logo + farba), správa technikov a fakturácia odomykáme postupne.
          </p>
        </div>
      </Card>
    </div>
  );
}

function TrainersSection() {
  const { csrfToken } = useAuth();
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
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Nepodarilo sa pridať školiteľa.');
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
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Archiváciu sa nepodarilo dokončiť.');
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
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Nahranie podpisu sa nepodarilo.');
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
          <div className="flex justify-center py-6 text-ink-400">
            <Spinner />
          </div>
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
                  className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 disabled:opacity-50"
                >
                  {busyId === t.id ? <Spinner size="sm" /> : <UploadCloud className="size-4" />}
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
