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
  sending on by itself; a live key without `send`, a live key with `APP_ENV`
  other than `production`, a live payment whose invoice was only prepared or
  skipped (no iDoklad credentials) and every paid invoice held for review are
  errors in „Kontrola fakturácie", named by account and amount, and
  `ADMIN_EMAIL` gets one e-mail per row. A Stripe test-mode invoice is never
  sent unless `IDOKLAD_ALLOW_TEST_EVENTS=true`. `IDOKLAD_DRAFT_MODE` is no
  longer read.
- **`BILLING_RECONCILE`** accepts only `on` and `off` (unset = on). `off`
  stops every push of seat quantities to Stripe: webhooks still record the
  subscription state, the daily run only reports, and „Ďalšia fakturácia"
  shows what Stripe really charges. Any other value counts as off and is an
  error in „Kontrola fakturácie"; so is the switch left off for more than 3
  days. Used for the first deploy (below); unset (or `on`) afterwards.
- **Daily billing run** `backend/bin/billing-daily.php` — the deploy workflow
  installs the cron line (05:30, log `billing-daily.log` next to the backup
  logs; `php` there must be the PHP 8.5 CLI). It re-runs Stripe webhook
  events that failed (an event Stripe no longer has, or one failing for 30
  days, is given up and listed), reads each customer's subscriptions from
  Stripe (a paid subscription takes over an unpaid recorded one; a customer
  with more than one live subscription is alerted and listed — the app never
  cancels or changes one), writes status and period end, pushes the wanted
  extra-technician quantity (and merges duplicate items), stores each
  account's result (an account left for a person or failing is alerted once
  and listed), rebuilds invoice rows of the old code from Stripe and holds
  them for a person, alerts every held invoice whose e-mail did not go out,
  issues live payments left `prepared` / `skipped` once that is possible,
  retries failed or stuck iDoklad invoices (at most 3 automatic attempts,
  then an e-mail to `ADMIN_EMAIL`) and stamps a full run; „Kontrola
  fakturácie" reports it when it has not run for 36 hours (an error with a
  live key). With a live Stripe key and `APP_ENV` other than `production`
  (and no `--allow-live`) it refuses only what writes to Stripe — event
  re-runs and seat pushes (seats are reported) — and runs the checks and the
  invoice step; the refusal is an error in „Kontrola fakturácie" and one
  e-mail. A run with `--dry-run`, `--only` or `--account` does not stamp the
  last run. `--dry-run` lists what it would do without changing anything.
- **Stripe webhook** (Developers → Webhooks, endpoint
  `https://app.poapp.sk/api/billing/webhook`, secret in
  `STRIPE_WEBHOOK_SECRET`) must send: `checkout.session.completed`,
  `customer.subscription.created`, `customer.subscription.updated`,
  `customer.subscription.deleted`, `customer.subscription.paused`,
  `customer.subscription.resumed`, `invoice.paid` (or
  `invoice.payment_succeeded`; both are fine), `invoice.finalized` (the PDF
  link) and `invoice.payment_failed` (logged).
- **`ADMIN_EMAIL`** receives the billing alerts — and every address in it is
  also a platform admin of the app (it signs in with full admin rights).
  Use addresses of people who should have that.
- Test scripts (test mode only — both refuse a key that is not `sk_test_`
  and `APP_ENV=production`; iDoklad is replaced by a recorder loaded before
  the autoloader, also in the child processes they start):
  `bin/test-billing-invoices.php` (no Stripe clock) and
  `bin/test-billing-stripe.php` (Stripe test clocks, deleted at the end).

#### Production deploy checklist — billing fixes of 17.–18. 11. 2026

Do these in this order. Nothing here is automatic.

1. **Before the deploy, set in the production `backend/.env`:**
   - `STRIPE_PRODUCT_EXTRA_TECHNICIAN=prod_…` — the live product, created in
     the live Stripe dashboard (not the plan's product).
   - `IDOKLAD_ISSUE_MODE=send` — explicitly.
   - `APP_ENV=production` — exactly this word (with a live key the daily run
     otherwise refuses to write to Stripe, and „Kontrola fakturácie" shows an
     error).
   - `ADMIN_EMAIL=…` — at least one address; billing alerts go there (and the
     address is a platform admin, see above).
   - `BILLING_RECONCILE=off` — so the first webhook or daily run after the
     deploy does not start charging before the report was read.

   Check in the live Stripe dashboard that the webhook sends the events
   listed above, and on the server that `php -v` (the cron's `php`) is 8.5.
2. **Remove** `IDOKLAD_DRAFT_MODE` from the production `.env`.
3. **Deploy** (push to `main`). The workflow runs migration `079` (it can be
   re-run if it stops half way) and installs the daily cron line (the
   crontab is read once and left alone when it cannot be read).
4. **Before the first nightly run (05:30), count the old invoice rows:**
   `php backend/bin/billing-daily.php --dry-run --only=invoices`. It lists
   rows of the old code without a Stripe snapshot (the first run rebuilds
   them from Stripe and holds them — check iDoklad for each before
   „Vystaviť znova"), live payments left prepared or skipped, and held rows
   that will be e-mailed. It changes nothing and does not stamp the run.
5. **Run the seat report** on the server:
   `php backend/bin/billing-seat-report.php`. It lists accounts that use more
   technicians than they pay for („NOT CHARGED"), overcharged ones, duplicate
   and legacy items, UNVERIFIED and UNRECOGNISED items (left for a person —
   the app never touches them) and customers with more than one live
   subscription.
6. **The owner decides** what to do about each flagged account, before the
   switch goes on:
   - **More than one live subscription:** resolve it in Stripe (cancel or
     refund the one that should not bill; move an extra item if needed).
     The app never cancels or changes a subscription by itself; after the
     switch the daily run moves an account from an unpaid recorded
     subscription to a paid one and puts the extra technicians there, while
     the old one keeps billing until it is resolved in Stripe.
   - **NOT CHARGED:** the past is not charged automatically; from the moment
     the switch goes on, the missing technicians are charged prorated on the
     next renewal.
   - **OVERCHARGED:** when the switch goes on, the Stripe quantity is lowered
     to the active technicians. Stripe writes that as a proration credit, so
     the account's next renewal nets to a credit and is **held without a tax
     document** („Na kontrolu", an e-mail per invoice) until the owner
     decides how credits are invoiced (open decision).
   - **The per-account lever:** Admin → account → „Technici zahrnutí v
     predplatnom" changes how many technicians the plan includes for that
     account — the wanted extra count follows it (raise it to leave a NOT
     CHARGED account as it is; set it so the wanted count equals Stripe's to
     leave an OVERCHARGED account unchanged).
   - **UNVERIFIED / UNRECOGNISED items:** fix the subscription in Stripe; the
     account stays „na ručné riešenie" (listed in „Kontrola fakturácie")
     until the daily run can read it.
7. **Switch on:** remove `BILLING_RECONCILE=off` (or set it to `on`). The next
   roster change, webhook or daily run pushes the wanted quantities.
8. **Check Admin → „Kontrola fakturácie".** It must show no error: Stripe in
   live mode with `APP_ENV=production`, the extra-technician product found by
   name and not the plan's product, iDoklad „faktúry sa vystavujú", no live
   payment without an invoice, no paid invoice held without a decision, no
   account left for a person, no customer with two live subscriptions, no
   refused daily run, and after the first night the daily run with a fresh
   timestamp. Failed or given-up Stripe events are listed as warnings.

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
