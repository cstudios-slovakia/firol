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
