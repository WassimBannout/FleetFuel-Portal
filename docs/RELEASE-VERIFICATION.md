# Release verification (M10)

Evidence for the MVP release gate in [the build plan](08-BUILD-PLAN.md): every applicable acceptance case T01–T35 from [the test plan](07-TEST-PLAN.md), the CI-equivalent quality gates, a clean-checkout rehearsal, documentation and contract parity, a dependency check and a review of authorization, money, idempotency, audit and CSV handling. Recorded on 2026-10-01. Hosting, backups and the live deployment are M11; SQL Server (S01) was not attempted.

Everything below was run on MySQL 8.4 in the project's Docker stack (PHP 8.3, Laravel 13, Node 24). "Automated" means a test in `make test`, which CI runs on every push.

## Acceptance cases

| Case | Evidence | Status |
| --- | --- | --- |
| T01 setup, health, repeated setup | CI and the clean-clone rehearsal run `make setup` from a fresh checkout; `HealthCheckTest` (readiness 200/503 without details, liveness without the database); `docker/bin/check-setup-preserves-state.sh` (APP_KEY, credentials and rows survive a second setup) | Automated + rehearsed |
| T02 migrations, constraints, seed | `MigrationsTest` (every migration rolls back and reapplies), `SchemaConstraintsTest`, `ErDiagramTest`, `DemoSeederTest` (exact reconciliation, deterministic, repeatable) | Automated |
| T03 sign-in | `WebLoginTest`: session ID replaced, identical failure for wrong password, unknown email and disabled account, 5-attempt lockout, sign-out, disabled-after-sign-in | Automated |
| T04 tenant isolation | `TenantIsolationTest`, `AuthorizationMatrixTest`, the fleet, delivery, report, transaction and API tests: another company's record is 404 by URL, filters and AJAX never widen a scope, exports are scoped. New in M10: every route except six public ones refuses a guest | Automated |
| T05 role limits | `FuelCardScreensTest` and `CardPatchApiTest` (operators cannot change quotas), `PosIngestionTest` (a payload `station_id` is refused; the station comes from the token), `ProductPriceScreensTest` (managers cannot publish prices) | Automated |
| T06 tokens | `TokenAuthenticationTest`, `AbilitiesAndPoliciesTest`: missing, malformed, unknown, revoked, expired and disabled-account tokens 401; missing ability or wrong role 403 | Automated |
| T07 assignments | `FuelCardScreensTest`, `FleetApiTest`: cross-company vehicle/driver refused, owner immutable, used card's assignment locked | Automated |
| T08 audits | Card, company, station, product, vehicle, driver, price, override, delivery and account audits with before/after fields; the audit screen redacts credential-like fields and masks card numbers (`AuditDisplayTest`, `AuditScreenTest`); failed sign-ins log a masked email. New in M10: failed queries are logged without their bound values | Automated |
| T09 decimals | `FuelAmountsTest`, `PriceResolverTest`: 20 L at 80,000 LBP and 89,500 → 1,600,000.00 LBP / 17.88 USD, half-up edges, overflow and excess scale | Automated |
| T10 price boundary | `PriceResolverTest`, `PosIngestionTest`, `ProductPriceScreensTest`: exact effective instant, missing price 422, later prices never change stored purchases | Automated |
| T11 FX provider | `HttpExchangeRateProviderTest`, `RatesSyncCommandTest` with faked HTTP: success, schema errors, timeouts, 5xx, 429, three attempts at most, deduplicated observations | Automated |
| T12 rate precedence | `PriceResolverTest`, `RatesSyncCommandTest`, `IntegrationStatusScreenTest`: manual override first, expired or missing rate 503, live mode ignores fixtures | Automated |
| T13–T19 POS rules | `PosIngestionTest` (25 cases): snapshots and one counter increment, replays (exact and canonically equivalent), 409 on a changed payload, replay after block/price/quota/age changes, declines without writes, exact limits, malformed input, Beirut months across DST | Automated |
| T20–T22 concurrency | `PosConcurrencyTest`: separate PHP processes, each with its own MySQL connection, released together at the card lock; no double spend, one purchase per identical retry, one winner + 409, quota edits and blocks serialized both ways | Automated |
| T23 rollback, reconciliation | `PosIngestionTest` (a failure after the ledger insert rolls back both writes), `TransactionReadApiTest` (reconciliation reports a tampered counter) | Automated |
| T24 API contract | `OpenApiContractTest` validates `openapi.json` against the official OpenAPI 3.1 schema, compares routes, abilities and roles, and checks real responses, including 429 with `Retry-After`. New in M10: contention 503 `temporarily_unavailable` with `Retry-After: 1` | Automated |
| T25 simulator, Postman | `PosSimulatorTest` runs the standalone simulator over real HTTP (five scenarios, charged once). Postman folders 01–05 ran with Newman in M06–M08 | Automated (simulator); Newman not rerun in M10 |
| T26–T28 deliveries | `DeliveryApiTest`, `DeliveryOrderServiceTest`, `DeliveryScreensTest`, `DeliveryConcurrencyTest` (separate processes: one 200, one 409 `stale_state`) | Automated |
| T29–T33 reports, CSV | `ReportRepositoryTest`, `ReportApiTest`, `ReportScreensTest`: hand-checked totals, ID grouping, quota exceptions, rapid-fill lookback and ties, CSV scope, quoting and formula neutralization, SLA from history | Automated |
| T34 UI | `TransactionScreensTest` (totals equal the CSV, field errors, 401 for signed-out scripts, fixed query counts), JavaScript unit tests for latest-response-wins and error handling; keyboard, phone, offline and expired-session checks in Chrome (M09) | Automated + manual (M09) |
| T35 release build | New `ProductionBootTest`: `php artisan optimize` in production mode, then real HTTP from the cached config, routes, events and views with debug off, compiled assets from the manifest, a real database failure shown as the plain 500 page with the SQL error in the log only. Clean-checkout gates: see the rehearsal below | Automated + rehearsed; the hosted part is M11 |
| T36 SQL Server | Not attempted (optional S01) | Not run |

## Quality gates

| Command | Result |
| --- | --- |
| `make verify` in the development checkout, first run | Stopped at `analyse`: Larastan found 4 type errors in the new test code (a Symfony versus Laravel response type, a route collection iterated without `getRoutes()`, an always-true assertion). Fixed |
| `make verify` in the development checkout, second run | exit 0 in 4 min 05 s:<br>Pint PASS on 317 files;<br>Larastan `[OK] No errors` for the app and for the POS simulator;<br>PHPUnit **590 tests, 5,972 assertions** in 201.78 s (Unit, Feature, Concurrency and Integration suites on MySQL 8.4);<br>`npm test` 14 passed;<br>`npm ci` 0 vulnerabilities;<br>Vite production build OK |
| `make audit` | No advisories (Composer for the app and the simulator, npm) |
| GitHub Actions | Runs the same sequence on every push: `make setup` from a clean checkout, the readiness check, `make verify`, the repeated-setup check, and now `make audit`. The result for the M10 commit is reported after the push; it was not known when this file was written |

## Clean-checkout rehearsal

Run on 2026-10-01 from the M10 commit, cloned from the local repository into a folder under the home directory, next to the running development stack (`fleetfuel`, port 8080). The clone had no `.env`, `vendor`, `node_modules` or build output; every secret was generated by `make setup`.

| Step | Result |
| --- | --- |
| A. `make setup` without its own project name | Refused before building anything: "Compose project "fleetfuel" already belongs to the checkout at /home/wassim/code/FleetFuel-Portal", with the fix (exit 2). The development containers kept running |
| B. `COMPOSE_PROJECT_NAME=fleetfuel-rehearsal APP_PORT=8092 make setup` | exit 0 in 58 s. It ran these steps:<br>a generated `.env` with the project, port and URL;<br>image `fleetfuel-rehearsal-app:dev` and volume `fleetfuel-rehearsal_mysql-data`;<br>locked Composer and npm installs (0 vulnerabilities);<br>migrations and the demo seed;<br>the Vite build and the readiness check |
| `curl http://localhost:8092/health`, and `/login` | `{"status":"ok","checks":{"app":"ok","database":"ok"}}`; 200 |
| `make verify`, with the project read from `.env` | exit 0 in 246 s:<br>Pint 317 files;<br>Larastan `[OK]` twice;<br>**590 tests, 5,987 assertions** (199.07 s);<br>14 JavaScript tests;<br>0 vulnerabilities;<br>build OK.<br>That is 15 more assertions than in the development run, because the five documentation checks also read the three new documents |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS: APP_KEY, credentials and rows kept |
| `make audit` | No advisories |
| `git status` afterwards | No tracked file changed |
| The development stack afterwards | Containers not recreated (up 5 hours, MySQL 3 days), `/health` ok, 36 purchases and 5 users as before |
| Teardown | `docker compose down -v` for `fleetfuel-rehearsal` only, its image removed, the folder deleted; the development volume `fleetfuel_mysql-data` remains |

A first attempt from a folder under `/tmp` failed at the first container with "mounts denied". Docker Desktop for Linux shares only some host folders with its VM (by default the home directory). The project check had already refused the unnamed copy correctly by then. The partial network and the empty volume were removed and the rehearsal was rerun under the home directory. The requirement is now in the README and the troubleshooting table of docs/09.

## Documentation and contract parity

- `OpenApiContractTest` (since M06) keeps `openapi.json` and the API routes, abilities and roles identical, and checks real responses against it.
- New `DocumentationParityTest`: every `make` target, `php artisan` command, `METHOD /path` endpoint, local URL (on port 8080) and relative link in the maintained documents (README, START_HERE, CLAUDE, `docs/*.md` except the dated progress log, and the simulator, Postman and screenshot READMEs) must exist. A probe line with one wrong item of each kind made all five checks fail.
- Found by reading, not by the test: `START_HERE.md` still said the application had not been built. It now states when it was prepared and the current status.
- Found and fixed: the prose contract promised `Retry-After: 1` on a contention 503, but `openapi.json` did not document it. It is now an optional header on that 503 (absent for `rate_unavailable`), the other documented headers are marked required, and the contract checker requires exactly the required ones.

## Review findings and fixes

| Finding | Fix | Regression check |
| --- | --- | --- |
| A failed query's exception message, which Laravel logs, contained its bound values written out (`card_no = FF-TEST-1234`): a database fault could log card numbers, emails or session IDs | The MySQL connection sets Laravel's `mask_bindings_in_exception_messages` | `ErrorResponsesTest` (the logged message keeps `card_no = ?`), `ProductionBootTest` (the logged session query keeps `id = ?`) |
| The demo seed audited its quota cut as `fuel_card.limits_changed`; the screens and the API write `card.limits_changed`. The audit filter listed one action under two names | The seed's limit change goes through `FuelCardService::updateLimitsHistorical()`, so its row is the live one | `LedgerFixtureBuilderTest` compares the seeded row with a live change, and checks the change is judged against its own month |
| A second clone on the same machine would take over the first clone's containers and database volume: `compose.yaml` fixes the project name `fleetfuel` | `COMPOSE_PROJECT_NAME` and `APP_PORT` can be set for the first `make setup` and are written to `.env`; the PHP image is named after the project; `make setup`, `up` and `test` refuse to start another checkout's project | Shown in the rehearsal below |
| Two unused public routes, the signed download and upload routes at `storage/{path}` (Laravel's local-disk serving); the app stores no files | `serve` is off for the local disk | The guest sweep in `TenantIsolationTest` fails if any non-public route appears |
| The 503 `temporarily_unavailable` path (contention outlasting the retries) had never run | — | `PosConcurrencyTest`: the test holds the card lock while all three attempts time out; 503, `Retry-After: 1`, the documented body, nothing recorded, and the same request accepted afterwards |
| T35 had no automated check | — | `ProductionBootTest` |

Reviewed without findings:
- Raw SQL: the report SQL interpolates only identifiers from fixed allowlists; every value is bound.
- Blade: no unescaped output (`{!!`).
- Money: amounts use `brick/math`; `number_format` is used for whole counts and kilometres only.
- Logs: only masked emails, safe codes and request IDs.
- Mass assignment: refused for role and tenant fields (`AuthorizationMatrixTest`).

Deliberate breakages, each run against the tests and then restored with a matching checksum:

| Breakage | Result |
| --- | --- |
| The historical limit change judged against the current month | 1 failed (`LedgerFixtureBuilderTest`) |
| Lock timeouts treated as ordinary errors (no retry, no 503) | the contention test failed |
| Query bindings unmasked | 2 failed (`ErrorResponsesTest`, `ProductionBootTest`) |
| Local-disk serving switched back on | the guest sweep failed |
| `APP_DEBUG=true` in the production boot | `ProductionBootTest` failed |
| A README line with an unknown make target, Artisan command, endpoint, URL and link | all 5 parity checks failed |
| `Retry-After` made required on every POS 503 | the contract test failed for `rate_unavailable` |

## Dependencies

`make audit` (new; also a CI step): `composer audit --locked` for the app and the simulator, and `npm audit`, found no advisories. `composer outdated --direct` lists two non-security patch releases (`laravel/framework` 13.34.0 and `phpunit/phpunit` 12.5.37); the lockfiles are unchanged during release verification.

## Limitations

- The hosted deployment, backups and restore, HTTPS and secure-cookie settings are M11. `ProductionBootTest` covers the application's production boot, not a host.
- SQL Server (S01) was not attempted.
- Postman/Newman was not rerun in M10; the simulator suite covers the same POS scenarios on every run.
- Browser checks (keyboard, phone, offline, expired session) are manual, from M09, in Chrome only. No screen reader, no automated accessibility audit, no other browsers.
- A duplicate-key error message from MySQL quotes the duplicated value. The application's expected unique violations (POS references) are handled without logging, and card numbers and emails are checked before insert, but a race on those could still log the value.
- The optional Mailpit container uses fixed port 8025, so it runs in one checkout at a time.
- Seeded databases created before M10 keep the old `fuel_card.limits_changed` audit row; audit history is not rewritten.
