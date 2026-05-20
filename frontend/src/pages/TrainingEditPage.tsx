import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft, Building2, CalendarDays, Clock, NotebookPen, Save, User, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Trainers, type Trainer } from '@/api/trainers';
import {
  TRAINING_TYPE_LABELS,
  Trainings,
  type Training,
} from '@/api/trainings';
import { ApiError } from '@/lib/api';
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
  const [trainers, setTrainers] = useState<Trainer[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [date, setDate] = useState('');
  const [trainerId, setTrainerId] = useState<number | null>(null);
  const [topics, setTopics] = useState('');
  const [durationMin, setDurationMin] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [dateError, setDateError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([Trainings.show(id), Trainers.list()])
      .then(([detail, trs]) => {
        if (cancelled) return;
        const t = detail.training;
        setTraining(t);
        setTrainers(trs.items);
        setDate(t.date ?? '');
        setTrainerId(t.trainer_id);
        setTopics(t.topics ?? '');
        setDurationMin(t.duration_min !== null ? String(t.duration_min) : '');
        setLoading(false);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať školenie.');
        setLoading(false);
      });
    return () => { cancelled = true; };
  }, [id]);

  const trainerWarning = useMemo(() => {
    if (trainerId === null || !trainers) return null;
    const t = trainers.find((x) => x.id === trainerId);
    if (!t || t.has_signature) return null;
    return `Školiteľ „${t.fullname}" zatiaľ nemá nahraný podpis. Bez neho sa nedá vystaviť PDF protokol.`;
  }, [trainerId, trainers]);

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
        topics: topics.trim() || null,
        duration_min: durationMin ? Number(durationMin) : null,
      }, csrfToken);
      toast.success('Školenie uložené');
      navigate(`/trainings/${id}`, { replace: true });
    } catch (err) {
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

  const noTrainers = trainers !== null && trainers.length === 0;

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
              noTrainers
                ? 'Nemáš ešte nikoho v zozname školiteľov.'
                : trainerWarning ?? 'Vyber zo zoznamu školiteľov.'
            }
            error={trainerWarning ?? undefined}
          >
            {(p) => (
              <Select
                id={p.id}
                value={trainerId !== null ? String(trainerId) : ''}
                onChange={(v) => setTrainerId(v ? Number(v) : null)}
                disabled={trainers === null || noTrainers}
                placeholder="— bez školiteľa —"
                leftIcon={<User className="size-4" />}
                options={[
                  { value: '', label: '— bez školiteľa —' },
                  ...(trainers ?? []).map((t) => ({
                    value: String(t.id),
                    label: t.fullname,
                    description: t.certification_number ?? undefined,
                  })),
                ]}
              />
            )}
          </Field>

          <div className="grid gap-4 sm:grid-cols-3">
            <div className="sm:col-span-2">
              <Field label="Témy školenia" hint="Stručný zoznam preberaných tém — pôjde do PDF protokolu.">
                {(p) => (
                  <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                      <NotebookPen className="size-4" />
                    </span>
                    <textarea id={p.id} rows={3} value={topics}
                      onChange={(e) => setTopics(e.target.value)}
                      placeholder="OPP osnova, evakuácia, RPHP — zaobchádzanie, prvá pomoc."
                      className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
                  </div>
                )}
              </Field>
            </div>
            <Field label="Dĺžka (min)" hint="Voliteľné">
              {(p) => (
                <Input {...p} type="number" inputMode="numeric" min={0} max={1440}
                  leftIcon={<Clock className="size-4" />}
                  value={durationMin} onChange={(e) => setDurationMin(e.target.value)}
                  placeholder="120" />
              )}
            </Field>
          </div>

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
