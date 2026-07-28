import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowRight, Ban, CopyPlus, Edit2, FileSearch, Hash, ListChecks, MapPin,
  Save, Tag, Trash2,
} from 'lucide-react';
import { type VyradenieItemFields } from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { AutocompleteInput, PHP_COMMON_TYPES } from '@/components/ui/AutocompleteInput';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';
import { consumeDuplicateSeed, setDuplicateSeed } from './duplicateSeed';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import type {
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

/**
 * Typical reasons from the client's template. Offered as quick picks; the
 * field stays free text so the technician can describe anything else.
 */
const COMMON_REASONS = [
  'Neúspešná tlaková skúška',
  'Neopraviteľná porucha',
  'Mechanické poškodenie alebo korózia',
  'Chýbajúce súčasti',
  'Prekročená životnosť určená výrobcom',
  'Nerentabilná oprava',
];

/** Fields carried into the next item by "Ďalší rovnaký" — never serial/location. */
type VyradenieSeed = { manufacturer: string; type: string; year: string; reason: string };

function VyradenieStep2Form({ inspectionId, facilityId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [manufacturer, setManufacturer] = useState('');
  const [extType, setExtType] = useState('');
  const [serial, setSerial] = useState('');
  const [year, setYear] = useState<string>('');
  const [location, setLocation] = useState('');
  const [reason, setReason] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();
  const photos = usePhotoStaging(initialItem?.photos);

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<VyradenieItemFields>;
      setManufacturer(typeof f.manufacturer === 'string' ? f.manufacturer : '');
      setExtType(typeof f.type === 'string' ? f.type : '');
      setSerial(typeof f.serial === 'string' ? f.serial : '');
      setYear(typeof f.year === 'number' ? String(f.year) : '');
      setLocation(typeof f.location === 'string' ? f.location : '');
      setReason(typeof f.reason === 'string' ? f.reason : '');
    } else {
      const seed = consumeDuplicateSeed<VyradenieSeed>(inspectionId);
      setManufacturer(seed?.manufacturer ?? '');
      setExtType(seed?.type ?? '');
      setSerial('');
      setYear(seed?.year ?? '');
      setLocation('');
      setReason(seed?.reason ?? '');
    }
  }, [initialItem, inspectionId]);

  function isPristine() {
    return !manufacturer && !extType && !serial && !year && !location && !reason;
  }

  function handleGoToSummary(e: React.SyntheticEvent) {
    e.preventDefault();
    if (isPristine()) { onSaved('save-and-summary'); return; }
    void handleSubmit(e as FormEvent, 'save-and-summary');
  }

  async function handleSubmit(
    e: FormEvent,
    action: 'save-and-next' | 'save-and-summary',
    duplicate = false,
  ) {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (!manufacturer.trim()) errs.manufacturer = 'Doplň výrobcu.';
    if (!extType.trim()) errs.extType = 'Doplň typ prístroja.';
    if (!serial.trim()) errs.serial = 'Doplň výrobné číslo.';
    if (!reason.trim()) errs.reason = 'Doplň dôvod vyradenia.';
    const yn = Number(year);
    if (!Number.isInteger(yn) || yn < 1900 || yn > 2200) errs.year = 'Rok výroby musí byť v rozsahu 1900–2200.';
    if (Object.keys(errs).length > 0) { setFieldErrors(errs); return; }
    setFieldErrors({});
    setApiError(null);
    setSubmitting(true);
    try {
      const fields: VyradenieItemFields = {
        manufacturer: manufacturer.trim(),
        type: extType.trim(),
        serial: serial.trim(),
        year: Number(year),
        location: location.trim() || null,
        reason: reason.trim(),
      };
      const saved = await saveItemWithPhotos({
        inspectionId,
        itemId: editing ? itemId : null,
        fields,
        csrfToken,
        photos,
      });
      if (duplicate) {
        const seed: VyradenieSeed = {
          manufacturer: manufacturer.trim(),
          type: extType.trim(),
          year: year.trim(),
          reason: reason.trim(),
        };
        setDuplicateSeed(inspectionId, seed);
      }
      onSaved(action);
      toast.success(saveItemMessage(saved));
    } catch (err) {
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
              <AutocompleteInput {...p} required field="manufacturer" leftIcon={<Tag className="size-4" />}
                value={manufacturer} onChange={(v) => { setManufacturer(v); if (fieldErrors.manufacturer) setFieldErrors((prev) => { const n = { ...prev }; delete n.manufacturer; return n; }); }}
                placeholder="Gloria" />
            )}
          </Field>
          <Field label="Typ" required hint={fieldErrors.extType ? undefined : 'Napr. P6, CO2-5, P9'} error={fieldErrors.extType}>
            {(p) => (
              <AutocompleteInput {...p} required field="type" staticOptions={PHP_COMMON_TYPES} leftIcon={<FileSearch className="size-4" />}
                value={extType} onChange={(v) => { setExtType(v); if (fieldErrors.extType) setFieldErrors((prev) => { const n = { ...prev }; delete n.extType; return n; }); }}
                placeholder="P6" />
            )}
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Č. výr. série / tlak. nádoby" required error={fieldErrors.serial}>
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
                placeholder="2018" />
            )}
          </Field>
        </div>

        <Field label="Umiestnenie" hint="Kde bol prístroj umiestnený pred vyradením.">
          {(p) => (
            <AutocompleteInput {...p} field="location" facilityId={facilityId} leftIcon={<MapPin className="size-4" />}
              value={location} onChange={setLocation} placeholder="Hala A, vchod" />
          )}
        </Field>

        <Field label="Dôvod vyradenia" required error={fieldErrors.reason}>
          {(p) => (
            <div className="flex flex-col gap-2">
              <Input {...p} required leftIcon={<Ban className="size-4" />}
                value={reason}
                onChange={(e) => { setReason(e.target.value); if (fieldErrors.reason) setFieldErrors((prev) => { const n = { ...prev }; delete n.reason; return n; }); }}
                placeholder="Neúspešná tlaková skúška" />
              <div className="flex flex-wrap gap-1.5">
                {COMMON_REASONS.map((r) => (
                  <button key={r} type="button" onClick={() => { setReason(r); setFieldErrors((prev) => { const n = { ...prev }; delete n.reason; return n; }); }}
                    className={cn(
                      'rounded-lg border px-2 py-1 text-xs transition-all duration-200 active:scale-[0.97]',
                      reason === r
                        ? 'border-firol-300 bg-firol-50 text-firol-700'
                        : 'border-ink-200 bg-white text-ink-600 hover:border-firol-300 hover:text-firol-700',
                    )}>
                    {r}
                  </button>
                ))}
              </div>
            </div>
          )}
        </Field>

        <ItemPhotoField photos={photos} />

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
          {!editing && (
            <Button type="button" variant="secondary" onClick={(e) => handleSubmit(e as unknown as FormEvent, 'save-and-next', true)}
              loading={submitting} leftIcon={<CopyPlus className="size-4" />}
              title="Uloží a predvyplní ďalšiu položku rovnakými údajmi (okrem výr. čísla a umiestnenia).">
              Ďalší rovnaký
            </Button>
          )}
          <Button type="submit" loading={submitting}
            rightIcon={editing ? <Save className="size-4" /> : <ArrowRight className="size-4" />}>
            {editing ? 'Uložiť zmeny a ďalší' : 'Uložiť a ďalší'}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function VyradenieItemRow({ inspectionId, index, item, canEdit, deleting, onDelete }: ItemRowProps) {
  const f = item.fields as Partial<VyradenieItemFields>;
  return (
    <div className="px-4 py-3">
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-ink-100 text-ink-700 text-sm font-semibold">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-sm font-semibold text-ink-900">
            <Ban className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
            {f.manufacturer} · {f.type}
          </h3>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            <Hash className="-mt-0.5 mr-1 inline size-3" />
            {f.serial}
            <span className="mx-1.5 text-ink-300">·</span>
            r. {f.year}
            {f.location && (
              <>
                <span className="mx-1.5 text-ink-300">·</span>
                <MapPin className="-mt-0.5 mr-1 inline size-3" />
                {f.location}
              </>
            )}
          </p>
          {f.reason && (
            <p className="mt-1 line-clamp-2 text-xs text-ink-600">
              <Ban className="-mt-0.5 mr-1 inline size-3 text-[var(--color-status-bad)]" />
              {f.reason}
            </p>
          )}
        </div>
        {canEdit && (
          <div className="flex shrink-0 items-center gap-3.5">
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

function VyradenieStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  return (
    <Card className="px-4 py-3">
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold uppercase tracking-wider text-ink-500">Štatistika</span>
        <span className="text-ink-500">spolu {items.length}</span>
      </div>
      <div className="mt-2 flex items-center justify-between gap-2 rounded-xl bg-ink-100 px-3 py-2 text-sm text-ink-700">
        <span className="text-xs">Vyradených prístrojov</span>
        <span className="text-base font-semibold tabular-nums">{items.length}</span>
      </div>
    </Card>
  );
}

export const vyradenieModule: InspectionTypeModule = {
  type: 'vyradenie',
  Step2Form: VyradenieStep2Form,
  ItemRow: VyradenieItemRow,
  StatsBar: VyradenieStatsBar,
};
