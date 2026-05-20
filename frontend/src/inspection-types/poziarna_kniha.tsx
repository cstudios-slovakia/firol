import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, BookOpen, Calendar, Check, CheckCircle2, Edit2, ListChecks,
  NotebookPen, Plus, Save, Trash2, X,
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
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Spinner } from '@/components/ui/Spinner';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';
import type {
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

type CustomActivity = { id: number; label: string; checked: boolean };

function isPkActivity(s: unknown): s is PkActivity {
  return typeof s === 'string' && (PK_ACTIVITIES as string[]).includes(s);
}
function isPkResult(s: unknown): s is PkResult {
  return s === 'bez_nedostatkov' || s === 'zistene_nedostatky';
}

let _nextId = 1;
function nextId() { return _nextId++; }

function PkStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [workspaces, setWorkspaces] = useState('');
  const [activities, setActivities] = useState<PkActivity[]>([]);
  const [customActivities, setCustomActivities] = useState<CustomActivity[]>([]);
  const [result, setResult] = useState<PkResult>('bez_nedostatkov');
  const [defectDeadline, setDefectDeadline] = useState('');
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [workspacesError, setWorkspacesError] = useState<string | null>(null);
  const [activitiesError, setActivitiesError] = useState<string | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();
  const lastInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<PoziarnaKnihaItemFields>;
      setWorkspaces(typeof f.workspaces === 'string' ? f.workspaces : '');
      setActivities(Array.isArray(f.activities) ? f.activities.filter(isPkActivity) : []);
      // Load custom activities — backward compat: old records used activities_other (string)
      const legacy = typeof (f as Record<string, unknown>)['activities_other'] === 'string'
        ? (f as Record<string, unknown>)['activities_other'] as string
        : null;
      const fromCustom = Array.isArray(f.custom_activities)
        ? (f.custom_activities as string[]).map((label) => ({ id: nextId(), label, checked: true }))
        : legacy
          ? [{ id: nextId(), label: legacy, checked: true }]
          : [];
      setCustomActivities(fromCustom);
      setResult(isPkResult(f.result) ? f.result : 'bez_nedostatkov');
      setDefectDeadline(typeof f.defect_deadline === 'string' ? f.defect_deadline : '');
      setNotes(typeof f.notes === 'string' ? f.notes : '');
    } else {
      setWorkspaces('');
      setActivities([]);
      setCustomActivities([]);
      setResult('bez_nedostatkov');
      setDefectDeadline('');
      setNotes('');
    }
  }, [initialItem]);

  function toggleActivity(a: PkActivity) {
    setActivities((prev) => prev.includes(a) ? prev.filter((x) => x !== a) : [...prev, a]);
    if (activitiesError) setActivitiesError(null);
  }

  function addCustomActivity() {
    const id = nextId();
    setCustomActivities((prev) => [...prev, { id, label: '', checked: true }]);
    if (activitiesError) setActivitiesError(null);
    // Focus the new input after render
    requestAnimationFrame(() => lastInputRef.current?.focus());
  }

  function removeCustomActivity(id: number) {
    setCustomActivities((prev) => prev.filter((x) => x.id !== id));
  }

  function updateCustomActivity(id: number, changes: Partial<Pick<CustomActivity, 'label' | 'checked'>>) {
    setCustomActivities((prev) => prev.map((x) => x.id === id ? { ...x, ...changes } : x));
    if (activitiesError) setActivitiesError(null);
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    let hasError = false;
    if (!workspaces.trim()) { setWorkspacesError('Doplň prehliadnuté pracoviská.'); hasError = true; }
    const hasCustomChecked = customActivities.some((x) => x.checked && x.label.trim());
    if (activities.length === 0 && !hasCustomChecked) {
      setActivitiesError('Vyber aspoň jednu vykonanú činnosť alebo pridaj vlastnú pomocou tlačidla +.');
      hasError = true;
    }
    if (hasError) return;
    setWorkspacesError(null);
    setActivitiesError(null);
    setApiError(null);
    setSubmitting(true);
    try {
      const fields: PoziarnaKnihaItemFields = {
        workspaces: workspaces.trim(),
        activities,
        custom_activities: customActivities
          .filter((x) => x.checked && x.label.trim())
          .map((x) => x.label.trim()),
        result,
        defect_deadline: result === 'zistene_nedostatky' ? (defectDeadline || null) : null,
        notes: notes.trim() || null,
      };
      if (editing && itemId !== null) {
        await Inspections.updateItem(inspectionId, itemId, fields, csrfToken);
      } else {
        await Inspections.addItem(inspectionId, fields, csrfToken);
      }
      onSaved('save-and-summary');
      toast.success('Záznam uložený');
    } catch (err) {
      if (handleOfflineSave(err, toast)) {
        onSaved('save-and-summary');
        return;
      }
      setApiError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={handleSubmit}>
        <Field
          label="Prehliadnuté pracoviská"
          required
          hint={workspacesError ? undefined : 'Stručný zoznam priestorov / pracovísk, ktoré boli prehliadnuté.'}
          error={workspacesError}
        >
          {(p) => (
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                <BookOpen className="size-4" />
              </span>
              <textarea id={p.id} required rows={2} value={workspaces}
                onChange={(e) => { setWorkspaces(e.target.value); if (workspacesError) setWorkspacesError(null); }}
                placeholder="Hala A, kancelárie 1.NP, sklad chemikálií, kotolňa."
                className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
            </div>
          )}
        </Field>

        <Field
          label="Vykonané činnosti"
          hint={activitiesError ? undefined : 'Zaškrtni, čo bolo vykonané. Pridaj vlastné pomocou tlačidla +.'}
          error={activitiesError}
        >
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

              {customActivities.map(({ id, label, checked }, idx) => {
                const isLast = idx === customActivities.length - 1;
                return (
                  <div key={id}
                    className={cn(
                      'flex items-center gap-2 rounded-xl border px-3 py-2 transition-colors',
                      checked ? 'border-firol-400 bg-firol-50' : 'border-ink-200 bg-white',
                    )}>
                    <button
                      type="button"
                      role="checkbox"
                      aria-checked={checked}
                      onClick={() => updateCustomActivity(id, { checked: !checked })}
                      className="shrink-0"
                    >
                      <span className={cn(
                        'grid size-4 place-items-center rounded-sm border transition-colors',
                        checked ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
                      )}>
                        {checked && <Check className="size-3.5" strokeWidth={3} />}
                      </span>
                    </button>
                    <input
                      ref={isLast ? lastInputRef : undefined}
                      type="text"
                      value={label}
                      onChange={(e) => updateCustomActivity(id, { label: e.target.value })}
                      placeholder="Popis vlastnej činnosti..."
                      className="min-w-0 flex-1 bg-transparent text-sm text-ink-800 placeholder:text-ink-400 outline-none"
                    />
                    <button
                      type="button"
                      onClick={() => removeCustomActivity(id)}
                      aria-label="Odstrániť"
                      className="grid size-6 shrink-0 place-items-center rounded-lg text-ink-400 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad"
                    >
                      <X className="size-3.5" />
                    </button>
                  </div>
                );
              })}

              <button
                type="button"
                onClick={addCustomActivity}
                className="flex items-center gap-1.5 rounded-xl border border-dashed border-ink-300 px-3 py-2 text-sm text-ink-500 transition-colors hover:border-firol-400 hover:text-firol-600"
              >
                <Plus className="size-4" />
                Pridať vlastnú činnosť
              </button>
            </div>
          )}
        </Field>

        <Field label="Výsledok" required>
          {() => (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Výsledok záznamu">
              <ResultButton value="bez_nedostatkov" active={result === 'bez_nedostatkov'} onClick={() => { setResult('bez_nedostatkov'); setDefectDeadline(''); }} />
              <ResultButton value="zistene_nedostatky" active={result === 'zistene_nedostatky'} onClick={() => setResult('zistene_nedostatky')} />
            </div>
          )}
        </Field>

        {result === 'zistene_nedostatky' && (
          <Field label="Termín na odstránenie nedostatkov" hint="Dátum, do ktorého musia byť nedostatky odstránené.">
            {(p) => (
              <Input {...p} type="date" leftIcon={<Calendar className="size-4" />}
                value={defectDeadline} onChange={(e) => setDefectDeadline(e.target.value)} />
            )}
          </Field>
        )}

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

        {apiError && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {apiError}
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
        'mt-0.5 grid size-4 shrink-0 place-items-center rounded-sm border',
        active ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
      )}>
        {active && <Check className="size-3.5" strokeWidth={3} />}
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
  const customCount = Array.isArray(f.custom_activities) ? f.custom_activities.length : 0;
  const totalActivities = checkedActivities.length + customCount;

  return (
    <Card className="px-4 py-3">
      <div className="flex items-center gap-3">
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
          {f.defect_deadline && (
            <p className="mt-0.5 text-xs text-ink-600">
              <Calendar className="-mt-0.5 mr-1 inline size-3 text-status-warn" />
              Termín: {f.defect_deadline}
            </p>
          )}
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
              className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]">
              <Edit2 className="size-4" />
            </Link>
            <button type="button" onClick={onDelete} disabled={deleting} aria-label="Zmazať"
              className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)] disabled:opacity-50">
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
