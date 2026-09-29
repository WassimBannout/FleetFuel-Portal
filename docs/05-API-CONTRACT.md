# REST API contract

Base URL: `http://localhost:8080/api/v1` locally. Machine-readable contract: [openapi.json](api/openapi.json). It is the target contract until implementation verifies it. Keep it, Postman and this document synchronized whenever behavior changes.

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

Limits implemented in M02 (tested with time travel, not real waiting):

| Surface | Limit | Counted | Over the limit |
| --- | --- | --- | --- |
| Web sign-in (`POST /login`) | 5 per minute per lowercase email + IP | Failed attempts only; a successful sign-in clears the count | Form error "Too many login attempts…" for about 60 s, even with the right password |
| `POST /api/v1/auth/token` | 5 per minute per lowercase email + IP | Every request | 429 `rate_limited` with `Retry-After` |
| Authenticated `/api/v1` requests | 120 per minute per user | Every request | 429 `rate_limited` with `Retry-After` |
| POS writes | 60 per minute per station | M05 | M05 |

Every response carries a server-generated `X-Request-Id` (a client-supplied value is ignored). The same value is `error.request_id` in the envelope and is attached to server log entries. Unknown `/api/*` paths and methods also use the envelope, whatever the `Accept` header. Unrecognized request fields are `validation_failed` (for example `abilities` or `role` on token issuance), not silently ignored.

## CSV contract

Columns in this order: transaction_id, external_ref, company, vehicle_plate, station, product_code, liters, unit_price_lbp, amount_lbp, amount_usd, rate_source, transacted_at_utc. Include a header, use UTF-8, stream with proper CSV escaping, and neutralize formula prefixes in textual cells. Apply the same filters and tenant scope as the transaction list; no page/per_page truncation. File response has Content-Type text/csv and Content-Disposition attachment.
