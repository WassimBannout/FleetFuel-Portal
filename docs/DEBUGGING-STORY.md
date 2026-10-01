# Debugging story: card numbers in the error log

A real finding from the M10 release review (2026-10-01), recorded with the commands that were actually run. Nothing here is reconstructed after the fact.

## Symptom

The project rule is that credentials and card identifiers never reach the logs. The sign-in listener already masks emails, and the API never puts exception text in a response. The open question was the log entry for an *unexpected* database error. Laravel reports such an exception with its message, and the message of a failed query is built by the framework, not by this application.

## Reproduction

One harmless failing query on the isolated test database (an unknown column, read-only):

```bash
docker compose run --rm app php artisan tinker --env=testing --execute="try { DB::select('select * from fuel_cards where card_no = ? and no_such_column = 1', ['FF-TEST-1234']); } catch (Throwable \$e) { echo \$e->getMessage(); }"
```

```text
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'no_such_column' in 'where clause'
(Connection: mysql, Host: mysql, Port: 3306, Database: fleetfuel_test,
SQL: select * from fuel_cards where card_no = FF-TEST-1234 and no_such_column = 1)
```

The card number sits in the message, in clear. The value was a bound parameter, so the query itself was safe from injection. It is the error message that writes the value back into the SQL.

## Cause

`Illuminate\Database\QueryException::formatMessage()` replaces each `?` with its binding before building the message. Laravel's exception handler logs that message for every unexpected error. So any database fault would put the bound values of the failing statement into `storage/logs`. That covers a lost connection, a bug, or a lock timeout outside the POS retry. The values could be:
- a card number (the POS card lookup);
- an email address (the sign-in lookup);
- a session ID (`select * from sessions where id = ?` runs on every web request, so a database outage would log one per visitor; a session ID is enough to take over a signed-in session).

## Fix

Reading the same method showed a `$maskBindings` parameter. `Illuminate\Database\Connection` passes it from the connection setting `mask_bindings_in_exception_messages`. One line in `config/database.php` turns it on for the MySQL connection:

```php
'mask_bindings_in_exception_messages' => true,
```

The message keeps its placeholders (`card_no = ?`), and the normal exception logging, with its stack trace, is unchanged. A custom exception reporter would also have worked, but it would duplicate framework behaviour and has to be maintained. The built-in option is smaller and survives framework upgrades.

## Tests that keep it fixed

- `tests/Feature/Api/ErrorResponsesTest.php`, `test_a_failed_query_is_logged_without_its_bound_values`:
  - a route runs a failing query bound to `FF-ATLAS-001`;
  - the client gets the generic 500 envelope;
  - the only error log entry contains `card_no = ?` and not the card number.
- `tests/Integration/ProductionBootTest.php`, which runs the application in production mode from cached configuration over real HTTP:
  - the `sessions` table is hidden, so the real session query fails;
  - the visitor gets the plain 500 page;
  - the server log has the SQL error with `` where `id` = ? `` and no session ID.

To prove the tests can fail, the setting was switched back to `false` (the deliberate-breakage check in [the release verification](RELEASE-VERIFICATION.md)). Both tests failed, and passed again once the setting was restored.

## What remains

MySQL's own message for a duplicate key quotes the duplicated value ("Duplicate entry '…' for key …"). The masking does not change driver messages. The application handles its expected duplicates without logging: a POS reference is answered as a replay or a 409, and card numbers and emails are checked before insert. This is recorded as a limitation, not hidden.

## Other real debugging notes

Earlier sessions recorded these with their evidence, in [the progress log](PROGRESS.md):
- **M09:** screen-reader text widened pages on phones. A position-absolute element escaped a scrolling table with no positioned ancestor; found by measuring page widths in Chrome. Its guard is the manual phone sweep, not an automated test.
- **M09:** `php artisan serve` dropped environment overrides, because its workers re-read `.env`.
- **M09:** Docker Desktop kept serving a replaced file's old inode to a running container.
- **M01:** nginx returned 502 after `app` was recreated.
