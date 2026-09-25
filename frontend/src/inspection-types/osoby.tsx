/**
 * The „osoby" úkony of block 2 — dychová skúška, kontrola omamných látok and
 * oboznámenie BOZP (chapters 7, 8, 8.1). Registered through the usual module
 * contract (common.ts), but typing the list happens on its own screen,
 * `pages/PersonsFillPage` (/inspections/:id/osoby): a shift of twenty people
 * is not entered through twenty separate forms.
 *
 * What lives here:
 *   Step2Form     one person in full — the same fields as the list row plus
 *                 the shared „Zistené nedostatky" block with photos, which is
 *                 too big for a row (reached from the row's „Nedostatok" link)
 *   ItemRow       the person on the Step 3 summary
 *   StatsBar      counts by result (tests) or participants (oboznámenie)
 *   DetailsBlock  the header on the summary — device / test kit / druh and
 *                 obsah oboznámenia, and the opatrenia of a test
 */
import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AlertTriangle, Briefcase, Clock, Edit2, ListChecks, PenLine, Save, Trash2, User } from 'lucide-react';
import {
  PERSON_RESULT_LABELS,
  PERSON_RESULT_SHORT,
  personFields,
  skDate,
  type PersonFields,
  type PersonListType,
  type PersonResult,
} from '@/api/personList';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { DefectsEditor, defectCount, useDefects } from '@/components/DefectsEditor';
import { SignatureCapture } from '@/components/persons/SignatureCapture';
import { PersonDetailsCard } from '@/components/persons/PersonHeaderFields';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import { StatsGrid } from './bozpShared';
import type {
  DetailsBlockProps,
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

const RESULT_TONE: Record<PersonResult, 'ok' | 'bad' | 'warn'> = {
  negativny: 'ok',
  pozitivny: 'bad',
  odmietol: 'warn',
};

function makeStep2Form(type: PersonListType) {
  return function PersonStep2Form({ inspectionId, initialItem, csrfToken }: Step2FormProps) {
    const navigate = useNavigate();
    const toast = useToast();
    const [f, setF] = useState<PersonFields>({ name: '', position: '' });
    const [errors, setErrors] = useState<{ name?: string; position?: string }>({});
    const [apiError, setApiError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const defects = useDefects(initialItem);
    const isTest = type !== 'skolenie_bozp';

    useEffect(() => {
      setF(initialItem ? personFields(initialItem) : { name: '', position: '' });
    }, [initialItem]);

    async function submit(e: FormEvent | React.SyntheticEvent) {
      e.preventDefault();
      if (submitting) return;
      const errs: typeof errors = {};
      if (!f.name.trim()) errs.name = 'Doplň meno a priezvisko.';
      if (!f.position.trim()) errs.position = 'Doplň pracovné zaradenie.';
      if (Object.keys(errs).length > 0) { setErrors(errs); return; }
      const collected = defects.collect();
      if (!collected) return;
      setErrors({});
      setApiError(null);
      setSubmitting(true);
      try {
        const fields: PersonFields = {
          name: f.name.trim(),
          position: f.position.trim(),
          signature: f.signature ?? null,
          defects: collected.defects,
          ...(isTest
            ? {
                time: f.time || null,
                result: f.result ?? null,
                ...(type === 'dychova_skuska' ? { value: (f.value ?? '').trim() || null } : {}),
              }
            : { date: f.date || null }),
        };
        const itemId = initialItem?.id ?? null;
        const saved = await saveItemWithPhotos({
          inspectionId, itemId, fields, csrfToken, photos: collected.photos,
        });
        collected.discardRemovedPhotos(inspectionId, itemId, csrfToken);
        toast.success(saveItemMessage(saved, 'Osoba uložená'));
        navigate(`/inspections/${inspectionId}/osoby`, { replace: true });
      } catch (err) {
        setApiError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
      } finally {
        setSubmitting(false);
      }
    }

    return (
      <Card className="p-5">
        <form className="flex flex-col gap-4" noValidate onSubmit={submit}>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Meno a priezvisko" required error={errors.name}>
              {(p) => (
                <Input {...p} leftIcon={<User className="size-4" />} value={f.name} autoComplete="off"
                  onChange={(e) => setF({ ...f, name: e.target.value })} />
              )}
            </Field>
            <Field label="Pracovné zaradenie" required error={errors.position}>
              {(p) => (
                <Input {...p} leftIcon={<Briefcase className="size-4" />} value={f.position} autoComplete="off"
                  onChange={(e) => setF({ ...f, position: e.target.value })} />
              )}
            </Field>
          </div>

          {isTest ? (
            <>
              <div className="grid grid-cols-2 gap-4">
                <Field label="Čas">
                  {(p) => (
                    <Input {...p} type="time" leftIcon={<Clock className="size-4" />} value={f.time ?? ''}
                      onChange={(e) => setF({ ...f, time: e.target.value })} />
                  )}
                </Field>
                {type === 'dychova_skuska' && (
                  <Field label="Nameraná hodnota">
                    {(p) => (
                      <Input {...p} inputMode="decimal" placeholder="0,00" suffix="‰" value={f.value ?? ''}
                        onChange={(e) => setF({ ...f, value: e.target.value })} />
                    )}
                  </Field>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">Výsledok</p>
                <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Výsledok">
                  {(Object.keys(PERSON_RESULT_SHORT) as PersonResult[]).map((r) => {
                    const active = f.result === r;
                    return (
                      <button key={r} type="button" role="radio" aria-checked={active}
                        onClick={() => setF({ ...f, result: active ? null : r })}
                        className={cn(
                          'min-h-12 rounded-xl border px-2 text-sm font-semibold transition-all duration-200 active:scale-[0.98]',
                          active
                            ? r === 'negativny'
                              ? 'border-status-ok bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]'
                              : r === 'pozitivny'
                                ? 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]'
                                : 'border-status-warn bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]'
                            : 'border-ink-200 bg-white text-ink-700 hover:border-ink-300',
                        )}>
                        {PERSON_RESULT_SHORT[r]}
                      </button>
                    );
                  })}
                </div>
              </div>
            </>
          ) : (
            <Field label="Dátum" hint="Prázdne = dátum oboznámenia.">
              {(p) => (
                <Input {...p} type="date" value={f.date ?? ''} onChange={(e) => setF({ ...f, date: e.target.value })} />
              )}
            </Field>
          )}

          <div className="flex flex-col gap-1.5">
            <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-500">
              <PenLine className="size-3.5" />Podpis
            </p>
            <p className="text-xs text-ink-400">Na displeji, alebo nechaj prázdne na podpis perom.</p>
            <SignatureCapture value={f.signature ?? null} name={f.name}
              onChange={(sig) => setF({ ...f, signature: sig })} />
          </div>

          <DefectsEditor state={defects} initialPhotos={initialItem?.photos} />

          {apiError && (
            <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {apiError}
            </div>
          )}
          <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:justify-end">
            <Button type="button" variant="secondary" onClick={() => navigate(`/inspections/${inspectionId}/osoby`)}
              leftIcon={<ListChecks className="size-4" />}>
              Späť na zoznam
            </Button>
            <Button type="submit" loading={submitting} leftIcon={<Save className="size-4" />}>
              Uložiť
            </Button>
          </div>
        </form>
      </Card>
    );
  };
}

function PersonItemRow({ inspectionId, index, item, canEdit, deleting, onDelete }: ItemRowProps) {
  const p = personFields(item);
  const defects = defectCount(item.fields);
  const detail = [
    p.position,
    p.time ?? '',
    p.value ? `${p.value} ‰` : '',
    p.date ? skDate(p.date) : '',
  ].filter(Boolean).join(' · ');
  return (
    <div className="flex items-start gap-3 px-4 py-3">
      <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-sm font-semibold text-firol-700">
        {index}
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <h3 className="min-w-0 truncate text-sm font-semibold text-ink-900">{p.name}</h3>
          {p.result && <Badge tone={RESULT_TONE[p.result]}>{PERSON_RESULT_LABELS[p.result]}</Badge>}
          {p.signature && <Badge tone="neutral"><PenLine className="size-3" />podpísané</Badge>}
        </div>
        {detail && <p className="mt-0.5 truncate text-xs text-ink-500">{detail}</p>}
        {defects > 0 && (
          <p className="mt-1 flex items-center gap-1 text-xs font-medium text-status-bad">
            <AlertTriangle className="size-3" />
            {defects === 1 ? '1 nedostatok' : defects < 5 ? `${defects} nedostatky` : `${defects} nedostatkov`}
          </p>
        )}
      </div>
      {canEdit && (
        <div className="flex shrink-0 items-center gap-3.5">
          <Link to={`/inspections/${inspectionId}/items/${item.id}`} aria-label="Opraviť"
            className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]">
            <Edit2 className="size-4" />
          </Link>
          <button type="button" onClick={onDelete} disabled={deleting} aria-label="Zmazať"
            className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)] disabled:opacity-50">
            {deleting ? <Spinner size="sm" /> : <Trash2 className="size-4" />}
          </button>
        </div>
      )}
    </div>
  );
}

function makeStatsBar(type: PersonListType) {
  return function PersonStatsBar({ items }: StatsBarProps) {
    if (type === 'skolenie_bozp') {
      const signed = items.filter((it) => personFields(it).signature).length;
      return (
        <StatsGrid total={items.length} cells={[
          { label: 'Účastníci', value: items.length, tone: 'neutral' },
          { label: 'Podpísaní na displeji', value: signed, tone: 'ok' },
        ]} />
      );
    }
    const count = (r: PersonResult | null) => items.filter((it) => personFields(it).result === r).length;
    const missing = count(null);
    return (
      <StatsGrid total={items.length} cells={[
        { label: 'Negatívny', value: count('negativny'), tone: 'ok' },
        { label: 'Pozitívny', value: count('pozitivny'), tone: 'bad' },
        { label: missing > 0 ? 'Bez výsledku' : 'Odmietol', value: missing > 0 ? missing : count('odmietol'), tone: missing > 0 ? 'neutral' : 'warn' },
      ]} />
    );
  };
}

function makeDetailsBlock(type: PersonListType) {
  return function PersonDetailsBlock(props: DetailsBlockProps) {
    return <PersonDetailsCard type={type} {...props} />;
  };
}

function makeModule(type: PersonListType): InspectionTypeModule {
  return {
    type,
    Step2Form: makeStep2Form(type),
    ItemRow: PersonItemRow,
    StatsBar: makeStatsBar(type),
    DetailsBlock: makeDetailsBlock(type),
  };
}

export const dychovaSkuskaModule = makeModule('dychova_skuska');
export const omamneLatkyModule = makeModule('omamne_latky');
export const skolenieBozpModule = makeModule('skolenie_bozp');

