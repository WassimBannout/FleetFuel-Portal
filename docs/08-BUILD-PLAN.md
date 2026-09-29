# Ordered implementation plan

Execute one milestone per request. Each should finish with working code, checks, a short explanation and an updated PROGRESS.md. If it spans sessions, record the unfinished gate instead of skipping it. Suggested commit messages describe real completed work; they are not instructions to fabricate history or publish code.

Dependencies follow table order M00→M11. M11 may be locally prepared while waiting for a real hosting/account choice. S01 is optional after the MySQL MVP.

## M00 — Foundation

Read architecture and current progress. Check Docker daemon/registry access, port 8080, disk, Git identity (without changing it), and runtime compatibility. Create Laravel 13 in a temporary staging directory and merge into the nonempty repository root without losing the handoff. Pin compatible dependencies; select PHPUnit; install Fortify/Sanctum support, Bootstrap/jQuery/Vite, decimal arithmetic, Pint and Larastan. Avoid a UI starter kit that replaces the chosen frontend.

Create Compose, PHP Dockerfile, nginx config, local/test example envs, persistent dev and separate test DB, Makefile commands from the architecture contract and a small executable CI workflow. Initialize Git locally if absent; do not choose a remote. Add a health page and replace the default welcome page with a plain project shell.

Gate: `make setup`, health request, `make build`, `make lint`, `make analyse`, `make test` pass. Run setup again and confirm key/data preservation. Empty application tests at this phase only prove framework boot; say so. Record exact versions and actual commands. Explain Composer, Docker services and Laravel request flow.

Suggested commit: `chore: scaffold Laravel and reproducible local tooling`.

## M01 — Database, models and fixtures

Implement all domain migrations and relationships from DATA-MODEL; enums, factories, portable constraints, required indexes and guarded demo seeder. Include usage counters and immutable snapshot columns from the outset. Infrastructure tables must support selected cache/session/auth config. Add the seed identities/scenarios and synthetic historical prices/rates with a relative/frozen clock. Document demo login and seeding rules.

Gate: T02 on MySQL; migration rollback/reapply only in isolated test DB; repeatable seeding; relationship/ownership constraints checked. If the transaction service does not exist yet, use a dedicated fixture builder that updates ledger/counters consistently and is later reconciled in M05. ER diagram matches migrations. Explain foreign keys versus validation and why DECIMAL matters.

Suggested commit: `feat: model fleet data and deterministic demo scenarios`.

## M02 — Authentication, roles and isolation

Implement Fortify login/logout with Bootstrap views, no public registration, session regeneration, disabled-account handling and login throttle. Implement role policies and explicit scoped query helpers; establish the route/middleware boundaries for each role. Add Sanctum issue/revoke endpoints with server-selected abilities, expiration and token-only API access. Create consistent API exception rendering and request IDs. Provide a documented local/admin provisioning path for users (seed first; controlled Artisan command is enough for MVP).

Gate: T03–T06 for implemented surfaces. Add two-company and two-station policy tests, not only admin success. Verify no manager-controlled role/company escalation, generic auth failures and token revocation. Explain authentication versus authorization and CSRF versus bearer tokens.

Suggested commit: `feat: add role-based authentication and tenant isolation`.

## M03 — Fleet and reference-data UI

Create paginated Blade CRUD/archive screens for companies, stations, products, vehicles, drivers and cards. Use Form Requests and Policies. Implement card services with ownership checks, lock discipline, allowed quota/status edits and audit. Show quota reduction warning; archive rather than delete history. Tenant-scoped selectors matter as much as list pages. Seed support for all three roles remains usable.

Gate: T04/T05/T07/T08 on real routes; bad company/vehicle/driver IDs denied, master data cannot erase ledger references, user input escaped. Manually create company→vehicle/driver→card. No production billing/credit-limit feature. Explain thin controllers and service reuse.

Suggested commit: `feat: add fleet management screens and audited card controls`.

## M04 — Prices and external FX

Implement BigDecimal calculations, PriceResolver, ExchangeRateProvider interface and HTTP implementation, persisted observations, fixture/live modes, bounded fallback and admin override. Add `rates:sync`, daily schedule with overlap prevention, integration-status UI and price timeline. Respect publication time/expiry, caching and attribution. No external call is permitted in ingestion or report rendering.

Gate: T09–T12 (transaction immutability portion completes in M05). Run sync against fakes in tests, optionally one live manual request if network works; label which was verified. Test provider failure without valid cache, manual expiry, response validation and duplicate observations. Explain why reports use stored amounts and why latest data cannot create historical rates.

Suggested commit: `feat: add historical pricing and resilient exchange-rate sync`.

## M05 — POS transactions and atomic quotas

Implement FuelTransactionService, canonical request hashing, station-derived scope, card/month locks, DB uniqueness, decimal snapshot calculation, retries and balance. Expose transaction create/list/detail and card balance. Complete card quota editing's shared-lock behavior. Add read-only counter reconciliation. Use the exact status/error/replay contract.

Gate: T10 and T12–T23, including genuine overlapping MySQL connections/processes. Same-ref replay does not spend twice; two stations cannot jointly overspend the same card. Check immutable rows and snapshots. Price/FX failures roll back counters. Explain the lost-update race and how the locks plus unique index solve separate problems.

Suggested commit: `feat: ingest idempotent POS transactions with atomic quotas`.

## M06 — API completeness and simulator

Implement reference endpoints, card patch, vehicle/driver GET/POST, route policies/resources and pagination/filters. Build independent `tools/pos-simulator` Composer/Guzzle CLI reading base URL/token from environment. It must support named scenarios: success, replay, conflict, blocked, quota and all, with assertions and nonzero exit on unexpected outcome. Never bake tokens into files.

Validate/update OpenAPI and Postman against actual responses. Import supplied collection; fill only local environment credentials. Postman uses generated time/ref variables, but replay/conflict reuse the original saved payload. Add role/isolation calls and token revocation. Document reruns: already-consumed cards need a fresh dedicated fixture or different scenario card; a normal rerun must not silently reset user data.

Gate: T24/T25 and fresh-fixture scenario sequence; 401/403/404/409/422/429 covered. OpenAPI tools validate schema and route parity. Delivery/report endpoints remain explicitly planned until M07/M08. Explain status codes and token abilities.

Suggested commit: `feat: document the API and add a standalone POS simulator`.

## M07 — Delivery workflow

Implement scoped creation/list/detail API and Blade UI, state service, expected_status concurrency check, scheduled slot/truck validation, manager pending cancellation and admin lifecycle. Write initial history and every transition/audit atomically. AJAX status buttons send CSRF and expected_status; errors refresh stale data. Delivery is separate from card fuel spending.

Gate: T26–T28, including concurrent transition test. Terminal state cannot reopen; skipped transitions and unauthorized manager updates fail. Demonstrate complete timeline. Explain state machines and transactions across related writes.

Suggested commit: `feat: add audited diesel delivery workflow`.

## M08 — SQL reports and exports

Implement ReportRepository with parameterized SQL for consumption by company/vehicle/product, current quota exceptions, top stations, tank/rapid-fill anomalies, efficiency estimate and delivery SLA. Add web report pages, consumption JSON and streaming accounting CSV. Use transaction ownership/capacity snapshots, ID-based grouping, half-open UTC ranges and proper predecessor lookback. Document real query plans/indexes for representative data.

Gate: T29–T33 and cross-tenant tests for every report/export/filter. Filter totals match full CSV and known fixtures. Formula-injection test passes. No recalculation with today's FX. Explain GROUP BY/HAVING, LAG, bound values versus allowlisted identifiers and index tradeoffs.

Suggested commit: `feat: add SQL consumption reports and scoped accounting exports`.

## M09 — Dashboard and UI finish

Complete scoped dashboard, AJAX transaction filters/totals, loading/error/empty states, responsive navigation, safe dynamic text, accessible labels and validation. Prevent out-of-order AJAX responses. Show fixture/provider/manual FX provenance and source attribution. Keep dependency footprint small; charts are optional if a table is clearer.

Gate: T34 plus regression tests; complete the five-minute demo in UI-SPEC. Check admin/manager/operator views at desktop and mobile widths, keyboard-only flow, expired sessions and network failures. Capture real screenshots. Explain N+1 query avoidance and frontend/backend filtering consistency.

Suggested commit: `feat: finish the dashboard and responsive AJAX workflows`.

## M10 — Release verification and troubleshooting exhibit

Finish CI with Pint, Larastan, MySQL feature/concurrency tests and frontend build. Validate docs/contracts against routes and commands. Review the acceptance matrix for gaps, inspect authorization/money/idempotency/audit/CSV handling, check dependency advisories and triage real findings. Fix meaningful issues with regression tests. Document one actual debugging story; do not manufacture a bug/PR history.

Gate: all applicable T01–T35 pass, excluding deployment-specific steps until M11; clean-checkout setup in isolated volumes works; documented commands need no untracked secrets. Save verification evidence and genuine limitations. Prepare CHANGELOG and a reviewable PR description. Explain one bug, its cause and the test preventing recurrence.

Suggested commit: `test: complete release checks and clean-clone verification`.

## M11 — Shipping and portfolio

Follow OPERATIONS-AND-PORTFOLIO. Build and smoke-test a production image/runbook, backup/restore and health checks using disposable local/staging data. Replace README with actual setup, features, architecture, demo/API usage, test evidence, screenshots and tradeoffs. Prepare short demo script and résumé bullets based on measured output. Prepare GitHub/branch-protection instructions and a PR if requested.

Ask for hosting/account/URL or publishing direction only when the local release candidate is concrete. If unavailable, finish the runbook and demo recording instructions, and record live deployment as pending. Do not mark the live-demo objective done without an actual reachable URL and smoke test. Do not send an application email or publish credentials automatically.

Gate: production-style boot, migration, schedule, auth, ingestion, delivery, report/CSV and restore rehearsal verified; README and claims reflect actual state. Live deployment/protected branch/CI status must have evidence or be labeled pending. Explain operations failure handling and remaining tradeoffs.

Suggested commit: `docs: prepare release runbook and portfolio walkthrough`.

## S01 — SQL Server compatibility (optional)

Read current official Laravel/Microsoft requirements. Add optional SQL Server 2022 Compose profile, Microsoft ODBC and compatible sqlsrv/pdo_sqlsrv PHP extensions without burdening default MySQL runtime. Check machine architecture and resource needs before pulling. Use a dedicated database/account and externalized credentials.

Port migrations, nullable-unique constraints, JSON representation/checks, date math, pagination/limit, isolation/locking and exception mapping. Keep dialect SQL explicit inside ReportRepository. Test reports against the same expected fixture outputs; verify real concurrent quota/replay behavior on SQL Server. Add opt-in/manual CI until reliable, then a supported matrix if practical.

Gate: T36 with recorded versions/commands/results. An installed driver or successful connection alone is not compatibility. Explain actual differences encountered and update README only after tests pass. No stretch feature is required to begin applying with the MySQL MVP.
