# Decisions and source corrections

These are implementation defaults chosen to make the research actionable. They are project design decisions, not claims about a real distributor. Amend with a dated rationale and update specs, contracts and tests together.

| ID | Decision | Rationale / source correction |
| --- | --- | --- |
| D01 | Laravel 13 / PHP 8.3 / MySQL 8.4 | Supported framework baseline; lock actual resolved dependencies |
| D02 | Fortify + custom Bootstrap Blade views | Preserve requested UI stack; do not assume Breeze scaffolding matches Laravel 13 and Bootstrap |
| D03 | PHPUnit, real MySQL integration tests | One test framework; SQLite cannot establish MySQL locking behavior |
| D04 | First POST 201, exact replay 200, changed payload 409 | A harmless retry should return the original success; source suggested 409 for every duplicate |
| D05 | Card lock plus monthly usage row | Database atomicity for concurrent spending, across all stations |
| D06 | LBP per-liter canonical price, USD derived | Avoid two independently editable prices disagreeing; persist transaction snapshots |
| D07 | Decimal strings, explicit half-up rounding | No binary float financial arithmetic; documented amount-based USD conversion |
| D08 | UTC storage, Beirut business months, <=72h ingestion | Bound late POS traffic and define month-boundary behavior |
| D09 | FX freshness <=72h at transaction time | Last-known fallback is bounded and never silently becomes zero/1:1 |
| D10 | Immutable FX observations and expiring manual overrides | Preserve provenance; overrides cannot silently rewrite history |
| D11 | Quota reduction below usage allowed and audited | Explains legitimate over-quota report rows despite rejecting overspending; remaining balance floors at zero |
| D12 | Explicit tenant-scoped queries and policies | Scope every surface, including reports and dropdowns; no magic global scope hiding admin behavior |
| D13 | Snapshot transaction ownership and vehicle data | Reports remain historically accurate; referenced entities archived instead of deleted |
| D14 | Card ownership immutable; assignment fixed after first use | Simplifies junior MVP and prevents accidental transfer of historic usage |
| D15 | Append-only accepted fuel ledger | Refunds/imports/accounting corrections require later design, outside MVP |
| D16 | No self-registration or role mutation by users | Internal B2B roles are provisioned deliberately |
| D17 | Complete MySQL MVP before SQL Server | Portability is not verified until driver, migrations, queries and tests run there |
| D18 | No enforcement of `credit_limit_usd` | Source listed a field without billing rules; omit until credit model exists |
| D19 | Versioned static OpenAPI plus Postman | Satisfies API documentation without making Scribe a build dependency |
| D20 | Docker-first dependencies | Host Composer is missing; preserve existing machine configuration |
| D21 | No paid hosting assumption | Prepare deployment first, choose/publish only with user's actual account and direction |
| D22 | Live provider data stays server-side | Show attribution on converted-value pages; do not publish a raw exchange-rate redistribution API |

## Change record

2026-09-28: Initial defaults above. No unresolved product choice blocks local MVP development.

2026-09-28 (M00): Implementation choices within existing decisions; no product behavior changed and no spec contract changed.

- D03/D20, test isolation: MySQL init creates a separate `fleetfuel_test` user whose grants cover only databases matching `fleetfuel\_test%`; the dev user covers only `fleetfuel`. Root gets a random password that nothing stores. Tests also refuse any database other than `fleetfuel_test*` (guard in `tests/TestCase.php`) and never read a development config cache (`APP_CONFIG_CACHE` in `phpunit.xml`).
- D08/D09: the 72-hour FX and POS age limits are constants in `config/fleetfuel.php`, not per-environment variables, so the rule cannot drift between environments. Tests arrive with M04/M05.
- D02/D16: Fortify is installed, but `Fortify::ignoreRoutes()` keeps its default routes (including public registration) off until M02 configures login with Bootstrap views.
- Health: `/up` is Laravel's liveness route; `/health` is JSON readiness with a database check. It sits outside the session middleware and returns 503 without details when MySQL is down.
- Queue: `QUEUE_CONNECTION=sync` with no worker, per docs/09 ("a queue worker is unnecessary until a queued feature exists").
- Skeleton cleanup: removed Tailwind, `concurrently`, `@laravel/multiplex`, the bunny-font Vite option and the `laravel/pao` agent-output package. Removed the Composer `setup`/`dev` scripts, which were a non-Docker SQLite setup path. Did not copy the skeleton's `CLAUDE.md`/`AGENTS.md`, which tell agents to install host PHP and Laravel Boost, conflicting with this project's CLAUDE.md. Added Bootstrap 5.3, Popper 2 and jQuery 4.0.
- License: the skeleton's `"license": "MIT"` is replaced by the placeholder `proprietary` (all rights reserved). Choosing the real license stays with the user at M11.

2026-09-28 (M01): Schema and fixture choices within existing decisions. No product rule changed; the ER diagram in 03-DATA-MODEL was completed to list every real foreign key.

- D12/D13/D14, ownership in the database: composite foreign keys make MySQL reject a card whose vehicle or driver belongs to another company, and a ledger row whose company snapshot differs from its card, vehicle or driver. Parent tables carry a `UNIQUE (company_id, id)` key for this. Validation still runs first; the keys are the backstop.
- CHECK constraints: named, portable `ALTER TABLE … ADD CONSTRAINT … CHECK` statements (positive amounts, allowed status/enum strings, user role scope, delivery state details, manual-rate reason). They are enforced by MySQL 8.4 and tested; SQL Server behavior is unverified until S01.
- D15, append-only rows: prices, rates, the ledger, delivery history and audit logs have `created_at` only, no `updated_at`. An `AppendOnly` model trait throws on Eloquent update and delete. Raw query-builder statements bypass it; there are no database triggers or per-table grants in the MVP.
- Time columns: domain instants are `DATETIME` storing UTC (the server time zone is +00:00), avoiding `TIMESTAMP`'s 2038 limit and session time-zone conversion. Laravel's own `created_at`/`updated_at` stay `TIMESTAMP`.
- Framework defaults set in AppServiceProvider:
  - dates are `CarbonImmutable`;
  - `Model::shouldBeStrict()` outside production (lazy-loading, discarded-attribute and missing-attribute errors);
  - an enforced morph map, so polymorphic columns store stable aliases such as `fuel_card`.
- Shared helpers introduced early because seeded history needs them: `FuelAmounts` (half-up decimal arithmetic), `BusinessMonth` (Beirut quota months), `PosRequestHash` (canonical request hash), `UsageReconciliation` (read-only counter check) and `DeliveryStatus` transitions. M04, M05 and M07 must reuse and extend them (input validation, bounds, commands), not duplicate them.
- Ledger fixtures: `Database\Seeders\Support\LedgerFixtureBuilder` writes purchases, quota changes, manual rates and delivery transitions under the documented locks and audit rules until the real services exist. It resolves rates in fixture mode only (manual override first, then fixture). M05 must reconcile it with FuelTransactionService.
- Demo seeding:
  - `DemoSeeder` is guarded (local/testing only, `DEMO_MODE` on, `DEMO_PASSWORD` set, no existing users) and only inserts.
  - `php artisan demo:seed --as-of=<ISO-8601 with offset>` picks the clock; it must not be in the future.
  - Scenario times are fractions of the elapsed month, so any as-of works. Current-month scenarios are skipped when the as-of is less than two hours into its month.
  - The admin override expires before the as-of, so simulator purchases use the fixture rate.
  - `prepare-env.sh` now also generates `DEMO_PASSWORD` when it creates `.env`.
  - `DatabaseSeeder` no longer uses `WithoutModelEvents`, so the append-only guards stay active while seeding.
- Tooling: Larastan `parseModelCastsMethod: true`. Without it Larastan ignored every `casts()` array (because of the skeleton's `array<string, string>` return docblock) and typed casts as raw column types.
- M00 fix found during M01: after `make setup` recreated the `app` container, nginx kept the old container IP and returned 502. nginx now resolves `app` through Docker DNS at request time, and `make setup`/`make up` end with a real `/health` request instead of trusting cached container health.

2026-09-29 (M02): Authentication and isolation choices within D02, D12 and D16. No product rule changed. 05-API-CONTRACT now records the chosen rate limits, request IDs, `method_not_allowed` (405) and the rejection of unknown fields.

- D02/D16, Fortify:
  - Fortify 1.40's package defaults enable registration, password reset, two-factor and passkeys, and its route file always adds password-confirmation routes.
  - So `config/fortify.php` sets `features => []` and `Fortify::ignoreRoutes()` stays. `routes/web.php` registers only `GET/POST /login` and `POST /logout`, pointed at Fortify's own controllers, so Fortify's throttling, username lowercasing, session regeneration and logout invalidation still run.
  - A test asserts that the other Fortify paths (and Sanctum's `/sanctum/csrf-cookie`, turned off with `sanctum.routes => false`) return 404.
- Credentials: `App\Services\CredentialVerifier` is the single check behind web sign-in (`Fortify::authenticateUsing`) and API token issuance.
  - Unknown email, wrong password and disabled account all fail with the same message (`auth.failed`) and the same 401 body.
  - An unknown email still spends one hash operation, so response time does not reveal whether an email is registered.
- Login throttle: web sign-in uses Fortify's built-in limiter, which counts failed attempts only and shows a form error. It allows 5 failed attempts per email + IP per minute, and a success clears the count. Token issuance uses the named limiter `token-issue`: 5 requests per minute per email + IP, counting every request, over the limit 429 with `Retry-After`. Authenticated API requests use `api`: 120 per minute per user.
- D12, tenant scoping: tenant models have explicit `scopeVisibleTo(User)` local scopes, never global scopes:
  - vehicles, drivers, cards and deliveries: `BelongsToCompany`;
  - purchases: company for managers, station for operators;
  - companies;
  - stations and products: active only for non-admins;
  - audit logs: admin only.

  Order of checks on a record: role-level policy (403), then scoped lookup (404, so a guessed foreign ID looks like a missing one), then the record policy (403). Controllers load records explicitly with `->visibleTo($user)->findOrFail()`; no implicit route-model binding for tenant data.
- Policies: one per model, encoding the brief's capability table. Each has a `before()` that denies a disabled account everything. Accepted purchases and prices have no update or delete ability. `role:` route middleware fences off each role's area, and `active` middleware signs out a user disabled after sign-in on their next request.
- Sanctum:
  - `sanctum.guard => []` makes the API token-only; a browser session cookie never authenticates `/api/v1`.
  - Tokens get `expires_at = now + 24 h`, with `sanctum.expiration = 1440` minutes as a backstop.
  - `Sanctum::authenticateAccessTokensUsing` rejects any token whose account is disabled.
  - Abilities come from `UserRole::tokenAbilities()` (the contract table); a request naming `abilities` or `role` is a 422, via `RejectsUnknownFields`.
  - Token responses send `Cache-Control: no-store`.
- API errors: `App\Exceptions\ApiErrorRenderer` renders every `/api/*` exception as the documented envelope. 500s are generic even with `APP_DEBUG=true`, and `Retry-After` is preserved. `RejectMalformedJson` returns 400 `malformed_json` for an unparseable or non-object JSON body. Browser pages keep Laravel's HTML error pages, and session AJAX error shape is decided when AJAX screens arrive (M08/M09).
- Request IDs: `AssignRequestId` (global) generates a UUID per request and ignores any client-supplied value, to prevent log injection and ID collisions. It puts the ID in Laravel `Context`, so every log entry carries it, and in the `X-Request-Id` header. `App\Support\RequestId::current()` reads it for audit rows and error bodies.
- D16, provisioning:
  - `users:create` asks for the password at a hidden prompt only (minimum 12 characters; never an option, which would land in shell history). It enforces the role/company/station rule and an active company or station.
  - `users:deactivate` refuses sign-in, deletes every API token, deletes stored database sessions and rotates the remember token.
  - `users:activate` re-enables an account; revoked tokens stay revoked.
  - Each writes an audit row (`user.created`/`deactivated`/`activated`, no actor for the command line) through the new `App\Services\AuditService`, which M03+ services reuse.
  - There is no password-reset feature yet (no email flow in the MVP).
- Inactive company or station: its users can still sign in and read. Writes are refused from M03 (fleet) and M05 (POS), as the brief says.
- Read-only pages added in M02 so isolation is testable on real routes: the dashboard (scoped counts and latest 10 purchases), the station home (latest 25 purchases at the operator's station plus token instructions) and purchase detail. Card numbers are masked to the last four characters on screens. M08/M09 add filters, reports and polish.
- Observation, unchanged: the Laravel skeleton's signed local-disk routes (`GET`/`PUT storage/{path}`, from `filesystems.disks.local.serve`) are registered. They are unused and reject requests without a valid signature.

2026-09-29 (M03): Fleet and reference-data screens within D12, D13, D14 and D11. No product rule changed. The points below fill in details the specs leave open.

- D12, tenant-scoped route binding:
  - `AppServiceProvider::bindTenantScopedModels()` resolves `{company}`, `{station}`, `{product}`, `{vehicle}`, `{driver}` and `{card}` through `Model::visibleTo($user)`, so another company's record is a 404 on every route that names it, including future API routes.
  - `bootstrap/app.php` moves `active`, `role:` and Sanctum `abilities`/`ability` ahead of route binding in the middleware priority. A wrong role or missing token ability therefore still gets 403, not 404.
  - API routes (M06) must declare `role:` middleware for role-level denials to be 403.
  - The M02 test route in `AbilitiesAndPoliciesTest` now uses this wiring.
- Services shared with the API:
  - `FuelCardService`: issue, assignment, limits, status, balance.
  - `FleetService`: vehicles, drivers.
  - `ReferenceDataService`: companies, stations, products.

  Controllers only call a Form Request (validation plus policy) and a service. `BusinessRuleViolation` (extends `ApiException`) carries the documented code: `company_inactive` 403, `assignment_locked` 409, `invalid_transition` 409 for an archived card. On `/api/*` it becomes the envelope; on web pages it returns to the form with the message.
- D14, cards:
  - Every card change runs in one transaction that first reads the card `FOR UPDATE`, the lock M05 ingestion will also take, and writes one audit row.
  - Company ownership never changes; `company_id` on any update is a validation error.
  - Vehicle, driver and product restriction change only while the card has no transactions; this is checked under the lock.
  - Archived is final, with no restore and no edits.
  - Card numbers are generated by the server (`FF-XXXX-XXXX`, without 0/O/1/I look-alikes), not typed by users.
  - Audit values never contain the card number; `auditable_id` identifies the card.
- D11, quota cuts: lowering a limit below this Beirut month's usage is allowed and audited with `below_current_usage: true`.
  - The web screen first refuses it with a warning and asks for a tick box, via `allowBelowUsage` in `FuelCardService::updateLimits`.
  - The API (M06) will pass `true`, as the contract says reductions are valid.
  - Only a changed dimension triggers the warning. Limits are validated as plain decimal strings (`App\Rules\DecimalString`: no sign, exponent, separators or extra decimals), and an empty value means unlimited.
- Inactive company: its fleet becomes read-only, except actions that reduce spending: blocking or archiving cards and deactivating vehicles or drivers. Creating records, editing, raising or setting limits, unblocking and reactivating are refused with `company_inactive`. Its users can still sign in and read.
- Vehicles: the plate is normalized to capitals with single spaces and is unique across all companies. The fuel type is fixed at creation, like the company, because card product restrictions depend on it. Tank capacity and odometer stay editable; purchases keep their own snapshot. Drivers: the license number is normalized and unique per company.
- Products: the three codes are fixed, so admins can only rename or deactivate them. The price timeline is M04.
- Stations and products: every role reads the active ones (reference data); only admins change them.
- Audited in M03:
  - `card.created`, `card.assignment_changed`, `card.limits_changed`, `card.status_changed`;
  - `company.activated`/`deactivated`;
  - `station.*`, `product.*`, `vehicle.*` and `driver.*` activation changes.

  Plain name or plate edits are not audited. No delete routes exist; `DELETE` answers 405.
- Admins see a card's audit history on its page; the full audit screen with filters is later UI work. Card numbers are masked in lists and shown in full on the card page to the admin and the owning manager.
- Testing note: the session uses JSON serialization, Laravel 13's default. Calling `assertSessionHasErrors()` between two requests in one test re-loads the session and empties the error bag of the next simulated request. Tests that check a page after a refused form follow the redirect in one chain. The real app shows the errors; this was verified live through nginx.

2026-09-29 (M04): Prices and exchange rates within D06, D07, D09, D10 and D22. No product rule changed. One schema addition: `integration_sync_states.next_attempt_at` (03-DATA-MODEL updated). The points below fill in details the specs leave open.

- Rate modes (`App\Enums\RateMode`, from `EXCHANGE_RATE_MODE`; any other value is a startup error):
  - Fixture mode reads fixture rows and ignores provider rows. Live mode reads provider rows and ignores fixture rows.
  - A valid manual override wins in both modes (latest `effective_at` first).
  - Eligibility at instant T is `effective_at <= T < expires_at`, in one model scope that both `PriceResolver` and the status page use.
  - A live failure never creates or falls back to a fixture row.
- `PriceResolver` only reads the database:
  - price: the latest `effective_from <= T`, else `price_unavailable` (422);
  - rate: as above, else `rate_unavailable` (503).
  - `quote()` also re-validates the liters string (the `DecimalString` rule) and checks that `amount_lbp` fits DECIMAL(20,2) and `amount_usd` fits DECIMAL(18,2). An overflow is a validation error (422 `validation_failed`), as a backstop for the M05 request layer.
  - `LedgerFixtureBuilder` now prices seeded purchases through it with `RateMode::Fixture`, because demo history is synthetic whatever mode runs. Seeded history is unchanged: the seeder and builder tests pass as before.
- Provider response (`HttpExchangeRateProvider`, fixed URL from config):
  - `result` must be `success` and `base_code` must be `USD`.
  - `rates.LBP` must be a JSON number (a string is refused), positive after rounding, with at most 12 integer digits.
  - PHP decodes a JSON number into a float. It is written back out as its shortest exact decimal text and rounded half-up to 8 decimals at once; no arithmetic is done on the float. About 15 significant digits survive, which is exact for any USD/LBP rate with 8 decimals below 10^7.
  - `effective_at` is `time_last_update_unix`. It is refused if more than 5 minutes ahead of our clock, or if it is already 72 hours old, since it would expire on arrival (`stale_observation`).
  - `time_next_update_unix` is used as a hint only when it is after the observation time.
- Retries:
  - at most 3 attempts in total, retrying only on no response or timeout (`ConnectionException`) and HTTP 5xx, with pauses of 500 ms and 1000 ms;
  - connect timeout 3 s, total timeout 10 s, redirects not followed.
  - A 429 ends the run: the next attempt waits for `Retry-After` (seconds or an HTTP date, capped at 24 hours), else the provider's documented 20 minutes.
  - Other 4xx and invalid bodies are not retried.
  - Failures store only a short code (`connection_failed`, `server_error`, `rate_limited`, `http_error`, `invalid_response`, `provider_error`, `unexpected_base`, `invalid_rate`, `invalid_timestamp`, `stale_observation`), never the response.
- `rates:sync`:
  - It keeps one `integration_sync_states` row per mode (`exchange_rates.fixture`, `exchange_rates.live`), so switching modes does not inherit the other mode's next-update time.
  - `next_attempt_at` holds the provider's next update after a success, or the 429 retry advice. A run before it downloads nothing unless `--force` is passed.
  - Runs never overlap: the command takes the cache lock `rates-sync`, and the daily schedule (01:00 UTC) also uses `withoutOverlapping()`.
  - A repeated observation is a no-op. A different value for an instant already stored keeps the stored row, logs a warning with both values, and sets `last_error_code = conflicting_observation`.
  - Pages never sync, so there is no "sync now" button; the status page shows the command.
- Fixture mode: `rates:sync` makes no HTTP request and stores one `fixture` row at 00:00 UTC per UTC day, valid for 72 hours. Its value is the config constant `fleetfuel.exchange_rates.fixture_rate` (89500.00000000, fictional), which the demo seeder now reads too.
- Manual overrides are entered as a start (empty means now, else a later Beirut time) plus a validity of 1–72 whole hours, instead of a free-form expiry timestamp, so the 72-hour limit holds by construction.
  - `ExchangeRateService` re-checks both rules.
  - An override cannot be edited or ended early; a newer override takes precedence, or it expires on its own.
  - Two overrides starting in the same second are refused.
  - Audit action: `exchange_rate.override_created` (rate, effective_at, expires_at, reason).
- Prices: an empty start means now (the server's current second); otherwise a later Beirut minute.
  - A past start or a second price at the same instant is refused.
  - Audit action: `product_price.published` (product_code, price_lbp, effective_from).
  - Every role reads a product's timeline, non-admins only for active products (the scoped `{product}` binding). Admins also see who published each price and when.
  - Admins may publish a price for an inactive product, ready for reactivation.
- Indicative USD per liter (products list and timeline) is `price_lbp ÷ rate`, rounded half-up to 4 decimals with the rate in effect now. It is for display only and is hidden when no rate is valid. Purchases store their own amounts (M05).
- D22 attribution: "Rates By Exchange Rate API", linking to exchangerate-api.com, appears wherever a provider rate is shown: products list, timeline, the integration page in live mode, and purchase detail when its snapshot source is `provider`. Only USD/LBP is stored; the provider feed is not republished.
- Tests: `Http::preventStrayRequests()` in `tests/TestCase.php`, so any unfaked outbound request fails a test. Retry pauses use Laravel's `Sleep`, faked in tests to assert the exact pauses.

2026-09-29 (M05): POS ingestion within D04, D05, D07, D08, D13 and D15. No product rule changed. The API contract gained an "Implemented behavior (M05)" section; the points below fill in details the specs leave open.

- One service, `FuelTransactionService`, records every accepted purchase:
  - `ingest()` handles the POS API.
  - `recordHistorical()` handles demo and test history, with the same checks, locks and snapshots. It skips only the request-time rules (72-hour window, replay) and always prices in fixture mode.
  - `LedgerFixtureBuilder` now delegates to `recordHistorical()`. Its refusals keep the `LogicException` form and include the error code. Seeded history is unchanged: the seeder and builder tests pass as before.
- Locks (D05):
  - Order: card row `FOR UPDATE`, then this month's counter row `FOR UPDATE`, then the ledger insert.
  - The counter row is created on a card's first purchase of the month after a plain existence check. Only the holder of the card lock can create it, so the check is safe, and no gap lock is taken. (`SELECT … FOR UPDATE` on a missing row would take one and could deadlock with another card's first purchase.)
  - The in-transaction replay recheck is the transaction's first plain read, made after the card lock is granted. Under MySQL's REPEATABLE READ the snapshot starts at that read, so it sees an identical request that committed while this one waited.
  - It deliberately does not use `FOR UPDATE` on the missing `(station_id, external_ref)` key. That gap lock would turn the different-card race into a deadlock instead of a unique-index decision.
- D04, the unique index as the final arbiter:
  - A `UniqueConstraintViolationException` on insert rolls the transaction back. A fresh read then finds the winner: equal hash gives 200, a different hash 409.
  - Deadlocks and lock timeouts (Laravel's concurrency-error detector) are retried with the whole transaction, 3 attempts in total, pausing 20–60 ms × attempt.
  - When attempts run out, the answer is 503 `temporarily_unavailable` with `Retry-After: 1`.
  - The mutation check showed the recheck is defense in depth: without it, identical concurrent retries still end as one 201 and one 200, through the unique index.
- Step 1 order: an inactive station is refused in the Form Request's `authorize()` (403 `station_inactive`), so before structural validation, as docs/04 lists.
- Window (D08): both ends are inclusive, and an event even one second after "now" is refused. There is no clock-skew allowance, following docs/04 literally; M06's simulator sends the current time.
- Counter bounds: an increment that would overflow `used_l` DECIMAL(14,2) or `used_usd` DECIMAL(20,2) is a 422 before anything is written, like the amount bounds in `PriceResolver::quote()`.
- Balance endpoint:
  - A station operator may look up any card, because a card works at every station (new `FuelCardPolicy::viewBalance`).
  - A manager only finds their company's cards (404 otherwise), and admins find all.
  - Figures always use current limits, and the response has no customer data.
- Transaction list: `from`/`to` must come together, unknown query parameters are 422, and the response is built explicitly so `meta` has exactly the OpenAPI keys (Laravel's default paginator adds others).
- Rate limit: `throttle:pos-writes`, 60 per minute keyed by station, so one station's operators share it.
- `php artisan usage:reconcile` wraps the M01 `UsageReconciliation`. It is read-only, exits 1 when any counter differs, and prints card IDs, never card numbers.
- Concurrency tests (T20–T22), in the new "Concurrency" PHPUnit suite:
  - Each request runs in a separate PHP process (`tests/Concurrency/pos-worker.php`, through the real HTTP kernel or `FuelCardService`), with its own MySQL connection to a dedicated database, `fleetfuel_test_concurrency`. It is created and migrated on first use, then emptied before each test.
  - Barrier: the test holds the contested row lock, waits until `SHOW FULL PROCESSLIST` lists every worker waiting (Laravel's prepared statements show as `Execute`), then releases it. Ordered cases run one side inside the test's own open transaction.
  - Every wait is bounded (20 s), and workers are stopped in `tearDown`.
- Laravel 12+ `Schema::getTableListing()` lists every schema the MySQL user can see. `MigrationsTest` and the concurrency cleanup now pass the current database explicitly; the second test database had exposed this.

Template: date, affected decision, old/new behavior, reason, spec/test updates, migration implications.
