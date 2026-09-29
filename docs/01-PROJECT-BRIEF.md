# Product brief

## Purpose

FleetFuel Portal models the internal business software of a fictional Lebanese fuel distributor. A company manager maintains a fleet and fuel cards; a station POS sends authenticated fuel purchases; distributor staff manage reference data and diesel deliveries. Reports show consumption and cost in USD and LBP.

The portfolio should demonstrate PHP/OOP, Laravel, relational modeling, SQL, REST API development and consumption, application security, testing, debugging, Docker and a reviewable Git workflow. The POS is a simulator, not a real pump or payment integration.

## Users and authorization

| Capability | Admin | Company manager | Station operator |
| --- | --- | --- | --- |
| Manage companies, stations, products and prices | All | Read active products/stations | Read active products/stations |
| Manage vehicles, drivers and cards | All companies | Own company | No |
| Block cards / change monthly quotas | All | Own company | No |
| View purchases and reports | All | Own company | Own station purchases only |
| Submit POS purchases | No by default | No | Own station via token |
| Read card balance for POS | All | Own cards | Minimal card status/remainder |
| Create delivery order | For selected active company | Own company | No |
| View delivery orders | All | Own company | No |
| Advance delivery status | Yes | No | No |
| Cancel pending delivery | Yes | Own pending order only | No |
| Enter FX override / view audit | Yes | No | No |
| Export accounting CSV | All | Own company | No |

Roles are mutually exclusive. Managers have exactly one company; station operators exactly one station; admins neither. Disabled users cannot log in, issue tokens or keep using existing tokens. Inactive company/station state is enforced on related writes. A station operator's browser UI is a small own-station transaction list and help page; the POS ingestion route requires a real token with the correct ability.

## MVP scope and user stories

| ID | Requirement | Demo / completion evidence |
| --- | --- | --- |
| F1 | Login/logout, three roles, tenant policies | Company A cannot access Company B even with guessed IDs |
| F2 | Companies, vehicles, drivers, stations, products | Validated Bootstrap forms and paginated lists |
| F3 | Cards, product restriction, liter/USD quotas, block status | Manager changes quota; audit records old/new values |
| F4 | Authenticated POS ingestion | Valid purchase, replay, conflict and declined purchase |
| F5 | External FX integration and bounded fallback | Successful sync plus simulated outage shown in tests |
| F6 | Effective-dated product prices and snapshots | Old purchase retains its original amounts after a change |
| F7 | Diesel delivery order lifecycle and audit | Manager creates; admin schedules, dispatches and delivers |
| F8 | SQL reports, anomaly flags, CSV | Consumption, quota exceptions, top stations, anomalies and SLA |
| F9 | AJAX transaction filtering and totals | No full reload; correct totals across all filtered records |
| F10 | Audit sensitive changes | Admin can inspect actor/time/change without secrets |
| F11 | Unit + MySQL feature/concurrency tests and CI | Actual green workflow and meaningful failure-path tests |
| F12 | Postman and versioned OpenAPI | Collection runs against seeded local application |
| F13 | Reproducible Docker setup | A clean checkout runs using documented commands |
| F14 | Maintainable Git and release documents | Focused changes, PR template, changelog and real evidence |

Build all F1–F14 through M11, with the SQL Server portion of F13 explicitly optional. Full CRUD refers to create/read/update/archive for business master data; immutable ledgers do not have delete/edit endpoints.

## Explicit boundaries

MVP excludes actual fuel dispensing, card payments, invoicing/taxes, credit-limit enforcement, inventory, loyalty rewards, route optimization, public registration, refunds/reversals, historical imports older than 72 hours, mobile apps and multi-station credentials. Delivery liters do not debit a fuel card. Prices are fictional per-liter demonstration prices, not Lebanese regulated retail price quotes.

SQL Server verification is the first stretch. Signed webhooks, reconciliation, a station map and a clearly labeled refactoring exercise can follow. Do not add them before completing and demonstrating the core.

## Definition of finished

A reviewer can start the project, sign in as each role, submit a POS scenario, observe quota enforcement, place and fulfill a delivery, run reports and inspect the tests. Setup and deployment docs match the actual repository. The project presents only features and metrics that have been built and measured.

Use a generic brand and fictional people/companies. The research's statements about Coral's internal technology are unverified inferences; do not repeat them as fact in the application, README or interview.
