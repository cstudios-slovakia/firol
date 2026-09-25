import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  AlertTriangle, ArrowLeft, ArrowRight, Building2, CalendarDays, CopyPlus,
  Lock, Plus, Trash2, UserPlus, Users, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  type InspectionDetail,
  type InspectionItem,
  type InspectionType,
} from '@/api/inspections';
import {
  PERSON_RESULT_SHORT,
  PersonList,
  isPersonListType,
  isTestType,
  peopleAccusative,
  personFields,
  skDate,
  summaryBlockers,
  type PersonFields,
  type PersonListType,
  type PersonResult,
  type PersonSource,
} from '@/api/personList';
import { TRAINING_TYPE_LABELS, type TrainingType } from '@/api/trainings';
import { ApiError } from '@/lib/api';
import { handleOfflineSave, offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { useConfirm } from '@/lib/confirm';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { CardBlockSkeleton, DetailHeaderSkeleton } from '@/components/ui/Skeleton';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { PersonHeaderFields, saveHeader, type HeaderValue } from '@/components/persons/PersonHeaderFields';
import { SignatureCapture } from '@/components/persons/SignatureCapture';

/**
 * Step 2 of a dychová skúška, kontrola omamných látok or oboznámenie BOZP —
 * the person list, on one screen (chapters 7, 8, 8.1; walkthrough 29.3).
 *
 * Built for a shift of twenty people and one hand: every person is a row
 * typed in place (no dialog per person), Enter on the phone keyboard walks to
 * the next field and, from the last field of the new row, adds the person and
 * starts the next one. The names of the company's previous test or training
 * are offered at the top — „Prevziať 8 osôb z minulej skúšky (14. 5. 2026)?".
 *
 * Results are optional here on purpose: the blank form for handwriting
 * (chapter 8.1) is printed from exactly the list of names, and the results
 * can be typed in afterwards. What is required on the way to the summary is
 * the list itself — every person with a name and a pracovné zaradenie — and,
 * for an oboznámenie, its druh and obsah.
 */
export function PersonsFillPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const { csrfToken } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const [data, setData] = useState<InspectionDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [header, setHeader] = useState<HeaderValue>({});
  const [headerError, setHeaderError] = useState<string | null>(null);
  const [blockers, setBlockers] = useState<string[]>([]);
  const listRef = useRef<HTMLDivElement | null>(null);

  async function reload() {
    const detail = await Inspections.show(id);
    setData(detail);
    setHeader(detail.inspection.details ?? {});
    return detail;
  }

  useEffect(() => {
    let cancelled = false;
    Inspections.show(id)
      .then((detail) => {
        if (cancelled) return;
        setData(detail);
        setHeader(detail.inspection.details ?? {});
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať úkon.');
      });
    return () => { cancelled = true; };
  }, [id]);

  const items = data?.items ?? [];
  const positions = useMemo(
    () => Array.from(new Set(items.map((it) => personFields(it).position).filter((p) => p.trim() !== ''))).sort(),
    [items],
  );

  if (error && !data) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink to="/bozp" />
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }
  if (!data) {
    return (
      <div className="flex flex-col gap-5">
        <BackLink to="/bozp" />
        <DetailHeaderSkeleton />
        <CardBlockSkeleton rows={4} />
      </div>
    );
  }

  const i = data.inspection;
  if (!isPersonListType(i.type)) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink to={`/inspections/${id}`} />
        <Card className="px-4 py-3 text-sm text-ink-600">Tento úkon nemá zoznam osôb.</Card>
      </div>
    );
  }
  const type: PersonListType = i.type;
  const isDraft = i.status === 'draft';
  const isTest = isTestType(type);
  const who = type === 'skolenie_bozp' ? 'účastník' : 'osoba';
  const anyAlarm = items.some((it) => {
    const r = personFields(it).result;
    return r === 'pozitivny' || r === 'odmietol';
  });

  async function commitHeader(next: HeaderValue) {
    setHeader(next);
    if (JSON.stringify(next) === JSON.stringify(data?.inspection.details ?? {})) return;
    setHeaderError(null);
    try {
      await saveHeader(id, next, csrfToken, toast);
      setData((prev) => (prev ? { ...prev, inspection: { ...prev.inspection, details: next } } : prev));
    } catch (err) {
      setHeaderError(err instanceof ApiError ? err.message : 'Uloženie sa nepodarilo.');
    }
  }

  function replaceItem(item: InspectionItem) {
    setData((prev) => (prev ? { ...prev, items: prev.items.map((it) => (it.id === item.id ? item : it)) } : prev));
  }

  function goToSummary() {
    const b = summaryBlockers(type, header, items);
    setBlockers(b);
    if (b.length === 0) navigate(`/inspections/${id}`);
  }

  return (
    <div className="flex flex-col gap-5">
      <BackLink to={`/inspections/${id}`} label="Súhrn" />
      <PendingSyncBanner resource="inspections" id={id} />

      <header className="min-w-0">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
          Krok 2 · {type === 'skolenie_bozp' ? 'účastníci' : 'zoznam osôb'}
        </p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">{INSPECTION_TYPE_LABELS[i.type]}</h1>
        <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-500">
          <Building2 className="size-3" />{i.company_name}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />{i.facility_name}
          {i.executed_on && (<><span className="text-ink-300">·</span><CalendarDays className="size-3" />{skDate(i.executed_on)}</>)}
        </p>
      </header>

      {!isDraft && (
        <Card className="flex items-start gap-3 bg-ink-50 px-4 py-3">
          <Lock className="mt-0.5 size-4 shrink-0 text-ink-400" />
          <p className="text-xs text-ink-600">
            Úkon je uzamknutý — má vystavený protokol. Zoznam sa už nedá meniť.{' '}
            <Link to={`/inspections/${id}`} className="font-medium text-firol-600">Späť na súhrn</Link>
          </p>
        </Card>
      )}

      <Card className="flex flex-col gap-3 p-4">
        <PersonHeaderFields type={type} value={header} onChange={setHeader} onCommit={commitHeader} disabled={!isDraft} />
        {headerError && <p className="text-xs text-status-bad">{headerError}</p>}
      </Card>

      {/* Offered while the list is being put together; once results are being
          entered (e.g. typed in from the paper form) it is only noise. */}
      {isDraft && !items.some((it) => personFields(it).result !== null) && (
        <TakeOverOffer inspectionId={id} type={type} empty={items.length === 0}
          csrfToken={csrfToken} onTaken={async (added) => {
            await reload().catch(() => undefined);
            toast.success(added > 0 ? `Prevzaté: ${peopleAccusative(added)} — len mená a zaradenia.` : 'Všetky osoby už v zozname sú.');
          }} />
      )}

      <div ref={listRef} className="flex flex-col gap-3">
        <div className="flex items-center justify-between px-1">
          <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-ink-500">
            <Users className="size-3.5" />
            {type === 'skolenie_bozp' ? 'Účastníci' : 'Zoznam osôb'}
          </p>
          <span className="text-xs text-ink-500">spolu {items.length}</span>
        </div>

        {items.map((it, idx) => (
          <PersonRow key={it.id} type={type} inspectionId={id} index={idx + 1} item={it}
            executedOn={i.executed_on} canEdit={isDraft} csrfToken={csrfToken}
            onSaved={replaceItem}
            onDeleted={() => setData((prev) => (prev ? { ...prev, items: prev.items.filter((x) => x.id !== it.id) } : prev))} />
        ))}

        {isDraft && (
          <NewPersonRow type={type} inspectionId={id} csrfToken={csrfToken}
            label={who === 'účastník' ? 'Nový účastník' : 'Nová osoba'}
            onAdded={(item) => setData((prev) => (prev ? { ...prev, items: [...prev.items, item] } : prev))}
            onQueued={() => reload().catch(() => undefined)} />
        )}
      </div>

      {isTest && (anyAlarm || String(header.measures ?? '').trim() !== '') && (
        <Card className="flex flex-col gap-2 border-status-warn/30 p-4">
          <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-status-warn">
            <AlertTriangle className="size-3.5" />
            Opatrenia
          </p>
          <p className="text-xs text-ink-500">Pri pozitívnom výsledku alebo odmietnutí — čo sa s osobou stalo.</p>
          <textarea rows={3} disabled={!isDraft} value={String(header.measures ?? '')}
            onChange={(e) => setHeader({ ...header, measures: e.target.value })}
            onBlur={() => commitHeader({ ...header, measures: String(header.measures ?? '').trim() || null })}
            className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200" />
        </Card>
      )}

      {/* One list of the pracovné zaradenia already typed — a shift mostly
          shares a handful of them, so the second „skladník" is two letters. */}
      <datalist id="person-positions">
        {positions.map((p) => <option key={p} value={p} />)}
      </datalist>

      {blockers.length > 0 && (
        <Card className="flex flex-col gap-1 border-status-bad/30 bg-[var(--color-status-bad-bg)] px-4 py-3 text-sm text-[var(--color-status-bad)]">
          {blockers.map((b) => <p key={b}>{b}</p>)}
        </Card>
      )}

      {/* Sticky, not fixed: the app shell animates its content, and a fixed
          bar inside a transformed parent would scroll away with it. */}
      <div className="sticky bottom-3 z-30 rounded-2xl border border-ink-100 bg-white/90 px-4 py-3 shadow-lg backdrop-blur-md">
        <div className="flex items-center justify-between gap-3">
          <p className="text-xs text-ink-500">
            {items.length} {items.length === 1 ? who : type === 'skolenie_bozp' ? 'účastníkov' : 'osôb'}
          </p>
          <Button type="button" onClick={goToSummary} rightIcon={<ArrowRight className="size-4" />}>
            Prejsť na súhrn
          </Button>
        </div>
      </div>
    </div>
  );
}

function BackLink({ to, label = 'Späť' }: { to: string; label?: string }) {
  return (
    <Link to={to} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
      <ArrowLeft className="size-4" />
      {label}
    </Link>
  );
}

/**
 * Enter on the phone keyboard moves to the next field of the list instead of
 * submitting anything — the fastest way through twenty rows with one thumb.
 */
function focusNext(e: KeyboardEvent<HTMLElement>): void {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  const all = Array.from(document.querySelectorAll<HTMLElement>('[data-nav]'));
  const at = all.indexOf(e.currentTarget);
  const next = at >= 0 ? all[at + 1] : undefined;
  next?.focus();
}

function nowHm(): string {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

const RESULT_TONES: Record<PersonResult, string> = {
  negativny: 'border-status-ok bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
  pozitivny: 'border-status-bad bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
  odmietol: 'border-status-warn bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
};

/** Saved fields of a row plus the ones typed since — always the whole row. */
function rowPayload(base: PersonFields, patch: Partial<PersonFields>, type: PersonListType): PersonFields {
  const f = { ...base, ...patch };
  const out: PersonFields = {
    name: f.name.trim(),
    position: f.position.trim(),
    signature: f.signature ?? null,
    // Never dropped by a row save — they are edited on the row's detail form.
    defects: f.defects ?? [],
  };
  if (type === 'skolenie_bozp') {
    out.date = f.date || null;
  } else {
    out.time = f.time || null;
    out.result = f.result ?? null;
    if (type === 'dychova_skuska') out.value = (f.value ?? '').trim() || null;
  }
  return out;
}

function PersonRow({
  type, inspectionId, index, item, executedOn, canEdit, csrfToken, onSaved, onDeleted,
}: {
  type: PersonListType;
  inspectionId: number;
  index: number;
  item: InspectionItem;
  executedOn: string | null;
  canEdit: boolean;
  csrfToken: string | null;
  onSaved: (item: InspectionItem) => void;
  onDeleted: () => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const saved = personFields(item);
  const [f, setF] = useState<PersonFields>(saved);
  const [busy, setBusy] = useState(false);
  const [rowError, setRowError] = useState<string | null>(null);
  const [dateOpen, setDateOpen] = useState(Boolean(saved.date));
  const savedKey = JSON.stringify(saved);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { setF(personFields(item)); }, [savedKey]);

  const isTest = type !== 'skolenie_bozp';
  const defectCount = Array.isArray(item.fields.defects) ? item.fields.defects.length : 0;

  async function save(patch: Partial<PersonFields>) {
    const payload = rowPayload(f, patch, type);
    setF((prev) => ({ ...prev, ...patch }));
    if (payload.name === '' || payload.position === '') {
      setRowError('Meno a pracovné zaradenie sú povinné.');
      return;
    }
    if (JSON.stringify(rowPayload(saved, {}, type)) === JSON.stringify(payload)) {
      setRowError(null);
      return;
    }
    setBusy(true);
    try {
      const res = await Inspections.updateItem(inspectionId, item.id, payload, csrfToken);
      setRowError(null);
      onSaved(res.item);
    } catch (err) {
      if (handleOfflineSave(err, toast)) {
        onSaved({ ...item, fields: { ...item.fields, ...payload } });
        return;
      }
      setRowError(err instanceof ApiError ? err.message : 'Uloženie sa nepodarilo.');
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    const ok = await confirm({
      title: 'Odstrániť osobu zo zoznamu?',
      description: f.name || undefined,
      confirmLabel: 'Odstrániť',
    });
    if (!ok) return;
    try {
      await Inspections.deleteItem(inspectionId, item.id, csrfToken);
      onDeleted();
    } catch (err) {
      if (handleOfflineSave(err, toast)) { onDeleted(); return; }
      toast.error(offlineMessage(err, 'Odstránenie sa nepodarilo.'));
    }
  }

  return (
    <Card className={cn('flex flex-col gap-2.5 p-3 transition-shadow duration-300', rowError && 'ring-2 ring-status-bad/30')}>
      <div className="flex items-start gap-2.5">
        <span className="mt-1.5 grid size-8 shrink-0 place-items-center rounded-xl bg-firol-50 text-sm font-semibold text-firol-700">
          {busy ? <Spinner size="sm" /> : index}
        </span>
        <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-2">
          <Input data-nav aria-label="Meno a priezvisko" placeholder="Meno a priezvisko" value={f.name}
            disabled={!canEdit} autoComplete="off" enterKeyHint="next"
            onChange={(e) => setF({ ...f, name: e.target.value })}
            onBlur={() => save({ name: f.name })} onKeyDown={focusNext} />
          <Input data-nav aria-label="Pracovné zaradenie" placeholder="Pracovné zaradenie" value={f.position}
            disabled={!canEdit} list="person-positions" autoComplete="off" enterKeyHint="next"
            onChange={(e) => setF({ ...f, position: e.target.value })}
            onBlur={() => save({ position: f.position })} onKeyDown={focusNext} />
        </div>
        {canEdit && (
          <button type="button" onClick={remove} aria-label="Odstrániť"
            className="mt-1 grid size-9 shrink-0 place-items-center rounded-xl text-ink-400 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad">
            <Trash2 className="size-4" />
          </button>
        )}
      </div>

      {isTest && (
        <div className="flex flex-col gap-2 pl-0 sm:pl-10">
          <div className="flex gap-2">
            <div className="min-w-0 flex-1">
              <Input data-nav type="time" aria-label="Čas" value={f.time ?? ''} disabled={!canEdit}
                onChange={(e) => setF({ ...f, time: e.target.value })}
                onBlur={() => save({ time: f.time })} onKeyDown={focusNext} />
            </div>
            {type === 'dychova_skuska' && (
              <div className="w-28 shrink-0">
                <Input data-nav inputMode="decimal" aria-label="Nameraná hodnota" placeholder="0,00"
                  suffix="‰" value={f.value ?? ''} disabled={!canEdit} enterKeyHint="next"
                  onChange={(e) => setF({ ...f, value: e.target.value })}
                  onBlur={() => save({ value: f.value })} onKeyDown={focusNext} />
              </div>
            )}
          </div>
          <div className="grid grid-cols-3 gap-1.5" role="radiogroup" aria-label="Výsledok">
            {(Object.keys(PERSON_RESULT_SHORT) as PersonResult[]).map((r) => {
              const active = f.result === r;
              return (
                <button key={r} type="button" role="radio" aria-checked={active} disabled={!canEdit}
                  onClick={() => save(active ? { result: null } : { result: r, ...(f.time ? {} : { time: nowHm() }) })}
                  className={cn(
                    'min-h-11 rounded-xl border px-2 text-sm font-semibold transition-all duration-200 active:scale-[0.97] disabled:opacity-60',
                    active ? RESULT_TONES[r] : 'border-ink-200 bg-white text-ink-600 hover:border-ink-300',
                  )}>
                  {PERSON_RESULT_SHORT[r]}
                </button>
              );
            })}
          </div>
        </div>
      )}

      {type === 'skolenie_bozp' && (
        <div className="pl-0 sm:pl-10">
          {dateOpen ? (
            <Input type="date" aria-label="Dátum" value={f.date ?? ''} disabled={!canEdit}
              onChange={(e) => save({ date: e.target.value || null })} />
          ) : (
            <button type="button" disabled={!canEdit} onClick={() => setDateOpen(true)}
              className="text-xs text-ink-500 transition-colors hover:text-firol-600">
              Dátum: {skDate(executedOn) || 'dátum oboznámenia'} · zmeniť
            </button>
          )}
        </div>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2 pl-0 sm:pl-10">
        <SignatureCapture value={f.signature ?? null} disabled={!canEdit} name={f.name}
          onChange={(sig) => save({ signature: sig })} />
        <Link to={`/inspections/${inspectionId}/items/${item.id}`}
          className={cn(
            'inline-flex min-h-10 items-center gap-1 rounded-xl px-2 text-xs font-medium transition-colors',
            defectCount > 0 ? 'text-status-bad hover:bg-[var(--color-status-bad-bg)]' : 'text-ink-500 hover:bg-ink-50',
          )}>
          <AlertTriangle className="size-3.5" />
          {defectCount > 0 ? `Nedostatky: ${defectCount}` : 'Nedostatok'}
        </Link>
      </div>

      {rowError && <p className="text-xs text-status-bad">{rowError}</p>}
    </Card>
  );
}

function NewPersonRow({
  type, inspectionId, csrfToken, label, onAdded, onQueued,
}: {
  type: PersonListType;
  inspectionId: number;
  csrfToken: string | null;
  label: string;
  onAdded: (item: InspectionItem) => void;
  onQueued: () => void;
}) {
  const toast = useToast();
  const [name, setName] = useState('');
  const [position, setPosition] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const nameRef = useRef<HTMLInputElement | null>(null);
  const positionRef = useRef<HTMLInputElement | null>(null);
  // Adds are sent one after another: the row order on the protocol follows
  // the order people were typed in, and the server's position counter never
  // sees two inserts at once.
  const queueRef = useRef<Promise<void>>(Promise.resolve());

  function add() {
    const n = name.trim();
    const p = position.trim();
    if (n === '') { nameRef.current?.focus(); return; }
    if (p === '') { setErr('Doplň pracovné zaradenie.'); positionRef.current?.focus(); return; }
    setErr(null);
    // Ready for the next person straight away — the technician keeps typing
    // while the request is in flight, and on a weak signal that is seconds.
    // A shift usually shares a pracovné zaradenie, so that one is kept.
    setName('');
    nameRef.current?.focus();
    // A test row starts with the current time — the person is tested as
    // they are added; the field stays editable.
    const payload = rowPayload({ name: n, position: p, defects: [], time: nowHm() }, {}, type);
    queueRef.current = queueRef.current.then(async () => {
      try {
        const res = await Inspections.addItem(inspectionId, payload, csrfToken);
        onAdded(res.item);
      } catch (e) {
        if (handleOfflineSave(e, toast)) {
          onQueued();
        } else {
          setErr(`${n}: ${e instanceof ApiError ? e.message : 'Pridanie sa nepodarilo.'}`);
          setName((cur) => (cur === '' ? n : cur));
        }
      }
    });
  }

  return (
    <Card className="flex flex-col gap-2 border-dashed border-firol-200 bg-firol-50/30 p-3">
      <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-firol-600">
        <UserPlus className="size-3.5" />
        {label}
      </p>
      <div className="grid gap-2 sm:grid-cols-2">
        <Input ref={nameRef} data-nav placeholder="Meno a priezvisko" value={name} autoComplete="off"
          enterKeyHint="next" onChange={(e) => setName(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); positionRef.current?.focus(); } }} />
        <Input ref={positionRef} data-nav placeholder="Pracovné zaradenie" value={position} autoComplete="off"
          list="person-positions" enterKeyHint="done" onChange={(e) => setPosition(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } }} />
      </div>
      {err && <p className="text-xs text-status-bad">{err}</p>}
      <Button type="button" variant="secondary" onClick={add} leftIcon={<Plus className="size-4" />}>
        Pridať
      </Button>
    </Card>
  );
}

/** Wording of a source in the offer — „z minulej skúšky", „zo školenia". */
function sourcePhrase(source: PersonSource, type: PersonListType): string {
  if (source.kind === 'training') {
    const label = TRAINING_TYPE_LABELS[source.type as TrainingType];
    return label ? `zo školenia (${label.toLowerCase()})` : 'zo školenia';
  }
  if (source.type === type) {
    return type === 'skolenie_bozp' ? 'z minulého oboznámenia' : 'z minulej skúšky';
  }
  switch (source.type as InspectionType) {
    case 'dychova_skuska': return 'z dychovej skúšky';
    case 'omamne_latky': return 'z kontroly omamných látok';
    case 'skolenie_bozp': return 'z oboznámenia BOZP';
    default: return 'z minulého zoznamu';
  }
}

/**
 * „Prevziať 8 osôb z minulej skúšky (14. 5. 2026)?" — chapters 7, 8, 8.1.
 * The company's previous list of the same kind first; any other test,
 * oboznámenie or training of the company in the picker below it. Names and
 * pracovné zaradenie only.
 */
function TakeOverOffer({
  inspectionId, type, empty, csrfToken, onTaken,
}: {
  inspectionId: number;
  type: PersonListType;
  empty: boolean;
  csrfToken: string | null;
  onTaken: (added: number) => Promise<void>;
}) {
  const [sources, setSources] = useState<PersonSource[] | null>(null);
  const [picked, setPicked] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    // Online only — offline, the technician types the names; nothing is lost.
    PersonList.sources(inspectionId)
      .then((res) => { if (!cancelled) setSources(res.sources); })
      .catch(() => { if (!cancelled) setSources([]); });
    return () => { cancelled = true; };
  }, [inspectionId]);

  if (done || !sources || sources.length === 0) return null;
  const primary = sources[0];
  const chosen = sources.find((s) => `${s.kind}:${s.id}` === picked) ?? primary;

  async function take(source: PersonSource) {
    setBusy(true);
    setErr(null);
    try {
      const res = await PersonList.takeOver(inspectionId, source, csrfToken);
      setDone(true);
      await onTaken(res.added);
    } catch (e) {
      setErr(offlineMessage(e, 'Prevzatie sa nepodarilo.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className={cn('flex flex-col gap-3 p-4', empty ? 'border-firol-200 bg-firol-50/60' : 'bg-white')}>
      <div className="flex items-start gap-3">
        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-white text-firol-600">
          <CopyPlus className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-ink-900">
            Prevziať {peopleAccusative(chosen.people)} {sourcePhrase(chosen, type)}
            {chosen.date ? ` (${skDate(chosen.date)})` : ''}?
          </p>
          <p className="mt-1 text-xs text-ink-600">
            Prenesú sa len mená a pracovné zaradenia — výsledky ani podpisy nie.
            {!empty && ' Kto už v zozname je, sa nepridá druhýkrát.'}
          </p>
        </div>
      </div>
      {sources.length > 1 && (
        <Select value={picked || `${primary.kind}:${primary.id}`} onChange={setPicked}
          options={sources.map((s) => ({
            value: `${s.kind}:${s.id}`,
            label: `${s.date ? skDate(s.date) : 'bez dátumu'} — ${sourcePhrase(s, type).replace(/^zo? /, '')}`,
            description: `${s.people} ${s.people === 1 ? 'osoba' : s.people < 5 ? 'osoby' : 'osôb'}${s.facility_name ? ` · ${s.facility_name}` : ''}`,
          }))} />
      )}
      {err && <p className="text-xs text-status-bad">{err}</p>}
      <Button type="button" className="self-start" loading={busy} onClick={() => take(chosen)}
        leftIcon={<CopyPlus className="size-4" />}>
        Prevziať
      </Button>
    </Card>
  );
}
