/**
 * Úlohy — block 4 / chapter 20. A simple list, not a project tool: text,
 * optional firma + prevádzka, optional assignee, optional termín, done or not.
 *
 * Writes go through the usual api layer, so offline they land in the outbox:
 * PATCH / DELETE on an existing task are queued as they are, and a create
 * carries an optimistic spec (temp id, remapped once it syncs). A create for a
 * nedostatok whose úkon exists only offline yet is queued directly, behind the
 * úkon's own create — see {@link Tasks.create}.
 *
 * ─── For the „Dnes" screen (chapter 18, card „Úlohy do 7 dní") ─────────────
 *
 *   Tasks.upcoming('mine' | 'team') → { items: Task[], until: 'YYYY-MM-DD' }
 *
 *   Open tasks with a termín up to today + 7 days, overdue ones included,
 *   earliest termín first. `mine` = assigned to the signed-in user, plus
 *   unassigned tasks they created; `team` = the whole account. Tasks of an
 *   archived firm never appear. Or use the hook `useUpcomingTasks(scope)`
 *   from this file. A row opens `/ulohy`; `task.source` (when present) links
 *   to `/inspections/{source.inspection_id}`.
 * ─────────────────────────────────────────────────────────────────────────────
 */
import { useEffect, useState } from 'react';
import { api, type OptimisticSpec } from '@/lib/api';
import { enqueueMutation, drainQueue } from '@/lib/queue';
import { mintTempId, isTempId } from '@/lib/tempId';
import type { InspectionType } from '@/api/inspections';

export type Task = {
  id: number;
  text: string;
  company_id: number | null;
  company_name: string | null;
  facility_id: number | null;
  facility_name: string | null;
  assignee_user_id: number | null;
  assignee_name: string | null;
  due_date: string | null;
  done: boolean;
  done_at: string | null;
  /** The úkon whose nedostatok the task came from — only while that úkon exists. */
  source: {
    inspection_id: number;
    defect_key: string;
    type: InspectionType;
    executed_on: string | null;
    /** Protocol number, or null while the úkon has no protocol yet. */
    document_number: string | null;
  } | null;
  /** Key of the source nedostatok, kept even when its úkon is gone. */
  source_defect_key: string | null;
  created_by_user_id: number | null;
  created_by_name: string | null;
  created_at: string;
};

export type TaskState = 'open' | 'done';

export type TaskPayload = {
  text: string;
  company_id: number | null;
  facility_id: number | null;
  assignee_user_id: number | null;
  due_date: string | null;
};

/** A task born from a nedostatok — firma and prevádzka come from the úkon. */
export type DefectTaskPayload = {
  text: string;
  due_date: string;
  assignee_user_id: number | null;
  source_inspection_id: number;
  source_defect_key: string;
};

/** Fired after any task write, so the menu badge and open lists refresh. */
export const TASKS_CHANGED_EVENT = 'firol:tasks-changed';

function announce() {
  window.dispatchEvent(new Event(TASKS_CHANGED_EVENT));
}

/** Runs a write and announces it — also when it was queued offline. */
async function write<T>(run: () => Promise<T>): Promise<T> {
  try {
    return await run();
  } finally {
    announce();
  }
}

function listPath(state: TaskState, assignee: number | null): string {
  const qs = new URLSearchParams({ state });
  if (assignee !== null) qs.set('assignee', String(assignee));
  return `/api/tasks?${qs.toString()}`;
}

function nowIso(): string {
  return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * What a task created offline looks like until it syncs: shown at the top of
 * the unfiltered open list and counted in the badge.
 */
function createOptimistic(
  body: TaskPayload | DefectTaskPayload,
  extra: Partial<Task>,
): OptimisticSpec {
  const id = mintTempId();
  const task: Task = {
    id,
    text: body.text,
    company_id: 'company_id' in body ? body.company_id : null,
    company_name: null,
    facility_id: 'facility_id' in body ? body.facility_id : null,
    facility_name: null,
    assignee_user_id: body.assignee_user_id,
    assignee_name: null,
    due_date: body.due_date,
    done: false,
    done_at: null,
    source: null,
    source_defect_key: 'source_defect_key' in body ? body.source_defect_key : null,
    created_by_user_id: null,
    created_by_name: null,
    created_at: nowIso(),
    ...extra,
  };
  return {
    returns: { task },
    create: { clientId: id, idPath: 'task.id' },
    label: 'Nová úloha',
    detail: body.text,
    patches: [
      {
        path: listPath('open', null),
        apply: (current) => {
          const list = current as { items?: Task[] } | undefined;
          return { ...(list ?? {}), items: [...(list?.items ?? []), task] };
        },
      },
      {
        path: '/api/tasks/count',
        apply: (current) => {
          const c = current as { open?: number } | undefined;
          return { open: (c?.open ?? 0) + 1 };
        },
      },
    ],
  };
}

let countInFlight: Promise<{ open: number }> | null = null;

export const Tasks = {
  list: (state: TaskState, assignee: number | null = null) =>
    api<{ items: Task[] }>(listPath(state, assignee)),

  /** Tasks that came from this úkon's nedostatky, done or not. */
  forInspection: (inspectionId: number) =>
    api<{ items: Task[] }>(`/api/tasks?source_inspection_id=${inspectionId}`),

  /**
   * Number of open tasks of the account — the menu badge. The sidebar and the
   * bottom bar each mount a badge, so concurrent calls share one request.
   */
  count: (): Promise<{ open: number }> => {
    countInFlight ??= api<{ open: number }>('/api/tasks/count').finally(() => {
      countInFlight = null;
    });
    return countInFlight;
  },

  /** Open tasks with a termín within 7 days (overdue included) — the Dnes card. */
  upcoming: (scope: 'mine' | 'team' = 'mine') =>
    api<{ items: Task[]; until: string }>(`/api/tasks/upcoming?scope=${scope}`),

  /**
   * `extra` only decorates the optimistic copy shown while offline (names the
   * page already knows); it is never sent.
   */
  create: (body: TaskPayload, csrfToken: string | null, extra: Partial<Task> = {}) =>
    write(() =>
      api<{ task: Task }>('/api/tasks', {
        method: 'POST',
        body,
        csrfToken,
        optimistic: createOptimistic(body, extra),
      }),
    ),

  /**
   * Task for a nedostatok. When the úkon itself was created offline and has
   * not synced, its id is still a temp id the server can't know — the create
   * is then queued behind it (the outbox replays in order and rewrites the
   * temp id in this body once the úkon has its real one).
   */
  createFromDefect: async (body: DefectTaskPayload, csrfToken: string | null): Promise<void> => {
    if (isTempId(body.source_inspection_id)) {
      await enqueueMutation({
        method: 'POST',
        path: '/api/tasks',
        body,
        label: 'Nová úloha',
        detail: body.text,
      });
      announce();
      void drainQueue();
      return;
    }
    await write(() =>
      api<{ task: Task }>('/api/tasks', {
        method: 'POST',
        body,
        csrfToken,
        optimistic: createOptimistic(body, {}),
      }),
    );
  },

  update: (id: number, body: Partial<TaskPayload> & { done?: boolean }, csrfToken: string | null) =>
    write(() => api<{ task: Task }>(`/api/tasks/${id}`, { method: 'PATCH', body, csrfToken })),

  remove: (id: number, csrfToken: string | null) =>
    write(() => api<void>(`/api/tasks/${id}`, { method: 'DELETE', csrfToken })),
};

/**
 * Number of open tasks — the badge on the „Úlohy" menu item. Re-read after
 * every task write and whenever `refreshKey` changes (pass the route, so a
 * change made on another device shows up on the next screen change).
 */
export function useOpenTaskCount(refreshKey?: unknown): number {
  const [count, setCount] = useState(0);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    const bump = () => setTick((t) => t + 1);
    window.addEventListener(TASKS_CHANGED_EVENT, bump);
    // A queued create/edit that syncs later changes the count too.
    window.addEventListener('firol:remap', bump);
    window.addEventListener('online', bump);
    return () => {
      window.removeEventListener(TASKS_CHANGED_EVENT, bump);
      window.removeEventListener('firol:remap', bump);
      window.removeEventListener('online', bump);
    };
  }, []);

  useEffect(() => {
    let cancelled = false;
    Tasks.count()
      .then((res) => {
        if (!cancelled) setCount(res.open);
      })
      .catch(() => {
        // Offline without a cached count — keep what we had.
      });
    return () => {
      cancelled = true;
    };
  }, [tick, refreshKey]);

  return count;
}

/** The „Úlohy do 7 dní" rows for the Dnes screen (chapter 18). */
export function useUpcomingTasks(scope: 'mine' | 'team' = 'mine'): {
  items: Task[];
  loading: boolean;
} {
  const [items, setItems] = useState<Task[]>([]);
  const [loading, setLoading] = useState(true);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    const bump = () => setTick((t) => t + 1);
    window.addEventListener(TASKS_CHANGED_EVENT, bump);
    return () => window.removeEventListener(TASKS_CHANGED_EVENT, bump);
  }, []);

  useEffect(() => {
    let cancelled = false;
    Tasks.upcoming(scope)
      .then((res) => {
        if (!cancelled) setItems(res.items);
      })
      .catch(() => {
        if (!cancelled) setItems([]);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [scope, tick]);

  return { items, loading };
}
