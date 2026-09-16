import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { setActiveAccountId, setCsrfToken } from '@/lib/session';
import { drainQueue } from '@/lib/queue';

export type User = {
  id: number;
  fullname: string;
  email: string;
  phone: string | null;
};

/**
 * Collapsed subscription state — derived in the backend from raw Stripe
 * fields so the UI doesn't have to interpret stripe_status itself.
 *
 * - `none`             — free trial only, no Stripe subscription yet
 * - `trial_paid`       — trialing with a subscription on file (will auto-charge)
 * - `active`           — paid subscription, fully active
 * - `past_due`         — payment failed, Stripe is retrying
 * - `canceled`         — subscription canceled (may still have access until period end)
 * - `incomplete`       — payment never completed or expired
 * - `has_subscription` — any other Stripe state with a subscription attached
 */
export type SubscriptionState =
  | 'none'
  | 'trial_paid'
  | 'active'
  | 'past_due'
  | 'canceled'
  | 'incomplete'
  | 'has_subscription';

export type Account = {
  id: number;
  invoice_company_name: string;
  subscription_end_date: string;
  main_user_id: number;
  stripe_status: string | null;
  stripe_cancel_at_period_end: boolean;
  billing_period: 'monthly' | 'yearly' | null;
  stripe_customer_id: string | null;
  has_billing_details: boolean;
  subscription_state: SubscriptionState;
  admin_owned: boolean;
};

/**
 * Published version + this user's recorded consent for one legal document.
 */
export type LegalDocStatus = {
  version: string;
  effective_from: string;
  /** e.g. "Všeobecné obchodné podmienky v1.0, účinné 1. 7. 2026" */
  label: string;
  url: string;
  accepted_version: string | null;
  accepted_at: string | null;
  needs_acceptance: boolean;
};

/**
 * Published legal documents and this user's recorded consent
 * (change request 3.1). The VOP and the privacy policy are versioned
 * independently since they can be revised on independent schedules.
 * `needs_acceptance` is true when either document is unconsented or the
 * user consented to an older revision — the app then shows the "new
 * version" notice after login.
 */
export type TermsSnapshot = {
  needs_acceptance: boolean;
  vop: LegalDocStatus;
  privacy: LegalDocStatus;
};

type Snapshot = {
  user: User | null;
  accounts: Account[];
  activeAccountId: number;
  csrfToken: string;
  isAdmin: boolean;
  terms?: TermsSnapshot;
};

export type AuthStatus = 'loading' | 'authed' | 'unauthed';

export type RegistrationPlan = 'trial' | 'monthly' | 'yearly';

type RegisterPayload = {
  fullname: string;
  email: string;
  phone?: string;
  password: string;
  invoice_company_name: string;
  billing_period: RegistrationPlan;
  /** Mandatory declaration from the registration form (change request 3.1). */
  terms_accepted: true;
};

type AuthContextValue = {
  status: AuthStatus;
  user: User | null;
  accounts: Account[];
  activeAccountId: number | null;
  csrfToken: string | null;
  isAdmin: boolean;
  terms: TermsSnapshot | null;
  login(email: string, password: string, remember?: boolean): Promise<void>;
  register(payload: RegisterPayload): Promise<string>;
  logout(): Promise<void>;
  switchAccount(accountId: number): Promise<void>;
  refresh(): Promise<void>;
  /** Record that the user acknowledged the current version of the documents. */
  acceptTerms(): Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('loading');
  const [snap, setSnap] = useState<Snapshot | null>(null);

  const apply = useCallback((data: Snapshot | null) => {
    setSnap(data);
    setStatus(data?.user ? 'authed' : 'unauthed');
    setActiveAccountId(data?.activeAccountId ?? null);
    setCsrfToken(data?.csrfToken ?? null);
    if (data?.user) {
      // Re-auth or account switch — try to flush any queued mutations.
      drainQueue().catch(() => undefined);
    }
  }, []);

  const refresh = useCallback(async () => {
    try {
      const data = await api<Snapshot>('/api/me');
      apply(data);
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        apply(null);
        return;
      }
      throw err;
    }
  }, [apply]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  useEffect(() => {
    // The api layer fires this once it has established the session is gone
    // for good (and has parked any interrupted write as a draft).
    const lost = () => apply(null);
    // ...and this when it silently renewed the CSRF token mid-session, so our
    // snapshot stops handing out the stale one.
    const refreshed = () => { refresh().catch(() => undefined); };
    window.addEventListener('firol:unauthorized', lost);
    window.addEventListener('firol:session-refreshed', refreshed);
    return () => {
      window.removeEventListener('firol:unauthorized', lost);
      window.removeEventListener('firol:session-refreshed', refreshed);
    };
  }, [apply, refresh]);

  const login = useCallback(
    async (email: string, password: string, remember = false) => {
      const data = await api<Snapshot>('/api/auth/login', {
        method: 'POST',
        body: { email, password, remember },
      });
      apply(data);
    },
    [apply],
  );

  const register = useCallback(
    async (payload: RegisterPayload): Promise<string> => {
      const data = await api<Snapshot>('/api/auth/register', {
        method: 'POST',
        body: payload,
      });
      apply(data);
      return data.csrfToken;
    },
    [apply],
  );

  const logout = useCallback(async () => {
    await api('/api/auth/logout', {
      method: 'POST',
      csrfToken: snap?.csrfToken,
    });
    apply(null);
  }, [apply, snap?.csrfToken]);

  const acceptTerms = useCallback(async () => {
    const data = await api<Snapshot>('/api/me/accept-terms', {
      method: 'POST',
      csrfToken: snap?.csrfToken,
    });
    apply(data);
  }, [apply, snap?.csrfToken]);

  const switchAccount = useCallback(
    async (accountId: number) => {
      const data = await api<Snapshot>('/api/me/switch-account', {
        method: 'POST',
        body: { account_id: accountId },
        csrfToken: snap?.csrfToken,
      });
      apply(data);
    },
    [apply, snap?.csrfToken],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      status,
      user: snap?.user ?? null,
      accounts: snap?.accounts ?? [],
      activeAccountId: snap?.activeAccountId ?? null,
      csrfToken: snap?.csrfToken ?? null,
      isAdmin: snap?.isAdmin ?? false,
      terms: snap?.terms ?? null,
      login,
      register,
      logout,
      switchAccount,
      refresh,
      acceptTerms,
    }),
    [status, snap, login, register, logout, switchAccount, refresh, acceptTerms],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
