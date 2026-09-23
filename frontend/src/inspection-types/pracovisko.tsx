/**
 * Kontrola pracoviska — block 2 / chapter 7. One record per úkon:
 * kontrolované priestory *, oblasti * (the eight of chapter 7, each with its
 * own result, plus own areas), zistené nedostatky, celkové hodnotenie *.
 *
 * An area counts as checked the moment it has a result — tapping „Vyhovuje"
 * is the check. „Všetky nevyhodnotené vyhovujú" answers only the areas still
 * open, so the technician marks the lot and then corrects the one that
 * failed, without overwriting anything already answered.
 */
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { AlertTriangle, Plus, X } from 'lucide-react';
import {
  OVERALL_LABELS,
  PRACOVISKO_AREAS,
  isOverall,
  isRowResult,
  stringList,
  type OverallResult,
  type PracoviskoFields,
  type RowResult,
} from '@/api/bozpRecords';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { DefectsEditor, defectCount, useDefects } from '@/components/DefectsEditor';
import { cn } from '@/lib/cn';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import {
  FieldError,
  OverallPicker,
  PassFailToggle,
  PlaceListField,
  RecordRow,
  RecordSubmit,
  SectionLabel,
  fieldClasses,
  plural,
} from './bozpRecordShared';
import type { InspectionTypeModule, ItemRowProps, StatsBarProps, Step2FormProps } from './common';

type Area = { id: number; name: string; custom: boolean; result: RowResult | ''; note: string };
let nextId = 1;

function seedAreas(stored: unknown): Area[] {
  const saved = Array.isArray(stored) ? stored : [];
  const byName = new Map<string, { result: RowResult | ''; note: string }>();
  for (const a of saved) {
    if (!a || typeof a !== 'object') continue;
    const r = a as Record<string, unknown>;
    if (typeof r.name !== 'string' || !r.name.trim()) continue;
    byName.set(r.name, {
      result: isRowResult(r.result) ? r.result : '',
      note: typeof r.note === 'string' ? r.note : '',
    });
  }
  const predefined = PRACOVISKO_AREAS.map((name) => ({
    id: nextId++, name, custom: false, result: byName.get(name)?.result ?? '', note: byName.get(name)?.note ?? '',
  }));
  const own = [...byName.entries()]
    .filter(([name]) => !PRACOVISKO_AREAS.includes(name))
    .map(([name, v]) => ({ id: nextId++, name, custom: true, result: v.result, note: v.note }));
  return [...predefined, ...own];
}

function PracoviskoStep2Form({ inspectionId, facilityId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const toast = useToast();
  const defects = useDefects(initialItem);

  const [spaces, setSpaces] = useState<string[]>([]);
  const [areas, setAreas] = useState<Area[]>(() => seedAreas([]));
  const [overall, setOverall] = useState<OverallResult | ''>('');
  const [errors, setErrors] = useState<Record<string, string | null>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const lastCustomRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    const f = initialItem?.fields ?? {};
    setSpaces(stringList(f.spaces));
    setAreas(seedAreas(f.areas));
    setOverall(isOverall(f.overall) ? f.overall : '');
    setErrors({});
  }, [initialItem]);

  function patchArea(id: number, patch: Partial<Area>) {
    setAreas((prev) => prev.map((a) => (a.id === id ? { ...a, ...patch } : a)));
    setErrors((e) => ({ ...e, areas: null }));
  }

  const open = areas.filter((a) => a.result === '' && a.name.trim()).length;

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const evaluated = areas.filter((a) => a.name.trim() && a.result !== '');
    const unnamed = areas.some((a) => a.custom && !a.name.trim() && a.result !== '');
    const next: Record<string, string | null> = {
      spaces: spaces.length === 0 ? 'Doplň kontrolované priestory.' : null,
      areas: unnamed
        ? 'Vlastná oblasť nemá názov.'
        : evaluated.length === 0 ? 'Vyhodnoť aspoň jednu oblasť.' : null,
      overall: overall === '' ? 'Vyber celkové hodnotenie.' : null,
    };
    const collected = defects.collect();
    setErrors(next);
    if (Object.values(next).some(Boolean) || !collected || overall === '') return;

    setApiError(null);
    setSubmitting(true);
    try {
      const fields: PracoviskoFields = {
        spaces,
        areas: evaluated.map((a) => ({
          name: a.name.trim(),
          result: a.result as RowResult,
          note: a.note.trim() || null,
        })),
        overall,
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
          label="Kontrolované priestory"
          values={spaces}
          onChange={(v) => { setSpaces(v); setErrors((e) => ({ ...e, spaces: null })); }}
          facilityId={facilityId}
          placeholder="Napr. skladové priestory"
          error={errors.spaces ?? null}
        />

        <div className="flex flex-col gap-2">
          <SectionLabel required aside={open > 0 ? (
            <button type="button"
              onClick={() => {
                setAreas((prev) => prev.map((a) => (a.result === '' && a.name.trim() ? { ...a, result: 'vyhovuje' } : a)));
                setErrors((e) => ({ ...e, areas: null }));
              }}
              className="min-h-10 rounded-xl px-3 text-xs font-semibold text-firol-600 transition-colors hover:bg-firol-50">
              Nevyhodnotené označiť ako vyhovuje ({open})
            </button>
          ) : undefined}>
            Oblasti
          </SectionLabel>
          <p className="text-xs text-ink-400">
            Oblasť je súčasťou kontroly, keď jej zadáš výsledok. Oblasti bez výsledku sa do protokolu nevypíšu.
          </p>
          {areas.map((a) => (
            <div key={a.id} className={cn(
              'flex flex-col gap-2 rounded-2xl border p-3 transition-all duration-300',
              a.result === 'nevyhovuje' ? 'border-status-bad/50 bg-[var(--color-status-bad-bg)]/40'
                : a.result === 'vyhovuje' ? 'border-status-ok/40 bg-white' : 'border-ink-200 bg-white',
            )}>
              <div className="flex items-start gap-2">
                {a.custom ? (
                  <input
                    ref={a.id === areas[areas.length - 1]?.id ? lastCustomRef : undefined}
                    type="text"
                    value={a.name}
                    onChange={(e) => patchArea(a.id, { name: e.target.value })}
                    placeholder="Názov vlastnej oblasti…"
                    aria-label="Názov vlastnej oblasti"
                    className={fieldClasses.input}
                  />
                ) : (
                  <span className="flex-1 pt-1 text-sm font-medium text-ink-800">{a.name}</span>
                )}
                {a.custom && (
                  <button type="button" aria-label="Odstrániť vlastnú oblasť"
                    onClick={() => setAreas((prev) => prev.filter((x) => x.id !== a.id))}
                    className="grid size-11 shrink-0 place-items-center rounded-xl text-ink-400 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad">
                    <X className="size-4" />
                  </button>
                )}
              </div>
              <PassFailToggle
                label={`Výsledok — ${a.name || 'vlastná oblasť'}`}
                value={a.result}
                allowClear
                onChange={(v) => patchArea(a.id, { result: v })}
              />
              {a.result !== '' && (
                <input
                  type="text"
                  value={a.note}
                  onChange={(e) => patchArea(a.id, { note: e.target.value })}
                  placeholder="Poznámka (nepovinné)"
                  aria-label={`Poznámka — ${a.name}`}
                  className={fieldClasses.input}
                />
              )}
            </div>
          ))}
          <button type="button"
            onClick={() => {
              setAreas((prev) => [...prev, { id: nextId++, name: '', custom: true, result: '', note: '' }]);
              requestAnimationFrame(() => lastCustomRef.current?.focus());
            }}
            className="flex min-h-12 items-center justify-center gap-1.5 rounded-xl border border-dashed border-ink-300 px-3 py-2 text-sm font-medium text-ink-600 transition-all duration-300 hover:border-firol-400 hover:text-firol-600 active:scale-[0.99]">
            <Plus className="size-4" />
            Pridať vlastnú oblasť
          </button>
          <FieldError>{errors.areas}</FieldError>
        </div>

        <DefectsEditor state={defects} initialPhotos={initialItem?.photos} />

        <OverallPicker
          value={overall}
          onChange={(v) => { setOverall(v); setErrors((e) => ({ ...e, overall: null })); }}
          error={errors.overall ?? null}
        />

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

function PracoviskoItemRow({ inspectionId, index, item, canEdit, deleting, onDelete }: ItemRowProps) {
  const f = item.fields;
  const areas = Array.isArray(f.areas) ? (f.areas as Array<Record<string, unknown>>) : [];
  const failing = areas.filter((a) => a?.result === 'nevyhovuje').length;
  const overall = isOverall(f.overall) ? f.overall : null;
  const nDefects = defectCount(f);
  return (
    <RecordRow
      inspectionId={inspectionId}
      itemId={item.id}
      index={index}
      canEdit={canEdit}
      deleting={deleting}
      onDelete={onDelete}
      title={stringList(f.spaces).join(', ') || 'Kontrola pracoviska'}
      badges={overall
        ? <Badge tone={overall === 'vyhovujuci' ? 'ok' : overall === 'nevyhovujuci' ? 'bad' : 'warn'}>{OVERALL_LABELS[overall]}</Badge>
        : <Badge tone="warn">Chýba hodnotenie</Badge>}
      lines={
        <>
          <p className="mt-0.5 text-xs text-ink-500">
            {plural(areas.length, 'oblasť', 'oblasti', 'oblastí')}
            {failing > 0 && ` · nevyhovuje ${failing}`}
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

/** Shared by pracovisko and osamelé pracoviská: counts plus the celkové hodnotenie. */
export function OverallStatsBar({ items, listKey }: StatsBarProps & { listKey: 'areas' | 'rows' }) {
  if (items.length === 0) return null;
  const f = items[0].fields;
  const rows = Array.isArray(f[listKey]) ? (f[listKey] as Array<Record<string, unknown>>) : [];
  const ok = rows.filter((r) => r?.result === 'vyhovuje').length;
  const bad = rows.filter((r) => r?.result === 'nevyhovuje').length;
  const overall = isOverall(f.overall) ? f.overall : null;
  return (
    <Card className="grid grid-cols-3 gap-2 px-4 py-3 text-center">
      <div>
        <p className="text-xs text-ink-500">Vyhovuje</p>
        <p className="text-lg font-semibold text-[var(--color-status-ok)]">{ok}</p>
      </div>
      <div>
        <p className="text-xs text-ink-500">Nevyhovuje</p>
        <p className={cn('text-lg font-semibold', bad > 0 ? 'text-[var(--color-status-bad)]' : 'text-ink-700')}>{bad}</p>
      </div>
      <div>
        <p className="text-xs text-ink-500">Hodnotenie</p>
        <p className="text-sm font-semibold text-ink-800">{overall ? OVERALL_LABELS[overall] : '—'}</p>
      </div>
    </Card>
  );
}

export const pracoviskoModule: InspectionTypeModule = {
  type: 'pracovisko',
  Step2Form: PracoviskoStep2Form,
  ItemRow: PracoviskoItemRow,
  StatsBar: ({ items }) => <OverallStatsBar items={items} listKey="areas" />,
  singleItem: true,
};
