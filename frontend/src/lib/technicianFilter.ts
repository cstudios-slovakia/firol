import { useCallback, useEffect, useMemo, useState } from 'react';
import { useAuth, type TeamIdentity } from '@/auth/AuthContext';

/**
 * Technician filter „Kto: [Všetci ▾] [Len moje]" — chapter 11.6.
 *
 * Offered in the calendar and on the Časová os (not on Dnes, not in the
 * Revízie / OPP / BOZP lists). Defaults to Všetci; the choice is remembered
 * between app openings, per account, and shared by the views that offer it —
 * narrowing the calendar to one technician and then opening the timeline
 * shows the same person. A solo technician never sees the filter.
 *
 * Use with `<TechnicianFilter filter={kto} />` and filter rows with
 * `kto.matches(row.technician?.id)`.
 */

/** 'all' = Všetci; a number = one member's user id. */
export type TechnicianFilterValue = 'all' | number;

const STORAGE_PREFIX = 'firol.kto.';

function read(key: string): TechnicianFilterValue {
  try {
    const raw = window.localStorage.getItem(key);
    if (raw === null || raw === 'all') return 'all';
    const n = Number(raw);
    return Number.isInteger(n) && n > 0 ? n : 'all';
  } catch {
    return 'all';
  }
}

function write(key: string, value: TechnicianFilterValue): void {
  try {
    window.localStorage.setItem(key, String(value));
  } catch {
    // Private mode / blocked storage — the filter still works for this visit.
  }
}

export type TechnicianFilterState = {
  value: TechnicianFilterValue;
  setValue: (value: TechnicianFilterValue) => void;
  /** Members to offer (active first — the roster order of /api/me). */
  team: TeamIdentity[];
  /** Signed-in user — the target of „Len moje". */
  currentUserId: number | null;
  /** True when there is nobody else to filter by — the control is hidden. */
  isSolo: boolean;
  /**
   * Whether a termín of the given technician passes the filter. A termín with
   * no known technician (a legacy vlastná udalosť) only shows under Všetci.
   */
  matches: (technicianId: number | null | undefined) => boolean;
};

export function useTechnicianFilter(): TechnicianFilterState {
  const { team, user, activeAccountId } = useAuth();
  const storageKey = `${STORAGE_PREFIX}${activeAccountId ?? 0}`;
  const [stored, setStored] = useState<TechnicianFilterValue>(() => read(storageKey));

  // Account switch → that account's own remembered choice.
  useEffect(() => {
    setStored(read(storageKey));
  }, [storageKey]);

  const isSolo = team.length <= 1;

  // A remembered member who has since left the team falls back to Všetci; a
  // solo technician is never filtered at all.
  const value: TechnicianFilterValue =
    isSolo || (stored !== 'all' && !team.some((m) => m.id === stored)) ? 'all' : stored;

  const setValue = useCallback(
    (next: TechnicianFilterValue) => {
      setStored(next);
      write(storageKey, next);
    },
    [storageKey],
  );

  const matches = useCallback(
    (technicianId: number | null | undefined) =>
      value === 'all' || (technicianId !== null && technicianId !== undefined && technicianId === value),
    [value],
  );

  return useMemo(
    () => ({
      value,
      setValue,
      team,
      currentUserId: user?.id ?? null,
      isSolo,
      matches,
    }),
    [value, setValue, team, user?.id, isSolo, matches],
  );
}
