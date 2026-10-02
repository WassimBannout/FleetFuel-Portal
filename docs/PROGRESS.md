# Progress and session handoff

Updated: 2026-10-02

## Current state

- **All MVP milestones M00–M11 are DONE locally.** M11 shipping preparation added:
  - **A production image** (`docker/production/Dockerfile`, `make prod-image`):
    - nginx and PHP-FPM in one container, as a non-root user;
    - locked production dependencies and compiled assets;
    - configuration from the environment, cached on start; no migration on start.
  - **A reference stack** (`compose.production.yaml`) and the env template `.env.production.example`.
  - **A full local deployment rehearsal** (`make rehearse`, also a CI step). It covers the release steps, sign-in, the Postman POS, delivery, report and CSV scenarios, the scheduler and logs, a backup restored and compared table by table, and a restart.
  - **[docs/RUNBOOK.md](RUNBOOK.md):** deployment, releases, rollback, scheduler, backups, restore, failure handling, the demo policy, GitHub settings.
  - **An implementation-based README,** [docs/DEMO-SCRIPT.md](DEMO-SCRIPT.md) and [docs/PORTFOLIO.md](PORTFOLIO.md).
  - **`TRUSTED_PROXIES`,** secure cookies in the production template, and a `mysql` default connection.
  - **`demo:seed --force`** for a dedicated production demo deployment.
- **Live deployment: pending.** No host has been chosen, and nothing was deployed or published. The deployment record in the runbook is empty.
- **Dev database:** M11 changed no data in it (36 purchases, 5 users). The running containers already see the changed config files, so no restart is needed.
- **Local URLs:** <http://localhost:8080> (sign-in `/login`, dashboard `/dashboard`, transactions `/transactions`, audit `/audit`, reports `/reports`, readiness `/health`, liveness `/up`, API base `/api/v1`).
- **Git:** branch `main` tracks `origin/main` (github.com/WassimBannout/FleetFuel-Portal).
  - M10 is `fd739b2`. GitHub Actions run 36914194485 passed on it (5 min 27 s):
    - Pint PASS on 317 files and Larastan OK twice;
    - 590 tests / 5987 assertions (131.86 s) and 14 JavaScript tests;
    - npm 0 vulnerabilities and a Vite build OK;
    - the repeated-setup step and `make audit` passed.
  - M11 is committed and pushed in the commit that contains this file. Its CI result, including the first CI run of `make rehearse`, had not been observed when this file was written.

## Next action

All MVP milestones are complete locally. **The remaining release tasks need the owner's decisions**; none should start without them:

1. **Hosting.** Choose a provider and an account that can run a container from this image, with:
   - a durable MySQL 8.4 database;
   - HTTPS termination;
   - a second process or cron for the scheduler.

   Then follow the runbook's "First deployment", run its smoke test and fill in its deployment record. Check the provider's current official limits and costs first; the original research's free-tier claims are not guarantees.
2. **The public demo policy.** The runbook recommends publishing only the manager accounts and deactivating the admin and operators on the demo database.
3. **A license**, the owner's choice; the README says none has been chosen.
4. **Branch protection on `main`**, requiring "Setup and quality gates" (runbook, "GitHub settings").
5. **A demo recording**, following docs/DEMO-SCRIPT.md.

Optional, only on request:
- **S01 SQL Server** (`prompts/12-sql-server-stretch.md`).
- Later improvements from the limitations:
  - a Content-Security-Policy;
  - browser end-to-end tests (for example, for the phone-overflow regression);
  - an automated accessibility audit.

## Milestone ledger

| Milestone | State | Evidence / remaining work |
| --- | --- | --- |
| M00 Foundation | DONE | Local gates pass. Two items open after M00 are now verified (log filter, browser render). A setup bug was found and fixed in M01 (nginx 502 after `app` recreation). GitHub CI still not run (no remote) |
| M01 Data model | DONE | T02 on MySQL: migrations roll back and reapply; FK/unique/CHECK constraints enforced; seed reconciliation exact; seeding deterministic and repeatable; ER diagram test matches MySQL foreign keys. `make verify`: 100 tests, 870 assertions |
| M02 Auth/security | DONE | T03, and T04–T06 for the implemented surfaces, on MySQL: two-company and two-station tests on real routes, manager A denied B's data (404), wrong role denied (403), revoked/expired/disabled tokens rejected (401), CSRF tested with the real middleware. `make verify`: 194 tests, 1933 assertions. T05 write routes (quotas, prices) are policy-level until M03/M04 wire them to screens |
| M03 Fleet CRUD | DONE | T04/T05/T07/T08 on real routes (MySQL): cross-company vehicle/driver IDs refused, card company immutable, used-card assignment locked, quota/block/deactivation audits with before/after values, operators 403, managers 404 on other companies' records, no delete routes (405), input escaped. Company → vehicle/driver → card and a blocked card walked through live. `make verify`: 254 tests, 2375 assertions |
| M04 Pricing/FX | DONE | T09–T12 (ledger-immutability part of T10/T12 completes in M05) on MySQL with faked HTTP: exact 20 L sample (1600000.00 LBP / 17.88 USD), half-up edges, overflow/excess-scale inputs, exact price boundary, missing price 422, manual > provider/fixture precedence, expired/missing rate 503, live ignores fixtures, success/schema/timeout/5xx/429 with bounded attempts, deduplicated observations. One live sync run, labeled. `make verify`: 349 tests, 2800 assertions |
| M05 POS ingestion | DONE | On MySQL through the real HTTP stack: T13–T19, T23 and the ledger parts of T10/T12. T20–T22 use genuinely overlapping PHP processes, each with its own connection, on a dedicated database: no double spend at the quota edge (80 + 15 + 15 of 100 L; 6 × 20 L of 100 L), one purchase per identical concurrent retry, one winner + 409 for a conflicting or cross-card shared reference (settled by the unique index), and card edits serialized with ingestion in both orders. Live walkthrough through nginx. `make verify`: 441 tests, 3355 assertions |
| M06 API/tooling | DONE | T24 on MySQL: `openapi.json` valid against the official OpenAPI 3.1 schema (a broken copy fails with exactly the injected errors); routes, abilities and roles match it and planned operations are unrouted; every response in the API tests is validated against the documented status, headers and schema, including 400/401/403/404/409/422/429 (with `Retry-After`)/503. T25: the simulator's five scenarios over real HTTP on fresh cards (Integration suite, and live through nginx: ledger +1); Postman folders 01/02/05 with Newman 6: 26 requests, 51 assertions, 0 failures (ledger +1). `make verify`: 481 tests, 4242 assertions |
| M07 Deliveries | DONE | T26–T28 on MySQL. Creation writes the initial history. Each accepted change writes exactly one history row and one audit row, and both roll back with the order when either write is forced to fail. Skipped, repeated, stale and final-status changes and manager escalation are refused with nothing written. Token abilities are checked per role; another company's order is 404. T28: separate PHP processes through the real HTTP kernel; two admins, or an admin and a manager, from the same expected status give one 200 and one 409 `stale_state`; the commit and rollback orderings are covered too. Live: Postman folder 03 with Newman (19 requests, 38 assertions, 0 failures) and the real jQuery module in jsdom through nginx (12/12). `make verify`: 508 tests, 4937 assertions |
| M08 Reporting | DONE | T29–T33 on MySQL against the demo seed. Consumption totals per company ID worked out by hand (515.00 L / 41,675,000.00 LBP and 325.00 L / 26,775,000.00 LBP; USD equal to the stored per-purchase sum). Equal names never merge; vehicle grouping follows the purchase snapshot; new prices and rates change nothing. Quota exceptions: a reduced limit, a limit reached exactly, blocked and archived cards; a declined POS purchase adds nothing. Rapid fills: exactly 30 min not flagged, 29:59 flagged, same-second fills by ID, the predecessor read from before the range start, card-only purchases never flagged. Efficiency from recorded readings only. SLA from the history rows. Top stations ties by ID. CSV: tenant and date scope, every row across chunks, the same totals as the ledger list, RFC 4180 quoting and formula neutralization, identical to the browser download. Cross-tenant checks on every report and the export. 11 deliberate breakages all caught. Query plans on 64,032 purchases led to one new index. `make verify`: 544 tests, 5312 assertions |
| M09 UI polish | DONE | T34 on MySQL and in Chrome. Latest response wins: unit tests with controlled promises, and in the real page with a delayed first answer (a broken build shows the stale result, the real one does not). Totals agree with the CSV for the whole filter, across pages and roles. Loading, empty, error, offline, expired-session and validation states. Keyboard sign-in, skip link, menu and paging. No horizontal page overflow at 390 px on 32 pages. The five-minute walkthrough for all three roles, with the POS simulator. Fixed query counts for the list, dashboard and audit log. 13 deliberate breakages all caught. `make verify`: 580 tests, 5716 assertions (plus 14 JavaScript tests) |
| M10 Release quality | DONE | T01–T35 mapped to tests (docs/RELEASE-VERIFICATION.md); T35 locally by `ProductionBootTest` (production caches, debug off, real HTTP, a real database failure shown plainly and logged without values), the hosted part in M11. Clean clone in its own Compose project, port and volume next to the running stack: setup, `make verify` (590 tests / 5987 assertions + 14 JS), repeated setup and `make audit` all passed; an unnamed second clone is refused. Five review findings fixed with regression tests; the contention 503 tested; documentation parity tested; 7 deliberate breakages caught. `make verify`: 590 tests, 5972 assertions (plus 14 JavaScript tests) |
| M11 Shipping/portfolio | DONE (local); live deployment PENDING | A production image (non-root nginx + PHP-FPM, locked `--no-dev` dependencies, compiled assets, no `.env` inside) and a runbook. `make rehearse` passed locally in 108 s: release steps, the `demo:seed --force` guard, healthy app with one scheduler, HTTP checks, form sign-in scoped by tenant, Newman 55 requests / 113 assertions with 0 failures (POS, manager scope, deliveries, reports and CSV, revocation), `rates:sync`, no secrets in the logs, a backup restored into a throwaway server with identical rows and checksums in 24 tables, restart persistence. It found and fixed Debian nginx dropping the port from URLs. `TrustedProxiesTest`, secure cookies and an HTTPS proxy in `ProductionBootTest`; 3 deliberate breakages caught. The live URL, the host smoke test and branch protection are pending the owner. `make verify`: 593 tests, 6027 assertions (plus 14 JavaScript tests) |
| S01 SQL Server | OPTIONAL | Begin only after user requests the stretch |

Use TODO / IN PROGRESS / DONE / BLOCKED. A milestone is DONE only when its checks pass. If an external prerequisite blocks one part, record exactly which part and finish independent local work.

## Most recent session: M11

**Date / milestone:** 2026-10-02, M11 shipping preparation.

**Goal and actual state:**
- Goal (prompts/11-shipping-portfolio.md): prepare and verify locally:
  - a production image;
  - deploy, rollback, scheduler, and backup-and-restore runbooks;
  - the actual README and screenshots;
  - a demo script and truthful résumé bullets.

  Finish the concrete release candidate before asking for hosting, account or publishing decisions, and publish only when asked.
- **Result:** done locally.
  - The production image, the runbook, backup and restore, and a full deployment rehearsal are built, and verified with disposable data.
  - The README, the demo script and the portfolio notes are written from measured results.
- **The live deployment is pending.** No host has been chosen; that is the owner's decision. Nothing was deployed or published.
- M10 had nothing outstanding: the tree was clean, `main` matched `origin/main` at `fd739b2`, and GitHub CI run 36914194485 had passed on it (observed at the end of M10).

### What was built

- **Production image:**
  - `docker/production/Dockerfile` (stages: PHP base, `vendor`, `assets`, `app`);
  - `docker/production/{entrypoint.sh, nginx.conf, php.ini, php-fpm.conf}`;
  - `.dockerignore`.
- **Stack and settings:**
  - `compose.production.yaml`;
  - `.env.production.example`, whitelisted in `.gitignore`;
  - `config/fleetfuel.php` gained `trusted_proxies`;
  - `AppServiceProvider::configureTrustedProxies()`;
  - `config/database.php`: the default connection is `mysql`.
- **Demo seeding:** `demo:seed --force`, through `SeedDemoData` and `DemoSeeder::run($asOf, $hostedDemo)`.
- **Operations:**
  - `docker/production/backup.sh`, `restore-check.sh` and `rehearse.sh`;
  - Makefile targets `prod-image` and `rehearse` (and `make help` now lists hyphenated targets);
  - CI step "Production deployment rehearsal".
- **Tests:**
  - new: `tests/Feature/TrustedProxiesTest.php` (2);
  - `DemoSeederTest`: one new test, one extended;
  - `ProductionBootTest`: HTTPS through a trusted proxy, and a `Secure` + `HttpOnly` session cookie;
  - `DocumentationParityTest`: a URL's trailing sentence punctuation is ignored.
- **Docs:**
  - new `docs/RUNBOOK.md`, `docs/DEMO-SCRIPT.md` and `docs/PORTFOLIO.md`;
  - the README rewritten from the implementation;
  - START_HERE status;
  - `docs/02-ARCHITECTURE.md` (commands), `docs/09-OPERATIONS-AND-PORTFOLIO.md` (an outdated opening paragraph), RELEASE-VERIFICATION (a pointer to M11);
  - DECISIONS (the M11 record), CHANGELOG, this file.

### Checks: exact command and actual outcome

PHP commands ran as `docker compose run --rm app …` against the isolated MySQL test databases, unless stated otherwise.

| Command | Outcome |
| --- | --- |
| `git status`, `git log` at the start | Clean tree; `main` at `fd739b2` = `origin/main` |
| `docker build -f docker/production/Dockerfile -t fleetfuel-portal:local .`, first build | exit 0. 846 MB unpacked (`docker images`), 201 MB compressed (`docker image inspect`, containerd store) |
| Inspecting the image (`docker run --entrypoint bash …`) | Inside:<br>user 10001 `app`;<br>no dev packages, `.env`, tests, docs, tools, `node_modules` or `.git`;<br>no Composer or git;<br>code not writable, `storage` and `bootstrap/cache` writable;<br>`nginx -t` and `php-fpm -t` OK;<br>all extensions loaded; `opcache.validate_timestamps=0` |
| The image started with environment variables only and no database | `/up` 200; `/health` 503 `{"status":"unavailable",…}`; security headers; assets cached for a year; `docker stop` in 1.2 s.<br>**Found:** with `DB_CONNECTION` unset, the skeleton's default fell back to SQLite. The default is now `mysql` |
| `DemoSeederTest` after the `--force` change | **1 failed:** my test expected a failed exit code from `db:seed`, but the seeder's refusal is an exception. It now asserts the exception and its guidance. Then **17 passed / 653 assertions** |
| `TrustedProxiesTest`, first run | **1 failed:** an untrusted address appeared to be believed. Reproduced outside PHPUnit, where it behaves correctly. The cause was the test client: it builds the next request's absolute URL with `url()`, which still held the previous request's forwarded https host. The test now requests `http://localhost/login` explicitly. Then **2 passed / 8 assertions** |
| `ProductionBootTest` with the proxy and cookie checks | Passed. Changing `TRUSTED_PROXIES` to `10.9.9.9` once made it fail at the https assertion, so the check runs |
| `make rehearse` #1 | Every step passed up to web sign-in, which **failed**: it redirected to `http://127.0.0.1/dashboard`, without the port. Debian's nginx 1.26 `fastcgi_params` sets `HTTP_HOST $host` as a security workaround. The production config now lists the standard parameters, so the client's Host header passes unchanged. A standalone probe then showed `http://127.0.0.1:8095/login` |
| `make rehearse` #2 | Sign-in passed. **Failed** at Newman: `--no-color` is not a Newman 6 option. Changed to `--color off` |
| `make rehearse` #3 | **Passed in 98 s** |
| Larastan | 1 error: a repeated `User::query()->count()` was treated as always true. That check now uses `assertDatabaseCount` |
| `DocumentationParityTest` over the new docs | **1 failed:** `http://localhost:8080/login.` with the sentence's full stop. The test now ignores trailing punctuation. Then **5 passed / 127 assertions** |
| `make verify` | exit 0 in 3 min 53 s:<br>Pint PASS on 318 files;<br>Larastan `[OK] No errors` and simulator PHPStan `[OK] No errors`;<br>**593 tests / 6027 assertions** (191.07 s);<br>`npm test` 14 passed;<br>npm 0 vulnerabilities;<br>Vite build OK |
| `make rehearse` #4, on the same tree | **Passed in 108 s.** Image built in 11 s from cache (201 MB compressed) and inspected.<br>Release steps: `migrate --force`; `demo:seed` without `--force` refused, with `--force` seeded.<br>App healthy with one scheduler.<br>HTTP checks; form sign-in scoped to Atlas.<br>Newman: **26 requests / 51 assertions** (folders 01, 02, 05) and **29 / 62** (03, 04), 0 failures; `usage:reconcile` clean.<br>`rates:sync` scheduled and run; no secrets in the logs.<br>A 12 KB backup restored into a throwaway server: identical rows and checksums in **24 tables**.<br>Restart: 33 purchases before and after.<br>Everything removed |
| Deliberate breakages N1–N3 (file changed in place, tests run, file restored and checksum-verified) | **N1**, the trusted-proxy list never applied: `TrustedProxiesTest` and `ProductionBootTest` failed.<br>**N2**, demo seeding in production without `--force`: `DemoSeederTest` failed.<br>**N3**, `SESSION_SECURE_COOKIE=false` in the production boot: `ProductionBootTest` failed |
| Leftovers after the rehearsals | No rehearsal or restore-check container, volume or network. Images: `fleetfuel-portal:local` and `fleetfuel-app:dev` |
| The development stack (read-only) | Containers not recreated (up 18 hours, MySQL 3 days); `/health` ok; 36 purchases, 5 users. The running `app` sees the new config files |
| After the final `make verify` and rehearsal, two kinds of change | Docs only, plus the `demo:seed` description string ("…empty database (local, or a production demo deployment with --force)"). `DemoSeederTest` + `DocumentationParityTest`: **22 passed / 780 assertions**; Pint PASS. GitHub CI runs every gate and `make rehearse` on the pushed commit |

### Not run or not verified

- **No live deployment:**
  - no host, URL, HTTPS termination, or `TRUSTED_PROXIES` behind a real proxy;
  - no smoke test on a host; the deployment record in the runbook is empty.
- **GitHub branch protection** was not set: that is the owner's action (instructions in the runbook).
- **Backups:** the `gpg` encryption step and a backup schedule were not run (no host). A rollback between two real releases was not rehearsed; only one release exists.
- **GitHub CI for the M11 commit,** including the first CI run of `make rehearse` (reported in the session reply after the push).
- **No demo recording** was made, and no application email was written or sent (by design).
- **Carried over:**
  - screen readers and browsers other than Chrome;
  - the manual phone-overflow guard;
  - MySQL duplicate-key messages quote the duplicated value;
  - the simulator with a host PHP outside Docker;
  - SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-10-02, M11):
- the single-container image and its entrypoint;
- nginx passing the client's Host header;
- `TRUSTED_PROXIES` and secure cookies;
- the `mysql` default connection;
- `demo:seed --force` for a production demo deployment;
- the reference compose file;
- backup and restore-check;
- the rehearsal;
- the README and the license left unchosen.

No product rule and no API changed.

### Remaining work and blockers

None for the local M11 release candidate. The remaining release tasks need the owner:
1. A hosting decision (a provider and account that can run a container from this image, a durable MySQL 8.4, HTTPS and a scheduler), then the deployment and its smoke test, recorded in the runbook.
2. The public demo policy: the runbook recommends manager accounts only.
3. A license.
4. Branch protection on `main`.
5. A demo recording.

**Suggested commit message:** `build: add production image, deployment rehearsal and release runbook`. The build plan suggests `docs: prepare release runbook and portfolio walkthrough`; this milestone also changes the image, CI, configuration and tests, so `build:` describes it better.

**One concept to explain:** build once, configure at deploy time, and trust only what you have restored.
- **One immutable image** holds the code and its locked dependencies, and nothing secret. The same image runs the web server, the scheduler and the migrations; only the environment differs.
- **Releases are explicit steps.** Migrations and seeding never happen on start, so restarting a container can never change the schema or the data. Rolling back means starting the previous image.
- **A backup is only as good as its restore.** The rehearsal restores every backup into a throwaway server, checks it with the application itself, and compares every table's row count and checksum with the source.

## Earlier session: M10

**Date / milestone:** 2026-10-01, M10 release verification.

**Goal and actual state:**
- Goal (prompts/10-release-quality.md):
  - audit every implemented acceptance case;
  - run complete CI-equivalent checks and fix actual findings with regression tests;
  - rehearse a clean checkout with isolated Compose volumes and ports;
  - check route-contract and documentation-command parity;
  - record a real troubleshooting example.
- Result: done. All local gates pass on MySQL 8.4, both in the development checkout and in a fresh clone set up in its own Compose project next to it. The evidence is in [docs/RELEASE-VERIFICATION.md](RELEASE-VERIFICATION.md).
- M09 had nothing outstanding: the tree was clean, `main` matched `origin/main` at `a4c1ea0`, and GitHub CI run 36874363104 had passed on it (observed at the end of the M09 session).

### What was built and fixed

- **Fix: failed queries logged their bound values.** `config/database.php` sets `mask_bindings_in_exception_messages` on the MySQL connection (the story is in [docs/DEBUGGING-STORY.md](DEBUGGING-STORY.md)).
  - Regression tests: `ErrorResponsesTest::test_a_failed_query_is_logged_without_its_bound_values`, plus an assertion in `ProductionBootTest`.
- **Fix: one audit action under two names.** `FuelCardService::updateLimitsHistorical()` exists, and `LedgerFixtureBuilder::changeMonthlyLimits()` uses it, so the seed's quota cut is the live `card.limits_changed` row.
  - `LedgerFixtureBuilderTest`: the seeded row equals a live change, and a past change is judged against its own month.
  - `DemoSeederTest`, `FuelCardScreensTest` and `CardPatchApiTest` updated: the last two look up their card's own row, because the seed now writes the same action.
- **Fix: a second clone took over the first one's containers and volume.**
  - `docker/bin/prepare-env.sh` accepts `COMPOSE_PROJECT_NAME` and `APP_PORT` for a new `.env`.
  - `compose.yaml` names the image after the project; `.env.example` documents the name (commented out).
  - New `docker/bin/check-compose-project.sh`, run by `make setup`, `make up` and `make test`.
  - README section "A second copy on one machine".
- **Fix: the contract.** `openapi.json` 0.10.0 documents the optional `Retry-After` on the POS 503 and marks the always-sent headers required. `ChecksOpenApiContract` requires exactly the required headers.
- **Fix: unused public routes.** `config/filesystems.php` turns `serve` off. `TenantIsolationTest::test_every_route_except_the_public_ones_requires_sign_in` requests every route as a guest.
- **New tests:**
  - `PosConcurrencyTest::test_contention_that_outlasts_the_retries_is_a_503_and_records_nothing`. The POS worker can shorten its lock wait timeout and returns `Retry-After` and the raw body.
  - `tests/Feature/DocumentationParityTest.php` (5 checks).
  - `tests/Integration/ProductionBootTest.php` (T35).
- **Tooling:** `make audit` and a CI step "Dependency security advisories".
- **Docs:**
  - [docs/RELEASE-VERIFICATION.md](RELEASE-VERIFICATION.md), [docs/DEBUGGING-STORY.md](DEBUGGING-STORY.md) and [docs/M10-PULL-REQUEST.md](M10-PULL-REQUEST.md);
  - README (status, `make audit`, a second copy, links);
  - `docs/02-ARCHITECTURE.md` (`make audit`, the project check);
  - `docs/09-OPERATIONS-AND-PORTFOLIO.md` (two troubleshooting rows);
  - `START_HERE.md`: a status line. Its "the application has not been built yet" had been stale since M00;
  - DECISIONS (the M10 record), CHANGELOG, this file.

### Checks: exact command and actual outcome

PHP commands ran as `docker compose run --rm app …` against the isolated MySQL test databases, unless stated otherwise.

| Command | Outcome |
| --- | --- |
| `git status`, `git log`, `git remote -v` at the start | Clean tree; `main` at `a4c1ea0` = `origin/main` |
| Acceptance audit: every test name per file, and the T-numbers cited in the tests | T01–T34 covered by existing tests (see the matrix in RELEASE-VERIFICATION). T35 had no test, and the documented 503 `temporarily_unavailable` path had never run |
| Seeder, card and card-API tests after routing the seed through `FuelCardService` | **3 failed, 53 passed.** Three tests looked up "the" `card.limits_changed` row with `sole()`, and the seed now writes one too. Scoped to the card under test, then **85 passed / 1395 assertions** (seeders, cards, card API, audit, dashboard, reports) |
| The new contention test, first run | **Failed on the contract check:** `details` was an array. The cause was my worker, which decoded the body into PHP arrays and turned `{}` into `[]`; the server sends `{}`. The worker now returns the raw body too. Then **1 passed / 12 assertions** (9.45 s) |
| API, POS and concurrency suites after the contract-header change | **166 passed / 2268 assertions** |
| `DocumentationParityTest`, first run | 4 passed, 1 error: matching the API base URL `/api/v1` threw `NotFoundHttpException`. The test now accepts the base URL and POST-only endpoints. Then **5 passed / 97 assertions** |
| `php artisan optimize`, then `about --json`, in production mode with temporary cache paths (one-off container) | Config, events, routes and views cached; `about`: production, `debug_mode` false, all four caches in use |
| `ProductionBootTest` | **1 passed / 47 assertions** on the first run |
| `composer audit` (app, simulator), `npm audit` | No advisories / 0 vulnerabilities. `composer outdated --direct`: `laravel/framework` 13.34.0 and `phpunit/phpunit` 12.5.37 available, no advisory; not applied |
| A failing query in tinker on the test database (an unknown column, card number bound) | The `QueryException` message contained `card_no = FF-TEST-1234`. After setting `mask_bindings_in_exception_messages`: `ErrorResponsesTest` + `ProductionBootTest` **8 passed / 83 assertions** |
| `route:list`, routes without `auth` | Only the six public routes, plus `GET`/`PUT storage/{path}` (Laravel's local-disk serving, unused). `serve` turned off; the guest sweep **1 passed / 93 assertions** |
| `prepare-env.sh` under `dash` in a scratch folder | With a project and port: both written, with `APP_URL`. Without them: the `.env` is as before. A bad name or port is refused before anything is written |
| `docker compose config`, with and without `COMPOSE_PROJECT_NAME` in `.env` | `fleetfuel-copy` / `fleetfuel-copy-app:dev` / `fleetfuel-copy_mysql-data` / port 8091, against `fleetfuel` / `fleetfuel-app:dev` / `fleetfuel_mysql-data`. The development names are unchanged |
| `sh docker/bin/check-compose-project.sh` in the development checkout | exit 0 |
| `make audit` | No advisories (Composer for the app and the simulator, npm) |
| Deliberate breakages M1–M7 (file changed in place, tests run, file restored and checksum-verified) | All 7 caught; see the list below this table |
| `make verify` #1 | Stopped at `analyse`, with **4 Larastan errors** in new test code: a Symfony versus Laravel response type, route collections iterated without `getRoutes()`, an always-true assertion. Fixed |
| `make verify` #2 | exit 0 in 4 min 05 s:<br>Pint PASS on 317 files;<br>Larastan `[OK] No errors` and simulator PHPStan `[OK] No errors`;<br>**590 tests / 5972 assertions** (201.78 s);<br>`npm test` 14 passed;<br>npm 0 vulnerabilities;<br>Vite build OK |
| `DocumentationParityTest` while writing the docs | It caught two of my own mistakes:<br>a link to `RELEASE-VERIFICATION.md` before the file existed;<br>a placeholder URL `http://localhost:8080/…` in that file.<br>Both fixed: **5 passed / 112 assertions** |
| Local commit `1756382`, then the rehearsal from a clone in `/tmp` | The project check refused the unnamed clone correctly. Then **"mounts denied"**: Docker Desktop for Linux shares only some host folders with its VM (by default the home directory). The partial `fleetfuel-rehearsal` network and empty volume were removed, and the requirement was added to the README and docs/09 |
| The rehearsal again, from `/home/wassim/code/FleetFuel-Portal-m10-rehearsal` | **A.** Unnamed `make setup`: refused before building (exit 2).<br>**B.** `COMPOSE_PROJECT_NAME=fleetfuel-rehearsal APP_PORT=8092 make setup`: exit 0 in 58 s; `/health` ok, `/login` 200.<br>**`make verify`:** exit 0 in 246 s: Pint 317 files, Larastan OK twice, **590 tests / 5987 assertions** (199.07 s), 14 JS tests, 0 vulnerabilities, build OK.<br>**Repeated-setup check:** PASS.<br>**`make audit`:** no advisories.<br>**`git status`:** clean |
| The development stack afterwards (read-only) | Containers not recreated (up 5 hours, MySQL 3 days); `/health` ok; 36 purchases, 5 users |
| Rehearsal teardown | `down -v` for `fleetfuel-rehearsal` only, its image removed, the folder deleted. Remaining: `fleetfuel_mysql-data`, `fleetfuel_default`, `fleetfuel-app:dev` and the four development containers |
| Inside the running `app` and `scheduler` containers | The new config and service code are visible, and `route:list --path=storage` finds no route, so no restart is needed |

Deliberate breakages:
- **M1**, a historical limit change judged against the current month: `LedgerFixtureBuilderTest` failed (1).
- **M2**, lock timeouts rethrown instead of retried: the contention test failed (500, not 503).
- **M3**, query bindings unmasked: `ErrorResponsesTest` and `ProductionBootTest` failed.
- **M4**, local-disk serving back on: the guest sweep failed (the storage route answered a guest).
- **M5**, `APP_DEBUG=true` in the production boot: `ProductionBootTest` failed.
- **M6**, a README line with an unknown make target, Artisan command, endpoint, local URL and link: all 5 parity checks failed.
- **M7**, `Retry-After` made required on every POS 503: the contract test failed for `rate_unavailable`, which sends none. A first M7 that switched the header check off entirely passed, as expected, because every response sends its required headers. It proved nothing, so it was replaced.

### Not run or not verified

- GitHub CI for the M10 commit was not observed when this log was written. It was checked after the push: run 36914194485 passed (590 tests / 5987 assertions, 14 JavaScript tests, `make audit`).
- The hosted deployment, HTTPS, secure cookies, backups and restore (M11).
- Newman: not rerun in M10. The simulator suite covers the POS scenarios on every run.
- Browser checks: not repeated (no UI changed in M10). The phone-overflow guard is still the manual Chrome sweep.
- `check-compose-project.sh` has no automated test. It was exercised in the rehearsal (refusal) and in the development checkout (pass). It cannot see a project whose containers were removed.
- MySQL's duplicate-key messages still quote the duplicated value (recorded as a limitation).
- Carried over: the simulator with a host PHP outside Docker, SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-10-01, M10):
- the seed's limit change through the service, and audit history not rewritten;
- masked query bindings;
- the optional `Retry-After` and the required headers;
- the second-copy project name, port and check;
- local-disk serving off and the public-route list;
- `make audit` outside `verify`;
- T35 tested in-process.

No product rule changed. `openapi.json` changed in documentation only (0.10.0).

### Remaining work and blockers

None for M10.
- M11 needs your decisions on hosting, the demo policy and the license before anything is published. The production image, runbooks and README can be prepared and verified locally first.

**Suggested commit message:** `test: complete release checks and clean-clone verification`.

**One concept to explain:** a release check only proves something if it can fail, in an environment that cannot touch real data.
- **Each claim points to a test, and each new test was broken on purpose once.** Switching the masking off, the retry off or the debug flag on made the matching test fail. A check that cannot fail, like the first M7, proves nothing.
- **The clean clone tests the instructions, not just the code.** A fresh copy had only the tracked files and the README's commands. It set itself up, passed every gate and left no tracked file changed. That shows nothing depends on files that exist only on the developer's machine.
- **Isolation is a naming problem in Docker Compose.** Containers, networks, volumes and images are named after the project. Two copies with one name share one database. Giving the copy its own project name and port, and refusing to start someone else's project, is what made the rehearsal safe next to the real development stack.

## Earlier session: M09

**Date / milestone:** 2026-10-01, M09 dashboard and UI finish.

**Goal and actual state:**
- Goal (prompts/09-ui-polish.md):
  - the role-scoped dashboard and responsive Bootstrap navigation;
  - AJAX filters and totals protected against stale responses;
  - accessible forms, and clear loading, empty and error states;
  - escaped dynamic text, CSRF on browser writes, and real screenshots.

  Run the five-minute walkthrough for every role, check mobile, keyboard, expired-session and error flows (T34), and explain the query-count and filtering decisions.
- Result: done. All local gates pass on MySQL, and the screens were checked in a real Chrome browser.
- M08 had nothing outstanding: the tree was clean, `main` matched `origin/main` at `12021fe`, and GitHub CI run 36845821888 had passed on it (observed at the end of the M08 session).
- Scope note: the UI spec's audit screen was deferred in M03 as "later UI work". It is built here because M09 is the last UI milestone and F10 needs it (recorded in DECISIONS).

### What was built

- **Layout:**
  - `resources/views/layouts/app.blade.php`: a navy sidebar, an off-canvas menu below 992 px, grouped role-based links and a skip link;
  - new `layouts/_flash` and `layouts/_footer`;
  - `resources/css/app.css`: tokens, focus outlines, the loading dimmer and the `.table-responsive` fix.
- **Dashboard:**
  - `Web\DashboardController`: two months in one grouped query, quota warnings through `ReportRepository::quotaExceptions`, open deliveries, and the rate in use through `PriceResolver::findRate`;
  - the `dashboard` view.
- **Transactions:**
  - `Web\TransactionController@index` and `@results` (JSON `{html, summary, url, from, to}`);
  - `Requests\Transactions\ListTransactionsRequest` (the `FiltersTransactions` rules, `filterQuery()`);
  - views `transactions/{index, _results}`;
  - `_table` gained an optional rate-source column;
  - `partials/rate-source-badge`;
  - routes `transactions.index` and `transactions.results`.
- **Audit log:**
  - `Web\AuditLogController`, `Requests\Audit\ListAuditLogsRequest`, `App\Support\AuditDisplay`;
  - the `audit/index` view and the `audit.index` route (admins).
- **JavaScript:**
  - `resources/js/transaction-filters.js`;
  - `resources/js/lib/latest-only.js` and `lib/ajax-failure.js` (no imports, unit-tested);
  - `delivery-actions.js` now uses `describeFailure()`, which adds the 401 case and a sign-in link;
  - `app.js` imports the new module.
- **Errors and sessions:**
  - `resources/views/errors/{layout, 403, 404, 419, 4xx, 429, 5xx, 500, 503}`;
  - `EnsureUserIsActive` returns 401 to page scripts.
- **Accessibility:** `aria-invalid` in the form components and on the sign-in form.
- **Text updates:**
  - the home page text (no longer "added in later milestones");
  - a link from the station page to its full list;
  - the purchase page's rate-source badge.
- **Tooling:**
  - `package.json` `npm test` (Node's built-in runner, no new package);
  - `make test` runs it after PHPUnit.
- **Tests (36 new PHP tests and 14 JavaScript tests; 3 PHP tests changed):**
  - `tests/Feature/Transactions/TransactionScreensTest` (7);
  - `tests/Feature/Dashboard/DashboardTest` (6);
  - `tests/Feature/Audit/AuditScreenTest` (5);
  - `tests/Feature/UiShellTest` (4);
  - `tests/Unit/Support/AuditDisplayTest` (13 cases);
  - one new `WebLoginTest` case;
  - `resources/js/tests/{latest-only, ajax-failure}.test.js` (6 + 8).

  Helpers: `tests/Concerns/CountsQueries`, and `BuildsLedgerFixtures::addCardOnlyPurchases()`. Changed: `TenantIsolationTest` (the dashboard's view data is renamed) and `CsrfProtectionTest` (the 419 page's content).
- **Docs:**
  - `docs/screenshots/` (9 WebP files and a README);
  - README (status, screenshots, "Dashboard, transactions and audit log", `make test`);
  - `docs/02-ARCHITECTURE.md` (`make test`);
  - `docs/DECISIONS.md` (M09 record), CHANGELOG, this file.

### Checks: exact command and actual outcome

PHP commands ran as `docker compose run --rm app …` against the isolated MySQL test databases, unless stated otherwise.

| Command | Outcome |
| --- | --- |
| `git status`, `git log`, `git branch -vv` at the start | Clean tree; `main` at `12021fe` = `origin/main` |
| `node --test "resources/js/tests/*.test.js"` (node container) | 13 passed on the first run |
| Pint and Larastan after the PHP code | Pint PASS on 309 files; Larastan `[OK] No errors` |
| Existing screen, auth, report, delivery, fleet and price tests after the new layout | **2 failed, 154 passed**: `TenantIsolationTest` read the dashboard's old `monthTotals` view variable, renamed to `current`/`previous`. Updated, then 9 passed / 109 assertions |
| `TransactionScreensTest` + `WebLoginTest` | 20 passed / 239 assertions on the first run |
| `DashboardTest` + `TransactionScreensTest` | 13 passed / 187 assertions on the first run |
| `AuditScreenTest`, `UiShellTest`, `AuditDisplayTest`, `CsrfProtectionTest` | **1 failed, 28 passed**: the 500 page was Laravel's own. Laravel ships 429, 500 and 503 views, which win over a `5xx`/`4xx` fallback, so `errors/{429,500,503}` now extend ours. Then 4 passed / 102 assertions |
| Throwaway copy for the browser checks: database `fleetfuel_test_screens` (test user), `migrate`, `demo:seed` with a one-off demo password | 32 purchases, 15 in October. Two setup mistakes on the way: (1) `php artisan serve` passes only a few environment variables to its PHP workers, which re-read `.env`, so the copy was really serving the **dev** database. My two sign-ins with the one-off password failed there (logged on dev as failed sign-ins; no data changed). (2) `php -S` with Laravel's router script must run from `public/`. Restarted correctly; the copy then answered from its own database |
| Real Chrome (DevTools protocol), desktop 1366 × 900 and phone 390 × 844 | See "Browser checks" below |
| `make simulate SCENARIO=all` against the throwaway copy (`POS_BASE_URL=http://ffshots:8000/api/v1`, Harbor operator, password in the environment) | **5 scenarios, 16 checks passed**. One 20.00 L purchase (id 33, 1600000.00 LBP / 17.88 USD); identical and equivalent replays returned it; conflict 409; blocked 403; quota 403. Ledger 32 → 33 |
| Docker Desktop file sharing | The throwaway server kept serving an old `dashboard.blade.php`. The host file was inode 20500298 after `sed -i`; the running container still saw 20500297. Restarting the container fixed it, and I repeated the checks that depended on those files. Files edited in place were seen at once. The dev `app` and `web` containers were restarted for the same reason |
| Negative checks J1, J2, P1–P10, P1b (file mutated, tests run, file restored in place and checksum-verified) | All 13 caught; see the list below this table |
| `docker compose restart app web`, then a one-off Node smoke script through nginx (password from `.env` in an environment variable, never printed) | **First run: 20 passed, 3 failed**, all my own check: I assumed sign-out redirects to `/`, but `config/fortify.php` sends it to `/login`. The check now also requires that the old cookie no longer opens `/transactions`. Then **23/23**: the guest is redirected or gets 401; admin, manager and operator open their pages (`/audit` 403 for the others); results JSON 200; no Cedar rows for the manager; sign-out ends each session |
| `make verify` #1 | exit 0: Pint PASS on 315 files, Larastan `[OK] No errors`, simulator PHPStan `[OK] No errors`, **580 tests / 5716 assertions** (184.93 s), `npm test` 14 passed, npm 0 vulnerabilities, Vite build OK |
| Change after review: business refusals with a `code` (such as `company_inactive`, 403) show their own message on the delivery buttons again; framework 403 texts are still hidden | `npm test`: 14 passed (one new case). It was already included in `make verify` #1, whose `npm test` step ran after the edit |
| `make verify` #2, the committed tree | exit 0: Pint PASS on 315 files, Larastan `[OK] No errors`, simulator PHPStan `[OK] No errors`, **580 tests / 5716 assertions** (176.56 s), `npm test` 14 passed, npm 0 vulnerabilities, Vite build OK |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS: repeated setup kept APP_KEY, credentials and database rows (and restarted the stack, so every container reads the current files) |

Negative checks:
- **J1**, the latest-only sequence check removed: 2 JavaScript tests failed. In Chrome with that build, the delayed older answer replaced the newer filter: Unleaded 95 rows and address while "Unleaded 98" was selected. With the real build the same script shows Unleaded 98.
- **J2**, server error bodies shown to the user: the "never shown" test failed (`SQLSTATE…` would have appeared).
- **P1**, eager loading removed from the list: 6 tests failed. Laravel's strict mode refuses lazy loading outside production, so the pages returned 500.
- **P1b**, the same with strict mode off, as in production: only the query-count test failed, with **102 queries instead of 6**.
- **P2**, the pager running its own `COUNT`: the query-count test failed (7, not 6).
- **P3**, totals of the first page only: the totals/CSV test failed (25 rows in the totals, 32 in the CSV).
- **P4**, the list not tenant-scoped: 2 tests failed (scope and tampered filters).
- **P5**, the CSV link without explicit dates: the totals/CSV test failed (the CSV fell back to the current month: 15 rows, not 32).
- **P6**, the dashboard's open deliveries without the eager-loaded company: 3 tests failed (lazy loading refused).
- **P7**, quota warnings not scoped to the manager: the other-manager test failed.
- **P8**, audit redaction disabled: 5 tests failed (the screen and 4 unit cases).
- **P9**, the audit entity allowlist removed: the fixed-list test failed (200, not a redirect with an error). The value is bound either way, so no SQL could be injected; the allowlist is what turns it into a clear error.
- **P10**, the disabled-user JSON branch removed: the new `WebLoginTest` case failed (302, not 401).

### Browser checks (real Chrome through the DevTools protocol, on the throwaway copy)

- **Keyboard sign-in:** the email field has focus; Tab goes to the password, Enter submits.
  - A wrong password shows the generic error, linked with `aria-describedby` and `aria-invalid`; the email is kept and focus returns to it.
  - The correct password goes to the dashboard (admin) or the station page (operator).
- **AJAX list, admin:**
  - Choosing Diesel reloaded only the results: a marker set on `window` survived, so there was no page reload.
  - The address became `?from=2026-10-01&to=2026-11-01&product_code=DIESEL` and the live region read "Showing 1–10 of 10 purchases."
  - The CSV of that filter, fetched from the page, had 10 rows and exactly the page totals: 635.00 L, 50,800,000.00 LBP, 567.24 USD.
- **Latest response wins (T34):** `$.ajax` was wrapped so the first answer arrived 1.5 s late, and its `abort()` did nothing, as if the answer were already on its way. Choosing Unleaded 95 and then Unleaded 98: the late answer arrived and was ignored, and the page, address and totals stayed on Unleaded 98 (2 purchases, 75.00 L). Repeated after the container restart with a card filter: the newest, empty result stayed. J1 above shows the same script catching the bug.
- **Offline:**
  - With the network emulated offline, a filter change showed "No answer from the server. Check your connection and try again." as an assertive alert with **Try again**; the old totals and rows were removed and the address was unchanged.
  - Back online, Try again (keyboard) restored the results and the address.
- **Validation:** a "before" date earlier than "from" put the message under the field (`aria-invalid`, `aria-describedby`), cleared the totals, and everything reset once the date was fixed.
- **History and paging:**
  - Back restored the earlier filter and results without a reload.
  - Page 2 by keyboard showed "Showing 26–32 of 32 purchases.", kept the totals at 32 and moved focus to the results.
  - A filter change from page 2 went back to page 1, and a full reload of a filtered address showed the same view.
- **Expired session:**
  - Signed out in a second tab: in the first tab, a filter change showed "Your session has expired. Sign in again to continue." with a **Sign in again** link, and no stale totals.
  - The delivery buttons showed the same message, re-enabled the button, and left order 5 pending.
  - An old sign-out form posted from a signed-out tab was **not** a 419: Laravel 13 accepts the browser's `Sec-Fetch-Site: same-origin`. The `auth` middleware sent the user to sign in, and after signing in they returned to the transaction list.
  - The 419 page itself is covered by `CsrfProtectionTest` with the real middleware.
- **Phone (390 × 844):**
  - 18 manager pages, 9 admin pages (audit, companies, rates, prices, forms) and 5 operator pages: no horizontal page overflow; wide tables scroll inside their wrapper.
  - The first sweep found 5 pages at up to 782 px: absolutely positioned screen-reader text in table cells escaped `.table-responsive`. Confirmed in place, fixed in CSS, and the sweep repeated.
- **Menu and skip link on a phone:**
  - Tab shows "Skip to main content" at the top; Enter, then Tab, lands on the first link in the main content.
  - Tab reaches **Menu**, with the visible focus outline; Enter opens the panel as a modal dialog with focus inside; Escape closes it and returns focus to Menu.
- **Not found:** Cedar's card, purchase, delivery and vehicle opened by the Atlas manager returned the same "Page not found" page, with no Cedar text. Admin pages were 403 for the manager and the operator.

### Five-minute walkthrough (UI spec), all roles, on the throwaway copy

1. The admin dashboard shows every company (16 purchases this month, 860.00 L at the time of the screenshot; quota warnings; 3 open deliveries; the fixture rate with its source). The manager's shows Atlas only. Cedar records are 404 by URL, and `?company_id=` on reports is ignored.
2. The manager's card `FF-ATLAS-001` page: 0.00 of 100.00 L. After the simulator's purchase and replays: 20.00 L used, 80.00 L left, one purchase listed.
3. Blocked, conflict and over-quota requests were refused with nothing charged; the ledger grew by exactly one row (32 → 33).
4. The manager requested order 7 through the form (keyboard submit).
   - The admin scheduled it through the in-place panel; the button was disabled while the request ran, and the panel reloaded.
   - The manager's stale tab tried to cancel and was refused with "This order changed in the meantime…"; its panel refreshed with no cancel form.
   - The admin dispatched and delivered it, and the manager saw the four-step timeline.
5. The manager filtered transactions by a lowercase card number (normalized) and by date. The CSV matched the totals (all Atlas: 20 rows, 1,140.00 L, 91,140,000.00 LBP, 1,017.85 USD).
   - The admin's anomalies page shows the 75 L fill on a 60 L tank and the 20-minute refill.
   - Purchase 28 shows its "Manual override" source at 89,700.00000000 LBP per USD.
- **Operator:** sees only Station, Transactions, Stations and Products. The list shows 17 Harbor purchases, with no station filter and no CSV. The dashboard, reports, CSV, cards and audit are 403, and North purchases are 404.

The throwaway server was stopped afterwards, `fleetfuel_test_screens` dropped, the one-off password file deleted and the browser tabs closed.

### Not run or not verified

- GitHub CI for the M09 commit was not observed when this log was written. It was checked after the push: run 36874363104 passed (580 tests / 5716 assertions, 14 JavaScript tests).
- A real screen reader (NVDA, VoiceOver): I checked the accessibility tree, focus order and ARIA attributes, not spoken output.
- Real phone hardware and browsers other than Chrome (Firefox, Safari). The phone checks used Chrome's device emulation.
- An automated accessibility audit (axe, Lighthouse). The sidebar colours' contrast was computed by formula, not measured with a tool.
- The phone-overflow fix has no automated regression test (CSS). It was verified in the browser only.
- The screenshots come from the throwaway copy, not the dev database.
- Carried over: the 503 `temporarily_unavailable` path, the simulator with a host PHP outside Docker, SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-10-01, M09):

- the layout and navigation;
- the audit screen brought into M09;
- the transaction list's shared rules and redirect;
- one aggregate for the totals and the count;
- the AJAX design (server-rendered fragment in JSON, latest response wins, the address as state, error states);
- the web AJAX error shapes and the disabled-user 401;
- the error pages and the `Sec-Fetch-Site` observation;
- the dashboard contents;
- the rate badges;
- the phone fix;
- Node's built-in test runner;
- the screenshots.

No product rule and no API changed.

### Remaining work and blockers

None for M09.
- Carried over: the seed's `fuel_card.limits_changed` versus the service's `card.limits_changed` audit names, now visible as two actions in the audit filter (for M10).
- Possible M10 work: a browser-level regression check for the phone overflow.

**Suggested commit message:** `feat: finish the dashboard and responsive AJAX workflows`.

**One concept to explain:** a filtered list has one source of truth, the server, and only its newest answer may reach the screen.
- **Filtering happens once, on the server.** The page, the AJAX results, the API list and the CSV all apply the same `TransactionFilters` to the same tenant-scoped query. The browser only sends the form's values and shows what comes back; it never filters or adds up rows itself. That is why the totals and the CSV agree by construction, and why a manager cannot widen their view by editing the address.
- **Only the newest answer counts.** Requests can finish out of order: a slow answer to an old filter may arrive after the answer to the new one. Each request gets a sequence number. The previous request is aborted when possible, and any answer that is not the newest is ignored, because aborting alone cannot stop an answer already on its way.
- **The number of queries must not grow with the rows (N+1).** Loading each purchase's company, station, product and card one row at a time costs four queries per row: 102 queries for one page in P1b. Eager loading (`->with([...])`) fetches each relation once per page; one aggregate gives both the totals and the row count. The list runs 6 queries whether it shows 1 row or 25. Laravel's strict mode turns any accidental lazy load into an error outside production, and a test counts the queries so production-like code cannot regress.

## Earlier session: M08

**Date / milestone:** 2026-10-01, M08 SQL reports and accounting CSV.

**Goal and actual state:**
- Goal (prompts/08-reports-exports.md):
  - bound SQL in `ReportRepository` for every listed report;
  - ID-based grouping and snapshot ownership;
  - deterministic windows and correct predecessor lookback;
  - tenant-scoped filtered totals and a safe streaming CSV;
  - documented query plans and indexes, and no repricing.

  Verify totals against fixtures, cross-tenant access, the quota reduction report, the rapid-fill boundary, formula neutralization and the delivery SLA (T29–T33).
- Result: done, and all local gates pass on MySQL.
- M07 had nothing outstanding: the tree was clean, `main` matched `origin/main` at `93415df`, and GitHub CI run 36838076726 had passed on it.

### What was built

- **Repository and scope:** `app/Repositories/ReportRepository.php`:
  - `consumption`, `topStations`, `quotaExceptions` (with reasons), `tankOverfills`, `rapidFills`, `efficiency`, `deliverySla`;
  - `App\Support\ReportScope`, which pins a manager to their own company, with `forConsole` for diagnostics;
  - the Gate abilities `viewReports` and `exportTransactions`.
- **Shared filters:**
  - `App\Support\TransactionFilters`;
  - the request traits `FiltersBusinessDates` (dates, the 366-day limit, the UTC range) and `FiltersTransactions` (the ledger filters);
  - `ListTransactionsRequest` and `TransactionController::index` now use them, with the same behavior.
- **CSV:**
  - `App\Services\TransactionCsvExport` (keyset chunks inside one transaction, `fputcsv` with RFC 4180 quoting);
  - `App\Support\CsvCell`;
  - config `fleetfuel.exports.chunk_size` (500).
- **API:**
  - `Api\V1\ReportController@consumption` and `Api\V1\TransactionExportController`;
  - `ConsumptionReportRequest` and `ExportTransactionsRequest`;
  - two routes. `openapi.json` 0.8.0 marks both `implemented`, with descriptions.
- **Web:**
  - `Web\ReportController` (six pages) and `Web\TransactionExportController`;
  - `Requests\Reports\{ReportRequest, ExportTransactionsRequest}`;
  - views `reports/{_header, consumption, top-stations, quota-exceptions, anomalies, efficiency, delivery-sla}`;
  - the Reports nav link.
- **Database:** migration `2026_10_01_120000_add_transacted_at_index_to_fuel_transactions_table`.
- **Diagnostics:** `php artisan reports:explain` (`app/Console/Commands/ExplainReports.php`).
- **Tests (36 new, 2 changed):**
  - `tests/Feature/Reports/{ReportRepositoryTest 12, ReportScreensTest 6}`;
  - `tests/Feature/Api/ReportApiTest` (6);
  - `tests/Unit/Support/CsvCellTest` (12 cases).
  - `OpenApiContractTest` now requires no planned operation, and `ChecksOpenApiContract` checks non-JSON (CSV) responses by media type.
- **Postman:** folder 04 rebuilt (10 requests); the environment template gained `report_manager_token` (blank).
- **Docs:**
  - `docs/REPORT-QUERY-PLANS.md` (new);
  - `docs/05-API-CONTRACT.md` ("Implemented behavior (M08)");
  - `docs/DECISIONS.md` (M08 record);
  - `docs/03-DATA-MODEL.md` (the new index);
  - README ("Reports and the accounting CSV", API table, Postman);
  - `postman/README.md`, CHANGELOG, this file.

### Checks: exact command and actual outcome

All PHP commands ran as `docker compose run --rm app …` against the isolated MySQL test databases, unless stated otherwise.

| Command | Outcome |
| --- | --- |
| `git status`, `git rev-parse HEAD origin/main`, `gh run list` at the start | Clean tree; both at `93415df`; run 36838076726 success |
| `TransactionReadApiTest` + `OpenApiContractTest`, after moving the ledger filters into shared code | 25 passed / 298 assertions: the list behaves as before |
| Pint and Larastan after the repository, requests, controllers and views | Pint fixed one import order (`AppServiceProvider`); Larastan `[OK] No errors` |
| Every report query against the dev database (tinker, September and October) | All ran on MySQL. The 75 L overfill and the 1,200 s rapid fill were flagged, card-only purchases formed one null-ID vehicle group, and seed order 1 came out at 74.00 h |
| `OpenApiContractTest`, after marking both operations implemented | 7 passed / 188 assertions |
| `ReportRepositoryTest` | 12 passed / 55 assertions on the first run |
| `ReportApiTest` + `CsvCellTest` | 18 passed / 211 assertions on the first run |
| `ReportScreensTest`, first run | **2 failed, 4 passed**, both my test's mistakes: I guessed Atlas's per-station liters (255/260) instead of working them out (290/225), and expected a full card number although the quota page masks card numbers. Fixed: 6 passed / 107 assertions. I then made the manager's quota check meaningful: it could never fail, so it now blocks a Cedar card and asserts that the admin sees it and the manager does not |
| Negative checks R1–R11 (each file mutated, tests run, file restored and checksum-verified) | All 11 caught; see the list below this table |
| `php artisan reports:explain`, first try on dev | Failed with `HY093`: MySQL accepts no placeholders in `EXPLAIN`. The command now inlines the values with the connection's own escaping, only for EXPLAIN |
| Representative plans in a throwaway database `fleetfuel_test_explain` (migrate, seed, 2,000 shifted copies of each purchase = 64,032 rows, `ANALYZE TABLE`; dropped afterwards) | My first attempt did not create the database: zsh does not split an unquoted `$E`, so `docker compose run` got one malformed argument. Rerun with explicit flags. First measurement: all-company reports and each CSV chunk scanned all 64,032 rows (85–110 ms); the efficiency estimate read 56,028 rows of history (747 ms). After adding the `(transacted_at, id)` index and bounding the efficiency lookback, the efficiency estimate stayed slow (861 ms): MySQL walked the new time index backwards per vehicle (26,057 rows each). Ordering that lookup by `vehicle_id` too fixed it (44.5 ms, then 58.7 ms in the final capture). Full table: `docs/REPORT-QUERY-PLANS.md` |
| Report tests after these two changes | 24 passed / 363 assertions |
| `php artisan migrate` on the dev database | `2026_10_01_120000_add_transacted_at_index_to_fuel_transactions_table` DONE (adds an index; changes no data) |
| `docker compose restart app`, then **Newman folder 04** (`npx --yes newman@6 … --folder "04 — Reports and export (M08)"` in the node container, through nginx, password in an environment variable) | **10 requests, 24 assertions, 0 failures** on the first run. Company, vehicle and product groupings, the ledger totals and the CSV agreed on the same liters for 2025-10-06 to 2026-10-03; the CSV row count equalled the ledger total; `company_id` and `group_by=station` were refused (422); the token was revoked (204, then 401) |
| Browser smoke test through nginx (one-off Node script, not committed; signs in as the Atlas manager with the password from the environment) | All six report pages 200 without Cedar data. The browser CSV returned 200 with `text/csv; charset=UTF-8`, `attachment; filename=fleetfuel-transactions-2026-08-01-to-2026-09-30.csv`, and its liters matched the page total (1200.00). One check failed because of my script: it split lines on commas, but `fputcsv` quotes cells containing a space (`"Atlas Logistics"`). Checked through the exporter itself: all 23 rows are Atlas Logistics. Signed out afterwards |
| `make verify` #1 | exit 2: Pint `single_quote` in my new screens test. Fixed with Pint |
| `make verify` #2 | exit 2: Larastan `method.alreadyNarrowedType` in the SLA test. It calls `deliverySla()` again after changing the order columns, and PHPStan assumes the same call returns the same value. `@phpstan-impure` on the method did not change that (cache cleared too), so I removed the tag; the test now computes again through a freshly resolved repository |
| `make verify` #3 | exit 0: Pint PASS on 305 files, Larastan `[OK] No errors`, simulator PHPStan `[OK] No errors`, **544 tests / 5312 assertions** (176.30 s), npm 0 vulnerabilities, Vite build OK |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS: repeated setup kept APP_KEY, credentials and database rows; `migrate:status` lists the new migration as run |
| `php artisan usage:reconcile` (dev) | "All monthly usage counters match the ledger." |

Negative checks:
- **R1**, a manager given the admin's company filter: 3 tests failed (repository, screens and API scope).
- **R2**, no lookback before the range start: the rapid-fill test failed.
- **R3**, `<=` instead of `<` (exactly 30 minutes counted as rapid): the rapid-fill test failed.
- **R4**, grouping by label instead of ID: the equal-names test failed.
- **R5**, vehicle taken from the card's current assignment: the snapshot test failed.
- **R6**, formula neutralization disabled: the CSV test and 8 `CsvCell` cases failed.
- **R7**, the keyset cursor re-reading the last row of each chunk: the chunking test failed (the files differed).
- **R8**, SLA from the order's `created_at`/`delivered_at`: the history-boundary test failed.
- **R9**, `>` instead of `>=` in quota exceptions: the "limit reached exactly" case failed.
- **R10**, the `group_by` allowlist removed from the request: the API test got 500 instead of 422. The repository's own allowlist still refused (`InvalidArgumentException: Unknown consumption grouping "station"`), so no SQL ran: defense in depth, and the request is what turns it into a proper 422.
- **R11**, tank overfill compared against the vehicle's current capacity: the snapshot test failed.

### Not run or not verified

- GitHub CI for the M08 commit was not observed when this log was written. It was checked after the push: run 36845821888 passed (544 tests / 5312 assertions).
- A real browser: the report pages ran in feature tests and through nginx with a script, not in a browser. Visual, keyboard and mobile checks are M09.
- The Postman desktop app: folder 04 ran with Newman 6.
- Query plans at a scale beyond the demo's shape: 64,032 purchases but still 2 companies, 10 cards and 16 history rows.
- Carried over: the 503 `temporarily_unavailable` path, the simulator with a host PHP outside Docker, SQL Server (S01). The report SQL deliberately refuses other drivers.

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-10-01, M08):

- raw bound SQL with an allowlist, and `ReportScope`;
- snapshots only, never repriced;
- the rapid-fill lookback and the bounded efficiency lookback;
- quota exceptions on today's limits, with "reached" counted;
- the SLA from history rows, MySQL only;
- the shared ledger filters;
- the CSV streaming, snapshot, quoting and neutralization;
- the `(transacted_at, id)` index and the plan regression it caused, then fixed;
- `reports:explain`;
- the screen conventions;
- the self-contained Postman folder 04.

No product rule changed.

### Remaining work and blockers

None for M08.
- Carried over: the seed's `fuel_card.limits_changed` versus the service's `card.limits_changed` audit names (for M10).
- Deliberately later: the web transaction list with AJAX filters and totals, dashboard quota warnings, screenshots and the accessibility pass (M09).

**Suggested commit message:** `feat: add SQL consumption reports and scoped accounting exports`.

**One concept to explain:** what each piece of a report query is for.
- **WHERE, then GROUP BY, then HAVING.** WHERE picks rows before grouping: the tenant and the date range, so a manager's other-company rows never reach the totals. GROUP BY folds the remaining rows into one row per company, vehicle or product ID; grouping by ID, not name, keeps two "Atlas Logistics" apart. HAVING would filter whole groups after the sums, such as "vehicles over 500 L". None of these reports needs that, so it is not used.
- **LAG().** A window function that reads the previous row in an order you choose (here, the same vehicle by time, then ID), without collapsing rows the way GROUP BY does. That makes "minutes since this vehicle's previous fill" one query. The window only sees the rows you give it, so the query must include the predecessor of the first fill in range: 30 minutes back for rapid fills, one row per vehicle for the efficiency estimate.
- **Bound values, allowlisted identifiers.** A date or company ID is data and goes in as a `?` binding, so it can never become SQL. A column or join cannot be bound, so the request's `group_by` only selects one of three fixed fragments written in the code (R10 shows both gates).
- **Index tradeoffs.** An index is a sorted copy of some columns. It turns "check every row" into "read a range", at the cost of one more entry per write. The time index made the admin reports about 5–10× faster. It also tempted MySQL into a worse plan for a query nobody touched, so measure the neighbors after adding one.

## Earlier session: M07

**Date / milestone:** 2026-09-30 to 2026-10-01, M07 diesel delivery workflow.

**Goal and actual state:**
- Goal (prompts/07-delivery-orders.md):
  - scoped orders, the lifecycle service and the `expected_status` check;
  - the scheduling and truck requirements and the cancellation rules;
  - atomic history and audit, and the Bootstrap/AJAX screens;
  - fulfillment never debits a fuel card.

  Demonstrate pending → scheduled → out_for_delivery → delivered, a manager cancellation, refused skip/stale/final changes, and run the concurrent-transition test (T26–T28).
- Result: done, and all local gates pass on MySQL.
- M06 had nothing outstanding: the tree was clean, `main` matched `origin/main` at `a59210f`, and GitHub CI run 36734519806 had passed on it.

### What was built

- **Service:** `app/Services/DeliveryOrderService.php`:
  - `create()` and `transition()`, plus `createHistorical()` and `transitionHistorical()` for the demo seed (same rules, judged at a given time);
  - `HORIZON_DAYS = 366`.

  Supporting changes:
  - `DeliveryOrderPolicy::transition()`;
  - `DeliveryStatus::label()`;
  - delivery refusals in `BusinessRuleViolation` (`staleDeliveryState`, `invalidDeliveryTransition`, `deliveryTransitionForbidden`, `deliveryCompanyInactive`), which gained optional `details`;
  - `AuditService::record()` gained an optional time.
- **Seed:** `LedgerFixtureBuilder::createDelivery()`/`transitionDelivery()` now delegate to the service. `DemoSeeder` is unchanged, and seeded history is unchanged.
- **API:**
  - `Api\V1\DeliveryOrderController` (index, store with `Location`, show, updateStatus);
  - `ListDeliveryOrdersRequest`, `StoreDeliveryOrderRequest` and `TransitionDeliveryRequest`, extending the web requests with unknown-field refusal, offset timestamps and the per-role token ability;
  - `DeliveryOrderResource` and `DeliveryHistoryResource`;
  - four routes in `routes/api.php`; the status route uses `ability:deliveries:status,deliveries:write`;
  - the tenant-scoped `{delivery}` binding in `AppServiceProvider`.
- **Web:**
  - `Web\DeliveryOrderController` (index, create, store, show, panel, updateStatus) and six routes;
  - `Requests\Deliveries\{StoreDeliveryOrderRequest, TransitionDeliveryRequest}`;
  - views `deliveries/{index, choose-company, form, show, _panel}` and `partials/delivery-status-badge`, plus the nav link;
  - `resources/js/delivery-actions.js`, imported by `app.js`;
  - `bootstrap/app.php`: business-rule refusals now return JSON to web requests that ask for it.
- **Contract:** `docs/api/openapi.json` 0.7.0. The four delivery operations are `implemented` with descriptions. The status route has `x-any-abilities`, and the 201 a `Location` header.
- **Tests (27 new, 1 changed):**
  - `tests/Feature/Api/DeliveryApiTest` (8);
  - `tests/Feature/Deliveries/{DeliveryOrderServiceTest 4, DeliveryScreensTest 10}`;
  - `tests/Concurrency/DeliveryConcurrencyTest` (4) with `tests/Concurrency/delivery-worker.php`;
  - one new `DeliveryStatusTest` case.
  - `OpenApiContractTest`'s route parity now reads `ability:` middleware, and the planned list is down to the two M08 operations.
  - The worker helpers moved from `PosConcurrencyTest` into `tests/Concerns/RunsConcurrentWorkers.php`; its behavior is unchanged.
- **Postman:** folder 03 rebuilt (19 requests). The environment template gained `admin_email`, `admin_token`, `delivery_manager_token`, `delivery_b_id`, `schedule_start` and `schedule_end`; the token fields are blank.
- **Docs:**
  - `docs/05-API-CONTRACT.md` ("Implemented behavior (M07)", and the token-ability exception);
  - `docs/DECISIONS.md` (M07 record);
  - README ("Diesel deliveries", API table, Postman);
  - `postman/README.md`, CHANGELOG, this file.

### Checks: exact command and actual outcome

All PHP commands ran as `docker compose run --rm app …` against the isolated MySQL test databases, unless stated otherwise.

| Command | Outcome |
| --- | --- |
| `git status`, `git log`, `git rev-parse HEAD origin/main`, `gh run list` at the start | Clean tree; both at `a59210f`; run 36734519806 success |
| `php artisan test tests/Feature/Seeders tests/Feature/Database tests/Unit/Enums`, after the builder started delegating | 69 passed / 843 assertions: the seed through the service produces the same history |
| `php artisan route:list --path=deliver` | 10 routes (4 API, 6 web) |
| Larastan and Pint after the HTTP layer | `[OK] No errors`; Pint PASS on 278 files |
| `DeliveryApiTest` + `OpenApiContractTest`, first run | **1 failed, 14 passed.** A mistake in my test: the lifecycle test jumped two days ahead with a 24-hour token, so the 401 was right (the same slip as in M06). Each later step now signs in again. Then 8 passed / 538 assertions |
| `DeliveryOrderServiceTest` | 4 passed / 19 assertions on the first run |
| `tests/Feature/Deliveries` | 14 passed / 131 assertions. Before the first run I fixed a guessed manager name in an assertion and put the timeline line on one line, so text matching works |
| `php artisan test --testsuite=Concurrency`, after extracting `RunsConcurrentWorkers` | 16 passed / 71 assertions: the 4 new T28 tests and the 12 M05 tests |
| Negative checks (each file mutated, suite run, file restored and checksum-verified) | All 10 caught; see the list below this table |
| Larastan on the new tests | 8 errors, all redundant `?->` after PHPUnit's type narrowing in my tests; fixed, then no errors. Pint PASS on 284 files |
| `docker compose restart app`, then **Newman folder 03** (`npx --yes newman@6 … --folder "03 — Delivery scenario (M07)"` in the node container, through nginx, password in an environment variable) | **19 requests, 38 assertions, 0 failures** on the first run. Order 7: created, manager schedule 403, skip 409 `invalid_transition`, scheduled, repeat 409 `stale_state` (`current_status` scheduled), manager cancel 403, dispatched, delivered, cancel 409 `invalid_transition`, four-step timeline. Order 8: cancelled by its manager. Both tokens revoked (204, then 401). Dev database: orders 6 → 8, history 16 → 22, audit 26 → 30, purchases 36 → 36, 0 `postman-local` tokens; `usage:reconcile`: "All monthly usage counters match the ledger." |
| **jsdom UI check** (one-off harness, not committed): jsdom 26 installed in the node container's `/tmp`. It signed in through `/login` with real sessions and CSRF tokens, the password read from an environment variable. It ran the real `delivery-actions.js` and jQuery in pages loaded from nginx | **12/12 checks.** Manager form post creates order 9; manager sees only the cancel form; the button is disabled while the request runs; an empty reason comes back as a 422 field error and the button is re-enabled; admin schedules and the panel reloads; the reloaded dispatch form sends `expected_status=scheduled`; dispatch; a second, stale tab gets the 409 message and its panel reloads to the current status; deliver leaves no forms; the timeline shows four steps. Both sessions signed out |
| Message fix, then tests and jsdom again | The stale message read "Reload it and try again. The order has been reloaded." on the page; reworded for both API and page. `tests/Feature/Deliveries` + `DeliveryApiTest`: 22 passed / 669 assertions. jsdom on order 10: 12/12. Orders 9 and 10 each have 4 history rows and 3 audit rows: the stale clicks wrote nothing |
| `make verify` #1 | **exit 2: 507 passed, 1 failed.** `IntegrationStatusScreenTest` (M04, untouched) failed in setup: `demo:seed` exited 1. `journalctl` shows the laptop suspended from 00:40:06 to 11:21:07 (38,461 s); that test's reported duration was 38,459.85 s. Rerun alone: 17 passed / 114 assertions. The likely cause is MySQL dropping the idle connection after its 8-hour `wait_timeout` during the suspend; not proven, since the log does not show the exception |
| `make verify` #2 | exit 0: Pint PASS on 284 files, Larastan `[OK] No errors`, simulator PHPStan `[OK] No errors`, **508 tests / 4937 assertions** (488.61 s, on battery while a VM and a browser were running), npm 0 vulnerabilities, Vite build OK |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS: repeated setup kept APP_KEY, credentials and database rows |
| `curl` smoke test through nginx | `/deliveries` as a guest: 302 to `/login`; `/api/v1/delivery-orders` without a token: 401; the built bundle contains the delivery handler |

Negative checks:
- **N1**, the order row lock removed. With the test's barrier switched to a generic "blocked for 1 s" wait, so the workers race instead of failing at the barrier: both admins got 200 (`[200, 200]`, not `[200, 409]`). The lost update is real without the lock.
- **N2**, the `expected_status` check removed: the API stale case failed, and so did 3 of the 4 T28 tests.
- **N3**, the transaction around a status change replaced with `call_user_func`: both forced-failure rollback tests failed.
- **N4**, the policy letting a manager ask for any move: the manager-escalation test failed (200, not 403).
- **N5**, the per-role token ability check removed from the API request: the abilities test failed (an admin token without `deliveries:status` scheduled an order).
- **N6**, the `ability:` middleware removed from the status route: the route-parity test failed.
- **N7**, the panel always sending `expected_status=pending`: the panel-reload test failed.
- **N8**, the "window starts in the future" check removed: 4 tests failed (API create and transition validation, web create, web field errors).
- **N9**, the JSON branch for web refusals removed: the stale-button test failed (302, not 409).
- **N10**, the tenant-scoped `{delivery}` binding removed: 2 tests failed (403, not 404, for another company's order).

### Not run or not verified

- GitHub CI for the M07 commit was not observed when this log was written. It was checked after the push: run 36838076726 passed (508 tests / 4937 assertions).
- A real browser: the jQuery module ran in jsdom, which has no layout, focus or visual rendering. Visual, keyboard and mobile checks are M09.
- The Postman desktop app: folder 03 ran with Newman 6.
- Carried over: the 503 `temporarily_unavailable` path, the simulator with a host PHP outside Docker, SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-10-01, M07):

- the order of checks under the lock (role, then stale, then transition, then details);
- the audit name and shape matching the seed, and no audit row on creation;
- the seed delegating to the service;
- the 366-day window horizon and strictly future windows;
- the inactive-company rule;
- any-of token abilities and `x-any-abilities`;
- `Location` on 201, and history in lists;
- governorate as free text;
- the AJAX design (server-rendered panel reload, form fallback, JSON refusals on web);
- the worker-helper extraction;
- the jsdom check;
- the self-contained Postman folder.

No product rule changed.

### Remaining work and blockers

None for M07.
- Carried over: the seed audits its quota-cut scenario as `fuel_card.limits_changed` while `FuelCardService` writes `card.limits_changed` (for M10). Delivery audit names now match between the seed and the service.
- Deliberately later: reports and CSV, including the delivery SLA (M08); visual polish and a real-browser pass (M09).

**Suggested commit message:** `feat: add audited diesel delivery workflow`.

**One concept to explain:** a state machine is only safe if every change is checked and written as one unit, against the state as it is right now.
- The allowed moves are a small table (`DeliveryStatus::nextStatuses()`).
- The client also sends the status it saw (`expected_status`). If someone else changed the order meanwhile, the server refuses (409 `stale_state`) instead of applying a decision made on old information.
- Checking is not enough on its own: two requests could both read "pending" and both pass. So the service locks the order row first (`SELECT … FOR UPDATE`). The second request waits, then reads the new status and is refused. N1 showed both admins winning without that lock.
- The status update, the history row and the audit row are written in one database transaction. Either the change happened and its history says so, or nothing happened (N3).

## Earlier session: M06

**Date / milestone:** 2026-09-30, M06 API completeness and POS simulator.

**Goal and actual state:**
- Goal:
  - the reference endpoints, the card patch, vehicle and driver GET/POST;
  - the standalone `tools/pos-simulator` with asserted scenarios and environment-only credentials;
  - OpenAPI and Postman validated against real responses (T24/T25), with delivery and report endpoints left as planned.
- Result: done, and all local gates pass on MySQL.
- M05 had nothing outstanding: the tree was clean, `main` matched `origin/main` at `a9bbcc5`, and GitHub CI run 36608901328 had passed on it.

### What was built

- **API:**
  - `Api\V1\StationController`, `ProductPriceController`, `FuelCardController`, `VehicleController` and `DriverController`, with seven new routes in `routes/api.php`.
  - Requests:
    - the new `PaginatedListRequest` base;
    - `ListStationsRequest`, `ListCompanyRecordsRequest` (for `ListVehiclesRequest` and `ListDriversRequest`), `ListPricesRequest` and `UpdateCardRequest`;
    - API `StoreVehicleRequest`/`StoreDriverRequest`, extending the web ones with unknown-field refusal and JSON-integer IDs. `ResolvesCompany::companyRules()` gained an optional strict mode.
  - Resources: `StationResource`, `VehicleResource`, `DriverResource`, `CardResource`.
  - The shared page builder `Concerns\RespondsWithPages`, which the transaction list now uses too (same output).
- **Service:** `FuelCardService::applyChanges()` applies the sent limits and status under one card lock and one transaction; a limit not sent keeps its locked value.
- **Command:** `demo:simulator-cards` (`app/Console/Commands/CreateSimulatorCards.php`).
- **Simulator:** `tools/pos-simulator/`, with its own Composer project and lock (Guzzle 7.15.5):
  - `bin/pos-simulator`;
  - `src/{Simulator, Scenarios, Config, ApiClient, ApiResponse, Reporter, Cents, ConfigurationError, UnexpectedOutcome}`;
  - `phpstan.neon` and a README.
- **Contract:**
  - `docs/api/openapi.json` 0.6.0: `x-status`, `x-milestone`, `x-abilities` and `x-roles` on every operation; descriptions for the M06 operations; `rate_source` in `Price`.
  - The official OpenAPI 3.1 schema, stored unmodified in `tests/Fixtures/openapi/`.
  - The dev dependency `opis/json-schema` 2.6.
- **Postman:**
  - replay and conflict reuse the saved `purchase_payload`;
  - the balance check is relative to the balance read at the start;
  - new calls: station field refused, station refused on vehicles and card changes, manager's `company_id` refused, revoked manager token;
  - the sandbox-global fix;
  - the environment template gained three blank variables.
- **Tests (40 new):**
  - `tests/Feature/Api/{OpenApiContractTest 7, ReferenceApiTest 9, CardPatchApiTest 8, FleetApiTest 8}`;
  - `tests/Feature/Console/SimulatorCardsCommandTest` (4);
  - the new Integration suite, `tests/Integration/PosSimulatorTest` (4).
  - Helpers: `tests/Concerns/{ChecksOpenApiContract, CallsApi, UsesCommittedDatabase}`. The last one was moved out of `PosConcurrencyTest`, whose behavior is unchanged.
- **Tooling:**
  - `make simulate`;
  - `make test` installs the simulator's locked dependencies first;
  - `make analyse` also runs PHPStan level 6 on the simulator;
  - `phpunit.xml` gained the Integration suite.
- **UI:** the station home page gained a "POS simulator" paragraph (UI-SPEC: "API simulator usage instructions").
- **Docs:**
  - `docs/05-API-CONTRACT.md` ("Implemented behavior (M06)", token abilities);
  - `docs/DECISIONS.md` (M06 record);
  - README (endpoints, simulator, Postman, commands, status);
  - `postman/README.md`, `tools/pos-simulator/README.md`;
  - `docs/02-ARCHITECTURE.md` (`make simulate`), `docs/SOURCES.md`, CHANGELOG.

### Checks: exact command and actual outcome

| Command | Outcome |
| --- | --- |
| `git status`, `git log`, `git rev-parse HEAD origin/main` at the start | Clean tree; both at `a9bbcc5` |
| OpenAPI tooling probe (scratch Composer project, removed afterwards) | The official schema was fetched (33,992 bytes, SHA-256 `da01ba28…314ed0`). First run: every Schema Object reported "openapi, info missing", because opis resolves `$dynamicRef: #meta` to the document root. After substituting `$ref: #/$defs/schema`: "unevaluated properties style, explode", because opis writes schema defaults into the data. With `allowDefaults` off: the document is valid, and a broken copy reports exactly the 3 injected errors |
| `php artisan route:list --path=api/v1` | 13 routes (7 new) |
| `phpstan` after the API code | 1 error: `rules()` unknown in the trait's context on the abstract list request. It is now declared abstract; then `[OK] No errors` |
| `OpenApiContractTest`, first run | **2 failed, 5 passed**, both test mistakes: (1) malformed JSON is refused before the token limiter runs, so it did not count toward the 5; (2) `withToken()` persists on the test client, so the "no token" call sent the manager's token (403, not 401). Second run: **1 failed**: the helper's token expires after a day, so after `travel(10)` a 401 was right; the test now issues a fresh token. Then 7 passed / 188 assertions. The M02/M05 responses matched the document without any change |
| `ReferenceApiTest` | 9 passed / 177 assertions on the first run |
| `CardPatchApiTest`, first run | **5 failed, 2 passed**, my wrong assumptions: the demo seed already writes 12 audit rows and cuts `FF-ATLAS-H02` to 200 L. Assertions now look only at audit rows written after the seed. The same inspection found the seed's `fuel_card.limits_changed` versus the service's `card.limits_changed` (recorded, not changed). Then 7 passed. I then added a forced-failure test, because the inactive-company test cannot tell whether the outer transaction exists (the limits step fails first there). Then 8 passed / 210 assertions |
| `FleetApiTest` | 8 passed / 235 assertions. Its request helper was renamed from `call()` before the first run: that name would have overridden Laravel's own test method |
| `php artisan test tests/Feature/Api` | 54 passed / 932 assertions |
| `composer update --working-dir=tools/pos-simulator`, `composer audit` | guzzlehttp/guzzle 7.15.5 plus 8 dependencies; "No security vulnerability advisories found". `--help` exit 0; missing credentials exit 2 |
| `php artisan test --testsuite=Integration`, first run | 4 passed / 48 assertions (11.71 s) |
| `SimulatorCardsCommandTest` | 4 passed / 29 assertions |
| **Live simulator through nginx**: `demo:simulator-cards --tag=M06`, then `make simulate` with `POS_EMAIL=operator.beirut@…` and the password from `.env` in an environment variable, never printed | Ledger 33 before. All 5 scenarios, 16 checks passed. New purchase id 34: 1600000.00 LBP / 17.88 USD at the fixture rate; identical and equivalent replays 200 with the same id; conflict 409; blocked 403; quota 403 (6.00 L on a 5.00 L card); "Token revoked." Ledger 34 after: +1 |
| **Newman run 1** (`npx --yes newman@6` in the node container; folders 01, 02, 05; fresh cards, tag `PM1`) | 26 requests; **47 assertions, 3 failed**. A real bug in the supplied collection: `const data` at the top of a test script collides with the Postman sandbox's legacy `data` global (SyntaxError), so the purchase was never saved and two later checks failed as a result. Ledger 34 → 35 |
| **Newman run 2** after renaming the variable (tag `PM2`) | 26 requests, **51 assertions, 0 failed**. Ledger 35 → 36 |
| `make verify` #1 | exit 0: Pint PASS on 268 files, Larastan OK, **481 tests / 4242 assertions** (136.84 s), npm 0 vulnerabilities, Vite build OK |
| Simulator style and analysis | Pint PASS on 10 files. PHPStan level 6 (`-c tools/pos-simulator/phpstan.neon`): 1 error, an unhandled `match` value; a default was added. `make analyse` now runs both analyses: `[OK] No errors` twice |
| Negative checks (file mutated, suite run, file restored and checksum-verified) | See the list below this table |
| `make verify` #2, the committed tree | exit 0: Pint PASS on 268 files (the simulator sources included), Larastan OK, simulator PHPStan OK, **481 tests / 4242 assertions** (136.21 s), npm 0 vulnerabilities, Vite build OK |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS: "Nothing to migrate", demo "nothing changed", APP_KEY, credentials and database rows kept |
| `usage:reconcile`; token count in dev after the runs | "All monthly usage counters match the ledger."; 0 tokens named `pos-simulator` or `postman-local` |

Negative checks:
- **N1**, an extra field in `TransactionResource`: 2 contract tests failed (`additionalProperties: false`).
- **N2**, `abilities:fleet:write` removed from `POST /vehicles`: the route-parity test and the read-only-token check failed.
- **N3**, a double counter increment in the shared service: it also broke the demo seed, so all 4 Integration tests failed in setup. Not a fair test of the simulator, so it was redone as N3b.
- **N3b**, an extra usage bump on the API path only: the simulator stopped with "Result: FAILED" and its test failed.
- **N4**, replays answered 201: the simulator failed.
- **N5**, my first attempt at removing the outer transaction was malformed: it returned the closure, so every test failed. Discarded.
- **N5b**, the outer transaction replaced with `call_user_func`: only the forced-failure rollback test failed, as predicted.
- **N6**, the rate checked before the price: the missing-price test failed (503 instead of 422).
- **N7**, the station `is_active` filter removed: 2 station tests failed.

### Not run or not verified

- GitHub CI for the M06 commit was not observed when this log was written. It was checked after the push: run 36734519806 passed (481 tests / 4242 assertions).
- The Postman desktop app itself: the collection ran with Newman 6, Postman's own command-line runner.
- Postman folders 03 and 04 and the delivery, report and export endpoints: M07 and M08. They remain `planned` in `openapi.json`.
- The 503 `temporarily_unavailable` path (carried over from M05): no deadlock or lock timeout was provoked.
- The simulator with a host PHP outside Docker: it ran only in the project's PHP 8.3 image.
- SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-09-30, M06):

- `rate_source` added to the price rows (the one contract addition);
- the `x-*` operation markers and route parity;
- how the official schema is validated;
- the price-list horizon and no future instants;
- stations active only for every role;
- the card patch's atomicity, keep-unsent, no API archiving and no confirmation step;
- the fleet API rules;
- the simulator design and exit codes;
- fresh fixtures instead of resets;
- the Integration suite;
- the Postman changes and the sandbox-global fix;
- the seed audit-name finding.

No product rule changed.

### Remaining work and blockers

None for M06.
- Found, not changed: the demo seed audits its quota-cut scenario as `fuel_card.limits_changed`, while `FuelCardService` writes `card.limits_changed`. For the M10 review.
- Deliberately later: deliveries (M07); reports and CSV (M08).

**Suggested commit message:** `feat: document the API and add a standalone POS simulator`.

**One concept to explain:** a contract is only trustworthy when a test compares it with reality. M06 checks the same promise at three levels:
1. **The document is well formed:** `openapi.json` is validated against the official OpenAPI 3.1 schema.
2. **The document matches the routes:** every documented operation exists with exactly the documented token ability and roles, and nothing undocumented is routed. Authorization boundaries become a checked list, not prose.
3. **The behavior matches the document:** real responses, errors included, are validated against the documented schemas. The simulator then drives the running application over HTTP and checks not only the status codes but also that the card was charged exactly once.

The mutation checks show each level catches a different kind of drift: an extra field, a missing ability, a double charge.

## Earlier session: M05

**Date / milestone:** 2026-09-29, M05 POS transactions and atomic quotas.

**Goal and actual state:**
- Goal: the complete ingestion algorithm (station-derived scope, canonical replay hashing, card and counter locks, snapshot amounts, bounded unique-race and deadlock handling), the balance, read-only reconciliation, and real overlapping MySQL workers, including a shared reference on different cards.
- Result: done, and all local gates pass on MySQL.
- M04 had nothing outstanding: the tree was clean and GitHub CI passed on `6922807`.

### What was built

- **Service:** `App\Services\FuelTransactionService`.
  - `ingest()` covers steps 3–9: early replay lookup; card `FOR UPDATE`; replay recheck under the lock; the window; card, company, assignment and product checks; `PriceResolver::quote()`; the Beirut quota month; counter row created if missing, then `FOR UPDATE`; the quota check; ledger insert with snapshots; one counter increment.
  - Around that, 3 whole-transaction attempts for deadlocks and lock timeouts, and resolution of a unique-index race by a fresh read (200 or 409). After that, `TemporarilyUnavailable` (503).
  - `recordHistorical()` does the same for seeded history, with no window or replay and in fixture mode.
- **Supporting classes:**
  - `App\Support\PosPurchase` (canonical input and hash) and `IngestResult`.
  - `App\Exceptions\PurchaseDeclined` (station_inactive, not_found, card_blocked, card_inactive, company_inactive, assignment_inactive, product_not_allowed, quota_exceeded, with details), `IdempotencyConflict` and `TemporarilyUnavailable`.
  - `App\Rules\OffsetTimestamp`: an explicit offset, whole seconds and a real date.
- **API:**
  - `Api\V1\TransactionController` (store, index, show) and `Api\V1\CardBalanceController`.
  - `Api\V1\StorePosTransactionRequest` (unknown fields refused; inactive station 403 in `authorize()`), `ListTransactionsRequest` and `CardBalanceRequest`.
  - `App\Http\Resources\TransactionResource`.
  - `FuelCardPolicy::viewBalance`, the `pos-writes` rate limiter, and four routes in `routes/api.php`.
- **Command:** `usage:reconcile` (`app/Console/Commands/ReconcileUsage.php`).
- **Refactor:** `LedgerFixtureBuilder::recordPurchase()` delegates to `recordHistorical()`; its own purchase checks, quota check and counter locking were removed.
- **Station home:** a working purchase example and the balance lookup replace "not available yet".
- **Tests (92 new):**
  - `tests/Feature/Pos/{PosIngestionTest 62, TransactionReadApiTest 18}`.
  - `tests/Concurrency/PosConcurrencyTest` (12) with the worker `tests/Concurrency/pos-worker.php`, in the new "Concurrency" suite in `phpunit.xml`.
  - `tests/Concerns/SubmitsPosRequests`.
  - `LedgerFixtureBuilderTest`: two refusal texts now expect the service's code or message.
  - `MigrationsTest`: the table listing is scoped to the current database.
- **Docs:** `docs/05-API-CONTRACT.md` (POS rate limit, "Implemented behavior (M05)"), `docs/DECISIONS.md` (M05 record), README ("POS purchases (API)", status, `make test`), CHANGELOG.

### Checks: exact command and actual outcome

| Command | Outcome |
| --- | --- |
| `git status`, `gh run list` at the start | Clean tree on `main`, up to date with `origin/main`. Run 36596673138 (M04, `6922807`) `success` |
| MySQL probe with the mysql CLI: one session holds `FOR UPDATE`, a second waits, a third runs `SHOW FULL PROCESSLIST` | The waiting session is visible to the same user, with its statement in `Info`. That is the basis of the test barrier |
| Seeder, builder, schema and append-only suites after the builder delegated to the service | 61 passed / 762 assertions, so seeded history is unchanged |
| `make analyse`; `route:list --path=api` | `[OK] No errors`; 6 API routes (4 new) |
| `PosIngestionTest` (first run) | **1 failed, 61 passed**, a test mistake: after `travel(4)->days()` the default payload's "one hour ago" had moved, so 409 was correct. The test now reuses the original payload. Then 62 passed / 398 assertions |
| `TransactionReadApiTest` (first run) | 18 passed / 110 assertions |
| Concurrency suite, run 1 | Fatal: my helper `result()` overrode PHPUnit's final `TestCase::result()`; renamed |
| Concurrency suite, run 2 | **11 failed**, each after the 20 s wait: my filter matched `Command = 'Query'`, but Laravel's server-side prepared statements are listed as `Execute` (the CLI probe had used plain queries). Then **11 passed / 42 assertions** (63.11 s) |
| `make verify` #1 | **exit 2 at analyse**: 4 Larastan errors in tests (`TestResponse` generics ×3, an unhandled `match` value). Fixed |
| `make verify` #2 | **exit 2 at lint**: 1 import-order issue; Pint fixed it |
| `make verify` #3 | **exit 2 at test: 240 failed, 200 passed.** Cause: in Laravel 12+, `Schema::getTableListing()` lists every schema the MySQL user can see, and `fleetfuel_test_concurrency` now existed. `MigrationsTest` rolled back, failed its "only `migrations` left" check, never re-migrated, and every later test found no tables. Fixed by scoping the listing to the current database (also in the concurrency cleanup) |
| `make verify` #4 | exit 0: Pint PASS on 230 files, Larastan OK, **440 tests / 3350 assertions** (382.35 s), 0 vulnerabilities, Vite build OK. The duration is the host: load average about 5 and 29 % iowait from other applications. The same M04 suite took 55.5 s instead of 11.8 s |
| New ordered T20 test (a purchase behind an in-flight purchase on the same card) | 1 passed |
| Negative checks (file mutated, suite run, restored and checksum-verified) | Changed payload treated as a replay → 6 failed. Exact liter limit refused → 2 failed. UTC month instead of Beirut → 2 failed. Window removed → 2 failed. Inactive station accepted → 1 failed. **Card lock removed** → the block-first test fails (a stale "active" status is used); the ordered T20 test still passes, because the counter lock alone prevents overspending. **Card and counter locks removed** → the ordered T20 test fails with "201 is identical to 403": a real lost update and double spend. Unique-violation handling removed → 2 failed (500 instead of 409). In-lock recheck removed → still passes: the unique index decides anyway (defense in depth; reported, not a gap) |
| `make verify` #5, the committed tree | exit 0: Pint PASS on 230 files, Larastan OK, **441 tests / 3355 assertions** (338.98 s on the loaded host), 0 vulnerabilities, Vite build OK |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS: "Nothing to migrate", demo "nothing changed", env files and APP_KEY kept |
| Live walkthrough through nginx (curl; token requested with the password from `.env`, never printed) | Operator token issued. New purchase on `FF-ATLAS-H01` → 201 (id 33, 1600000.00 LBP / 17.88 USD, fixture rate, month 2026-09-01, `Location`). The same purchase with a UTC time, `"20"` and a lowercase reference → 200 `Idempotency-Replayed: true`, same id. 25 L under the same reference → 409 `idempotency_conflict`. `FF-ATLAS-BLOCKED` → 403 `card_blocked`. Payload `station_id` → 422 "This field is not allowed." Balance → 190.00 L used, 210.00 remaining. Detail → 200. Revoke → 204, then the POST → 401. `usage:reconcile` → "All monthly usage counters match the ledger." (exit 0) |

### Not run or not verified

- GitHub CI for the M05 commit (reported in the session reply after the push).
- The POS simulator, the Postman collection and OpenAPI validation against real responses (M06, T24/T25).
- The 503 `temporarily_unavailable` path: no deadlock or lock timeout was provoked, so the retry and the exhausted-attempts answer are untested. Covered: unique-index races resolve to 200/409 (Concurrency suite), and a failure that is not a concurrency error propagates and rolls everything back (T23 forced failure → 500).
- The API card patch (M06); edits here go through `FuelCardService`, which the concurrency tests cover.
- SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-09-29, M05):

- one service for API and history;
- lock order, and the counter row created without a gap lock;
- the plain-read recheck after the card lock (REPEATABLE READ);
- the unique index as the final judge, with bounded retries and 503;
- the inactive station refused before validation;
- no clock-skew allowance;
- counter overflow returns 422;
- operators may look up any card's balance;
- the list's exact `meta` keys;
- the per-station rate limit;
- the concurrency harness;
- the Laravel 12+ schema-listing fix.

No product rule changed.

### Remaining work and blockers

None for M05. Deliberately later: the simulator, Postman, the other API endpoints and OpenAPI parity (M06); the transaction screens with filters (M08/M09).

**Suggested commit message:** `feat: ingest idempotent POS transactions with atomic quotas`.

**One concept to explain:** the lost-update race, and why locks and the unique index solve different problems.
- **Lost update:** two stations read "80 L used" at the same moment, each adds 15 L, and each writes 95. The card is at 110 L while the counter says 95. Locking the card row first makes the second purchase wait and then read 95, so it is refused. The mutation check reproduced exactly this double spend with the locks removed.
- **Duplicate retries:** a POS that times out and resends the same purchase must not spend twice. The same reference can even arrive for two different cards, which no card lock serializes. The unique `(station_id, external_ref)` index lets exactly one row exist. The loser rereads the winner and answers 200 (identical) or 409 (different).
- So the locks protect the quota arithmetic, and the index protects "one purchase per POS reference".

## Earlier session: M04

**Date / milestone:** 2026-09-29, M04 Prices and external FX.

**Goal and actual state:**
- Goal: decimal pricing, effective price resolution, `ExchangeRateProvider`, validated observation storage, fixture/live modes, scheduled sync, bounded fallback and expiring audited manual overrides, with no FX fetch in transactions or page requests, and attribution where rates are used.
- Result: done, and all local gates pass on MySQL.
- M03 had nothing outstanding: GitHub CI passed on the pushed M00–M03 commit (see "Current state").

### What was built

- **Pricing:**
  - `App\Services\PriceResolver`: `findPrice`/`priceAt`, `findRate`/`rateAt` (mode-aware), and `quote()`, which re-validates liters and checks column bounds.
  - `App\Support\PriceQuote`.
  - `App\Exceptions\PriceUnavailable` (422) and `RateUnavailable` (503).
  - `Decimal::fits()` and `FuelAmounts::indicativeUnitPriceUsd()`.
  - `App\Services\ProductPriceService::publish()`: now or later, audited `product_price.published`.
- **Exchange rates:**
  - `App\Contracts\ExchangeRateProvider`.
  - `App\Services\ExchangeRates\HttpExchangeRateProvider`: timeouts 3 s / 10 s, at most 3 attempts, retries only on no response or 5xx with `Sleep` pauses of 500/1000 ms, 429 retry advice, strict response checks, float-free eight-decimal normalization.
  - `FixtureExchangeRateProvider`: no HTTP.
  - `App\Enums\RateMode` and `ObservationOutcome`; `RateSource::label()`.
  - `App\Support\RateObservation` and `RateSyncResult`; `App\Exceptions\ExchangeRateFetchFailed`, which carries safe codes.
  - `App\Services\ExchangeRateService`: `recordObservation()` stores once and logs conflicts; `createOverride()` is audited as `exchange_rate.override_created`.
  - `App\Services\ExchangeRateSync`: cache lock, next-update hint, per-mode `integration_sync_states`.
  - `ExchangeRate` scopes `usdLbp()`/`eligibleAt()` and `isEligibleAt()`; `IntegrationSyncState::errorDescription()`.
- **Commands and wiring:**
  - `rates:sync [--force]` (`app/Console/Commands/SyncExchangeRates.php`).
  - A daily 01:00 UTC schedule with `withoutOverlapping()` in `routes/console.php`.
  - The provider is bound by mode in `AppServiceProvider`.
  - New constants in `config/fleetfuel.php` (fixture rate, HTTP limits, clock skew, sync time).
  - The migration `2026_09_29_120001_add_next_attempt_at_to_integration_sync_states_table`.
- **HTTP layer:**
  - `Web\ProductPriceController` (timeline for all roles, publish for admins) and `Web\ExchangeRateController` (admin status and overrides).
  - `StoreProductPriceRequest` and `StoreExchangeRateOverrideRequest`, plus the `ParsesBusinessTime` concern (Beirut `datetime-local` to UTC).
  - Four routes in `routes/web.php`.
- **Views:**
  - `products/prices`, `integrations/exchange-rates`, `integrations/rate-status`, `partials/rate-attribution` and `partials/indicative-rate-note`.
  - `products/index` shows the current price, indicative USD and a Prices link.
  - `transactions/show` shows the source label, plus attribution for provider snapshots.
  - The layout got an admin-only "Exchange rates" link.
- **Refactor:**
  - `LedgerFixtureBuilder` prices purchases through `PriceResolver` with `RateMode::Fixture`; its private `priceAt`/`rateAt` were removed.
  - `DemoSeeder` reads the fixture rate from config.
- **Tests (95 new):**
  - `tests/Feature/Pricing/{PriceResolverTest 16, ProductPriceScreensTest 18}`;
  - `tests/Feature/ExchangeRates/{HttpExchangeRateProviderTest 22, RatesSyncCommandTest 11, IntegrationStatusScreenTest 17}`;
  - `tests/Unit/Support/DecimalTest` (10), and 1 new `FuelAmountsTest` case.
  - `tests/TestCase.php` now calls `Http::preventStrayRequests()`.
- **Docs:** README (status, "Prices and exchange rates"), `docs/DECISIONS.md` (M04 record), `docs/03-DATA-MODEL.md` (`next_attempt_at`), CHANGELOG, `.env.example` comment.

### Checks: exact command and actual outcome

| Command | Outcome |
| --- | --- |
| `gh run list` / job steps and log for `86287bb` | Run 36551839506 `success`, with every step green; the log shows `Tests: 254 passed (2375 assertions)`, Pint PASS on 183 files, `[OK] No errors` |
| `make analyse` after the core classes and screens | `[OK] No errors` |
| Seeder, builder, amounts and schema suites after the builder refactor (first run) | **45 failed, 23 passed**: `BigDecimal::stripTrailingZeros()` does not exist in brick/math 1.0 (the method is `strippedOfTrailingZeros()`). Fixed: 68 passed / 771 assertions, so seeded history is unchanged |
| New suites (first runs) | Resolver + decimal + amounts: 34 passed. Provider: 22 passed / 63 assertions. `rates:sync`: 11 passed / 71. Integration page: 17 passed / 114. Price screens: **2 failed, 16 passed**, both test mistakes: (1) the MySQL JSON audit column does not keep key order, so the test now uses `assertEquals`; (2) the view wraps "rate of" and the value onto separate lines, so the test now uses `assertSeeInOrder`. Then 18 passed / 127 |
| `make verify` #1 | **exit 2 at analyse**: 3 Larastan errors in tests (a nullsafe call on an expression PHPStan had narrowed; `Log::shouldHaveReceived()` is unknown on the facade). Fixed with a loop variable and the spy object |
| `make verify` #2 | exit 0: Pint PASS on 211 files, Larastan OK, **349 tests / 2800 assertions** (78.88 s), `npm ci` 0 vulnerabilities, Vite build OK |
| `make verify` #3, after the last docblock and doc edits (the committed tree) | exit 0: Pint PASS on 211 files, Larastan OK, **349 tests / 2800 assertions** (84.14 s), 0 vulnerabilities, Vite build OK |
| Negative checks (each file mutated, the matching suite run, then restored and checksum-verified) | Automated source before manual → 2 failed. Rate still eligible at `expires_at` → 2 failed. Live mode reading fixtures → 2 failed. A fourth attempt allowed → 2 failed. 429 not special-cased → 3 failed. Fetch time stored as observation time → 4 failed. Next-update hint ignored → 3 failed. Past price start accepted → 1 failed. Retroactive override accepted → 1 failed. Overflow check removed → 1 failed |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS. It applied only `2026_09_29_120001_add_next_attempt_at…` (32.75 ms); env files and APP_KEY kept; demo "nothing changed" |
| `docker compose exec -T app php artisan rates:sync` twice (fixture mode, dev) | 1st: "Stored the Fixture (synthetic) observation of 89500.00000000 LBP per USD at 2026-09-29T00:00:00Z." 2nd: "Nothing fetched: the next fixture update is not due until 2026-09-30T00:00:00Z." `schedule:list`: `0 1 * * * php artisan rates:sync` |
| **LIVE, one manual request:** `docker compose exec -T -e EXCHANGE_RATE_MODE=live app php artisan rates:sync` | exit 0: "Stored the Provider observation of 89500.00000000 LBP per USD at 2026-09-29T00:02:31Z." Row: fetched 16:11:02Z, expires 2026-10-02T00:02:31Z; state `exchange_rates.live` has no error and next attempt 2026-09-30T00:25:01Z. The provider's value happens to equal the fictional fixture constant. The resolver in fixture mode still returns the fixture row |
| Walkthrough through nginx (curl, real sessions and CSRF; password read from `.env`, never printed) | Admin: `/products` 200 with "LBP per liter", indicative `0.8939` and the fixture-rate note; DIESEL timeline 200 with the publish form; `/integrations/exchange-rates` 200 (fixture mode, rate in effect, expired overrides). A retroactive price POST (no `-L`) → 302 back with "A new price cannot start in the past."; `product_prices` count 6 before and after. manager.atlas: timeline 200; integration page 403; price POST 403 |

### Not run or not verified

- The ledger parts of T10/T12 (an accepted purchase snapshotting price and rate, a replay after a price change) need POS ingestion, which is M05. M04 checks that publishing a price leaves seeded purchase snapshots unchanged.
- Real provider failures: timeouts, 5xx and 429 were only faked. The one live request succeeded.
- The scheduler firing at 01:00 UTC was not observed; the registration is tested and `schedule:list` shows it.
- A visual browser check of the new pages (password kept out of the transcript); M09 does visual polish.
- The API price preview `GET /api/v1/products/prices` (M06) will reuse `PriceResolver`.
- SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-09-29, M04):

- rate modes;
- float-free provider normalization;
- timestamp sanity rules;
- retry and 429 policy;
- per-mode sync state and `next_attempt_at` (the only schema change);
- overrides as start plus 1–72 hours;
- price start rules;
- indicative USD;
- where attribution appears;
- stray-request blocking in tests.

No product rule changed.

### Remaining work and blockers

None for M04. Deliberately later:

- POS ingestion using `PriceResolver` (M05);
- the price-preview API (M06);
- report pages showing stored amounts (M08);
- visual polish (M09).

**Suggested commit message:** `feat: add historical pricing and resilient exchange-rate sync`. The user asked for this commit and push in this session.

**One concept to explain:** why reports use stored amounts, and why the latest rate cannot create historical rates.
- A purchase is priced once, at its event time, with the price and rate in effect then. Those values are copied onto the transaction row.
- The provider's open endpoint only says what the rate is now. Using it for an old purchase would silently reprice history.
- So observations are stored at the provider's own timestamp and expire after 72 hours. An override can never start in the past. When nothing valid covers an instant, the answer is `rate_unavailable`, not a guess.

## Earlier session: M03

**Date / milestone:** 2026-09-29, M03 Fleet and reference-data UI.

**Goal and actual state:**
- Goal: paginated screens with validation for companies, stations, products, vehicles, drivers and cards; selectors scoped as strictly as detail routes; card quota/status changes under the card lock and audited; no ownership or used-card assignment changes; archive instead of delete.
- Result: done, and all local gates pass on MySQL.
- M02 had nothing outstanding. One M02 test route was rewired: see "Checks", `make verify` #2.

### What was built

- **Services**, shared with the M06 API:
  - `App\Services\FuelCardService`: create, updateAssignment, updateLimits, changeStatus, balance. Each change locks the card row and writes an audit row.
  - `FleetService`: vehicles and drivers, with activation audits.
  - `ReferenceDataService`: companies, stations, products, with status audits.
  - `App\Exceptions\BusinessRuleViolation`: `company_inactive`, `assignment_locked`, `invalid_transition`, and web-only `confirmation_required`.
  - `App\Support\CardBalance`: limits, used, remaining floored at 0, over quota.
  - `App\Support\Decimal`, `App\Support\Like` (literal LIKE search) and `App\Rules\DecimalString`.
  - `Vehicle::normalizePlate()` and `Driver::normalizeLicense()`.
- **HTTP layer:**
  - Controllers: `Web\{Company,Station,Product,Vehicle,Driver,FuelCard}Controller` plus the `FiltersLists` concern.
  - 13 Form Requests: `app/Http/Requests/{Fleet,Reference}/*`, `SetActiveRequest`, and the `ResolvesCompany` trait (an admin picks an active company; a manager may not send `company_id`).
  - `routes/web.php`: resource routes without `destroy`, plus PATCH routes for status, active, limits and card status.
- **Wiring:**
  - `AppServiceProvider::bindTenantScopedModels()`: tenant-scoped `{company|station|product|vehicle|driver|card}` binding, and Bootstrap 5 pagination.
  - `bootstrap/app.php`:
    - the `active`, `role:` and Sanctum ability middleware moved ahead of route binding;
    - web rendering of `BusinessRuleViolation`.
- **Views:**
  - The `x-form.input` and `x-form.select` components, with accessible error and help text.
  - Partials: status badges, the active toggle and list filters.
  - `companies/*`, `stations/*`, `products/*`, `vehicles/*`, `drivers/*`, `cards/{index,choose-company,form,show}`.
  - The layout got role-aware navigation and a business-rule alert.
  - `resources/js/app.js` got `data-confirm` for deactivate and archive.
- **Tests (60 new):**
  - `tests/Feature/Fleet/FuelCardScreensTest` (19), `VehicleAndDriverScreensTest` (12), `tests/Feature/Reference/ReferenceDataScreensTest` (8);
  - unit: `DecimalStringTest` (16), `CardBalanceTest` (4), `LikeTest` (1);
  - `tests/Concerns/SignsInDemoAccounts`.
  - `tests/Feature/Api/AbilitiesAndPoliciesTest` was updated: see "Checks".
- **Docs:** README (status, "Fleet screens"), `docs/DECISIONS.md` (M03 record), CHANGELOG.

### Checks: exact command and actual outcome

| Command | Outcome |
| --- | --- |
| `make analyse` before the tests were written; `route:list` | `[OK] No errors`; 36 new routes, none of them DELETE |
| New M03 suites (first run) | **2 failed, 58 passed.** (1) The quota-warning page check: the harness artifact described in DECISIONS, where `assertSessionHasErrors()` between requests empties the JSON-serialized error bag. I diagnosed it by dumping `$errors` inside the view; the test now follows the redirect in one chain. (2) A products assertion matched a leftover flash message; it now checks the listed records |
| Negative checks (each file restored and checksum-verified) | Used-card lock removed → used-card test fails. `{card}` binding unscoped → cross-company test fails. Role checks after binding → operator test fails (404 instead of 403). `lockForUpdate` removed → lock test fails. Ability middleware after binding → read-only token test fails (404 instead of 403) |
| `make lint` | 1 import-order issue; Pint fixed it |
| `make verify` #1 | **exit 2 at analyse**: the fake `$fail` closure in `DecimalStringTest` had the wrong signature. Fixed to match the real `Closure(string, ?string): PotentiallyTranslatedString` contract |
| `make verify` #2 | **4 failed (M02 `AbilitiesAndPoliciesTest`).** Its test route declared `string $card`, so the new `{card}` binding passed a model, PHP converted it to JSON text, `(int)` gave 0, and the result was a 404. That also showed that token abilities ran after binding. Fix: ability middleware is now in the priority list ahead of binding, and the test route uses the M06 pattern (`role:` + `abilities:` + scoped `{card}` + policy), plus a new assertion (read-only token on another company's card → 403) |
| `make verify` #3 | exit 0: Pint PASS on 183 files, Larastan OK, **254 tests / 2375 assertions** (61.91 s), `npm ci` 0 vulnerabilities, Vite build OK |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS: env files and APP_KEY kept, "Nothing to migrate", demo "nothing changed" |
| Live walkthrough through nginx (curl, real sessions and CSRF; password read from `.env`, never printed) | As admin: company created, then vehicle `WLK 001`, driver `WLK-DL-1`. The card form for that company offered `WLK 001` and not `ATL-101`. The card was issued as `FF-…-9SVR` (302 → `/cards/11`), then blocked; its page shows "Blocked"; audit rows `card.created`, `card.status_changed`. manager.atlas: that card 404, that vehicle's edit page 404, a block attempt 404 |
| Live quota-cut warning on `FF-ATLAS-H01` (manager, limit 1 L, no confirmation) | Redirected to the card page with the warning, the tick box and the typed value kept; the limit stayed 400.00; the warning disappears on reload. My first attempt used `curl -X PATCH -L`, which repeats the PATCH on the redirect target, so it sent an empty assignment update to `PUT/PATCH /cards/5`. The used-card lock refused it: `FF-ATLAS-H01` has the same vehicle, driver, product and limits, and no new audit rows |

### Not run or not verified

- GitHub Actions (no remote).
- Visual browser check of the M03 screens. As in M02, I kept the demo password out of the session transcript; the pages were checked in feature tests and through nginx with curl. Visual polish and keyboard/mobile checks are M09.
- Concurrency: card changes take `SELECT … FOR UPDATE`, and a test asserts that query. Overlapping-connection tests of edits against POS ingestion are T22 in M05.
- The API card PATCH and the vehicle/driver endpoints (M06) will reuse the services; they have no routes yet.
- SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-09-29, M03):

- Tenant-scoped route binding, with role and ability checks ahead of it.
- Services shared with the API.
- Server-generated card numbers.
- A web confirmation step for quota cuts below usage, while the API accepts cuts directly.
- An inactive company's fleet is read-only, except blocking or archiving and deactivation.
- A vehicle's fuel type is fixed after creation.
- Products can only be renamed or deactivated.
- Card audit history is shown on the card page to admins.

No product rule changed.

### Remaining work and blockers

None for M03. Deliberately later:

- the full audit screen with filters;
- the price timeline (M04);
- the API endpoints (M06);
- visual polish (M09).

**Suggested commit message:** `feat: add fleet management screens and audited card controls`. M00–M02 are also uncommitted; suggested order: `chore: scaffold Laravel and reproducible local tooling`, `feat: model fleet data and deterministic demo scenarios`, `feat: add role-based authentication and tenant isolation`, then this one. Not committed; commit only when asked.

**One concept to explain:** thin controllers and service reuse.
- A controller here does three things only: accept an already tenant-scoped record from route binding, let a Form Request validate and authorize the input, and call one service method.
- The rules live in the services: same company, not while used, not when archived, not for an inactive company, lock the card row, write the audit row. They are identical whether a manager clicks a button or, in M06, the POS or Postman calls the API.
- A rule enforced in one place cannot be forgotten on the second entry point. For example, `FuelCardService::updateLimits()` takes the same card lock that POS ingestion will take in M05.

## Earlier session: M02

**Date / milestone:** 2026-09-29, M02 Authentication, roles and isolation.

**Goal and actual state:**
- Goal: Fortify session auth with Bootstrap views, role policies, explicit tenant scoping, disabled-user handling, Sanctum issue/revoke with fixed abilities and expiry, rate limits, API error envelopes, request IDs and a provisioning path, with no public registration.
- Result: done, and all local gates pass on MySQL.
- M01 prerequisites re-checked first: PROGRESS matched the files, the containers were up, and M01's 100 tests still pass inside the 194.

### What was built

- **Sign-in (web):**
  - `config/fortify.php` has all features off, because Fortify's package defaults enable registration, password reset, 2FA and passkeys.
  - `routes/web.php` registers only `GET/POST /login` and `POST /logout`, on Fortify's controllers.
  - `resources/views/auth/login.blade.php`.
  - `App\Http\Responses\LoginResponse` sends each role to its landing page.
  - `App\Services\CredentialVerifier` holds the shared check.
  - `App\Listeners\LogAuthenticationFailures` writes the redacted security log.
- **Access control:**
  - `app/Policies/*`: 11 policies plus the `DeniesInactiveUsers` trait.
  - `visibleTo` scopes, through `App\Models\Concerns\BelongsToCompany`, on Vehicle, Driver, FuelCard and DeliveryOrder, plus custom scopes on FuelTransaction, Company, Station, Product and AuditLog.
  - Middleware `EnsureUserHasRole` (`role:`) and `EnsureUserIsActive` (`active`).
  - `User` helpers (`isAdmin`, `managesCompany`, `operatesStation`, …) and `HasApiTokens`.
  - `UserRole::tokenAbilities()`, `homeRoute()` and `label()`.
- **Pages:**
  - `Web\DashboardController`: scoped month totals, counts and the latest 10 purchases.
  - `Web\StationHomeController`: the latest 25 purchases at the operator's station plus token instructions.
  - `Web\TransactionController::show`: scope first, then policy.
  - Views: `dashboard`, `station/home`, `transactions/show` and `transactions/_table`; `layouts/app` (role navigation, sign-out form, status flash) and `home` were updated.
  - `App\Support\Display` (decimal grouping without floats, Beirut time) and `Redact` (masked email and card number).
  - `FuelTransaction::exceedsTankCapacity()`.
- **API:**
  - `Api\V1\TokenController` with `IssueTokenRequest` and the `RejectsUnknownFields` concern.
  - `routes/api.php`: `/api/v1/auth/token`.
  - `config/sanctum.php`: `guard => []`, `expiration => 1440`, `routes => false`.
  - The Sanctum active-account callback and named rate limiters `token-issue`/`api` in `AppServiceProvider`.
  - `App\Exceptions\ApiErrorRenderer` and `ApiException`.
  - Middleware `RejectMalformedJson` and `AssignRequestId`, plus `App\Support\RequestId`.
  - `bootstrap/app.php` wiring.
- **Provisioning:** `users:create`, `users:deactivate` and `users:activate` (`app/Console/Commands/*User.php`), with `App\Services\UserAccountService` and the new `App\Services\AuditService`.
- **Tests:**
  - Web: `tests/Feature/Auth/{WebLogin 12, CsrfProtection 7, TenantIsolation 9, AuthorizationMatrix 10}`.
  - API: `tests/Feature/Api/{TokenAuthentication 11, AbilitiesAndPolicies 6, ErrorResponses 6}`.
  - Console: `tests/Feature/Console/UserAccountCommands 10`.
  - Unit: `tests/Unit/{Enums/UserRole 3, Support/Display 8, Support/Redact 2}`.
  - `PublicPagesTest` grew from 2 to 12: every other Fortify route and `/sanctum/csrf-cookie` must be 404.
  - `tests/TestCase.php` now always uses `withoutVite()` and gains `startNewRequestCycle()`, which forgets cached auth guards between simulated requests.
- **Docs:** README (status, sign-in, "Accounts and API tokens"), `docs/05-API-CONTRACT.md` (implemented limits, request IDs, 405, unknown fields), `docs/DECISIONS.md` (M02 record), CHANGELOG.

### Checks: exact command and actual outcome

| Command | Outcome |
| --- | --- |
| `php artisan route:list` in a fresh container | Only `login`, `login.store` and `logout` come from Fortify; also `/dashboard`, `/station`, `/transactions/{id}` and `api/v1/auth/token` (POST, DELETE). `/sanctum/csrf-cookie` was listed until `sanctum.routes => false` removed it. `event:list` shows both listener methods |
| `make analyse` (before and after the tests were written) | `[OK] No errors` both times, tests included |
| `make test` (first run) | **1 failed, 193 passed.** The audit `new_values` comparison failed on key order only: MySQL's `JSON` type stores object keys in its own order (shorter keys first). The test now compares with sorted keys; the values were already correct |
| Negative checks: each protection was broken on purpose, the matching tests were run, then the file was restored (checksums verified) | Sanctum `guard => ['web']`: 1 test failed (session cookie reached the API). `visibleTo` removed from purchase detail: 2 failed (403 instead of 404, which would reveal that the record exists). Sanctum `is_active` check removed: 2 failed. `active` middleware removed: 1 failed |
| `make lint` | 1 file flagged (fully qualified class name in a docblock); Pint fixed it by adding an import only |
| `make verify` | exit 0: Pint PASS on 147 files, Larastan OK, **194 tests / 1933 assertions** (118.98 s), `npm ci` 0 vulnerabilities, Vite build OK |
| `sh docker/bin/check-setup-preserves-state.sh` | PASS: env files and APP_KEY kept, "Nothing to migrate", demo "nothing changed", readiness OK |
| Live API through nginx (`curl`; the password was read from `.env` and never printed, and the token was masked) | Operator token issued 201 (24 h expiry, correct user summary), revoked 204, reused 401 `unauthenticated` with `X-Request-Id` equal to `error.request_id`. Wrong password 401 `invalid_credentials`. `abilities` in the body 422. Malformed JSON 400. 0 test tokens left |
| Live web through nginx, with real sessions and **real CSRF enforcement** (the middleware is on outside tests) | `POST /login` without a token: 419. Manager and operator sign-ins redirect to `/dashboard` and `/station`. The Atlas dashboard shows no "Cedar Catering". Operator `/dashboard` 403; manager `/station` 403. Logout without a token 419, with a token 302 → `/login`, then `/station` → `/login` |
| Live purchase-detail matrix (IDs from the dev DB: #1 Atlas at Harbor, #4 Cedar, #3 Atlas at North) | manager.atlas 200/404/200 · manager.cedar 404/200/404 · operator.beirut (Harbor) 200/404/404 · admin 200/200/200 |
| Chrome (DevTools MCP), isolated context | A guest opening `/dashboard` lands on the sign-in page. An unknown account shows "These credentials do not match our records." linked to the email field; the password field is not refilled; no console errors. `storage/logs/laravel.log` got `Sign-in failed. {"email":"n***@fleetfuel.test",…} {"request_id":…}` with no password and no full email |

### Not run or not verified

- GitHub Actions (no remote).
- Signed-in pages were not viewed in a browser, to keep the demo password out of the session transcript. They were checked through nginx with curl and rendered in feature tests; visual polish is M09.
- The login throttle and token rate limits were tested with time travel on the array cache, not against the dev database cache.
- T05 on write routes: operators changing quotas and managers writing prices are denied by the policies (tested), but those screens and endpoints do not exist until M03/M04.
- T06 on real business endpoints: ability and role 403s were tested on a test-only route shaped like M06's card PATCH; M05/M06 add their own endpoint tests.
- SQL Server (S01).

### Decisions and deviations

All are in `docs/DECISIONS.md` (2026-09-29, M02):

- Fortify routes are registered explicitly, not by Fortify.
- Web and API share one credential check.
- The web limiter counts failures only; the token limiter counts every request.
- Checks run in the order 403 → 404 → 403.
- The API is token-only.
- Request IDs are server-generated.
- Provisioning goes through CLI commands, with audit rows.
- Read-only pages were added to test isolation on real routes.

No product rule changed.

### Remaining work and blockers

None for M02. Known gaps, deliberately out of scope:

- no password-reset flow (no email in the MVP);
- the skeleton's signed `storage/{path}` routes are left as they are (unused, signature-protected);
- the session AJAX error format is decided in M08/M09.

**Suggested commit message:** `feat: add role-based authentication and tenant isolation`. M00 and M01 are also uncommitted; suggested order: `chore: scaffold Laravel and reproducible local tooling`, then `feat: model fleet data and deterministic demo scenarios`, then this one. Not committed; commit only when asked.

**One concept to explain:** authentication versus authorization, and CSRF versus bearer tokens.
- *Authentication* answers "who are you?": the password check at sign-in, or a valid unexpired token. It fails with 401 or a return to the sign-in page.
- *Authorization* answers "may you do this, to this record?", in three layers:
  - the role policy or route boundary: 403;
  - the tenant-scoped lookup: 404, so a guessed ID reveals nothing;
  - the record policy: 403.

  A token ability only narrows a token; it never grants what the role and ownership checks deny. The test with a `*` token proves it.
- *CSRF* protects cookie sessions. A browser attaches cookies to any site's form post, so each state change must also carry the per-session token, or a same-origin `Sec-Fetch-Site`.
- *Bearer tokens* are sent deliberately by the client in a header, so they carry no CSRF risk. That is also why the API refuses session cookies.

## Earlier session: M01

**Date / milestone:** 2026-09-28, M01 Database, models and fixtures, plus the unfinished M00 checks.

**Goal and actual state:** Implement the schema, models, enums, relationships, constraints, factories and a guarded, repeatable demo seed with consistent ledger counters. Done. All local gates pass on MySQL.

### M00 follow-ups resolved first

| Open item | Result |
| --- | --- |
| `make logs` hides MySQL's generated root password | Verified on a disposable `mysql:8.4` container: its log had 1 `GENERATED ROOT PASSWORD` line and 0 after the Makefile's filter |
| Browser/visual check of the home page | Loaded `http://localhost:8080/` in Chrome through the DevTools MCP; the Bootstrap CSS from the Vite build rendered (dark navbar, card, footer) |
| GitHub Actions run | Still not possible: no remote. The workflow stays linted but unrun |
| New M00 bug found during M01 | After `make setup` recreated the `app` container, nginx kept the old container IP and answered **502**, while `make setup` still reported success because container health lags about 60 s. Fixed: nginx resolves `app` via Docker DNS per request, and `make setup`/`make up` end with a real `/health` request. Proven by forcing `app` from 172.24.0.3 to 172.24.0.6: `/health` returned 200 with nginx untouched, and the readiness request fails (502, exit 1) when `app` is stopped |

### What was built

- **Schema** (15 migrations, `database/migrations/2026_09_28_1700{01..15}_*`):
  - Tables: companies, stations, users (role/company/station/is_active), products, product_prices, exchange_rates, integration_sync_states, vehicles, drivers, fuel_cards, card_monthly_usage, fuel_transactions (all snapshot columns), delivery_orders, delivery_status_history, audit_logs.
  - Every foreign key is restrict-on-delete.
  - Composite `(company_id, …)` foreign keys stop cross-company assignments.
  - Named CHECK constraints cover amounts, enum strings, user role scope, delivery state details and manual-rate reasons.
- **Code:**
  - `app/Enums/*` (7 enums; `DeliveryStatus` holds the transition map).
  - `app/Models/*` (14 domain models plus `User`), with the `app/Models/Concerns/AppendOnly.php` trait.
  - `app/Support/{FuelAmounts,BusinessMonth,PosRequestHash}.php` and `app/Services/UsageReconciliation.php`.
  - `app/Console/Commands/SeedDemoData.php` (`demo:seed`).
  - An AppServiceProvider morph map, immutable dates and strict models.
- **Fixtures:**
  - `database/factories/*` (9 new plus `UserFactory` role states).
  - `database/seeders/{DatabaseSeeder,ProductSeeder,DemoSeeder}.php`.
  - `database/seeders/Support/LedgerFixtureBuilder.php`.
- **Tests:**
  - `tests/Concerns/BuildsLedgerFixtures.php`.
  - Unit: FuelAmounts 7, BusinessMonth 6, PosRequestHash 8, DeliveryStatus 2.
  - Feature: Migrations 1, SchemaConstraints 32, AppendOnlyModels 1, ErDiagram 1, DemoSeeder 16, LedgerFixtureBuilder 12.
- **Setup and docs:**
  - `docker/bin/prepare-env.sh` generates `DEMO_PASSWORD` for a new `.env`.
  - `docker/nginx/default.conf` gets the dynamic upstream.
  - `Makefile` gets the readiness check and a demo-login hint.
  - `phpstan.neon` gets `parseModelCastsMethod`.
  - Also updated: `.env.example`, README (demo data section), `docs/03-DATA-MODEL.md` (complete ER diagram and composite-key note), `docs/DECISIONS.md` (M01 entry), CHANGELOG.
- **Local only (untracked):** this machine's `.env` had an empty `DEMO_PASSWORD` (created before this change). I filled it once with a random 16-character value; nothing else in `.env` changed.

### Demo data (fixed as-of 2026-09-28T09:00:00Z in tests)

| Table | Rows | Notes |
| --- | --- | --- |
| companies / stations / users / products | 2 / 3 / 5 / 3 | Accounts from docs/06; South Demo Station is inactive |
| product_prices | 6 | Previous-month price and current price per product (current = docs/examples/fixtures.json) |
| exchange_rates | 63 | 61 daily fixture observations (valid 72 h each), 1 expired provider value, 1 expired audited manual override |
| vehicles / drivers / fuel_cards | 8 / 8 / 10 | Simulator cards have zero usage; one petrol vehicle; one unrestricted card with no vehicle |
| fuel_transactions / card_monthly_usage | 32 / 12 | Overfill 75 L into a 60 L tank; fills 20 min apart; FF-ATLAS-H02 250 L used against a 200 L limit after an audited cut from 300 L; one purchase at the manual rate 89700 |
| delivery_orders / history / audit_logs | 6 / 16 / 12 | Every status covered; one audit row per transition, plus the quota change and the override |

### Checks: exact command and actual outcome

| Command | Outcome |
| --- | --- |
| `php artisan migrate:fresh --env=testing` → `migrate:reset` → `migrate` (test DB) | All 19 migrations applied, rolled back and reapplied |
| MySQL probe: CHECK on a column with an `ON DELETE RESTRICT` foreign key | Accepted, so every foreign key uses explicit restrict-on-delete |
| Smoke run: `demo:seed --env=testing --as-of=2026-09-28T09:00:00Z`, then reconciliation and spot checks, then a second run | Counts as in the table above; 0 mismatches; second run "nothing changed" |
| `make test` (first run) | **2 failed / 98 passed.** (1) `ErDiagramTest`: the spec diagram lacked real FKs (`created_by`/`changed_by`, vehicle/driver snapshots, audit company), so the diagram was completed. (2) Determinism test: all business values were identical, but `request_hash` includes the auto-increment `station_id`, so it was dropped from that fingerprint (another test recomputes every hash) |
| `make test` (second run) | 100 passed (870 assertions), 62.75 s |
| `make lint` | 3 files flagged (fully qualified class names in docblocks); Pint fixed them by adding imports only |
| `make analyse` | **55 errors at first.** Root cause: Larastan ignored every `casts()` array, even the skeleton's `email_verified_at`, because of the `array<string, string>` return docblock; `parseModelCastsMethod: true` fixed it. That left 1 real finding (a needless `?->` in a test), now fixed. Rerun: `[OK] No errors` |
| `make verify` | exit 0: Pint PASS on 99 files, Larastan OK, 100 tests / 870 assertions (52.60 s), `npm ci` 0 vulnerabilities, Vite build OK |
| `make setup` on the dev DB | The 15 new migrations ran; the demo was seeded as of 2026-09-28T20:36:17Z with the same volumes |
| Dev DB: `UsageReconciliation::mismatches()` and `Hash::check(DEMO_PASSWORD)` for all 5 accounts | 0 mismatches; all 5 accounts verify, with the right role and company/station |
| `sh docker/bin/check-setup-preserves-state.sh` (after the nginx fix) | PASS: env files kept, "Nothing to migrate", "nothing changed" for the demo, readiness OK |
| `docker compose run web nginx -t`; shellcheck on the 2 scripts | Config OK; clean |

### Environment note

Docker Desktop's file sharing once served stale file contents to the long-running `app` container: an edited file kept its old inode and content for several minutes, while fresh `docker compose run` containers saw the current file. The quality gates (`make test`, `make lint`, `make analyse`) always start fresh containers. If a code change does not show up in the browser, run `docker compose restart app`.

### Not run or not verified

- GitHub Actions (no remote).
- CHECK constraints and composite foreign keys on SQL Server (S01).
- Concurrency: the builder takes the documented locks, but overlapping-connection tests are M05 scope (T20–T23).
- Login through a UI (M02).

**Suggested commit message:** `feat: model fleet data and deterministic demo scenarios` (M00 is also still uncommitted; `chore: scaffold Laravel and reproducible local tooling` can be committed first). Not committed; commit only when asked.

**One concept to explain:** foreign keys and constraints versus validation. Validation (Form Requests, M02+) gives users friendly errors. Constraints are the database's own guarantee, so they also hold for seeders, scripts, bugs and future code paths. Two examples: here, a card cannot point at another company's vehicle even through raw SQL, and a ledger amount is `DECIMAL`, stored exactly (1600000.00 LBP → 17.88 USD) instead of as a binary float.

## Earlier session: M00

**Date / milestone:** 2026-09-28, M00 Foundation.

**Goal and actual state:** Scaffold Laravel safely into the nonempty kit and implement the Docker, Makefile and test-database contract, locked dependencies, minimal CI and the health page. Done; all local gates pass.

### Environment checked

| Check | Result |
| --- | --- |
| `bash scripts/check-prerequisites.sh` | exit 0 (Docker daemon reachable, GNU Make 4.3, Compose v5.1.4, Git 2.55.0, host Composer absent) |
| Docker engine | Docker Desktop for Linux, context `desktop-linux`, engine 29.5.3, x86_64, 8 CPUs, 8 GB RAM |
| Docker Desktop file sharing | `/tmp` is not shared, so staging used `~/.cache/fleetfuel-laravel-staging.*`, now deleted. Bind-mounted files show up as the host user (uid 1000) whatever the container UID |
| Ports | 8080 free. The host already runs MySQL on `127.0.0.1:3306`, so the container MySQL publishes no port |
| Disk | 88 GB free |
| Git identity | Global name/email already set; not changed |
| Registry | All images pulled successfully (slow link: roughly 10 minutes for the first pulls) |

### Resolved versions

| Component | Tag used | Resolved |
| --- | --- | --- |
| PHP image | `php:8.3-fpm-trixie` (Debian 13.7) | PHP 8.3.35, plus bcmath, intl (ICU 76.1), opcache, pcntl, pdo_mysql, zip 1.22.3 |
| Extension installer | `mlocati/php-extension-installer:2` | digest `sha256:1afade3e29cf…` |
| Composer | `composer:2.10` (copied into the image) | 2.10.3 |
| MySQL | `mysql:8.4` | 8.4.11 |
| nginx | `nginx:1.30-alpine` | 1.30.5 |
| Node | `node:24.21-alpine` | Node 24.21.0, npm 11.19.0 |
| Mailpit (optional profile) | `axllent/mailpit:v1.31` | v1.31.3 |
| Composer packages (locked) | | laravel/framework v13.33.0, fortify v1.40.0, sanctum v4.3.3, tinker v3.0.2, brick/math 1.0.0; dev: larastan v3.12.2, phpstan 2.2.16, pint v1.32.1, phpunit 12.5.36, collision v8.9.5, pail v1.2.7, mockery 1.6.15, faker v1.24.1 |
| npm packages (locked) | | vite 8.3.1, laravel-vite-plugin 3.2.0, bootstrap 5.3.8, @popperjs/core 2.11.8, jquery 4.0.0 |

### Changed files

- **New, infrastructure:** `compose.yaml`, `Makefile`, `docker/php/Dockerfile`, `docker/php/php.ini`, `docker/nginx/default.conf`, `docker/mysql/conf.d/fleetfuel.cnf`, `docker/mysql/init/10-create-test-database.sh`, `docker/bin/prepare-env.sh`, `docker/bin/check-setup-preserves-state.sh`, `.env.example`, `.env.testing.example`, `phpstan.neon`, `.github/workflows/ci.yml`.
- **New, application:** the Laravel 13 skeleton (`app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `artisan`, `composer.json/lock`, `package.json`, `package-lock.json`, `vite.config.js`, `phpunit.xml`, `.editorconfig`, `.gitattributes`, `.npmrc`), the Sanctum `personal_access_tokens` migration and `routes/api.php`.
- **New, project code:** `app/Http/Controllers/HealthController.php`, `config/fleetfuel.php`, `resources/views/layouts/app.blade.php`, `resources/views/home.blade.php`, `tests/TestDatabaseGuard.php`.
- **New, tests:** `tests/Unit/TestDatabaseGuardTest.php`, `tests/Feature/HealthCheckTest.php`, `tests/Feature/PublicPagesTest.php`, `tests/Feature/TestDatabaseIsolationTest.php`.
- **Skeleton files modified:** `bootstrap/app.php` (`/health` route), `app/Providers/AppServiceProvider.php` (`Fortify::ignoreRoutes()`), `routes/web.php`, `routes/api.php`, `database/seeders/DatabaseSeeder.php` (guarded no-op), `phpunit.xml` (MySQL test DB, config-cache isolation), `tests/TestCase.php` (guard), `resources/css/app.css`, `resources/js/app.js`, `vite.config.js`, `composer.json` (name, license placeholder, scripts), `package.json`.
- **Skeleton files removed:** `resources/views/welcome.blade.php`, `tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`. The skeleton `CLAUDE.md`, `AGENTS.md`, `README.md`, `.env`, `.env.example` and `database.sqlite` were never copied.
- **Kit files edited on purpose:** `README.md` (status and local-development section merged in), `.gitignore` (skeleton entries appended), `CHANGELOG.md`, `docs/DECISIONS.md` (M00 change record), `docs/PROGRESS.md`. `sha256sum -c` against the pre-merge checksums shows every other kit file byte-identical. The original research SHA-256 is still `27cf9918…9ef76b`.

### Checks: exact command and actual outcome

| Command | Outcome |
| --- | --- |
| `composer create-project --prefer-dist "laravel/laravel:^13.0"` inside `fleetfuel-app:dev` (PHP 8.3.35), in a staging directory | Laravel v13.33.0 resolved on PHP 8.3 without `--ignore-platform-reqs` |
| `rsync -a --ignore-existing` (dry run first) then `sha256sum -c kit-before.sha256` | Only new files added; all 41 kit files unchanged right after the merge |
| `composer validate --strict` | `./composer.json is valid` |
| `make setup`, fresh: no `.env`, no `.env.testing`, no volume | exit 0 in 39 s. `.env`/`.env.testing` created as mode 600 with 48-character generated passwords and consistent test credentials; both APP_KEYs generated; 4 migrations ran; test DB created by the init script; app, web, scheduler and mysql up (web and mysql healthy) |
| `curl` against localhost:8080 | `/health` → 200 `{"status":"ok","checks":{"app":"ok","database":"ok"}}` with no Set-Cookie; `/up` → 200; `/` → 200 with the Vite-built assets; `/.env` → 403; `/register` → 404; `/api/user` → 404 |
| `make lint` | PASS, 34 files |
| `make analyse` | **First run failed:** 1 error, `Log::shouldHaveReceived()` called on the facade in `HealthCheckTest`. Fixed by asserting on the spy object that `Log::spy()` returns. Rerun: `[OK] No errors` |
| `make test` | 14 passed (23 assertions), 0.57 s, on MySQL 8.4.11 `fleetfuel_test` |
| `php artisan config:cache`, then `make test`, then `config:clear` | Tests still ran on `fleetfuel_test` (a dev config cache cannot redirect them); cache cleared afterwards |
| `make verify` | exit 0: lint PASS, analyse OK, 14 tests passed, `npm ci` (0 vulnerabilities) plus `vite build` (CSS 230.39 kB / JS 158.99 kB before gzip) |
| `make verify` with a temporary badly formatted `app/ZzStyleProbe.php` | exit 2 at lint; analyse, test and build did not run; probe deleted |
| `sh docker/bin/check-setup-preserves-state.sh` (runs `make setup` a second time) | `PASS`: `.env` and `.env.testing` byte-identical, APP_KEY fingerprint unchanged, "Nothing to migrate", DB marker row survived and was then removed |
| `make down`, then `make up` | Volume `fleetfuel_mysql-data` kept; DB marker survived; `/health` ok |
| `make setup`, third run after the final Makefile edit | exit 0; kept env files; "Nothing to migrate"; `/health` ok |
| `docker compose --profile mail up -d --wait mailpit` | Healthy; UI on `127.0.0.1:8025` → 200; then stopped and removed |
| `docker compose config -q` / `rhysd/actionlint` 1.7.12 / `koalaman/shellcheck:stable` | Compose valid / 0 workflow errors / 0 findings on the 3 shell scripts |

### Not run or not verified

- The GitHub Actions workflow has not run: there is no remote and nothing was pushed. It uses the same `make setup` / `make verify` / check-script sequence that passed locally, but on a native Docker runner (uid 1001) rather than Docker Desktop.
- `make logs` hides MySQL's `GENERATED ROOT PASSWORD` line. That line is printed only on the first volume initialization, and the container that printed it was removed by `make down`, so the filter was not seen acting on a live line. The filter string matches the entrypoint source.
- Browser/visual check of the home page: not done; the check was an HTTP/HTML response only.

### Decisions and deviations

All are recorded in `docs/DECISIONS.md` (M00 entry): the separate test DB user with a prefix grant and a random root password; the 72-hour limits as config constants; Fortify routes disabled until M02; `/health` outside the session middleware; the sync queue; the skeleton cleanup (Tailwind, multiplex, pao, Composer setup/dev scripts, skeleton agent files); and the license placeholder `proprietary`.

### Remaining work and blockers

None for M00. For M11 the user still needs to choose the license (currently the `proprietary` placeholder), a GitHub remote and hosting.

**Suggested commit message:** `chore: scaffold Laravel and reproducible local tooling`. Not committed; commit only when asked.

**One concept to explain:** how a request flows. The browser reaches nginx (`web`, port 8080). nginx serves files from `public/` directly and forwards everything else over FastCGI to PHP-FPM (`app:9000`). There, `public/index.php` boots Laravel (`bootstrap/app.php`), the router matches `routes/web.php` or `/health`, and the response goes back through nginx. Only the `app` and `scheduler` containers hold the code and talk to MySQL over the internal network by the service name `mysql`, never `localhost`.

## Earlier session: handoff kit

- Goal: prepare a self-contained Claude Code build kit.
- Changes: specifications, staged prompts, progress and decision records, API assets, fixtures and handoff documentation.
- Verification: handoff validation completed.
  - 41 files; all JSON parsed; local Markdown links resolved.
  - All 204 internal OpenAPI references resolved across 19 operations.
  - All 28 scripts in the 26-request Postman collection passed Node syntax checking; tracked token/password fields are blank.
  - The prerequisite script passed `bash -n`.
  - Fixture arithmetic independently yielded 1600000.00 LBP / 17.88 USD.
  - These are kit checks, not application tests or a full OpenAPI conformance/HTTP run.
- Original research: copied byte-for-byte to `docs/reference/original-research.md`; SHA-256 `27cf99189c33adf2ff6684c08c1756bcf5cdb31492faab1007daa6b09a9ef76b`.
- Known product decisions awaiting user input: none needed for M00–M10; defaults are recorded in DECISIONS.
- External choices for M11: GitHub repository/account, hosting/provider/budget, domain, demo access policy, portfolio name and license choice.

## Session log template

Append a compact entry at each milestone or interrupted session:

```text
Date / milestone:
Goal and actual state:
Changed files:
Checks: exact command -> actual outcome (include failures/skips):
Manual verification:
Decisions/deviations:
Remaining work / blocker:
Next exact action:
Suggested commit message:
One concept the user should be able to explain:
```
