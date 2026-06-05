import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft, Building2, CalendarDays, Save, User, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Team, type TeamMember } from '@/api/team';
import {
  TRAINING_TYPE_LABELS,
  Trainings,
  type Training,
} from '@/api/trainings';
import { ApiError } from '@/lib/api';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';

export function TrainingEditPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const navigate = useNavigate();
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [training, setTraining] = useState<Training | null>(null);
  const [members, setMembers] = useState<TeamMember[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [date, setDate] = useState('');
  const [trainerId, setTrainerId] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [dateError, setDateError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    // The training drives the page (served from cache offline). The team list
    // is best-effort — offline it may be uncached, in which case the trainer
    // picker simply has no options.
    Trainings.show(id)
      .then(async (detail) => {
        if (cancelled) return;
        const t = detail.training;
        setTraining(t);
        setDate(t.date ?? '');
        setTrainerId(t.trainer_id);
        const tm = await Team.list().catch(() => ({ items: [] as TeamMember[] }));
        if (cancelled) return;
        setMembers(tm.items.filter((m) => m.is_active));
        setLoading(false);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať školenie.');
        setLoading(false);
      });
    return () => { cancelled = true; };
  }, [id]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!date) { setDateError('Zadaj dátum školenia.'); return; }
    setDateError(null);
    setError(null);
    setSubmitting(true);
    try {
      await Trainings.update(id, {
        date,
        trainer_id: trainerId,
      }, csrfToken);
      toast.success('Školenie uložené');
      navigate(`/trainings/${id}`, { replace: true });
    } catch (err) {
      // Offline edit: queued + cache patched, navigate to the detail anyway.
      if (handleOfflineSave(err, toast)) {
        navigate(`/trainings/${id}`, { replace: true });
        return;
      }
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }


  if (loading) {
    return (
      <div className="flex flex-col gap-4">
        <Link to={`/trainings/${id}`} className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <div className="flex justify-center py-10 text-ink-400"><Spinner /></div>
      </div>
    );
  }

  if (error && !training) {
    return (
      <div className="flex flex-col gap-4">
        <Link to={`/trainings/${id}`} className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }

  const noMembers = members !== null && members.length === 0;

  return (
    <div className="flex flex-col gap-4">
      <Link to={`/trainings/${id}`} className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť
      </Link>

      <header>
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Upraviť školenie</p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
          {training ? TRAINING_TYPE_LABELS[training.type] : ''}
        </h1>
      </header>

      {training && (
        <Card className="flex items-center gap-3 px-4 py-3 bg-ink-50/60">
          <Building2 className="size-4 shrink-0 text-ink-400" />
          <span className="text-sm text-ink-600">{training.company_name}</span>
          {training.facility_name && (
            <>
              <span className="text-ink-300">·</span>
              <Warehouse className="size-4 shrink-0 text-ink-400" />
              <span className="text-sm text-ink-600">{training.facility_name}</span>
            </>
          )}
        </Card>
      )}

      <Card className="p-5">
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field
            label="Dátum školenia"
            required
            hint={dateError ? undefined : 'Zadaj manuálne, nemusí byť dnešný dátum.'}
            error={dateError}
          >
            {(p) => (
              <Input {...p} required type="date"
                leftIcon={<CalendarDays className="size-4" />}
                value={date} onChange={(e) => { setDate(e.target.value); if (dateError) setDateError(null); }} />
            )}
          </Field>

          <Field
            label="Školiteľ"
            hint={
              noMembers
                ? 'V tíme zatiaľ nie je žiadny aktívny technik.'
                : 'Vyber z členov tímu. Podpis a oprávnenie sa berú z jeho profilu.'
            }
          >
            {(p) => (
              <Select
                id={p.id}
                value={trainerId !== null ? String(trainerId) : ''}
                onChange={(v) => setTrainerId(v ? Number(v) : null)}
                disabled={members === null || noMembers}
                placeholder="— bez školiteľa —"
                leftIcon={<User className="size-4" />}
                options={[
                  { value: '', label: '— bez školiteľa —' },
                  ...(members ?? []).map((m) => ({
                    value: String(m.id),
                    label: m.fullname,
                    description: m.email,
                  })),
                ]}
              />
            )}
          </Field>


          {error && !dateError && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </div>
          )}

          <div className="flex justify-end pt-1">
            <Button type="submit" loading={submitting} leftIcon={<Save className="size-4" />}>
              Uložiť zmeny
            </Button>
          </div>
        </form>

      </Card>
    </div>
  );
}
