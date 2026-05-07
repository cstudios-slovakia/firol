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

### Other types (after RPHP ships)
- ⬜ Type 2: **Hydranty** (12 mo, DN25/DN33/DN52/C52/other, HS/HD/Q values)
- ⬜ Type 3: **Oprava + plnenie + TS RPHP** (60 mo)
- ⬜ Type 4: **Požiarna kniha** (3/6 mo, no Repeat flow)
- ⬜ Type 5/6: **Požiarne uzávery** AK (3 mo) + UD (12 mo)
- ⬜ Type 7: **Núdzové osvetlenie** (12 mo)
- ⬜ Type 8: **TS hadíc** (60 mo)

## Phase 4 — Trainings ⬜
- ⬜ DB: `trainings`, `trainees` (with touchscreen signatures)
- ⬜ Training types enum (6 types)
- ⬜ Trainer entity (name, certification number, signature)
- ⬜ Touchscreen signature capture (canvas → PNG)
- ⬜ Training PDF protocol (with attendees list + signatures)

## Phase 5 — Settings ⬜
- ⬜ Profile, Inspector profile (signature upload / draw, validity dates)
- ⬜ Account / Branding (logo upload, theme color picker)
- ⬜ Technicians management (invite by email, deactivate)
- ⬜ Default periodicities per facility

## Phase 6 — Subscription & billing ⬜
- ⬜ Stripe Customer + subscription on registration (trial period from settings)
- ⬜ Stripe webhooks: `invoice.payment_succeeded`, `invoice.payment_failed`,
  `customer.subscription.deleted` (signature-verified)
- ⬜ iDoklad invoice generation per successful charge
- ⬜ Read-only mode when `subscription_end_date` < now
- ⬜ Billing screen (next charge, history, switch plan, cancel)
- ⬜ System admin settings UI (trial_days, prices)

## Phase 7 — Polish & extras ⬜
- ⬜ Empty states, error boundaries, loading skeletons
- ⬜ Email transport (password reset, invoice fallback)
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
