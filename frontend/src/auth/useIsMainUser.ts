import { useAuth } from './AuthContext';

/**
 * True when the signed-in user is the main user (hlavný používateľ) of the
 * active account — the one who manages what belongs to the account as a
 * whole, e.g. the firemné oprávnenia of chapter 1.3.1.
 */
export function useIsMainUser(): boolean {
  const { user, accounts, activeAccountId } = useAuth();
  const account = accounts.find((a) => a.id === activeAccountId) ?? null;
  return user !== null && account !== null && account.main_user_id === user.id;
}
