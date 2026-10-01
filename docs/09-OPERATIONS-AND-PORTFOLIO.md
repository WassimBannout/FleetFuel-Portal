# Shipping, maintenance and portfolio

This is a build/runbook specification. M11 replaces planned commands with tested commands for the chosen deployment. No hosting service, live demo, Git remote or CI result is currently provisioned.

## Local operational checklist

Use `make setup`/`make up`/`make down` after M00. Preserve development volumes. Document the exact demo seed/reset command, but keep it separate from ordinary startup; demo reset operates only on explicitly selected local/demo databases. Keep `.env` and tokens out of Git. Print one-time token output only when the operator asks for it, never in CI logs.

Troubleshooting order: identify failing service/route; inspect safe logs; verify env/service hostnames and DB readiness; reproduce on fixtures; write a failing test for behavioral bugs; fix and rerun relevant checks. Record actual symptoms and fixes. Avoid deleting volumes or bypassing authorization/platform checks as a diagnostic shortcut.

| Symptom | First checks |
| --- | --- |
| Composer unavailable on host | Use `app`/tooling container; don't change machine-wide PHP to solve a container problem |
| Docker socket inaccessible | Check daemon/context/user access; explain required local permission change |
| Web port occupied | Change documented local host port/APP_URL together |
| A second checkout on the same machine | Give it its own `COMPOSE_PROJECT_NAME` and `APP_PORT` (README, "A second copy on one machine"); `make setup` refuses to take over another checkout's containers |
| Docker Desktop: "mounts denied … is not shared from the host" | The checkout is outside the folders Docker Desktop shares with its VM (by default the home directory; `/tmp` is not shared). Move it under your home directory, or add the folder in Docker Desktop's Resources → File sharing |
| Database connection fails | Service hostname `mysql`, health, credentials, correct DB; never localhost inside app container |
| APP_KEY or cached env wrong | Preserve key, inspect config cache; do not regenerate a key on every restart |
| Assets missing | Run locked frontend build, check manifest and mounted directory |
| Every transaction has missing rate | Check fixture/live mode, eligible observation timestamp and expiry; no invented fallback |
| Quota differs from ledger | Run read-only reconciliation; inspect service bypasses before proposing a repair |
| Tests affect demo data | Stop immediately and repair test DB guard/isolation before rerunning |

## Production release preparation

1. Choose a PHP/container host plus durable MySQL after checking the provider's current official limits, costs, storage and scheduler support. The research's free-tier claims are not deployment guarantees.
2. Build a production image with locked Composer dependencies (`--no-dev`) and compiled assets from a build stage. Serve `public/` with nginx/PHP-FPM or an explicitly chosen supported equivalent; no host source bind mount. Run with appropriate non-root ownership.
3. Provide secrets via the host secret manager/environment: APP_KEY, DB credentials, URL and app settings. Set production environment, debug false, HTTPS, secure cookies and trusted proxies narrowly. Turn off public registration and uncontrolled demo administration.
4. Confirm DB readiness, back up, and run migrations as a single explicit release step. Do not migrate/seed destructively from every container's startup. Run framework cache commands suitable for the built app. Keep writable storage/cache persistent as needed.
5. Run exactly one scheduler (or documented host cron) and monitor `rates:sync` success, eligible-rate expiry and database reconciliation. A queue worker is unnecessary until a queued feature exists.
6. Configure liveness/readiness checks, safe logs and retention. Validate HTTP headers/session settings against the actual app; avoid a blanket CSP that breaks local compiled scripts.
7. Verify role isolation, valid/replayed/declined POS, a delivery lifecycle, report totals and CSV against dedicated demonstration data. Confirm deployed assets and external FX failure behavior.
8. Record actual image version/commit, migration result, URL, health response and smoke-test time. Rollback plan uses the previous image with schema compatibility; do not automatically reverse migrations that could lose data.

Backup/restore: document tested database backup commands, encrypted/off-repo storage, retention and a restore into a disposable database. Preserve the application key securely with deployment secrets. A successful dump without a tested restore is incomplete evidence. Never commit backups to the repository.

## Public demo policy

Use only fictional data. Decide whether the demo is private/password-protected or offers limited demo accounts. A public unrestricted admin and published write-capable station token are inappropriate defaults. Give reviewers a limited manager account, a private guided admin demo, or a controlled short-lived demo session. If demo reset is scheduled, show its cadence and isolate the demo from any real dataset. Limit API mutation volume and never store a live token in README/Postman tracked exports.

If the app uses fixture rates, label them plainly. If live provider data is used, display source attribution and observation time. A video/screenshots provide fallback evidence if live hosting is pending or unavailable; do not label a recording as a live deployment.

## README to publish after implementation

Replace the placeholder README with:

- Title and one-sentence problem; verified feature status and a generic fictional-domain disclaimer.
- Actual live-demo link/access method or explicit pending status; screenshots of real screens.
- Stack versions, architecture/ER diagrams and a quick start tested from a clean clone.
- Test/lint/build commands and real test results/CI link. Coverage only if measured.
- API base URL/auth/replay example, OpenAPI and Postman links, simulator instructions and fictional fixture credentials.
- Currency/rounding, atomic quota/idempotency, tenant-security and historical-snapshot decisions.
- A few real SQL examples/query plans and relevant indexes. SQL Server support only if S01 passed.
- Deployment/scheduler/backup instructions, limitations and roadmap, provider attribution.
- License chosen by the owner. Don't invent a copyright holder or license badge before that choice.

Keep START_HERE/CLAUDE/specs useful for maintainers, but make the main README lead with the finished product rather than the build kit.

## Git and review workflow

Initialize Git in M00. Use small actual commits at completed slices, not artificially backdated history. Each milestone may use `feat/...` or `chore/...` branches. When the user requests GitHub publication, use their chosen repository; create a PR with the supplied template and actual checks. Branch protection must be set in the hosting service (it cannot be enforced by a local markdown file). Require the real CI job once it exists. Never claim a human reviewed a self-review.

Include one honest maintenance story: a real bug found during testing/review, the regression test and the fix. A later deliberately messy legacy example must be clearly labeled as an exercise; do not present it as prior professional work.

## Demo narration (about three minutes)

1. **0:00–0:25** — Explain the fictional distributor problem and three roles.
2. **0:25–1:00** — Show manager's fleet/card quotas and tenant boundary.
3. **1:00–1:45** — Run POS success, exact retry and over-quota decline; show one ledger row and updated balance.
4. **1:45–2:15** — Show delivery timeline and a SQL report/CSV.
5. **2:15–3:00** — Explain stored FX snapshots, card locking and one meaningful test/bug fix; state what is simulated.

Capture a screen recording only after rehearsing. Keep real secrets/account details out of the recording.

## Résumé bullets — use only after implementation

Replace bracketed values using actual evidence; omit unbuilt clauses:

- Built FleetFuel Portal using Laravel/PHP, MySQL and Bootstrap/jQuery, with corporate fleet management, role-based tenant isolation and diesel-delivery workflows.
- Developed a Sanctum-authenticated REST API consumed by a standalone PHP POS simulator, with atomic quota enforcement, idempotent retries and a published Postman/OpenAPI contract.
- Integrated a scheduled external exchange-rate feed with validated caching/fallback and immutable USD/LBP transaction snapshots; implemented SQL consumption/anomaly reports and scoped CSV exports.
- Automated [measured count] meaningful tests in GitHub Actions and Dockerized the application; [include SQL Server compatibility only if S01 evidence exists].

The original research contains an application-email example. Adapt only truthful features and personal details; sending it is a separate user action, not part of building this project.

## Interview preparation

Be ready to explain: request→controller→request/policy→service→database→resource; interfaces and dependency injection using FX provider; authentication versus authorization; SQL parameter binding/CSRF/XSS; a card-lock race; uniqueness versus replay hashing; DECIMAL versus float; why rate snapshots matter; GROUP BY versus HAVING; LAG with tie ordering; indexes and N+1 queries; test isolation; a real debugging workflow; and actual SQL dialect differences if S01 is complete.

Use `prompts/REVIEW-DEBUG-LEARN.md` to practice. Ask for questions first, answer yourself, then ask Claude to critique your answer against the implemented code.
