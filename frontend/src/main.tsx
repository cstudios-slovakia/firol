import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { Agentation } from 'agentation';
import { AuthProvider } from '@/auth/AuthContext';
import { ApplyUpdateOnNavigate } from '@/components/ApplyUpdateOnNavigate';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { SessionExpiredNotice } from '@/components/SessionExpiredNotice';
import { ToastProvider } from '@/lib/toast';
import { ConfirmProvider } from '@/lib/confirm';
import { initPwa } from '@/lib/pwa';
import App from './App';
import './index.css';

initPwa();

ReactDOM.createRoot(document.getElementById('root') as HTMLElement).render(
  <React.StrictMode>
    <ErrorBoundary>
      <BrowserRouter>
        <ApplyUpdateOnNavigate />
        <AuthProvider>
          <ToastProvider>
            <SessionExpiredNotice />
            <ConfirmProvider>
              <App />
            </ConfirmProvider>
          </ToastProvider>
        </AuthProvider>
      </BrowserRouter>
    </ErrorBoundary>
    {import.meta.env.DEV && <Agentation endpoint="http://localhost:4747" />}
  </React.StrictMode>,
);
