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
- **VAT** (owner's decision of 18. 11. 2026): list prices are net, the
  customer pays the price plus VAT. `VAT_RATE_PERCENT` (default 23) is the one
  rate setting (screens, e-mails, the iDoklad payload, the Stripe check — the
  admin form only shows it). `STRIPE_TAX_RATE_ID` (test and live) is a manual
  Stripe tax rate (Products → Tax rates: „DPH", that percentage, **exclusive**,
  SK): Checkout sets it as the subscription's default tax rate, and the
  reconcile (webhooks, daily run, behind `BILLING_RECONCILE`) puts it on every
  live subscription that lacks it. Stripe then adds VAT to every line —
  plan, extra technicians, prorations of additions and removals, after
  coupons — rounded per line. Without it Checkout is refused (503) and
  „Kontrola fakturácie" shows an error; a live subscription without it, a
  rate that is archived, inclusive or of another percentage are errors too.
  Foreign customers / reverse charge are not handled: everyone pays Slovak VAT.
- **iDoklad sending is opt-in.** `IDOKLAD_ISSUE_MODE` defaults to `send` only
  with `APP_ENV=production` and to `prepare` elsewhere (payload built and
  stored on the `invoices` row, nothing sent). Production sets
  `IDOKLAD_ISSUE_MODE=send` explicitly. A live Stripe key does not switch
  sending on by itself; a live key without `send`, a live key with `APP_ENV`
  other than `production`, a live payment whose invoice was only prepared or
  skipped (no iDoklad credentials) and every paid invoice held for review are
  errors in „Kontrola fakturácie", named by account and amount, and
  `ADMIN_EMAIL` gets one e-mail per row and state. A Stripe test-mode invoice
  is never sent unless `IDOKLAD_ALLOW_TEST_EVENTS=true` (and never e-mailed
  to the customer); in production a test row is a warning, not an error.
- **The iDoklad invoice**: one item per (netted) Stripe line with its net unit
  price, `PriceType` 1 (without VAT) and `VatRateType` 1 (Basic — the
  standard rate; 2 would be the zero rate). A technician removed mid-period is
  a negative item on the same invoice; a part paid from the customer's Stripe
  credit balance is one negative item „Kredit z predchádzajúceho obdobia"; an
  invoice where nothing was paid (a credit larger than the invoice, or one
  covered by the credit) gets no document (`no_document`, a visible state).
  Before every POST the app asks iDoklad to recount the document
  (`IssuedInvoices/Recount`) and holds it (`vat_mismatch`, an alert) when the
  total with VAT is not Stripe's amount paid.
- **After issuing** the app marks the invoice paid in iDoklad
  (`PUT IssuedDocumentPayments/FullyPay/{id}?dateOfPayment=` — Stripe's
  payment date) and asks iDoklad to e-mail it to the customer
  (`POST Mails/IssuedInvoice/Send`, `SendToPartner`, PDF, Slovak) — to the
  e-mail on the iDoklad contact, which the app sets to the main user's
  e-mail. Each step has its own state on the row; a failure never touches the
  issued invoice; the daily run retries up to 3 times, then alerts; an
  uncertain send (no answer after the request left) waits for a person;
  Admin → faktúry účtu → „Poslať znova". **Turn off Stripe's own customer
  e-mails for successful payments** (Settings → Customer emails) so the
  customer gets one e-mail per payment — iDoklad's invoice.
- **Failed renewal**: when a renewal payment fails, the main user gets one
  e-mail per failed invoice and every member a banner; the account stays
  fully usable for `PAYMENT_GRACE_DAYS` (default 14) from the failed renewal,
  then it is read-only (the server answers writes with 402) until it is paid.
  A payment restores full access at once (webhook or daily run). **Stripe's
  retry schedule and final action are dashboard settings** (Settings →
  Billing → Subscriptions and emails → Manage failed payments) and are not
  changed by the app: keep the retries (Smart Retries or a schedule of up to
  3 weeks — they may run longer than the 14 days; a retry that is paid
  restores access), and set the final action to „cancel the subscription"
  or „mark as unpaid" — both keep the account read-only until a new
  checkout / payment.
- **`BILLING_RECONCILE`** accepts only `on` and `off` (unset = on). `off`
  stops every push of seat quantities (and of the VAT tax rate) to Stripe:
  webhooks still record the subscription state, the daily run only reports,
  and „Ďalšia fakturácia" shows what Stripe really charges. Any other value
  counts as off and is an error in „Kontrola fakturácie"; so is the switch
  left off for more than 3 days. Used for the first deploy (below); unset (or
  `on`) afterwards.
- **Daily billing run** `backend/bin/billing-daily.php` — the deploy workflow
  installs the cron line (05:30, log `billing-daily.log` next to the backup
  logs; `php` there must be the PHP 8.5 CLI). It e-mails every error of
  „Kontrola fakturácie" once per check, re-runs Stripe webhook events that
  failed (an event Stripe no longer has, or one failing for 30 days, is given
  up and listed — a gone `invoice.*` event is an error until an admin marks it
  „Vyriešené"), reads each customer's POapp subscriptions from Stripe — also
  for accounts recorded as cancelled (a paid subscription takes over an unpaid
  recorded one, a live one takes over one that is over; a subscription of
  another product on the same customer is never taken over; a customer with
  more than one live POapp subscription is alerted and listed — the app never
  cancels or changes one), writes status and period end (capped by the grace
  period of a failed renewal), pushes the wanted extra-technician quantity and
  the VAT tax rate (and merges duplicate items), stores each account's result
  (an account left for a person or failing is alerted once and listed),
  rebuilds invoice rows of the old code from Stripe and holds them for a
  person, issues rows held as credits under the old rule, alerts every row that
  needs a person once per state, issues live payments left `prepared` /
  `skipped` once that is possible, retries failed or stuck iDoklad invoices and
  the follow-up steps (at most 3 automatic attempts, then an e-mail to
  `ADMIN_EMAIL`), sends the failed-payment e-mails that did not go out, and
  stamps a full run; „Kontrola fakturácie" reports it when it has not run for
  36 hours (an error with a live key), and the webhook e-mails it once. With
  a live Stripe key and `APP_ENV` other than `production` (and no
  `--allow-live`) it refuses what writes to Stripe — event re-runs and seat
  pushes (seats are reported) — and its invoice step only prepares and
  alerts; the refusal is an error in „Kontrola fakturácie" and one e-mail. A
  run with `--dry-run`, `--only`, `--account` or `--event` does not stamp the
  last run. `--dry-run` lists what it would do without changing anything.
- **Stripe webhook** (Developers → Webhooks, endpoint
  `https://app.poapp.sk/api/billing/webhook`, secret in
  `STRIPE_WEBHOOK_SECRET`) must send: `checkout.session.completed`,
  `customer.subscription.created`, `customer.subscription.updated`,
  `customer.subscription.deleted`, `customer.subscription.paused`,
  `customer.subscription.resumed`, `invoice.paid` (or
  `invoice.payment_succeeded`; both are fine), `invoice.finalized` (the PDF
  link) and `invoice.payment_failed` (the failed-renewal notice and grace
  period).
- **`ADMIN_EMAIL`** receives the billing alerts — and every address in it is
  also a platform admin of the app (it signs in with full admin rights).
  Use addresses of people who should have that.
- Test scripts (test mode only — both refuse a key that is not `sk_test_`
  and `APP_ENV=production`; iDoklad is replaced by a recorder loaded before
  the autoloader, also in the child processes they start, and no test process
  can reach SMTP — mail is captured to a file):
  `bin/test-billing-invoices.php` (no Stripe clock) and
  `bin/test-billing-stripe.php` (Stripe test clocks, deleted at the end).

#### Production deploy checklist — billing (17.–19. 11. 2026)

Do these in this order. Nothing here is automatic.

1. **Before the deploy, set in the production `backend/.env`:**
   - `STRIPE_PRODUCT_EXTRA_TECHNICIAN=prod_…` — the live product, created in
     the live Stripe dashboard (not the plan's product).
   - `STRIPE_TAX_RATE_ID=txr_…` — a live tax rate „DPH" 23 %, exclusive, SK
     (Products → Tax rates), and `VAT_RATE_PERCENT=23`.
   - `IDOKLAD_ISSUE_MODE=send` — explicitly.
   - `APP_ENV=production` — exactly this word (with a live key the daily run
     otherwise refuses to write to Stripe and only prepares invoices, and
     „Kontrola fakturácie" shows an error).
   - `ADMIN_EMAIL=…` — at least one address; billing alerts go there (and the
     address is a platform admin, see above).
   - `BILLING_RECONCILE=off` — so the first webhook or daily run after the
     deploy does not start charging (or put the tax rate on subscriptions)
     before the report was read.

   In the live Stripe dashboard: the webhook sends the events listed above;
   Stripe's customer e-mails for successful payments are off; the failed-
   payment retries and final action are as described above. On the server:
   `php -v` (the cron's `php`) is 8.5.
2. **Remove** `IDOKLAD_DRAFT_MODE` from the production `.env`.
3. **Deploy** (push to `main`). The workflow runs migrations `079`, `082`
   (079 again, for databases that ran an earlier 079) and `083` (all can be
   re-run if they stop half way) and installs the daily cron line (the
   crontab is read once and left alone when it cannot be read).
4. **Before the first nightly run (05:30), count the old invoice rows:**
   `php backend/bin/billing-daily.php --dry-run --only=invoices`. It lists
   rows of the old code without a Stripe snapshot (the first run rebuilds
   them from Stripe and holds them — check iDoklad for each before
   „Vystaviť znova"), rows held as credits under the old rule (the first run
   issues them with a negative line), live payments left prepared or skipped,
   and held rows that will be e-mailed. It changes nothing and does not stamp
   the run.
5. **Run the seat report** on the server:
   `php backend/bin/billing-seat-report.php`. It lists accounts that use more
   technicians than they pay for („NOT CHARGED"), overcharged ones, duplicate
   and legacy items, UNVERIFIED and UNRECOGNISED items (left for a person —
   the app never touches them) and customers with more than one live
   subscription, and says for each account which subscription the daily run
   will charge.
6. **The owner decides** what to do about each flagged account, before the
   switch goes on:
   - **More than one live subscription:** resolve it in Stripe (cancel or
     refund the one that should not bill; move an extra item if needed).
     The app never cancels or changes a subscription by itself. After the
     switch the daily run charges the extra technicians on **the paid
     (active / trialing) POapp subscription, the newest one first**: it moves
     an account from an unpaid recorded subscription to a paid one, and from
     a cancelled one to one that is live; the old one keeps billing until it
     is resolved in Stripe. Cancelling the old one in Stripe is therefore
     safe: the account stays on (or moves to) the live one — a subscription
     of another product on the same customer is never used.
   - **NOT CHARGED:** the past is not charged automatically; from the moment
     the switch goes on, the missing technicians are charged prorated on the
     next renewal.
   - **OVERCHARGED:** when the switch goes on, the Stripe quantity is lowered
     to the active technicians. Stripe writes that as a proration credit,
     which the next renewal's tax document carries as a negative line (or,
     when the credit is larger than the renewal, no document — the rest stays
     on the customer's Stripe balance and is used by the next invoice).
   - **The per-account lever:** Admin → account → „Technici zahrnutí v
     predplatnom" changes how many technicians the plan includes for that
     account — the wanted extra count follows it (raise it to leave a NOT
     CHARGED account as it is; set it so the wanted count equals Stripe's to
     leave an OVERCHARGED account unchanged).
   - **UNVERIFIED / UNRECOGNISED items:** fix the subscription in Stripe; the
     account stays „na ručné riešenie" (listed in „Kontrola fakturácie")
     until the daily run can read it.
7. **Switch on:** remove `BILLING_RECONCILE=off` (or set it to `on`). The next
   roster change, webhook or daily run pushes the wanted quantities and the
   VAT tax rate.
8. **Check Admin → „Kontrola fakturácie".** It must show no error: Stripe in
   live mode with `APP_ENV=production`, the extra-technician product found by
   name and not the plan's product, the tax rate found (exclusive, 23 %), no
   live subscription without it, iDoklad „faktúry sa vystavujú", no live
   payment without an invoice, no paid invoice held without a decision, no
   failed follow-up, no account left for a person, no customer with two live
   subscriptions, no refused daily run, and after the first night the daily
   run with a fresh timestamp. Rows that nobody can clear stay errors until a
   person acts: a held row (`needs_review` — fix it in iDoklad by hand; a
   0 € row of the old code too), an invoice stopped after 3 attempts, a gone
   `invoice.*` event (mark it „Vyriešené" after checking). Test-mode rows,
   failed or given-up non-invoice Stripe events and rows without a document
   are warnings or plain states, not errors.
9. **Watch the first real invoice.** On the first live payment after the
   deploy the owner checks in iDoklad: the invoice's base, VAT and total equal
   what Stripe charged (the Recount check held it otherwise), it is marked
   paid with Stripe's payment date, and the customer received iDoklad's
   e-mail with the PDF (and no second e-mail from Stripe). Until that one
   has been checked, look at every invoice of the first days in iDoklad.

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
