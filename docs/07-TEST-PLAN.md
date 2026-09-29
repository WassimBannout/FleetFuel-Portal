# Verification and acceptance matrix

Use PHPUnit. Test isolated arithmetic/month-boundary logic as units and observable HTTP/service behavior as features. MySQL is the required integration database; do not use SQLite to claim quota concurrency or dialect correctness. All external HTTP is faked in automated tests; fail unexpected network calls. Freeze time per test. Tests assert decimal strings and exact stored rows/counters, not approximate floats.

## Required cases

| ID | Scenario and assertion | Milestone |
| --- | --- | --- |
| T01 | Fresh setup boots; health responds; repeated setup preserves key and data | M00 |
| T02 | Migrations/seed on MySQL; foreign keys and unique refs enforced; seed reconciliation exact | M01 |
| T03 | Login/logout/session regeneration; invalid login generic; login throttle; disabled account denied | M02 |
| T04 | Manager A cannot list/show/update/export Company B data via URL, filters or AJAX | M02 onward |
| T05 | Operator cannot change quotas or use another station identity; manager cannot write prices | M02 onward |
| T06 | Missing/invalid/revoked/expired token 401; missing ability or wrong role 403 | M02/M05 |
| T07 | Invalid cross-company assignments rejected; immutable owner and used-card assignment enforced | M03 |
| T08 | Quota/block/deactivation audit records selected before/after fields; no secrets logged | M03 |
| T09 | Decimal example yields 1600000.00 LBP / 17.88 USD; half-up edges and overflow/excess-scale inputs | M04 |
| T10 | Price chosen at exact effective boundary; missing price 422; later price never changes old purchase | M04/M05 |
| T11 | FX success/schema errors/timeout/5xx/429; retry count bounded, repeat observation deduplicated | M04 |
| T12 | Valid cached/manual rate precedence; expired/missing rate 503; live mode ignores fixture rates | M04/M05 |
| T13 | New POS request 201, immutable snapshots correct, counter incremented once | M05 |
| T14 | Exact and canonically equivalent replay 200 same ID; changed payload 409; same ref at other station allowed | M05 |
| T15 | Replay succeeds after card blocked, price changed, quota reduced or event ages >72h; bad token still fails | M05 |
| T16 | Blocked/inactive card, inactive owner/assignment/product, wrong fuel all decline without mutation | M05 |
| T17 | Exact liter/USD limit accepted; smallest excess rejected; null unlimited and zero limits distinguished | M05 |
| T18 | Zero/negative/numeric-json/exponent/excess-scale liters rejected; future, old or offset-free time rejected | M05 |
| T19 | Beirut month boundary and DST conversion: event goes to correct counter; previous-month late arrival works | M05 |
| T20 | Concurrent different-ref requests cannot jointly exceed a card quota; only allowed spend committed | M05 |
| T21 | Concurrent identical refs create one ledger/counter increment; conflicting refs produce winner +409 | M05 |
| T22 | Concurrent quota edit/block versus ingestion serializes by card lock; no stale limit application after edit wins | M05 |
| T23 | Forced mid-transaction error rolls back both ledger and counter; reconciliation detects an intentional mismatch | M05 |
| T24 | API list pagination/totals/scopes and exact success/error shape match OpenAPI; throttles preserve Retry-After | M06 |
| T25 | Simulator + Postman happy/replay/conflict/blocked/quota scenarios produce asserted HTTP results | M06 |
| T26 | Delivery creation has initial history; every permitted transition writes one atomic history/audit row | M07 |
| T27 | Invalid transition, stale expected state, terminal edits and manager escalation denied; no extra history | M07 |
| T28 | Concurrent delivery transitions using same expected state permit only one mutation | M07 |
| T29 | Consumption SQL matches known totals/group IDs; duplicate names do not merge companies/products | M08 |
| T30 | Quota exceptions arise after reduction; zero remaining; declined POS does not create consumption | M08 |
| T31 | Rapid-fill predecessor before filter start included; tie ordering stable; missing tank/odometer handled | M08 |
| T32 | CSV tenant/date scope exact, full filtered set exported, proper quoting and formula neutralization | M08 |
| T33 | Delivery SLA excludes non-delivered; uses history boundaries; top stations stable tie order | M08 |
| T34 | AJAX latest response wins; totals agree with export; accessible empty/error states, keyboard/mobile checks | M09 |
| T35 | Clean checkout quality gates, production asset build, env/cache boot and production debug disabled | M10/M11 |
| T36 | Full migrations and relevant tests on actual SQL Server plus both dialect query results | S01 only |

## Concurrency test design

Use independent database connections/processes and an explicit barrier so requests overlap. Do not use a sequential loop, mocked lock or Laravel test-wide transaction to claim concurrency. Commit fixture setup before starting workers; use a dedicated isolated integration database/process cleanup. Assert final ledger count, used_l/used_usd and response codes.

For T20, create a card with 100.00 L quota and 80.00 L used, start two different-ref 15.00 L requests together: one 201, one 403, final 95.00 L. For T21, submit identical payloads with the same ref concurrently: one 201 and one 200, one row. Also reuse one station/ref against two distinct cards to exercise the unique-index race independently of the card lock.

For T22/T28, use controlled locks/barriers and bounded timeouts to test both meaningful orderings. A failing worker must not hang CI. Run concurrency tests serially as a suite while the workers inside each test are concurrent. Explicitly isolate DB names if running other tests in parallel.

## Security verification

Test representative CSRF behavior through enabled middleware or browser smoke tests: Laravel feature tests commonly disable CSRF, so a passing default HTTP test alone is not evidence. Check escaped malicious names in HTML/JS, bound SQL with adversarial filter input, denied mass assignment and absence of passwords/tokens in API/audit responses. Rate limiter tests use isolated cache state and time travel.

Test the role/tenant matrix on each data surface as it is implemented: lists, counts, selectors, detail, mutation, reports, exports. An authorization helper's unit test is insufficient to prove a controller used it. Avoid live credentials and live provider calls in CI.

## CI progression

M00 adds a small runnable workflow; M01 adds MySQL-backed migration/seed checks; later milestones expand it. By M10 a PR checks Pint, Larastan, all PHPUnit suites including MySQL concurrency, and frontend production build. Use locked dependencies, service health waits, least-privilege workflow permissions and disposable CI credentials. Do not add a failing placeholder SQL Server job to the default MVP pipeline.

Record exact commands, outcomes and real counts in PROGRESS.md. No fixed coverage/test-count target justifies trivial tests; prioritize the matrix above. A test only counts as verified on a database that was actually used. Mark unavailable checks as not run and preserve their acceptance gate.

## Manual review after automated checks

M09 performs the five-minute walkthrough in UI-SPEC plus keyboard/mobile/error states. M10 rehearses documented setup from a clean copy without untracked secrets, using a separate Compose project, ports and volumes; do not reset the working developer database. M11 rehearses production backup/restore and deployment with disposable data before a live release.
