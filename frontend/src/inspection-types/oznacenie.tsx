import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { CheckCircle2, MapPin, PenLine, Signpost } from 'lucide-react';
import {
  BOZP_RESULT_LABELS,
  OZNACENIE_KINDS,
  type OznacenieItemFields,
  type PassFail,
} from '@/api/bozpItems';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
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
  BozpItemRowShell, ChoiceGroup, ItemFormFooter, MeasuresBlock, TextArea,
  clearError, saveErrorMessage, str, type FieldErrors,
} from './bozpShared';
import { PASS_FAIL_CHOICES, PassFailStatsBar, isPassFail } from './pracovne_prostriedky';
import type {
  InspectionTypeModule,
  ItemRowProps,
  Step2FormProps,
} from './common';

/**
 * Kontrola bezpečnostného a zdravotného označenia — block 2 / chapter 7.
 *
 * One row per druh označenia: druh *, umiestnenie *, stav * (vyhovuje /
 * nevyhovuje), poznámka; opatrenia (opatrenie, termín) on the úkon.
 *
 * The nine predefined kinds are offered as a list the technician walks down:
 * a kind already recorded in this úkon is ticked, and a new row starts on the
 * first one not yet recorded — so „Uložiť a ďalší" nine times covers the lot
 * without scrolling a picker. A kind of their own is typed in instead.
 */

const CUSTOM = '__custom__';

function OznacenieStep2Form({ inspectionId, facilityId, initialItem, csrfToken, onSaved, items }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  // Kinds already recorded in this úkon, not counting the row being edited.
  const usedKinds = useMemo(() => new Set(
    (items ?? [])
      .filter((it) => it.id !== itemId)
      .map((it) => str(it.fields, 'kind'))
      .filter(Boolean),
  ), [items, itemId]);

  const [kindChoice, setKindChoice] = useState<string>('');
  const [customKind, setCustomKind] = useState('');
  const [location, setLocation] = useState('');
  const [result, setResult] = useState<PassFail | null>(null);
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();
  const photos = usePhotoStaging(initialItem?.photos);
  const defects = useDefects(initialItem);
  const locationRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const f = initialItem?.fields ?? {};
    if (initialItem) {
      const kind = str(f, 'kind');
      const predefined = OZNACENIE_KINDS.includes(kind);
      setKindChoice(predefined ? kind : CUSTOM);
      setCustomKind(predefined ? '' : kind);
      setLocation(str(f, 'location'));
      setResult(isPassFail(f.result) ? f.result : null);
      setNotes(str(f, 'notes'));
    } else {
      // Start on the first predefined kind nobody has recorded yet.
      const next = OZNACENIE_KINDS.find((k) => !usedKinds.has(k));
      setKindChoice(next ?? CUSTOM);
      setCustomKind('');
      setLocation('');
      setResult(null);
      setNotes('');
    }
    // usedKinds is derived from the items the page loaded with this form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialItem]);

  const kind = kindChoice === CUSTOM ? customKind.trim() : kindChoice;

  function isPristine() {
    // A preselected kind alone is not work the technician did.
    return !location && result === null && !notes && !customKind
      && photos.total === 0 && defects.rows.length === 0;
  }

  function validate(): FieldErrors {
    const errs: FieldErrors = {};
    if (!kind) errs.kind = kindChoice === CUSTOM ? 'Napíš druh označenia.' : 'Vyber druh označenia.';
    if (!location.trim()) errs.location = 'Doplň umiestnenie.';
    if (!result) errs.result = 'Vyber stav.';
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
      const fields: OznacenieItemFields = {
        kind,
        location: location.trim(),
        result: result as PassFail,
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

  function pickKind(value: string) {
    setKindChoice(value);
    clearError(setErrors, 'kind');
    if (value !== CUSTOM) {
      locationRef.current?.querySelector('input')?.focus();
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={(e) => handleSubmit(e, 'save-and-next')}>
        <Field label="Druh označenia" required error={errors.kind}>
          {() => (
            <div className="flex flex-col gap-1.5" role="radiogroup" aria-label="Druh označenia">
              {OZNACENIE_KINDS.map((k) => (
                <KindOption key={k} label={k} active={kindChoice === k} done={usedKinds.has(k)}
                  onClick={() => pickKind(k)} />
              ))}
              <KindOption label="Vlastný druh označenia" active={kindChoice === CUSTOM} done={false}
                icon={<PenLine className="size-4" />} onClick={() => pickKind(CUSTOM)} />
            </div>
          )}
        </Field>

        {kindChoice === CUSTOM && (
          <Field label="Vlastný druh označenia" required error={errors.kind}>
            {(p) => (
              <Input {...p} required autoFocus leftIcon={<Signpost className="size-4" />}
                value={customKind} placeholder="Napr. Označenie priestorov s nebezpečenstvom výbuchu"
                onChange={(e) => { setCustomKind(e.target.value); clearError(setErrors, 'kind'); }} />
            )}
          </Field>
        )}

        <div ref={locationRef}>
          <Field label="Umiestnenie" required error={errors.location}>
            {(p) => (
              <AutocompleteInput {...p} required field="location" facilityId={facilityId}
                leftIcon={<MapPin className="size-4" />} value={location} placeholder="celý objekt"
                onChange={(v) => { setLocation(v); clearError(setErrors, 'location'); }} />
            )}
          </Field>
        </div>

        <Field label="Stav" required error={errors.result}>
          {() => (
            <ChoiceGroup label="Stav" choices={PASS_FAIL_CHOICES} value={result} invalid={!!errors.result}
              onChange={(v) => { setResult(v); clearError(setErrors, 'result'); }} />
          )}
        </Field>

        <Field label="Poznámka">
          {(p) => (
            <TextArea id={p.id} value={notes} onChange={setNotes} rows={2}
              placeholder="chýba pri vstupe do haly" />
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

function KindOption({
  label,
  active,
  done,
  icon,
  onClick,
}: {
  label: string;
  active: boolean;
  done: boolean;
  icon?: React.ReactNode;
  onClick: () => void;
}) {
  return (
    <button type="button" role="radio" aria-checked={active} onClick={onClick}
      className={cn(
        'flex min-h-11 items-center gap-2.5 rounded-xl border px-3 py-2 text-left text-sm transition-all duration-200 active:scale-[0.99]',
        active
          ? 'border-firol-500 bg-firol-50 font-semibold text-firol-700'
          : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300',
      )}>
      <span className={cn('shrink-0', done ? 'text-status-ok' : active ? 'text-firol-500' : 'text-ink-300')}>
        {icon ?? <CheckCircle2 className="size-4" />}
      </span>
      <span className="min-w-0 flex-1">{label}</span>
      {done && <span className="shrink-0 text-xs font-medium text-status-ok">zapísané</span>}
    </button>
  );
}

function OznacenieItemRow(props: ItemRowProps) {
  const f = props.item.fields;
  const result = isPassFail(f.result) ? f.result : null;
  return (
    <BozpItemRowShell
      type="oznacenie"
      {...props}
      icon={<Signpost className="size-3.5" />}
      title={str(f, 'kind')}
      badges={result && (
        <Badge tone={result === 'vyhovuje' ? 'ok' : 'bad'}>{BOZP_RESULT_LABELS[result]}</Badge>
      )}
      lines={[
        <><MapPin className="-mt-0.5 mr-1 inline size-3" />{str(f, 'location')}</>,
        str(f, 'notes'),
      ]}
    />
  );
}

export const oznacenieModule: InspectionTypeModule = {
  type: 'oznacenie',
  Step2Form: OznacenieStep2Form,
  ItemRow: OznacenieItemRow,
  StatsBar: PassFailStatsBar,
  DetailsBlock: MeasuresBlock,
};
