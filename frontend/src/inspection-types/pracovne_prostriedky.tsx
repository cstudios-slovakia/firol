import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Factory, Hash, MapPin, Wrench } from 'lucide-react';
import {
  BOZP_RESULT_LABELS,
  type PassFail,
  type PracovnyProstriedokItemFields,
} from '@/api/bozpItems';
import { useToast } from '@/lib/toast';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';
import { DefectsEditor, useDefects } from '@/components/DefectsEditor';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { AutocompleteInput } from '@/components/ui/AutocompleteInput';
import { Field } from '@/components/ui/Field';
import { Badge } from '@/components/ui/Badge';
import { clearDuplicateSeed } from './duplicateSeed';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import {
  BozpItemRowShell, ChoiceGroup, ItemFormFooter, MeasuresBlock, StatsGrid, TextArea,
  clearError, countResults, saveErrorMessage, str, type Choice, type FieldErrors,
} from './bozpShared';
import type {
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

/**
 * Kontrola pracovných prostriedkov — block 2 / chapter 7, pattern `tabulka`.
 *
 * názov *, výrobca a typ, inventárne číslo, umiestnenie *, zistené závady,
 * výsledok * (vyhovuje / nevyhovuje); opatrenia (opatrenie, termín) on the
 * úkon.
 */

export const PASS_FAIL_CHOICES: Choice<PassFail>[] = [
  { value: 'vyhovuje', label: 'Vyhovuje', tone: 'ok' },
  { value: 'nevyhovuje', label: 'Nevyhovuje', tone: 'bad' },
];

export function isPassFail(v: unknown): v is PassFail {
  return v === 'vyhovuje' || v === 'nevyhovuje';
}

function PpStep2Form({ inspectionId, facilityId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [name, setName] = useState('');
  const [manufacturerType, setManufacturerType] = useState('');
  const [inventoryNumber, setInventoryNumber] = useState('');
  const [location, setLocation] = useState('');
  const [faults, setFaults] = useState('');
  const [result, setResult] = useState<PassFail | null>(null);

  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();
  const photos = usePhotoStaging(initialItem?.photos);
  const defects = useDefects(initialItem);
  const firstRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const f = initialItem?.fields ?? {};
    setName(str(f, 'name'));
    setManufacturerType(str(f, 'manufacturer_type'));
    setInventoryNumber(str(f, 'inventory_number'));
    setLocation(str(f, 'location'));
    setFaults(str(f, 'faults'));
    setResult(isPassFail(f.result) ? f.result : null);
    if (!initialItem) firstRef.current?.focus();
  }, [initialItem]);

  function isPristine() {
    return !name && !manufacturerType && !inventoryNumber && !location && !faults
      && result === null && photos.total === 0 && defects.rows.length === 0;
  }

  function validate(): FieldErrors {
    const errs: FieldErrors = {};
    if (!name.trim()) errs.name = 'Doplň názov.';
    if (!location.trim()) errs.location = 'Doplň umiestnenie.';
    if (!result) errs.result = 'Vyber výsledok.';
    return errs;
  }

  async function handleSubmit(e: FormEvent | React.SyntheticEvent, action: 'save-and-next' | 'save-and-summary') {
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
      const fields: PracovnyProstriedokItemFields = {
        name: name.trim(),
        manufacturer_type: manufacturerType.trim() || null,
        inventory_number: inventoryNumber.trim() || null,
        location: location.trim(),
        faults: faults.trim() || null,
        result: result as PassFail,
        defects: collected.defects,
      };
      const saved = await saveItemWithPhotos({
        inspectionId, itemId: editing ? itemId : null, fields, csrfToken,
        photos: [photos, ...collected.photos],
      });
      collected.discardRemovedPhotos(inspectionId, editing ? itemId : null, csrfToken);
      clearDuplicateSeed(inspectionId);
      onSaved(action);
      toast.success(saveItemMessage(saved, 'Riadok uložený'));
    } catch (err) {
      setApiError(saveErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={(e) => handleSubmit(e, 'save-and-next')}>
        <Field label="Názov" required error={errors.name}>
          {(p) => (
            <Input {...p} ref={firstRef} required leftIcon={<Wrench className="size-4" />}
              value={name} placeholder="Stĺpová vŕtačka"
              onChange={(e) => { setName(e.target.value); clearError(setErrors, 'name'); }} />
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Výrobca a typ">
            {(p) => (
              <Input {...p} leftIcon={<Factory className="size-4" />}
                value={manufacturerType} placeholder="Optimum B23"
                onChange={(e) => setManufacturerType(e.target.value)} />
            )}
          </Field>
          <Field label="Inventárne číslo">
            {(p) => (
              <Input {...p} leftIcon={<Hash className="size-4" />}
                value={inventoryNumber} placeholder="PP-015"
                onChange={(e) => setInventoryNumber(e.target.value)} />
            )}
          </Field>
        </div>

        <Field label="Umiestnenie" required error={errors.location}>
          {(p) => (
            <AutocompleteInput {...p} required field="location" facilityId={facilityId}
              leftIcon={<MapPin className="size-4" />} value={location} placeholder="dielňa"
              onChange={(v) => { setLocation(v); clearError(setErrors, 'location'); }} />
          )}
        </Field>

        <Field label="Zistené závady" hint="Nechaj prázdne, ak je prostriedok v poriadku.">
          {(p) => (
            <TextArea id={p.id} value={faults} onChange={setFaults} rows={2}
              placeholder="chýba ochranný kryt kotúča" />
          )}
        </Field>

        <Field label="Výsledok" required error={errors.result}>
          {() => (
            <ChoiceGroup label="Výsledok" choices={PASS_FAIL_CHOICES} value={result} invalid={!!errors.result}
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
        />
      </form>
    </Card>
  );
}

function PpItemRow(props: ItemRowProps) {
  const f = props.item.fields;
  const result = isPassFail(f.result) ? f.result : null;
  const ident = [str(f, 'manufacturer_type'), str(f, 'inventory_number') && `inv. č. ${str(f, 'inventory_number')}`]
    .filter(Boolean).join(' · ');
  return (
    <BozpItemRowShell
      type="pracovne_prostriedky"
      {...props}
      icon={<Wrench className="size-3.5" />}
      title={str(f, 'name')}
      badges={result && (
        <Badge tone={result === 'vyhovuje' ? 'ok' : 'bad'}>{BOZP_RESULT_LABELS[result]}</Badge>
      )}
      lines={[
        <><MapPin className="-mt-0.5 mr-1 inline size-3" />{str(f, 'location')}</>,
        ident,
      ]}
    />
  );
}

export function PassFailStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  const c = countResults(items);
  return (
    <StatsGrid total={items.length} cells={[
      { label: 'Vyhovuje', value: c.vyhovuje ?? 0, tone: 'ok' },
      { label: 'Nevyhovuje', value: c.nevyhovuje ?? 0, tone: 'bad' },
    ]} />
  );
}

export const pracovneProstriedkyModule: InspectionTypeModule = {
  type: 'pracovne_prostriedky',
  Step2Form: PpStep2Form,
  ItemRow: PpItemRow,
  StatsBar: PassFailStatsBar,
  DetailsBlock: MeasuresBlock,
};
