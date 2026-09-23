import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Briefcase, HardHat } from 'lucide-react';
import {
  OOPP_CONDITION_LABELS,
  type OoppCondition,
  type OoppItemFields,
} from '@/api/bozpItems';
import { useToast } from '@/lib/toast';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';
import { DefectsEditor, useDefects } from '@/components/DefectsEditor';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Badge } from '@/components/ui/Badge';
import { clearDuplicateSeed } from './duplicateSeed';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import {
  BozpItemRowShell, ChoiceGroup, DetailsTextBlock, ItemFormFooter, StatsGrid, TextArea,
  YES_NO, boolOrNull, clearError, fromYesNo, saveErrorMessage, str, toYesNo,
  type Choice, type DetailsBlockProps, type FieldErrors,
} from './bozpShared';
import type {
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

/**
 * Kontrola OOPP — block 2 / chapter 7, pattern `tabulka`.
 *
 * One row per pracovná pozícia: pridelené OOPP *, poskytnuté (áno/nie) *,
 * používané (áno/nie), stav (vyhovujúci / opotrebený / chýba), poznámka.
 * The záver is on the úkon.
 */

const CONDITION_CHOICES: Choice<OoppCondition>[] = [
  { value: 'vyhovujuci', label: 'Vyhovujúci', tone: 'ok' },
  { value: 'opotrebeny', label: 'Opotrebený', tone: 'warn' },
  { value: 'chyba', label: 'Chýba', tone: 'bad' },
];

function isCondition(v: unknown): v is OoppCondition {
  return v === 'vyhovujuci' || v === 'opotrebeny' || v === 'chyba';
}

function OoppStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [position, setPosition] = useState('');
  const [equipment, setEquipment] = useState('');
  const [provided, setProvided] = useState<boolean | null>(null);
  const [used, setUsed] = useState<boolean | null>(null);
  const [condition, setCondition] = useState<OoppCondition | null>(null);
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();
  const photos = usePhotoStaging(initialItem?.photos);
  const defects = useDefects(initialItem);
  const firstRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const f = initialItem?.fields ?? {};
    setPosition(str(f, 'position'));
    setEquipment(str(f, 'equipment'));
    setProvided(boolOrNull(f, 'provided'));
    setUsed(boolOrNull(f, 'used'));
    setCondition(isCondition(f.condition) ? f.condition : null);
    setNotes(str(f, 'notes'));
    if (!initialItem) firstRef.current?.focus();
  }, [initialItem]);

  function isPristine() {
    return !position && !equipment && provided === null && used === null && condition === null
      && !notes && photos.total === 0 && defects.rows.length === 0;
  }

  function validate(): FieldErrors {
    const errs: FieldErrors = {};
    if (!position.trim()) errs.position = 'Doplň pracovnú pozíciu.';
    if (!equipment.trim()) errs.equipment = 'Doplň pridelené OOPP.';
    if (provided === null) errs.provided = 'Vyber, či boli OOPP poskytnuté.';
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
      const fields: OoppItemFields = {
        position: position.trim(),
        equipment: equipment.trim(),
        provided: provided as boolean,
        used,
        condition,
        notes: notes.trim() || null,
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
        <Field label="Pracovná pozícia" required error={errors.position}>
          {(p) => (
            <Input {...p} ref={firstRef} required leftIcon={<Briefcase className="size-4" />}
              value={position} placeholder="skladník"
              onChange={(e) => { setPosition(e.target.value); clearError(setErrors, 'position'); }} />
          )}
        </Field>

        <Field label="Pridelené OOPP" required error={errors.equipment}>
          {(p) => (
            <Input {...p} required leftIcon={<HardHat className="size-4" />}
              value={equipment} placeholder="obuv S3, rukavice, reflexná vesta"
              onChange={(e) => { setEquipment(e.target.value); clearError(setErrors, 'equipment'); }} />
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Poskytnuté" required error={errors.provided}>
            {() => (
              <ChoiceGroup label="Poskytnuté" choices={YES_NO} value={toYesNo(provided)}
                invalid={!!errors.provided}
                onChange={(v) => { setProvided(fromYesNo(v)); clearError(setErrors, 'provided'); }} />
            )}
          </Field>
          <Field label="Používané" hint="Ťukni znova na zvolenú možnosť, ak to nebolo možné overiť.">
            {() => (
              <ChoiceGroup label="Používané" choices={YES_NO} value={toYesNo(used)} allowClear
                onChange={(v) => setUsed(fromYesNo(v))} />
            )}
          </Field>
        </div>

        <Field label="Stav">
          {() => (
            <ChoiceGroup label="Stav" choices={CONDITION_CHOICES} value={condition} allowClear
              onChange={setCondition} />
          )}
        </Field>

        <Field label="Poznámka">
          {(p) => (
            <TextArea id={p.id} value={notes} onChange={setNotes} rows={2}
              placeholder="nepoužíva prilbu" />
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

function yesNoLabel(v: boolean | null): string {
  return v === null ? '—' : v ? 'áno' : 'nie';
}

function OoppItemRow(props: ItemRowProps) {
  const f = props.item.fields;
  const provided = boolOrNull(f, 'provided');
  const used = boolOrNull(f, 'used');
  const condition = isCondition(f.condition) ? f.condition : null;
  return (
    <BozpItemRowShell
      type="oopp"
      {...props}
      icon={<HardHat className="size-3.5" />}
      title={str(f, 'position')}
      badges={condition && (
        <Badge tone={condition === 'vyhovujuci' ? 'ok' : condition === 'opotrebeny' ? 'warn' : 'bad'}>
          {OOPP_CONDITION_LABELS[condition]}
        </Badge>
      )}
      lines={[
        str(f, 'equipment'),
        <>
          <span className={provided === false ? 'text-status-bad' : undefined}>Poskytnuté: {yesNoLabel(provided)}</span>
          <span className="text-ink-300"> · </span>
          <span className={used === false ? 'text-status-bad' : undefined}>Používané: {yesNoLabel(used)}</span>
        </>,
        str(f, 'notes'),
      ]}
    />
  );
}

function OoppStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  const count = (pred: (f: Record<string, unknown>) => boolean) =>
    items.filter((it) => pred(it.fields)).length;
  return (
    <StatsGrid total={items.length} cells={[
      { label: 'Poskytnuté', value: count((f) => f.provided === true), tone: 'ok' },
      { label: 'Neposkytnuté', value: count((f) => f.provided === false), tone: 'bad' },
      { label: 'Nepoužívané', value: count((f) => f.used === false), tone: 'bad' },
      { label: 'Vyhovujúci', value: count((f) => f.condition === 'vyhovujuci'), tone: 'ok' },
      { label: 'Opotrebený', value: count((f) => f.condition === 'opotrebeny'), tone: 'warn' },
      { label: 'Chýba', value: count((f) => f.condition === 'chyba'), tone: 'bad' },
    ]} />
  );
}

function OoppDetails(props: DetailsBlockProps) {
  return (
    <DetailsTextBlock {...props} field="conclusion" title="Záver"
      hint="Hodnotenie zoznamu poskytovaných OOPP. Vytlačí sa v protokole."
      placeholder="Zoznam poskytovaných OOPP je aktuálny a zodpovedá výsledkom posúdenia rizika…" />
  );
}

export const ooppModule: InspectionTypeModule = {
  type: 'oopp',
  Step2Form: OoppStep2Form,
  ItemRow: OoppItemRow,
  StatsBar: OoppStatsBar,
  DetailsBlock: OoppDetails,
};
