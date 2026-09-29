# FleetFuel Portal

A Laravel/MySQL portfolio application, in progress, for corporate fuel cards, station POS transactions, diesel deliveries, and USD/LBP reports. All companies, people and prices are fictional.

**Current status: M04 prices and exchange rates done.** The Docker stack runs locally, with the constrained database schema and a deterministic demo seed. Sign-in, roles and company/station isolation work, and API tokens can be issued and revoked. Admins manage companies, stations and products; admins and managers manage vehicles, drivers and fuel cards, with audited quota and block controls. Admins publish LBP prices on an append-only timeline and monitor USD/LBP rates, which a daily `rates:sync` stores with bounded fallback and audited manual overrides. POS ingestion, deliveries and reports come in later milestones. See [docs/PROGRESS.md](docs/PROGRESS.md).

Start with [START_HERE.md](START_HERE.md) to continue the build in Claude Code. It contains the milestone order and resume instructions.

## Local development

You need Docker with Compose v2 and GNU Make. PHP 8.3, Composer, MySQL 8.4 and Node run in containers; nothing else is installed on the host.

```bash
make setup   # first run, and safe to repeat: keeps .env, APP_KEY and database data
```

Then open <http://localhost:8080>. Readiness (app + database) is at `/health`; liveness (app boots) is at `/up`.

| Command | What it does |
| --- | --- |
| `make setup` | Create `.env`/`.env.testing` only if absent (with generated local DB passwords), build the PHP image, install locked dependencies, create keys only if missing, migrate, seed an empty database, build assets, start the stack |
| `make up` / `make down` | Start or stop the stack; `down` keeps the MySQL volume |
| `make test` | PHPUnit against the separate `fleetfuel_test` MySQL database |
| `make lint` / `make analyse` | Pint style check / Larastan (PHPStan level 6) |
| `make build` | `npm ci` and a production Vite build |
| `make verify` | lint, analyse, test and build; stops at the first failure |
| `make logs` / `make shell` | Recent service logs / a shell in the app container |

To run other Artisan commands: `docker compose exec app php artisan <command>`.

## Demo data

`make setup` seeds fictional demo data once, into an empty local database. If any user already exists it skips, so repeated setups never duplicate or overwrite data. All demo accounts share one local password, the `DEMO_PASSWORD` value in `.env` (`grep DEMO_PASSWORD .env`). `make setup` generates it when it creates `.env`, and it is stored hashed. Sign in at <http://localhost:8080/login>: admins and managers land on the dashboard, operators on their station page.

| Role | Email | Scope |
| --- | --- | --- |
| Admin | admin@fleetfuel.test | Distributor |
| Company manager | manager.atlas@fleetfuel.test | Atlas Logistics |
| Company manager | manager.cedar@fleetfuel.test | Cedar Catering |
| Station operator | operator.beirut@fleetfuel.test | Harbor Demo Station |
| Station operator | operator.tripoli@fleetfuel.test | North Demo Station |

Dates are relative to an "as of" clock (default: now). The POS simulator cards `FF-ATLAS-001`, `FF-ATLAS-BLOCKED`, `FF-ATLAS-TINY` and `FF-CEDAR-001` start with no usage. Dashboard history (32 purchases over the current and previous month) lives on the other cards. It includes:

- a tank overfill;
- two fills 20 minutes apart;
- a card whose quota was cut below its usage;
- an expired provider exchange rate;
- an expired admin rate override;
- six deliveries covering every status.

- Seed with a chosen clock (empty database only): `docker compose exec app php artisan demo:seed --as-of=2026-09-28T09:00:00Z`
- Seeding refuses to run outside the `local`/`testing` environment, without `DEMO_MODE=true`, or without a `DEMO_PASSWORD`.
- Starting over **deletes all local data**: `docker compose exec app php artisan migrate:fresh --seed`. It is deliberately not part of any `make` target.

## Fleet screens

Sign in as `admin@fleetfuel.test` or `manager.atlas@fleetfuel.test`; the navigation bar shows what each role may open.

- **Companies, stations, products** (admin): add, edit and deactivate. Managers and operators see active stations and products only.
- **Vehicles and drivers** (admin: every company; manager: own company): add, edit and deactivate. The company is chosen by the server (an admin picks it; a manager cannot), and a vehicle's fuel type is fixed once created.
- **Fuel cards** (Cards → Issue card):
  - An admin first picks the company; its vehicles and drivers are then offered.
  - The card page shows this month's usage and remaining liters/USD, the monthly limits, block/unblock/archive, and (for admins) the audit history.
  - Once a card has been used, its vehicle, driver and product restriction are locked.
  - Lowering a limit below this month's usage asks for confirmation.
- Nothing is deleted: records are deactivated or archived, so the purchase history keeps pointing at them. An inactive company's fleet is read-only, except that cards can still be blocked or archived.

## Prices and exchange rates

- **Prices** (Products → Prices): each product has an append-only timeline in **LBP per liter**. An admin publishes a price that starts now or at a later Beirut time; published prices never change, and a correction is a newer price. Every role can read the timeline. The products list shows the current price and an indicative USD price.
- **Exchange rates** (admin: Exchange rates): the USD/LBP rate in effect, the last sync result, recent observations and a manual override form. An override starts now or later, lasts at most 72 hours, needs a reason, and is audited.
- A purchase at time T uses the latest price that started at or before T, and the latest valid manual override, otherwise the latest valid observation (each valid for 72 hours). With no valid rate, new purchases are refused rather than converted at a made-up rate.
- `EXCHANGE_RATE_MODE` in `.env`:
  - `fixture` (default) uses synthetic, fictional rates and never calls the provider;
  - `live` fetches from [ExchangeRate-API](https://www.exchangerate-api.com)'s open endpoint and ignores fixture rates.
- Sync: the `scheduler` container runs `rates:sync` daily at 01:00 UTC. To run it by hand:

  ```bash
  docker compose exec app php artisan rates:sync           # skips if the provider's next update is not due
  docker compose exec app php artisan rates:sync --force   # fetch anyway
  ```

  In fixture mode it stores one synthetic observation per UTC day. In live mode it stores each provider observation once, retries timeouts and 5xx errors at most three times, and leaves stored rates untouched when the provider fails. Page requests never call the provider.

## Accounts and API tokens

There is no self-registration. An administrator with shell access manages accounts; each change is written to the audit log:

```bash
# Create an account. The password is asked for at a hidden prompt (at least 12 characters).
docker compose exec app php artisan users:create new.manager@fleetfuel.test \
    --name="New Manager" --role=company_manager --company=1
#   --role=admin (no --company/--station), or --role=station_operator --station=<id>

# Disable an account: sign-in refused, every API token revoked, stored sessions ended.
docker compose exec app php artisan users:deactivate new.manager@fleetfuel.test
docker compose exec app php artisan users:activate new.manager@fleetfuel.test
```

API clients (the POS, Postman) use a bearer token, never the browser session:

- Issue: `POST /api/v1/auth/token` with JSON `email`, `password` and `device_name`. The token is shown once, expires after 24 hours and carries only the abilities of the account's role.
- Revoke: `DELETE /api/v1/auth/token` with `Authorization: Bearer <token>`.
- Token requests are limited to 5 per minute per email + IP, and other API calls to 120 per minute per user. Errors use the `{"error": {...}}` envelope described in the [API contract](docs/05-API-CONTRACT.md).

| Document | Purpose |
| --- | --- |
| [Project brief](docs/01-PROJECT-BRIEF.md) | What to build and what to leave out |
| [Architecture](docs/02-ARCHITECTURE.md) | Stack, structure and local setup contract |
| [Data model](docs/03-DATA-MODEL.md) | Tables, relations, precision and indexes |
| [Business rules](docs/04-BUSINESS-RULES.md) | Quotas, concurrency, idempotency, currency and deliveries |
| [API](docs/05-API-CONTRACT.md) | Routes, permissions, payloads and errors |
| [UI](docs/06-UI-SPEC.md) | Screens, interactions and demo data |
| [Tests](docs/07-TEST-PLAN.md) | Acceptance cases and verification strategy |
| [Build plan](docs/08-BUILD-PLAN.md) | Ordered milestones and completion gates |
| [Shipping](docs/09-OPERATIONS-AND-PORTFOLIO.md) | Deployment, demo, README and interview preparation |
| [Progress](docs/PROGRESS.md) | Durable session handoff |
| [Decisions](docs/DECISIONS.md) | Defaults and deliberate deviations from source |
| [Sources](docs/SOURCES.md) | Verified technical references |

The original research is preserved in [docs/reference/original-research.md](docs/reference/original-research.md). Its company research is context, not a confirmed account of any employer's internal systems.

M11 will replace this introduction with an implementation-based README: real setup commands, screenshots, API usage, test evidence and actual deployment information. Keep the link to START_HERE for maintainers.
