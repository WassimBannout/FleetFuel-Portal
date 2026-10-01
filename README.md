# FleetFuel Portal

A Laravel/MySQL portfolio application, in progress, for corporate fuel cards, station POS transactions, diesel deliveries, and USD/LBP reports. All companies, people and prices are fictional.

**Current status: M08 reports and exports done.** The Docker stack runs locally, with the constrained database schema and a deterministic demo seed. Sign-in, roles and company/station isolation work, and API tokens can be issued and revoked. Admins manage companies, stations and products; admins and managers manage vehicles, drivers and fuel cards, with audited quota and block controls. Admins publish LBP prices on an append-only timeline and monitor USD/LBP rates, which a daily `rates:sync` stores with bounded fallback and audited manual overrides. Stations submit fuel purchases through the API, where retries are safe and quotas hold under concurrent use. The API also serves stations, prices, card quota and block changes, vehicles and drivers; its OpenAPI contract is validated against the real responses. A standalone POS simulator and a Postman collection exercise it end to end. Managers request diesel deliveries, and admins schedule, dispatch and deliver them, with an audited status timeline that concurrent clicks cannot corrupt. Reports cover consumption, top stations, quota exceptions, anomalies, a fuel-efficiency estimate and delivery times, with a scoped accounting CSV. The dashboard and UI finish come in M09. See [docs/PROGRESS.md](docs/PROGRESS.md).

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
| `make test` | PHPUnit against the separate `fleetfuel_test` MySQL database, plus the concurrency suite on `fleetfuel_test_concurrency` (overlapping PHP processes) and the simulator suite on `fleetfuel_test_integration` (the app over real HTTP) |
| `make lint` / `make analyse` | Pint style check / Larastan (PHPStan level 6), then PHPStan level 6 for the POS simulator |
| `make build` | `npm ci` and a production Vite build |
| `make verify` | lint, analyse, test and build; stops at the first failure |
| `make simulate` | Run the POS simulator against the running stack (`SCENARIO=all`, `success`, `replay`, `conflict`, `blocked` or `quota`); credentials from `POS_*` environment variables, see [tools/pos-simulator](tools/pos-simulator/README.md) |
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

Dates are relative to an "as of" clock (default: now). The POS simulator cards `FF-ATLAS-001`, `FF-ATLAS-BLOCKED`, `FF-ATLAS-TINY` and `FF-CEDAR-001` start with no usage; `docker compose exec app php artisan demo:simulator-cards` adds a fresh set for another simulator or Postman run without touching other data. Dashboard history (32 purchases over the current and previous month) lives on the other cards. It includes:

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

## POS purchases (API)

A station's point-of-sale sends each fuel purchase with a station operator's token. Get a token first (see "Accounts and API tokens" below), then:

```bash
curl -X POST http://localhost:8080/api/v1/transactions \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"external_ref": "POS-0001", "card_no": "FF-ATLAS-001", "product_code": "DIESEL",
       "liters": "20.00", "transacted_at": "2026-09-29T10:00:00+03:00", "odometer_km": 45000}'
```

- **201**: a new purchase, with a `Location` header. It stores the price, exchange rate, amounts and ownership as they were at `transacted_at`.
- **200** with `Idempotency-Replayed: true`: the same request again (JSON key order, `"20"` vs `"20.00"` and the time zone notation do not matter). Nothing is charged twice.
- **409**: the same `external_ref` from this station with a different purchase.
- **403**: a business decline, such as a blocked card, the wrong fuel, or a quota exceeded (the details say which).
- **422**: an invalid request, or an event time outside the last 72 hours.
- The station always comes from the token; sending `station_id` is refused. Liters are a decimal string, and the time must include its UTC offset.
- A card's quotas cannot be overspent even when several stations submit at the same moment: each purchase locks the card row first.
- `GET /api/v1/transactions` lists purchases within your scope (filters `from`/`to` as Beirut dates, `card`, `station_id`, `product_code`, admin-only `company_id`), with totals for the whole filter.
- `GET /api/v1/cards/{card_no}/balance?month=YYYY-MM` shows usage and what remains under the card's current limits.
- `docker compose exec app php artisan usage:reconcile` compares every monthly counter with the ledger. It changes nothing and exits 1 if anything differs.

## Diesel deliveries

Open **Deliveries** in the navigation bar (admins and managers). Times are Beirut time, and delivered diesel is never charged to a fuel card.

- A manager requests a delivery for their own company: address, governorate, liters and a preferred window. They can cancel it, with a reason, while it is still pending.
- An admin picks the company first when requesting an order. On the order page, admins move it along: **Schedule** (delivery window and truck), **Mark out for delivery**, **Mark delivered** (final), or **Cancel** from any open status (final).
- The order page shows the timeline (who changed what, when) and, for admins, the audit rows.
- The buttons update the page in place. Each one sends the status the page showed; if someone else changed the order in the meantime, the change is refused and the page reloads the order with an explanation. Try it with the same order open in two tabs.
- The API offers the same through `/api/v1/delivery-orders` (below).

## Reports and the accounting CSV

Open **Reports** in the navigation bar (admins and managers). Each report has a date filter in Beirut days (default: this month); admins can also pick one company. Managers always see their own company only.

- **Consumption** by company, vehicle or product, with totals. Every figure is the sum of what each purchase stored; nothing is repriced with today's price or rate.
- **Top stations** by liters, **quota exceptions** this month with the reason, **anomalies** (more than the tank holds, refills within 30 minutes), a **fuel-efficiency estimate**, and **delivery time** by governorate.
- **Download CSV** on the consumption page exports every matching purchase. Its totals match the table.

From the command line, `docker compose exec app php artisan reports:explain` prints the database's query plans for these reports (read-only; see [docs/REPORT-QUERY-PLANS.md](docs/REPORT-QUERY-PLANS.md)).

## Other API endpoints, simulator and Postman

The full contract is [docs/api/openapi.json](docs/api/openapi.json) (OpenAPI 3.1). The tests validate it against the official schema, compare its routes, token abilities and roles with the real routes, and check real responses against it. [docs/05-API-CONTRACT.md](docs/05-API-CONTRACT.md) explains the rules in prose.

| Endpoint | Who | What |
| --- | --- | --- |
| `GET /api/v1/stations` | every role | Active stations, `governorate` filter, paginated |
| `GET /api/v1/products/prices?at=` | every role | Each active product's LBP price per liter, an indicative USD price and the rate's source, at an instant within the last 366 days (default now; write `+03:00` as `%2B03:00` in a URL) |
| `PATCH /api/v1/cards/{id}` | admin, own company's manager | Any of `monthly_limit_l`, `monthly_limit_usd` (decimal strings, `null` = unlimited) and `status` (`active`/`blocked`), applied together and audited |
| `GET`, `POST /api/v1/vehicles` and `/api/v1/drivers` | admin, own company's manager | Tenant-scoped lists; creation in your own company (admins must send `company_id`) |
| `GET`, `POST /api/v1/delivery-orders`, `GET /api/v1/delivery-orders/{id}` | admin, own company's manager | Orders with their history; a new order starts `pending` |
| `GET /api/v1/reports/consumption?group_by=` | admin, own company's manager | Liters and stored LBP/USD per `company` (default), `vehicle` or `product`, for `from`/`to` (Beirut dates, default this month) |
| `GET /api/v1/exports/transactions.csv` | admin, own company's manager | The accounting CSV: every purchase matching the transaction filters, streamed, formula-safe |
| `PATCH /api/v1/delivery-orders/{id}/status` | admin; own company's manager for cancelling a pending order | `expected_status` and `status`, plus the window and `assigned_truck` to schedule or a `reason` to cancel. 409 `stale_state` if the order changed meanwhile, 409 `invalid_transition` for a skipped or final step |

Status codes follow one pattern: 401 no or bad token, 403 wrong role, missing token ability or a business decline, 404 not found or another company's record, 409 a conflict with the current state, 422 invalid input, 429 too many requests (with `Retry-After`), 503 no valid exchange rate.

**POS simulator.** `tools/pos-simulator` is a separate PHP program that plays a station terminal over HTTP only. It checks each answer and that the card was charged exactly once, or not at all. With the stack running:

```bash
export POS_EMAIL=operator.beirut@fleetfuel.test
export POS_PASSWORD="$(sed -n 's/^DEMO_PASSWORD=//p' .env)"
make simulate        # success, replay, conflict, blocked and quota; exit code 0 when all pass
```

Each successful run uses 20.00 L of the card's monthly quota. For another clean run, `demo:simulator-cards` adds fresh cards and prints the variables to set. See [tools/pos-simulator/README.md](tools/pos-simulator/README.md).

**Postman.** Import `postman/FleetFuel.postman_collection.json` and `postman/local.postman_environment.json`, set the demo password in your own copy of the environment, and run folders 01, 02 and 05 (POS and manager access). Folder 03 (the delivery lifecycle) and folder 04 (reports and CSV) each run on their own and never touch a card. [postman/README.md](postman/README.md) also shows the same run with Newman from the command line.

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
