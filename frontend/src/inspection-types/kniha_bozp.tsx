/**
 * Kniha kontrol BOZP — block 2 / chapter 7. One record per úkon:
 * prehliadnuté pracoviská *, vykonané činnosti * (the 11 of
 * checklist_kniha_bozp.json + own ones), zistené nedostatky, výsledok *.
 */
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { AlertTriangle, CheckCircle2, ListChecks, Plus, X } from 'lucide-react';
import {
  KNIHA_BOZP_ACTIVITIES,
  KNIHA_RESULT_LABELS,
  isKnihaResult,
  stringList,
  type KnihaBozpFields,
  type KnihaResult,
} from '@/api/bozpRecords';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { DefectsEditor, defectCount, useDefects } from '@/components/DefectsEditor';
import { cn } from '@/lib/cn';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import {
  CheckRow,
  FieldError,
  PlaceListField,
  RecordRow,
  RecordSubmit,
  SectionLabel,
  plural,
} from './bozpRecordShared';
import type { InspectionTypeModule, ItemRowProps, StatsBarProps, Step2FormProps } from './common';

type Custom = { id: number; label: string; checked: boolean };
let nextId = 1;

function KnihaStep2Form({ inspectionId, facilityId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const toast = useToast();
  const defects = useDefects(initialItem);

  const [workspaces, setWorkspaces] = useState<string[]>([]);
  const [activities, setActivities] = useState<string[]>([]);
  const [custom, setCustom] = useState<Custom[]>([]);
  const [result, setResult] = useState<KnihaResult | ''>('');
  const [errors, setErrors] = useState<Record<string, string | null>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const lastCustomRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    const f = initialItem?.fields ?? {};
    setWorkspaces(stringList(f.workspaces));
    setActivities(stringList(f.activities).filter((a) => KNIHA_BOZP_ACTIVITIES.includes(a)));
    setCustom(stringList(f.custom_activities).map((label) => ({ id: nextId++, label, checked: true })));
    // A record carried over from last time comes with its result blank on
    // purpose (chapter 12) — the technician states it again.
    setResult(isKnihaResult(f.result) ? f.result : '');
    setErrors({});
  }, [initialItem]);

  const allChecked = KNIHA_BOZP_ACTIVITIES.every((a) => activities.includes(a));

  function toggle(a: string) {
    setActivities((prev) => (prev.includes(a) ? prev.filter((x) => x !== a) : [...prev, a]));
    setErrors((e) => ({ ...e, activities: null }));
  }

  function chooseResult(r: KnihaResult) {
    setResult(r);
    setErrors((e) => ({ ...e, result: null }));
    if (r === 'zistene_nedostatky' && defects.rows.length === 0) defects.add();
    // Empty rows mean nothing; rows with text stay, and the mismatch is
    // pointed out on save rather than silently deleting what was typed.
    if (r === 'bez_nedostatkov' && defects.rows.every((d) => !d.description.trim() && !d.measure.trim() && !d.deadline)) {
      defects.clear();
    }
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const customLabels = custom.filter((c) => c.checked && c.label.trim()).map((c) => c.label.trim());
    const next: Record<string, string | null> = {
      workspaces: workspaces.length === 0 ? 'Doplň aspoň jedno prehliadnuté pracovisko.' : null,
      activities: activities.length === 0 && customLabels.length === 0
        ? 'Vyber aspoň jednu vykonanú činnosť alebo pridaj vlastnú.' : null,
      result: result === '' ? 'Vyber výsledok kontroly.' : null,
    };
    const collected = defects.collect({ requireOne: result === 'zistene_nedostatky' });
    if (collected && result === 'bez_nedostatkov' && collected.defects.length > 0) {
      next.result = 'Pri výsledku „Bez nedostatkov" nemôžu byť zapísané nedostatky — zmeň výsledok alebo nedostatky odstráň.';
    }
    setErrors(next);
    if (Object.values(next).some(Boolean) || !collected || result === '') return;

    setApiError(null);
    setSubmitting(true);
    try {
      const fields: KnihaBozpFields = {
        workspaces,
        activities: KNIHA_BOZP_ACTIVITIES.filter((a) => activities.includes(a)),
        custom_activities: customLabels,
        result,
        defects: collected.defects,
      };
      const saved = await saveItemWithPhotos({
        inspectionId,
        itemId: initialItem?.id ?? null,
        fields,
        csrfToken,
        photos: collected.photos,
      });
      collected.discardRemovedPhotos(inspectionId, initialItem?.id ?? null, csrfToken);
      onSaved('save-and-summary');
      toast.success(saveItemMessage(saved, 'Záznam uložený'));
    } catch (err) {
      setApiError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-4 sm:p-5">
      <form className="flex flex-col gap-6" noValidate onSubmit={handleSubmit}>
        <PlaceListField
          label="Prehliadnuté pracoviská"
          values={workspaces}
          onChange={(v) => { setWorkspaces(v); setErrors((e) => ({ ...e, workspaces: null })); }}
          facilityId={facilityId}
          placeholder="Napr. výrobná hala"
          error={errors.workspaces ?? null}
        />

        <div className="flex flex-col gap-2">
          <SectionLabel required aside={
            <button type="button"
              onClick={() => {
                setActivities(allChecked ? [] : [...KNIHA_BOZP_ACTIVITIES]);
                setErrors((e) => ({ ...e, activities: null }));
              }}
              className="min-h-10 rounded-xl px-3 text-xs font-semibold text-firol-600 transition-colors hover:bg-firol-50">
              {allChecked ? 'Zrušiť označenie' : 'Označiť všetky'}
            </button>
          }>
            Vykonané činnosti
          </SectionLabel>
          {KNIHA_BOZP_ACTIVITIES.map((a) => (
            <CheckRow key={a} label={a} checked={activities.includes(a)} onToggle={() => toggle(a)} />
          ))}
          {custom.map((c, idx) => (
            <div key={c.id} className={cn(
              'flex items-center gap-2 rounded-xl border px-3 py-2 transition-all duration-300',
              c.checked ? 'border-firol-500 bg-firol-50' : 'border-ink-200 bg-white',
            )}>
              <input
                ref={idx === custom.length - 1 ? lastCustomRef : undefined}
                type="text"
                value={c.label}
                onChange={(e) => setCustom((prev) => prev.map((x) => (x.id === c.id ? { ...x, label: e.target.value } : x)))}
                placeholder="Vlastná činnosť…"
                aria-label="Vlastná činnosť"
                className="min-h-10 min-w-0 flex-1 bg-transparent text-sm text-ink-800 outline-none placeholder:text-ink-400"
              />
              <button type="button" aria-label="Odstrániť vlastnú činnosť"
                onClick={() => setCustom((prev) => prev.filter((x) => x.id !== c.id))}
                className="grid size-10 shrink-0 place-items-center rounded-xl text-ink-400 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad">
                <X className="size-4" />
              </button>
            </div>
          ))}
          <button type="button"
            onClick={() => {
              setCustom((prev) => [...prev, { id: nextId++, label: '', checked: true }]);
              setErrors((e) => ({ ...e, activities: null }));
              requestAnimationFrame(() => lastCustomRef.current?.focus());
            }}
            className="flex min-h-12 items-center justify-center gap-1.5 rounded-xl border border-dashed border-ink-300 px-3 py-2 text-sm font-medium text-ink-600 transition-all duration-300 hover:border-firol-400 hover:text-firol-600 active:scale-[0.99]">
            <Plus className="size-4" />
            Pridať vlastnú činnosť
          </button>
          <FieldError>{errors.activities}</FieldError>
        </div>

        <div className="flex flex-col gap-2">
          <SectionLabel required>Výsledok</SectionLabel>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Výsledok">
            {(['bez_nedostatkov', 'zistene_nedostatky'] as KnihaResult[]).map((r) => {
              const active = result === r;
              const tone = r === 'bez_nedostatkov'
                ? 'border-status-ok bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]'
                : 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]';
              return (
                <button key={r} type="button" role="radio" aria-checked={active} onClick={() => chooseResult(r)}
                  className={cn(
                    'flex min-h-12 items-center gap-1.5 rounded-xl border px-4 py-3 text-sm font-semibold transition-all duration-300 active:scale-[0.99]',
                    active ? tone : 'border-ink-200 bg-white text-ink-700 hover:border-ink-300',
                  )}>
                  {active && <CheckCircle2 className="size-4" />}
                  {KNIHA_RESULT_LABELS[r]}
                </button>
              );
            })}
          </div>
          <FieldError>{errors.result}</FieldError>
        </div>

        {(result === 'zistene_nedostatky' || defects.rows.length > 0) && (
          <DefectsEditor
            state={defects}
            initialPhotos={initialItem?.photos}
            required={result === 'zistene_nedostatky'}
          />
        )}

        <RecordSubmit
          submitting={submitting}
          editing={editing}
          apiError={apiError}
          hasErrors={Object.values(errors).some(Boolean) || defects.error !== null}
        />
      </form>
    </Card>
  );
}

function KnihaItemRow({ inspectionId, index, item, canEdit, deleting, onDelete }: ItemRowProps) {
  const f = item.fields;
  const workspaces = stringList(f.workspaces);
  const activityCount = stringList(f.activities).length + stringList(f.custom_activities).length;
  const result = isKnihaResult(f.result) ? f.result : null;
  const nDefects = defectCount(f);
  return (
    <RecordRow
      inspectionId={inspectionId}
      itemId={item.id}
      index={index}
      canEdit={canEdit}
      deleting={deleting}
      onDelete={onDelete}
      title={workspaces.join(', ') || 'Záznam o kontrole'}
      badges={result
        ? <Badge tone={result === 'bez_nedostatkov' ? 'ok' : 'bad'}>{KNIHA_RESULT_LABELS[result]}</Badge>
        : <Badge tone="warn">Chýba výsledok</Badge>}
      lines={
        <>
          <p className="mt-0.5 text-xs text-ink-500">
            <ListChecks className="-mt-0.5 mr-1 inline size-3" />
            {plural(activityCount, 'činnosť', 'činnosti', 'činností')}
          </p>
          {nDefects > 0 && (
            <p className="mt-0.5 text-xs text-ink-600">
              <AlertTriangle className="-mt-0.5 mr-1 inline size-3 text-status-bad" />
              {plural(nDefects, 'nedostatok', 'nedostatky', 'nedostatkov')}
            </p>
          )}
        </>
      }
    />
  );
}

function KnihaStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  const f = items[0].fields;
  const result = isKnihaResult(f.result) ? f.result : null;
  const tone = result === 'bez_nedostatkov'
    ? 'bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]'
    : result === 'zistene_nedostatky'
      ? 'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]'
      : 'bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]';
  return (
    <Card className={cn('px-4 py-3', tone)}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-semibold uppercase tracking-wider opacity-80">Výsledok</span>
        <span className="text-base font-semibold">{result ? KNIHA_RESULT_LABELS[result] : 'Nevyplnený'}</span>
      </div>
    </Card>
  );
}

export const knihaBozpModule: InspectionTypeModule = {
  type: 'kniha_bozp',
  Step2Form: KnihaStep2Form,
  ItemRow: KnihaItemRow,
  StatsBar: KnihaStatsBar,
  singleItem: true,
};
