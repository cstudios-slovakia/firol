import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, Hash, MapPin, Phone, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type Company } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';

export function CompanyEditPage() {
  const params = useParams<{ id?: string }>();
  const editing = params.id !== undefined;
  const id = editing ? Number(params.id) : null;

  const navigate = useNavigate();
  const { csrfToken } = useAuth();

  const [name, setName] = useState('');
  const [ico, setIco] = useState('');
  const [address, setAddress] = useState('');
  const [contact, setContact] = useState('');

  const [loadStatus, setLoadStatus] = useState<'idle' | 'loading'>(editing ? 'loading' : 'idle');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!editing || id === null) return;
    let cancelled = false;
    Companies.show(id)
      .then((res) => {
        if (cancelled) return;
        const c: Company = res.company;
        setName(c.name);
        setIco(c.ico ?? '');
        setAddress(c.address ?? '');
        setContact(c.contact ?? '');
        setLoadStatus('idle');
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať firmu.');
        setLoadStatus('idle');
      });
    return () => {
      cancelled = true;
    };
  }, [editing, id]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    const payload = {
      name: name.trim(),
      ico: ico.trim() || undefined,
      address: address.trim() || undefined,
      contact: contact.trim() || undefined,
    };
    try {
      if (editing && id !== null) {
        await Companies.update(id, payload, csrfToken);
        navigate(`/companies/${id}`, { replace: true });
      } else {
        const res = await Companies.create(payload, csrfToken);
        navigate(`/companies/${res.company.id}`, { replace: true });
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  async function onArchive() {
    if (!editing || id === null) return;
    if (!window.confirm('Naozaj archivovať firmu? Údaje zostanú v systéme, len sa skryjú.')) return;
    try {
      await Companies.archive(id, csrfToken);
      navigate('/', { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Archiváciu sa nepodarilo dokončiť.');
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <Link to={editing && id ? `/companies/${id}` : '/'} className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť
      </Link>

      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">
          {editing ? 'Upraviť firmu' : 'Nová firma'}
        </h1>
        <p className="mt-0.5 text-sm text-ink-500">
          Klient, ku ktorému budeš zaznamenávať revízie a kontroly.
        </p>
      </header>

      {loadStatus === 'loading' ? (
        <div className="flex justify-center py-10 text-ink-400">
          <Spinner />
        </div>
      ) : (
        <Card className="p-5">
          <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
            <Field label="Názov firmy" required>
              {(p) => (
                <Input
                  {...p}
                  required
                  leftIcon={<Building2 className="size-4" />}
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="ALFA Trade s. r. o."
                />
              )}
            </Field>

            <Field label="IČO">
              {(p) => (
                <Input
                  {...p}
                  inputMode="numeric"
                  leftIcon={<Hash className="size-4" />}
                  value={ico}
                  onChange={(e) => setIco(e.target.value)}
                  placeholder="12345678"
                />
              )}
            </Field>

            <Field label="Adresa">
              {(p) => (
                <Input
                  {...p}
                  leftIcon={<MapPin className="size-4" />}
                  value={address}
                  onChange={(e) => setAddress(e.target.value)}
                  placeholder="Hlavná 12, 851 01 Bratislava"
                />
              )}
            </Field>

            <Field label="Kontakt" hint="Napríklad email a telefón na kontaktnú osobu">
              {(p) => (
                <Input
                  {...p}
                  leftIcon={<Phone className="size-4" />}
                  value={contact}
                  onChange={(e) => setContact(e.target.value)}
                  placeholder="info@firma.sk · +421 900 123 456"
                />
              )}
            </Field>

            {error && (
              <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                {error}
              </div>
            )}

            <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
              {editing && (
                <Button
                  type="button"
                  variant="ghost"
                  onClick={onArchive}
                  leftIcon={<Trash2 className="size-4" />}
                  className="sm:mr-auto text-status-bad hover:bg-[var(--color-status-bad-bg)]"
                >
                  Archivovať
                </Button>
              )}
              <Button type="submit" loading={submitting}>
                {editing ? 'Uložiť zmeny' : 'Vytvoriť firmu'}
              </Button>
            </div>
          </form>
        </Card>
      )}
    </div>
  );
}
