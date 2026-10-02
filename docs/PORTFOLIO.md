# Portfolio notes

Résumé bullets and interview preparation, built only from what this repository contains and what its checks measured (docs/09-OPERATIONS-AND-PORTFOLIO.md, "Résumé bullets"). Update the numbers if the project changes.

The project was implemented with AI assistance (Claude Code) under your direction, milestone by milestone (START_HERE.md). Be ready to say so, and to explain any part of it in your own words. The interview topics below point at the code to study.

## Résumé bullets

- Built FleetFuel Portal, a Laravel 13 / PHP 8.3, MySQL 8.4 and Bootstrap / jQuery web application for corporate fleet fuel cards, with role-based tenant isolation and an audited diesel-delivery workflow.
- Developed a Sanctum-authenticated REST API with atomic quota enforcement under concurrent requests and idempotent retries. A standalone PHP POS simulator consumes it, and an OpenAPI 3.1 contract and a Postman collection are checked against its real responses.
- Integrated a scheduled exchange-rate feed that stores validated, deduplicated observations, each usable for up to 72 hours, with an explicit no-rate failure mode. Stored immutable USD/LBP price and rate snapshots on every purchase, and wrote SQL consumption and anomaly reports (window functions) with a scoped, formula-safe CSV export.
- Automated 593 PHPUnit tests and 14 JavaScript tests in GitHub Actions, including multi-process MySQL concurrency tests. Dockerized development, plus a non-root production image whose scripted deployment rehearsal verifies backup and restore.

Do not add:
- a live demo URL until one exists;
- SQL Server support (not attempted);
- a coverage percentage (not measured);
- any claim that this is production software used by a real company.

## Measured facts to quote

| Fact | Where it comes from |
| --- | --- |
| 593 PHPUnit tests, 6,027 assertions, 14 JavaScript tests | `make verify`, 2026-10-02 (docs/PROGRESS.md) |
| The T01–T35 acceptance cases each mapped to tests | docs/RELEASE-VERIFICATION.md |
| Two stations, 20 L left on a card, 15 L each: one 201, one 403 | `tests/Concurrency/PosConcurrencyTest.php` |
| 20 L at 80,000 LBP and 89,500 LBP/USD gives 1,600,000.00 LBP / 17.88 USD | `tests/Unit/Support/FuelAmountsTest.php` |
| One index added after measuring plans on 64,032 purchases | docs/REPORT-QUERY-PLANS.md |
| The transaction list runs 6 queries for 1 or 25 rows (102 without eager loading) | `tests/Feature/Transactions/TransactionScreensTest.php`, M09 log |
| The production rehearsal: 55 API requests and 113 assertions, and a restore with identical checksums in 24 tables | `make rehearse` (docs/RUNBOOK.md) |

## Interview topics, with the code to read

| Topic | Read |
| --- | --- |
| A request from route to response | `routes/api.php` → `Api\V1\TransactionController::store` → `StorePosTransactionRequest` → `FuelTransactionService::ingest` → `TransactionResource` |
| Interfaces and dependency injection | `App\Contracts\ExchangeRateProvider`, bound per mode in `AppServiceProvider` |
| Authentication versus authorization | Fortify sign-in and Sanctum tokens; Policies, the token abilities and the tenant-scoped route bindings in `AppServiceProvider` |
| SQL injection, CSRF and XSS | Bound values and allowlisted identifiers in `ReportRepository`; `tests/Feature/Auth/CsrfProtectionTest.php`; escaped Blade (`{{ }}` only) |
| The card-lock race | `FuelTransactionService::ingestUnderCardLock` and `tests/Concurrency/PosConcurrencyTest.php` (separate processes) |
| Unique index versus replay hash | `(station_id, external_ref)` in the ledger migration; `App\Support\PosRequestHash` |
| DECIMAL versus float | `App\Support\FuelAmounts`, `brick/math` |
| Why rate snapshots matter | The rate columns of `fuel_transactions`; `test_new_prices_and_rates_never_change_past_totals` |
| GROUP BY, and LAG with ties | `ReportRepository::consumption` (groups by ID, never by name, so two companies with one name stay apart) and `::rapidFills` (`LAG` ordered by time, then ID, so ties are stable). No report needs `HAVING`; be ready to explain where it would apply |
| Indexes and N+1 queries | docs/REPORT-QUERY-PLANS.md; `tests/Concerns/CountsQueries.php` |
| Test isolation | `tests/TestDatabaseGuard.php`, `tests/Concerns/UsesCommittedDatabase.php` |
| A real debugging workflow | docs/DEBUGGING-STORY.md |
| Operating it | docs/RUNBOOK.md, "Failure handling" |

Practise with `prompts/REVIEW-DEBUG-LEARN.md`, "Understand code before advancing": ask for questions first, answer them yourself, then have your answers critiqued against the code.

## Not done here

- No application email was written or sent. Sending one is your own decision.
- No recording exists yet. Record with docs/DEMO-SCRIPT.md once you have rehearsed.
