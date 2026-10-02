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

2026-09-30 (M06): API completeness, simulator and contract checks within D04, D12 and D16. No product rule changed. The API contract gained an "Implemented behavior (M06)" section; the points below fill in details the specs leave open, plus one intentional contract addition.

- Contract addition: each price-list row carries `rate_source` (`fixture`, `provider` or `manual`), added to the OpenAPI `Price` schema as required. Reason: the USD figure is indicative, so a client should know whether it rests on a synthetic demo rate, a provider observation or an admin override, as the purchase resource already tells it.
- `openapi.json` now marks each operation with:
  - `x-status` (`implemented` or `planned`) and `x-milestone`;
  - `x-abilities`, the token ability the route requires;
  - `x-roles`, where the route restricts roles.
  
  A test compares these with the real route middleware. Delivery (M07) and report/export (M08) operations stay documented as `planned` and unrouted.
- Validation of the document: the official OpenAPI 3.1 JSON Schema (2022-10-07), stored unmodified in `tests/Fixtures/openapi/`, checked with the dev dependency `opis/json-schema` 2.6.
  - opis resolves the schema's `"$dynamicRef": "#meta"` to the document root. In this base schema, where no dialect overrides the anchor, the reference means `"$ref": "#/$defs/schema"`, so the test substitutes it in memory.
  - Schema defaults are never written into validated data (`allowDefaults` off).
  - A deliberately broken copy must fail with exactly the two injected errors.
- Response validation: every response in the new API tests, and the M02/M05 responses in `OpenApiContractTest`, is checked for:
  - a documented status;
  - every documented header present;
  - the body valid against the documented schema, with all `$ref`s followed.
- Price list: `at` may be at most 366 days ago and not in the future, and inactive products are left out. Price is checked before rate, as for a purchase.
- Stations: active only for every role, including admins, as the contract says. Ordered by name.
- Card patch: `FuelCardService::applyChanges()` applies the sent limits and status under one card lock and one transaction.
  - A limit that was not sent keeps its value as read under the lock.
  - Limits may drop below usage without the web form's confirmation step, and are audited with `below_current_usage`.
  - Archiving is not offered through the API (422), because it is final.
  - A test forces the status step to fail after the limits were written and shows both roll back.
- Vehicles and drivers: the API Form Requests extend the web ones and add unknown-field refusal and JSON-integer `company_id`/`odometer_km`. Lists are ordered by id and include inactive records.
- Lists share one response builder (`RespondsWithPages`) and one request base (`PaginatedListRequest`), which the transaction list now uses too. Its output is unchanged.
- Simulator (`tools/pos-simulator`): its own Composer project (Guzzle 7), HTTP only.
  - Configuration and credentials come from environment variables only.
  - Given an email and password instead of a token, it issues its own token and revokes it at the end.
  - It checks balance and ledger before and after every scenario.
  - It refuses to start a purchase that the card's remaining quota cannot cover and explains how to get fresh cards.
  - It masks card numbers to their last four characters in its output.
  - Exit codes: 0 passed, 1 unexpected outcome, 2 usage error.
- Fresh fixtures: `php artisan demo:simulator-cards [--tag=]` adds three cards (`FF-SIM-<tag>-MAIN`/`-BLOCKED`/`-TINY`, diesel only, no vehicle or driver) to the demo company, each with a `card.created` audit row without a user.
  - It is guarded like the demo seed (local/testing environment, `DEMO_MODE`), refuses a used tag, and never resets or deletes data.
  - Reason: purchases are immutable and quota is monthly, so a rerun needs new cards, not a reset.
- Simulator test: the new "Integration" PHPUnit suite serves the app with PHP's built-in server (Laravel's router script) on a dedicated database, `fleetfuel_test_integration`, and runs the simulator as a process. The committed-database setup moved from `PosConcurrencyTest` into the shared `UsesCommittedDatabase` trait. `make test` installs the simulator's locked dependencies first, and `make analyse` runs PHPStan level 6 on the simulator with its own `phpstan.neon` (Larastan's paths cover only the app).
- Postman:
  - Replay and conflict reuse the saved original payload.
  - The balance check is relative to the balance read at the start of the run.
  - Role and isolation calls were added.
  - Validated with Newman 6 in the node container, not a real Postman app.
  - Running it exposed a bug in the supplied collection: a top-level `const data` collides with the Postman sandbox's legacy `data` global. The variable is now `purchase`.
- Found, not changed (outside M06): the demo seed's quota-cut scenario is audited as `fuel_card.limits_changed`, while `FuelCardService` writes `card.limits_changed`. The audit history therefore shows two spellings. Left for the M10 review; changing it would also change seeded history.

Template: date, affected decision, old/new behavior, reason, spec/test updates, migration implications.

2026-10-01 (M07): diesel delivery workflow. No product rule changed. The API contract gained an "Implemented behavior (M07)" section; the points below fill in details the specs leave open.

- `DeliveryOrderService` is the only writer of orders, for the web screens, the API and the demo seed. A status change locks the order row, then checks, in order:
  1. the role, through the new `DeliveryOrderPolicy::transition()` (403);
  2. `expected_status` (409 `stale_state`);
  3. the state machine (409 `invalid_transition`);
  4. the details (422).

  Only then does it write the order, one history row and one audit row, in one transaction. The role check does not depend on the current status, so a manager whose pending order was scheduled meanwhile gets 409 `stale_state` and a reload, not 403.
- Audit: transitions are audited as `delivery_order.status_changed`, with `old_values` `{status}` and `new_values` `{status, …fields set}`. That is the action name and value shape the M01 seed already used, so seeded and live history read the same. Creation is not audited: its first history row records who and when (T26 names history for creation and audit for transitions).
- Demo seed: `LedgerFixtureBuilder::createDelivery()` and `transitionDelivery()` now delegate to `DeliveryOrderService::createHistorical()` and `transitionHistorical()`. These apply the same rules, judged at the given time and without an expected status. Refusals keep the builder's `LogicException` form, with the error code. Seeded history is unchanged: the seeder and builder tests pass as before. The builder's unused `$note` parameter was removed. `AuditService::record()` gained an optional time, for seeded history only.
- Windows: the preferred and scheduled windows must start strictly after now and at most 366 days ahead, and end after their start. The horizon is not in the specs; it matches the other 366-day limits and keeps year-9999 dates out. The scheduled window need not match the preferred one: distributor staff decide.
- Inactive company: no new orders. An admin can only pick active companies (422); an inactive company's own manager gets 403 `company_inactive`. Open orders can still be moved on or cancelled, so nothing gets stuck.
- API abilities: the status route uses Sanctum's any-of `ability:deliveries:status,deliveries:write` middleware, and the Form Request requires the ability that matches the role. `openapi.json` documents this as `x-any-abilities`, and the route-parity test now reads `ability:` middleware too.
- API shape: a 201 carries `Location`, as for purchases. Lists include each order's history, eager-loaded, because the documented `Delivery` schema requires it. Lists are newest first.
- Governorate: free text up to 80 characters, like stations. The form suggests Lebanon's nine governorates. M08's SLA report will group by the stored value.
- Web screens:
  - The order page's status forms are ordinary PATCH forms, so they work without JavaScript: they redirect, and a refused rule shows as the page's alert.
  - `resources/js/delivery-actions.js` sends them with jQuery (CSRF header, the form's `expected_status`) and disables the button while the request runs.
  - After a success or a 409, it reloads the server-rendered panel (`GET /deliveries/{id}/panel`) instead of patching the page in JavaScript. On 422 it marks the fields. Messages are inserted as text.
  - `BusinessRuleViolation` now answers web requests that ask for JSON with `{message, code, details}`; before M07, no page used AJAX. It also gained optional `details`, used for `current_status`.
- Tests:
  - The generic worker helpers moved from `PosConcurrencyTest` into `tests/Concerns/RunsConcurrentWorkers.php`. Its 12 tests pass unchanged.
  - T28 runs real HTTP requests in separate processes (`tests/Concurrency/delivery-worker.php`).
- Checking the JavaScript: there is no JavaScript test framework in the MVP. The module was run once in jsdom against the live app (real sessions and CSRF, password from the environment); the harness is not committed. That is not a real browser, and visual and keyboard checks are M09.
- Postman folder 03 is self-contained. It issues its own manager and admin tokens (`delivery_manager_token`, `admin_token`) and revokes both at the end, so it runs alone without making a purchase, and does not interfere with folders 02 and 05.

2026-10-01 (M08): SQL reports and the accounting CSV. No product rule changed. The API contract gained an "Implemented behavior (M08)" section; the points below fill in details the specs leave open, plus one index added after measuring.

- `app/Repositories/ReportRepository.php` holds every report query as raw SQL with bound values. Identifiers (the grouping column, label expression and join for each `group_by`) come from a constant allowlist. An unknown grouping throws before any SQL runs, so the request's validation (422) is the first gate and the repository the second.
- Tenant scope: `App\Support\ReportScope` pins a company manager to their own company whatever the request says. An admin sees every company unless they choose one; station operators get no reports. The Gate abilities `viewReports` and `exportTransactions` admit active admins and managers.
- Snapshots only:
  - grouping by vehicle uses `fuel_transactions.vehicle_id`;
  - tank overfills use `fuel_transactions.tank_capacity_l`;
  - the efficiency estimate uses the odometer recorded at the pump;
  - amounts are the stored LBP/USD values.

  Card assignments, current vehicle capacities and odometers, and today's prices and rates are never joined in. Tests change each of them and show the reports unchanged.
- Rapid fills: `LAG()` over `(transacted_at, id)` per vehicle, reading 30 minutes before the range start, so the first fill in range can be flagged without reading older history. Exactly 30 minutes is not rapid.
- Efficiency estimate: the window gets the fills in range plus each vehicle's last fill before the range (one indexed lookup per vehicle), not the whole history. The division uses brick/math, half up, 2 decimals. It is null, with a reason, without a previous fill, without readings on both fills, or when the reading did not increase.
- Quota exceptions: today's limits against this Beirut month's counter. A card is listed when blocked or when usage is at or over a limit, so "nothing left" counts. Archived cards are left out. The date filter does not apply to this report, and the screen says so.
- Delivery SLA: from the history rows (the first null → pending row and the delivered row), not the order's `created_at`/`delivered_at` columns, filtered by delivery time and grouped by the stored governorate. Hours are computed from summed seconds with brick/math. Timestamp differences use MySQL's `TIMESTAMPDIFF`. The repository throws a clear error on other drivers: SQL Server is S01.
- Shared ledger filters: `App\Support\TransactionFilters` and the `FiltersTransactions`/`FiltersBusinessDates` request traits now serve the transaction list, both CSV routes and the reports, so a list's totals and the CSV of the same filter cover the same rows. The transaction list's behavior is unchanged; its tests pass as before.
- CSV export (`App\Services\TransactionCsvExport`):
  - streamed with `fputcsv` (RFC 4180, empty escape character);
  - read in chunks of `fleetfuel.exports.chunk_size` (500) with a keyset cursor on `(transacted_at, id)` rather than OFFSET;
  - all chunks inside one transaction, which InnoDB's repeatable read turns into a consistent snapshot;
  - no byte-order mark;
  - formula neutralization by a leading apostrophe (`App\Support\CsvCell`), applied to every text column.
- Index: `fuel_transactions (transacted_at, id)` (migration `2026_10_01_120000`), added because `EXPLAIN ANALYZE` on about 64,000 purchases showed all-company reports and every CSV chunk scanning the whole table. A manager's queries keep using `(company_id, transacted_at, id)`.
  - Adding it made MySQL serve the efficiency estimate's per-vehicle lookup through the new index (about 570 ms). Ordering that lookup by `vehicle_id` too returns the same row and restores the vehicle index (28–59 ms).
  - Details, plans and the reproduction steps: [REPORT-QUERY-PLANS.md](REPORT-QUERY-PLANS.md). `php artisan reports:explain [--analyze] [--company=] [--from=] [--to=]` prints the plans; it is read-only.
- Screens: one page per report under `/reports` with a shared date and company filter. The CSV form on the consumption page uses the same dates and company. Card numbers are masked as on the card list. A manager's `company_id` in the URL is ignored, as on the other web lists (the API refuses it).
- Postman folder 04 is self-contained like folder 03, with its own manager token, revoked at the end. It checks that the three groupings, the ledger totals and the CSV agree for a 360-day range computed at run time.

2026-10-01 (M09): Dashboard and UI finish. No product rule and no API changed. The points below fill in details the specs leave open.

- Layout: the dark navy sidebar of the UI spec.
  - It stays in place from 992 px (Bootstrap `lg`) and becomes an off-canvas menu below that: a modal dialog with focus inside, closed with Escape, focus returned to the Menu button.
  - Links are grouped (Overview, Fleet, Reference data, Administration), and each appears only when the page's policy allows it.
  - There is a "Skip to main content" link and a visible focus outline on the dark background. The wrapper is a plain `div`, so the only landmark is the `<nav>`.
- Audit screen (`/audit`, admins): the UI-spec screen deferred in M03 as "later UI work". It lands with the UI finish because F10 needs it in the MVP.
  - Filters: the person (or "system" for the command line), action, record type (an allowlist of morph aliases, never a class name), company and Beirut dates.
  - Values go through `App\Support\AuditDisplay`: fields named like credentials are shown as `[redacted]` and `card_no` is masked. This is a second line of defense; services already audit selected business fields only.
- Transaction list (`/transactions`, every role, scoped with `visibleTo`):
  - It uses the same request rules as the API list and the CSV (`FiltersTransactions`), so the screen, the API and the CSV cover the same rows by construction.
  - A manager's `company_id` is refused here, as on the API and the CSV. The report screens ignore it instead (M08).
  - Invalid filters on the full page return to the unfiltered list with the messages (`$redirectRoute`), never to the broken address.
- Totals and count: one aggregate query (`COUNT` and the `SUM`s) gives both the totals of the whole filter and the row count for the pager (`paginate(..., $total)`). The list therefore runs 6 queries whatever the number of rows; a test counts them, and without eager loading the same page ran 102.
- AJAX design:
  - `/transactions/results` returns JSON `{html, summary, url, from, to}`. The HTML is the same Blade partial as the full page, escaped on the server, so no row template is duplicated in JavaScript. A separate URL keeps the browser from caching a fragment under the page's address.
  - Latest response wins: `createLatestOnly()` aborts the request in flight and ignores any answer that is not the newest, by sequence number. Aborting alone is not enough, because an answer may already be on its way.
  - The filters live in the query string (`history.pushState`); back and forward fetch again. A new filter returns to page 1. The card number is matched exactly, as on the API; the field waits 400 ms after typing stops.
  - An error replaces the results, so stale totals never look current. Network and server errors offer "Try again"; 401/419 offers "Sign in again". Field errors are linked with `aria-describedby` and `aria-invalid`, and a polite live region announces "Loading…" and then the count.
- Web AJAX errors, which M02 left open: browser pages keep Laravel's JSON shapes (422 `{message, errors}`, 401 `{message}`, 419, and 409 business refusals `{message, code, details}`).
  - Scripts show only the application's own 409/422 messages. Other statuses get a fixed message, because a debug-mode body can carry exception details.
  - A disabled user's page script now gets 401 with the reason instead of the sign-in page (`EnsureUserIsActive`).
- Error pages: 403, 404, 419, 429 and 5xx in the application's words, on a minimal layout that reads no session user and runs no query, so they render even with the database down.
  - Laravel ships its own 429, 500 and 503 views, which would win over the 4xx/5xx fallbacks, so those three files extend ours.
  - The 404 wording is the same for a missing record and another company's record.
  - Observed in Chrome: Laravel 13 accepts the browser's `Sec-Fetch-Site: same-origin`, so a same-site form with an outdated token is not a 419. A signed-out user is sent to sign in and then back to the page they came from. The 419 page remains for clients without that header; its test runs the real CSRF middleware.
- Dashboard:
  - this month next to last month, in one grouped query (useful early in a month, and with demo data seeded a few days earlier);
  - quota warnings from `ReportRepository::quotaExceptions` (the first five, with a link to the report);
  - the first five open deliveries by scheduled or preferred start;
  - the USD/LBP rate in use with its source and attribution, or a warning when none is valid (POS purchases are then refused, never converted at a guessed rate).

  A test checks that its number of queries does not grow with the data.
- Rate provenance: a badge in words (fixture, provider, manual override) on the transaction list, the purchase page and the dashboard.
- A phone-layout bug found in the browser check, present since M03: screen-reader text in table cells (`.visually-hidden`, absolutely positioned) escaped `.table-responsive` because no ancestor was positioned. At 390 px it widened the dashboard, transactions, card, vehicle and product pages to up to 782 px. `.table-responsive { position: relative; }` keeps it inside the horizontal scroll.
- JavaScript tests use Node's built-in runner (`npm test`); no package was added. The tested modules (`resources/js/lib`) import nothing. `make test` runs them after PHPUnit, so `make verify` and CI do too.
- Screenshots are WebP files in `docs/screenshots`, taken on a throwaway seeded copy of the app, as documented there.

2026-10-01 (M10): Release verification. No product rule changed. The API contract changed only in documentation (`openapi.json` 0.10.0). Evidence is in docs/RELEASE-VERIFICATION.md.

- The demo seed's quota cut is now `card.limits_changed`, written by `FuelCardService::updateLimitsHistorical()` (the same rules and audit row as a live change, judged against the usage of its own month). Existing local databases keep their earlier `fuel_card.limits_changed` row: audit history is append-only and is not rewritten. Reseeding is a separate, destructive choice (`migrate:fresh --seed`).
- Failed-query messages are masked: the MySQL connection sets `mask_bindings_in_exception_messages`, so the logged SQL keeps its `?` placeholders instead of card numbers, emails or session IDs. A framework option was preferred to a custom exception reporter: it keeps the standard log entry and stack trace. MySQL's own duplicate-key messages still quote the duplicated value (a limitation, recorded).
- A contention 503 documents its `Retry-After` header in `openapi.json` as optional (`rate_unavailable` sends none). The other documented headers (`Location`, `Idempotency-Replayed`, `Content-Disposition`, `Retry-After` on 429) are marked `required`, and the contract checker requires exactly the required ones.
- A second copy on one machine: `compose.yaml` keeps the project name `fleetfuel`, so existing volumes and the image keep their names. `COMPOSE_PROJECT_NAME` in `.env` overrides it. `prepare-env.sh` writes `COMPOSE_PROJECT_NAME` and `APP_PORT` (with `APP_URL`) into a new `.env` when they are set for the first `make setup`. The PHP image is named `<project>-app:dev`. `docker/bin/check-compose-project.sh` stops `make setup`, `make up` and `make test` when the project's containers belong to another directory (the Compose `working_dir` label). It cannot see a project whose containers were removed; a copy started then fails at migration with "access denied", because the volume keeps the first copy's passwords.
- The local disk's `serve` option is off: the app stores no files, so its signed download and upload routes (`storage/{path}`, observed as unused in M02) are no longer registered. Every route except `GET /`, `GET`/`POST /login`, `GET /up`, `GET /health` and `POST /api/v1/auth/token` must refuse a guest; a test requests each one.
- `make audit` checks the Composer (app and simulator) and npm lockfiles against published advisories. It is not part of `make verify`, because it needs the network and its result changes when new advisories appear; CI runs it as a separate step. Patch releases without advisories are not applied during release verification.
- T35 is tested in-process with PHP's built-in server: production environment, debug off, `php artisan optimize` caches in a temporary directory (`APP_*_CACHE`, `VIEW_COMPILED_PATH`), a dedicated test database. A production image and host are M11.

2026-10-02 (M11): Shipping preparation. No product rule changed. The production commands are in docs/RUNBOOK.md, and `make rehearse` verifies them.

- **Production image** (`docker/production/Dockerfile`), D20/D21:
  - one container runs nginx and PHP-FPM side by side as the non-root user `app` (UID 10001), so any container host can run it, not only multi-container ones;
  - the entrypoint starts both and exits when either stops, so the host restarts the container. It runs `php artisan optimize` from the environment on every start and never migrates or seeds;
  - the code is root-owned and read-only for `app`;
  - the build stages install locked `--no-dev` Composer packages and build the Vite assets; Composer, git and Node are not in the final image;
  - `.dockerignore` keeps env files, tests, docs and tools out of the build context.
- **nginx** passes the client's Host header unchanged. Debian's `fastcgi_params` (nginx 1.26) sets `HTTP_HOST` to `$host` as a security workaround, which dropped the port from every generated URL: the rehearsal's sign-in redirect went to `http://127.0.0.1/dashboard`. nginx 1.30's `$request_port` would fix it properly, but trixie ships 1.26. So the production config lists the standard FastCGI parameters itself, matching the official nginx image used in development. Forged Host headers stay low-risk here:
  - the app has no password-reset links and no caching layer;
  - behind the HTTPS proxy, only `TRUSTED_PROXIES` may set the forwarded host.
- **`TRUSTED_PROXIES`** (config `fleetfuel.trusted_proxies`, applied with `TrustProxies::at()` in `AppServiceProvider`): `X-Forwarded-*` headers are believed only from the listed addresses. The production template sets `SESSION_SECURE_COOKIE=true`.
- **The default connection is now `mysql`**, not the skeleton's `sqlite`. A deployment that forgets `DB_CONNECTION` must fail against MySQL, not fall back to SQLite (D03).
- **Demo data in production:** `php artisan demo:seed --force` is allowed in the `production` environment, for a dedicated public-demo deployment. It still needs `DEMO_MODE=true`, a `DEMO_PASSWORD` and an empty database. Without `--force`, and in any other environment except local and testing, it refuses; `db:seed` never seeds demo data in production. Which accounts the public gets is the owner's decision. The runbook recommends deactivating the admin and the operators on a public demo.
- **`compose.production.yaml`** is the reference single-host stack: `app`, exactly one `scheduler` and `mysql`, with no bind mounts. MySQL's settings come from command-line flags instead of a mounted file. `FLEETFUEL_IMAGE` and `FLEETFUEL_ENV_FILE` select the image and the env file.
- **Backups:** `backup.sh` runs `mysqldump --single-transaction` with the application's own account, and the password goes through `MYSQL_PWD`, never the command line.
- **Restore checks:** `restore-check.sh` restores into a throwaway MySQL server and checks the copy with the production image (`migrate:status`, `usage:reconcile`, row counts and `CHECKSUM TABLE`). Encryption and off-host storage are the operator's steps; the runbook gives a `gpg` command, which was not run here.
- **`make rehearse`** (also a CI step) deploys the image locally in the `fleetfuel-rehearsal` Compose project with generated secrets, tests it end to end and removes everything. It uses `SESSION_SECURE_COOKIE=false`, because it is plain HTTP on 127.0.0.1.
- **README** replaced with an implementation-based one. `docs/DEMO-SCRIPT.md` and `docs/PORTFOLIO.md` added. The license is left unchosen: that is the owner's decision.
