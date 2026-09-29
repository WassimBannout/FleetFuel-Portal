# UI and demo specification

## Visual direction

Build a restrained business dashboard: dark navy sidebar, white/light-gray content, blue primary actions, amber warnings and red destructive/blocked indicators. Use Bootstrap 5 components and locally built assets. Show a simple text brand, FleetFuel Portal; no Coral logo or imitation. English is the MVP language; use Beirut business time and label USD/LBP explicitly.

Layout: persistent navigation, page title with primary action, filter bar, compact summary cards where useful, responsive table and pagination. Use modest spacing, readable labels, keyboard focus and accessible contrast. Status badges include words, not color alone. At narrow widths use collapsible navigation and horizontally scrollable tables without hiding essential actions.

## Screens and role behavior

| Screen | Contents / interaction | Roles |
| --- | --- | --- |
| Login | Email/password, generic credential errors, no public registration link | All |
| Dashboard | Current-month liters and spend, active cards, quota warnings, deliveries, recent transactions | Admin / manager; scoped |
| Station home | Own station's purchases and API simulator usage instructions | Operator |
| Companies | List/create/edit/deactivate, status, associated fleet counts | Admin |
| Stations | List/create/edit/deactivate, district/governorate | Admin; active reference lookup for others |
| Products/prices | Product metadata + immutable price timeline + publish future/current price | Admin; read prices for others |
| Vehicles | Plate, company (admin only), fuel, capacity, odometer, active flag | Admin / manager |
| Drivers | Name, phone, license, active flag | Admin / manager |
| Fuel cards | Masked number, assignment, status, used/limit/remaining liters and USD, edit/block actions | Admin / manager |
| Transactions | Date/station/product/card filters, filtered totals, paginated rows, detail drawer/page | Scoped all roles |
| Transaction detail | Snapshot amounts, price/rate provenance, event/received time, warnings | Scoped all roles |
| Delivery orders | List + create form, status timeline and authorized next actions | Admin / manager |
| Reports | Consumption grouping, quota exceptions, top stations, anomalies, efficiency estimate, delivery SLA | Admin / manager |
| Audit | Filter actor/action/entity/date, redacted old/new values | Admin |
| Integration status | Latest FX observation, expiry, sync result, manual override form | Admin |

Managers never choose another company. Admin selects a company before fleet/card/delivery creation. Empty dropdowns tell the user to create the prerequisite record. A price timeline displays the unit as **LBP per liter**, not per 20-liter container. FX status distinguishes fixture, provider and manual values.

## AJAX behavior

Use jQuery for filter submissions, debounced search where applicable, inline delivery transitions and selected live totals. Session requests include CSRF header on mutation and request JSON errors. Never embed a long-lived station/admin bearer token in browser markup.

Abort an older filter request or ignore its response using a request sequence number, so slow responses cannot overwrite newer filters. Reset page to 1 on filter changes. Persist filter values in query parameters; reload/deep link should reproduce the view. Totals describe the whole filter, not only current page rows. Display loading, no-results and retryable-error states; don't keep stale totals looking current after errors.

Disable repeated mutation submission while pending, restore controls on failure and show field-level validation. Delivery controls send expected_status and refresh on stale_state. Provide normal form/filter fallbacks where practical. Escape table content inserted with JavaScript; do not use untrusted HTML concatenation.

## Demo data

All records are fictional. Default local login password comes from `DEMO_PASSWORD` and is documented only as a local demo credential after implementation. Use these identities:

| Role | Email | Scope |
| --- | --- | --- |
| Admin | admin@fleetfuel.test | Distributor |
| Manager A | manager.atlas@fleetfuel.test | Atlas Logistics |
| Manager B | manager.cedar@fleetfuel.test | Cedar Catering |
| Operator A | operator.beirut@fleetfuel.test | Harbor Demo Station |
| Operator B | operator.tripoli@fleetfuel.test | North Demo Station |

Seed roughly 2 companies, 3 stations, 3 products, 8 vehicles, 8 drivers, 10 cards, a few dozen purchases across current/previous months and 6 deliveries spanning states. These are seed targets, not résumé metrics. Demo seeding should be deterministic, repeatable and use dates relative to `--as-of` so dashboards stay populated.

Keep dedicated simulator cards separate from historical dashboard fixtures:

- `FF-ATLAS-001`: active diesel card, 100.00 L / 100.00 USD monthly limits, initially zero current usage, tank 60.00 L.
- `FF-ATLAS-BLOCKED`: blocked diesel card.
- `FF-ATLAS-TINY`: active diesel card, 5.00 L / 100.00 USD limits.
- `FF-CEDAR-001`: other-company card, useful for isolation checks.
- One unrestricted/no-vehicle card; one petrol vehicle that cannot purchase diesel.

Create separate historical scenarios: tank overfill within quota, two purchases 20 minutes apart, a card whose quota was reduced below usage, an expired FX observation, and an admin override. Do not make the default demo fail because an expired test scenario replaced every valid rate.

## Five-minute acceptance walkthrough

1. Admin sees aggregate dashboard, manager sees only Atlas, Cedar records remain inaccessible by URL.
2. Manager opens Atlas card usage; station simulator posts 20.00 L; balance changes exactly once after a retry.
3. Simulator shows blocked-card, conflict and over-quota responses; no extra ledger rows are created.
4. Manager requests diesel delivery; admin moves it to scheduled, out_for_delivery, delivered; manager sees timeline.
5. Manager filters transactions, checks totals and downloads scoped CSV. Admin views anomalies and FX provenance.

Record screenshots only when the screens exist. Verify keyboard login, form labels/errors, empty states and responsive layout manually in M09.
