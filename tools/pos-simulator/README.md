# POS simulator

A small command-line program that plays a fuel station's point-of-sale terminal against the FleetFuel Portal API. It is a separate PHP project (its own `composer.json`, Guzzle for HTTP) and knows the portal only through the public API: it cannot reach the application code or the database. All data it touches is fictional.

It runs named scenarios and checks every answer. Each scenario compares the card balance and the station's ledger before and after, so it also proves that exactly one charge happened, or none.

| Scenario | What it sends | Expected answer |
| --- | --- | --- |
| `success` | A new 20.00 L diesel purchase on the main card | 201 Created with a `Location` header; usage up by exactly 20.00 L; one more ledger row |
| `replay` | The same payload again, then an equivalent spelling (lower-case reference, UTC time, `"20"` liters) | 200 with `Idempotency-Replayed: true` and the original purchase; nothing charged |
| `conflict` | The same reference with 21.00 L | 409 `idempotency_conflict`; nothing charged |
| `blocked` | A new purchase on the blocked card | 403 `card_blocked`; nothing recorded |
| `quota` | 1.00 L more than the small card has left | 403 `quota_exceeded` (`liters`); nothing recorded |
| `all` (default) | The five above, in that order | All of the above |

`replay` and `conflict` reuse the payload of the purchase accepted earlier in the same run. Run on their own, they make one first.

The simulator stops at the first unexpected answer. Exit codes: **0** every check passed, **1** an unexpected answer (or the API could not be reached), **2** a usage or configuration error.

## Configuration: environment variables only

Nothing is read from a file, so a token or password cannot end up committed next to the code.

| Variable | Meaning | Default |
| --- | --- | --- |
| `POS_BASE_URL` | API base URL | `http://localhost:8080/api/v1` (`make simulate` uses `http://web/api/v1` inside Docker) |
| `POS_TOKEN` | A station operator's API token | none |
| `POS_EMAIL`, `POS_PASSWORD` | Instead of a token: the simulator requests one (device name `pos-simulator`) and revokes it at the end | none |
| `POS_CARD` | Active card for success, replay and conflict | `FF-ATLAS-001` |
| `POS_BLOCKED_CARD` | Blocked card | `FF-ATLAS-BLOCKED` |
| `POS_TINY_CARD` | Active card with a small liter limit | `FF-ATLAS-TINY` |
| `POS_PRODUCT` | Product code | `DIESEL` |
| `POS_LITERS` | Liters for new purchases | `20.00` |
| `POS_TIMEOUT` | HTTP timeout in seconds | `10` |

The output never shows the token or password. Card numbers are shortened to their last four characters.

## Running it

From the repository root, with the stack running (`make up`). This example signs in as the demo station operator. The demo password is the `DEMO_PASSWORD` in your `.env`:

```bash
export POS_EMAIL=operator.beirut@fleetfuel.test
export POS_PASSWORD="$(sed -n 's/^DEMO_PASSWORD=//p' .env)"
make simulate                    # all scenarios
make simulate SCENARIO=replay    # one scenario
```

`make simulate` installs the simulator's locked dependencies and runs it in the app container, which reaches nginx as `http://web`. With PHP 8.3 and Composer on your own machine you can also run it directly:

```bash
composer install --working-dir=tools/pos-simulator
POS_BASE_URL=http://localhost:8080/api/v1 php tools/pos-simulator/bin/pos-simulator all
```

## Running it again

A purchase is never undone, and quota is counted per Beirut calendar month. Every successful run therefore uses 20.00 L of the main card's 100.00 L. After a few runs the `success` scenario stops with an explanation ("has only … L left this month") and exit code 1. It checks this before sending anything, so nothing is charged.

For another clean run, add fresh dedicated cards. This only adds three cards to the demo company; it never resets or deletes data:

```bash
docker compose exec app php artisan demo:simulator-cards
# prints, for example:
#   export POS_CARD=FF-SIM-7KQ2-MAIN POS_BLOCKED_CARD=FF-SIM-7KQ2-BLOCKED POS_TINY_CARD=FF-SIM-7KQ2-TINY
```

Paste the printed `export` line, then run `make simulate` again. The command works only in the local or testing environment with `DEMO_MODE=true`. `--tag=ABC` chooses the middle part of the card numbers.

The seeded simulator cards (`FF-ATLAS-001`, `FF-ATLAS-BLOCKED`, `FF-ATLAS-TINY`) are fresh again at the start of each Beirut month. The blocked and small cards can be reused as long as nobody unblocks the first or raises the second's limit.

## How it is tested

`tests/Integration/PosSimulatorTest.php` (part of `make test`) serves the real application with PHP's built-in web server on a dedicated test database, then runs this program as a separate process. It checks that:

- `all` passes on fresh cards and the card is charged exactly once;
- a consumed card fails with guidance and nothing is charged;
- missing configuration exits 2, and a bad token exits 1;
- email and password sign-in revokes its token at the end;
- the output never contains the token or password.

`make lint` checks its code style with the main project's Pint, and `make analyse` runs PHPStan level 6 on it (`phpstan.neon` in this folder).
