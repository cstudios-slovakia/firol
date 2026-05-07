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

## Phase 1 — Database, auth, multi-tenancy ⬜
The whole app depends on this. Everything has `account_id`.

- ⬜ Migration system (lightweight: `backend/migrations/*.sql` + a runner script)
- ⬜ DB schema:
  - `users` (id, fullname, email, phone, password_hash, created_at)
  - `accounts` (id, invoice_*, logo_path, theme_color, subscription_end_date,
    main_user_id, created_at)
  - `account_users` (account_id, user_id, role) — pivot, many-to-many
  - `password_resets` (token, user_id, expires_at)
  - `system_settings` (key/value: trial_days, monthly_price, yearly_price)
- ⬜ Backend routing layer (replace the trivial if-chain in `index.php`)
- ⬜ Auth endpoints: `/api/auth/register`, `/login`, `/logout`,
  `/password-reset/{request,confirm}`, `/api/me`, `/api/me/switch-account`
- ⬜ Session token (likely cookie-based — same-origin, HttpOnly, SameSite=Lax)
  + CSRF token for state-changing requests
- ⬜ Multi-tenancy guard at the data layer (every query reads
  `current_account_id()` from request context; no controller can bypass)
- ⬜ Frontend: login, registration (without Stripe yet — trial only),
  password reset, account switcher in header
- ⬜ Design system tokens (colors, spacing, typography, components: Button,
  Input, Card, Badge) — first pass during this phase

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
