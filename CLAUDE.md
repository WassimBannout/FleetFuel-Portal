# FleetFuel Portal — persistent project instructions

## Purpose and current state

Build a junior-developer portfolio project: a Laravel/MySQL corporate fleet-fuel and diesel-delivery portal with a simulated station POS and USD/LBP reporting. Use fictional data and no employer branding or claims of affiliation.

This repository initially contains a handoff kit, not an implemented app. Read `docs/PROGRESS.md` at the start of every session. Inspect files and Git state; never assume a recorded check passed without evidence.

## Read in this order

1. `docs/PROGRESS.md` — current milestone and exact next action.
2. `docs/01-PROJECT-BRIEF.md` and `docs/02-ARCHITECTURE.md` — scope and structure.
3. The relevant milestone in `docs/08-BUILD-PLAN.md` and its file in `prompts/`.
4. Only the detailed specs needed for that milestone: data `03`, rules `04`, API `05`, UI `06`, testing `07`, shipping `09`.

Use `docs/DECISIONS.md` for deliberate changes. The refined specs and API contract override `docs/reference/original-research.md`. User instructions override project defaults. Surface a material contradiction and resolve it explicitly; do not silently change behavior.

## Work one milestone at a time

- Implement the requested milestone, including its meaningful tests, and fix failures before advancing.
- Keep changes small and explain the main decisions in beginner-friendly terms.
- At the end, update `docs/PROGRESS.md`: state, files, exact commands/results, blockers, next action, and suggested commit message. Then stop for the user's next milestone request.
- Do not claim code, tests, deployments, CI runs, PR reviews, or SQL Server support that do not exist.
- Preserve existing work and this handoff. Bootstrap Laravel in a temporary directory and merge carefully into this nonempty root; never delete the kit to make `create-project` work.
- Routine local implementation and verification are part of the requested milestone. Ask only for a material product decision, credentials, destructive action, or an actual environment permission requirement. Deployment preparation can proceed without account details; document blocked live steps honestly.
- Do not publish, spend money, send messages, or push to a remote until the user requests that external action. Suggest local commits; commit when requested. Never invent historical commits or reviews.

## Stack

- PHP 8.3, Laravel 13, MySQL 8.4; Docker Compose is the canonical environment.
- Blade, Bootstrap 5, jQuery, Vite; no SPA, Tailwind conversion, microservices, or AI features.
- Fortify for session authentication with custom Bootstrap views; Sanctum for API tokens.
- PHPUnit, Laravel Pint, Larastan; Composer/npm lockfiles committed when created.
- `brick/math` decimal arithmetic with explicit rounding; no float money calculations.
- SQL Server 2022 is optional S01. Portable migrations are a design aim, not proof of compatibility.

## Structure and conventions

- Laravel lives at repository root; `app/Services`, `app/Policies`, `app/Http/Requests`, `app/Http/Resources`, `app/Repositories/ReportRepository.php`, `app/Contracts`, `app/Enums`.
- Keep controllers thin. Put write business rules in services used by both web and API.
- Use PHP backed enums plus portable string database columns. Form Requests validate every write; Policies authorize it.
- Scope tenant queries explicitly before lookup, including reports, exports, counts, dropdowns and AJAX. Never trust client-supplied company/station ownership.
- Eager-load list relationships; bind raw SQL parameters; allowlist sortable/grouping identifiers.
- Use escaped Blade output and CSRF on session mutations. Rate-limit login and API. Redact credentials and card identifiers from logs.
- Use database constraints as well as validation. Archive business records with history rather than deleting the ledger.

## Critical invariants

- USD/LBP and liters enter/leave JSON as decimal strings. Store immutable price, rate, company/vehicle/driver and tank-capacity snapshots on accepted transactions.
- Store UTC timestamps; determine quota months in `Asia/Beirut`. Offset-bearing timestamps are required.
- Lock the fuel-card row, then the monthly usage row. Validate quota and insert transaction/update usage in one database transaction. Quota changes use the same card lock.
- Idempotency is unique `(station_id, external_ref)`: identical canonical payload replays with 200, changed payload conflicts with 409, first acceptance returns 201. Check replay before mutable card/price/quota rules, after authentication and structural validation.
- Derive station from the token's authenticated user; reject a payload `station_id`.
- Never reprice historical transactions. Never treat a failed FX fetch as a zero/1:1 rate.
- Accepted transactions are immutable. No refunds, backfill imports, billing or credit-limit enforcement in MVP.
- MySQL integration/concurrency tests are required; SQLite alone cannot verify these guarantees.

## Planned command interface

These commands become real in M00. Before that, do not claim they run:

```bash
make setup                 # non-destructive local setup; preserves existing .env and APP_KEY
make up
make test                  # isolated MySQL test database; never development/production
make lint                  # Pint --test
make analyse               # PHPStan/Larastan
make build                 # npm ci and production assets
make verify                # the preceding quality gates
make down                  # preserve database volumes
```

Add POS simulation in M06 and document its command. Do not put `migrate:fresh`, volume deletion, or demo reset in normal startup.

## End-of-milestone response

State what now works, how it was verified, any remaining limitation, one important concept to learn, and the next milestone. Use real test results rather than a target test count.
