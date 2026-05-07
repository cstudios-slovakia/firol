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

## Phase 2 — Companies & facilities ⬜
The technician's data, no inspections yet.

- ⬜ DB: `companies`, `facilities` (with `account_id`)
- ⬜ CRUD endpoints (search, pagination, soft-delete / archive)
- ⬜ Dashboard / Moje firmy: list, search, status badges, "+ Nová kontrola"
- ⬜ Company detail (info, facilities list, recent inspections placeholder)
- ⬜ Facility detail (info, inspections-history placeholder)
- ⬜ Bottom tab bar: Firmy / Kontroly / Školenia / Nastavenia (mobile)
- ⬜ Mobile + desktop responsive layout

## Phase 3 — Inspection types ⬜
Implement one type fully end-to-end (RPHP), then replicate the pattern.

- ⬜ DB: `inspections`, `inspection_items` (per-type fields in JSON column),
  `documents` (PDF protocols)
- ⬜ Common flow scaffolding: type picker → step 1 → step 2 → step 3
- ⬜ Inspector profile (signature, certification number, validity)
- ⬜ Document numbering per account+type+year (RPHP-2025-001 etc.)
- ⬜ PDF generation pipeline (mPDF) — branded header (logo + theme color),
  data sections, signature stamp, footer
- ⬜ Type 1: **RPHP** (12/24 mo, 4 statuses A/TS/O/V, full PDF)
- ⬜ Type 2: **Hydranty** (12 mo, DN25/DN33/DN52/C52/other, HS/HD/Q values)
- ⬜ Type 3: **Oprava + plnenie + TS RPHP** (60 mo)
- ⬜ Type 4: **Požiarna kniha** (3/6 mo, no Repeat flow)
- ⬜ Type 5/6: **Požiarne uzávery** AK (3 mo) + UD (12 mo)
- ⬜ Type 7: **Núdzové osvetlenie** (12 mo)
- ⬜ Type 8: **TS hadíc** (60 mo)
- ⬜ "Opakovať" flow (clone, jump to step 3, fresh date + number)

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
