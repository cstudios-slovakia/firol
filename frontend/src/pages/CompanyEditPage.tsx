import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type Company } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { CompanyForm } from '@/components/CompanyForm';

export function CompanyEditPage() {
  const params = useParams<{ id?: string }>();
  const editing = params.id !== undefined;
  const id = editing ? Number(params.id) : null;

  const navigate = useNavigate();
  const { csrfToken } = useAuth();

  const [company, setCompany] = useState<Company | null>(null);
  const [loadStatus, setLoadStatus] = useState<'idle' | 'loading'>(editing ? 'loading' : 'idle');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!editing || id === null) return;
    let cancelled = false;
    Companies.show(id)
      .then((res) => {
        if (cancelled) return;
        setCompany(res.company);
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

  function handleSaved(saved: Company) {
    navigate(`/companies/${saved.id}`, { replace: true });
  }

  return (
    <div className="flex flex-col gap-4">
      <Link
        to={editing && id ? `/companies/${id}` : '/'}
        className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
      >
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
          {error && !editing && (
            <div className="mb-3 rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}
          {editing && error && (
            <div className="mb-3 rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}
          <CompanyForm
            initial={company ?? undefined}
            onSaved={handleSaved}
          />
        </Card>
      )}
    </div>
  );
}
