import { useAuth } from './AuthContext';

/**
 * Returns true when the active account's subscription has expired and the
 * current user is not an app admin (or on an admin-owned account). In this
 * state the UI should hide all write-action controls (create, edit, delete).
 */
export function useIsReadOnly(): boolean {
  const { accounts, activeAccountId, isAdmin } = useAuth();
  const account = accounts.find((a) => a.id === activeAccountId);
  if (!account) return false;
  if (isAdmin) return false;
  if (account.admin_owned) return false;

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const end = new Date(`${account.subscription_end_date}T00:00:00`);
  return !Number.isNaN(end.getTime()) && end < today;
}
