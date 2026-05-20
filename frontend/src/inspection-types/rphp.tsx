import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, CheckCircle2, Edit2, FileSearch, Hash, ListChecks,
  MapPin, NotebookPen, Save, Tag, Trash2,
} from 'lucide-react';
import {
  Inspections,
  RPHP_STATUS_LABELS,
  RPHP_STATUS_TONES,
  type RphpItemFields,
  type RphpStatus,
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

function isRphpStatus(s: unknown): s is RphpStatus {
  return s === 'A' || s === 'TS' || s === 'O' || s === 'V';
}

function RphpStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [manufacturer, setManufacturer] = useState('');
  const [extType, setExtType] = useState('');
  const [serial, setSerial] = useState('');
  const [year, setYear] = useState<string>('');
  const [location, setLocation] = useState('');
  const [status, setStatus] = useState<RphpStatus>('A');
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<RphpItemFields>;
      setManufacturer(typeof f.manufacturer === 'string' ? f.manufacturer : '');
      setExtType(typeof f.type === 'string' ? f.type : '');
      setSerial(typeof f.serial === 'string' ? f.serial : '');
      setYear(typeof f.year === 'number' ? String(f.year) : '');
      setLocation(typeof f.location === 'string' ? f.location : '');
      setStatus(isRphpStatus(f.status) ? f.status : 'A');
      setNotes(typeof f.notes === 'string' ? f.notes : '');
    } else {
      setManufacturer('');
      setExtType('');
      setSerial('');
      setYear('');
      setLocation('');
      setStatus('A');
      setNotes('');
    }
  }, [initialItem]);

  function isPristine() {
    return (
      !manufacturer && !extType && !serial && !year && !location && !notes &&
      status === 'A'
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
    if (!manufacturer.trim()) errs.manufacturer = 'Doplň výrobcu.';
    if (!extType.trim()) errs.extType = 'Doplň typ prístroja.';
    if (!serial.trim()) errs.serial = 'Doplň výrobné číslo.';
    if (!location.trim()) errs.location = 'Doplň umiestnenie.';
    const yn = Number(year);
    if (!Number.isInteger(yn) || yn < 1900 || yn > 2200) errs.year = 'Rok výroby musí byť v rozsahu 1900–2200.';
    if (Object.keys(errs).length > 0) { setFieldErrors(errs); return; }
    setFieldErrors({});
    setApiError(null);
    setSubmitting(true);
    try {
      const fields: RphpItemFields = {
        manufacturer: manufacturer.trim(),
        type: extType.trim(),
        serial: serial.trim(),
        year: Number(year),
        location: location.trim(),
        status,
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
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Výrobca" required error={fieldErrors.manufacturer}>
            {(p) => (
              <Input {...p} required leftIcon={<Tag className="size-4" />}
                value={manufacturer} onChange={(e) => { setManufacturer(e.target.value); if (fieldErrors.manufacturer) setFieldErrors((prev) => { const n = { ...prev }; delete n.manufacturer; return n; }); }}
                placeholder="Gloria" />
            )}
          </Field>
          <Field label="Typ" required hint={fieldErrors.extType ? undefined : 'Napr. P6, CO2-5, P9'} error={fieldErrors.extType}>
            {(p) => (
              <Input {...p} required leftIcon={<FileSearch className="size-4" />}
                value={extType} onChange={(e) => { setExtType(e.target.value); if (fieldErrors.extType) setFieldErrors((prev) => { const n = { ...prev }; delete n.extType; return n; }); }}
                placeholder="P6" />
            )}
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Výrobné číslo / séria" required error={fieldErrors.serial}>
            {(p) => (
              <Input {...p} required leftIcon={<Hash className="size-4" />}
                value={serial} onChange={(e) => { setSerial(e.target.value); if (fieldErrors.serial) setFieldErrors((prev) => { const n = { ...prev }; delete n.serial; return n; }); }}
                placeholder="GLR-2024-001" />
            )}
          </Field>
          <Field label="Rok výroby" required error={fieldErrors.year}>
            {(p) => (
              <Input {...p} required type="number" inputMode="numeric" min={1900} max={2200}
                leftIcon={<Hash className="size-4" />}
                value={year} onChange={(e) => { setYear(e.target.value); if (fieldErrors.year) setFieldErrors((prev) => { const n = { ...prev }; delete n.year; return n; }); }}
                placeholder="2024" />
            )}
          </Field>
        </div>

        <Field label="Umiestnenie" required hint={fieldErrors.location ? undefined : 'Kde sa prístroj nachádza v prevádzke.'} error={fieldErrors.location}>
          {(p) => (
            <Input {...p} required leftIcon={<MapPin className="size-4" />}
              value={location} onChange={(e) => { setLocation(e.target.value); if (fieldErrors.location) setFieldErrors((prev) => { const n = { ...prev }; delete n.location; return n; }); }}
              placeholder="Hala A, vchod" />
          )}
        </Field>

        <Field label="Stav" required>
          {() => (
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="Stav prístroja">
              {(Object.keys(RPHP_STATUS_LABELS) as RphpStatus[]).map((s) => (
                <RphpStatusButton key={s} value={s} active={status === s} onClick={() => setStatus(s)} />
              ))}
            </div>
          )}
        </Field>

        <Field label="Poznámky / závady" hint="Voliteľné — uvádzaj len skutočné nálezy.">
          {(p) => (
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
                <NotebookPen className="size-4" />
              </span>
              <textarea id={p.id} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)}
                placeholder="Popis závady, čas zistenia, navrhované opatrenie."
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
            {editing ? 'Uložiť zmeny a ďalší' : 'Uložiť a ďalší'}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function RphpStatusButton({
  value,
  active,
  onClick,
}: {
  value: RphpStatus;
  active: boolean;
  onClick: () => void;
}) {
  const tones: Record<RphpStatus, { active: string; idle: string }> = {
    A:  { active: 'border-status-ok bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
          idle: 'border-ink-200 hover:border-status-ok' },
    TS: { active: 'border-status-warn bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
          idle: 'border-ink-200 hover:border-status-warn' },
    O:  { active: 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
          idle: 'border-ink-200 hover:border-status-bad' },
    V:  { active: 'border-ink-500 bg-ink-100 text-ink-800',
          idle: 'border-ink-200 hover:border-ink-400' },
  };
  const t = tones[value];
  return (
    <button type="button" role="radio" aria-checked={active} onClick={onClick}
      className={cn('rounded-xl border px-3 py-2.5 text-sm font-medium transition-colors text-left',
        active ? t.active : `bg-white text-ink-700 ${t.idle}`)}>
      <div className="flex items-center gap-1.5">
        {active && <CheckCircle2 className="size-3.5" />}
        <span className="font-semibold">{value}</span>
      </div>
      <p className={cn('mt-0.5 text-[11px]', active ? 'opacity-80' : 'text-ink-500')}>
        {RPHP_STATUS_LABELS[value]}
      </p>
    </button>
  );
}

function RphpItemRow({
  inspectionId,
  index,
  item,
  canEdit,
  deleting,
  onDelete,
}: ItemRowProps) {
  const f = item.fields as Partial<RphpItemFields>;
  const status = isRphpStatus(f.status) ? f.status : null;
  return (
    <div className="px-4 py-3">
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700 text-sm font-semibold">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="truncate text-sm font-semibold text-ink-900">
              <Tag className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
              {f.manufacturer} · {f.type}
            </h3>
            {status && (
              <Badge tone={RPHP_STATUS_TONES[status]}>
                {status} · {RPHP_STATUS_LABELS[status]}
              </Badge>
            )}
          </div>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            <Hash className="-mt-0.5 mr-1 inline size-3" />
            {f.serial}
            <span className="mx-1.5 text-ink-300">·</span>
            r. {f.year}
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

function RphpStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  const stats: Record<RphpStatus, number> = { A: 0, TS: 0, O: 0, V: 0 };
  for (const it of items) {
    const s = (it.fields as Partial<RphpItemFields>).status;
    if (isRphpStatus(s)) stats[s] += 1;
  }
  const entries: { key: RphpStatus; label: string; tone: 'ok' | 'warn' | 'bad' | 'neutral' }[] = [
    { key: 'A',  label: 'Akcieschopné',     tone: 'ok' },
    { key: 'TS', label: 'Tlaková skúška',  tone: 'warn' },
    { key: 'O',  label: 'Vyžaduje opravu', tone: 'bad' },
    { key: 'V',  label: 'Vyradené',         tone: 'neutral' },
  ];
  const toneClasses: Record<typeof entries[number]['tone'], string> = {
    ok:      'bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
    warn:    'bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
    bad:     'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
    neutral: 'bg-ink-100 text-ink-700',
  };
  return (
    <Card className="px-4 py-3">
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold uppercase tracking-wider text-ink-500">Štatistika</span>
        <span className="text-ink-500">spolu {items.length}</span>
      </div>
      <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
        {entries.map((e) => (
          <div key={e.key}
            className={cn('flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm',
              toneClasses[e.tone])}>
            <span className="text-xs">{e.label}</span>
            <span className="text-base font-semibold tabular-nums">{stats[e.key]}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}

export const rphpModule: InspectionTypeModule = {
  type: 'rphp',
  Step2Form: RphpStep2Form,
  ItemRow: RphpItemRow,
  StatsBar: RphpStatsBar,
};
