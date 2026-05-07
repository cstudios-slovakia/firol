import { Routes, Route, Navigate } from 'react-router-dom';
import { RequireAuth, RedirectIfAuthed } from '@/auth/RequireAuth';
import { AppShell } from '@/components/AppShell';
import { LoginPage } from '@/pages/auth/LoginPage';
import { RegisterPage } from '@/pages/auth/RegisterPage';
import { PasswordResetRequestPage } from '@/pages/auth/PasswordResetRequestPage';
import { PasswordResetConfirmPage } from '@/pages/auth/PasswordResetConfirmPage';
import { DashboardPage } from '@/pages/DashboardPage';
import { CompanyDetailPage } from '@/pages/CompanyDetailPage';
import { CompanyEditPage } from '@/pages/CompanyEditPage';
import { FacilityDetailPage } from '@/pages/FacilityDetailPage';
import { FacilityEditPage } from '@/pages/FacilityEditPage';
import { PlaceholderPage } from '@/pages/PlaceholderPage';

export default function App() {
  return (
    <Routes>
      <Route
        path="/login"
        element={
          <RedirectIfAuthed>
            <LoginPage />
          </RedirectIfAuthed>
        }
      />
      <Route
        path="/register"
        element={
          <RedirectIfAuthed>
            <RegisterPage />
          </RedirectIfAuthed>
        }
      />
      <Route path="/password-reset" element={<PasswordResetRequestPage />} />
      <Route path="/password-reset/confirm" element={<PasswordResetConfirmPage />} />

      <Route
        element={
          <RequireAuth>
            <AppShell />
          </RequireAuth>
        }
      >
        <Route path="/" element={<DashboardPage />} />

        <Route path="/companies/new" element={<CompanyEditPage />} />
        <Route path="/companies/:id" element={<CompanyDetailPage />} />
        <Route path="/companies/:id/edit" element={<CompanyEditPage />} />
        <Route path="/companies/:companyId/facilities/new" element={<FacilityEditPage />} />

        <Route path="/facilities/:id" element={<FacilityDetailPage />} />
        <Route path="/facilities/:id/edit" element={<FacilityEditPage />} />

        <Route
          path="/inspections"
          element={
            <PlaceholderPage
              title="Kontroly"
              subtitle="Tu uvidíš všetky vykonané kontroly s možnosťou stiahnutia PDF protokolov."
            />
          }
        />
        <Route
          path="/trainings"
          element={
            <PlaceholderPage
              title="Školenia"
              subtitle="Sekcia pre školenia a podpisy účastníkov bude pripravená v ďalšej fáze."
            />
          }
        />
        <Route
          path="/settings"
          element={
            <PlaceholderPage
              title="Nastavenia"
              subtitle="Profil, technici, fakturácia a značka — pripravujeme."
            />
          }
        />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
