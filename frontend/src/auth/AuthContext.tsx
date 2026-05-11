import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '@/lib/api';

export type User = {
  id: number;
  fullname: string;
  email: string;
  phone: string | null;
};

export type Account = {
  id: number;
  invoice_company_name: string;
  subscription_end_date: string;
  main_user_id: number;
  stripe_status: string | null;
  billing_period: 'monthly' | 'yearly' | null;
  stripe_customer_id: string | null;
};

type Snapshot = {
  user: User | null;
  accounts: Account[];
  activeAccountId: number;
  csrfToken: string;
};

export type AuthStatus = 'loading' | 'authed' | 'unauthed';

type RegisterPayload = {
  fullname: string;
  email: string;
  phone?: string;
  password: string;
  invoice_company_name: string;
  billing_period: 'monthly' | 'yearly';
};

type AuthContextValue = {
  status: AuthStatus;
  user: User | null;
  accounts: Account[];
  activeAccountId: number | null;
  csrfToken: string | null;
  login(email: string, password: string): Promise<void>;
  register(payload: RegisterPayload): Promise<void>;
  logout(): Promise<void>;
  switchAccount(accountId: number): Promise<void>;
  refresh(): Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('loading');
  const [snap, setSnap] = useState<Snapshot | null>(null);

  const apply = useCallback((data: Snapshot | null) => {
    setSnap(data);
    setStatus(data?.user ? 'authed' : 'unauthed');
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

  const login = useCallback(
    async (email: string, password: string) => {
      const data = await api<Snapshot>('/api/auth/login', {
        method: 'POST',
        body: { email, password },
      });
      apply(data);
    },
    [apply],
  );

  const register = useCallback(
    async (payload: RegisterPayload) => {
      const data = await api<Snapshot>('/api/auth/register', {
        method: 'POST',
        body: payload,
      });
      apply(data);
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
      login,
      register,
      logout,
      switchAccount,
      refresh,
    }),
    [status, snap, login, register, logout, switchAccount, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
