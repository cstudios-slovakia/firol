import { useAuth } from './AuthContext';
import { useIsMainUser } from './useIsMainUser';

type Ukon = {
  status: 'draft' | 'finalized';
  created_by_user_id: number | null;
};

/**
 * Práva členov (chapter 1.6). With the account switched to „obmedzené", a
 * member doesn't get the buttons that delete a finished úkon or discard its
 * protocol at all (hidden, not disabled). Their own draft stays deletable.
 * The main user and a platform admin are never restricted. The backend
 * enforces the same rule (MemberRights.php).
 */
export function useMemberRights() {
  const { user, accounts, activeAccountId, isAdmin } = useAuth();
  const isMain = useIsMainUser();
  const account = accounts.find((a) => a.id === activeAccountId) ?? null;
  const canDelete = isAdmin || isMain || account?.member_rights !== 'obmedzene';

  /** `assigneeId` stands in for the creator on úkony created before that was recorded. */
  function canDeleteUkon(ukon: Ukon, assigneeId: number | null): boolean {
    if (canDelete) return true;
    if (ukon.status !== 'draft' || user === null) return false;
    return ukon.created_by_user_id !== null
      ? ukon.created_by_user_id === user.id
      : assigneeId === user.id;
  }

  return { canDelete, canDeleteUkon };
}
