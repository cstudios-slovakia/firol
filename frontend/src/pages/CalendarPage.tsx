import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import {
  AlertTriangle, CalendarDays, CalendarPlus, ChevronLeft, ChevronRight,
  Pencil, Plus, Trash2, X,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  Calendar,
  type CalendarDeadline,
  type CalendarEvent,
  type CalendarEventInput,
} from '@/api/calendar';
import { INSPECTION_TYPE_LABELS } from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { useConfirm } from '@/lib/confirm';
import {
  companiesForDay,
  effectiveDate,
  groupByFacility,
  type FacilityDeadlineGroup,
} from '@/lib/calendarGrouping';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Spinner } from '@/components/ui/Spinner';

const WEEKDAYS = ['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'];
const MONTHS = [
  'Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún',
  'Júl', 'August', 'September', 'Október', 'November', 'December',
];

function iso(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function todayIso(): string {
  return iso(new Date());
}
/** `?den=YYYY-MM-DD` deep link (from the dashboard "Termíny" block). */
function dayFromParam(value: string | null): string {
  return value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : todayIso();
}

/**
 * Calendar (change request 2.5). Monthly view that projects computed statutory
 * deadlines (grouped one-per-facility per day) and custom events. Selecting a
 * day opens its agenda where the technician can set a planned visit date on a
 * deadline or manage custom events. The optional client-email button (2.5.4)
 * is intentionally left out of this first version.
 */
export function CalendarPage() {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();

  const [deadlines, setDeadlines] = useState<CalendarDeadline[]>([]);
  const [events, setEvents] = useState<CalendarEvent[]>([]);
  const [loaded, setLoaded] = useState(false);

  // Open on the day passed in the URL (deep link from the dashboard), else today.
  const [searchParams] = useSearchParams();
  const dayParam = searchParams.get('den');
  const initialDay = dayFromParam(dayParam);
  const initialDate = new Date(initialDay + 'T00:00:00');

  const [viewYear, setViewYear] = useState(initialDate.getFullYear());
  const [viewMonth, setViewMonth] = useState(initialDate.getMonth());
  const [selected, setSelected] = useState<string>(initialDay);
  const [showEventForm, setShowEventForm] = useState(false);
  const [editingEvent, setEditingEvent] = useState<CalendarEvent | null>(null);

  async function reload() {
    try {
      const data = await Calendar.get();
      setDeadlines(data.deadlines);
      setEvents(data.events);
    } catch {
      // Non-blocking: keep whatever we had.
    } finally {
      setLoaded(true);
    }
  }
  useEffect(() => { void reload(); }, []);

  // Follow the URL when it changes while the page stays mounted.
  useEffect(() => {
    if (!dayParam) return;
    const day = dayFromParam(dayParam);
    const d = new Date(day + 'T00:00:00');
    setViewYear(d.getFullYear());
    setViewMonth(d.getMonth());
    setSelected(day);
  }, [dayParam]);

  // Index deadlines (by their effective day) and events by ISO day.
  const byDay = useMemo(() => {
    const m = new Map<string, { deadlines: CalendarDeadline[]; events: CalendarEvent[] }>();
    const bucket = (day: string) => {
      let b = m.get(day);
      if (!b) { b = { deadlines: [], events: [] }; m.set(day, b); }
      return b;
    };
    for (const d of deadlines) bucket(effectiveDate(d)).deadlines.push(d);
    for (const e of events) bucket(e.event_date).events.push(e);
    return m;
  }, [deadlines, events]);

  const weeks = useMemo(() => buildMonthGrid(viewYear, viewMonth), [viewYear, viewMonth]);

  // Mobile agenda: the days of the viewed month that carry anything, ascending.
  const monthDays = useMemo(() => {
    const rows: { day: string; deadlines: CalendarDeadline[]; events: CalendarEvent[] }[] = [];
    for (const [day, info] of byDay) {
      const d = new Date(day + 'T00:00:00');
      if (d.getFullYear() !== viewYear || d.getMonth() !== viewMonth) continue;
      rows.push({ day, deadlines: info.deadlines, events: info.events });
    }
    return rows.sort((a, b) => a.day.localeCompare(b.day));
  }, [byDay, viewYear, viewMonth]);

  function shiftMonth(delta: number) {
    const d = new Date(viewYear, viewMonth + delta, 1);
    setViewYear(d.getFullYear());
    setViewMonth(d.getMonth());
  }

  const selectedGroups = groupByFacility(byDay.get(selected)?.deadlines ?? []);
  const selectedEvents = byDay.get(selected)?.events ?? [];

  async function handleDeleteEvent(id: number) {
    const ok = await confirm({
      title: 'Zmazať udalosť?',
      description: 'Vlastná udalosť bude natrvalo odstránená.',
      confirmLabel: 'Zmazať',
    });
    if (!ok) return;
    try {
      await Calendar.deleteEvent(id, csrfToken);
      await reload();
      toast.success('Udalosť zmazaná');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Mazanie zlyhalo.');
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex items-center justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Kalendár</p>
          <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Termíny kontrol</h1>
        </div>
        <Button
          type="button"
          leftIcon={<CalendarPlus className="size-4" />}
          onClick={() => { setEditingEvent(null); setShowEventForm(true); }}
        >
          Pridať udalosť
        </Button>
      </header>

      <Card className="p-4">
        <div className="mb-3 flex items-center justify-between">
          <button
            type="button"
            onClick={() => shiftMonth(-1)}
            className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-50"
            aria-label="Predchádzajúci mesiac"
          >
            <ChevronLeft className="size-5" />
          </button>
          <h2 className="text-sm font-semibold text-ink-900">
            {MONTHS[viewMonth]} {viewYear}
          </h2>
          <button
            type="button"
            onClick={() => shiftMonth(1)}
            className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-50"
            aria-label="Nasledujúci mesiac"
          >
            <ChevronRight className="size-5" />
          </button>
        </div>

        {/* Phones get a scrollable day list — a 7-column grid is too narrow for names. */}
        <MonthDayList
          days={monthDays}
          selected={selected}
          onSelect={setSelected}
          className="sm:hidden"
        />

        <div className="hidden grid-cols-7 gap-1 sm:grid">
          {WEEKDAYS.map((w) => (
            <div key={w} className="py-1 text-center text-[11px] font-semibold uppercase text-ink-400">
              {w}
            </div>
          ))}
          {weeks.flat().map((cell) => {
            const dayIso = iso(cell.date);
            const inMonth = cell.date.getMonth() === viewMonth;
            const info = byDay.get(dayIso);
            const companies = companiesForDay(info?.deadlines ?? []);
            const hasEvents = (info?.events.length ?? 0) > 0;
            const isSelected = dayIso === selected;
            const isToday = dayIso === todayIso();
            return (
              <button
                key={dayIso}
                type="button"
                onClick={() => setSelected(dayIso)}
                className={[
                  'flex min-h-[3.25rem] w-full min-w-0 flex-col items-center gap-1 rounded-xl border px-1 py-1.5 text-left transition-colors',
                  isSelected ? 'border-firol-400 bg-firol-50' : 'border-transparent hover:bg-ink-50',
                  inMonth ? '' : 'opacity-40',
                ].join(' ')}
              >
                <span
                  className={[
                    'grid size-6 shrink-0 place-items-center rounded-full text-xs',
                    isToday ? 'bg-firol-500 font-semibold text-white' : 'text-ink-700',
                  ].join(' ')}
                >
                  {cell.date.getDate()}
                </span>
                <span className="flex w-full min-w-0 flex-col items-center gap-0.5">
                  {companies.map((c) => (
                    <span
                      key={c.company_id}
                      title={c.company_name}
                      className={[
                        'block w-full truncate rounded px-1 py-px text-[9px] font-semibold leading-tight text-white',
                        c.overdue ? 'bg-status-bad' : 'bg-firol-400',
                      ].join(' ')}
                    >
                      {c.company_name}
                    </span>
                  ))}
                  {hasEvents && <span className="size-1.5 rounded-full bg-emerald-500" />}
                </span>
              </button>
            );
          })}
        </div>
      </Card>

      {showEventForm && (
        <EventForm
          initial={editingEvent}
          defaultDate={selected}
          csrfToken={csrfToken}
          onClose={() => setShowEventForm(false)}
          onSaved={async () => { setShowEventForm(false); await reload(); }}
        />
      )}

      {!loaded ? (
        <div className="flex justify-center py-8 text-ink-400"><Spinner /></div>
      ) : (
        <DayAgenda
          dayIso={selected}
          groups={selectedGroups}
          events={selectedEvents}
          csrfToken={csrfToken}
          onChanged={reload}
          onEditEvent={(e) => { setEditingEvent(e); setShowEventForm(true); }}
          onDeleteEvent={handleDeleteEvent}
        />
      )}
    </div>
  );
}

/**
 * Mobile replacement for the month grid: one scrollable row per day that has
 * deadlines or events, with company names spelled out in full.
 */
function MonthDayList({
  days,
  selected,
  onSelect,
  className,
}: {
  days: { day: string; deadlines: CalendarDeadline[]; events: CalendarEvent[] }[];
  selected: string;
  onSelect: (day: string) => void;
  className?: string;
}) {
  if (days.length === 0) {
    return (
      <p className={['py-6 text-center text-sm text-ink-400', className].filter(Boolean).join(' ')}>
        Tento mesiac nemá žiadne termíny ani udalosti.
      </p>
    );
  }

  return (
    <div
      className={[
        'max-h-[60vh] divide-y divide-ink-50 overflow-y-auto rounded-xl border border-ink-100',
        className,
      ].filter(Boolean).join(' ')}
    >
      {days.map(({ day, deadlines, events }) => {
        const date = new Date(day + 'T00:00:00');
        const companies = companiesForDay(deadlines);
        const isSelected = day === selected;
        const isToday = day === todayIso();
        return (
          <button
            key={day}
            type="button"
            onClick={() => onSelect(day)}
            className={[
              'flex w-full items-start gap-3 px-3 py-2.5 text-left transition-colors',
              isSelected ? 'bg-firol-50' : 'hover:bg-ink-50',
            ].join(' ')}
          >
            <span className="flex w-9 shrink-0 flex-col items-center gap-0.5">
              <span
                className={[
                  'grid size-7 place-items-center rounded-full text-sm',
                  isToday ? 'bg-firol-500 font-semibold text-white' : 'font-medium text-ink-800',
                ].join(' ')}
              >
                {date.getDate()}
              </span>
              <span className="text-[10px] font-semibold uppercase text-ink-400">
                {WEEKDAYS[(date.getDay() + 6) % 7]}
              </span>
            </span>
            <span className="flex min-w-0 flex-1 flex-col gap-1 py-0.5">
              {companies.map((c) => (
                <span key={c.company_id} className="flex min-w-0 items-center gap-1.5">
                  <span
                    className={[
                      'size-1.5 shrink-0 rounded-full',
                      c.overdue ? 'bg-status-bad' : 'bg-firol-400',
                    ].join(' ')}
                  />
                  <span
                    className={[
                      'truncate text-sm',
                      c.overdue ? 'font-medium text-status-bad' : 'text-ink-800',
                    ].join(' ')}
                  >
                    {c.company_name}
                  </span>
                </span>
              ))}
              {events.map((e) => (
                <span key={e.id} className="flex min-w-0 items-center gap-1.5">
                  <span className="size-1.5 shrink-0 rounded-full bg-emerald-500" />
                  <span className="truncate text-sm text-ink-600">{e.title}</span>
                </span>
              ))}
            </span>
            <ChevronRight className="mt-1 size-4 shrink-0 text-ink-300" />
          </button>
        );
      })}
    </div>
  );
}

function DayAgenda({
  dayIso,
  groups,
  events,
  csrfToken,
  onChanged,
  onEditEvent,
  onDeleteEvent,
}: {
  dayIso: string;
  groups: FacilityDeadlineGroup[];
  events: CalendarEvent[];
  csrfToken: string | null;
  onChanged: () => Promise<void>;
  onEditEvent: (e: CalendarEvent) => void;
  onDeleteEvent: (id: number) => void;
}) {
  const label = new Date(dayIso + 'T00:00:00').toLocaleDateString('sk-SK', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
  });

  if (groups.length === 0 && events.length === 0) {
    return (
      <Card className="px-4 py-6 text-center text-sm text-ink-400">
        <CalendarDays className="mx-auto mb-2 size-6 text-ink-300" />
        {label} — žiadne termíny ani udalosti.
      </Card>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="px-1 text-xs font-semibold uppercase tracking-wider text-ink-500">{label}</p>

      {groups.map((g) => (
        <Card key={g.facility_id} className="overflow-hidden">
          <div className="border-b border-ink-100 px-4 py-2.5">
            <p className="text-sm font-semibold text-ink-900">
              {g.company_name} — {g.facility_name}
            </p>
            <p className="text-xs text-ink-500">
              {g.deadlines.length} {g.deadlines.length === 1 ? 'termín' : g.deadlines.length < 5 ? 'termíny' : 'termínov'}
            </p>
          </div>
          <ul className="divide-y divide-ink-50">
            {g.deadlines.map((d) => (
              <li key={d.inspection_id}>
                <DeadlineRow deadline={d} csrfToken={csrfToken} onChanged={onChanged} />
              </li>
            ))}
          </ul>
        </Card>
      ))}

      {events.map((e) => (
        <Card key={e.id} className="flex items-start gap-3 px-4 py-3">
          <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-emerald-100 text-emerald-600">
            <CalendarDays className="size-4" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-ink-900">{e.title}</p>
            {(e.company_name || e.facility_name) && (
              <p className="text-xs text-ink-500">
                {[e.company_name, e.facility_name].filter(Boolean).join(' — ')}
              </p>
            )}
            {e.note && <p className="mt-0.5 text-xs text-ink-600">{e.note}</p>}
          </div>
          <div className="flex shrink-0 items-center gap-1.5">
            <button
              type="button"
              onClick={() => onEditEvent(e)}
              aria-label="Upraviť"
              className="grid size-8 place-items-center rounded-lg text-ink-400 transition-colors hover:bg-ink-50 hover:text-ink-700"
            >
              <Pencil className="size-4" />
            </button>
            <button
              type="button"
              onClick={() => onDeleteEvent(e.id)}
              aria-label="Zmazať"
              className="grid size-8 place-items-center rounded-lg text-status-bad transition-colors hover:bg-[var(--color-status-bad-bg)]"
            >
              <Trash2 className="size-4" />
            </button>
          </div>
        </Card>
      ))}
    </div>
  );
}

function DeadlineRow({
  deadline,
  csrfToken,
  onChanged,
}: {
  deadline: CalendarDeadline;
  csrfToken: string | null;
  onChanged: () => Promise<void>;
}) {
  const toast = useToast();
  const [editing, setEditing] = useState(false);
  const [planned, setPlanned] = useState(deadline.planned_date ?? '');
  const [saving, setSaving] = useState(false);

  const statutory = deadline.statutory_date;
  const overshoot = planned !== '' && planned > statutory;

  async function save() {
    if (!planned) return;
    setSaving(true);
    try {
      await Calendar.setPlan(deadline.inspection_id, planned, csrfToken);
      setEditing(false);
      await onChanged();
      toast.success('Plánovaný dátum uložený');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Uloženie zlyhalo.');
    } finally {
      setSaving(false);
    }
  }
  async function clearPlan() {
    setSaving(true);
    try {
      await Calendar.clearPlan(deadline.inspection_id, csrfToken);
      setPlanned('');
      setEditing(false);
      await onChanged();
      toast.success('Plánovaný dátum zrušený');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Operácia zlyhala.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="px-4 py-3">
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0">
          <Link
            to={`/inspections/${deadline.inspection_id}`}
            className="text-sm font-medium text-ink-900 hover:text-firol-600"
          >
            {INSPECTION_TYPE_LABELS[deadline.type]}
          </Link>
          <p className="text-xs text-ink-500">
            Zákonný termín: {new Date(statutory + 'T00:00:00').toLocaleDateString('sk-SK')}
            {deadline.planned_date && (
              <> · plán: {new Date(deadline.planned_date + 'T00:00:00').toLocaleDateString('sk-SK')}</>
            )}
          </p>
        </div>
        <button
          type="button"
          onClick={() => setEditing((v) => !v)}
          className="shrink-0 rounded-lg px-2 py-1 text-xs font-medium text-firol-600 transition-colors hover:bg-firol-50"
        >
          {deadline.planned_date ? 'Zmeniť plán' : 'Naplánovať'}
        </button>
      </div>

      {editing && (
        <div className="mt-3 flex flex-col gap-2 rounded-xl bg-ink-50 p-3">
          <label className="text-xs font-semibold uppercase tracking-wide text-ink-500">
            Plánovaný dátum návštevy
          </label>
          <Input
            type="date"
            value={planned}
            onChange={(e) => setPlanned(e.target.value)}
            aria-label="Plánovaný dátum návštevy"
          />
          {overshoot && (
            <p className="flex items-center gap-1.5 text-xs text-status-warn">
              <AlertTriangle className="size-3.5" />
              Plánovaný dátum je po zákonnom termíne — zodpovednosť je na tebe.
            </p>
          )}
          <div className="flex items-center gap-2">
            <Button type="button" onClick={save} loading={saving} disabled={!planned} className="flex-1">
              Uložiť plán
            </Button>
            {deadline.planned_date && (
              <Button type="button" variant="secondary" onClick={clearPlan} loading={saving}>
                Zrušiť plán
              </Button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function EventForm({
  initial,
  defaultDate,
  csrfToken,
  onClose,
  onSaved,
}: {
  initial: CalendarEvent | null;
  defaultDate: string;
  csrfToken: string | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const toast = useToast();
  const [title, setTitle] = useState(initial?.title ?? '');
  const [date, setDate] = useState(initial?.event_date ?? defaultDate);
  const [note, setNote] = useState(initial?.note ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!title.trim() || !date) { setError('Doplň názov a dátum.'); return; }
    setSaving(true);
    setError(null);
    const body: CalendarEventInput = {
      title: title.trim(),
      event_date: date,
      note: note.trim() || null,
    };
    try {
      if (initial) {
        await Calendar.updateEvent(initial.id, body, csrfToken);
      } else {
        await Calendar.createEvent(body, csrfToken);
      }
      toast.success(initial ? 'Udalosť upravená' : 'Udalosť pridaná');
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Uloženie zlyhalo.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card className="p-4">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold text-ink-900">
          {initial ? 'Upraviť udalosť' : 'Nová vlastná udalosť'}
        </h2>
        <button
          type="button"
          onClick={onClose}
          aria-label="Zavrieť"
          className="grid size-8 place-items-center rounded-lg text-ink-400 transition-colors hover:bg-ink-50"
        >
          <X className="size-4" />
        </button>
      </div>
      <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
        <Field label="Názov" required>
          {(p) => (
            <Input {...p} required value={title} onChange={(e) => setTitle(e.target.value)}
              placeholder="Stretnutie s klientom, obhliadka…" />
          )}
        </Field>
        <Field label="Dátum" required>
          {(p) => <Input {...p} required type="date" value={date} onChange={(e) => setDate(e.target.value)} />}
        </Field>
        <Field label="Poznámka" hint="Voliteľné">
          {(p) => (
            <textarea
              id={p.id}
              rows={2}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="Detaily udalosti…"
              className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
            />
          )}
        </Field>
        {error && <p className="text-xs text-status-bad">{error}</p>}
        <div className="flex justify-end gap-2 pt-1">
          <Button type="button" variant="secondary" onClick={onClose}>Zrušiť</Button>
          <Button type="submit" loading={saving} leftIcon={initial ? undefined : <Plus className="size-4" />}>
            {initial ? 'Uložiť zmeny' : 'Pridať udalosť'}
          </Button>
        </div>
      </form>
    </Card>
  );
}

type Cell = { date: Date };

/** Monday-first weeks covering the given month (with leading/trailing days). */
function buildMonthGrid(year: number, month: number): Cell[][] {
  const first = new Date(year, month, 1);
  // JS: 0=Sun … 6=Sat. Convert to Monday-first offset.
  const lead = (first.getDay() + 6) % 7;
  const start = new Date(year, month, 1 - lead);
  const weeks: Cell[][] = [];
  const cursor = new Date(start);
  for (let w = 0; w < 6; w++) {
    const week: Cell[] = [];
    for (let d = 0; d < 7; d++) {
      week.push({ date: new Date(cursor) });
      cursor.setDate(cursor.getDate() + 1);
    }
    weeks.push(week);
    // Stop after we've passed the month and completed a week.
    if (cursor.getMonth() !== month && week[6].date.getMonth() !== month && w >= 3) break;
  }
  return weeks;
}
