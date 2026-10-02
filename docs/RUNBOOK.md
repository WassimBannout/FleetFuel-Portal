# Production runbook

How to build, deploy, operate, back up and restore FleetFuel Portal. Every command here was run in the local production rehearsal (`make rehearse`, see "What has been verified" below), except where a step says it needs a real host. **No live deployment exists yet**: choosing a hosting provider, a domain and the public demo policy is the owner's decision, and the deployment record at the end is still empty.

## What runs

- **One image**, built by `docker/production/Dockerfile`:
  - PHP 8.3 with PHP-FPM and nginx in one container, listening on port 8080;
  - the locked production Composer packages (no dev tools) and the compiled Vite assets;
  - running as the non-root user `app` (UID 10001). The code is read-only for that user; only `storage/` and `bootstrap/cache/` are writable;
  - no `.env` file inside: settings come from environment variables.
- **The entrypoint** caches configuration, routes, events and views from the environment (`php artisan optimize`) on every start. It never migrates or seeds. With the default command `web` it runs PHP-FPM and nginx; if either stops, the container exits and the host restarts it. Any other command runs as given.
- **Three ways to run the image:**
  - `web` (default): the web server;
  - `php artisan schedule:work`: the scheduler, exactly one instance;
  - one-off release commands such as `php artisan migrate --force`.
- **MySQL 8.4**: durable storage, `utf8mb4`, server clock in UTC. Managed or self-hosted, with backups.
- **Health:**
  - `GET /up` is liveness (the app boots, no database); the image's health check uses it;
  - `GET /health` is readiness: JSON, 200 with the database, 503 without, never with details.

`compose.production.yaml` runs exactly this on a single Docker host: `app`, `scheduler` and `mysql`, with no source bind mount. A platform that runs containers from an image uses the same image, the same variables and the same two processes.

## Configuration

Copy `.env.production.example` to `.env.production` (ignored by Git) on the deployment machine, or enter the same variables in the host's secret manager. Never commit real values.

| Variable | Production value |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_KEY` | Generated once, kept in the secret manager. It signs cookies, sessions and CSRF tokens; no database column is encrypted with it. Losing it signs everyone out but loses no data |
| `APP_URL` | The public HTTPS address |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | The MySQL 8.4 database. The account needs all privileges on that database only |
| `SESSION_SECURE_COOKIE` | `true`: session cookies only over HTTPS |
| `TRUSTED_PROXIES` | The HTTPS proxy's or load balancer's addresses (comma-separated IPs or CIDR ranges). Only their `X-Forwarded-*` headers are believed. Empty trusts nobody |
| `LOG_CHANNEL`, `LOG_LEVEL` | `stderr`, `info`: the host collects the container output |
| `EXCHANGE_RATE_MODE` | `fixture` (labelled synthetic rates) or `live` (ExchangeRate-API, attribution shown on the screens) |
| `DEMO_MODE`, `DEMO_PASSWORD` | `false` and empty for real data. A public demo deployment only: see "Demo deployment" |

Generate a key without a running stack:

```bash
make prod-image
docker run --rm --entrypoint php fleetfuel-portal:local artisan key:generate --show
```

## First deployment

On a single Docker host with `compose.production.yaml` (the same steps apply to any container host):

1. Build the image from the release commit and tag it with that commit:

   ```bash
   docker build -f docker/production/Dockerfile -t fleetfuel-portal:$(git rev-parse --short HEAD) .
   ```

   With a registry, push this tag; never deploy an untagged `latest`.
2. Create `.env.production` from the template, as above, and set `FLEETFUEL_IMAGE` to the tag in your shell or the env file.
3. Start the database and wait until it is healthy:

   ```bash
   docker compose --env-file .env.production -f compose.production.yaml up -d --wait mysql
   ```

4. Run the migrations, once, as an explicit release step:

   ```bash
   docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan migrate --force
   ```

5. Add the reference products. With `DEMO_MODE=false` this adds nothing else:

   ```bash
   docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan db:seed --force
   ```

   For a demo deployment, run `php artisan demo:seed --force` instead (see "Demo deployment").
6. Create the first administrator for real data. The password is typed at a hidden prompt, so this needs a terminal (`-it`):

   ```bash
   docker compose --env-file .env.production -f compose.production.yaml run --rm -it app php artisan users:create admin@your-company.example --name="Administrator" --role=admin
   ```

7. Start the web server and exactly one scheduler:

   ```bash
   docker compose --env-file .env.production -f compose.production.yaml up -d --wait app scheduler
   ```

8. Put the HTTPS proxy in front of port 8080. Set `TRUSTED_PROXIES` to its address and HSTS on the proxy, then restart `app`.
9. Smoke test, and fill in the deployment record below:
   - `/health`;
   - sign in;
   - for a demo, one POS purchase and its replay, a delivery, a report and its CSV (the Postman folders run with Newman, as in `docker/production/rehearse.sh`).

## Releasing an update

1. Build and tag the new image (step 1 above).
2. Back up the database ("Backups").
3. Migrate with the **new** image: `docker compose … run --rm app php artisan migrate --force`.
4. Recreate `app` and `scheduler` on the new tag: `FLEETFUEL_IMAGE=fleetfuel-portal:<new> docker compose … up -d --wait app scheduler`.
5. Smoke test.

Write migrations so that the previous image still works with the new schema: add columns and tables first, and remove them only in a later release. That is what makes the rollback below safe.

## Rollback

Redeploy the previous image tag:

```bash
FLEETFUEL_IMAGE=fleetfuel-portal:<previous> docker compose --env-file .env.production -f compose.production.yaml up -d --wait app scheduler
```

- Do not run `migrate:rollback` automatically: a down migration can drop data.
- If a release damaged data, stop `app` and `scheduler`, restore the last backup into a **new** database ("Restore"), check it, and point `DB_DATABASE` at it.
- Not yet rehearsed with two real releases: only one release exists. The mechanics (a new tag, recreate, health) are the same as a release.

## Scheduler

- Exactly one scheduler: the `scheduler` service (`php artisan schedule:work`).
- A host without long-running workers can use cron instead, calling `php artisan schedule:run` every minute in a one-off container. Never both.
- It runs `rates:sync` daily at 01:00 UTC (`withoutOverlapping`).
- Watch the admin page **Exchange rates**. It shows the last attempt, the last success, any failure reason and whether a valid rate exists.
- A rate is valid for 72 hours. Without one, POS purchases are refused with 503 `rate_unavailable`, never converted at a guessed rate.
- An admin can enter a manual override (at most 72 hours) while the provider is down.
- To run the sync by hand: `docker compose … exec scheduler php artisan rates:sync --force`.

## Backups

```bash
bash docker/production/backup.sh /srv/fleetfuel-backups
```

- It runs `mysqldump --single-transaction` inside the `mysql` container with the application's account, so the app stays up.
- It writes `fleetfuel-<UTC time>.sql.gz` and a `.sha256` file, readable only by the owner (`umask 077`). A failed dump leaves no file.
- The file contains every business record. Encrypt it before it leaves the machine, store it off the host, and never commit it (`/backups/` is ignored by Git). For example:

  ```bash
  gpg --symmetric --cipher-algo AES256 fleetfuel-20261002T090000Z.sql.gz
  ```

- Schedule it with the host's cron, before every release and at least daily. Keep, for example, 7 daily, 4 weekly and 3 monthly copies.
- Keep `APP_KEY` and the database password in the secret manager, not with the backups.
- With another env file or project, set `FLEETFUEL_ENV_FILE` and `COMPOSE_PROJECT_NAME`.

A backup counts only once a restore of it has been checked.

## Restore

**Check a backup** without touching the live database:

```bash
bash docker/production/restore-check.sh /srv/fleetfuel-backups/fleetfuel-20261002T090000Z.sql.gz fleetfuel-portal:<tag>
```

It does the following:
- verifies the `.sha256`;
- loads the dump into a throwaway MySQL server;
- checks with the production image that every migration is recorded and that the monthly quota counters reconcile with the ledger;
- prints each table's row count and checksum;
- removes the throwaway server.

**Restore for real** (destructive; decide first):

1. Stop the writers: `docker compose … stop app scheduler`.
2. Load the dump into a **new** database on the server. Prefer this to overwriting the current one, which you then keep until the restored copy is confirmed. Then set `DB_DATABASE` to the new name.
3. Run `php artisan usage:reconcile` and `php artisan migrate:status` against it.
4. Start `app` and `scheduler`, and smoke test.

## Health checks and logs

- **Liveness:** `GET /up` (and the image's own health check, every 30 s).
- **Readiness:** `GET /health`. Use it for load-balancer routing and alerts.
- **Logs:** everything goes to the container output, and the host keeps it:
  - nginx access and error logs;
  - PHP-FPM;
  - Laravel at `info` and above.

  Each log entry and each API error carries a request ID (`X-Request-Id`).
- **What never appears in logs:** passwords, tokens and full card numbers. Failed queries are logged with `?` placeholders instead of their values (docs/DEBUGGING-STORY.md). The rehearsal checks the logs for the demo password and the app key.

## Failure handling

| Failure | What the app does | What to do |
| --- | --- | --- |
| Database down | `/health` 503; pages show the plain error page; API calls 500 `internal_error`. Nothing is half-written: every change runs in a transaction | Restore the database service; nothing to repair afterwards |
| Exchange-rate provider down | Keeps the stored rates (each valid 72 hours) and records the failure. After expiry, new POS purchases get 503 `rate_unavailable` | Check **Exchange rates**; enter a manual override (at most 72 hours) |
| Concurrent POS requests on one card | Each waits for the card lock. A deadlock or lock timeout is retried 3 times, then 503 `temporarily_unavailable` with `Retry-After: 1`, nothing recorded | Nothing: the POS repeats the same request, and the replay rule makes repeats safe |
| PHP-FPM or nginx crashes | The container exits; the image health check also fails | The host restarts it; investigate the logs |
| A bad release | | Roll back to the previous tag ("Rollback") |
| A quota counter disagrees with the ledger | `php artisan usage:reconcile` lists the differences and changes nothing | Investigate before any repair; never edit the ledger |
| A leaked secret | | A new `APP_KEY` signs everyone out. Change the database password. `users:deactivate` revokes an account's API tokens |
| The scheduler stopped | No new rates; stored rates expire after 72 hours | Restart the `scheduler` service; check that exactly one runs |

## Security settings

- `APP_DEBUG=false`.
- HTTPS at the proxy, with HSTS there.
- `SESSION_SECURE_COOKIE=true`.
- `TRUSTED_PROXIES` set to the proxy only.
- No public registration exists: accounts come from `users:create`.
- Sign-in and the API are rate limited.
- nginx sends `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy`.
- There is no Content-Security-Policy yet. One needs testing against every page's scripts first, and is listed as later work.

## Demo deployment (decision pending)

A public demo uses its own database, never real data:
1. Set `DEMO_MODE=true` and a `DEMO_PASSWORD`.
2. After the migrations, run `php artisan demo:seed --force` once. It refuses without `--force` in production, and on a database that already has users.

Every demo account shares `DEMO_PASSWORD`, so the owner has to choose what reviewers get (docs/09-OPERATIONS-AND-PORTFOLIO.md, "Public demo policy"):

- **Recommended default:** publish only the manager accounts. On the demo, deactivate the admin and the station operators:

  ```bash
  docker compose … run --rm app php artisan users:deactivate admin@fleetfuel.test
  ```

  Then no public account can change prices or submit purchases. Show the admin and POS parts in a recording or a private guided demo.
- A demo reset would mean wiping and reseeding the demo database. No reset is scheduled; if one is wanted, it needs a separate decision and runs only on the demo database.

## GitHub settings (owner action, not done)

CI already runs on every push to `main` and on pull requests (`.github/workflows/ci.yml`).

To require it before merging: in the repository settings, add a branch rule for `main`. Require a pull request, require the status check "Setup and quality gates", and block force pushes. That cannot be set from this repository's files. Ask before anyone changes it on your behalf.

**Blocked while the repository is private.** On 2026-10-02 the repository was private on a free GitHub plan. GitHub's API refused both branch protection and rulesets for it (HTTP 403: "Upgrade to GitHub Pro or make this repository public to enable this feature"). The rule can be added once the owner makes the repository public or upgrades the plan.

Making the repository public publishes its whole history, not only the current files. Checked on 2026-10-02:
- **Secrets:** a gitleaks v8.28.0 scan of all 10 commits found one match, a false positive: the `YOUR-TOKEN` placeholder in the station page's curl example. No `.env`, key, dump or backup file was ever committed, only the `.example` templates.
- **Commit email:** every commit records the author's email address, which becomes public with the history. GitHub's private-email setting and its noreply address protect future commits only. Changing past commits would need a history rewrite and a force push; that is the owner's call and not part of this runbook.
- **Repository details:** the description, website and topics are empty, and there is no license file. Add the demo URL once it exists.

## Deployment record

| Field | Value |
| --- | --- |
| Host and region | Pending (owner's choice) |
| URL | Pending |
| Image tag / commit | Pending |
| Migration result | Pending |
| `/health` response and time | Pending |
| Smoke test (who, when, result) | Pending |
| Backup and restore check on the host | Pending |

## What has been verified

`make rehearse` passed on 2026-10-02 in 108 s, on the code of the M11 commit (Docker Desktop on Linux). It is also a CI step.

| Step | Result |
| --- | --- |
| Image | Built from the lockfiles: 201 MB compressed, 846 MB unpacked. Runs as UID 10001. No `.env`, tests, tools or dev packages; the code is read-only; the compiled assets are present |
| Release steps | `migrate --force` ran. `demo:seed` without `--force` was refused; `demo:seed --force` seeded the empty database |
| Start | The app is healthy through the image's health check, and exactly one scheduler runs |
| HTTP | `/up` 200 and `/health` `{"status":"ok",...}`.<br>Hashed assets with a one-year cache, the security headers, the plain 404 page.<br>`artisan about`: production, debug off, config, routes and views cached |
| Web sign-in | The manager signed in through the form (CSRF token and session cookie); the dashboard showed Atlas only |
| API (Newman, Postman folders 01–05) | 55 requests and 113 assertions, 0 failures: a POS purchase, its replay, a conflict, the blocked-card and quota declines, the manager's scope, the delivery lifecycle, reports and the CSV, token revocation. `usage:reconcile` is clean |
| Scheduler and logs | `rates:sync` is scheduled, and ran by hand. No demo password or app key in the container logs |
| Backup and restore | A 12 KB backup with its `.sha256`, restored into a throwaway server: migrations complete, counters reconcile, identical row counts and checksums in all 24 tables |
| Restart | Containers recreated from scratch: 33 purchases before and after, and ready again |
| Clean-up | The rehearsal project, its volume, the image tag and the settings removed |

Found by the rehearsal and fixed: Debian's nginx passes `HTTP_HOST` without the port, so the first run's sign-in redirect lost `:8094` (docs/DECISIONS.md, M11).

Not verified:
- a real host and HTTPS termination;
- `TRUSTED_PROXIES` behind a real proxy (covered by `TrustedProxiesTest` and `ProductionBootTest` only);
- the `gpg` encryption step;
- a rollback between two real releases.

In CI, the first run of this step (run 36981927462, on `df58cd6`) failed at `up --wait`, because the scheduler had no health check. The fix is commit `193f941`. Its run 36983000185 passed every step: the rehearsal reported "passed in 172 s" (183 s for the whole step). The API, restore and restart results matched the table above: Newman 26 and 29 requests with 0 failures, 24 identical tables, 33 purchases before and after the restart.
