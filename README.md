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

### Billing (Stripe + iDoklad)

- **New env variable `STRIPE_PRODUCT_EXTRA_TECHNICIAN`** (`backend/.env`, test
  and live): the Stripe product (`prod_…`) every extra-technician item is
  created under. Create it once in the Stripe dashboard (name e.g.
  „POapp — extra technik", no price — the app sends the price). Without it no
  extra technician can be added to a subscription or a Checkout, and
  Admin → „Kontrola fakturácie" reports an error.
- **iDoklad sending is opt-in outside production.** `IDOKLAD_ISSUE_MODE`
  defaults to `send` only with `APP_ENV=production` and to `prepare`
  elsewhere (payload built and stored on the `invoices` row, nothing sent).
  A Stripe test-mode invoice is never sent unless `IDOKLAD_ALLOW_TEST_EVENTS=true`.
  `IDOKLAD_DRAFT_MODE` is no longer read — remove it.
- **Daily billing run** — add this cron line on the server (the deploy
  workflow does not install it):

  ```
  30 5 * * * php /path/to/firol/backend/bin/billing-daily.php >> /path/to/backups/billing-daily.log 2>&1
  ```

  It pushes the wanted extra-technician quantity to Stripe for every live
  subscription (and merges duplicate items), retries failed or stuck iDoklad
  invoices (at most 3 automatic attempts, then an e-mail to `ADMIN_EMAIL`)
  and stamps its run; Admin → „Kontrola fakturácie" warns when it has not run
  for 36 hours. `--dry-run` lists what it would do without changing anything.
- **Before the first deploy of the 17. 11. 2026 billing fixes**, run the
  read-only report `php backend/bin/billing-seat-report.php` against live
  Stripe. It lists accounts that use more technicians than their
  subscription charges for („NOT CHARGED"). From the first webhook, roster
  change or daily run after the deploy these accounts are charged from that
  moment on (prorated); the past stays the owner's decision.
- Test scripts (test mode only, refuse a live key / production):
  `bin/test-billing-invoices.php` (no Stripe clock, no iDoklad) and
  `bin/test-billing-stripe.php` (Stripe test clocks, deleted at the end).

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
