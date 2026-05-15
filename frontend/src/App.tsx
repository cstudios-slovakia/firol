import { Routes, Route, Navigate } from 'react-router-dom';
import { RequireAuth, RedirectIfAuthed, RequireBillingComplete } from '@/auth/RequireAuth';
import { AppShell } from '@/components/AppShell';
import { LoginPage } from '@/pages/auth/LoginPage';
import { RegisterPage } from '@/pages/auth/RegisterPage';
import { OnboardingBillingPage } from '@/pages/auth/OnboardingBillingPage';
import { PasswordResetRequestPage } from '@/pages/auth/PasswordResetRequestPage';
import { PasswordResetConfirmPage } from '@/pages/auth/PasswordResetConfirmPage';
import { DashboardPage } from '@/pages/DashboardPage';
import { CompanyDetailPage } from '@/pages/CompanyDetailPage';
import { CompanyEditPage } from '@/pages/CompanyEditPage';
import { FacilityDetailPage } from '@/pages/FacilityDetailPage';
import { FacilityEditPage } from '@/pages/FacilityEditPage';
import { NewInspectionTypePicker } from '@/pages/NewInspectionTypePicker';
import { InspectionStep1Page } from '@/pages/InspectionStep1Page';
import { InspectionStep2Page } from '@/pages/InspectionStep2Page';
import { InspectionDetailPage } from '@/pages/InspectionDetailPage';
import { InspectionsListPage } from '@/pages/InspectionsListPage';
import { TrainingsListPage } from '@/pages/TrainingsListPage';
import { NewTrainingPage } from '@/pages/NewTrainingPage';
import { TrainingDetailPage } from '@/pages/TrainingDetailPage';
import { TrainingEditPage } from '@/pages/TrainingEditPage';
import { SettingsPage } from '@/pages/SettingsPage';
import { BillingPage } from '@/pages/BillingPage';
import { AdminPage, RequireAdmin } from '@/pages/AdminPage';

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
        path="/onboarding/billing"
        element={
          <RequireAuth>
            <OnboardingBillingPage />
          </RequireAuth>
        }
      />

      <Route
        element={
          <RequireAuth>
            <RequireBillingComplete>
              <AppShell />
            </RequireBillingComplete>
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

        <Route path="/inspections" element={<InspectionsListPage />} />
        <Route path="/inspections/new" element={<NewInspectionTypePicker />} />
        <Route path="/inspections/new/:type/step-1" element={<InspectionStep1Page />} />
        <Route path="/inspections/:id" element={<InspectionDetailPage />} />
        <Route path="/inspections/:id/items/new" element={<InspectionStep2Page />} />
        <Route path="/inspections/:id/items/:itemId" element={<InspectionStep2Page />} />

        <Route path="/trainings" element={<TrainingsListPage />} />
        <Route path="/trainings/new" element={<NewTrainingPage />} />
        <Route path="/trainings/:id" element={<TrainingDetailPage />} />
        <Route path="/trainings/:id/edit" element={<TrainingEditPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="/billing" element={<BillingPage />} />
        <Route
          path="/admin"
          element={
            <RequireAdmin>
              <AdminPage />
            </RequireAdmin>
          }
        />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
