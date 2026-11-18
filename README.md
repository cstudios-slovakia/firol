# Firol

SaaS for fire protection inspection technicians. Mobile-first web app,
inspection output is signed PDF protocols.

Current status: **environment scaffold**. Application not yet written.

## Tech stack

- **Backend:** plain PHP 8.5 (PSR-4 autoloading via Composer, namespace `Firol\`)
- **DB:** MariaDB 11
- **Frontend:** React 19 + Vite + TypeScript + Tailwind CSS v4 + Lucide React
- **Hosting:** production server hosting `app.poapp.sk`

## Repo structure

```
firol/
├── frontend/             React + Vite + TS + Tailwind
│   ├── src/
│   ├── package.json
│   └── vite.config.ts
├── backend/              Plain PHP 8.5
│   ├── public/           webroot (index.php, .htaccess)
│   ├── src/              PHP classes (Firol\…)
│   ├── composer.json
│   └── .env.example
├── docker/               Local dev environment
│   ├── php/Dockerfile    PHP 8.5-fpm
│   └── nginx/default.conf
├── docs/                 Module specifications, handoff materials
├── docker-compose.yml
└── connection.example.md SSH/DB config template (sanitized)
```

## Local development

Prerequisites: Docker Desktop, Node 20 (`nvm use`), Git.

```bash
# 1) Backend (PHP + DB) via Docker
cp backend/.env.example backend/.env
docker compose up -d

# 2) Backend dependencies
docker compose exec php composer install

# 3) Frontend
cd frontend
cp .env.example .env.local
npm install
npm run dev
```

After startup:
- Frontend (Vite) → http://localhost:5173
- Backend (PHP via nginx) → http://localhost:8080
- Health check → http://localhost:5173/api/health (via Vite proxy) or
  http://localhost:8080/api/health (direct)

## Deploy

GitHub Actions (`.github/workflows/deploy.yml`) on push to `main` SSHs into
the production server (`app.poapp.sk`), runs `git pull`, rebuilds the
frontend (`npm ci && npm run build`), runs `composer install --no-dev`,
applies any pending DB migrations and ensures the `backend/storage/`
tree exists.

### Frontend build on a small server

- **Source maps are off by default.** Writing them is what made the build
  run out of memory on the staging host (without them it fits in ~400 MB).
  Set `VITE_BUILD_SOURCEMAP=1` for a build that should emit them (as
  `hidden` maps — no `//# sourceMappingURL` pointer in the public bundle),
  e.g. `VITE_BUILD_SOURCEMAP=1 npm run build`.
- **`npm run build:safe`** builds into `frontend/dist-next/` and swaps it in
  as `frontend/dist/` only after Vite finished successfully
  (`frontend/scripts/swap-dist.mjs`). A build the host kills half-way then
  leaves the live `dist/` untouched instead of empty. The previous build is
  kept in `dist-prev/` until the next swap (rollback: rename it back). The
  deploy workflow still runs the plain `npm run build`; switch it to
  `build:safe` on a server where the build may be killed.
- **Vite reads `vite.config.ts` only.** `tsc -b` used to emit
  `frontend/vite.config.js` and `frontend/vite.config.d.ts` next to the source,
  and Vite prefers a `.js` config — so a stale file won over every later edit.
  `tsconfig.node.json` now sends that output to `node_modules/.tmp/`, and the
  `dev`, `build`, `build:safe` and `preview` scripts pass
  `--config vite.config.ts`. **Once per server**, delete the old files:
  `rm -f frontend/vite.config.js frontend/vite.config.d.ts`.

### Billing (Stripe + iDoklad)

- **`STRIPE_PRODUCT_EXTRA_TECHNICIAN`** (`backend/.env`, test and live): the
  Stripe product (`prod_…`) every extra-technician item is created under.
  Create it once in the Stripe dashboard (name e.g. „POapp — extra technik",
  no price — the app sends the price). It must be its own product, never the
  product of the plan's price. Without it no extra technician can be added to
  an existing subscription (Checkout falls back to an inline product), and
  Admin → „Kontrola fakturácie" reports an error.
- **iDoklad sending is opt-in.** `IDOKLAD_ISSUE_MODE` defaults to `send` only
  with `APP_ENV=production` and to `prepare` elsewhere (payload built and
  stored on the `invoices` row, nothing sent). Production sets
  `IDOKLAD_ISSUE_MODE=send` explicitly. A live Stripe key does not switch
  sending on by itself; a live key without `send`, and a live payment whose
  invoice was only prepared, are errors in „Kontrola fakturácie" and the daily
  run e-mails `ADMIN_EMAIL`. A Stripe test-mode invoice is never sent unless
  `IDOKLAD_ALLOW_TEST_EVENTS=true`. `IDOKLAD_DRAFT_MODE` is no longer read.
- **`BILLING_RECONCILE=off`** stops every push of seat quantities to Stripe:
  webhooks still record the subscription state, the daily run only reports.
  Used for the first deploy (below); unset (or `on`) afterwards.
- **Daily billing run** `backend/bin/billing-daily.php` — the deploy workflow
  installs the cron line (05:30, log `billing-daily.log` next to the backup
  logs). It re-runs Stripe webhook events that failed, writes each
  subscription's status and period end from Stripe, pushes the wanted
  extra-technician quantity (and merges duplicate items), rebuilds invoice
  rows of the old code from Stripe and holds them for a person, issues live
  payments left `prepared` once sending is allowed, retries failed or stuck
  iDoklad invoices (at most 3 automatic attempts, then an e-mail to
  `ADMIN_EMAIL`) and stamps its run; „Kontrola fakturácie" warns when it has
  not run for 36 hours. With a live Stripe key it refuses to run unless
  `APP_ENV=production` or `--allow-live` is given. `--dry-run` lists what it
  would do without changing anything.
- Test scripts (test mode only, refuse a live key / production; iDoklad is
  replaced by a recorder loaded before the autoloader):
  `bin/test-billing-invoices.php` (no Stripe clock) and
  `bin/test-billing-stripe.php` (Stripe test clocks, deleted at the end).

#### Production deploy checklist — billing fixes of 17.–18. 11. 2026

Do these in this order. Nothing here is automatic.

1. **Before the deploy, set in the production `backend/.env`:**
   - `STRIPE_PRODUCT_EXTRA_TECHNICIAN=prod_…` — the live product, created in
     the live Stripe dashboard (not the plan's product).
   - `IDOKLAD_ISSUE_MODE=send` — explicitly.
   - `APP_ENV=production` — exactly this word (the daily run refuses a live key
     otherwise).
   - `ADMIN_EMAIL=…` — at least one address; billing alerts go there.
   - `BILLING_RECONCILE=off` — so the first webhook or daily run after the
     deploy does not start charging before the report was read.
2. **Remove** `IDOKLAD_DRAFT_MODE` from the production `.env`.
3. **Deploy** (push to `main`). The workflow runs migration `079` (it can be
   re-run if it stops half way) and installs the daily cron line.
4. **Run the seat report** on the server:
   `php backend/bin/billing-seat-report.php`. It lists accounts that use more
   technicians than they pay for („NOT CHARGED"), overcharged ones, duplicate
   and legacy items, UNVERIFIED items (left for a person — the app never
   touches them) and customers with more than one live subscription.
5. **The owner decides** what to do about each flagged account (the past is
   not charged automatically; from the moment the switch goes on, missing
   technicians are charged prorated on the next renewal).
6. **Switch on:** remove `BILLING_RECONCILE=off` (or set it to `on`). The next
   roster change, webhook or daily run pushes the wanted quantities.
7. **Check Admin → „Kontrola fakturácie".** It must show no error: Stripe in
   live mode, the extra-technician product found by name and not the plan's
   product, iDoklad „faktúry sa vystavujú", no live payment without an
   invoice, no failed Stripe events, and after the first night the daily run
   with a fresh timestamp. Invoices „Na kontrolu" (credits, rows of the old
   code) are listed per account and need a person.

### GitHub Secrets required for deploy workflow
- `PROD_SSH_HOST`
- `PROD_SSH_PORT`
- `PROD_SSH_USER`
- `PROD_SSH_PASSWORD` (or migrate to `PROD_SSH_PRIVATE_KEY`)

## Scripts

Frontend (`cd frontend`):
- `npm run dev` — Vite dev server
- `npm run build` — production build to `dist/`
- `npm run typecheck` — TS type check

Backend (`cd backend`):
- `composer install` — dependencies
- `composer stan` — static analysis (PHPStan level 6)
- `composer fix` — PHP CS Fixer

## Principles

- No credentials in repo. Everything via `.env` (local) or GitHub Secrets (CI).
- Slovak for UI strings and domain terms.
- Mobile-first design (HTML mockups in `docs/handoff/` are 360px mobile).
- Multi-tenant from day one (each technician sees only their own companies).
- All documentation and code comments in English.
