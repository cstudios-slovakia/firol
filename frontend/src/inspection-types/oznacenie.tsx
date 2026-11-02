import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Check, CheckCircle2, MapPin, PenLine, Signpost } from 'lucide-react';
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
 * Supports single edit and multi-select creation: technician can select all
 * or multiple kinds at once with "Označiť všetky".
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

  // Single-edit state
  const [kindChoice, setKindChoice] = useState<string>('');
  // Multi-select state (when !editing)
  const [selectedKinds, setSelectedKinds] = useState<Set<string>>(() => new Set());
  const [customEnabled, setCustomEnabled] = useState(false);
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
      setCustomEnabled(!predefined);
      setLocation(str(f, 'location'));
      setResult(isPassFail(f.result) ? f.result : null);
      setNotes(str(f, 'notes'));
    } else {
      // By default select all kinds not yet recorded, or start empty with first kind
      const next = OZNACENIE_KINDS.find((k) => !usedKinds.has(k));
      setSelectedKinds(next ? new Set([next]) : new Set());
      setCustomEnabled(false);
      setCustomKind('');
      setLocation('');
      setResult(null);
      setNotes('');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialItem]);

  const singleKind = kindChoice === CUSTOM ? customKind.trim() : kindChoice;

  function isPristine() {
    return !location && result === null && !notes && !customKind
      && photos.total === 0 && defects.rows.length === 0;
  }

  function getKindsToSave(): string[] {
    if (editing) {
      return singleKind ? [singleKind] : [];
    }
    const list = OZNACENIE_KINDS.filter((k) => selectedKinds.has(k));
    if (customEnabled && customKind.trim()) {
      list.push(customKind.trim());
    }
    return list;
  }

  function validate(): FieldErrors {
    const errs: FieldErrors = {};
    const kinds = getKindsToSave();
    if (kinds.length === 0) {
      errs.kind = editing
        ? (kindChoice === CUSTOM ? 'Napíš druh označenia.' : 'Vyber druh označenia.')
        : 'Vyber aspoň jeden druh označenia.';
    }
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
      const kinds = getKindsToSave();
      let lastSaved = null;
      for (let idx = 0; idx < kinds.length; idx++) {
        const k = kinds[idx];
        const fields: OznacenieItemFields = {
          kind: k,
          location: location.trim(),
          result: result as PassFail,
          notes: notes.trim() || null,
          defects: collected.defects,
        };
        lastSaved = await saveItemWithPhotos({
          inspectionId,
          itemId: editing ? itemId : null,
          fields,
          csrfToken,
          photos: idx === 0 ? [photos, ...collected.photos] : [],
        });
        if (idx === 0) {
          collected.discardRemovedPhotos(inspectionId, editing ? itemId : null, csrfToken);
        }
      }
      clearDuplicateSeed(inspectionId);
      onSaved(action);
      toast.success(
        kinds.length > 1
          ? `${kinds.length} druhov označenia uložených`
          : lastSaved ? saveItemMessage(lastSaved, 'Riadok uložený') : 'Riadok uložený',
      );
    } catch (err) {
      setApiError(saveErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  }

  function toggleMultiKind(k: string) {
    setSelectedKinds((prev) => {
      const next = new Set(prev);
      if (next.has(k)) next.delete(k);
      else next.add(k);
      return next;
    });
    clearError(setErrors, 'kind');
  }

  const allSelected = OZNACENIE_KINDS.every((k) => selectedKinds.has(k));

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={(e) => handleSubmit(e, 'save-and-next')}>
        <Field label="Druh označenia" required error={errors.kind}>
          {() => (
            <div className="flex flex-col gap-2">
              {!editing && (
                <div className="flex items-center justify-between pb-1">
                  <span className="text-xs text-ink-500">
                    {selectedKinds.size === 0
                      ? 'Vyberte druhy označenia'
                      : `${selectedKinds.size} z ${OZNACENIE_KINDS.length} označených`}
                  </span>
                  <button
                    type="button"
                    onClick={() => {
                      if (allSelected) {
                        setSelectedKinds(new Set());
                      } else {
                        setSelectedKinds(new Set(OZNACENIE_KINDS));
                      }
                      clearError(setErrors, 'kind');
                    }}
                    className="text-xs font-semibold text-firol-600 hover:text-firol-700"
                  >
                    {allSelected ? 'Zrušiť výber' : 'Označiť všetky'}
                  </button>
                </div>
              )}

              <div className="flex flex-col gap-1.5" role={editing ? 'radiogroup' : undefined} aria-label="Druh označenia">
                {OZNACENIE_KINDS.map((k) => {
                  const isChecked = editing ? kindChoice === k : selectedKinds.has(k);
                  return (
                    <KindOption
                      key={k}
                      label={k}
                      active={isChecked}
                      multi={!editing}
                      done={usedKinds.has(k)}
                      onClick={() => {
                        if (editing) {
                          setKindChoice(k);
                          clearError(setErrors, 'kind');
                          locationRef.current?.querySelector('input')?.focus();
                        } else {
                          toggleMultiKind(k);
                        }
                      }}
                    />
                  );
                })}

                <KindOption
                  label="Vlastný druh označenia"
                  active={editing ? kindChoice === CUSTOM : customEnabled}
                  multi={!editing}
                  done={false}
                  icon={<PenLine className="size-4" />}
                  onClick={() => {
                    if (editing) {
                      setKindChoice(CUSTOM);
                      clearError(setErrors, 'kind');
                    } else {
                      setCustomEnabled((prev) => !prev);
                      clearError(setErrors, 'kind');
                    }
                  }}
                />
              </div>
            </div>
          )}
        </Field>

        {((editing && kindChoice === CUSTOM) || (!editing && customEnabled)) && (
          <Field label="Vlastný druh označenia" required error={errors.kind}>
            {(p) => (
              <Input
                {...p}
                required
                autoFocus
                leftIcon={<Signpost className="size-4" />}
                value={customKind}
                placeholder="Napr. Označenie priestorov s nebezpečenstvom výbuchu"
                onChange={(e) => {
                  setCustomKind(e.target.value);
                  clearError(setErrors, 'kind');
                }}
              />
            )}
          </Field>
        )}

        <div ref={locationRef}>
          <Field label="Umiestnenie" required error={errors.location}>
            {(p) => (
              <AutocompleteInput
                {...p}
                required
                field="location"
                facilityId={facilityId}
                leftIcon={<MapPin className="size-4" />}
                value={location}
                placeholder="celý objekt"
                onChange={(v) => {
                  setLocation(v);
                  clearError(setErrors, 'location');
                }}
              />
            )}
          </Field>
        </div>

        <Field label="Stav" required error={errors.result}>
          {() => (
            <ChoiceGroup
              label="Stav"
              choices={PASS_FAIL_CHOICES}
              value={result}
              invalid={!!errors.result}
              onChange={(v) => {
                setResult(v);
                clearError(setErrors, 'result');
              }}
            />
          )}
        </Field>

        <Field label="Poznámka">
          {(p) => (
            <TextArea
              id={p.id}
              value={notes}
              onChange={setNotes}
              rows={2}
              placeholder="chýba pri vstupe do haly"
            />
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
            if (isPristine()) {
              onSaved('save-and-summary');
              return;
            }
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
  multi,
  done,
  icon,
  onClick,
}: {
  label: string;
  active: boolean;
  multi?: boolean;
  done: boolean;
  icon?: React.ReactNode;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      role={multi ? 'checkbox' : 'radio'}
      aria-checked={active}
      onClick={onClick}
      className={cn(
        'flex min-h-11 items-center gap-2.5 rounded-xl border px-3 py-2 text-left text-sm transition-all duration-200 active:scale-[0.99]',
        active
          ? 'border-firol-500 bg-firol-50 font-semibold text-firol-700'
          : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300',
      )}
    >
      <span className={cn('shrink-0', done ? 'text-status-ok' : active ? 'text-firol-500' : 'text-ink-300')}>
        {multi ? (
          <span
            className={cn(
              'grid size-4 place-items-center rounded border transition-colors',
              active ? 'border-firol-500 bg-firol-500 text-white' : 'border-ink-300 bg-white',
            )}
          >
            {active && <Check className="size-3" />}
          </span>
        ) : (
          icon ?? <CheckCircle2 className="size-4" />
        )}
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
