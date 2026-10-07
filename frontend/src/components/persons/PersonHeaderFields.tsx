import { useEffect, useState } from 'react';
import { BookOpenText, Hash, Save, ShieldCheck, TestTube, Timer, Wind } from 'lucide-react';
import { Inspections } from '@/api/inspections';
import {
  CONTENT_REFERENCES,
  TRAINING_KINDS,
  type PersonListType,
  type TestDetails,
  type TrainingDetails,
  type TrainingKind,
} from '@/api/personList';
import { ApiError } from '@/lib/api';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';

export type HeaderValue = Record<string, unknown>;

function s(v: HeaderValue, key: string): string {
  const x = v[key];
  return typeof x === 'string' ? x : typeof x === 'number' ? String(x) : '';
}

/**
 * The header of a person list — what sits above the names (chapter 7):
 *
 *   dychová skúška  Použitý prostriedok *: typ prístroja, výrobné číslo,
 *                   platnosť kalibrácie
 *   omamné látky    Použitý prostriedok *: typ testu, šarža, exspirácia
 *   oboznámenie     Druh oboznámenia *, Časový rozsah, Obsah oboznámenia *
 *
 * Controlled: `onChange` on every keystroke, `onCommit` when a field is left
 * (blur, or a pick from a list) — the caller saves then.
 */
export function PersonHeaderFields({
  type,
  value,
  onChange,
  onCommit,
  disabled,
}: {
  type: PersonListType;
  value: HeaderValue;
  onChange: (next: HeaderValue) => void;
  onCommit: (next: HeaderValue) => void;
  disabled?: boolean;
}) {
  const set = (key: string, v: unknown) => onChange({ ...value, [key]: v });
  const commit = () => onCommit(value);

  if (type === 'skolenie_bozp') {
    return <TrainingHeader value={value} set={set} onCommit={onCommit} commit={commit} disabled={disabled} />;
  }

  const isAlcohol = type === 'dychova_skuska';
  return (
    <div className="flex flex-col gap-3">
      <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-ink-500">
        {isAlcohol ? <Wind className="size-3.5" /> : <TestTube className="size-3.5" />}
        Použitý prostriedok <span className="text-firol-500">*</span>
      </p>
      <Field label={isAlcohol ? 'Typ prístroja' : 'Typ testu'}>
        {(p) => (
          <Input {...p} value={s(value, isAlcohol ? 'device_type' : 'test_type')} disabled={disabled}
            onChange={(e) => set(isAlcohol ? 'device_type' : 'test_type', e.target.value)} onBlur={commit}
            placeholder={isAlcohol ? 'napr. Dräger Alcotest 6820' : 'napr. test zo slín, 6 látok'}
            enterKeyHint="next" />
        )}
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label={isAlcohol ? 'Výrobné číslo' : 'Šarža'}>
          {(p) => (
            <Input {...p} value={s(value, isAlcohol ? 'device_serial' : 'batch')} disabled={disabled}
              leftIcon={<Hash className="size-4" />}
              onChange={(e) => set(isAlcohol ? 'device_serial' : 'batch', e.target.value)} onBlur={commit}
              enterKeyHint="next" />
          )}
        </Field>
        <Field label={isAlcohol ? 'Platnosť kalibrácie' : 'Exspirácia'}>
          {(p) => (
            <Input {...p} type={isAlcohol ? 'date' : 'month'} disabled={disabled}
              value={s(value, isAlcohol ? 'calibration_valid_to' : 'expiry')}
              onChange={(e) => {
                const next = { ...value, [isAlcohol ? 'calibration_valid_to' : 'expiry']: e.target.value || null };
                onChange(next);
                onCommit(next);
              }} />
          )}
        </Field>
      </div>
    </div>
  );
}

function TrainingHeader({
  value,
  set,
  onCommit,
  commit,
  disabled,
}: {
  value: HeaderValue;
  set: (key: string, v: unknown) => void;
  onCommit: (next: HeaderValue) => void;
  commit: () => void;
  disabled?: boolean;
}) {
  const kind = s(value, 'kind') as TrainingKind | '';
  const content = s(value, 'content');
  const isCustomContent = content !== '' && !CONTENT_REFERENCES.includes(content);
  const [customOpen, setCustomOpen] = useState(isCustomContent);
  useEffect(() => { if (isCustomContent) setCustomOpen(true); }, [isCustomContent]);

  return (
    <div className="flex flex-col gap-3">
      <Field label="Druh oboznámenia" required>
        {(p) => (
          <Select id={p.id} aria-invalid={p['aria-invalid']} disabled={disabled}
            value={kind}
            placeholder="— vyber druh —"
            leftIcon={<ShieldCheck className="size-4" />}
            options={TRAINING_KINDS.map((k) => ({ value: k.value, label: k.label }))}
            onChange={(v) => {
              const next = { ...value, kind: v || null, kind_custom: v === 'vlastne' ? value.kind_custom ?? null : null };
              onCommit(next);
            }} />
        )}
      </Field>
      {kind === 'vlastne' && (
        <Field label="Vlastný názov" required>
          {(p) => (
            <Input {...p} value={s(value, 'kind_custom')} disabled={disabled}
              onChange={(e) => set('kind_custom', e.target.value)} onBlur={commit} />
          )}
        </Field>
      )}
      <Field label="Časový rozsah" hint="v minútach">
        {(p) => (
          <Input {...p} type="number" inputMode="numeric" min={1} disabled={disabled}
            leftIcon={<Timer className="size-4" />} suffix="min"
            value={s(value, 'duration_min')}
            onChange={(e) => set('duration_min', e.target.value === '' ? null : Number(e.target.value))}
            onBlur={commit} />
        )}
      </Field>
      <div className="flex flex-col gap-1.5">
        <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-500">
          <BookOpenText className="size-3.5" />
          Obsah oboznámenia <span className="text-firol-500">*</span>
        </p>
        <p className="text-xs text-ink-400">
          Odkaz na osnovu — protokol neobsahuje tematický plán.
        </p>
        <div className="flex flex-col gap-2" role="radiogroup" aria-label="Obsah oboznámenia">
          {CONTENT_REFERENCES.map((ref) => {
            const active = !customOpen && content === ref;
            return (
              <button key={ref} type="button" role="radio" aria-checked={active} disabled={disabled}
                onClick={() => { setCustomOpen(false); onCommit({ ...value, content: ref }); }}
                className={cn(
                  'min-h-12 rounded-xl border px-3 py-2.5 text-left text-sm transition-all duration-200 active:scale-[0.99]',
                  active
                    ? 'border-firol-400 bg-firol-50 font-medium text-firol-800'
                    : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300',
                )}>
                {ref}
              </button>
            );
          })}
          <button type="button" role="radio" aria-checked={customOpen} disabled={disabled}
            onClick={() => { setCustomOpen(true); if (!isCustomContent) set('content', ''); }}
            className={cn(
              'min-h-12 rounded-xl border px-3 py-2.5 text-left text-sm transition-all duration-200 active:scale-[0.99]',
              customOpen
                ? 'border-firol-400 bg-firol-50 font-medium text-firol-800'
                : 'border-ink-200 bg-white text-ink-700 hover:border-firol-300',
            )}>
            Vlastný text
          </button>
          {customOpen && (
            <textarea rows={3} value={isCustomContent || content === '' ? content : ''} disabled={disabled}
              onChange={(e) => set('content', e.target.value)} onBlur={commit}
              placeholder="Odkaz na osnovu vlastnými slovami"
              className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
          )}
        </div>
      </div>
    </div>
  );
}

/** "2026-08" → "8/2026"; an empty or unrecognised value is returned as is. */
function formatMonthSk(month: string): string {
  const m = /^(\d{4})-(\d{2})$/.exec(month);
  return m ? `${Number(m[2])}/${m[1]}` : month;
}

/** One-line summary of the header for a locked úkon. */
export function headerSummary(type: PersonListType, value: HeaderValue): { label: string; text: string }[] {
  if (type === 'skolenie_bozp') {
    const d = value as TrainingDetails;
    const kind = d.kind === 'vlastne' ? d.kind_custom ?? '' : TRAINING_KINDS.find((k) => k.value === d.kind)?.label ?? '';
    return [
      { label: 'Druh oboznámenia', text: kind },
      { label: 'Časový rozsah', text: d.duration_min ? `${d.duration_min} minút` : '' },
      { label: 'Obsah oboznámenia', text: d.content ?? '' },
    ].filter((r) => r.text !== '');
  }
  const d = value as TestDetails;
  const isAlcohol = type === 'dychova_skuska';
  return [
    { label: isAlcohol ? 'Prístroj' : 'Použitý prostriedok', text: (isAlcohol ? d.device_type : d.test_type) ?? '' },
    { label: isAlcohol ? 'Výrobné číslo' : 'Šarža', text: (isAlcohol ? d.device_serial : d.batch) ?? '' },
    { label: isAlcohol ? 'Platnosť kalibrácie' : 'Exspirácia', text: isAlcohol ? (d.calibration_valid_to ? formatDateSk(d.calibration_valid_to) : '') : formatMonthSk(d.expiry ?? '') },
    { label: 'Opatrenia', text: d.measures ?? '' },
  ].filter((r) => r.text !== '');
}

/**
 * Save the header. Offline, the write waits in the outbox and the local copy
 * stands (the same as every other field of a draft).
 */
export async function saveHeader(
  inspectionId: number,
  details: HeaderValue,
  csrfToken: string | null,
  toast: { success: (m: string) => void },
): Promise<void> {
  try {
    await Inspections.update(inspectionId, { details }, csrfToken);
  } catch (err) {
    if (handleOfflineSave(err, toast)) return;
    throw err;
  }
}

/**
 * The header on the Step 3 summary (module `DetailsBlock`): editable with a
 * save button while the úkon is a draft, read-only afterwards. Opatrenia of a
 * test are edited here too — chapter 7 fills them in at a positive result or a
 * refusal, which is often only clear once the whole list is through.
 */
export function PersonDetailsCard({
  type,
  inspectionId,
  details,
  canEdit,
  csrfToken,
  onSaved,
}: {
  type: PersonListType;
  inspectionId: number;
  details: Record<string, unknown> | null;
  canEdit: boolean;
  csrfToken: string | null;
  onSaved: (details: Record<string, unknown>) => void;
}) {
  const saved = details ?? {};
  const savedKey = JSON.stringify(saved);
  const [value, setValue] = useState<HeaderValue>(saved);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { setValue(details ?? {}); }, [savedKey]);

  const title = type === 'skolenie_bozp' ? 'Oboznámenie' : 'Použitý prostriedok';

  if (!canEdit) {
    const rows = headerSummary(type, saved);
    if (rows.length === 0) return null;
    return (
      <Card className="flex flex-col gap-2 p-4">
        <p className="text-xs font-semibold uppercase tracking-wider text-ink-500">{title}</p>
        {rows.map((r) => (
          <div key={r.label}>
            <p className="text-[11px] uppercase tracking-wide text-ink-400">{r.label}</p>
            <p className="whitespace-pre-line text-sm text-ink-800">{r.text}</p>
          </div>
        ))}
      </Card>
    );
  }

  const dirty = JSON.stringify(value) !== savedKey;

  async function persist(next: HeaderValue) {
    setBusy(true);
    setError(null);
    try {
      await saveHeader(inspectionId, next, csrfToken, toast);
      onSaved(next);
      toast.success('Uložené');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Uloženie sa nepodarilo.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="flex flex-col gap-4 p-4">
      <PersonHeaderFields type={type} value={value} onChange={setValue}
        onCommit={(next) => setValue(next)} disabled={busy} />
      {type !== 'skolenie_bozp' && (
        <Field label="Opatrenia" hint="Vypĺňa sa pri pozitívnom výsledku alebo odmietnutí.">
          {(p) => (
            <textarea id={p.id} rows={3} value={s(value, 'measures')} disabled={busy}
              onChange={(e) => setValue({ ...value, measures: e.target.value })}
              className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
          )}
        </Field>
      )}
      {error && <p className="text-xs text-status-bad">{error}</p>}
      {dirty && (
        <Button type="button" className="self-end" loading={busy} onClick={() => persist(value)}
          leftIcon={<Save className="size-4" />}>
          Uložiť
        </Button>
      )}
    </Card>
  );
}
