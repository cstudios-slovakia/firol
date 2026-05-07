# Firol — project context (CLAUDE.md)

SaaS web app for fire protection inspection technicians. Mobile-first UI,
inspection output is signed and numbered PDF protocols.

## Tech stack
- **Backend**: plain PHP 8.5 (no framework), PSR-4 autoloading via Composer, namespace `Firol\`.
- **Database**: MariaDB.
- **Frontend**: React 19 + Vite + TypeScript + Tailwind CSS v4.
- **Styling helper**: `cn()` combining `clsx` and `tailwind-merge`.
- **Icons**: Lucide React.
- **Hosting**: Websupport (shared, PHP 8.5 supported).

## Repo
- GitHub: `git@github.com:cstudios-slovakia/firol.git`
- Monorepo: `frontend/` + `backend/` + `docker/` + `docs/`.

## Local development
1. `docker compose up -d` — starts PHP-FPM 8.5, nginx (port 8080), MariaDB (port 3306).
2. `cd frontend && npm install && npm run dev` — Vite dev server on 5173 with proxy `/api → :8080`.
3. `cd backend && composer install` — PHP dependencies.

## Deploy
GitHub Actions (`.github/workflows/deploy.yml`) on push to `main`:
build frontend → composer install → SCP only artifacts to Websupport.

## Design philosophy (from .claude/ui_design_and_implementation.md)

### Aesthetics
- Curated HSL palettes, no generic colors.
- Modern fonts: Inter / Roboto / Outfit.
- `rounded-2xl` / `rounded-3xl`, subtle shadows, gradients.
- Micro-animations: `transition-all duration-300`, hover/active states.
- Glassmorphism (subtle backdrop blur, transparent background) where it fits.

### Stack
- Tailwind v4 as primary utility framework.
- `cn()` helper for conditional classes (clsx + tailwind-merge).
- Vanilla CSS only for resets and complex custom animations.

### Layout
- Consistent design system (spacing, colors, typography tokens).
- Fully responsive, optimized for mobile and desktop (mockups in `docs/handoff/` are 360px mobile).
- Component architecture — modular, reusable.

### SEO
- Semantic HTML (`<header>`, `<main>`, `<section>`, `<h1>`).
- Descriptive titles and meta tags.
- Unique IDs for critical interactive elements.

## Modules (from specifications in docs/handoff/Specifikacie/)
1. **RPHP** — fire extinguisher inspection (12 / 24 months)
2. **Hydranty** — fire hydrant inspection (12 months)
3. **Oprava / plnenie / TS HP** — fire extinguisher servicing
4. **Požiarna kniha** — periodic records (3 / 6 months)
5. **Požiarne uzávery** — operational readiness (3 months) + maintenance (12 months)
6. **Núdzové osvetlenia** — emergency lighting
7. **Školenia** — fire safety trainings

Common flow: select company/facility → enter data item by item
→ summary with editable date → generate PDF. "Repeat" function for existing inspections.

## Principles
- Multi-tenancy: every record has `account_id` and all queries filter by the active account of the logged-in user. Address **from the start**, not as a retrofit.
- No credentials in repo — `.env` (local) + GitHub Secrets (CI).
- Inspection dates are always entered manually, NEVER today's date automatically.
- Slovak for UI strings and domain terms.
- All documentation and code comments in English.

## Source of truth — NO HALLUCINATION
- App functionality, fields, screens, copy, intervals, PDF layouts and any
  domain decision come **only** from `docs/Firol base document-*.md` and
  `docs/handoff/`. Nothing else is authoritative.
- If a piece of context is missing or ambiguous, **stop and ask**. Do not
  invent fields, flows, validations, statuses, screens or features. Do not
  add nice-to-haves that the spec doesn't mention.
- React/PHP implementation choices (folder structure, libraries, patterns)
  are at Claude's discretion — but the *what* (features, data model, UX)
  is fixed by the spec.
- Mockups in `docs/handoff/` are visual inspiration only. Don't copy them
  1:1 — produce a fresh, modern, clean design that mirrors the structure
  and information hierarchy.

## Roadmap
Active development plan and phase tracking lives in
`docs/development-roadmap.md`. Update it when a phase changes status,
when scope is clarified by the user, or when a new phase is added.
