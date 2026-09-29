# Postman contract scenarios

These assets are prepared for the target API. They cannot pass before the matching application milestones are implemented. The OpenAPI contract covers all 19 specified operations; this collection focuses on demonstrable end-to-end scenarios, while application tests cover the full authorization/error matrix.

1. Complete M06 for folders 01/02; M07 for deliveries; M08 for reports/CSV.
2. Start the local app and seed fresh dedicated simulator fixtures using the implemented, guarded fixture command. Keep unrelated demo/user data intact.
3. Import `FleetFuel.postman_collection.json` and `local.postman_environment.json` into Postman.
4. Duplicate the environment and set the local demo password from your app's local configuration. Select that environment. Set base_url if you changed the local port.
5. Run folders in order. Token requests save the tokens only to your selected environment. The first purchase generates and saves a unique reference and timestamp; replay/conflict intentionally reuse them.
6. Expect one 20.00 L accepted purchase, replay 200, conflict 409, blocked/quota declines 403, cross-tenant 404 and revoked-token 401. The remaining balance assertion assumes a fresh 100.00 L / zero-used fixture card.
7. Run the final revocation folder after the scenarios. To repeat, use freshly prepared dedicated fixture cards or intentionally adjust the assertions for known starting usage. A new external reference alone does not reset quota.

Keep exported real environments in files ending `.local.json` inside this directory (ignored by Git), for example `my-environment.local.json`. The committed template must retain blank password/token fields. Never export live tokens into the tracked collection/template.

During M06 validate with an available Postman-compatible runner if practical, or record a manual Collection Runner result. Do not claim a green run solely because the collection JSON parses. Update the API/spec if the final implementation intentionally changes a field, preserving acceptance behavior.

The transaction timestamp and future delivery window are generated at run time with whole-second UTC strings. Historic example dates in docs are only for frozen-time tests. The external FX provider is not contacted by this collection; use fixture mode for deterministic local results.
