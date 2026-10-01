# REST API contract

Base URL: `http://localhost:8080/api/v1` locally. Machine-readable contract: [openapi.json](api/openapi.json). Operations marked `x-status: implemented` there are served and checked against real responses by the test suite; `planned` operations (reports and export, M08) are not routed yet. Keep it, Postman and this document synchronized whenever behavior changes.

## Authentication and common conventions

Send `Accept: application/json`; JSON writes also send `Content-Type: application/json`. All routes except `POST /auth/token` require a bearer token. Issue tokens to existing active seeded/admin-provisioned accounts using email, password and device_name; return plaintext once. Use role-defined abilities, expire after 24h, and revoke the current token on logout. Invalid credentials use 401 with a generic message; token issuance is rate-limited by normalized email and IP. There is no API public registration or user-chosen role/ability.

| Role | Allowed token abilities |
| --- | --- |
| Admin | reference:read, transactions:read, cards:read, cards:write, fleet:read, fleet:write, deliveries:read, deliveries:write, deliveries:status, reports:read, exports:read |
| Company manager | reference:read, transactions:read, cards:read, cards:write, fleet:read, fleet:write, deliveries:read, deliveries:write, reports:read, exports:read |
| Station operator | reference:read, transactions:create, transactions:read, cards:read |

Role/ownership policies run even if a token incorrectly has a wider ability. Reject cookies-only access to token API routes; use session-authorized web/AJAX routes for browser screens. DELETE token is available to any valid token.

Use request IDs; never expose exception traces. Resource IDs are integers. Decimal values are strings. Dates use ISO strings, response instants use UTC `Z`. Lists default 25 per page, maximum 100; reject invalid `per_page` values. Date ranges allow at most 366 days. If omitted, report/list dates default to the current Beirut month (inclusive start / exclusive next-month start).

Success resources use `{ "data": {...} }`. Paginated lists use `data`, `links`, and `meta` with current_page, per_page, last_page, total. Transaction lists additionally include `meta.totals` for all filtered records (`liters`, `amount_lbp`, `amount_usd`). Unpaginated reference lists and consumption rows still use a `data` array.

```json
{
  "error": {
    "code": "quota_exceeded",
    "message": "This purchase exceeds the card's monthly quota.",
    "details": {"dimension": "liters"},
    "request_id": "correlation-id"
  }
}
```

For validation errors, details maps field names to arrays of messages. For other errors it is an object, possibly empty. Never return a success status with an embedded error.

## Endpoints

| Method / route | Caller and scope | Result |
| --- | --- | --- |
| POST `/auth/token` | Public, rate-limited credentials | 201 token + expiry + user summary |
| DELETE `/auth/token` | Any token | 204, token revoked |
| GET `/stations` | reference:read; active only, optional governorate | Paginated station summaries |
| GET `/products/prices?at=` | reference:read; default now, offset instant | Active product prices at instant, LBP and indicative USD |
| POST `/transactions` | Station operator / transactions:create | 201 new, 200 exact replay |
| GET `/transactions` | transactions:read; admin all, manager company, operator station | Paginated ledger with filtered totals |
| GET `/transactions/{id}` | Same read policy | Immutable transaction detail |
| GET `/cards/{card_no}/balance?month=` | cards:read; admin, own manager, operator minimal POS lookup | Limit, usage, remaining, status, month; no customer/driver PII |
| PATCH `/cards/{id}` | cards:write; admin / own manager | Update quotas or active/blocked status, audit |
| GET `/vehicles` | fleet:read; admin / own manager | Paginated vehicles |
| POST `/vehicles` | fleet:write; admin / own manager | 201 created vehicle |
| GET `/drivers` | fleet:read; admin / own manager | Paginated drivers |
| POST `/drivers` | fleet:write; admin / own manager | 201 created driver |
| GET `/delivery-orders` | deliveries:read; admin / own manager | Paginated orders, optional status |
| POST `/delivery-orders` | deliveries:write; admin / own manager | 201 pending order + initial history |
| GET `/delivery-orders/{id}` | deliveries:read; admin / own manager | Order + chronological history |
| PATCH `/delivery-orders/{id}/status` | Admin deliveries:status; own manager deliveries:write for pending→cancelled only | Order after validated transition |
| GET `/reports/consumption` | reports:read; admin / own manager | Aggregated rows, group_by company/vehicle/product |
| GET `/exports/transactions.csv` | exports:read; admin / own manager | Stream CSV attachment |

Additional master-data writes and reports are served by web controllers in MVP; this API is intentionally finite. All routes above must be real and documented by M08. No undefined `v_vehicle_transactions` view from the research: report against the explicit ledger schema or create/test a view deliberately.

Common filters on transactions/CSV: `from`, `to`, `card` (card number), `station_id`, `product_code`, `company_id` (admin only); pagination only on JSON list. Manager-supplied company_id is rejected even if equal to their company, because ownership is server-derived. Station operators cannot use company_id. Admin fleet/delivery creation requires company_id; managers must omit it. A cross-tenant ID in an otherwise valid request does not grant access.

Balance `month` is `YYYY-MM`, defaults to current Beirut month and only accepts the current or immediately previous month (late POS use). It uses current limits; label this explicitly. POST remains authoritative. User-facing historical usage comes from reports, not a promise about past limits.

Price preview returns product_code, unit, unit_price_lbp (4 decimals), indicative_unit_price_usd (4 decimals), effective_from and rate_effective_at. Return an error if any requested active product lacks the required price/rate, rather than fabricating a zero price. Historical preview has the same 366-day query horizon; available observations determine what can actually resolve.

## POS request and response

```json
{
  "external_ref": "POS-DEMO-0001",
  "card_no": "FF-ATLAS-001",
  "product_code": "DIESEL",
  "liters": "20.00",
  "transacted_at": "2026-09-28T10:00:00+03:00",
  "odometer_km": 45000
}
```

The timestamp is illustrative. Postman must generate a current timestamp and unique external_ref for each new scenario; deterministic tests freeze the clock. Maximum liters is 99999999.99 by storage bounds; the UI can impose sensible smaller demo choices without changing the API contract. external_ref and card_no accept uppercase/lowercase ASCII letters, digits and hyphens, max 100 and 40 respectively; normalize uppercase. product_code is one of the defined codes. No caller-supplied amount, price, rate, company_id or station_id is allowed.

```json
{
  "data": {
    "id": 1,
    "external_ref": "POS-DEMO-0001",
    "station_id": 1,
    "company_id": 1,
    "card_no": "FF-ATLAS-001",
    "product_code": "DIESEL",
    "liters": "20.00",
    "unit_price_lbp": "80000.0000",
    "amount_lbp": "1600000.00",
    "amount_usd": "17.88",
    "rate_lbp_per_usd": "89500.00000000",
    "rate_source": "fixture",
    "rate_effective_at": "2026-09-28T00:00:00Z",
    "transacted_at": "2026-09-28T07:00:00Z",
    "quota_month": "2026-09-01",
    "odometer_km": 45000
  }
}
```

Response first creation: 201, `Location: /api/v1/transactions/1`. Same key and equivalent canonical payload: 200 with same data and `Idempotency-Replayed: true`. Same key but changed liters/card/product/time/odometer: 409. Absent and null odometer canonicalize identically. Different station can use the same external_ref independently.

## Write payloads

- **Token:** email, password, device_name (max 80). Return `data.token`, `data.expires_at`, `data.user` with id/name/role. Do not echo password.
- **Card patch:** nonempty subset of monthly_limit_l, monthly_limit_usd (decimal strings or null), status (active/blocked). Reject ownership/assignment changes via this endpoint. Archived cards cannot be restored. Quota reduction below usage is valid and audited.
- **Vehicle create:** plate_no, fuel_type petrol/diesel, tank_capacity_l, optional odometer_km, admin-only company_id. Normalize plate uppercase, validate uniqueness.
- **Driver create:** name, license_no, optional phone, admin-only company_id.
- **Delivery create:** address, governorate, liters, preferred_start_at, preferred_end_at, admin-only company_id. Reject status, delivered_at, price or truck fields at creation.
- **Delivery transition:** expected_status, status; scheduled_start_at, scheduled_end_at and assigned_truck required for scheduled; reason required for cancelled. Reject unrelated keys. Dispatch/delivery derive timestamps/state server-side.

## Status and error codes

| HTTP | Codes / meaning |
| --- | --- |
| 200 / 201 / 204 | Read/update/replay / creation / token revocation |
| 400 | malformed_json |
| 401 | unauthenticated, invalid_credentials |
| 403 | forbidden, card_blocked, card_inactive, company_inactive, station_inactive, assignment_inactive, product_not_allowed, quota_exceeded |
| 404 | not_found (also cross-tenant scoped records) |
| 405 | method_not_allowed (known path, unsupported HTTP method) |
| 409 | idempotency_conflict, stale_state, invalid_transition, assignment_locked |
| 422 | validation_failed, price_unavailable; age and numeric format errors are validation_failed |
| 429 | rate_limited; include Retry-After |
| 503 | rate_unavailable, temporarily_unavailable |
| 500 | internal_error; no stack trace in response |

Choose and document actual limits in M02/M05 (default login/token issuance 5/minute per email+IP, authenticated API 120/minute per user, POS writes 60/minute per station). Test exact configured thresholds without waiting in real time. Error rendering must preserve Retry-After and framework authentication headers.

Limits implemented in M02 and M05 (tested with time travel, not real waiting):

| Surface | Limit | Counted | Over the limit |
| --- | --- | --- | --- |
| Web sign-in (`POST /login`) | 5 per minute per lowercase email + IP | Failed attempts only; a successful sign-in clears the count | Form error "Too many login attempts…" for about 60 s, even with the right password |
| `POST /api/v1/auth/token` | 5 per minute per lowercase email + IP | Every request | 429 `rate_limited` with `Retry-After` |
| Authenticated `/api/v1` requests | 120 per minute per user | Every request | 429 `rate_limited` with `Retry-After` |
| `POST /api/v1/transactions` | 60 per minute per station (all its operators and tokens together), on top of the 120 per user | Every request | 429 `rate_limited` with `Retry-After` |

Every response carries a server-generated `X-Request-Id` (a client-supplied value is ignored). The same value is `error.request_id` in the envelope and is attached to server log entries. Unknown `/api/*` paths and methods also use the envelope, whatever the `Accept` header. Unrecognized request fields are `validation_failed` (for example `abilities` or `role` on token issuance), not silently ignored.

## Implemented behavior (M05)

`POST /transactions`, `GET /transactions`, `GET /transactions/{id}` and `GET /cards/{card_no}/balance` are live. Details the tables above leave open:

- **Check order for POS requests.** Each step answers before the next runs:
  1. Token, role (`station_operator`) and ability (`transactions:create`): 401/403. Then an inactive station: 403 `station_inactive`.
  2. Structure (types, formats, scale, unknown fields such as `station_id`): 422.
  3. Replay lookup on `(station_id, external_ref)`: 200 with the original data, or 409.
  4. Unknown card: 404.
  5. The 72-hour window, both ends inclusive; an event after "now" is refused, with no clock-skew allowance: 422.
  6. Card, company, assignment and product rules: 403.
  7. Price: 422 `price_unavailable`. Rate: 503 `rate_unavailable`.
  8. Quota: 403 `quota_exceeded`.

  A replay therefore succeeds after a block, a price change, a quota cut or the event ageing past 72 hours.
- **Decline details.**
  - `quota_exceeded`: `{"dimension": "liters" | "usd"}`.
  - `assignment_inactive`: `{"assignment": "vehicle" | "driver"}`.
  - `product_not_allowed`: `{"reason": "product_inactive" | "card_restriction" | "vehicle_fuel_type"}`.
  - `card_inactive` means an archived card; `card_blocked` a blocked one.
- **Input formats.**
  - `product_code` must be one of the exact upper-case codes.
  - `external_ref` and `card_no` accept any letter case and are stored in capitals.
  - `odometer_km` must be a JSON integer or null; a numeric string is refused.
- **Contention.** Deadlocks, lock timeouts and an unresolved same-reference race are retried up to 3 times. After that the answer is 503 `temporarily_unavailable` with `Retry-After: 1`, and nothing is recorded.
- **Transaction list.**
  - `from` and `to` come together or not at all; without them, the list covers the current Beirut month.
  - Unknown query parameters are 422.
  - An operator's `station_id` filter still applies inside their own station, so another station returns an empty list.
  - `meta.totals` covers every filtered row, not just the page.
- **Balance.**
  - An admin looks up any card, a manager only their company's cards (another company's is 404), and a station operator any card, because cards work at every station.
  - The figures always use the card's **current** limits, also for the previous month.
  - The response names no company, vehicle or driver, and reserves nothing.

## Implemented behavior (M06)

`GET /stations`, `GET /products/prices`, `PATCH /cards/{id}`, and `GET`/`POST` on `/vehicles` and `/drivers` are live. Details the tables above leave open:

- **Stations.**
  - Only active stations are listed, for every role (admins see inactive ones on the web screens).
  - Ordered by name. `governorate` is an exact match.
- **Price list.**
  - `at` must carry an offset and whole seconds, like every API timestamp.
  - It may be at most 366 days ago and never in the future: future prices are on the web price timeline, and no rate observed today could convert them.
  - In a query string, write a `+` offset as `%2B` (a bare `+` means a space) or use `Z`.
  - Each row adds `rate_source` (`fixture`, `provider` or `manual`), so a client can tell a synthetic demo rate from a real one.
  - As for a purchase, a missing price is checked before a missing rate: 422 `price_unavailable`, then 503 `rate_unavailable`.
- **Card patch.**
  - A limit sent as `null` means unlimited. A limit that is not sent keeps its value, read under the card lock, so a concurrent edit of the other limit is never overwritten.
  - Limits and status are applied together in one transaction, or not at all.
  - An unchanged value writes no audit row. Lowering a limit below this month's usage is allowed and audited with `below_current_usage: true`; the API has no confirmation step, unlike the web form.
  - An empty body is 422 with `details.body`. Ownership, assignment or card-number fields are 422 "This field is not allowed". `status: archived` is 422: archiving stays a web action because it is final.
  - An archived card is 409 `invalid_transition`.
  - An inactive company: its limits cannot change and its cards cannot be unblocked (403 `company_inactive`); blocking still works.
  - The response is the card itself (`Card`), not its balance.
- **Vehicles and drivers.**
  - Lists are ordered by id and include inactive records (`is_active`).
  - `company_id`, as a filter or in a body, is for admins only. A manager's is refused (422) even when it names their own company.
  - In bodies, `company_id` and `odometer_km` must be JSON integers. `"45000"` as a string is refused, as on `POST /transactions`.
  - Plates are normalized to upper case with single spaces and must be unique. License numbers are upper-cased and unique within the company.
  - An inactive company's fleet is read-only: creating is 403 `company_inactive`.
  - There is no detail route, so a 201 has no `Location` header.
- **Role before scope.** The role check (`role:admin,company_manager` or `role:station_operator`) and the token ability run before any record is looked up. A station operator asking for a card, vehicle or driver route gets 403, never a 404 that would reveal whether the record exists. After that, the tenant-scoped lookup makes another company's record 404.
- **Contract checks.** `tests/Feature/Api/OpenApiContractTest.php` validates `openapi.json` against the official OpenAPI 3.1 schema. It also checks that the routes, their token abilities (`x-abilities`) and roles (`x-roles`) match the document exactly, and that planned operations are not routed. Every success and error response in the API tests is validated against the documented status, headers and schema.

### Token abilities

A token carries the abilities of its user's role (table above), chosen by the server at issue time. Each route requires one ability (`x-abilities` in `openapi.json`). A token without it is refused with 403 before anything else happens.

The delivery status route is the one exception. It admits a token with either `deliveries:status` or `deliveries:write` (`x-any-abilities`), because admins and managers use it for different moves. The request then requires `deliveries:status` from an admin and `deliveries:write` from a manager (403 otherwise).

Abilities only narrow access; they never widen it. The role check, the tenant scope and the policies still run, so even a hand-made token with every ability cannot make a manager see another company's card or a station operator change a quota.

## Implemented behavior (M07)

`GET`/`POST /delivery-orders`, `GET /delivery-orders/{id}` and `PATCH /delivery-orders/{id}/status` are live. Details the tables above leave open:

- **Creation.**
  - The order starts `pending` with one history row (`from_status` null, `to_status` pending, by the creator, at creation time). Creation writes no audit row: that history row records who and when, and every later change is audited.
  - `company_id` is for admins only and must name an active company (422 "Choose an active company."). A manager of an inactive company gets 403 `company_inactive`.
  - `liters` is a positive decimal string up to 99999999.99. `preferred_start_at` must be after now and at most 366 days ahead; `preferred_end_at` must be after the start.
  - Status, truck, delivery time, a price or any other field is 422 "This field is not allowed".
  - 201 carries `Location: /api/v1/delivery-orders/{id}`.
- **Lists and detail.** Newest first (creation time, then id). Every order includes its history, ordered by `changed_at`, then id. `status` filters; `company_id` is for admins only.
- **Status changes.** Checked in this order, each refusal writing nothing:
  1. Validation (422): `expected_status` and `status` are required. Scheduling needs `scheduled_start_at`, `scheduled_end_at` and `assigned_truck` (at most 60 characters); cancelling needs `reason` (at most 255). These fields are refused for any other target.
  2. The order row is locked (`SELECT … FOR UPDATE`) for the rest of the check.
  3. Role (403 `forbidden`): a company manager may only ask for pending → cancelled on their own order.
  4. `expected_status` (409 `stale_state`): the order is no longer in the status the caller saw. `details.current_status` says what it is now. A manager whose pending order was scheduled meanwhile gets this, not 403.
  5. The move itself (409 `invalid_transition`): a skipped step, the same status again, or anything out of `delivered` or `cancelled`. `details.current_status` is included.
  6. Details (422): the scheduled window must start after now, at most 366 days ahead, and end after it starts.
  7. The order is updated, and exactly one history row and one audit row (`delivery_order.status_changed`, with the old status and the new status plus the fields it set) are written in the same transaction.
- **Server-set fields.** `delivered_at` is the server time of the delivery, set once. The cancellation reason is stored as `cancel_reason`. The history `note` is always null in the MVP.
- **Inactive companies.** New orders are refused, but open orders can still be moved on or cancelled.
- **No card effect.** A delivery never touches fuel cards, quotas or the POS ledger.
- **Concurrency.** Two changes that start from the same `expected_status` queue on the row lock. The first one wins; the second gets 409 `stale_state` (T28, separate processes on MySQL).

## CSV contract

Columns in this order: transaction_id, external_ref, company, vehicle_plate, station, product_code, liters, unit_price_lbp, amount_lbp, amount_usd, rate_source, transacted_at_utc. Include a header, use UTF-8, stream with proper CSV escaping, and neutralize formula prefixes in textual cells. Apply the same filters and tenant scope as the transaction list; no page/per_page truncation. File response has Content-Type text/csv and Content-Disposition attachment.
