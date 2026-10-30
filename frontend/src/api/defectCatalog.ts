/**
 * Číselník nedostatkov (spec 3.4, Phase 4).
 *
 * The catalogue a technician picks a nedostatok from: the 27 shipped items plus
 * the account's own („vlastný nedostatok", offered at every firm of the account).
 *
 * Offline handling, per endpoint:
 *   GET    /api/defect-catalog        cached by `lib/api.ts`; the picker works offline
 *   GET    /api/defect-catalog/usage  cached the same way („Najčastejšie")
 *   POST   /api/defect-catalog        queued, optimistic temp id (see {@link createOptimistic})
 *   PATCH  /api/defect-catalog/{id}   queued, the cached list is patched at once
 *   DELETE /api/defect-catalog/{id}   queued (archives), the cached list is patched at once
 * A queued PATCH/DELETE of an item that itself exists only offline is replayed
 * behind its create, with the temp id rewritten by the outbox.
 */
import { useCallback, useEffect, useState } from 'react';
import { api, type OptimisticSpec } from '@/lib/api';
import { mintTempId } from '@/lib/tempId';

/** po = požiarny, bo = BOZP, ob = oboje (spec 3.4 `o`). */
export type DefectScope = 'po' | 'bo' | 'ob';

export type DefectCatalogItem = {
  id: number;
  title: string;
  scope: DefectScope;
  /** Oblasť — only groups/labels the picker. */
  area: string;
  /** Catalogue lehota text, e.g. „do 30 dní". */
  default_deadline: string;
  /** Popis zisteného stavu. */
  description: string;
  /** Predpis — its own field, never part of the description. */
  regulation: string;
  /** Odôvodnenie, one sentence. */
  justification: string;
  /** Navrhované opatrenie. */
  measure: string;
  /** An item of this account (editable / removable); false = shipped, read-only. */
  own: boolean;
};

export type DefectCatalogPayload = {
  title: string;
  scope: DefectScope;
  description: string;
  regulation: string;
  justification: string;
  measure: string;
};

export type DefectUsage = { catalog_id: number; count: number };

export const DEFAULT_DEADLINE = 'do 30 dní';
export const OWN_AREA = 'Vlastné';

export const SCOPE_LABEL: Record<DefectScope, string> = {
  po: 'požiarny',
  bo: 'BOZP',
  ob: 'požiarny aj BOZP',
};

/** Fired after any catalogue write, so every mounted picker re-reads the list. */
export const DEFECT_CATALOG_CHANGED_EVENT = 'firol:defect-catalog-changed';

const LIST_PATH = '/api/defect-catalog';
const USAGE_PATH = '/api/defect-catalog/usage';

function announce() {
  window.dispatchEvent(new Event(DEFECT_CATALOG_CHANGED_EVENT));
}

async function write<T>(run: () => Promise<T>): Promise<T> {
  try {
    return await run();
  } finally {
    announce();
  }
}

type ListCache = { items?: DefectCatalogItem[] } | undefined;

/** What an own item created offline looks like until it syncs. */
function createOptimistic(body: DefectCatalogPayload): OptimisticSpec {
  const id = mintTempId();
  const item: DefectCatalogItem = {
    id,
    ...body,
    area: OWN_AREA,
    default_deadline: DEFAULT_DEADLINE,
    own: true,
  };
  return {
    returns: { item },
    create: { clientId: id, idPath: 'item.id' },
    label: 'Nový nedostatok v číselníku',
    detail: body.title,
    patches: [
      {
        path: LIST_PATH,
        apply: (current) => {
          const list = current as ListCache;
          return { ...(list ?? {}), items: [...(list?.items ?? []), item] };
        },
      },
    ],
  };
}

function updateOptimistic(id: number, body: Partial<DefectCatalogPayload>, title: string): OptimisticSpec {
  return {
    // Non-undefined, so a queued edit resolves instead of throwing; the pickers
    // re-read the (patched) list on the change event.
    returns: {},
    label: 'Úprava nedostatku v číselníku',
    detail: body.title ?? title,
    patches: [
      {
        path: LIST_PATH,
        apply: (current) => {
          const list = current as ListCache;
          if (!list?.items) return undefined;
          return {
            ...list,
            items: list.items.map((it) => (it.id === id ? { ...it, ...body } : it)),
          };
        },
      },
    ],
  };
}

function removeOptimistic(id: number, title: string): OptimisticSpec {
  return {
    returns: null,
    label: 'Odstránenie nedostatku z číselníka',
    detail: title,
    patches: [
      {
        path: LIST_PATH,
        apply: (current) => {
          const list = current as ListCache;
          if (!list?.items) return undefined;
          return { ...list, items: list.items.filter((it) => it.id !== id) };
        },
      },
    ],
  };
}

export const DefectCatalog = {
  list: () => api<{ items: DefectCatalogItem[] }>(LIST_PATH),

  /** The signed-in technician's most used items, most used first („Najčastejšie"). */
  usage: () => api<{ items: DefectUsage[] }>(USAGE_PATH),

  create: (body: DefectCatalogPayload, csrfToken: string | null) =>
    write(() =>
      api<{ item: DefectCatalogItem }>(LIST_PATH, {
        method: 'POST',
        body,
        csrfToken,
        optimistic: createOptimistic(body),
      }),
    ),

  update: (item: DefectCatalogItem, body: Partial<DefectCatalogPayload>, csrfToken: string | null) =>
    write(() =>
      api<{ item?: DefectCatalogItem }>(`${LIST_PATH}/${item.id}`, {
        method: 'PATCH',
        body,
        csrfToken,
        optimistic: updateOptimistic(item.id, body, item.title),
      }),
    ),

  /** Archives an own item; it vanishes from every list, written defects keep their text. */
  remove: (item: DefectCatalogItem, csrfToken: string | null) =>
    write(() =>
      api<void>(`${LIST_PATH}/${item.id}`, {
        method: 'DELETE',
        csrfToken,
        optimistic: removeOptimistic(item.id, item.title),
      }),
    ),
};

/**
 * The catalogue plus the technician's usage counts, re-read after every
 * catalogue write (also one that was only queued), when the outbox syncs and
 * when the device comes back online.
 */
export function useDefectCatalog(): {
  items: DefectCatalogItem[];
  usage: DefectUsage[];
  loading: boolean;
  error: boolean;
} {
  const [items, setItems] = useState<DefectCatalogItem[]>([]);
  const [usage, setUsage] = useState<DefectUsage[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [tick, setTick] = useState(0);

  const bump = useCallback(() => setTick((t) => t + 1), []);

  useEffect(() => {
    window.addEventListener(DEFECT_CATALOG_CHANGED_EVENT, bump);
    window.addEventListener('firol:remap', bump);
    window.addEventListener('online', bump);
    return () => {
      window.removeEventListener(DEFECT_CATALOG_CHANGED_EVENT, bump);
      window.removeEventListener('firol:remap', bump);
      window.removeEventListener('online', bump);
    };
  }, [bump]);

  useEffect(() => {
    let cancelled = false;
    DefectCatalog.list()
      .then((res) => {
        if (cancelled) return;
        setItems(res.items);
        setError(false);
      })
      .catch(() => {
        // Offline without a cached catalogue.
        if (!cancelled) setError(true);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    DefectCatalog.usage()
      .then((res) => {
        if (!cancelled) setUsage(res.items);
      })
      .catch(() => {
        // „Najčastejšie" is optional — without it the block just doesn't render.
        if (!cancelled) setUsage([]);
      });
    return () => {
      cancelled = true;
    };
  }, [tick]);

  return { items, usage, loading, error };
}
