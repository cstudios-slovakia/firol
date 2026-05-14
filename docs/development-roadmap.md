# Firol — development roadmap

Persistent plan for building the app. Each phase has a clear scope and a
status. Update this file when scope is clarified, when a phase finishes,
or when a new phase is added.

**Source of truth** for *what* to build is `Firol base document-*.md` +
`handoff/`. Nothing else.

Status legend: ⬜ todo · 🟡 in progress · ✅ done · ⏸ blocked / waiting

---

## Phase 0 — Foundation ✅
- ✅ Repo, monorepo layout (frontend/ + backend/ + docker/ + docs/)
- ✅ Docker compose: PHP-FPM 8.5, nginx :8080, MariaDB :3306
- ✅ Vite + React 19 + TS + Tailwind v4 + `cn()` + Lucide
- ✅ Composer + PSR-4 `Firol\` namespace, vlucas/phpdotenv
- ✅ GitHub Actions deploy to Websupport (firol.cstudios.ninja)
- ✅ /api/health endpoint reachable in dev (Vite proxy) and prod
  (`/api.php?path=/api/health` — webroot is `frontend/dist/`,
  PHP entry copied there from `frontend/public/api.php`)

## Phase 1 — Database, auth, multi-tenancy ✅
The whole app depends on this. Everything has `account_id`.

### 1a — DB & migrations ✅
- ✅ PDO connection helper (`Firol\Db`)
- ✅ Migration runner script (numbered `*.sql` files, tracked in `migrations` table)
- ✅ Schema applied locally:
  - `users`, `accounts`, `account_users`, `password_resets`, `system_settings`
- Notes: dev image temporarily on PHP 8.4 (8.5 docker had a broken
  `docker-php-ext-install` pipeline). Composer constraint relaxed to ^8.4.
  Prod (Websupport) still runs 8.5. DB healthcheck added to compose so
  migrations run after MariaDB initialises.

### 1b — Auth backbone (PHP) ✅
- ✅ Tiny regex router (`Firol\Http\Router`) replacing the if-chain
- ✅ `Request` / `Response` helpers (JSON in/out)
- ✅ `Session` helper (HttpOnly cookie, SameSite=Lax)
- ✅ `Csrf` token + `Csrf::require()` for state-changing requests
- ✅ `Tenant::currentUserId()` / `currentAccountId()` guards
- ✅ Endpoints: `POST /api/auth/{register,login,logout}`,
  `POST /api/auth/password-reset/{request,confirm}`,
  `GET /api/me`, `POST /api/me/switch-account`
- ✅ Verified locally with curl: register 201, /me 200/401, login 200/401,
  logout 403/204 (CSRF on/off), reset 204, duplicate register 409
- ⏸ Invited-technician flow → deferred to Phase 5 (Settings → Technicians)
  where it actually appears in the UI; the password-reset machinery here
  reuses the same `password_resets` table when added.
- ⏸ Real email transport — Phase 7. Reset links currently logged via
  `error_log()` (visible in `docker compose logs php`).

### 1c — Frontend auth flows ✅
- ✅ Design tokens (HSL palettes, brand `firol-*`, neutral `ink-*`, status
  triplet, shadows, radii) in `index.css` via Tailwind v4 `@theme`
- ✅ Base components (Button, Input, Field, Card, Badge, Spinner)
- ✅ `lib/api.ts` — fetch wrapper with `credentials: 'include'` + CSRF
- ✅ `auth/AuthContext` + `RequireAuth` / `RedirectIfAuthed` route guards
- ✅ Login, Registration, Password-reset (request + confirm) screens with
  shared `AuthLayout`
- ✅ Account switcher (renders only when user has 2+ accounts)
- ✅ Dashboard placeholder (real list of companies lands in Phase 2)
- ✅ React Router wired in `App.tsx`, providers in `main.tsx`
- ✅ Build passes (`tsc -b && vite build`)

## Decisions (locked, do not invent more)
- **Company entity**: `name`, `ico`, `address` (free text), `contact` (free text).
  Nothing else. No DIČ, no street/city breakdown.
- **Facility entity**: `name`, `address` (free text), `notes`, `contact_person`
  (fullname only — no email/phone field).
- **Periodicity defaults**: per facility, **per inspection type**, even for
  types whose interval is fixed (the picker still respects the stored default).
  Configurable in Settings → Default periodicities.
- **Invited technician**: receives an email with a tokenized link to set
  their own password. Application never auto-generates and emails passwords.

## Phase 2 — Companies & facilities ✅
The technician's data, no inspections yet.

- ✅ DB: `companies`, `facilities` (with `account_id`, soft-delete via
  `archived_at`); migration 002
- ✅ CRUD endpoints (search by name/IČO, soft-delete / archive); all routes
  go through `Csrf::require()` for state-changing methods and through
  `Tenant::currentAccountId()` for scoping
- ✅ Dashboard "Moje firmy": debounced search, company cards with neutral
  "Žiadne kontroly" badge until Phase 3 wires real status
- ✅ Company detail (info, facilities list, "+ Pridať prevádzku")
- ✅ Facility detail (info, inspections-history placeholder)
- ✅ Bottom tab bar: Firmy / Kontroly / Školenia / Nastavenia (mobile only;
  Kontroly / Školenia / Nastavenia are placeholder pages until later phases)
- ✅ AppShell layout with sticky header (brand, account switcher, logout)
- ✅ Multi-tenancy isolation verified — Petra cannot list, read or mutate
  Jan's companies (returns empty / 404 in all paths)

## Phase 3 — Inspection types 🟡
Implement one type fully end-to-end (RPHP), then replicate the pattern.
Phase 3 is split into **3a-1 → 3a-2 → 3a-3 → 3a-4** for RPHP, then
3b/3c/… for the other types.

### 3a-1 — Foundation: schema + Inspector profile + Step 1 ✅
- ✅ DB migration 003: `inspections`, `inspection_items` (JSON `fields`),
  `documents`, `document_sequences`, `inspector_profiles`
- ✅ Type slugs locked (used both as `inspections.type` and as
  `documents.type`): `rphp`, `hydranty`, `oprava_ts_rphp`,
  `poziarna_kniha`, `pu_akcieschopnost`, `pu_udrzba`,
  `nudzove_osvetlenie`, `ts_hadic`
- ✅ Backend `InspectionController` — list / show / create draft /
  patch (date, periodicity, notes) / archive; per-type periodicity
  validation, multi-tenancy on every query
- ✅ Backend `InspectorProfileController` — show / update / signature
  upload (PNG, max 512 KB, real MIME sniff) / signature stream;
  one row per (user_id, account_id), auto-created on first read
- ✅ Storage helper (`Firol\Storage\Storage`) keeps signatures and
  future PDFs outside the docroot under `backend/storage/` (gitignored)
- ✅ Frontend: API clients, type picker (8 cards, only RPHP active —
  others show "Čoskoro"), Step 1 form (date strictly manual, hint
  "Zadaj manuálne, nemusí byť dnešný dátum"), draft detail placeholder
- ✅ Settings → Inspector profile page (cert number, validity dates,
  signature upload + preview)
- ✅ "+ Nová kontrola" entry points on Dashboard, Company detail,
  Facility detail (preserving company/facility context via query string)
- ✅ Routes wired: `/inspections`, `/inspections/new`,
  `/inspections/new/:type/step-1`, `/inspections/:id`, `/settings`

### 3a-2 — Step 2 + Step 3 (without PDF) ✅
- ✅ Item CRUD: `POST /api/inspections/{id}/items`,
  `PATCH/DELETE /api/inspections/{id}/items/{item_id}`; per-type validator
  dispatch (RPHP enforced, others rejected with 422 until they ship)
- ✅ Item position auto-assigned (max + 1) on insert; deletes leave
  positions sparse — PDF render will renumber on the fly
- ✅ Finalized inspections reject item mutations (409)
- ✅ Step 2 — RPHP per-extinguisher form (Výrobca, Typ, Sériové č., Rok
  výroby, Umiestnenie, Stav A/TS/O/V, Poznámky); two CTAs
  „Uložiť a ďalší" / „Prejsť na súhrn"; progress dots clickable
  per saved item; edit & add modes share the route
- ✅ Step 3 — orange-highlighted editable date (critical for Opakovať),
  statistics box (A/TS/O/V counts), per-item rows with status badge,
  Opraviť & Trash icons, „+ Pridať prístroj" header CTA, disabled
  „Generovať PDF" placeholder
- ✅ Step 1 redirect → `/inspections/:id/items/new` so the flow is
  continuous (Step 1 → Step 2 → Step 3)
- ✅ Routes: `/inspections/:id/items/new`, `/inspections/:id/items/:itemId`

### 3a-3 — PDF generation + Document numbering + History ✅
- ✅ mPDF 8.3 added (composer); Docker dev image gained `gd` and
  `mbstring` extensions (Dockerfile rebuilt)
- ✅ `Firol\Pdf\PdfRenderer::renderRphp()` + standalone PHP template at
  `backend/src/Pdf/templates/rphp.php` — branded header (Firol red
  default, account theme later), client / facility / kontrola sections,
  items table with status pills, statistics row, signature block with
  inline PNG signature data URI, footer with document number
- ✅ `Firol\Documents\NumberAllocator` — atomic per-account+type+year
  via `document_sequences` + transaction + FOR UPDATE; format
  `<PREFIX>-<YEAR>-<SEQ:03d>` (RPHP-2026-001 etc.)
- ✅ `DocumentController` — `POST /api/inspections/{id}/generate-pdf`
  (transactional: number reservation, file write, documents row,
  inspection → finalized), `GET /api/inspections/{id}/documents`,
  `GET /api/documents/{id}/download` (auth + tenant check, streams PDF)
- ✅ Storage layout: `backend/storage/documents/{accountId}/{year}/{number}.pdf`,
  mPDF temp dir `backend/storage/mpdf-tmp/`
- ✅ Frontend: `Generovať PDF protokol` button (red, mapped to
  `status-bad` per spec), opens PDF in new tab on success; lists all
  generated protocols with download icons; date + items lock when
  finalized
- ✅ Inspection history list on Facility detail page (chronological,
  status badge, PDF icon for finalized)
- ✅ Re-generation rejected with 409 — fresh protocol requires Opakovať
  flow (Phase 3a-4)

### 3a-4 — Opakovať flow + polish ✅
- ✅ `POST /api/inspections/{id}/repeat` — clones a finalized inspection
  into a fresh draft (items copied via `INSERT … SELECT`, `executed_on`
  reset to NULL, status = draft); rejects drafts (422), rejects
  `poziarna_kniha` (each entry is unique per spec)
- ✅ Verified locally: re-issued protocol got fresh sequence
  (`RPHP-2027-001`) because the new date was in a different year bucket
- ✅ "Opakovať" CTA on `InspectionDetailPage` (replaces the "Pridať
  prístroj" button when finalized) and as an icon button on
  `FacilityDetailPage` history rows; hidden for `poziarna_kniha`
- ✅ Global `<ErrorBoundary>` mounted at the app root — uncaught render
  errors show a friendly „Skús načítať znova" panel instead of a blank
  page; per-page async errors continue to flow through local state
- ✅ Dashboard company cards now show `N kontrol · posledná D. M. RRRR`
  badge instead of the static „Žiadne kontroly"; backend
  `GET /api/companies` returns `inspections_count` + `last_inspection_at`
  via subqueries (single round-trip)

### Phase 3a — RPHP end-to-end ✅
RPHP is now feature-complete: schema → Step 1 (basic data) → Step 2
(per-prístroj entry, edit, delete) → Step 3 (summary, statistics,
editable date) → PDF generation with branded header, signature stamp,
sequential numbering → Opakovať flow for repeat protocols. The same
pattern replicates to the remaining 7 inspection types — Phase 3b
onward is mostly per-type form + per-type PDF template.

### 3b — Hydranty ✅
- ✅ Backend validator branch in `InspectionItemController` for hydranty:
  type enum DN25/DN33/DN52/C52/other (with `type_other` free text when
  `other`), location, hose_count, HS/HD (MPa), Q (l/s), defects, result
  (vyhovuje/nevyhovuje); shared `nonNegativeInt` + `float` helpers
- ✅ `PdfRenderer::renderForType()` dispatcher + standalone
  `templates/hydranty.php` — measurements column shows MPa / l/s,
  Vyhovuje/Nevyhovuje pills, type "other" rendered as the user-supplied
  free-text label
- ✅ `DocumentController::computeStats()` per-type branch
  (RPHP=A/TS/O/V, hydranty=vyhovuje/nevyhovuje, total)
- ✅ Frontend: `inspection-types/` registry — each type exports
  `Step2Form` / `ItemRow` / `StatsBar`; `InspectionStep2Page` and
  `InspectionDetailPage` are now type-agnostic wrappers driven by
  `getTypeModule(type)`
- ✅ Hydranty card enabled in `NewInspectionTypePicker`; full flow
  verified locally — created hydranty inspection, added DN52 + custom
  "DN40" hydrants with HS/HD/Q values, generated `HYD-2026-001`

### 3c — Oprava + plnenie + TS RPHP ✅
- ✅ Backend: `oprava_ts_rphp` validator (RPHP-style identification +
  `actions` multi-select from {tlakova_skuska, oprava, plnenie}); reject
  empty actions
- ✅ Stats branch counts how many items had each action (an item can
  contribute to multiple buckets)
- ✅ PDF template with action-pill column; verified `OPR-RPHP-2026-001`
- ✅ Frontend module + enabled in TypePicker

### 3d — Požiarna kniha ✅
- ✅ Backend: `poziarna_kniha` validator (workspaces, activities[] from
  14 predefined slugs, `activities_other` free text, result enum,
  notes); single-record protocol — second `POST /items` rejected with
  409 by the controller
- ✅ Stats reduces to a single overall result
- ✅ PDF template with checkbox-style activity list, full-width result
  banner; verified `PK-2026-001`
- ✅ Frontend module — single-shot form (no „save and next" CTA),
  „+ Pridať záznam" hidden in Step 3 once one entry exists
- ✅ Already-coded: Opakovať flow rejects this type (per spec)

### 3e + 3f — Požiarne uzávery (AK + UD) ✅
- ✅ Backend: shared `PU_KINDS = ['dvere','okno','klapka']`; AK fields
  (kind, identifier, manufacturer, location, result, notes) and UD
  fields (same minus manufacturer, plus mandatory `maintenance_work`)
- ✅ Two PDF templates with shared layout but different columns
  (manufacturer for AK vs maintenance_work for UD); verified
  `PU-AK-2026-001` and `PU-UD-2026-001`
- ✅ Two frontend modules + both enabled in TypePicker

### 3g — Núdzové osvetlenie ✅
- ✅ Backend: `nudzove_osvetlenie` validator (luminaire_type,
  manufacturer, location, `duration_min` 0–600, result, notes)
- ✅ PDF template with duration column (whole minutes); verified
  `NO-2026-001`
- ✅ Frontend module + enabled in TypePicker

### 3h — TS hadíc ✅
- ✅ Backend: `ts_hadic` validator (hose_type, serial, location,
  `test_pressure` 0–50 MPa, result, notes)
- ✅ PDF template; verified `TS-HAD-2026-001`
- ✅ Frontend module + enabled in TypePicker

### Phase 3 — all 8 inspection types ✅
RPHP, Hydranty, Oprava+TS RPHP, Požiarna kniha, PU akcieschopnosť,
PU údržba, Núdzové osvetlenie, TS hadíc — all live, all generate PDFs
with their own number prefix and per-type stats. Pattern stabilised in
the `inspection-types/` registry: a new type ships in one backend
validator branch + one PHP template + one frontend module file.

## Phase 4 — Trainings ✅
Split into 4a (foundation), 4b (trainees + canvas signatures),
4c (PDF protocol). Mirrors the Phase 3a split that worked well for RPHP.

### 4a — Foundation: trainers + training header CRUD ✅
- ✅ Migration 004: `trainers`, `trainings`, `trainees`. Locked training
  type slugs: `vstupne`, `opakovane`, `opp_mimo`, `zdrzujuca_sa`,
  `hliadka_oph`, `hliadka_opah`. `documents.parent_type` already
  supports `'training'` from migration 003.
- ✅ Backend `TrainerController` — full CRUD per account + PNG
  signature upload/stream (same pattern as inspector signature)
- ✅ Backend `TrainingController` — list (filters: company/facility/type),
  show (with embedded trainees array), create draft, patch (date /
  trainer / topics / duration), archive. Multi-tenancy on every query;
  facility (optional) must belong to the chosen company; trainer must
  belong to the active account.
- ✅ Storage helper for trainer signatures (`storage/trainers/`) and
  trainee signatures (`storage/trainings/{id}/`)
- ✅ Frontend: `api/trainers.ts`, `api/trainings.ts`;
  `TrainingsListPage` (replaces placeholder), `NewTrainingPage` with
  6-card type picker + company/facility/date/trainer/topics/duration
  form, `TrainingDetailPage` skeleton (shows training header + warning
  card that attendees + PDF land in 4b/4c)
- ✅ Trainers section in Settings — list, add (name + cert), upload
  signature (PNG), archive; surfaces "podpis nahraný / bez podpisu"
  badges so the technician knows who's ready
- ✅ Verified locally: created trainer (`Jan Skolitel`), then a
  `vstupne` training tied to it; list/show return correctly

### 4b — Trainees + on-screen signature capture ✅
- ✅ Backend `TraineeController` — POST `/api/trainings/{id}/trainees`
  (multipart with required PNG signature), DELETE
  `/api/trainings/{id}/trainees/{trainee_id}`, GET
  `/api/trainees/{id}/signature` (auth + tenant-scoped stream).
  `signed_at = NOW()` on insert; rolls back the row if move_uploaded_file
  fails so storage and DB stay in sync.
- ✅ Mutations rejected on finalized training (409).
- ✅ Frontend `<SignaturePad>` — single canvas with PointerEvent
  handlers (mouse + touch via one code path), HiDPI-aware sizing via
  ResizeObserver, quadratic stroke smoothing, eraser button,
  imperative `toBlob()` for the parent form.
- ✅ "Pridať účastníka" expanding form on `TrainingDetailPage` with
  fullname + position + canvas; trainee rows show inline signature
  thumbnails and a delete button (only while draft).

### 4c — Training PDF protocol ✅
- ✅ `NumberAllocator` extended with `'skolenie'` → `SKO` prefix; all
  6 training types share the SKO bucket per spec
- ✅ `PdfRenderer::renderTraining()` + `templates/training.php` — Firol
  red header with the full training-type label, klient/prevádzka/
  školenie sections, témy block, attendees table with embedded PNG
  signature thumbnails, trainer signature in the protocol footer
- ✅ `DocumentController::generateForTraining()` — transactional
  (number allocation, file write, `documents` row with
  `parent_type='training'`, training → finalized); validates date,
  trainer and at least one trainee before doing anything
- ✅ `DocumentController::indexForTraining()` for the list view
- ✅ Verified locally: `SKO-2026-001` issued for a vstupne training
  with one trainee; download stream reused from inspection flow
- ✅ Frontend: red „Generovať PDF protokol" CTA on
  `TrainingDetailPage` with per-precondition hint when disabled
  (chýba dátum / školiteľ / účastník); finalized state locks edits
  and shows the documents list with download links

## Phase 5 — Settings 🔄
- ✅ Inspector profile (signature upload, certification, validity) — Phase 3a-1
- ✅ Trainers section (CRUD + signature) — Phase 4
- ✅ **Phase 5a — Account branding**: logo upload (PNG/JPG, 1 MB),
  theme color picker, invoice company name. Backend `AccountController`
  + `Storage::accountLogo*` helpers; PDFs read `accounts.theme_color`
  and inline `accounts.logo_path` as data URI in the brand-bar.
- ✅ **Phase 5b — Default periodicities per facility per inspection type**:
  derived from history (no separate settings UI). `CompanyController::show`
  computes `last_periodicities[type]` per facility via window function over
  inspections; Step 1 prefills the periodicity dropdown for selectable types
  (RPHP 12/24, požiarna kniha 3/6) when the user hasn't manually picked one.
- ✅ **Phase 5c — Technicians management**: Settings → Technici section
  visible to everyone, mutations gated to the account's main user.
  `TeamController` over `account_users` with main-user/self guards
  (`GET/POST/PATCH/DELETE /api/account/users[/:id]`); invite reuses
  `password_resets` and returns the link in the response so the inviter
  can copy it (real email is Phase 7). Re-attaches a previously
  deactivated user instead of refusing. `meSnapshot` now exposes
  `accounts.main_user_id` so the frontend can hide management UI for
  technicians.

## Phase 6 — Subscription & billing 🔄
- ✅ **Phase 6a — Read-only mode**: dispatcher-level guard in
  `backend/public/index.php` returns 402 for any state-changing request
  (POST/PATCH/DELETE) once `accounts.subscription_end_date < today`.
  Whitelist: `/api/auth/*`, `/api/me/switch-account`, `/api/billing/*`
  (reserved for 6b). Frontend `<SubscriptionBanner>` in `AppShell`
  shows a red bar with the expiry date and a stub „Zaplatiť (čoskoro)"
  CTA — wired in 6b.
- ✅ **Phase 6b — Stripe Customer + subscription**: Customer created on
  register (best-effort — register doesn't fail if Stripe is down),
  Checkout Session in subscription mode with `subscription_data.trial_end`
  honouring our local trial when the user pays early; Customer Portal for
  switching plan / cancelling. Signature-verified webhooks via
  `Webhook::constructEvent` handle `customer.subscription.created/updated/
  deleted` and `invoice.payment_succeeded`. Reads `current_period_end`
  from BOTH subscription root and `items[0]` (Stripe API ≥2025-04
  moved it). Migration 005 adds `stripe_customer_id`,
  `stripe_subscription_id`, `stripe_status`, `billing_period`.
- ✅ **Phase 6c — iDoklad invoice on every Stripe payment**: thin
  `IDokladClient` (OAuth2 client_credentials, in-process token cache,
  envelope-aware response unwrap); `InvoiceIssuer` is invoked from the
  `invoice.payment_succeeded` webhook, idempotent on stripe_invoice_id.
  First payment per account auto-creates an iDoklad Contact from the
  `accounts.invoice_*` fields and persists `idoklad_contact_id`.
  `IDOKLAD_DRAFT_MODE` env (default true) keeps faktúry as Draft until
  production. Frontend shows „História faktúr" in BillingSection.
  Migration 006 adds `accounts.idoklad_contact_id` and the `invoices`
  mirror table.
- ✅ **Phase 6d — Billing screen + invoice details + admin knobs**:
  Settings → „Fakturačné údaje" form persists `accounts.invoice_*` (street,
  PSČ, mesto, krajina, IČO, DIČ, IČ DPH) so the iDoklad Contact created
  on the first paid invoice has real data. Settings → „Systémové
  nastavenia" (admin-only, gated by `ADMIN_EMAIL` env via `Auth\Admin`)
  edits `trial_days`, `price_monthly_eur`, `price_yearly_eur` from the
  `system_settings` table. New `AdminController` + `Auth\Admin` helper +
  `/api/admin/settings` GET/PATCH (whitelisted past the 402 read-only
  gate). `meSnapshot` exposes `isAdmin` so the section is hidden for
  non-admins. Switch-plan / cancel continues to live in Stripe Customer
  Portal — already wired in 6b.

## Phase 7 — Polish & extras 🔄
- ⬜ Empty states, error boundaries, loading skeletons
- ✅ **Email transport (Resend HTTP API)**: `Firol\Mail\Mailer` posiela
  cez Resend `/emails` (cURL, 10 s timeout), bez závislostí v composeri.
  Templates v `src/Mail/Templates/` — shared `Layout::render()` s
  gradient hlavičkou (Firol red → coral) zladený s app dizajnom, plus
  per-flow šablóny: `PasswordResetEmail`, `InviteEmail`,
  `InvoiceFallbackEmail`. Wired do `AuthController::passwordResetRequest`,
  `TeamController::invite` a `InvoiceIssuer` catch-bloku (Stripe účtoval,
  iDoklad zlyhal — používateľ dostane potvrdenie s hosted Stripe URL).
  Bez `RESEND_API_KEY` Mailer loguje subject + plain-text obsah cez
  `error_log()` aby dev flow ostal funkčný a invite/reset linky šli
  vyfishovať z `docker compose logs php`.
- ⬜ PWA / offline mode (optional, won't change data model)
- ⬜ Final design pass

---

## Open questions (must be answered before the relevant phase starts)
- **Company entity:** base doc says only "name, IČO, address, contact".
  Proposed full set (need confirmation):
  street + house number, postal_code, city, country (default Slovakia),
  contact_person fullname, contact_email, contact_phone, notes. Do we also
  store DIČ for the client company (used on PDF header)?
- **Facility entity:** base doc says "name, address, notes, contact person".
  Does facility have its own full address (street/postal/city), or just a
  free-text "address" field that defaults to the company's? And contact
  person — fullname only, or also email/phone?
- **Periodicity defaults:** is the per-facility default for Požiarna kniha
  (3 vs 6 mo) the only configurable default, or are there defaults per
  facility for every inspection type?
- **Inspector activation:** when a technician is invited via email, do they
  set their own password via the invite link, or is one generated?
