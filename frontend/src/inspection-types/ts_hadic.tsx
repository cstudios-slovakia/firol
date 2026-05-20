import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, Building2, CheckCircle2, Edit2, Gauge,
  ListChecks, MapPin, NotebookPen, Ruler, Save, Trash2,
} from 'lucide-react';
import {
  Inspections,
  type PassFailResult,
  type TsHadicItemFields,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { handleOfflineSave } from '@/lib/offline';
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

const TS_HADIC_RESULT_LABELS: Record<PassFailResult, string> = {
  vyhovuje:    'Funkčná',
  nevyhovuje:  'Nefunkčná',
};

function isPassFail(s: unknown): s is PassFailResult {
  return s === 'vyhovuje' || s === 'nevyhovuje';
}

function TsHadicStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [hoseType, setHoseType]               = useState('');
  const [location, setLocation]               = useState('');
  const [manufacturer, setManufacturer]       = useState('');
  const [workingPressure, setWorkingPressure] = useState('');
  const [testPressure, setTestPressure]       = useState('');
  const [length, setLength]                   = useState('');
  const [yearOfManufacture, setYearOfManufacture] = useState('');
  const [result, setResult]                   = useState<PassFailResult>('vyhovuje');
  const [notes, setNotes]                     = useState('');

  const [submitting, setSubmitting]   = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [apiError, setApiError]       = useState<string | null>(null);
  const toast = useToast();

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<TsHadicItemFields>;
      setHoseType(typeof f.hose_type === 'string' ? f.hose_type : '');
      setLocation(typeof f.location === 'string' ? f.location : '');
      setManufacturer(typeof f.manufacturer === 'string' ? f.manufacturer : '');
      setWorkingPressure(typeof f.working_pressure === 'number' ? String(f.working_pressure) : '');
      setTestPressure(typeof f.test_pressure === 'number' ? String(f.test_pressure) : '');
      setLength(typeof f.length === 'number' ? String(f.length) : '');
      setYearOfManufacture(typeof f.year_of_manufacture === 'number' ? String(f.year_of_manufacture) : '');
      setResult(isPassFail(f.result) ? f.result : 'vyhovuje');
      setNotes(typeof f.notes === 'string' ? f.notes : '');
    } else {
      setHoseType(''); setLocation(''); setManufacturer('');
      setWorkingPressure(''); setTestPressure('');
      setLength(''); setYearOfManufacture('');
      setResult('vyhovuje'); setNotes('');
    }
  }, [initialItem]);

  function clearErr(key: string) {
    setFieldErrors((prev) => { const n = { ...prev }; delete n[key]; return n; });
  }

  function isPristine() {
    return !hoseType && !location && !manufacturer && !workingPressure &&
      !testPressure && !length && !yearOfManufacture && !notes &&
      result === 'vyhovuje';
  }

  function handleGoToSummary(e: React.SyntheticEvent) {
    e.preventDefault();
    if (isPristine()) { onSaved('save-and-summary'); return; }
    void handleSubmit(e as FormEvent, 'save-and-summary');
  }

  async function handleSubmit(e: FormEvent, action: 'save-and-next' | 'save-and-summary') {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (!hoseType.trim()) errs.hoseType = 'Doplň typ / priemer hadice (napr. DN33, C52).';
    if (!location.trim()) errs.location = 'Doplň umiestnenie.';
    if (!manufacturer.trim()) errs.manufacturer = 'Doplň výrobcu.';
    const wp = Number(workingPressure);
    if (!workingPressure || !Number.isFinite(wp) || wp < 0 || wp > 50)
      errs.workingPressure = 'Pracovný pretlak musí byť číslo v rozsahu 0–50 MPa.';
    const tp = Number(testPressure);
    if (!testPressure || !Number.isFinite(tp) || tp < 0 || tp > 50)
      errs.testPressure = 'Skúšobný pretlak musí byť číslo v rozsahu 0–50 MPa.';
    const len = Number(length);
    if (!length || !Number.isFinite(len) || len <= 0 || len > 9999)
      errs.length = 'Dĺžka musí byť kladné číslo (v metroch).';
    const year = Number(yearOfManufacture);
    if (!yearOfManufacture || !Number.isInteger(year) || year < 1900 || year > new Date().getFullYear())
      errs.yearOfManufacture = 'Zadaj platný rok výroby.';
    if (Object.keys(errs).length > 0) { setFieldErrors(errs); return; }
    setFieldErrors({});
    setApiError(null);
    setSubmitting(true);
    try {
      const fields: TsHadicItemFields = {
        hose_type:           hoseType.trim(),
        location:            location.trim(),
        manufacturer:        manufacturer.trim(),
        working_pressure:    wp,
        test_pressure:       tp,
        length:              len,
        year_of_manufacture: year,
        result,
        notes: notes.trim() || null,
      };
      if (editing && itemId !== null) {
        await Inspections.updateItem(inspectionId, itemId, fields, csrfToken);
      } else {
        await Inspections.addItem(inspectionId, fields, csrfToken);
      }
      onSaved(action);
      toast.success('Položka uložená');
    } catch (err) {
      if (handleOfflineSave(err, toast)) { onSaved(action); return; }
      setApiError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={(e) => handleSubmit(e, 'save-and-next')}>

        <Field label="Typ / priemer hadice" required hint={fieldErrors.hoseType ? undefined : 'Napr. DN33, C52, D25, B65.'} error={fieldErrors.hoseType}>
          {(p) => (
            <Input {...p} required leftIcon={<Gauge className="size-4" />}
              value={hoseType} onChange={(e) => { setHoseType(e.target.value); clearErr('hoseType'); }}
              placeholder="DN33" />
          )}
        </Field>

        <Field label="Umiestnenie" required error={fieldErrors.location}>
          {(p) => (
            <Input {...p} required leftIcon={<MapPin className="size-4" />}
              value={location} onChange={(e) => { setLocation(e.target.value); clearErr('location'); }}
              placeholder="Chodba — 2. poschodie" />
          )}
        </Field>

        <Field label="Výrobca" required error={fieldErrors.manufacturer}>
          {(p) => (
            <Input {...p} required leftIcon={<Building2 className="size-4" />}
              value={manufacturer} onChange={(e) => { setManufacturer(e.target.value); clearErr('manufacturer'); }}
              placeholder="PYROSTOP s.r.o." />
          )}
        </Field>

        <div className="grid grid-cols-2 gap-4">
          <Field label="Pracovný pretlak" required error={fieldErrors.workingPressure}>
            {(p) => (
              <Input {...p} required type="number" inputMode="decimal" step="0.1" min={0} max={50}
                leftIcon={<Gauge className="size-4" />} suffix="MPa"
                value={workingPressure} onChange={(e) => { setWorkingPressure(e.target.value); clearErr('workingPressure'); }}
                placeholder="1,2" />
            )}
          </Field>
          <Field label="Skúšobný pretlak" required error={fieldErrors.testPressure}>
            {(p) => (
              <Input {...p} required type="number" inputMode="decimal" step="0.1" min={0} max={50}
                leftIcon={<Gauge className="size-4" />} suffix="MPa"
                value={testPressure} onChange={(e) => { setTestPressure(e.target.value); clearErr('testPressure'); }}
                placeholder="1,8" />
            )}
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <Field label="Dĺžka" required error={fieldErrors.length}>
            {(p) => (
              <Input {...p} required type="number" inputMode="decimal" step="0.5" min={0.5} max={9999}
                leftIcon={<Ruler className="size-4" />} suffix="m"
                value={length} onChange={(e) => { setLength(e.target.value); clearErr('length'); }}
                placeholder="20" />
            )}
          </Field>
          <Field label="Rok výroby" required error={fieldErrors.yearOfManufacture}>
            {(p) => (
              <Input {...p} required type="number" inputMode="numeric" step={1} min={1900} max={new Date().getFullYear()}
                leftIcon={<Ruler className="size-4" />}
                value={yearOfManufacture} onChange={(e) => { setYearOfManufacture(e.target.value); clearErr('yearOfManufacture'); }}
                placeholder="2021" />
            )}
          </Field>
        </div>

        <Field label="Výsledok skúšky" required>
          {() => (
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Výsledok skúšky">
              {(['vyhovuje', 'nevyhovuje'] as const).map((r) => (
                <ResultButton key={r} value={r} active={result === r} onClick={() => setResult(r)} />
              ))}
            </div>
          )}
        </Field>

        <Field label="Poznámky / závady" hint="Voliteľné — len ak sa niečo zistí.">
          {(p) => (
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                <NotebookPen className="size-4" />
              </span>
              <textarea id={p.id} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)}
                placeholder="Mikrotrhlinky pri spojke, výmena celej hadice odporúčaná."
                className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
            </div>
          )}
        </Field>

        {apiError && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {apiError}
          </div>
        )}
        {Object.keys(fieldErrors).length > 0 && (
          <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            Formulár obsahuje nevyplnené povinné polia.
          </p>
        )}

        <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
          <Button type="button" variant="secondary" onClick={handleGoToSummary}
            loading={submitting} leftIcon={<ListChecks className="size-4" />}>
            Uložiť a prejsť na súhrn
          </Button>
          <Button type="submit" loading={submitting}
            rightIcon={editing ? <Save className="size-4" /> : <ArrowRight className="size-4" />}>
            {editing ? 'Uložiť zmeny a ďalší' : 'Uložiť a ďalšia hadica'}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function ResultButton({
  value,
  active,
  onClick,
}: {
  value: PassFailResult;
  active: boolean;
  onClick: () => void;
}) {
  const tone = value === 'vyhovuje'
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
        <span className="font-semibold">{TS_HADIC_RESULT_LABELS[value]}</span>
      </div>
    </button>
  );
}

function TsHadicItemRow({
  inspectionId,
  index,
  item,
  canEdit,
  deleting,
  onDelete,
}: ItemRowProps) {
  const f = item.fields as Partial<TsHadicItemFields>;
  const result = isPassFail(f.result) ? f.result : null;

  const fmtPressure = (n: unknown): string =>
    typeof n === 'number' && Number.isFinite(n)
      ? n.toLocaleString('sk-SK', { minimumFractionDigits: 1, maximumFractionDigits: 2 })
      : '—';

  return (
    <div className="px-4 py-3">
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700 text-sm font-semibold">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="truncate text-sm font-semibold text-ink-900">
              <Gauge className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
              {f.hose_type ?? '—'}
            </h3>
            {result && (
              <Badge tone={result === 'vyhovuje' ? 'ok' : 'bad'}>
                {TS_HADIC_RESULT_LABELS[result]}
              </Badge>
            )}
          </div>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            <MapPin className="-mt-0.5 mr-1 inline size-3" />
            {f.location ?? '—'}
          </p>
          <p className="mt-0.5 text-xs text-ink-600">
            {f.manufacturer && (
              <span className="mr-3">{f.manufacturer}</span>
            )}
            <span className="font-mono">
              {fmtPressure(f.working_pressure)} / {fmtPressure(f.test_pressure)}{' '}
              <span className="text-ink-400">MPa</span>
            </span>
            {typeof f.length === 'number' && (
              <span className="ml-2 font-mono">{f.length} <span className="text-ink-400">m</span></span>
            )}
            {typeof f.year_of_manufacture === 'number' && (
              <span className="ml-2 text-ink-400">r. {f.year_of_manufacture}</span>
            )}
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
    </div>
  );
}

function TsHadicStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  let pass = 0;
  let fail = 0;
  for (const it of items) {
    const r = (it.fields as Partial<TsHadicItemFields>).result;
    if (r === 'vyhovuje') pass++;
    else if (r === 'nevyhovuje') fail++;
  }
  return (
    <Card className="px-4 py-3">
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold uppercase tracking-wider text-ink-500">Štatistika</span>
        <span className="text-ink-500">spolu {items.length}</span>
      </div>
      <div className="mt-2 grid grid-cols-2 gap-2">
        <div className="flex items-center justify-between gap-2 rounded-xl bg-[var(--color-status-ok-bg)] px-3 py-2 text-sm text-[var(--color-status-ok)]">
          <span className="text-xs">Funkčné</span>
          <span className="text-base font-semibold tabular-nums">{pass}</span>
        </div>
        <div className="flex items-center justify-between gap-2 rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          <span className="text-xs">Nefunkčné</span>
          <span className="text-base font-semibold tabular-nums">{fail}</span>
        </div>
      </div>
    </Card>
  );
}

export const tsHadicModule: InspectionTypeModule = {
  type: 'ts_hadic',
  Step2Form: TsHadicStep2Form,
  ItemRow: TsHadicItemRow,
  StatsBar: TsHadicStatsBar,
};
