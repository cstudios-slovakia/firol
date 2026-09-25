/**
 * Kontrola dodržiavania zákazu fajčenia — block 2 / chapter 7. One record per
 * úkon: rozsah kontroly * (text), zistenia * (kontrolovaná oblasť *,
 * výsledok *, poznámka), opatrenia (text), zistené nedostatky. A check of
 * premises, not a list of people (typy_ukonov.json).
 */
import { useEffect, useState, type FormEvent } from 'react';
import { AlertTriangle, Plus, Trash2 } from 'lucide-react';
import { isRowResult, type FajcenieFields, type RowResult } from '@/api/bozpRecords';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { DefectsEditor, defectCount, useDefects } from '@/components/DefectsEditor';
import { cn } from '@/lib/cn';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import {
  FieldError,
  PassFailToggle,
  RecordRow,
  RecordSubmit,
  SectionLabel,
  fieldClasses,
  plural,
} from './bozpRecordShared';
import type { InspectionTypeModule, ItemRowProps, StatsBarProps, Step2FormProps } from './common';

type Row = { id: number; area: string; result: RowResult | ''; note: string };
let nextId = 1;
const blankRow = (): Row => ({ id: nextId++, area: '', result: '', note: '' });

function seedRows(stored: unknown): Row[] {
  const rows = Array.isArray(stored) ? stored : [];
  const out = rows.flatMap((r): Row[] => {
    if (!r || typeof r !== 'object') return [];
    const x = r as Record<string, unknown>;
    return [{
      id: nextId++,
      area: typeof x.area === 'string' ? x.area : '',
      result: isRowResult(x.result) ? x.result : '',
      note: typeof x.note === 'string' ? x.note : '',
    }];
  });
  return out.length > 0 ? out : [blankRow()];
}

function FajcenieStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const toast = useToast();
  const defects = useDefects(initialItem);

  const [scope, setScope] = useState('');
  const [rows, setRows] = useState<Row[]>(() => seedRows([]));
  const [measures, setMeasures] = useState('');
  const [errors, setErrors] = useState<Record<string, string | null>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    const f = initialItem?.fields ?? {};
    setScope(typeof f.scope === 'string' ? f.scope : '');
    setRows(seedRows(f.rows));
    setMeasures(typeof f.measures === 'string' ? f.measures : '');
    setErrors({});
  }, [initialItem]);

  function patch(id: number, p: Partial<Row>) {
    setRows((prev) => prev.map((r) => (r.id === id ? { ...r, ...p } : r)));
    setErrors((e) => ({ ...e, rows: null }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const used = rows.filter((r) => r.area.trim() || r.result || r.note.trim());
    let rowsError: string | null = used.length === 0
      ? 'Pridaj aspoň jedno zistenie — kontrolovanú oblasť a výsledok.' : null;
    used.forEach((r, i) => {
      if (rowsError) return;
      if (!r.area.trim()) rowsError = `Zistenie č. ${i + 1}: doplň kontrolovanú oblasť.`;
      else if (!r.result) rowsError = `Zistenie č. ${i + 1}: vyber výsledok.`;
    });
    const next: Record<string, string | null> = {
      scope: scope.trim() ? null : 'Doplň rozsah kontroly — čo sa kontrolovalo.',
      rows: rowsError,
    };
    const collected = defects.collect();
    setErrors(next);
    if (Object.values(next).some(Boolean) || !collected) return;

    setApiError(null);
    setSubmitting(true);
    try {
      const fields: FajcenieFields = {
        scope: scope.trim(),
        rows: used.map((r) => ({ area: r.area.trim(), result: r.result as RowResult, note: r.note.trim() || null })),
        measures: measures.trim() || null,
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
        <div className="flex flex-col gap-2">
          <SectionLabel required>Rozsah kontroly</SectionLabel>
          <textarea
            rows={3}
            value={scope}
            onChange={(e) => { setScope(e.target.value); setErrors((x) => ({ ...x, scope: null })); }}
            placeholder="Čo sa kontrolovalo — napr. vizuálna kontrola priestorov a vyhradených miest na fajčenie."
            aria-label="Rozsah kontroly"
            className={fieldClasses.textarea}
          />
          <FieldError>{errors.scope}</FieldError>
        </div>

        <div className="flex flex-col gap-3">
          <SectionLabel required>Zistenia</SectionLabel>
          {rows.map((r, idx) => (
            <div key={r.id} className={cn(
              'flex flex-col gap-2 rounded-2xl border p-3 transition-all duration-300',
              r.result === 'nevyhovuje' ? 'border-status-bad/50' : 'border-ink-200',
            )}>
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">Zistenie č. {idx + 1}</span>
                {rows.length > 1 && (
                  <button type="button" aria-label={`Odstrániť zistenie č. ${idx + 1}`}
                    onClick={() => setRows((prev) => prev.filter((x) => x.id !== r.id))}
                    className="grid size-10 place-items-center rounded-xl text-ink-400 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad">
                    <Trash2 className="size-4" />
                  </button>
                )}
              </div>
              <label className="flex flex-col gap-1">
                <span className="text-xs font-medium text-ink-500">Kontrolovaná oblasť <span className="text-firol-500">*</span></span>
                <input type="text" value={r.area} onChange={(e) => patch(r.id, { area: e.target.value })}
                  placeholder="Napr. označenie zákazu fajčenia v priestoroch" className={fieldClasses.input} />
              </label>
              <PassFailToggle label={`Výsledok — zistenie č. ${idx + 1}`} value={r.result}
                onChange={(v) => patch(r.id, { result: v })} />
              <input type="text" value={r.note} onChange={(e) => patch(r.id, { note: e.target.value })}
                placeholder="Poznámka (nepovinné)" aria-label={`Poznámka — zistenie č. ${idx + 1}`}
                className={fieldClasses.input} />
            </div>
          ))}
          <button type="button" onClick={() => setRows((prev) => [...prev, blankRow()])}
            className="flex min-h-12 items-center justify-center gap-1.5 rounded-xl border border-dashed border-ink-300 px-3 py-2 text-sm font-medium text-ink-600 transition-all duration-300 hover:border-firol-400 hover:text-firol-600 active:scale-[0.99]">
            <Plus className="size-4" />
            Pridať zistenie
          </button>
          <FieldError>{errors.rows}</FieldError>
        </div>

        <div className="flex flex-col gap-2">
          <SectionLabel>Opatrenia</SectionLabel>
          <textarea rows={3} value={measures} onChange={(e) => setMeasures(e.target.value)}
            placeholder="Napr. opakovane oboznámiť zamestnancov so zákazom fajčenia."
            aria-label="Opatrenia" className={fieldClasses.textarea} />
        </div>

        <DefectsEditor state={defects} initialPhotos={initialItem?.photos} />

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

function FajcenieItemRow({ inspectionId, index, item, canEdit, deleting, onDelete }: ItemRowProps) {
  const f = item.fields;
  const rows = Array.isArray(f.rows) ? (f.rows as Array<Record<string, unknown>>) : [];
  const failing = rows.filter((r) => r?.result === 'nevyhovuje').length;
  const unanswered = rows.some((r) => !isRowResult(r?.result));
  const nDefects = defectCount(f);
  return (
    <RecordRow
      inspectionId={inspectionId}
      itemId={item.id}
      index={index}
      canEdit={canEdit}
      deleting={deleting}
      onDelete={onDelete}
      title={typeof f.scope === 'string' && f.scope ? f.scope : 'Kontrola zákazu fajčenia'}
      badges={unanswered
        ? <Badge tone="warn">Chýba výsledok</Badge>
        : <Badge tone={failing > 0 ? 'bad' : 'ok'}>{failing > 0 ? `Nevyhovuje ${failing}` : 'Vyhovuje'}</Badge>}
      lines={
        <>
          <p className="mt-0.5 text-xs text-ink-500">{plural(rows.length, 'zistenie', 'zistenia', 'zistení')}</p>
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

function FajcenieStatsBar({ items }: StatsBarProps) {
  if (items.length === 0) return null;
  const rows = Array.isArray(items[0].fields.rows) ? (items[0].fields.rows as Array<Record<string, unknown>>) : [];
  const ok = rows.filter((r) => r?.result === 'vyhovuje').length;
  const bad = rows.filter((r) => r?.result === 'nevyhovuje').length;
  return (
    <Card className="grid grid-cols-2 gap-2 px-4 py-3 text-center">
      <div>
        <p className="text-xs text-ink-500">Vyhovuje</p>
        <p className="text-lg font-semibold text-[var(--color-status-ok)]">{ok}</p>
      </div>
      <div>
        <p className="text-xs text-ink-500">Nevyhovuje</p>
        <p className={cn('text-lg font-semibold', bad > 0 ? 'text-[var(--color-status-bad)]' : 'text-ink-700')}>{bad}</p>
      </div>
    </Card>
  );
}

export const fajcenieModule: InspectionTypeModule = {
  type: 'fajcenie',
  Step2Form: FajcenieStep2Form,
  ItemRow: FajcenieItemRow,
  StatsBar: FajcenieStatsBar,
  singleItem: true,
};
