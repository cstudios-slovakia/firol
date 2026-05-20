import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, CheckCircle2, Droplets, Edit2, Gauge, Hash,
  ListChecks, MapPin, NotebookPen, Save, Trash2,
} from 'lucide-react';
import {
  HYDRANT_TYPES,
  Inspections,
  PASS_FAIL_LABELS,
  type HydrantItemFields,
  type HydrantTypeKind,
  type PassFailResult,
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

function isHydrantType(s: unknown): s is HydrantTypeKind {
  return (HYDRANT_TYPES as string[]).includes(s as string);
}
function isPassFail(s: unknown): s is PassFailResult {
  return s === 'vyhovuje' || s === 'nevyhovuje';
}

function HydrantyStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [hydrantType, setHydrantType] = useState<HydrantTypeKind>('DN52');
  const [typeOther, setTypeOther] = useState('');
  const [location, setLocation] = useState('');
  const [hoseCount, setHoseCount] = useState<string>('1');
  const [hs, setHs] = useState<string>('');
  const [hd, setHd] = useState<string>('');
  const [q, setQ] = useState<string>('');
  const [defects, setDefects] = useState('');
  const [result, setResult] = useState<PassFailResult>('vyhovuje');

  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<HydrantItemFields>;
      setHydrantType(isHydrantType(f.type) ? f.type : 'DN52');
      setTypeOther(typeof f.type_other === 'string' ? f.type_other : '');
      setLocation(typeof f.location === 'string' ? f.location : '');
      setHoseCount(typeof f.hose_count === 'number' ? String(f.hose_count) : '1');
      setHs(typeof f.hs === 'number' ? String(f.hs) : '');
      setHd(typeof f.hd === 'number' ? String(f.hd) : '');
      setQ(typeof f.q === 'number' ? String(f.q) : '');
      setDefects(typeof f.defects === 'string' ? f.defects : '');
      setResult(isPassFail(f.result) ? f.result : 'vyhovuje');
    } else {
      setHydrantType('DN52');
      setTypeOther('');
      setLocation('');
      setHoseCount('1');
      setHs('');
      setHd('');
      setQ('');
      setDefects('');
      setResult('vyhovuje');
    }
  }, [initialItem]);

  function isPristine() {
    return (
      hydrantType === 'DN52' && !typeOther && !location && hoseCount === '1' &&
      !hs && !hd && !q && !defects && result === 'vyhovuje'
    );
  }

  function handleGoToSummary(e: React.SyntheticEvent) {
    e.preventDefault();
    if (isPristine()) { onSaved('save-and-summary'); return; }
    void handleSubmit(e as FormEvent, 'save-and-summary');
  }

  async function handleSubmit(e: FormEvent, action: 'save-and-next' | 'save-and-summary') {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (hydrantType === 'other' && !typeOther.trim()) errs.typeOther = 'Doplň označenie typu (alebo vyber štandardný DN).';
    if (!location.trim()) errs.location = 'Doplň umiestnenie.';
    const hc = Number(hoseCount);
    if (!Number.isInteger(hc) || hc < 0 || hc > 20) errs.hoseCount = 'Počet hadíc musí byť 0–20.';
    const hsN = Number(hs);
    if (!Number.isFinite(hsN) || hsN < 0 || hsN > 50) errs.hs = 'Hodnota HS musí byť číslo v rozsahu 0–50.';
    const hdN = Number(hd);
    if (!Number.isFinite(hdN) || hdN < 0 || hdN > 50) errs.hd = 'Hodnota HD musí byť číslo v rozsahu 0–50.';
    const qN = Number(q);
    if (!Number.isFinite(qN) || qN < 0 || qN > 100) errs.q = 'Hodnota Q musí byť číslo v rozsahu 0–100.';
    if (Object.keys(errs).length > 0) { setFieldErrors(errs); return; }
    setFieldErrors({});
    setApiError(null);
    setSubmitting(true);
    try {
      const fields: HydrantItemFields = {
        type: hydrantType,
        type_other: hydrantType === 'other' ? typeOther.trim() : null,
        location: location.trim(),
        hose_count: Number(hoseCount),
        hs: Number(hs),
        hd: Number(hd),
        q: Number(q),
        defects: defects.trim() || null,
        result,
      };
      if (editing && itemId !== null) {
        await Inspections.updateItem(inspectionId, itemId, fields, csrfToken);
      } else {
        await Inspections.addItem(inspectionId, fields, csrfToken);
      }
      onSaved(action);
      toast.success('Položka uložená');
    } catch (err) {
      if (handleOfflineSave(err, toast)) {
        onSaved(action);
        return;
      }
      setApiError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={(e) => handleSubmit(e, 'save-and-next')}>
        <Field label="Typ hydrantu" required>
          {() => (
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-5" role="radiogroup" aria-label="Typ hydrantu">
              {HYDRANT_TYPES.map((t) => (
                <DnButton key={t} value={t} active={hydrantType === t} onClick={() => setHydrantType(t)} />
              ))}
            </div>
          )}
        </Field>

        {hydrantType === 'other' && (
          <Field label="Označenie typu" required hint={fieldErrors.typeOther ? undefined : 'Napr. DN40 alebo presný výrobcovský kód.'} error={fieldErrors.typeOther}>
            {(p) => (
              <Input {...p} required leftIcon={<Droplets className="size-4" />}
                value={typeOther} onChange={(e) => { setTypeOther(e.target.value); if (fieldErrors.typeOther) setFieldErrors((prev) => { const n = { ...prev }; delete n.typeOther; return n; }); }}
                placeholder="DN40 / DN65 / iné" />
            )}
          </Field>
        )}

        <Field label="Umiestnenie" required error={fieldErrors.location}>
          {(p) => (
            <Input {...p} required leftIcon={<MapPin className="size-4" />}
              value={location} onChange={(e) => { setLocation(e.target.value); if (fieldErrors.location) setFieldErrors((prev) => { const n = { ...prev }; delete n.location; return n; }); }}
              placeholder="Vchod hala A, druhé poschodie" />
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-4">
          <Field label="Počet hadíc" required error={fieldErrors.hoseCount}>
            {(p) => (
              <Input {...p} required type="number" inputMode="numeric" min={0} max={20}
                leftIcon={<Hash className="size-4" />}
                value={hoseCount} onChange={(e) => { setHoseCount(e.target.value); if (fieldErrors.hoseCount) setFieldErrors((prev) => { const n = { ...prev }; delete n.hoseCount; return n; }); }} />
            )}
          </Field>
          <Field label="HS" required hint={fieldErrors.hs ? undefined : 'Statický tlak'} error={fieldErrors.hs}>
            {(p) => (
              <Input {...p} required type="number" inputMode="decimal" step="0.01" min={0} max={50}
                leftIcon={<Gauge className="size-4" />} suffix="MPa"
                value={hs} onChange={(e) => { setHs(e.target.value); if (fieldErrors.hs) setFieldErrors((prev) => { const n = { ...prev }; delete n.hs; return n; }); }} placeholder="0,55" />
            )}
          </Field>
          <Field label="HD" required hint={fieldErrors.hd ? undefined : 'Dynamický tlak'} error={fieldErrors.hd}>
            {(p) => (
              <Input {...p} required type="number" inputMode="decimal" step="0.01" min={0} max={50}
                leftIcon={<Gauge className="size-4" />} suffix="MPa"
                value={hd} onChange={(e) => { setHd(e.target.value); if (fieldErrors.hd) setFieldErrors((prev) => { const n = { ...prev }; delete n.hd; return n; }); }} placeholder="0,42" />
            )}
          </Field>
          <Field label="Q" required hint={fieldErrors.q ? undefined : 'Prietok'} error={fieldErrors.q}>
            {(p) => (
              <Input {...p} required type="number" inputMode="decimal" step="0.01" min={0} max={100}
                leftIcon={<Gauge className="size-4" />} suffix="l/s"
                value={q} onChange={(e) => { setQ(e.target.value); if (fieldErrors.q) setFieldErrors((prev) => { const n = { ...prev }; delete n.q; return n; }); }} placeholder="1,8" />
            )}
          </Field>
        </div>

        <Field label="Zistené závady" hint="Voliteľné — nech zostane prázdne ak je hydrant v poriadku.">
          {(p) => (
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                <NotebookPen className="size-4" />
              </span>
              <textarea id={p.id} rows={3} value={defects} onChange={(e) => setDefects(e.target.value)}
                placeholder="Korózia spojky, popraskaná hadica, chýbajúca tabuľka."
                className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
            </div>
          )}
        </Field>

        <Field label="Výsledok kontroly" required>
          {() => (
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Výsledok kontroly">
              {(['vyhovuje', 'nevyhovuje'] as const).map((r) => (
                <ResultButton key={r} value={r} active={result === r} onClick={() => setResult(r)} />
              ))}
            </div>
          )}
        </Field>

        {apiError && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {apiError}
          </div>
        )}

        <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
          <Button type="button" variant="secondary" onClick={handleGoToSummary}
            loading={submitting} leftIcon={<ListChecks className="size-4" />}>
            Uložiť a prejsť na súhrn
          </Button>
          <Button type="submit" loading={submitting}
            rightIcon={editing ? <Save className="size-4" /> : <ArrowRight className="size-4" />}>
            {editing ? 'Uložiť zmeny a ďalší' : 'Uložiť a ďalší'}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function DnButton({
  value,
  active,
  onClick,
}: {
  value: HydrantTypeKind;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button type="button" role="radio" aria-checked={active} onClick={onClick}
      className={cn('rounded-xl border px-3 py-2.5 text-sm font-medium transition-colors',
        active
          ? 'border-firol-500 bg-firol-50 text-firol-700'
          : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300')}>
      <div className="flex items-center justify-center gap-1.5">
        {active && <CheckCircle2 className="size-3.5" />}
        <span>{value === 'other' ? 'Iný' : value}</span>
      </div>
    </button>
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
        <span className="font-semibold">{PASS_FAIL_LABELS[value]}</span>
      </div>
    </button>
  );
}

function HydrantyItemRow({
  inspectionId,
  index,
  item,
  canEdit,
  deleting,
  onDelete,
}: ItemRowProps) {
  const f = item.fields as Partial<HydrantItemFields>;
  const result = isPassFail(f.result) ? f.result : null;
  const typeLabel = f.type === 'other' ? (f.type_other || 'iný') : (f.type ?? '');
  const fmt = (n: unknown): string =>
    typeof n === 'number' && Number.isFinite(n)
      ? n.toLocaleString('sk-SK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      : '—';

  return (
    <Card className="px-4 py-3">
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700 text-sm font-semibold">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="truncate text-sm font-semibold text-ink-900">
              <Droplets className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
              {typeLabel}
            </h3>
            {result && (
              <Badge tone={result === 'vyhovuje' ? 'ok' : 'bad'}>
                {PASS_FAIL_LABELS[result]}
              </Badge>
            )}
          </div>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            <MapPin className="-mt-0.5 mr-1 inline size-3" />
            {f.location}
            <span className="mx-1.5 text-ink-300">·</span>
            {f.hose_count ?? 0} {(f.hose_count ?? 0) === 1 ? 'hadica' : 'hadíc'}
          </p>
          <p className="mt-0.5 font-mono text-xs text-ink-600">
            HS {fmt(f.hs)} · HD {fmt(f.hd)} <span className="text-ink-400">MPa</span>
            <span className="mx-1.5 text-ink-300">·</span>
            Q {fmt(f.q)} <span className="text-ink-400">l/s</span>
          </p>
          {f.defects && (
            <p className="mt-1 line-clamp-2 text-xs text-ink-600">
              <AlertTriangle className="-mt-0.5 mr-1 inline size-3 text-status-warn" />
              {f.defects}
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

function HydrantyStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  let pass = 0;
  let fail = 0;
  for (const it of items) {
    const r = (it.fields as Partial<HydrantItemFields>).result;
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
          <span className="text-xs">Vyhovujú</span>
          <span className="text-base font-semibold tabular-nums">{pass}</span>
        </div>
        <div className="flex items-center justify-between gap-2 rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          <span className="text-xs">Nevyhovujú</span>
          <span className="text-base font-semibold tabular-nums">{fail}</span>
        </div>
      </div>
    </Card>
  );
}

export const hydrantyModule: InspectionTypeModule = {
  type: 'hydranty',
  Step2Form: HydrantyStep2Form,
  ItemRow: HydrantyItemRow,
  StatsBar: HydrantyStatsBar,
};
