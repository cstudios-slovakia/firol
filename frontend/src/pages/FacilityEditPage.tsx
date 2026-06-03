import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, MapPin, NotebookPen, User, Warehouse } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Facilities } from '@/api/facilities';
import { facilityCreateOptimistic } from '@/lib/offlineEntities';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';

type Mode = { kind: 'create'; companyId: number } | { kind: 'edit'; facilityId: number };

function modeFromParams(params: { id?: string; companyId?: string }): Mode {
  // Path /companies/:companyId/facilities/new uses companyId.
  // Path /facilities/:id/edit uses id.
  if (params.companyId !== undefined) {
    return { kind: 'create', companyId: Number(params.companyId) };
  }
  return { kind: 'edit', facilityId: Number(params.id) };
}

export function FacilityEditPage() {
  const params = useParams<{ id?: string; companyId?: string }>();
  const mode = modeFromParams(params);
  const navigate = useNavigate();
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const [contactPerson, setContactPerson] = useState('');
  const [notes, setNotes] = useState('');
  const [companyId, setCompanyId] = useState<number | null>(
    mode.kind === 'create' ? mode.companyId : null,
  );

  const [loading, setLoading] = useState(mode.kind === 'edit');
  const [submitting, setSubmitting] = useState(false);
  const [nameError, setNameError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (mode.kind !== 'edit') return;
    let cancelled = false;
    Facilities.show(mode.facilityId)
      .then((res) => {
        if (cancelled) return;
        const f = res.facility;
        setName(f.name);
        setAddress(f.address ?? '');
        setContactPerson(f.contact_person ?? '');
        setNotes(f.notes ?? '');
        setCompanyId(f.company_id);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať prevádzku.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [mode.kind, mode.kind === 'edit' ? mode.facilityId : null]); // eslint-disable-line react-hooks/exhaustive-deps

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!name.trim()) { setNameError('Doplň názov prevádzky.'); return; }
    setNameError(null);
    setError(null);
    setSubmitting(true);
    const payload = {
      name: name.trim(),
      address: address.trim() || undefined,
      contact_person: contactPerson.trim() || undefined,
      notes: notes.trim() || undefined,
    };
    try {
      if (mode.kind === 'create') {
        const optimistic = facilityCreateOptimistic({
          companyId: mode.companyId,
          name: payload.name,
          address: payload.address ?? null,
          contact_person: payload.contact_person ?? null,
          notes: payload.notes ?? null,
        });
        const res = await Facilities.createUnderCompany(mode.companyId, payload, csrfToken, optimistic);
        toast.success('Prevádzka vytvorená');
        navigate(`/facilities/${res.facility.id}`, { replace: true });
      } else {
        await Facilities.update(mode.facilityId, payload, csrfToken);
        toast.success('Prevádzka uložená');
        navigate(`/facilities/${mode.facilityId}`, { replace: true });
      }
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  const backHref =
    mode.kind === 'create'
      ? `/companies/${mode.companyId}`
      : companyId
        ? `/facilities/${mode.facilityId}`
        : '/';

  return (
    <div className="flex flex-col gap-4">
      <Link
        to={backHref}
        className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
      >
        <ArrowLeft className="size-4" />
        Späť
      </Link>

      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">
          {mode.kind === 'edit' ? 'Upraviť prevádzku' : 'Nová prevádzka'}
        </h1>
        <p className="mt-0.5 text-sm text-ink-500">
          Konkrétna pobočka, sklad alebo objekt firmy.
        </p>
      </header>

      {loading ? (
        <div className="flex justify-center py-10 text-ink-400">
          <Spinner />
        </div>
      ) : (
        <Card className="p-5">
          <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
            <Field label="Názov prevádzky" required error={nameError}>
              {(p) => (
                <Input
                  {...p}
                  required
                  leftIcon={<Warehouse className="size-4" />}
                  value={name}
                  onChange={(e) => { setName(e.target.value); if (nameError) setNameError(null); }}
                  placeholder="Sklad A"
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
                  placeholder="Priemyselná 5, Bratislava"
                />
              )}
            </Field>

            <Field label="Kontaktná osoba">
              {(p) => (
                <Input
                  {...p}
                  leftIcon={<User className="size-4" />}
                  value={contactPerson}
                  onChange={(e) => setContactPerson(e.target.value)}
                  placeholder="Peter Vedúci"
                />
              )}
            </Field>

            <Field label="Poznámky" hint="Napríklad prístup, otváracie hodiny, špecifiká">
              {(p) => (
                <div className="relative">
                  <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                    <NotebookPen className="size-4" />
                  </span>
                  <textarea
                    id={p.id}
                    aria-invalid={p['aria-invalid']}
                    rows={3}
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="Vstup len cez recepciu, kód do skladu zmenil v 02/2026."
                    className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
                  />
                </div>
              )}
            </Field>

            {error && (
              <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                {error}
              </div>
            )}

            <div className="flex justify-end pt-2">
              <Button type="submit" loading={submitting}>
                {mode.kind === 'edit' ? 'Uložiť zmeny' : 'Vytvoriť prevádzku'}
              </Button>
            </div>
          </form>
        </Card>
      )}
    </div>
  );
}
