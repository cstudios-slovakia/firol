import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, Building2, CheckCircle2, FileSearch, Hash, ListChecks,
  MapPin, NotebookPen, Save, Tag, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  Inspections,
  RPHP_STATUS_LABELS,
  type InspectionDetail,
  type InspectionItem,
  type RphpItemFields,
  type RphpStatus,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';

/**
 * Step 2 — per-extinguisher form (RPHP). Same route covers two modes:
 *   /inspections/:id/items/new        — add a new item to the draft
 *   /inspections/:id/items/:itemId    — edit an existing item
 */
export function InspectionStep2Page() {
  const { id: inspectionIdStr, itemId: itemIdStr } = useParams<{
    id: string;
    itemId?: string;
  }>();
  const inspectionId = Number(inspectionIdStr);
  const itemId = itemIdStr !== undefined ? Number(itemIdStr) : null;
  const editing = itemId !== null;

  const { csrfToken } = useAuth();
  const navigate = useNavigate();

  const [detail, setDetail] = useState<InspectionDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [manufacturer, setManufacturer] = useState('');
  const [extType, setExtType] = useState('');
  const [serial, setSerial] = useState('');
  const [year, setYear] = useState<string>('');
  const [location, setLocation] = useState('');
  const [status, setStatus] = useState<RphpStatus>('A');
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Inspections.show(inspectionId)
      .then((res) => {
        if (cancelled) return;
        setDetail(res);
        if (editing) {
          const found = res.items.find((it) => it.id === itemId);
          if (!found) {
            setLoadError('Prístroj sa nenašiel — možno bol medzitým zmazaný.');
            return;
          }
          fillFromItem(found);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setLoadError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať kontrolu.');
      });
    return () => {
      cancelled = true;
    };
  }, [inspectionId, itemId, editing]);

  function fillFromItem(item: InspectionItem) {
    const f = item.fields as Partial<RphpItemFields>;
    setManufacturer(typeof f.manufacturer === 'string' ? f.manufacturer : '');
    setExtType(typeof f.type === 'string' ? f.type : '');
    setSerial(typeof f.serial === 'string' ? f.serial : '');
    setYear(typeof f.year === 'number' ? String(f.year) : '');
    setLocation(typeof f.location === 'string' ? f.location : '');
    setStatus(isRphpStatus(f.status) ? f.status : 'A');
    setNotes(typeof f.notes === 'string' ? f.notes : '');
  }

  const totalItems = detail?.items.length ?? 0;
  const currentIndex = useMemo(() => {
    if (!detail) return totalItems;
    if (!editing) return totalItems + 1;
    const idx = detail.items.findIndex((it) => it.id === itemId);
    return idx >= 0 ? idx + 1 : totalItems;
  }, [detail, editing, itemId, totalItems]);
  const totalForHeader = editing ? totalItems : totalItems + 1;

  async function persist(): Promise<number | null> {
    const fields: RphpItemFields = {
      manufacturer: manufacturer.trim(),
      type: extType.trim(),
      serial: serial.trim(),
      year: Number(year),
      location: location.trim(),
      status,
      notes: notes.trim() ? notes.trim() : null,
    };
    if (editing && itemId !== null) {
      const res = await Inspections.updateItem(inspectionId, itemId, fields, csrfToken);
      return res.item.id;
    }
    const res = await Inspections.addItem(inspectionId, fields, csrfToken);
    return res.item.id;
  }

  function localValidationError(): string | null {
    if (!manufacturer.trim()) return 'Doplň výrobcu.';
    if (!extType.trim()) return 'Doplň typ prístroja.';
    if (!serial.trim()) return 'Doplň výrobné číslo.';
    if (!location.trim()) return 'Doplň umiestnenie.';
    const yearNum = Number(year);
    if (!Number.isInteger(yearNum) || yearNum < 1900 || yearNum > 2200) {
      return 'Rok výroby musí byť v rozsahu 1900–2200.';
    }
    return null;
  }

  async function handleSubmit(
    e: FormEvent,
    action: 'save-and-next' | 'save-and-summary',
  ) {
    e.preventDefault();
    const localErr = localValidationError();
    if (localErr) {
      setSubmitError(localErr);
      return;
    }
    setSubmitError(null);
    setSubmitting(true);
    try {
      await persist();
      if (action === 'save-and-summary') {
        navigate(`/inspections/${inspectionId}`, { replace: true });
        return;
      }
      // save-and-next: stay on /items/new with a clean form. When editing,
      // the URL changes so the component remounts and fetches fresh state;
      // when adding, we reset locally and re-fetch the detail so progress
      // dots and counts update.
      if (editing) {
        navigate(`/inspections/${inspectionId}/items/new`, { replace: true });
        return;
      }
      resetForm();
      const fresh = await Inspections.show(inspectionId);
      setDetail(fresh);
    } catch (err) {
      setSubmitError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  function resetForm() {
    setManufacturer('');
    setExtType('');
    setSerial('');
    setYear('');
    setLocation('');
    setStatus('A');
    setNotes('');
    setSubmitError(null);
  }

  if (loadError) {
    return (
      <div className="flex flex-col gap-4">
        <Link to={`/inspections/${inspectionId}`} className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{loadError}</Card>
      </div>
    );
  }

  if (!detail) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  const { inspection: i } = detail;

  return (
    <div className="flex flex-col gap-5">
      <Link
        to={`/inspections/${inspectionId}`}
        className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
      >
        <ArrowLeft className="size-4" />
        Späť na súhrn
      </Link>

      <header>
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
          Krok 2 · zadávanie prístrojov
        </p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
          {editing ? `Upraviť prístroj č. ${currentIndex}` : `Prístroj č. ${currentIndex}`}
        </h1>
        <p className="mt-1 flex items-center gap-1.5 text-xs text-ink-500">
          <Building2 className="size-3" />
          {i.company_name}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />
          {i.facility_name}
        </p>
      </header>

      <ProgressDots
        total={totalForHeader}
        currentIndex={currentIndex}
        items={detail.items}
        editingItemId={itemId}
        inspectionId={inspectionId}
      />

      <Card className="p-5">
        <form className="flex flex-col gap-4" noValidate onSubmit={(e) => handleSubmit(e, 'save-and-next')}>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Výrobca" required>
              {(p) => (
                <Input
                  {...p}
                  required
                  leftIcon={<Tag className="size-4" />}
                  value={manufacturer}
                  onChange={(e) => setManufacturer(e.target.value)}
                  placeholder="Gloria"
                />
              )}
            </Field>
            <Field label="Typ" required hint="Napr. P6, CO2-5, P9">
              {(p) => (
                <Input
                  {...p}
                  required
                  leftIcon={<FileSearch className="size-4" />}
                  value={extType}
                  onChange={(e) => setExtType(e.target.value)}
                  placeholder="P6"
                />
              )}
            </Field>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Výrobné číslo / séria" required>
              {(p) => (
                <Input
                  {...p}
                  required
                  leftIcon={<Hash className="size-4" />}
                  value={serial}
                  onChange={(e) => setSerial(e.target.value)}
                  placeholder="GLR-2024-001"
                />
              )}
            </Field>
            <Field label="Rok výroby" required>
              {(p) => (
                <Input
                  {...p}
                  required
                  type="number"
                  inputMode="numeric"
                  min={1900}
                  max={2200}
                  leftIcon={<Hash className="size-4" />}
                  value={year}
                  onChange={(e) => setYear(e.target.value)}
                  placeholder="2024"
                />
              )}
            </Field>
          </div>

          <Field label="Umiestnenie" required hint="Kde sa prístroj nachádza v prevádzke.">
            {(p) => (
              <Input
                {...p}
                required
                leftIcon={<MapPin className="size-4" />}
                value={location}
                onChange={(e) => setLocation(e.target.value)}
                placeholder="Hala A, vchod"
              />
            )}
          </Field>

          <Field label="Stav" required>
            {() => (
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="Stav prístroja">
                {(Object.keys(RPHP_STATUS_LABELS) as RphpStatus[]).map((s) => (
                  <StatusButton key={s} value={s} active={status === s} onClick={() => setStatus(s)} />
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
                <textarea
                  id={p.id}
                  rows={3}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Popis závady, čas zistenia, navrhované opatrenie."
                  className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
                />
              </div>
            )}
          </Field>

          {submitError && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {submitError}
            </div>
          )}

          <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
            <Button
              type="button"
              variant="secondary"
              onClick={(e) => handleSubmit(e, 'save-and-summary')}
              loading={submitting}
              leftIcon={<ListChecks className="size-4" />}
            >
              {editing ? 'Uložiť a späť na súhrn' : 'Prejsť na súhrn'}
            </Button>
            <Button
              type="submit"
              loading={submitting}
              rightIcon={editing ? <Save className="size-4" /> : <ArrowRight className="size-4" />}
            >
              {editing ? 'Uložiť zmeny a ďalší' : 'Uložiť a ďalší'}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}

function ProgressDots({
  total,
  currentIndex,
  items,
  editingItemId,
  inspectionId,
}: {
  total: number;
  currentIndex: number;
  items: InspectionItem[];
  editingItemId: number | null;
  inspectionId: number;
}) {
  if (total === 0) return null;
  const dots: { key: string; state: 'saved' | 'current' | 'pending'; href?: string }[] = [];
  items.forEach((it) => {
    const isCurrent = editingItemId === it.id;
    dots.push({
      key: `i-${it.id}`,
      state: isCurrent ? 'current' : 'saved',
      href: isCurrent ? undefined : `/inspections/${inspectionId}/items/${it.id}`,
    });
  });
  if (editingItemId === null) {
    dots.push({ key: 'new', state: 'current' });
  }

  return (
    <div className="flex items-center gap-1.5 overflow-x-auto py-1" role="list" aria-label="Postup zadávania">
      {dots.map((d, idx) => {
        const className = cn(
          'shrink-0 size-2.5 rounded-full transition-all',
          d.state === 'saved' && 'bg-firol-500',
          d.state === 'current' && 'bg-firol-500 ring-4 ring-firol-100',
          d.state === 'pending' && 'bg-ink-200',
        );
        if (d.href) {
          return (
            <Link
              key={d.key}
              to={d.href}
              role="listitem"
              aria-label={`Prístroj č. ${idx + 1}`}
              className={className}
              title={`Prístroj č. ${idx + 1}`}
            />
          );
        }
        return (
          <span
            key={d.key}
            role="listitem"
            aria-current={d.state === 'current' ? 'step' : undefined}
            aria-label={`Prístroj č. ${idx + 1}${d.state === 'current' ? ' (aktuálny)' : ''}`}
            className={className}
          />
        );
      })}
      <span className="ml-2 shrink-0 text-xs text-ink-500">
        {currentIndex} / {total}
      </span>
    </div>
  );
}

function StatusButton({
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
          idle:   'border-ink-200 hover:border-status-ok' },
    TS: { active: 'border-status-warn bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
          idle:   'border-ink-200 hover:border-status-warn' },
    O:  { active: 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
          idle:   'border-ink-200 hover:border-status-bad' },
    V:  { active: 'border-ink-500 bg-ink-100 text-ink-800',
          idle:   'border-ink-200 hover:border-ink-400' },
  };
  const tone = tones[value];
  return (
    <button
      type="button"
      role="radio"
      aria-checked={active}
      onClick={onClick}
      className={cn(
        'rounded-xl border px-3 py-2.5 text-sm font-medium transition-colors text-left',
        active ? tone.active : `bg-white text-ink-700 ${tone.idle}`,
      )}
    >
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

function isRphpStatus(s: unknown): s is RphpStatus {
  return s === 'A' || s === 'TS' || s === 'O' || s === 'V';
}
