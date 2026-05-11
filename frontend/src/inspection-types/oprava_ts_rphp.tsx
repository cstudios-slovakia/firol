import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowRight, CheckCircle2, Edit2, FileSearch, Hash, ListChecks, MapPin,
  NotebookPen, Save, Tag, Trash2, Wrench,
} from 'lucide-react';
import {
  Inspections,
  OPRAVA_ACTIONS,
  OPRAVA_ACTION_LABELS,
  type OpravaAction,
  type OpravaTsRphpItemFields,
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

function isOpravaAction(s: unknown): s is OpravaAction {
  return s === 'tlakova_skuska' || s === 'oprava' || s === 'plnenie';
}

function OpravaStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [manufacturer, setManufacturer] = useState('');
  const [extType, setExtType] = useState('');
  const [serial, setSerial] = useState('');
  const [year, setYear] = useState<string>('');
  const [location, setLocation] = useState('');
  const [actions, setActions] = useState<OpravaAction[]>([]);
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<OpravaTsRphpItemFields>;
      setManufacturer(typeof f.manufacturer === 'string' ? f.manufacturer : '');
      setExtType(typeof f.type === 'string' ? f.type : '');
      setSerial(typeof f.serial === 'string' ? f.serial : '');
      setYear(typeof f.year === 'number' ? String(f.year) : '');
      setLocation(typeof f.location === 'string' ? f.location : '');
      setActions(Array.isArray(f.actions) ? f.actions.filter(isOpravaAction) : []);
      setNotes(typeof f.notes === 'string' ? f.notes : '');
    } else {
      setManufacturer('');
      setExtType('');
      setSerial('');
      setYear('');
      setLocation('');
      setActions([]);
      setNotes('');
    }
  }, [initialItem]);

  function toggleAction(a: OpravaAction) {
    setActions((prev) => prev.includes(a) ? prev.filter((x) => x !== a) : [...prev, a]);
  }

  function localValidationError(): string | null {
    if (!manufacturer.trim()) return 'Doplň výrobcu.';
    if (!extType.trim()) return 'Doplň typ prístroja.';
    if (!serial.trim()) return 'Doplň výrobné číslo.';
    if (!location.trim()) return 'Doplň umiestnenie.';
    const n = Number(year);
    if (!Number.isInteger(n) || n < 1900 || n > 2200) {
      return 'Rok výroby musí byť v rozsahu 1900–2200.';
    }
    if (actions.length === 0) {
      return 'Vyber aspoň jeden vykonaný úkon.';
    }
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
      const fields: OpravaTsRphpItemFields = {
        manufacturer: manufacturer.trim(),
        type: extType.trim(),
        serial: serial.trim(),
        year: Number(year),
        location: location.trim(),
        actions,
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
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Výrobca" required>
            {(p) => (
              <Input {...p} required leftIcon={<Tag className="size-4" />}
                value={manufacturer} onChange={(e) => setManufacturer(e.target.value)}
                placeholder="Gloria" />
            )}
          </Field>
          <Field label="Typ" required hint="Napr. P6, CO2-5, P9">
            {(p) => (
              <Input {...p} required leftIcon={<FileSearch className="size-4" />}
                value={extType} onChange={(e) => setExtType(e.target.value)}
                placeholder="P6" />
            )}
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Výrobné číslo / séria" required>
            {(p) => (
              <Input {...p} required leftIcon={<Hash className="size-4" />}
                value={serial} onChange={(e) => setSerial(e.target.value)}
                placeholder="GLR-2024-001" />
            )}
          </Field>
          <Field label="Rok výroby" required>
            {(p) => (
              <Input {...p} required type="number" inputMode="numeric" min={1900} max={2200}
                leftIcon={<Hash className="size-4" />}
                value={year} onChange={(e) => setYear(e.target.value)}
                placeholder="2024" />
            )}
          </Field>
        </div>

        <Field label="Umiestnenie" required>
          {(p) => (
            <Input {...p} required leftIcon={<MapPin className="size-4" />}
              value={location} onChange={(e) => setLocation(e.target.value)}
              placeholder="Hala A, vchod" />
          )}
        </Field>

        <Field label="Vykonané úkony" required hint="Aspoň jeden — môže byť aj viac naraz.">
          {() => (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3" role="group" aria-label="Vykonané úkony">
              {OPRAVA_ACTIONS.map((a) => (
                <ActionCheckbox key={a} value={a} active={actions.includes(a)} onClick={() => toggleAction(a)} />
              ))}
            </div>
          )}
        </Field>

        <Field label="Poznámky" hint="Voliteľné — postup servisu, použité diely, odporúčania.">
          {(p) => (
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                <NotebookPen className="size-4" />
              </span>
              <textarea id={p.id} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)}
                placeholder="Vymenený manometer, doplnené 4 kg prášku, stav OK po skúške."
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

function ActionCheckbox({
  value,
  active,
  onClick,
}: {
  value: OpravaAction;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button type="button" role="checkbox" aria-checked={active} onClick={onClick}
      className={cn('rounded-xl border px-3 py-2.5 text-sm font-medium transition-colors text-left',
        active
          ? 'border-firol-500 bg-firol-50 text-firol-700'
          : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300')}>
      <div className="flex items-center gap-2">
        <span className={cn(
          'grid size-4 shrink-0 place-items-center rounded border',
          active ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
        )}>
          {active && <CheckCircle2 className="size-3" strokeWidth={3} />}
        </span>
        <span>{OPRAVA_ACTION_LABELS[value]}</span>
      </div>
    </button>
  );
}

function OpravaItemRow({
  inspectionId,
  index,
  item,
  canEdit,
  deleting,
  onDelete,
}: ItemRowProps) {
  const f = item.fields as Partial<OpravaTsRphpItemFields>;
  const actions = Array.isArray(f.actions) ? f.actions.filter(isOpravaAction) : [];
  return (
    <Card className="px-4 py-3">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700 text-sm font-semibold">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-sm font-semibold text-ink-900">
            <Wrench className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
            {f.manufacturer} · {f.type}
          </h3>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            <Hash className="-mt-0.5 mr-1 inline size-3" />
            {f.serial}
            <span className="mx-1.5 text-ink-300">·</span>
            r. {f.year}
            <span className="mx-1.5 text-ink-300">·</span>
            <MapPin className="-mt-0.5 mr-1 inline size-3" />
            {f.location}
          </p>
          {actions.length > 0 && (
            <div className="mt-1.5 flex flex-wrap gap-1">
              {actions.map((a) => (
                <Badge key={a} tone="brand">{OPRAVA_ACTION_LABELS[a]}</Badge>
              ))}
            </div>
          )}
          {f.notes && (
            <p className="mt-1.5 line-clamp-2 text-xs text-ink-600 italic">{f.notes}</p>
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

function OpravaStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  const counts: Record<OpravaAction, number> = { tlakova_skuska: 0, oprava: 0, plnenie: 0 };
  for (const it of items) {
    const acts = (it.fields as Partial<OpravaTsRphpItemFields>).actions;
    if (Array.isArray(acts)) {
      for (const a of acts) {
        if (isOpravaAction(a)) counts[a] += 1;
      }
    }
  }
  return (
    <Card className="px-4 py-3">
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold uppercase tracking-wider text-ink-500">Štatistika</span>
        <span className="text-ink-500">spolu {items.length}</span>
      </div>
      <div className="mt-2 grid grid-cols-3 gap-2">
        {OPRAVA_ACTIONS.map((a) => (
          <div key={a}
            className="flex items-center justify-between gap-2 rounded-xl bg-firol-50 px-3 py-2 text-sm text-firol-700">
            <span className="text-xs">{OPRAVA_ACTION_LABELS[a]}</span>
            <span className="text-base font-semibold tabular-nums">{counts[a]}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}

export const opravaTsRphpModule: InspectionTypeModule = {
  type: 'oprava_ts_rphp',
  Step2Form: OpravaStep2Form,
  ItemRow: OpravaItemRow,
  StatsBar: OpravaStatsBar,
};
