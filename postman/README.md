# Postman contract scenarios

`FleetFuel.postman_collection.json` walks through the API with fictional demo data. The OpenAPI file (`docs/api/openapi.json`) covers every operation. This collection focuses on demonstrable end-to-end scenarios; the PHPUnit suite covers the full authorization and error matrix.

| Folder | Needs | Content |
| --- | --- | --- |
| 01 — POS scenario | M06 (done) | Station token, stations, prices, balance, one 20.00 L purchase, identical replay (200), conflicting retry (409), blocked card and over-quota declines (403), detail, balance after, refused `station_id` (422), station refused on vehicles and card changes (403), missing token (401) |
| 02 — Manager access | M06 (done) | Manager token, own vehicles, drivers and ledger, `company_id` refused (422), other company's card hidden (404), manager cannot post a purchase (403) |
| 03 — Delivery scenario | M07 | Not implemented yet |
| 04 — Reports and export | M08 | Not implemented yet |
| 05 — Revoke tokens | M06 (done) | Both tokens revoked (204), then refused (401) |

## Running folders 01, 02 and 05

1. Start the stack (`make up`).
2. Use fresh dedicated cards. On a newly seeded demo, the default `FF-ATLAS-001`, `FF-ATLAS-BLOCKED` and `FF-ATLAS-TINY` are fresh. Otherwise add a new set; this never resets or deletes other data:

   ```bash
   docker compose exec app php artisan demo:simulator-cards
   ```

   It prints the three new card numbers (`FF-SIM-<tag>-MAIN`, `-BLOCKED`, `-TINY`).
3. Import `FleetFuel.postman_collection.json` and `local.postman_environment.json`.
4. Duplicate the environment and fill in:
   - `demo_password`: the `DEMO_PASSWORD` value from your `.env`;
   - `card_no`, `blocked_card_no` and `tiny_card_no`: set them if you created new cards;
   - `base_url`: change it only if you changed the local port.

   Select that environment.
5. Run folders 01, 02 and 05, in that order.
   - Token requests save the tokens in your selected environment only.
   - The first purchase generates a unique reference and a current timestamp and saves the whole payload as `purchase_payload`.
   - Replay sends that saved payload unchanged. Conflict sends it with only the liters changed.

Expected result: one accepted 20.00 L purchase, replay 200, conflict 409, blocked and quota declines 403, cross-tenant 404, validation 422, wrong role 403, and 401 without a token or after revocation. The balance check compares against the balance read at the start of the run: usage must rise by exactly 20.00 L.

The same run from the command line uses [Newman](https://www.npmjs.com/package/newman), Postman's official runner, inside the project's node container, so no Node install is needed. The password stays in an environment variable and nothing is exported to a file:

```bash
DP="$(sed -n 's/^DEMO_PASSWORD=//p' .env)" docker compose run --rm --no-deps -e DP node sh -c \
  'npx --yes newman@6 run postman/FleetFuel.postman_collection.json -e postman/local.postman_environment.json \
     --folder "01 — POS scenario (M06)" --folder "02 — Manager access (M06)" --folder "05 — Revoke tokens" \
     --env-var base_url=http://web/api/v1 --env-var "demo_password=$DP" \
     --env-var card_no=FF-SIM-XXXX-MAIN --env-var blocked_card_no=FF-SIM-XXXX-BLOCKED --env-var tiny_card_no=FF-SIM-XXXX-TINY'
```

Replace `XXXX` with the tag printed by `demo:simulator-cards`.

## Running it again

A purchase is never undone, and quota counts per Beirut calendar month. Each run uses 20.00 L of the main card's 100.00 L limit. The "Initial card balance" request fails once less than 20.00 L is left. A new external reference alone does not reset quota: prepare fresh cards (step 2) instead of changing assertions.

## Secrets

Keep exported environments in files ending `.local.json` inside this directory, for example `my-environment.local.json`. Git ignores them. The committed template keeps its password and token fields blank. Never export live tokens into the tracked collection or template.

Timestamps are generated at run time as whole-second UTC strings; the historic dates in the docs are only for frozen-time tests. The collection never contacts the external FX provider. Use fixture mode (`EXCHANGE_RATE_MODE=fixture`, the default) for deterministic local results.
