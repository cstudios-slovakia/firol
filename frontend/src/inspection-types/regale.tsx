import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Hash, LibraryBig, MapPin, Tag, Weight } from 'lucide-react';
import {
  BOZP_RESULT_LABELS,
  type DisposalResult,
  type RegalItemFields,
} from '@/api/bozpItems';
import { useToast } from '@/lib/toast';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';
import { DefectsEditor, useDefects } from '@/components/DefectsEditor';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { AutocompleteInput } from '@/components/ui/AutocompleteInput';
import { Field } from '@/components/ui/Field';
import { Badge } from '@/components/ui/Badge';
import { clearDuplicateSeed, peekDuplicateSeed, setDuplicateSeed } from './duplicateSeed';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import {
  BozpItemRowShell, ChoiceGroup, DetailsTextBlock, ItemFormFooter, StatsGrid, TextArea,
  YES_NO, boolOrNull, clearError, countResults, fromYesNo, saveErrorMessage, str, toYesNo,
  type DetailsBlockProps, type FieldErrors,
} from './bozpShared';
import { DISPOSAL_CHOICES, isDisposalResult } from './rebriky';
import type {
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

/**
 * Kontrola regálov — block 2 / chapter 7, pattern `polozky`.
 *
 * označenie *, typ *, nosnosť *, umiestnenie *, označenie nosnosti (áno/nie) *,
 * zistené závady, výsledok * (vyhovuje / nevyhovuje / vyradené); opatrenia
 * (text) on the úkon.
 */

/**
 * „Ďalší rovnaký" — chapter 6: the previous row's values except its own
 * identifier (the označenie R-01, R-02 …). Závady, nedostatky and photos
 * describe that one regál and never travel.
 */
type RegalSeed = {
  type: string; capacity: string; location: string;
  capacity_marked: boolean | null; result: DisposalResult | null;
};

function RegaleStep2Form({ inspectionId, facilityId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [label, setLabel] = useState('');
  const [type, setType] = useState('');
  const [capacity, setCapacity] = useState('');
  const [location, setLocation] = useState('');
  const [capacityMarked, setCapacityMarked] = useState<boolean | null>(null);
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
      setLabel(str(f, 'label'));
      setType(str(f, 'type'));
      setCapacity(str(f, 'capacity'));
      setLocation(str(f, 'location'));
      setCapacityMarked(boolOrNull(f, 'capacity_marked'));
      setFaults(str(f, 'faults'));
      setResult(isDisposalResult(f.result) ? f.result : null);
    } else {
      const seed = peekDuplicateSeed<RegalSeed>(inspectionId);
      setLabel('');
      setType(seed?.type ?? '');
      setCapacity(seed?.capacity ?? '');
      setLocation(seed?.location ?? '');
      setCapacityMarked(seed?.capacity_marked ?? null);
      setFaults('');
      setResult(seed?.result ?? null);
      firstRef.current?.focus();
    }
  }, [initialItem, inspectionId]);

  function isPristine() {
    return !label && !type && !capacity && !location && capacityMarked === null && !faults
      && result === null && photos.total === 0 && defects.rows.length === 0;
  }

  function validate(): FieldErrors {
    const errs: FieldErrors = {};
    if (!label.trim()) errs.label = 'Doplň označenie regálu.';
    if (!type.trim()) errs.type = 'Doplň typ regálu.';
    if (!capacity.trim()) errs.capacity = 'Doplň nosnosť.';
    if (!location.trim()) errs.location = 'Doplň umiestnenie.';
    if (capacityMarked === null) errs.capacity_marked = 'Vyber, či je nosnosť označená.';
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
      const fields: RegalItemFields = {
        label: label.trim(),
        type: type.trim(),
        capacity: capacity.trim(),
        location: location.trim(),
        capacity_marked: capacityMarked as boolean,
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
        setDuplicateSeed(inspectionId, {
          type: type.trim(), capacity: capacity.trim(), location: location.trim(),
          capacity_marked: capacityMarked, result,
        } satisfies RegalSeed);
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
          <Field label="Označenie regálu" required error={errors.label}>
            {(p) => (
              <Input {...p} ref={firstRef} required leftIcon={<Hash className="size-4" />}
                value={label} placeholder="R-01"
                onChange={(e) => { setLabel(e.target.value); clearError(setErrors, 'label'); }} />
            )}
          </Field>
          <Field label="Typ regálu" required error={errors.type}>
            {(p) => (
              <Input {...p} required leftIcon={<Tag className="size-4" />}
                value={type} placeholder="paletový"
                onChange={(e) => { setType(e.target.value); clearError(setErrors, 'type'); }} />
            )}
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Nosnosť" required error={errors.capacity}>
            {(p) => (
              <Input {...p} required leftIcon={<Weight className="size-4" />}
                value={capacity} placeholder="1 000 kg/pole"
                onChange={(e) => { setCapacity(e.target.value); clearError(setErrors, 'capacity'); }} />
            )}
          </Field>
          <Field label="Umiestnenie" required error={errors.location}>
            {(p) => (
              <AutocompleteInput {...p} required field="location" facilityId={facilityId}
                leftIcon={<MapPin className="size-4" />} value={location} placeholder="sklad A"
                onChange={(v) => { setLocation(v); clearError(setErrors, 'location'); }} />
            )}
          </Field>
        </div>

        <Field label="Označenie nosnosti" required error={errors.capacity_marked}>
          {() => (
            <ChoiceGroup label="Označenie nosnosti" choices={YES_NO} value={toYesNo(capacityMarked)}
              invalid={!!errors.capacity_marked}
              onChange={(v) => { setCapacityMarked(fromYesNo(v)); clearError(setErrors, 'capacity_marked'); }} />
          )}
        </Field>

        <Field label="Zistené závady" hint="Nechaj prázdne, ak je regál v poriadku.">
          {(p) => (
            <TextArea id={p.id} value={faults} onChange={setFaults} rows={2}
              placeholder="ohnutý stĺpik, poškodená pätka" />
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
          duplicateHint="Uloží a predvyplní ďalší regál rovnakými údajmi — okrem označenia, závad a fotiek."
        />
      </form>
    </Card>
  );
}

function RegaleItemRow(props: ItemRowProps) {
  const f = props.item.fields;
  const result = isDisposalResult(f.result) ? f.result : null;
  const marked = boolOrNull(f, 'capacity_marked');
  return (
    <BozpItemRowShell
      type="regale"
      {...props}
      icon={<LibraryBig className="size-3.5" />}
      title={<>{str(f, 'label')} <span className="font-normal text-ink-600">· {str(f, 'type')}</span></>}
      badges={result && (
        <Badge tone={result === 'vyhovuje' ? 'ok' : result === 'nevyhovuje' ? 'bad' : 'neutral'}>
          {BOZP_RESULT_LABELS[result]}
        </Badge>
      )}
      lines={[
        <><MapPin className="-mt-0.5 mr-1 inline size-3" />{str(f, 'location')}
          <span className="text-ink-400"> · {str(f, 'capacity')}</span></>,
        marked !== null && (
          <span className={marked ? 'text-status-ok' : 'text-status-bad'}>
            Označenie nosnosti: {marked ? 'áno' : 'nie'}
          </span>
        ),
      ]}
    />
  );
}

function RegaleStatsBar({ items }: StatsBarProps) {
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

function RegaleDetails(props: DetailsBlockProps) {
  return (
    <DetailsTextBlock {...props} field="measures_text" title="Opatrenia"
      hint="Čo sa má s nevyhovujúcimi a vyradenými regálmi urobiť. Vytlačí sa v protokole."
      placeholder="Regál R-03 doplniť o štítok s označením nosnosti…" />
  );
}

export const regaleModule: InspectionTypeModule = {
  type: 'regale',
  Step2Form: RegaleStep2Form,
  ItemRow: RegaleItemRow,
  StatsBar: RegaleStatsBar,
  DetailsBlock: RegaleDetails,
};
