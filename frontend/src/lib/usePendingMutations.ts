import { useEffect, useState } from 'react';
import { liveQuery } from 'dexie';
import { db, type MutationEntry } from './db';
import { getActiveAccountId } from './session';

/**
 * Live view of the mutation outbox scoped to the active account.
 * Powered by Dexie's liveQuery so the UI updates instantly whenever
 * enqueueMutation / drainQueue mutates the underlying table.
 */
export function usePendingMutations(): MutationEntry[] {
  const [list, setList] = useState<MutationEntry[]>([]);
  useEffect(() => {
    const accountId = getActiveAccountId();
    const subscription = liveQuery(() =>
      db.mutations.where('accountId').equals(accountId ?? -1).sortBy('createdAt'),
    ).subscribe({
      next: (items) => setList(items),
      error: () => setList([]),
    });
    return () => subscription.unsubscribe();
  }, []);
  return list;
}
