import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext';
import { Spinner } from '@/components/ui/Spinner';

export function RequireAuth({ children }: { children: React.ReactNode }) {
  const { status } = useAuth();
  const location = useLocation();

  if (status === 'loading') {
    return (
      <div className="bg-app flex min-h-screen items-center justify-center">
        <Spinner />
      </div>
    );
  }
  if (status === 'unauthed') {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }
  return <>{children}</>;
}

export function RedirectIfAuthed({ children }: { children: React.ReactNode }) {
  const { status } = useAuth();
  if (status === 'loading') {
    return (
      <div className="bg-app flex min-h-screen items-center justify-center">
        <Spinner />
      </div>
    );
  }
  if (status === 'authed') return <Navigate to="/" replace />;
  return <>{children}</>;
}

/**
 * Forces paid-flow registrants (chose monthly/yearly, haven't completed
 * Stripe checkout, missing billing details) onto /onboarding/billing
 * before they can enter the app. Trial users skip this guard because they
 * have no billing_period stored until they pick a plan in Settings.
 */
export function RequireBillingComplete({ children }: { children: React.ReactNode }) {
  const { accounts, activeAccountId, isAdmin } = useAuth();
  // App admins (the agency + the client) get free, full access — they
  // never go through Stripe and shouldn't be redirected to onboarding.
  if (isAdmin) return <>{children}</>;
  const account = accounts.find((a) => a.id === activeAccountId);
  if (account) {
    const needsBilling =
      account.billing_period !== null &&
      account.stripe_status !== 'active' &&
      account.stripe_status !== 'trialing' &&
      !account.has_billing_details;
    if (needsBilling) {
      return <Navigate to="/onboarding/billing" replace />;
    }
  }
  return <>{children}</>;
}
