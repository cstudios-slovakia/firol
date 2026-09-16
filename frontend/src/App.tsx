import { Routes, Route, Navigate } from 'react-router-dom';
import { RequireAuth, RedirectIfAuthed, RequireBillingComplete } from '@/auth/RequireAuth';
import { AppShell } from '@/components/AppShell';
import { LoginPage } from '@/pages/auth/LoginPage';
import { RegisterPage } from '@/pages/auth/RegisterPage';
import { OnboardingBillingPage } from '@/pages/auth/OnboardingBillingPage';
import { PasswordResetRequestPage } from '@/pages/auth/PasswordResetRequestPage';
import { PasswordResetConfirmPage } from '@/pages/auth/PasswordResetConfirmPage';
import { InviteAcceptPage } from '@/pages/auth/InviteAcceptPage';
import { DashboardPage } from '@/pages/DashboardPage';
import { CompaniesPage } from '@/pages/CompaniesPage';
import { CompanyDetailPage } from '@/pages/CompanyDetailPage';
import { CompanyEditPage } from '@/pages/CompanyEditPage';
import { FacilityDetailPage } from '@/pages/FacilityDetailPage';
import { FacilityEditPage } from '@/pages/FacilityEditPage';
import { NewInspectionTypePicker } from '@/pages/NewInspectionTypePicker';
import { InspectionStep1Page } from '@/pages/InspectionStep1Page';
import { InspectionStep2Page } from '@/pages/InspectionStep2Page';
import { InspectionDetailPage } from '@/pages/InspectionDetailPage';
import { InspectionsListPage } from '@/pages/InspectionsListPage';
import { SectionPage } from '@/pages/SectionPage';
import { VisitNewPage } from '@/pages/VisitNewPage';
import { AuditFillPage } from '@/pages/AuditFillPage';
import { AuditTemplatesPage } from '@/pages/AuditTemplatesPage';
import { VisitDetailPage } from '@/pages/VisitDetailPage';
import { CalendarPage } from '@/pages/CalendarPage';
import { TrainingsListPage } from '@/pages/TrainingsListPage';
import { NewTrainingPage } from '@/pages/NewTrainingPage';
import { TrainingDetailPage } from '@/pages/TrainingDetailPage';
import { TrainingEditPage } from '@/pages/TrainingEditPage';
import {
  SettingsLayout,
  SettingsIndexPage,
  InspectorProfilePage,
  BrandingPage,
  TeamPage,
  DataPage,
  SystemPage,
} from '@/pages/SettingsPage';
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
      <Route path="/invite/accept" element={<InviteAcceptPage />} />

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
        <Route path="/companies" element={<CompaniesPage />} />

        <Route path="/companies/new" element={<CompanyEditPage />} />
        <Route path="/companies/:id" element={<CompanyDetailPage />} />
        <Route path="/companies/:id/edit" element={<CompanyEditPage />} />
        <Route path="/companies/:companyId/facilities/new" element={<FacilityEditPage />} />

        <Route path="/facilities/:id" element={<FacilityDetailPage />} />
        <Route path="/facilities/:id/edit" element={<FacilityEditPage />} />

        <Route path="/kalendar" element={<CalendarPage />} />

        {/* The three sections (chapter 2), spelled out rather than matched as
            a pattern — the router takes a literal segment, and three routes
            read more plainly than one that has to be decoded. /inspections
            stays as an unsectioned list: it is nowhere in the menu, but
            in-app links and bookmarks still point at it. */}
        <Route path="/revizie" element={<SectionPage />} />
        <Route path="/opp" element={<SectionPage />} />
        <Route path="/bozp" element={<SectionPage />} />
        <Route path="/inspections" element={<InspectionsListPage />} />

        {/* Návšteva (chapter 9) — an activity, not a section: it belongs to no
            odbor and offers types from all of them at once. */}
        <Route path="/visits/new" element={<VisitNewPage />} />
        <Route path="/visits/:id" element={<VisitDetailPage />} />
        <Route path="/inspections/new" element={<NewInspectionTypePicker />} />
        <Route path="/inspections/new/:type/step-1" element={<InspectionStep1Page />} />
        <Route path="/inspections/:id" element={<InspectionDetailPage />} />
        {/* Block 3 — an audit answers a checklist rather than adding items
            one by one, so it has its own screen instead of Step 2. */}
        <Route path="/inspections/:id/audit" element={<AuditFillPage />} />
        <Route path="/inspections/:id/items/new" element={<InspectionStep2Page />} />
        <Route path="/inspections/:id/items/:itemId" element={<InspectionStep2Page />} />

        <Route path="/trainings" element={<TrainingsListPage />} />
        <Route path="/trainings/new" element={<NewTrainingPage />} />
        <Route path="/trainings/:id" element={<TrainingDetailPage />} />
        <Route path="/trainings/:id/edit" element={<TrainingEditPage />} />
        <Route path="/settings" element={<SettingsLayout />}>
          <Route index element={<SettingsIndexPage />} />
          <Route path="profil" element={<InspectorProfilePage />} />
          <Route path="branding" element={<BrandingPage />} />
          <Route path="technici" element={<TeamPage />} />
          <Route path="audity" element={<AuditTemplatesPage />} />
          <Route path="data" element={<DataPage />} />
          <Route path="systemove" element={<SystemPage />} />
          <Route path="admin" element={<RequireAdmin><AdminPage /></RequireAdmin>} />
        </Route>
        <Route path="/billing" element={<BillingPage />} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
