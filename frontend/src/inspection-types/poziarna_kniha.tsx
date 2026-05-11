import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, BookOpen, CheckCircle2, Edit2, ListChecks,
  NotebookPen, Save, Trash2,
} from 'lucide-react';
import {
  Inspections,
  PK_ACTIVITIES,
  PK_ACTIVITY_LABELS,
  PK_RESULT_LABELS,
  type PkActivity,
  type PkResult,
  type PoziarnaKnihaItemFields,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';
import type {
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

function isPkActivity(s: unknown): s is PkActivity {
  return typeof s === 'string' && (PK_ACTIVITIES as string[]).includes(s);
}
function isPkResult(s: unknown): s is PkResult {
  return s === 'bez_nedostatkov' || s === 'zistene_nedostatky';
}

function PkStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [workspaces, setWorkspaces] = useState('');
  const [activities, setActivities] = useState<PkActivity[]>([]);
  const [activitiesOther, setActivitiesOther] = useState('');
  const [result, setResult] = useState<PkResult>('bez_nedostatkov');
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<PoziarnaKnihaItemFields>;
      setWorkspaces(typeof f.workspaces === 'string' ? f.workspaces : '');
      setActivities(Array.isArray(f.activities) ? f.activities.filter(isPkActivity) : []);
      setActivitiesOther(typeof f.activities_other === 'string' ? f.activities_other : '');
      setResult(isPkResult(f.result) ? f.result : 'bez_nedostatkov');
      setNotes(typeof f.notes === 'string' ? f.notes : '');
    } else {
      setWorkspaces('');
      setActivities([]);
      setActivitiesOther('');
      setResult('bez_nedostatkov');
      setNotes('');
    }
  }, [initialItem]);

  function toggleActivity(a: PkActivity) {
    setActivities((prev) => prev.includes(a) ? prev.filter((x) => x !== a) : [...prev, a]);
  }

  function localValidationError(): string | null {
    if (!workspaces.trim()) return 'Doplň prehliadnuté pracoviská.';
    if (activities.length === 0 && !activitiesOther.trim()) {
      return 'Vyber aspoň jednu vykonanú činnosť alebo doplň vlastnú v poli „iné".';
    }
    return null;
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const localErr = localValidationError();
    if (localErr) {
      setError(localErr);
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      const fields: PoziarnaKnihaItemFields = {
        workspaces: workspaces.trim(),
        activities,
        activities_other: activitiesOther.trim() || null,
        result,
        notes: notes.trim() || null,
      };
      if (editing && itemId !== null) {
        await Inspections.updateItem(inspectionId, itemId, fields, csrfToken);
      } else {
        await Inspections.addItem(inspectionId, fields, csrfToken);
      }
      // Požiarna kniha is a single-record protocol, so always go straight
      // to the summary instead of offering "save and next".
      onSaved('save-and-summary');
      toast.success('Záznam uložený');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={handleSubmit}>
        <Field label="Prehliadnuté pracoviská" required hint="Stručný zoznam priestorov / pracovísk, ktoré boli prehliadnuté.">
          {(p) => (
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                <BookOpen className="size-4" />
              </span>
              <textarea id={p.id} required rows={2} value={workspaces}
                onChange={(e) => setWorkspaces(e.target.value)}
                placeholder="Hala A, kancelárie 1.NP, sklad chemikálií, kotolňa."
                className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
            </div>
          )}
        </Field>

        <Field label="Vykonané činnosti" hint='Zaškrtni, čo bolo vykonané pri prehliadke. Možnosť „iné" je voľný text.'>
          {() => (
            <div className="flex flex-col gap-1.5">
              {PK_ACTIVITIES.map((a) => (
                <ActivityCheckbox
                  key={a}
                  label={PK_ACTIVITY_LABELS[a]}
                  active={activities.includes(a)}
                  onClick={() => toggleActivity(a)}
                />
              ))}
              <div className="mt-2 grid gap-1.5">
                <span className="text-[11px] uppercase tracking-wide text-ink-400">Iné (voľný text)</span>
                <Input
                  value={activitiesOther}
                  onChange={(e) => setActivitiesOther(e.target.value)}
                  placeholder='Napr. „Kontrola stavu únikového východu na 3.NP".'
                />
              </div>
            </div>
          )}
        </Field>

        <Field label="Výsledok" required>
          {() => (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Výsledok záznamu">
              <ResultButton value="bez_nedostatkov" active={result === 'bez_nedostatkov'} onClick={() => setResult('bez_nedostatkov')} />
              <ResultButton value="zistene_nedostatky" active={result === 'zistene_nedostatky'} onClick={() => setResult('zistene_nedostatky')} />
            </div>
          )}
        </Field>

        <Field label="Poznámky" hint="Voliteľné — popis nálezov a navrhované opatrenia.">
          {(p) => (
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                <NotebookPen className="size-4" />
              </span>
              <textarea id={p.id} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)}
                placeholder="Popis konkrétnych nálezov a navrhovaných opatrení."
                className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
            </div>
          )}
        </Field>

        {error && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </div>
        )}

        <div className="flex justify-end pt-1">
          <Button type="submit" loading={submitting}
            rightIcon={editing ? <Save className="size-4" /> : <ArrowRight className="size-4" />}
            leftIcon={editing ? undefined : <ListChecks className="size-4" />}>
            {editing ? 'Uložiť zmeny' : 'Uložiť záznam a pokračovať'}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function ActivityCheckbox({
  label,
  active,
  onClick,
}: {
  label: string;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button type="button" role="checkbox" aria-checked={active} onClick={onClick}
      className={cn('flex items-start gap-2 rounded-xl border px-3 py-2 text-left text-sm transition-colors',
        active
          ? 'border-firol-500 bg-firol-50 text-firol-800'
          : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300')}>
      <span className={cn(
        'mt-0.5 grid size-4 shrink-0 place-items-center rounded border',
        active ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
      )}>
        {active && <CheckCircle2 className="size-3" strokeWidth={3} />}
      </span>
      <span className="flex-1">{label}</span>
    </button>
  );
}

function ResultButton({
  value,
  active,
  onClick,
}: {
  value: PkResult;
  active: boolean;
  onClick: () => void;
}) {
  const tone = value === 'bez_nedostatkov'
    ? { active: 'border-status-ok bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
        idle:   'border-ink-200 hover:border-status-ok' }
    : { active: 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
        idle:   'border-ink-200 hover:border-status-bad' };
  return (
    <button type="button" role="radio" aria-checked={active} onClick={onClick}
      className={cn('rounded-xl border px-4 py-3 text-sm font-medium transition-colors text-left',
        active ? tone.active : `bg-white text-ink-700 ${tone.idle}`)}>
      <div className="flex items-center gap-1.5">
        {active && <CheckCircle2 className="size-3.5" />}
        <span className="font-semibold">{PK_RESULT_LABELS[value]}</span>
      </div>
    </button>
  );
}

function PkItemRow({
  inspectionId,
  index,
  item,
  canEdit,
  deleting,
  onDelete,
}: ItemRowProps) {
  const f = item.fields as Partial<PoziarnaKnihaItemFields>;
  const result = isPkResult(f.result) ? f.result : null;
  const checkedActivities = Array.isArray(f.activities)
    ? f.activities.filter(isPkActivity)
    : [];
  const totalActivities = checkedActivities.length + (f.activities_other ? 1 : 0);

  return (
    <Card className="px-4 py-3">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700 text-sm font-semibold">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="truncate text-sm font-semibold text-ink-900">
              <BookOpen className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
              {f.workspaces}
            </h3>
            {result && (
              <Badge tone={result === 'bez_nedostatkov' ? 'ok' : 'bad'}>
                {PK_RESULT_LABELS[result]}
              </Badge>
            )}
          </div>
          <p className="mt-0.5 text-xs text-ink-500">
            {totalActivities} {totalActivities === 1 ? 'činnosť' : 'činností'} zaznamenaných
          </p>
          {f.notes && (
            <p className="mt-1 line-clamp-2 text-xs text-ink-600">
              <AlertTriangle className="-mt-0.5 mr-1 inline size-3 text-status-warn" />
              {f.notes}
            </p>
          )}
        </div>
        {canEdit && (
          <div className="flex shrink-0 items-center gap-1">
            <Link to={`/inspections/${inspectionId}/items/${item.id}`} aria-label="Opraviť"
              className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700">
              <Edit2 className="size-4" />
            </Link>
            <button type="button" onClick={onDelete} disabled={deleting} aria-label="Zmazať"
              className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50">
              {deleting ? <Spinner size="sm" /> : <Trash2 className="size-4" />}
            </button>
          </div>
        )}
      </div>
    </Card>
  );
}

function PkStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  const f = items[0].fields as Partial<PoziarnaKnihaItemFields>;
  const result = isPkResult(f.result) ? f.result : 'bez_nedostatkov';
  const tone = result === 'bez_nedostatkov'
    ? 'bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]'
    : 'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]';
  return (
    <Card className={cn('px-4 py-3', tone)}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-semibold uppercase tracking-wider opacity-80">Výsledok</span>
        <span className="text-base font-semibold">{PK_RESULT_LABELS[result]}</span>
      </div>
    </Card>
  );
}

export const poziarnaKnihaModule: InspectionTypeModule = {
  type: 'poziarna_kniha',
  Step2Form: PkStep2Form,
  ItemRow: PkItemRow,
  StatsBar: PkStatsBar,
};
