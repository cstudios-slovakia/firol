/**
 * Kontrola osamelých pracovísk — block 2 / chapter 7. One record per úkon:
 * rows of pracovisko *, vykonávaná činnosť *, spojenie *, kontrola
 * prítomnosti *, výsledok * — plus zistené nedostatky and celkové
 * hodnotenie *. Carried over from last time, a row keeps its pracovisko and
 * činnosť; spojenie, kontrola prítomnosti and výsledok are this visit's
 * findings and start empty (chapter 12).
 */
import { useEffect, useState, type FormEvent } from 'react';
import { AlertTriangle, Plus, Trash2 } from 'lucide-react';
import {
  OVERALL_LABELS,
  isOverall,
  isRowResult,
  type OsameleFields,
  type OverallResult,
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
  RecordRow,
  RecordSubmit,
  SectionLabel,
  fieldClasses,
  plural,
} from './bozpRecordShared';
import { OverallStatsBar } from './pracovisko';
import type { InspectionTypeModule, ItemRowProps, Step2FormProps } from './common';

type Row = {
  id: number;
  workplace: string;
  activity: string;
  connection: string;
  presence_check: string;
  result: RowResult | '';
};
let nextId = 1;

const blankRow = (): Row => ({ id: nextId++, workplace: '', activity: '', connection: '', presence_check: '', result: '' });

function str(v: unknown): string {
  return typeof v === 'string' ? v : '';
}

function seedRows(stored: unknown): Row[] {
  const rows = Array.isArray(stored) ? stored : [];
  const out = rows.flatMap((r): Row[] => {
    if (!r || typeof r !== 'object') return [];
    const x = r as Record<string, unknown>;
    return [{
      id: nextId++,
      workplace: str(x.workplace),
      activity: str(x.activity),
      connection: str(x.connection),
      presence_check: str(x.presence_check),
      result: isRowResult(x.result) ? x.result : '',
    }];
  });
  return out.length > 0 ? out : [blankRow()];
}

const COLUMNS: Array<{ key: 'workplace' | 'activity' | 'connection' | 'presence_check'; label: string; placeholder: string }> = [
  { key: 'workplace', label: 'Pracovisko', placeholder: 'Napr. kotolňa' },
  { key: 'activity', label: 'Vykonávaná činnosť', placeholder: 'Napr. obsluha, kontrola' },
  { key: 'connection', label: 'Spojenie', placeholder: 'Napr. mobil + vysielačka' },
  { key: 'presence_check', label: 'Kontrola prítomnosti', placeholder: 'Napr. hlásenie každé 2 h' },
];

function OsameleStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const toast = useToast();
  const defects = useDefects(initialItem);

  const [rows, setRows] = useState<Row[]>(() => seedRows([]));
  const [overall, setOverall] = useState<OverallResult | ''>('');
  const [errors, setErrors] = useState<Record<string, string | null>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    const f = initialItem?.fields ?? {};
    setRows(seedRows(f.rows));
    setOverall(isOverall(f.overall) ? f.overall : '');
    setErrors({});
  }, [initialItem]);

  function patch(id: number, p: Partial<Row>) {
    setRows((prev) => prev.map((r) => (r.id === id ? { ...r, ...p } : r)));
    setErrors((e) => ({ ...e, rows: null }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    // A row left completely empty is the one „Pridať pracovisko" added and
    // nothing more — it is dropped, not reported.
    const used = rows.filter((r) => r.workplace.trim() || r.activity.trim() || r.connection.trim() || r.presence_check.trim() || r.result);
    let rowsError: string | null = used.length === 0 ? 'Pridaj aspoň jedno kontrolované pracovisko.' : null;
    used.forEach((r, i) => {
      if (rowsError) return;
      const missing = COLUMNS.find((c) => !r[c.key].trim());
      if (missing) rowsError = `Riadok č. ${i + 1}: doplň pole „${missing.label}".`;
      else if (!r.result) rowsError = `Riadok č. ${i + 1}: vyber výsledok.`;
    });
    const next: Record<string, string | null> = {
      rows: rowsError,
      overall: overall === '' ? 'Vyber celkové hodnotenie.' : null,
    };
    const collected = defects.collect();
    setErrors(next);
    if (Object.values(next).some(Boolean) || !collected || overall === '') return;

    setApiError(null);
    setSubmitting(true);
    try {
      const fields: OsameleFields = {
        rows: used.map((r) => ({
          workplace: r.workplace.trim(),
          activity: r.activity.trim(),
          connection: r.connection.trim(),
          presence_check: r.presence_check.trim(),
          result: r.result as RowResult,
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
        <div className="flex flex-col gap-3">
          <SectionLabel required>Kontrolované pracoviská</SectionLabel>
          {rows.map((r, idx) => (
            <div key={r.id} className={cn(
              'flex flex-col gap-2 rounded-2xl border p-3 transition-all duration-300',
              r.result === 'nevyhovuje' ? 'border-status-bad/50' : 'border-ink-200',
            )}>
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">Pracovisko č. {idx + 1}</span>
                {rows.length > 1 && (
                  <button type="button" aria-label={`Odstrániť pracovisko č. ${idx + 1}`}
                    onClick={() => setRows((prev) => prev.filter((x) => x.id !== r.id))}
                    className="grid size-10 place-items-center rounded-xl text-ink-400 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad">
                    <Trash2 className="size-4" />
                  </button>
                )}
              </div>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {COLUMNS.map((c) => (
                  <label key={c.key} className="flex flex-col gap-1">
                    <span className="text-xs font-medium text-ink-500">{c.label} <span className="text-firol-500">*</span></span>
                    <input
                      type="text"
                      value={r[c.key]}
                      onChange={(e) => patch(r.id, { [c.key]: e.target.value })}
                      placeholder={c.placeholder}
                      className={fieldClasses.input}
                    />
                  </label>
                ))}
              </div>
              <PassFailToggle label={`Výsledok — pracovisko č. ${idx + 1}`} value={r.result}
                onChange={(v) => patch(r.id, { result: v })} />
            </div>
          ))}
          <button type="button" onClick={() => setRows((prev) => [...prev, blankRow()])}
            className="flex min-h-12 items-center justify-center gap-1.5 rounded-xl border border-dashed border-ink-300 px-3 py-2 text-sm font-medium text-ink-600 transition-all duration-300 hover:border-firol-400 hover:text-firol-600 active:scale-[0.99]">
            <Plus className="size-4" />
            Pridať pracovisko
          </button>
          <FieldError>{errors.rows}</FieldError>
        </div>

        <DefectsEditor state={defects} initialPhotos={initialItem?.photos} title="Zistené nedostatky a opatrenia" />

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

function OsameleItemRow({ inspectionId, index, item, canEdit, deleting, onDelete }: ItemRowProps) {
  const f = item.fields;
  const rows = Array.isArray(f.rows) ? (f.rows as Array<Record<string, unknown>>) : [];
  const overall = isOverall(f.overall) ? f.overall : null;
  const nDefects = defectCount(f);
  const names = rows.map((r) => str(r?.workplace)).filter(Boolean);
  return (
    <RecordRow
      inspectionId={inspectionId}
      itemId={item.id}
      index={index}
      canEdit={canEdit}
      deleting={deleting}
      onDelete={onDelete}
      title={names.join(', ') || 'Kontrola osamelých pracovísk'}
      badges={overall
        ? <Badge tone={overall === 'vyhovujuci' ? 'ok' : overall === 'nevyhovujuci' ? 'bad' : 'warn'}>{OVERALL_LABELS[overall]}</Badge>
        : <Badge tone="warn">Chýba hodnotenie</Badge>}
      lines={
        <>
          <p className="mt-0.5 text-xs text-ink-500">{plural(rows.length, 'pracovisko', 'pracoviská', 'pracovísk')}</p>
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

export const osamelePracoviskoModule: InspectionTypeModule = {
  type: 'osamele_pracovisko',
  Step2Form: OsameleStep2Form,
  ItemRow: OsameleItemRow,
  StatsBar: ({ items }) => <OverallStatsBar items={items} listKey="rows" />,
  singleItem: true,
};
