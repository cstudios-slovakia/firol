import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Factory, Hash, TrainTrack, MapPin, Tag } from 'lucide-react';
import {
  BOZP_RESULT_LABELS,
  type DisposalResult,
  type RebrikItemFields,
} from '@/api/bozpItems';
import { useToast } from '@/lib/toast';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { AutocompleteInput } from '@/components/ui/AutocompleteInput';
import { Field } from '@/components/ui/Field';
import { Badge } from '@/components/ui/Badge';
import { clearDuplicateSeed, peekDuplicateSeed, setDuplicateSeed } from './duplicateSeed';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import {
  BozpItemRowShell, ChoiceGroup, DetailsTextBlock, ItemFormFooter, StatsGrid, TextArea,
  clearError, countResults, saveErrorMessage, str, type Choice, type DetailsBlockProps,
  type FieldErrors,
} from './bozpShared';
import { DefectsEditor, useDefects } from '@/components/DefectsEditor';
import type {
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

/**
 * Kontrola rebríkov — block 2 / chapter 7, pattern `polozky`.
 *
 * inventárne číslo *, typ *, výrobca, rok výroby, umiestnenie *, zistené
 * závady, výsledok * (vyhovuje / nevyhovuje / vyradené); opatrenia (text) on
 * the úkon.
 */

export const DISPOSAL_CHOICES: Choice<DisposalResult>[] = [
  { value: 'vyhovuje', label: 'Vyhovuje', tone: 'ok' },
  { value: 'nevyhovuje', label: 'Nevyhovuje', tone: 'bad' },
  { value: 'vyradene', label: 'Vyradené', tone: 'neutral' },
];

export function isDisposalResult(v: unknown): v is DisposalResult {
  return v === 'vyhovuje' || v === 'nevyhovuje' || v === 'vyradene';
}

/**
 * „Ďalší rovnaký" — chapter 6: the previous row's values except the serial,
 * which for a rebrík is the inventárne číslo. The výsledok comes along as it
 * does for PHP; the závady, nedostatky and photos describe that one ladder and
 * never do.
 */
type RebrikSeed = {
  type: string; manufacturer: string; year: string; location: string; result: DisposalResult | null;
};

function RebrikyStep2Form({ inspectionId, facilityId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [inventoryNumber, setInventoryNumber] = useState('');
  const [type, setType] = useState('');
  const [manufacturer, setManufacturer] = useState('');
  const [year, setYear] = useState('');
  const [location, setLocation] = useState('');
  const [faults, setFaults] = useState('');
  const [result, setResult] = useState<DisposalResult | null>(null);

  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();
  const photos = usePhotoStaging(initialItem?.photos);
  const defects = useDefects(initialItem);
  const firstRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const f = initialItem?.fields ?? {};
    if (initialItem) {
      setInventoryNumber(str(f, 'inventory_number'));
      setType(str(f, 'type'));
      setManufacturer(str(f, 'manufacturer'));
      setYear(str(f, 'year'));
      setLocation(str(f, 'location'));
      setFaults(str(f, 'faults'));
      setResult(isDisposalResult(f.result) ? f.result : null);
    } else {
      const seed = peekDuplicateSeed<RebrikSeed>(inspectionId);
      setInventoryNumber('');
      setType(seed?.type ?? '');
      setManufacturer(seed?.manufacturer ?? '');
      setYear(seed?.year ?? '');
      setLocation(seed?.location ?? '');
      setFaults('');
      setResult(seed?.result ?? null);
      firstRef.current?.focus();
    }
  }, [initialItem, inspectionId]);

  function isPristine() {
    return !inventoryNumber && !type && !manufacturer && !year && !location && !faults
      && result === null && photos.total === 0 && defects.rows.length === 0;
  }

  function validate(): FieldErrors {
    const errs: FieldErrors = {};
    if (!inventoryNumber.trim()) errs.inventory_number = 'Doplň inventárne číslo.';
    if (!type.trim()) errs.type = 'Doplň typ rebríka.';
    if (!location.trim()) errs.location = 'Doplň umiestnenie.';
    if (year.trim()) {
      const y = Number(year);
      if (!Number.isInteger(y) || y < 1900 || y > 2200) errs.year = 'Rok výroby musí byť rok, napr. 2019.';
    }
    if (!result) errs.result = 'Vyber výsledok.';
    return errs;
  }

  async function handleSubmit(e: FormEvent | React.SyntheticEvent, action: 'save-and-next' | 'save-and-summary', duplicate = false) {
    e.preventDefault();
    if (submitting) return;
    const errs = validate();
    if (Object.keys(errs).length > 0) { setErrors(errs); return; }
    const collected = defects.collect();
    if (!collected) return;
    setErrors({});
    setApiError(null);
    setSubmitting(true);
    try {
      const fields: RebrikItemFields = {
        inventory_number: inventoryNumber.trim(),
        type: type.trim(),
        manufacturer: manufacturer.trim() || null,
        year: year.trim() ? Number(year) : null,
        location: location.trim(),
        faults: faults.trim() || null,
        result: result as DisposalResult,
        defects: collected.defects,
      };
      const saved = await saveItemWithPhotos({
        inspectionId, itemId: editing ? itemId : null, fields, csrfToken,
        photos: [photos, ...collected.photos],
      });
      collected.discardRemovedPhotos(inspectionId, editing ? itemId : null, csrfToken);
      if (duplicate) {
        setDuplicateSeed(inspectionId, { type: type.trim(), manufacturer: manufacturer.trim(), year: year.trim(), location: location.trim(), result } satisfies RebrikSeed);
      } else {
        clearDuplicateSeed(inspectionId);
      }
      onSaved(action);
      toast.success(saveItemMessage(saved));
    } catch (err) {
      setApiError(saveErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={(e) => handleSubmit(e, 'save-and-next')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Inventárne číslo" required error={errors.inventory_number}>
            {(p) => (
              <Input {...p} ref={firstRef} required leftIcon={<Hash className="size-4" />}
                value={inventoryNumber} placeholder="REB-001"
                onChange={(e) => { setInventoryNumber(e.target.value); clearError(setErrors, 'inventory_number'); }} />
            )}
          </Field>
          <Field label="Typ rebríka" required error={errors.type}>
            {(p) => (
              <Input {...p} required leftIcon={<Tag className="size-4" />}
                value={type} placeholder="hliníkový dvojdielny"
                onChange={(e) => { setType(e.target.value); clearError(setErrors, 'type'); }} />
            )}
          </Field>
        </div>

        <div className="grid gap-4 grid-cols-[1fr_7rem]">
          <Field label="Výrobca">
            {(p) => (
              <AutocompleteInput {...p} field="manufacturer" leftIcon={<Factory className="size-4" />}
                value={manufacturer} onChange={setManufacturer} placeholder="Alve" />
            )}
          </Field>
          <Field label="Rok výroby" error={errors.year}>
            {(p) => (
              <Input {...p} type="number" inputMode="numeric" min={1900} max={2200}
                value={year} placeholder="2021"
                onChange={(e) => { setYear(e.target.value); clearError(setErrors, 'year'); }} />
            )}
          </Field>
        </div>

        <Field label="Umiestnenie" required error={errors.location}>
          {(p) => (
            <AutocompleteInput {...p} required field="location" facilityId={facilityId}
              leftIcon={<MapPin className="size-4" />} value={location} placeholder="sklad A"
              onChange={(v) => { setLocation(v); clearError(setErrors, 'location'); }} />
          )}
        </Field>

        <Field label="Zistené závady" hint="Nechaj prázdne, ak je rebrík v poriadku.">
          {(p) => (
            <TextArea id={p.id} value={faults} onChange={setFaults} rows={2}
              placeholder="ohnutá priečka, chýbajú pätky" />
          )}
        </Field>

        <Field label="Výsledok" required error={errors.result}>
          {() => (
            <ChoiceGroup label="Výsledok" choices={DISPOSAL_CHOICES} value={result} invalid={!!errors.result}
              onChange={(v) => { setResult(v); clearError(setErrors, 'result'); }} />
          )}
        </Field>

        <ItemPhotoField photos={photos} />
        <DefectsEditor state={defects} initialPhotos={initialItem?.photos} />

        <ItemFormFooter
          editing={editing}
          submitting={submitting}
          apiError={apiError}
          hasErrors={Object.keys(errors).length > 0}
          onSummary={(e) => {
            e.preventDefault();
            if (isPristine()) { onSaved('save-and-summary'); return; }
            void handleSubmit(e, 'save-and-summary');
          }}
          onDuplicate={(e) => void handleSubmit(e, 'save-and-next', true)}
          duplicateHint="Uloží a predvyplní ďalší rebrík rovnakými údajmi — okrem inventárneho čísla, závad a fotiek."
        />
      </form>
    </Card>
  );
}

function RebrikyItemRow(props: ItemRowProps) {
  const f = props.item.fields;
  const result = isDisposalResult(f.result) ? f.result : null;
  const year = str(f, 'year');
  const manufacturer = str(f, 'manufacturer');
  return (
    <BozpItemRowShell
      type="rebriky"
      {...props}
      icon={<TrainTrack className="size-3.5" />}
      title={<>{str(f, 'inventory_number')} <span className="font-normal text-ink-600">· {str(f, 'type')}</span></>}
      badges={result && (
        <Badge tone={result === 'vyhovuje' ? 'ok' : result === 'nevyhovuje' ? 'bad' : 'neutral'}>
          {BOZP_RESULT_LABELS[result]}
        </Badge>
      )}
      lines={[
        <><MapPin className="-mt-0.5 mr-1 inline size-3" />{str(f, 'location')}
          {(manufacturer || year) && <span className="text-ink-400"> · {[manufacturer, year].filter(Boolean).join(', ')}</span>}</>,
      ]}
    />
  );
}

function RebrikyStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  const c = countResults(items);
  return (
    <StatsGrid total={items.length} cells={[
      { label: 'Vyhovuje', value: c.vyhovuje ?? 0, tone: 'ok' },
      { label: 'Nevyhovuje', value: c.nevyhovuje ?? 0, tone: 'bad' },
      { label: 'Vyradené', value: c.vyradene ?? 0, tone: 'neutral' },
    ]} />
  );
}

function RebrikyDetails(props: DetailsBlockProps) {
  return (
    <DetailsTextBlock {...props} field="measures_text" title="Opatrenia"
      hint="Čo sa má s nevyhovujúcimi a vyradenými rebríkmi urobiť. Vytlačí sa v protokole."
      placeholder="Rebrík č. 3 vyradiť z používania do odstránenia závad…" />
  );
}

export const rebrikyModule: InspectionTypeModule = {
  type: 'rebriky',
  Step2Form: RebrikyStep2Form,
  ItemRow: RebrikyItemRow,
  StatsBar: RebrikyStatsBar,
  DetailsBlock: RebrikyDetails,
};
