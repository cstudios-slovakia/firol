import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, CheckCircle2, DoorClosed, Edit2, Hash,
  ListChecks, MapPin, NotebookPen, Save, Tag, Trash2,
} from 'lucide-react';
import {
  Inspections,
  PASS_FAIL_LABELS,
  PU_KIND_LABELS,
  PU_KINDS,
  type PassFailResult,
  type PuAkcieschopnostItemFields,
  type PuKind,
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

function isPuKind(s: unknown): s is PuKind {
  return s === 'dvere' || s === 'okno' || s === 'klapka';
}
function isPassFail(s: unknown): s is PassFailResult {
  return s === 'vyhovuje' || s === 'nevyhovuje';
}

function PuAkStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [kind, setKind] = useState<PuKind>('dvere');
  const [identifier, setIdentifier] = useState('');
  const [manufacturer, setManufacturer] = useState('');
  const [location, setLocation] = useState('');
  const [result, setResult] = useState<PassFailResult>('vyhovuje');
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<PuAkcieschopnostItemFields>;
      setKind(isPuKind(f.kind) ? f.kind : 'dvere');
      setIdentifier(typeof f.identifier === 'string' ? f.identifier : '');
      setManufacturer(typeof f.manufacturer === 'string' ? f.manufacturer : '');
      setLocation(typeof f.location === 'string' ? f.location : '');
      setResult(isPassFail(f.result) ? f.result : 'vyhovuje');
      setNotes(typeof f.notes === 'string' ? f.notes : '');
    } else {
      setKind('dvere');
      setIdentifier('');
      setManufacturer('');
      setLocation('');
      setResult('vyhovuje');
      setNotes('');
    }
  }, [initialItem]);

  function localValidationError(): string | null {
    if (!identifier.trim()) return 'Doplň číslo / označenie uzáveru.';
    if (!manufacturer.trim()) return 'Doplň výrobcu.';
    if (!location.trim()) return 'Doplň umiestnenie.';
    return null;
  }

  async function handleSubmit(e: FormEvent, action: 'save-and-next' | 'save-and-summary') {
    e.preventDefault();
    const localErr = localValidationError();
    if (localErr) {
      setError(localErr);
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      const fields: PuAkcieschopnostItemFields = {
        kind,
        identifier: identifier.trim(),
        manufacturer: manufacturer.trim(),
        location: location.trim(),
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
      setError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={(e) => handleSubmit(e, 'save-and-next')}>
        <Field label="Druh uzáveru" required>
          {() => (
            <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Druh uzáveru">
              {PU_KINDS.map((k) => (
                <KindButton key={k} value={k} active={kind === k} onClick={() => setKind(k)} />
              ))}
            </div>
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Číslo / označenie" required>
            {(p) => (
              <Input {...p} required leftIcon={<Hash className="size-4" />}
                value={identifier} onChange={(e) => setIdentifier(e.target.value)}
                placeholder="PD-A1, kl. 03/EW30" />
            )}
          </Field>
          <Field label="Výrobca" required>
            {(p) => (
              <Input {...p} required leftIcon={<Tag className="size-4" />}
                value={manufacturer} onChange={(e) => setManufacturer(e.target.value)}
                placeholder="Hörmann" />
            )}
          </Field>
        </div>

        <Field label="Umiestnenie" required>
          {(p) => (
            <Input {...p} required leftIcon={<MapPin className="size-4" />}
              value={location} onChange={(e) => setLocation(e.target.value)}
              placeholder="Hala A → kancelárie" />
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

        <Field label="Poznámky / závady" hint="Voliteľné — nech zostane prázdne ak je uzáver v poriadku.">
          {(p) => (
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                <NotebookPen className="size-4" />
              </span>
              <textarea id={p.id} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)}
                placeholder="Poškodené tesnenie, vypadáva pružina, chýba samozatvárač."
                className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
            </div>
          )}
        </Field>

        {error && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </div>
        )}

        <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
          <Button type="button" variant="secondary" onClick={(e) => handleSubmit(e, 'save-and-summary')}
            loading={submitting} leftIcon={<ListChecks className="size-4" />}>
            {editing ? 'Uložiť a späť na súhrn' : 'Prejsť na súhrn'}
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

function KindButton({
  value,
  active,
  onClick,
}: {
  value: PuKind;
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
        <span>{PU_KIND_LABELS[value]}</span>
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

function PuAkItemRow({
  inspectionId,
  index,
  item,
  canEdit,
  deleting,
  onDelete,
}: ItemRowProps) {
  const f = item.fields as Partial<PuAkcieschopnostItemFields>;
  const result = isPassFail(f.result) ? f.result : null;
  const kindLabel = isPuKind(f.kind) ? PU_KIND_LABELS[f.kind] : '';
  return (
    <Card className="px-4 py-3">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700 text-sm font-semibold">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="truncate text-sm font-semibold text-ink-900">
              <DoorClosed className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
              {kindLabel} · {f.identifier}
            </h3>
            {result && (
              <Badge tone={result === 'vyhovuje' ? 'ok' : 'bad'}>
                {PASS_FAIL_LABELS[result]}
              </Badge>
            )}
          </div>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            <Tag className="-mt-0.5 mr-1 inline size-3" />
            {f.manufacturer}
            <span className="mx-1.5 text-ink-300">·</span>
            <MapPin className="-mt-0.5 mr-1 inline size-3" />
            {f.location}
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

function PuAkStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  let pass = 0;
  let fail = 0;
  for (const it of items) {
    const r = (it.fields as Partial<PuAkcieschopnostItemFields>).result;
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

export const puAkcieschopnostModule: InspectionTypeModule = {
  type: 'pu_akcieschopnost',
  Step2Form: PuAkStep2Form,
  ItemRow: PuAkItemRow,
  StatsBar: PuAkStatsBar,
};
