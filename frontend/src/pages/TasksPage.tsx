/**
 * Úlohy — block 4 / chapter 20. „Jednoduchý zoznam, nie projektový nástroj."
 *
 * Open tasks by default, „Splnené" keeps the done ones findable. Filter by
 * priradená osoba (shown only in a team — a solo technician has nothing to
 * filter, like the calendar's filter in chapter 11.6). A task has text,
 * optional firma + prevádzka, optional assignee and optional termín; nothing
 * more — no priorities, tags or subtasks (POKYNY_PRE_AI.md).
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import {
  Building2,
  Calendar,
  Check,
  ClipboardCheck,
  Plus,
  Trash2,
  User as UserIcon,
  Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { Tasks, type Task, type TaskPayload, type TaskState } from '@/api/tasks';
import { Team, type TeamMember } from '@/api/team';
import { Companies, type CompanyListItem, type FacilityListItem } from '@/api/companies';
import { INSPECTION_TYPE_LABELS } from '@/api/inspections';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { useConfirm } from '@/lib/confirm';
import { todayIso } from '@/lib/dates';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Select } from '@/components/ui/Select';
import { Dialog } from '@/components/ui/Dialog';
import { SkeletonList } from '@/components/ui/Skeleton';

// texty_ui.json → ulohy
const T = {
  nova: 'Nová úloha',
  otvorene: 'Otvorené',
  splnene: 'Splnené',
  vseobecna: '— všeobecná —',
  zNedostatku: (cislo: string) => `vzniklo z kontroly ${cislo}`,
};

/** „2026-08-26" → „26. 8. 2026" */
function formatDay(iso: string): string {
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
  return `${d}. ${m}. ${y}`;
}

function addDays(iso: string, days: number): string {
  const [y, m, d] = iso.split('-').map(Number);
  const date = new Date(y, m - 1, d + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one;
  if (n >= 2 && n <= 4) return few;
  return many;
}

/** The protocol number when there is one, else what the úkon is. */
function sourceLabel(source: NonNullable<Task['source']>): string {
  if (source.document_number) return source.document_number;
  const type = INSPECTION_TYPE_LABELS[source.type] ?? source.type;
  return source.executed_on ? `${type} · ${formatDay(source.executed_on)}` : type;
}

export function TasksPage() {
  const { csrfToken, user } = useAuth();
  const isReadOnly = useIsReadOnly();
  const toast = useToast();
  const [params, setParams] = useSearchParams();

  const state: TaskState = params.get('stav') === 'splnene' ? 'done' : 'open';
  const assigneeParam = params.get('kto');
  const assignee = assigneeParam && /^\d+$/.test(assigneeParam) ? Number(assigneeParam) : null;

  const [items, setItems] = useState<Task[]>([]);
  const [status, setStatus] = useState<'loading' | 'idle' | 'error'>('loading');
  const [error, setError] = useState<string | null>(null);
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [editing, setEditing] = useState<Task | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [reloadTick, setReloadTick] = useState(0);

  const reload = useCallback(() => setReloadTick((t) => t + 1), []);

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');
    Tasks.list(state, assignee)
      .then((res) => {
        if (cancelled) return;
        setItems(res.items);
        setStatus('idle');
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať úlohy.');
        setStatus('error');
      });
    return () => {
      cancelled = true;
    };
  }, [state, assignee, reloadTick]);

  // A queued task that syncs gets its real id — reload so edits target it.
  useEffect(() => {
    window.addEventListener('firol:remap', reload);
    return () => window.removeEventListener('firol:remap', reload);
  }, [reload]);

  useEffect(() => {
    Team.list()
      .then((res) => setMembers(res.items))
      .catch(() => setMembers([]));
  }, []);

  const isTeam = members.length > 1;
  const today = todayIso();
  const soon = addDays(today, 7);

  function setFilter(next: { stav?: TaskState; kto?: number | null }) {
    const p = new URLSearchParams(params);
    const nextState = next.stav ?? state;
    if (nextState === 'done') p.set('stav', 'splnene'); else p.delete('stav');
    const nextKto = next.kto === undefined ? assignee : next.kto;
    if (nextKto !== null) p.set('kto', String(nextKto)); else p.delete('kto');
    setParams(p, { replace: true });
  }

  async function toggleDone(task: Task) {
    const done = !task.done;
    // Leaves the current list at once: a ticked task belongs to „Splnené".
    setItems((prev) => prev.filter((t) => t.id !== task.id));
    try {
      await Tasks.update(task.id, { done }, csrfToken);
      toast.success(done ? 'Úloha splnená' : 'Úloha je znova otvorená');
    } catch (err) {
      if (err instanceof OfflineQueuedError) {
        toast.success('Uloží sa keď budeš online');
        return;
      }
      setItems((prev) => [...prev, task]);
      reload();
      toast.error(err instanceof ApiError ? err.message : 'Úlohu sa nepodarilo uložiť.');
    }
  }

  function openNew() {
    setEditing(null);
    setFormOpen(true);
  }

  function openEdit(task: Task) {
    if (isReadOnly) return;
    setEditing(task);
    setFormOpen(true);
  }

  // `?uloha={id}` — a row of the Dnes card „Úlohy do 7 dní" (chapter 18)
  // opens that task. The param is consumed once the list is in, so closing
  // the form does not reopen it.
  const deepLinkId = params.get('uloha');
  useEffect(() => {
    if (!deepLinkId || status !== 'idle') return;
    const task = items.find((t) => String(t.id) === deepLinkId);
    if (task && !isReadOnly) {
      setEditing(task);
      setFormOpen(true);
    }
    const p = new URLSearchParams(params);
    p.delete('uloha');
    setParams(p, { replace: true });
  }, [deepLinkId, status, items, isReadOnly, params, setParams]);

  const memberOptions = useMemo(
    () => [
      { value: '', label: 'Všetci' },
      ...members.map((m) => ({ value: String(m.id), label: m.fullname })),
    ],
    [members],
  );

  return (
    <div className="flex flex-col gap-5">
      <header className="flex items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">Úlohy</h1>
          {status === 'idle' && (
            <p className="mt-0.5 text-sm text-ink-500">
              {state === 'open'
                ? `${items.length} ${plural(items.length, 'otvorená', 'otvorené', 'otvorených')}`
                : `${items.length} ${plural(items.length, 'splnená', 'splnené', 'splnených')}`}
            </p>
          )}
        </div>
        {!isReadOnly && (
          <Button size="sm" onClick={openNew} leftIcon={<Plus className="size-4" />} id="tasks-new">
            {T.nova}
          </Button>
        )}
      </header>

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div role="tablist" aria-label="Stav úloh" className="inline-flex rounded-2xl border border-ink-100 bg-white p-1 shadow-[var(--shadow-soft)]">
          {(['open', 'done'] as const).map((s) => (
            <button
              key={s}
              type="button"
              role="tab"
              aria-selected={state === s}
              onClick={() => setFilter({ stav: s })}
              className={cn(
                'min-h-10 flex-1 rounded-xl px-4 text-sm font-medium transition-all duration-200 active:scale-[0.98] sm:flex-none',
                state === s ? 'bg-firol-500 text-white shadow-[var(--shadow-glow)]' : 'text-ink-600 hover:bg-ink-50',
              )}
            >
              {s === 'open' ? T.otvorene : T.splnene}
            </button>
          ))}
        </div>
        {isTeam && (
          <div className="sm:w-60">
            <Select
              id="tasks-assignee-filter"
              value={assignee !== null ? String(assignee) : ''}
              onChange={(v) => setFilter({ kto: v ? Number(v) : null })}
              options={memberOptions}
              leftIcon={<UserIcon className="size-4" />}
              emptyLabel="Všetci"
            />
          </div>
        )}
      </div>

      {status === 'loading' && items.length === 0 && <SkeletonList count={3} />}

      {status === 'error' && error && (
        <Card className="border-status-bad/30 bg-[var(--color-status-bad-bg)]/50 px-4 py-3 text-sm text-[var(--color-status-bad)]">
          {error}
        </Card>
      )}

      {status === 'idle' && items.length === 0 && (
        <p className="px-1 text-sm text-ink-400">
          {state === 'open' ? 'Žiadne otvorené úlohy.' : 'Žiadne splnené úlohy.'}
        </p>
      )}

      {items.length > 0 && (
        <ul className="flex flex-col gap-2">
          {items.map((task) => (
            <TaskRow
              key={task.id}
              task={task}
              today={today}
              soon={soon}
              showAssignee={isTeam}
              readOnly={isReadOnly}
              onToggle={() => toggleDone(task)}
              onOpen={() => openEdit(task)}
            />
          ))}
        </ul>
      )}

      <TaskForm
        open={formOpen}
        initial={editing}
        members={members}
        currentUserId={user?.id ?? null}
        csrfToken={csrfToken}
        onClose={() => setFormOpen(false)}
        onSaved={() => {
          setFormOpen(false);
          reload();
        }}
      />
    </div>
  );
}

function TaskRow({
  task,
  today,
  soon,
  showAssignee,
  readOnly,
  onToggle,
  onOpen,
}: {
  task: Task;
  today: string;
  soon: string;
  showAssignee: boolean;
  readOnly: boolean;
  onToggle: () => void;
  onOpen: () => void;
}) {
  const overdue = !task.done && task.due_date !== null && task.due_date < today;
  const dueSoon = !task.done && !overdue && task.due_date !== null && task.due_date <= soon;
  const place = task.company_name
    ? task.facility_name ? `${task.company_name} · ${task.facility_name}` : task.company_name
    : null;

  return (
    <li>
      <Card elevated={false} className="flex items-start gap-3 px-3 py-3 transition-all duration-300 hover:shadow-[var(--shadow-lift)]">
        <button
          type="button"
          role="checkbox"
          aria-checked={task.done}
          aria-label={task.done ? 'Označiť ako nesplnenú' : 'Označiť ako splnenú'}
          disabled={readOnly}
          onClick={onToggle}
          className="grid size-11 shrink-0 place-items-center rounded-xl transition-transform duration-150 active:scale-90 disabled:opacity-50"
        >
          <span
            className={cn(
              'grid size-6 place-items-center rounded-lg border-2 transition-all duration-200',
              task.done
                ? 'border-[var(--color-status-ok)] bg-[var(--color-status-ok)] text-white'
                : 'border-ink-300 bg-white hover:border-firol-400',
            )}
          >
            {task.done && <Check className="size-4" />}
          </span>
        </button>

        <div className="min-w-0 flex-1 py-1">
        <button
          type="button"
          onClick={onOpen}
          disabled={readOnly}
          className="block w-full text-left disabled:cursor-default"
        >
          <p className={cn('break-words text-sm font-semibold', task.done ? 'text-ink-500 line-through decoration-ink-300' : 'text-ink-900')}>
            {task.text}
          </p>
          <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-500">
            <span className="inline-flex min-w-0 items-center gap-1">
              <Building2 className="size-3.5 shrink-0 text-ink-400" />
              {place ?? <span className="text-ink-400">{T.vseobecna}</span>}
            </span>
            {showAssignee && task.assignee_name && (
              <span className="inline-flex items-center gap-1">
                <UserIcon className="size-3.5 text-ink-400" />
                {task.assignee_name}
              </span>
            )}
            {task.done && task.done_at && (
              <span>splnená {formatDay(task.done_at)}</span>
            )}
          </p>
        </button>
        {task.source && (
          <Link
            to={`/inspections/${task.source.inspection_id}`}
            className="mt-1 inline-flex max-w-full items-center gap-1 text-xs text-firol-600 transition-colors hover:text-firol-700 hover:underline"
          >
            <ClipboardCheck className="size-3.5 shrink-0" />
            <span className="truncate">{T.zNedostatku(sourceLabel(task.source))}</span>
          </Link>
        )}
        </div>

        <div className="flex shrink-0 flex-col items-end gap-1.5 py-1">
          {task.due_date && (
            <span
              className={cn(
                'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
                overdue
                  ? 'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]'
                  : dueSoon
                    ? 'bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]'
                    : 'bg-ink-100 text-ink-600',
              )}
            >
              <Calendar className="size-3" />
              {formatDay(task.due_date)}
            </span>
          )}
        </div>
      </Card>
    </li>
  );
}

function TaskForm({
  open,
  initial,
  members,
  currentUserId,
  csrfToken,
  onClose,
  onSaved,
}: {
  open: boolean;
  initial: Task | null;
  members: TeamMember[];
  currentUserId: number | null;
  csrfToken: string | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const [text, setText] = useState('');
  const [companyId, setCompanyId] = useState<number | null>(null);
  const [facilityId, setFacilityId] = useState<number | null>(null);
  const [assigneeId, setAssigneeId] = useState<number | null>(null);
  const [dueDate, setDueDate] = useState('');
  const [companies, setCompanies] = useState<CompanyListItem[]>([]);
  const [facilities, setFacilities] = useState<FacilityListItem[]>([]);
  const [loadingFacilities, setLoadingFacilities] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isTeam = members.length > 1;

  // Re-seed each time it opens; a new task is the signed-in technician's.
  useEffect(() => {
    if (!open) return;
    setText(initial?.text ?? '');
    setCompanyId(initial?.company_id ?? null);
    setFacilityId(initial?.facility_id ?? null);
    setAssigneeId(initial ? initial.assignee_user_id : currentUserId);
    setDueDate(initial?.due_date ?? '');
    setError(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initial]);

  useEffect(() => {
    if (!open || companies.length > 0) return;
    Companies.list()
      .then((res) => setCompanies(res.items))
      .catch(() => {
        // Firma stays optional either way.
      });
  }, [open, companies.length]);

  useEffect(() => {
    if (companyId === null) {
      setFacilities([]);
      setFacilityId(null);
      return;
    }
    let cancelled = false;
    setLoadingFacilities(true);
    Companies.show(companyId)
      .then((res) => {
        if (cancelled) return;
        setFacilities(res.facilities);
        setFacilityId((current) =>
          current !== null && res.facilities.some((f) => f.id === current) ? current : null,
        );
      })
      .catch(() => {
        if (!cancelled) setFacilities([]);
      })
      .finally(() => {
        if (!cancelled) setLoadingFacilities(false);
      });
    return () => {
      cancelled = true;
    };
  }, [companyId]);

  // Active members; a deactivated assignee stays listed so the row isn't blanked.
  const assigneeOptions = useMemo(() => {
    const list = members.filter((m) => m.is_active || m.id === initial?.assignee_user_id);
    return list.map((m) => ({ value: String(m.id), label: m.fullname }));
  }, [members, initial]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!text.trim()) {
      setError('Zadaj text úlohy.');
      return;
    }
    const body: TaskPayload = {
      text: text.trim(),
      company_id: companyId,
      facility_id: facilityId,
      assignee_user_id: assigneeId,
      due_date: dueDate || null,
    };
    setSaving(true);
    setError(null);
    try {
      if (initial) {
        await Tasks.update(initial.id, body, csrfToken);
        toast.success('Úloha uložená');
      } else {
        const company = companies.find((c) => c.id === companyId);
        const facility = facilities.find((f) => f.id === facilityId);
        const member = members.find((m) => m.id === assigneeId);
        await Tasks.create(body, csrfToken, {
          company_name: company?.name ?? null,
          facility_name: facility?.name ?? null,
          assignee_name: member?.fullname ?? null,
        });
        toast.success('Úloha vytvorená');
      }
      onSaved();
    } catch (err) {
      if (err instanceof OfflineQueuedError) {
        toast.success('Uloží sa keď budeš online');
        onSaved();
        return;
      }
      setError(err instanceof ApiError ? err.message : 'Úlohu sa nepodarilo uložiť.');
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (!initial) return;
    const ok = await confirm({ title: 'Odstrániť úlohu?', description: initial.text });
    if (!ok) return;
    try {
      await Tasks.remove(initial.id, csrfToken);
      toast.success('Úloha odstránená');
      onSaved();
    } catch (err) {
      if (err instanceof OfflineQueuedError) {
        toast.success('Uloží sa keď budeš online');
        onSaved();
        return;
      }
      toast.error(err instanceof ApiError ? err.message : 'Úlohu sa nepodarilo odstrániť.');
    }
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={initial ? 'Upraviť úlohu' : T.nova}
      dismissible={!saving}
    >
      <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
        <Field label="Úloha" required>
          {(p) => (
            <textarea
              {...p}
              id="task-text"
              rows={3}
              maxLength={1000}
              value={text}
              onChange={(e) => setText(e.target.value)}
              placeholder="Napr. Objednať nálepky na kontrolu PHP"
              className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
            />
          )}
        </Field>
        <Field label="Firma" hint="Voliteľné">
          {(p) => (
            <Select
              id={p.id}
              value={companyId !== null ? String(companyId) : ''}
              onChange={(v) => setCompanyId(v ? Number(v) : null)}
              placeholder={T.vseobecna}
              leftIcon={<Building2 className="size-4" />}
              searchable
              options={[
                { value: '', label: T.vseobecna },
                ...companies.map((c) => ({ value: String(c.id), label: c.name })),
              ]}
              emptyLabel={initial?.company_name ?? undefined}
            />
          )}
        </Field>
        <Field label="Prevádzka" hint="Voliteľné">
          {(p) => (
            <Select
              id={p.id}
              value={facilityId !== null ? String(facilityId) : ''}
              onChange={(v) => setFacilityId(v ? Number(v) : null)}
              disabled={companyId === null || loadingFacilities}
              placeholder={
                companyId === null ? '— najprv vyber firmu —' : loadingFacilities ? 'Načítavam…' : '— celá firma —'
              }
              leftIcon={<Warehouse className="size-4" />}
              searchable
              options={[
                { value: '', label: '— celá firma —' },
                ...facilities.map((f) => ({ value: String(f.id), label: f.name })),
              ]}
              emptyLabel={initial?.facility_name ?? undefined}
            />
          )}
        </Field>
        {isTeam && (
          <Field label="Priradená osoba" hint="Voliteľné">
            {(p) => (
              <Select
                id={p.id}
                value={assigneeId !== null ? String(assigneeId) : ''}
                onChange={(v) => setAssigneeId(v ? Number(v) : null)}
                placeholder="— nikto —"
                leftIcon={<UserIcon className="size-4" />}
                options={[{ value: '', label: '— nikto —' }, ...assigneeOptions]}
              />
            )}
          </Field>
        )}
        <Field label="Termín" hint="Voliteľné">
          {(p) => (
            <Input
              {...p}
              id="task-due-date"
              type="date"
              value={dueDate}
              leftIcon={<Calendar className="size-4" />}
              onChange={(e) => setDueDate(e.target.value)}
            />
          )}
        </Field>
        {error && <p className="text-xs text-status-bad">{error}</p>}
        <div className="flex items-center justify-between gap-2 pt-1">
          {initial ? (
            <Button type="button" variant="ghost" size="sm" onClick={remove} leftIcon={<Trash2 className="size-4" />} className="text-status-bad">
              Odstrániť
            </Button>
          ) : <span />}
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              Zrušiť
            </Button>
            <Button type="submit" loading={saving}>
              Uložiť
            </Button>
          </div>
        </div>
      </form>
    </Dialog>
  );
}

