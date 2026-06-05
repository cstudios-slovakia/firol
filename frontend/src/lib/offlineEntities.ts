/*
 * Optimistic-create registry.
 *
 * Two responsibilities:
 *
 *  1. Per-entity builders (`*CreateOptimistic`) that the create screens call
 *     to describe what an inspection / training / company / facility should
 *     look like locally before the server has seen it. The builder mints a
 *     temp id, assembles the full entity from data the page already has
 *     (company/facility names, the current user, …) and returns an
 *     `OptimisticSpec` the api layer applies when the create POST fails
 *     offline: it seeds the detail + list caches and is handed back to the
 *     caller as the call's result, so navigation continues with the temp id.
 *
 *  2. `autoOptimistic()` — generic handling for nested writes (adding /
 *     editing / deleting inspection items and training trainees). The api
 *     layer calls it for every offline non-GET so these flows work against a
 *     draft (real or temp) without the per-type forms knowing anything about
 *     offline mode. Item/trainee adds mint their own temp id too, so a later
 *     edit/delete of a not-yet-synced row is remapped on sync just like a
 *     parent create.
 */
import type { CachePatch, OptimisticSpec } from './api';
import { mintTempId } from './tempId';
import type {
  Inspection,
  InspectionDetail,
  InspectionDraftPayload,
  InspectionItem,
  InspectionListItem,
} from '@/api/inspections';
import type {
  Training,
  TrainingDetail,
  TrainingListItem,
  TrainingPayload,
  Trainee,
} from '@/api/trainings';
import type {
  Company,
  CompanyDetail,
  CompanyListItem,
  FacilityListItem,
} from '@/api/companies';
import type { Facility } from '@/api/facilities';
import { formatAddress } from './address';

function nowIso(): string {
  // Server timestamps look like "2026-06-03 10:20:00"; mirror that shape.
  return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

/** Cache patch that unconditionally writes `value` at `path`. */
function seed(path: string, value: unknown): CachePatch {
  return { path, apply: () => value };
}

/** Cache patch that prepends `row` into a `{ items: [] }` list cache, if present. */
function prependListRow(path: string, row: unknown): CachePatch {
  return {
    path,
    apply: (current) => {
      const list = current as { items?: unknown[] } | undefined;
      const items = Array.isArray(list?.items) ? list!.items : [];
      return { ...(list ?? {}), items: [row, ...items] };
    },
  };
}

// --- top-level create builders ------------------------------------------

export function inspectionCreateOptimistic(args: {
  payload: InspectionDraftPayload;
  company: { id: number; name: string; ico: string | null };
  facility: { id: number; name: string };
  inspector: { id: number; name: string };
}): OptimisticSpec {
  const id = mintTempId();
  const ts = nowIso();
  const inspection: Inspection = {
    id,
    type: args.payload.type,
    periodicity_months: args.payload.periodicity_months,
    executed_on: args.payload.executed_on,
    status: 'draft',
    notes: args.payload.notes ?? null,
    created_at: ts,
    updated_at: ts,
    company_id: args.company.id,
    company_name: args.company.name,
    company_ico: args.company.ico,
    facility_id: args.facility.id,
    facility_name: args.facility.name,
    inspector_user_id: args.inspector.id,
    inspector_name: args.inspector.name,
    effective_inspector_user_id: null,
    effective_inspector_name: null,
    effective_cert_number: null,
  };
  const detail: InspectionDetail = { inspection, items: [] };
  const listRow: InspectionListItem = { ...inspection };
  return {
    returns: detail,
    create: { clientId: id, idPath: 'inspection.id' },
    label: 'Nová kontrola',
    detail: `${args.company.name} · ${args.facility.name}`,
    patches: [
      seed(`/api/inspections/${id}`, detail),
      prependListRow('/api/inspections', listRow),
    ],
  };
}

export function trainingCreateOptimistic(args: {
  payload: TrainingPayload;
  company: { id: number; name: string; ico: string | null };
  facility: { id: number; name: string } | null;
  trainer: { id: number; name: string; certification_number: string | null } | null;
}): OptimisticSpec {
  const id = mintTempId();
  const ts = nowIso();
  const training: Training = {
    id,
    type: args.payload.type,
    date: args.payload.date ?? null,
    duration_min: args.payload.duration_min ?? null,
    topics: args.payload.topics ?? null,
    status: 'draft',
    created_at: ts,
    updated_at: ts,
    company_id: args.company.id,
    company_name: args.company.name,
    company_ico: args.company.ico,
    facility_id: args.facility?.id ?? null,
    facility_name: args.facility?.name ?? null,
    trainer_id: args.trainer?.id ?? null,
    trainer_name: args.trainer?.name ?? null,
    trainer_certification_number: args.trainer?.certification_number ?? null,
    trainees_count: 0,
  };
  const detail: TrainingDetail = { training, trainees: [] };
  const listRow: TrainingListItem = { ...training };
  return {
    returns: { training },
    create: { clientId: id, idPath: 'training.id' },
    label: 'Nové školenie',
    detail: args.facility ? `${args.company.name} · ${args.facility.name}` : args.company.name,
    patches: [
      seed(`/api/trainings/${id}`, detail),
      prependListRow('/api/trainings', listRow),
    ],
  };
}

export function companyCreateOptimistic(args: {
  name: string;
  ico: string | null;
  street: string | null;
  postal_code: string | null;
  city: string | null;
  contact: string | null;
}): OptimisticSpec {
  const id = mintTempId();
  const address = formatAddress(args.street, args.postal_code, args.city);
  const company: Company = {
    id,
    name: args.name,
    ico: args.ico,
    address,
    street: args.street,
    postal_code: args.postal_code,
    city: args.city,
    contact: args.contact,
    created_at: nowIso(),
  };
  const detail: CompanyDetail = { company, facilities: [] };
  const listRow: CompanyListItem = {
    id,
    name: args.name,
    ico: args.ico,
    address,
    street: args.street,
    postal_code: args.postal_code,
    city: args.city,
    contact: args.contact,
    facilities_count: 0,
    inspections_count: 0,
    last_inspection_at: null,
  };
  return {
    returns: { company },
    create: { clientId: id, idPath: 'company.id' },
    label: 'Nová firma',
    detail: args.name,
    patches: [
      seed(`/api/companies/${id}`, detail),
      prependListRow('/api/companies', listRow),
    ],
  };
}

export function facilityCreateOptimistic(args: {
  companyId: number;
  companyName?: string;
  name: string;
  street: string | null;
  postal_code: string | null;
  city: string | null;
  contact_person: string | null;
  notes: string | null;
}): OptimisticSpec {
  const id = mintTempId();
  const address = formatAddress(args.street, args.postal_code, args.city);
  const facility: Facility = {
    id,
    name: args.name,
    address,
    street: args.street,
    postal_code: args.postal_code,
    city: args.city,
    contact_person: args.contact_person,
    notes: args.notes,
    company_id: args.companyId,
    company_name: args.companyName,
  };
  const listRow: FacilityListItem = {
    id,
    name: args.name,
    address,
    street: args.street,
    postal_code: args.postal_code,
    city: args.city,
    contact_person: args.contact_person,
    notes: args.notes,
    last_periodicities: {},
  };
  // Splice the new facility into its company's detail cache so it shows up in
  // pickers and on the company screen while offline.
  const appendToCompany: CachePatch = {
    path: `/api/companies/${args.companyId}`,
    apply: (current) => {
      const c = current as CompanyDetail | undefined;
      if (!c) return undefined;
      return { ...c, facilities: [...c.facilities, listRow] };
    },
  };
  return {
    returns: { facility },
    create: { clientId: id, idPath: 'facility.id' },
    label: 'Nová prevádzka',
    detail: args.name,
    patches: [seed(`/api/facilities/${id}`, { facility }), appendToCompany],
  };
}

// --- nested writes (items / trainees) -----------------------------------

const ITEMS_RE = /^\/api\/inspections\/(-?\d+)\/items(?:\/(-?\d+))?$/;
const TRAINEES_RE = /^\/api\/trainings\/(-?\d+)\/trainees(?:\/(-?\d+))?$/;

// Top-level entity edits (PATCH /api/<resource>/<id>).
const COMPANY_RE = /^\/api\/companies\/(-?\d+)$/;
const FACILITY_RE = /^\/api\/facilities\/(-?\d+)$/;
const TRAINING_RE = /^\/api\/trainings\/(-?\d+)$/;
const INSPECTION_RE = /^\/api\/inspections\/(-?\d+)$/;

/** Copy the listed keys from `f` onto a clone of `target` when present. */
function mergeFields<T extends Record<string, unknown>>(
  target: T,
  f: Record<string, unknown>,
  keys: string[],
): T {
  const next: Record<string, unknown> = { ...target };
  for (const k of keys) if (k in f) next[k] = f[k];
  return next as T;
}

/** mergeFields + recompute the combined `address` from the parts. */
function mergeAddressEntity<T extends Record<string, unknown>>(
  target: T,
  f: Record<string, unknown>,
  keys: string[],
): T {
  const next = mergeFields(target, f, keys);
  (next as Record<string, unknown>).address = formatAddress(
    (next.street as string | null) ?? null,
    (next.postal_code as string | null) ?? null,
    (next.city as string | null) ?? null,
  );
  return next;
}

/** Update the row with matching id inside a `{ items: [] }` list cache. */
function updateListRow(
  current: unknown,
  id: number,
  update: (row: Record<string, unknown>) => Record<string, unknown>,
): unknown {
  const list = current as { items?: unknown[] } | undefined;
  if (!list || !Array.isArray(list.items)) return undefined;
  let changed = false;
  const items = list.items.map((row) => {
    if (row && typeof row === 'object' && (row as { id?: number }).id === id) {
      changed = true;
      return update(row as Record<string, unknown>);
    }
    return row;
  });
  return changed ? { ...list, items } : undefined;
}

/**
 * Optimistic handling for editing a top-level entity offline. Patches the
 * detail (and list) caches so the change shows immediately from the local DB,
 * then lets the api layer queue the PATCH and throw OfflineQueuedError — the
 * record stays flagged "waiting to save" until the outbox drains.
 */
function topLevelEditOptimistic(pathOnly: string, body: unknown): OptimisticSpec | null {
  const f = (body && typeof body === 'object' ? body : {}) as Record<string, unknown>;

  const company = COMPANY_RE.exec(pathOnly);
  if (company) {
    const id = Number(company[1]);
    const keys = ['name', 'ico', 'street', 'postal_code', 'city', 'contact'];
    return {
      label: 'Úprava firmy',
      detail: typeof f.name === 'string' ? f.name : undefined,
      patches: [
        {
          path: `/api/companies/${id}`,
          apply: (current) => {
            const d = current as CompanyDetail | undefined;
            if (!d) return undefined;
            return { ...d, company: mergeAddressEntity(d.company, f, keys) };
          },
        },
        {
          path: '/api/companies',
          apply: (current) => updateListRow(current, id, (row) => mergeAddressEntity(row, f, keys)),
        },
      ],
    };
  }

  const facility = FACILITY_RE.exec(pathOnly);
  if (facility) {
    const id = Number(facility[1]);
    const keys = ['name', 'street', 'postal_code', 'city', 'contact_person', 'notes'];
    return {
      label: 'Úprava prevádzky',
      detail: typeof f.name === 'string' ? f.name : undefined,
      patches: [
        {
          path: `/api/facilities/${id}`,
          apply: (current) => {
            const d = current as { facility: Facility } | undefined;
            if (!d) return undefined;
            return { ...d, facility: mergeAddressEntity(d.facility, f, keys) };
          },
        },
      ],
    };
  }

  const training = TRAINING_RE.exec(pathOnly);
  if (training) {
    const id = Number(training[1]);
    const keys = ['date', 'duration_min', 'topics', 'trainer_id', 'facility_id', 'type'];
    return {
      label: 'Úprava školenia',
      patches: [
        {
          path: `/api/trainings/${id}`,
          apply: (current) => {
            const d = current as TrainingDetail | undefined;
            if (!d) return undefined;
            return { ...d, training: mergeFields(d.training, f, keys) };
          },
        },
        {
          path: '/api/trainings',
          apply: (current) => updateListRow(current, id, (row) => mergeFields(row, f, keys)),
        },
      ],
    };
  }

  const inspection = INSPECTION_RE.exec(pathOnly);
  if (inspection) {
    const id = Number(inspection[1]);
    const keys = ['executed_on', 'notes', 'periodicity_months'];
    return {
      label: 'Úprava kontroly',
      patches: [
        {
          path: `/api/inspections/${id}`,
          apply: (current) => {
            const d = current as InspectionDetail | undefined;
            if (!d) return undefined;
            return { ...d, inspection: mergeFields(d.inspection, f, keys) };
          },
        },
        {
          path: '/api/inspections',
          apply: (current) => updateListRow(current, id, (row) => mergeFields(row, f, keys)),
        },
      ],
    };
  }

  return null;
}

/**
 * Optimistic handling for nested collection writes so entered items/trainees
 * appear immediately on the cached draft. Returns null for anything we don't
 * recognise (the caller then falls back to plain queueing / rethrow).
 */
export function autoOptimistic(
  method: 'POST' | 'PATCH' | 'DELETE',
  path: string,
  body: unknown,
): OptimisticSpec | null {
  const pathOnly = path.split('?')[0];

  if (method === 'PATCH') {
    const edit = topLevelEditOptimistic(pathOnly, body);
    if (edit) return edit;
  }

  const itemMatch = ITEMS_RE.exec(pathOnly);
  if (itemMatch) {
    const inspectionId = Number(itemMatch[1]);
    const itemId = itemMatch[2] !== undefined ? Number(itemMatch[2]) : null;
    const detailPath = `/api/inspections/${inspectionId}`;

    if (method === 'POST') {
      const newId = mintTempId();
      const ts = nowIso();
      const patch: CachePatch = {
        path: detailPath,
        apply: (current) => {
          const d = current as InspectionDetail | undefined;
          if (!d) return undefined;
          const item: InspectionItem = {
            id: newId,
            position: d.items.length + 1,
            fields: (body as Record<string, unknown>) ?? {},
            created_at: ts,
            updated_at: ts,
          };
          return { ...d, items: [...d.items, item] };
        },
      };
      return {
        patches: [patch],
        create: { clientId: newId, idPath: 'item.id' },
        label: 'Nová položka',
      };
    }

    if (itemId !== null && method === 'PATCH') {
      const patch: CachePatch = {
        path: detailPath,
        apply: (current) => {
          const d = current as InspectionDetail | undefined;
          if (!d) return undefined;
          return {
            ...d,
            items: d.items.map((it) =>
              it.id === itemId
                ? { ...it, fields: (body as Record<string, unknown>) ?? it.fields, updated_at: nowIso() }
                : it,
            ),
          };
        },
      };
      return { patches: [patch], label: 'Úprava položky' };
    }

    if (itemId !== null && method === 'DELETE') {
      const patch: CachePatch = {
        path: detailPath,
        apply: (current) => {
          const d = current as InspectionDetail | undefined;
          if (!d) return undefined;
          return { ...d, items: d.items.filter((it) => it.id !== itemId) };
        },
      };
      return { patches: [patch], label: 'Zmazať položku' };
    }
  }

  const traineeMatch = TRAINEES_RE.exec(pathOnly);
  if (traineeMatch) {
    const trainingId = Number(traineeMatch[1]);
    const traineeId = traineeMatch[2] !== undefined ? Number(traineeMatch[2]) : null;
    const detailPath = `/api/trainings/${trainingId}`;

    if (method === 'POST') {
      const newId = mintTempId();
      const ts = nowIso();
      const fullname = body instanceof FormData ? String(body.get('fullname') ?? '') : '';
      const position = body instanceof FormData ? (body.get('position') as string | null) : null;
      const patchTrainee: CachePatch = {
        path: detailPath,
        apply: (current) => {
          const d = current as TrainingDetail | undefined;
          if (!d) return undefined;
          const trainee: Trainee = {
            id: newId,
            fullname,
            position: position || null,
            has_signature: false,
            signed_at: null,
            created_at: ts,
            updated_at: ts,
          };
          return {
            ...d,
            training: { ...d.training, trainees_count: d.training.trainees_count + 1 },
            trainees: [...d.trainees, trainee],
          };
        },
      };
      return {
        patches: [patchTrainee],
        create: { clientId: newId, idPath: 'trainee.id' },
        label: 'Nový účastník',
        detail: fullname || undefined,
      };
    }

    if (traineeId !== null && method === 'DELETE') {
      const patch: CachePatch = {
        path: detailPath,
        apply: (current) => {
          const d = current as TrainingDetail | undefined;
          if (!d) return undefined;
          return {
            ...d,
            training: {
              ...d.training,
              trainees_count: Math.max(0, d.training.trainees_count - 1),
            },
            trainees: d.trainees.filter((t) => t.id !== traineeId),
          };
        },
      };
      return { patches: [patch], label: 'Zmazať účastníka' };
    }
  }

  return null;
}
