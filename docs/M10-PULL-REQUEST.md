# Pull request: release verification (M10)

The description of the M10 change, following [the repository's template](../.github/pull_request_template.md). The change was pushed to `main`, as every milestone has been, so this text is for review and for anyone opening a PR from a branch with the same content. No human review has taken place.

## Problem and resulting behavior

M10 asks for proof that the MVP meets its release gate (docs/08-BUILD-PLAN.md):
- every applicable acceptance case T01–T35;
- CI-equivalent checks;
- a clean-checkout setup in isolated volumes;
- documentation and contract parity;
- a review of authorization, money, idempotency, audit and CSV handling.

The audit found five real problems and two untested paths. All are now fixed or covered:

- **Card numbers, emails and session IDs could reach the error log.** Laravel writes a failed query's bound values into the exception message it logs. The MySQL connection now masks them (`mask_bindings_in_exception_messages`). See [the debugging story](DEBUGGING-STORY.md).
- **One audit action had two names.** The demo seed audited its quota cut as `fuel_card.limits_changed`, the screens as `card.limits_changed`. The seed now goes through `FuelCardService`.
- **A second clone on the same machine took over the first one's containers and database volume** (fixed project name `fleetfuel`). Set `COMPOSE_PROJECT_NAME` and `APP_PORT` for its first `make setup`. `make setup`/`up`/`test` refuse to start another checkout's project.
- **The contract missed a header.** `openapi.json` did not document the `Retry-After: 1` that the prose contract promises on a contention 503. It is now documented (contract 0.10.0).
- **Two unused public routes:** Laravel's signed local-disk serving, `storage/{path}`. They are switched off.
- **Untested: the contention 503.** It now runs for real behind a held card lock.
- **Untested: T35 (production boot).** `ProductionBootTest` serves real HTTP from `php artisan optimize` caches in production mode with debug off.

New guards keep these from coming back:
- every route except six public ones refuses a guest;
- every documented make target, Artisan command, endpoint, local URL and relative link must exist;
- `make audit` checks Composer and npm advisories, and CI runs it.

## Verification

- **Commands run and actual results:** see [the release verification](RELEASE-VERIFICATION.md), "Quality gates" and "Clean-checkout rehearsal". `make verify` passed with 590 PHPUnit tests (5,972 assertions) on MySQL 8.4, plus 14 JavaScript tests; Pint, Larastan and the Vite build passed; `make audit` found no advisories.
- **Manual scenario checked:** a fresh clone was set up next to the running development stack, in its own Compose project (`fleetfuel-rehearsal`), port (8092) and volume. `make verify`, the repeated-setup check and `make audit` passed there. A clone without its own project name was refused before anything was built. The development stack and its data were untouched, and the rehearsal's containers, volume and image were removed afterwards.
- **Tenant, security and data-integrity checks:**
  - the guest sweep over every route;
  - failed queries logged without values;
  - the 503 path records nothing and the retry succeeds;
  - seeded and live audit rows identical.

  Seven deliberate breakages were each caught by the new tests, then restored with a matching checksum.
- **Checks not run, and why:**
  - the hosted deployment, backups and HTTPS (M11);
  - SQL Server (optional S01);
  - Newman (not rerun; the simulator suite covers the POS scenarios);
  - screen readers and browsers other than Chrome.

## Review notes

- **Config:** `config/database.php` (`mask_bindings_in_exception_messages`), `config/filesystems.php` (`serve` off), `compose.yaml` (image named after the project), `.env.example` (commented `COMPOSE_PROJECT_NAME`).
- **Scripts:** `docker/bin/prepare-env.sh` (optional project and port), the new `docker/bin/check-compose-project.sh`, and the Makefile (`make audit`, the project check).
- **Contract:** `openapi.json` 0.10.0, documentation only; no endpoint changed. The contract checker now requires the headers marked `required`.
- **Data:** no schema change. Existing seeded databases keep their old audit row; audit history is not rewritten.
- **Limitations:**
  - MySQL's duplicate-key messages still quote the duplicated value;
  - the project check cannot see a project whose containers were removed;
  - Mailpit's port is fixed.

  The full list is in [the release verification](RELEASE-VERIFICATION.md).
- **Screenshots:** no UI changed in M10.
