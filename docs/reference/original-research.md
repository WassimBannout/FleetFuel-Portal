# Best Portfolio Project for Coral's Junior PHP Developer Role (Ref. VC24328)

Build **"FleetFuel Portal": a Laravel + MySQL corporate fleet-fuel and diesel-delivery management system with a REST API, a simulated station-POS integration, LBP/USD exchange-rate handling and SQL Server compatibility.** It copies the kind of B2B system Coral already sells, and it covers almost every line of the posting in one project a junior developer can finish.

## TL;DR
- **Who the employer is:** The posting comes from The Coral Oil Company Limited ("Coral"). It is one of Lebanon's main fuel importers and distributors, with a network of service stations, EcoDiesel delivery, lubricants, aviation fuel, EV charging and a loyalty app that also sells a corporate service for managing a fleet's fuel spending. The job itself is about maintaining and extending internal business apps: SQL, REST APIs, integrations, Git, code reviews and testing. It is not greenfield product work.
- **What to build:** FleetFuel Portal. Corporate accounts, vehicles, drivers and fuel cards with monthly quotas; fuel transactions posted by a simulated "station POS" through an authenticated API; diesel-delivery orders; LBP/USD pricing from an external exchange-rate API; and SQL reports. Use Laravel 13 (Composer), MySQL, Bootstrap + jQuery/AJAX, Sanctum, Pest/PHPUnit, Docker, a Postman collection, and a GitHub pull-request workflow.
- **How to use it:** Ship a 2–3 week MVP with a live demo, a strong README and meaningful commit history. Then quote it in 3–4 resume bullets that reuse the posting's own words, link it in your email to hr@coraloil.com (subject line containing "VC24328"), and rehearse walking through its SQL, API, security and integration decisions. Those are the topics a junior interview at this kind of company will test.

## Assumptions
Your skill level and available time weren't specified. The plan assumes you are a fresh graduate or early-career developer with basic PHP, OOP, HTML/CSS/JS and SQL, and that you can put in about 15–25 hours a week. That makes the MVP roughly 2–3 weeks, with stretch goals if you have more time. If you already know Laravel, compress the timeline and do more of the stretch features.

## Key Findings

### 1. What the posting really asks for

**Must-have (screening criteria):**

| Requirement | Where it appears | What it signals |
|---|---|---|
| PHP + OOP | Responsibilities #1, Qualifications | Daily work is in PHP. You'll be asked about classes, interfaces, inheritance and dependency injection. |
| SQL + relational DBs (MySQL **or** SQL Server) | Responsibilities #4–5, Qualifications | Heavy data and reporting work. SQL is listed twice as a duty, which is unusual for a junior role. |
| REST APIs (develop **and** consume), HTTP methods, JSON | Responsibilities #6, Qualifications | Internal apps talk to other systems. You need to know verbs, status codes, auth and JSON payloads. |
| Integration with internal and external systems | Responsibilities #7 | Probably integration with ERP/accounting, station systems, the loyalty app backend or third-party providers. |
| Modify existing code, troubleshoot bugs | Responsibilities #2–3, Qualifications | Most of the job is **maintenance of an existing codebase**, not greenfield work. |
| Git, code reviews, standards, clear code | Responsibilities #9–12 | A team process exists with seniors reviewing code. Readable, conventional code matters more than cleverness. |
| Basic testing before deploying | Responsibilities #8 | They want someone who doesn't break production. Automated tests are a strong differentiator. |
| HTML, CSS, JavaScript | Qualifications | Server-rendered web UIs, not a SPA-first shop. |
| Bachelor's in CS/CE/IT, 0–2 years | Qualifications | Entry level. "Fresh graduates with relevant university or personal projects may be considered," so **the project is your substitute for experience**. |

**Reading between the lines:**
- **Maintenance-heavy role.** "Support," "assist," "maintain," "troubleshoot" and "ability to understand and modify existing code" dominate the text. Show that you can work inside a structured codebase with conventions, tests and migrations, not just write new code.
- **Data-centric business apps.** A fuel distributor runs on transactions, prices, quantities, customers, stations and deliveries. Expect reports, SQL queries and CRUD-plus-business-rules screens.
- **Both MySQL and SQL Server.** Naming both (with SQL Server repeated in the "advantage" list) suggests a mixed environment. New PHP apps probably run on MySQL, while some core systems (ERP, accounting, POS or legacy .NET apps) live on Microsoft SQL Server, and PHP code must read from or write to them. Supporting evidence: the program manager listed as the loyalty app's developer contact has a public profile describing years of work with ".NET technologies" and "SQL Server".\[1\] That is an inference from one person's background, not a confirmed stack. Laravel supports SQL Server natively, but the official docs say you need the `sqlsrv` and `pdo_sqlsrv` PHP extensions and the Microsoft SQL ODBC driver, which is exactly the kind of setup detail worth demonstrating.\[2\]
- **jQuery/AJAX/Bootstrap in the "advantage" list** points to server-rendered Blade or PHP pages enhanced with jQuery. That is typical of internal admin tools. Don't build a React SPA for this application.

**The "advantage" technologies, ranked by value to demonstrate:**

| Rank | Technology | Why |
|---|---|---|
| 1 | **Laravel** (with **Composer**, which comes with it) | The most likely framework for new modules. It also shows MVC, ORM, migrations and validation. Composer is implicit in any Laravel project, so list it but don't treat it as a separate achievement. |
| 2 | **MySQL** | Directly matches the must-have SQL and relational-database requirements. Show raw SQL too, not only Eloquent. |
| 3 | **Application security** | Internal fuel, finance and customer data is sensitive. Easy to demonstrate (auth, RBAC, validation, CSRF, rate limiting) and few juniors do it deliberately. |
| 4 | **SQL Server** | Named twice in the posting, probably tied to core or legacy systems. Showing your app also runs on SQL Server is a rare, targeted differentiator. |
| 5 | **Postman** | Proves you can "develop and consume REST APIs." A shared collection is concrete evidence a reviewer can run. |
| 6 | **AJAX / jQuery** | Matches their probable UI stack for internal tools. Use it for filters, live totals and inline status updates. |
| 7 | **Docker** | Makes your project one-command runnable for a reviewer. Useful, but secondary for this role. |
| 8 | **Bootstrap** | Quick, professional admin UI. The lowest signal, because everyone has it. |

### 2. The company: who Coral is and what systems it probably runs

- **Identity and history.** The Coral Oil Company Limited is a U.K.-incorporated company (1925) that registered its Lebanese branch in 1926. It operated commercially under Shell until 1976 and was acquired by the Alfred Yamin family at the end of 2016.\[3\] Headquarters are at Amaret El Raouche Building, Avenue De Gaulle, Beirut.\[4\]\[5\] Its sister company in the same group is Liquigas (LPG).\[6\]
- **Business lines (from coraloil.com):**
  - Products: EcoFuel+ (Unleaded 95/98), EcoDiesel, aviation fuel and lubricants.\[3\]
  - Services: Car Spa (car wash), lube change, station maintenance and EcoDiesel delivery "for your trucks, heavy machinery, or generators".\[7\] Delivery orders are still taken **by phone** (70 418 888).\[8\]\[9\]
  - The site's price ticker shows fuel in **LBP** and EV charging in **USD** (0.60 USD/kWh), which reflects Lebanon's dual-currency reality.\[7\]\[8\]
  - Coral runs fixed and mobile fuel-quality laboratories and displays ISO 9001 certification.\[3\]\[4\]\[10\]
- **Loyalty app and corporate/fleet service.**
  - Coral's homepage says its "Loyalty Program has been meticulously designed to empower you in efficiently managing and overseeing your fleet's gasoline and diesel expenses."\[8\]
  - The Google Play listing describes a "Corporate service" with a Fast-Pass QR code, an e-wallet, "Manage and monitor your Team / Family's gasoline expenses", points per purchase, e-gift cards, a station finder, oil-change reminders and transaction history.\[11\]\[12\]
  - Coral's current app page describes "steps" earned automatically on every fill-up and tiers from "Adventurer to Ambassador". It lists about 140 participating stations.\[13\]
  - "Automatically" implies the loyalty backend is fed by station transaction data, which is an internal integration of exactly the kind the posting describes.
- **Size.**
  - Coral's 2026 centennial content says "more than 1,000 employees", while its contact page says "over 600 employees".\[5\]\[10\]\[14\] The larger number probably includes station staff.
  - Coral's own published statement quotes Chairman Oscar Yamin: "We now serve approximately 200 Coral service gas stations in Lebanon, of which 20% are owned or directly managed by the Group and the remaining franchised." A 2021 report by Israel's Alma Research and Education Center says "'Coral' owns 250 gas stations throughout Lebanon."
  - Market-share figures conflict: one outlet cites 14% of the Lebanese market, while The Century Foundation said Coral secured "around 80 percent of the country's fuel imports" during the crisis years.\[15\]\[16\] Treat both as indicative only.
- **Technology signals (thin; mostly inference):**
  - The public site is WordPress (PHP),\[3\] and ZoomInfo lists WooCommerce, WordPress and PHP among its technologies.\[17\]
  - The app's package name (`com.tedmob.coral.client`) suggests it was originally built by the Lebanese agency TedMob. The privacy policy says a group-linked company, "SMS", now "operates this mobile application", and some hosting sits on a petro-one.com subdomain. Petro One is a related diesel distributor that shares Coral's phone number.\[12\]\[18\]\[19\]
  - Coral and Liquigas have both advertised "Software Support Officer" roles. Coral's (December 2022) mentioned "assisting in the implementation of new software", which hints at internal system rollouts.\[20\]\[21\] Other Coral postings (Station Administrator: "end-of-day reconciliations", cash transactions, "labor shift schedules"; Accounting Officer: "processing invoices, recoding payments and tracking expenses") show the business processes internal apps would support.\[22\]
  - **No public source names Coral's ERP, POS or forecourt system, or the size of its IT team.** Don't claim knowledge of them in your application.
- **What a junior PHP developer there probably works on (inference):** internal admin portals and reports (sales, stations, deliveries), the corporate/fleet customer back office, APIs feeding or reading from the loyalty app and station systems, data exchange with an ERP or accounting database (possibly SQL Server), diesel-delivery order handling, and maintaining existing PHP/WordPress properties.

### 3. What hiring reviewers reward in junior portfolios (2025–2026)

- **Real problems beat clones.** Several 2026 guides make the same point: "Todo apps, weather apps, and calculator apps are the 'lorem ipsum' of developer portfolios". Tutorial clones signal "course completed," not "engineer who can ship."\[23\]\[24\] Build something that solves a real domain problem and uses a real API.\[25\]
- **Finished and deployed beats ambitious and abandoned.** "A live, working app with limited features is better than a half-finished ambitious project." Reviewers "would rather see one project deployed, tested, and documented than five abandoned experiments."\[23\]\[26\]
- **The README is the first impression.** Strong READMEs include an overview, key features, tech stack, install/usage (Docker or live demo), testing instructions and coverage. A repo with no README, or one a stranger can't understand in 30 seconds, gets skipped.\[27\]\[28\]
- **Decisions you can explain.** An original project "generates the thing interviews are made of: decisions you can explain."\[25\]
- **Laravel-specific signals:** eager loading to avoid N+1 queries, validation and security discipline, API design, Docker/DevOps awareness, and clean conventional structure.\[29\]
- **Common mistakes to avoid:** generic todo/blog/e-commerce clones, dead demo links, no tests, secrets committed to Git, one giant "initial commit", and unexplained copy-pasted code.\[24\]\[27\]

## Details

### 4. Recommended project: FleetFuel Portal

**Pitch.** *FleetFuel Portal is a B2B web application for a fuel distributor. Corporate customers manage their vehicles, drivers and fuel cards and set monthly liter/amount quotas. Stations post fuel transactions through a secured REST API. Customers order diesel delivery for generators and trucks. Managers get consumption, quota-breach and anomaly reports in both LBP and USD using a live exchange-rate feed.*

**Why this is the best choice:**
1. **Domain match.** It digitizes things Coral already does: the corporate fleet-expense service, EcoDiesel delivery (currently phone-based), and the dual LBP/USD pricing on its own site.\[8\]\[9\] An interviewer immediately sees you researched their business.
2. **Requirement coverage.** It naturally needs complex SQL (aggregations, window functions, quota checks), both building and consuming REST APIs, an "internal system" integration (the station POS feed) and an "external system" integration (the exchange-rate API), role-based security, AJAX-driven admin screens and testable business rules.
3. **Realistic scope.** The core is CRUD plus business rules plus reports, which a junior can finish in 2–3 weeks with Laravel. The stretch features let you go deeper without having to finish everything.
4. **Interview fuel.** Every feature produces a story: "how did you prevent duplicate transactions?", "how do you handle currency conversion?", "why did you index this column?"

**Naming and ethics:** Don't use Coral's name or logo or imply affiliation. Present it as "inspired by the corporate fleet-fuel services offered by Lebanese fuel distributors." Use fictional seed data.

#### Feature list mapped to the posting

| # | Feature | Job requirement(s) demonstrated |
|---|---|---|
| F1 | Auth with roles: **Admin** (distributor staff), **Company Manager** (corporate client), **Station Operator** | PHP/OOP, application security, Laravel |
| F2 | CRUD for companies, vehicles (plate, fuel type, tank capacity), drivers, stations, products | Develop modules/features, relational DBs, HTML/CSS/Bootstrap |
| F3 | Fuel cards with monthly liter and amount quotas, status (active/blocked) and product restrictions (e.g., diesel only) | Business logic in OOP (service classes), SQL constraints |
| F4 | **Station POS ingestion API** (`POST /api/v1/transactions`): token-authenticated, validated, idempotent via a unique `external_ref`, rejects over-quota or blocked cards with clear JSON errors | Develop REST APIs, integration with internal systems, HTTP methods/status codes, JSON, security |
| F5 | **Exchange-rate integration**: scheduled command fetches USD→LBP from a public API, stores daily rates, and converts all reports; falls back gracefully if the API is down | Consume REST APIs, integration with external systems, troubleshooting |
| F6 | Price history per product (LBP and USD, `effective_from`), used to price each transaction at the time it happened | SQL design, data integrity |
| F7 | **Diesel delivery orders**: client creates an order (address, liters, preferred slot), admin moves it through statuses (pending → scheduled → out for delivery → delivered/cancelled) with AJAX inline updates and an audit trail | Feature development, jQuery/AJAX, maintainable workflow code |
| F8 | **Reports dashboard**: monthly consumption per company/vehicle/product, quota-breach list, top stations, anomaly flags (liters > tank capacity, two fills within 30 minutes), CSV export | Write and maintain SQL queries, analytical skills |
| F9 | AJAX filters and live totals on transaction lists (date range, station, product) with server-side pagination | AJAX, jQuery, JavaScript, performance (eager loading) |
| F10 | Audit log of sensitive actions (quota changes, card blocks, order status changes) | Security, maintainability, "internal systems" mindset |
| F11 | Automated tests (feature tests for the API and quota rules; unit tests for services) run in GitHub Actions on each pull request | Basic testing before deploying, code review readiness |
| F12 | Postman collection + OpenAPI/Scribe docs | Postman, REST API documentation |
| F13 | Docker Compose (app, MySQL, optional SQL Server) | Docker, "works on reviewer's machine" |
| F14 | Git workflow: `main` protected, feature branches, PRs with descriptions, conventional commits, a CHANGELOG | Git, code reviews, company standards |

#### Recommended stack

- **Backend:** PHP 8.3+ with **Laravel 13**, installed via **Composer**. Laravel 13 was released on March 17, 2026 and requires PHP 8.3 as a minimum. If your host only offers PHP 8.2, use Laravel 12.\[30\]
- **Database:** **MySQL 8** as the primary database (window functions like `LAG()` are available in both MySQL 8 and SQL Server). Stretch goal: run the test suite against **SQL Server 2022** in Docker to prove portability.
- **Frontend:** Blade templates + **Bootstrap 5** + **jQuery** for AJAX (filters, inline status changes, live totals). Optional Chart.js for dashboard charts.
- **API:** Laravel API routes under `/api/v1`, API Resources for consistent JSON, Form Requests for validation, **Laravel Sanctum** personal access tokens for station clients (Sanctum issues tokens with abilities/scopes without the complexity of OAuth).\[31\]
- **Testing:** **Pest** or **PHPUnit** (Laravel ships with both), using SQLite in-memory for speed plus a MySQL CI job.
- **Tooling:** **Postman** collection (committed as JSON), Laravel Pint (code style, i.e., "company coding standards"), Larastan/PHPStan (static analysis), GitHub Actions CI.
- **DevOps:** **Docker** Compose (or Laravel Sail) with `app`, `mysql`, optional `sqlserver`, and `mailpit` services.

**Application-security measures (implement and document each):**
- **Authentication:** Laravel Breeze (session auth) for the web UI, with passwords hashed by bcrypt/argon2 through Laravel's `Hash`.
- **Authorization:** role-based Policies and Gates. A Company Manager can only see their own company's vehicles and transactions (tenant scoping via a global scope or explicit `where company_id`), and a Station Operator token can only post transactions.
- **Input validation:** Form Requests on every write, including API payloads, with validation rules for liters > 0, a valid card, and an ISO timestamp not in the future.
- **SQL injection prevention:** Eloquent/Query Builder parameter binding everywhere. Any raw SQL report uses bound parameters (`DB::select($sql, [$from, $to])`), never string concatenation.
- **CSRF protection:** Laravel CSRF tokens on all forms and jQuery AJAX calls (the `X-CSRF-TOKEN` header).
- **XSS:** Blade `{{ }}` escaping. Never use `{!! !!}` on user input.
- **Rate limiting:** named rate limiters on `/login` and the transaction API. The `auth:sanctum` middleware doesn't rate-limit on its own; you must add `throttle`.\[32\]
- **Idempotency:** a unique DB index on `(station_id, external_ref)` so retried POS calls can't double-charge a card.
- **Secrets:** `.env` excluded from Git, `.env.example` provided, API keys never committed.
- **Mass assignment:** explicit `$fillable` properties. Also enforce HTTPS in production, set security headers, and add an audit log for sensitive actions.

#### Database schema

Main tables and relationships:

- `companies` (id, name, tax_no, credit_limit_usd, status) — **1:N** `vehicles`, `drivers`, `fuel_cards`, `delivery_orders`, `users`
- `users` (id, name, email, password, role ENUM[admin, company_manager, station_operator], company_id NULL, station_id NULL)
- `vehicles` (id, company_id FK, plate_no UNIQUE, fuel_type, tank_capacity_l, odometer_km)
- `drivers` (id, company_id FK, name, phone, license_no)
- `fuel_cards` (id, company_id FK, vehicle_id FK NULL, driver_id FK NULL, card_no UNIQUE, monthly_limit_l, monthly_limit_usd, allowed_product_id NULL, status)
- `stations` (id, name, district, governorate, lat, lng, is_active)
- `products` (id, code [ULP95, ULP98, DIESEL], name, unit)
- `product_prices` (id, product_id FK, price_lbp, price_usd, effective_from) — **1:N** from `products`
- `exchange_rates` (id, base, quote, rate, rate_date UNIQUE(base, quote, rate_date), source)
- `fuel_transactions` (id, fuel_card_id FK, station_id FK, product_id FK, liters DECIMAL(10,2), unit_price_lbp, amount_lbp, amount_usd, odometer_km, external_ref, transacted_at; UNIQUE(station_id, external_ref); INDEX(fuel_card_id, transacted_at))
- `delivery_orders` (id, company_id FK, address, governorate, liters, preferred_date, status, assigned_truck NULL, delivered_at NULL)
- `delivery_status_history` (id, delivery_order_id FK, from_status, to_status, changed_by FK users, changed_at)
- `audit_logs` (id, user_id, action, auditable_type, auditable_id, old_values JSON, new_values JSON, created_at)

**Meaningful SQL queries/reports to include (write these as raw SQL in a `ReportRepository`, and mention them in interviews):**

1. **Monthly consumption per company and product (USD):**
```sql
SELECT c.name, p.code,
       SUM(t.liters) AS liters, SUM(t.amount_usd) AS amount_usd
FROM fuel_transactions t
JOIN fuel_cards fc ON fc.id = t.fuel_card_id
JOIN companies c  ON c.id = fc.company_id
JOIN products p   ON p.id = t.product_id
WHERE t.transacted_at >= ? AND t.transacted_at < ?
GROUP BY c.name, p.code
ORDER BY amount_usd DESC;
```
2. **Cards over their monthly quota:**
```sql
SELECT fc.card_no, fc.monthly_limit_l, SUM(t.liters) AS used_l
FROM fuel_cards fc
JOIN fuel_transactions t ON t.fuel_card_id = fc.id
WHERE t.transacted_at >= ? AND t.transacted_at < ?
GROUP BY fc.id, fc.card_no, fc.monthly_limit_l
HAVING SUM(t.liters) > fc.monthly_limit_l;
```
3. **Anomaly: fill larger than tank capacity:** join `fuel_transactions` → `fuel_cards` → `vehicles` `WHERE t.liters > v.tank_capacity_l`.
4. **Fuel efficiency (km per liter) with a window function**, which is portable to MySQL 8 and SQL Server:
```sql
SELECT vehicle_id, transacted_at, liters,
       odometer_km - LAG(odometer_km) OVER (PARTITION BY vehicle_id ORDER BY transacted_at) AS km_since_last
FROM v_vehicle_transactions;
```
5. **Top 10 stations by volume this month.** Note the dialect difference: `LIMIT 10` in MySQL vs `TOP 10` / `OFFSET … FETCH` in SQL Server. This is a great talking point for the MySQL-vs-SQL Server question.
6. **Delivery SLA:** average hours from `pending` to `delivered` per governorate, using `delivery_status_history`.

#### REST API endpoints (`/api/v1`, JSON, Sanctum-protected)

| Method | Endpoint | Purpose / consumer |
|---|---|---|
| POST | `/auth/token` | Issue a token (station client or company integration) |
| DELETE | `/auth/token` | Revoke the current token |
| GET | `/stations` | List active stations (filters: governorate) |
| GET | `/products/prices?date=` | Current or historical prices in LBP and USD |
| POST | `/transactions` | **Station POS posts a fill-up**. Returns 201, 409 (duplicate `external_ref`), 422 (validation) or 403 (card blocked / over quota) |
| GET | `/transactions?card=&from=&to=` | Company or admin transaction history (paginated) |
| GET | `/cards/{card_no}/balance` | Remaining liters/USD for the month (POS pre-authorization) |
| PATCH | `/cards/{id}` | Block/unblock, change quota (admin/company manager) |
| GET/POST | `/vehicles`, `/drivers` | Company fleet management |
| POST | `/delivery-orders` | Create a diesel delivery order |
| GET | `/delivery-orders/{id}` | Order status tracking |
| PATCH | `/delivery-orders/{id}/status` | Status transition (admin only; validated state machine) |
| GET | `/reports/consumption?group_by=company|vehicle|product&from=&to=` | Aggregated report JSON |
| GET | `/exports/transactions.csv?from=&to=` | "ERP export" feed for accounting |

Use correct verbs and status codes, consistent error envelopes, pagination metadata, and API Resources, so you can explain REST conventions confidently.

#### Integration component: internal and external systems

- **External system (consume):** a scheduled Artisan command (`rates:sync`, run daily through Laravel's scheduler) calls the **ExchangeRate-API open endpoint** (`https://open.er-api.com/v6/latest/USD`). It is free, needs no key, updates once per day, and requires attribution and caching.\[33\]\[34\] Store the rate in `exchange_rates`, use Laravel's HTTP client with timeout/retry, and fall back to the last known rate if the call fails (log a warning). Note in your README that Lebanon's official rate and market rates have diverged historically,\[35\] so the app also allows an **admin-entered override rate**. That is exactly the kind of business nuance interviewers like.
- **Internal system (expose):** a tiny separate **"Station POS simulator"** in plain PHP (a Composer project with Guzzle, in `/tools/pos-simulator`, or a Postman Collection Runner) that authenticates with a station token and posts random fill-ups, including deliberate duplicates and over-quota attempts. This shows your API handling another internal system's traffic.
- **Internal system (feed out):** the `/exports/transactions.csv` endpoint and an optional signed **webhook** (`transaction.created`, HMAC-SHA256 signature header) to a mock "loyalty/ERP" receiver. This mirrors how Coral's loyalty "steps" are credited automatically on each fill-up.\[13\]
- **Optional:** a Leaflet + OpenStreetMap station map (external JS library, no API key).

#### Phased build plan

**Week 1: Foundation and data (MVP part 1)**
- Day 1: Create the repo, Laravel 13 via Composer, Docker Compose (app + MySQL), Pint, a README skeleton and the branch protection rule. First PR: "project scaffolding".
- Days 2–3: Migrations, models and relationships, factories/seeders with realistic Lebanese station names and districts and fictional companies.
- Days 4–5: Breeze auth, roles, policies and tenant scoping; Bootstrap admin layout; CRUD for companies, vehicles, drivers, cards and stations.
- Tests: policy tests (Company A can't see Company B).

**Week 2: Business logic and API (MVP part 2)**
- `FuelTransactionService` (OOP service class): quota check, product restriction, idempotency, price and currency calculation.
- Sanctum tokens, `/api/v1/transactions` and card balance, API Resources, rate limiting.
- `rates:sync` external integration with fallback; price history.
- POS simulator script and Postman collection with environment variables and example tests.
- Feature tests for every API status path (201/409/422/403/401/429).

**Week 3: Reports, UI polish, delivery, shipping (MVP part 3)**
- Reports dashboard (raw SQL repository), CSV export, jQuery/AJAX filters and live totals.
- Diesel delivery orders with a status state machine and history.
- GitHub Actions CI (Pint + PHPStan + tests), deployment, screenshots/GIF, final README.

**Stretch features, in priority order:**
1. **SQL Server compatibility:** add an `mssql` service (`mcr.microsoft.com/mssql/server:2022-latest`) with the `sqlsrv`/`pdo_sqlsrv` extensions in the Dockerfile, plus a CI matrix running tests on MySQL **and** SQL Server. Document the dialect differences you hit. **This is the single highest-value stretch for this posting.**
2. Signed webhook to a mock loyalty/ERP receiver, plus a queue worker for retries.
3. **Station shift and cash reconciliation module** (opening/closing pump meter readings versus recorded sales, cash counted, variance report), inspired directly by Coral's Station Administrator posting.\[22\]
4. OpenAPI docs generated by Scribe, and a public docs page.
5. Leaflet station map; driver mobile-friendly view with a QR code on the fuel card.
6. **"Legacy refactor" exhibit:** a small `/legacy` folder with a deliberately messy procedural PHP report, and a PR that refactors it into a tested service. This speaks directly to "understand and modify existing code."

#### Free or low-cost deployment options (verify limits before you choose, because they change often)

- **Render (free web service + Docker):** no credit card, but Render's docs say it "spins down a Free web service that goes 15 minutes without receiving any inbound traffic," and spinning back up "takes about one minute." Recent reports say free bandwidth was cut to 5 GB and free Postgres expires after 30 days, so use an external MySQL. Put a "first load may take ~1 minute" note in the README.
- **Railway:** Railway's docs say new accounts get a one-time $5 trial grant, after which "the free trial reverts to the Free plan, which provides $1 of free credit per month," with no rollover. Fine for a short-lived demo during the hiring window.
- **Oracle Cloud Always Free VM** (card required):\[36\] run your Docker Compose stack yourself. It's the most "real server" experience and a good interview story.
- **Cheap shared hosting or a VPS (~$3–6/month) with PHP 8.3 + MySQL:** always on, which is the most reliable option while you apply.
- **Fallbacks:** a 2–3 minute Loom/YouTube walkthrough video and a GIF in the README, so a reviewer can see it working even if the demo is asleep.
- **Avoid assuming a free tier:** Koyeb reportedly removed its card-free plan in February 2026, and Heroku and Fly.io no longer offer free tiers for new users.\[36\]\[37\]

#### What to include in the GitHub README

1. **Title + one-line pitch + badges** (CI passing, PHP 8.3, Laravel 13, license).
2. **Live demo link + demo credentials** for each role (admin / company manager / station token), plus a sleep-time note.
3. **Screenshots/GIF:** dashboard, transaction list with AJAX filters, delivery workflow, and a Postman run.
4. **Problem and domain context:** "Fuel distributors sell corporate fleet-fuel services and diesel delivery; this portal…"
5. **Features list** (mirror the feature table) and an **architecture diagram** (web UI ↔ Laravel ↔ MySQL/SQL Server; POS simulator → API; exchange-rate API → scheduler; webhook → mock ERP).
6. **ER diagram** (dbdiagram.io or a Mermaid `erDiagram`).
7. **Quick start with Docker:** `git clone … && cp .env.example .env && docker compose up -d && docker compose exec app php artisan migrate --seed`.
8. **API docs:** endpoint table, auth instructions, the Postman collection JSON plus a "Run in Postman" guide, and sample requests/responses with error codes.
9. **Testing:** `php artisan test` (or `./vendor/bin/pest`), coverage summary, what's tested and why.
10. **Security section** listing each measure and where it lives in the code.
11. **SQL reports section** with 2–3 of the queries above and the indexes that support them.
12. **Design decisions and trade-offs** (why Sanctum over Passport, why idempotency keys, how currency conversion works, MySQL vs SQL Server differences).
13. **Git workflow** (branching, PR template, conventional commits), a **roadmap**, and **exchange-rate data attribution**.

### 5. Alternatives (and why they rank lower)

1. **Station shift and cash-reconciliation system** (pump meter readings, cash vs card, shift schedules, variance reports). Very domain-relevant, since it mirrors Coral's Station Administrator duties,\[22\] but it has less natural REST API and external-integration surface. Build it as **stretch module #3** rather than as the main project.
2. **Diesel delivery ordering and dispatch app** (customer orders, truck assignment, route list, status tracking). A good fit because Coral takes these orders by phone today,\[9\] but on its own it is lighter on reporting SQL and quota/business rules. It is already included as feature F7.
3. **Lubricants inventory / e-commerce store.** Relevant products, but it drifts toward a generic e-commerce clone, and Coral's web presence already runs on WordPress/WooCommerce.\[3\]\[17\] Low differentiation.
4. **Generic IT helpdesk/ticketing tool.** Shows CRUD, roles and Git, but has no link to the fuel business and is a common portfolio pattern.

## Recommendations

### 6. Presenting the project

**Resume bullets (adapt the numbers to what you actually build and measure):**
- "Built **FleetFuel Portal**, a **Laravel 13 / PHP 8.3** web application for corporate fleet-fuel management (companies, vehicles, fuel cards, diesel delivery), with **13 MySQL tables**, role-based access for 3 user types and a **Bootstrap/jQuery AJAX** admin UI."
- "Designed and documented a **REST API** (14 endpoints, **Sanctum** token auth, rate limiting, idempotent transaction ingestion) consumed by a **simulated station-POS system**; published a **Postman** collection and OpenAPI docs."
- "Integrated an **external exchange-rate REST API** through a scheduled job with retry and fallback to price all transactions in **LBP and USD**; wrote **SQL reports** (aggregations, HAVING, window functions) for consumption, quota breaches and anomaly detection."
- "Wrote **60+ Pest/PHPUnit tests** run in **GitHub Actions** on every pull request; containerized with **Docker** and verified compatibility with both **MySQL 8 and SQL Server 2022**; followed a feature-branch **Git** workflow with PR reviews and PSR-12 standards (Laravel Pint)."

Put the project in a "Projects" section above or next to Education, with the GitHub and live-demo links. Mirror the posting's keywords: PHP, OOP, Laravel, Composer, MySQL, SQL Server, REST APIs, JSON, Postman, Git, Docker, AJAX, jQuery, Bootstrap, application security, testing, code reviews.

**Application email** (to hr@coraloil.com):
- **Subject:** `VC24328 – Junior PHP Developer – [Your Name]`
- **Body (short):** "Dear Hiring Team, I'm applying for the Junior PHP Developer position (VC24328). I recently graduated in [degree] from [university]. To prepare for work on business applications like yours, I built FleetFuel Portal, a Laravel/MySQL system for corporate fleet-fuel accounts and diesel-delivery orders. It includes a secured REST API used by a simulated station-POS client, an external exchange-rate integration for LBP/USD pricing, SQL reporting, automated tests, Docker and SQL Server compatibility. Live demo: [link] · Code: [GitHub link] · 3-min walkthrough: [video]. I'd welcome the chance to discuss how I can support your development team. My CV is attached. Best regards, [Name, phone, LinkedIn]."
- Attach a PDF CV named `YourName_Junior_PHP_Developer_VC24328.pdf`.

**How to talk about it in the interview:**
- Prepare a **2-minute walkthrough**: problem → architecture → one hard decision (idempotency or currency) → testing → what you'd improve.
- Open the code live and show a PR where you fixed a bug, which demonstrates the troubleshooting and code-review mindset.
- Be honest about scope and trade-offs. Say what's simulated (the POS, the ERP) and how you'd adapt it to a real SQL Server ERP.

**Likely technical questions, and how the project prepares you:**

| Likely question | Your project-based answer |
|---|---|
| Explain OOP: encapsulation, inheritance, interfaces, polymorphism. Abstract class vs interface? | `FuelTransactionService`; an `ExchangeRateProvider` interface with an `OpenErApiProvider` implementation that is swappable and mockable in tests |
| What happens in an HTTP request? GET vs POST vs PUT vs PATCH vs DELETE; status codes 200/201/204/400/401/403/404/409/422/429/500 | Your API returns each of these, and you can show the tests |
| What is REST? What makes an API RESTful? How do you secure one? | Resource URIs, verbs, stateless tokens (Sanctum), validation, rate limiting |
| What is JSON? How do you consume an external API in PHP? | `rates:sync` with the HTTP client, timeouts, retries, fallback |
| Write a query: total per customer per month; find customers above a limit; INNER vs LEFT JOIN; GROUP BY vs HAVING; indexes | Your reports repository (queries 1–2) and the indexes on `(fuel_card_id, transacted_at)` |
| MySQL vs SQL Server differences? | `LIMIT` vs `TOP`/`OFFSET FETCH`, `AUTO_INCREMENT` vs `IDENTITY`, `NOW()` vs `GETDATE()`, the drivers you installed (`pdo_sqlsrv`) |
| How do you prevent SQL injection, XSS and CSRF? How are passwords stored? | Bound parameters, Blade escaping, CSRF tokens in AJAX headers, bcrypt hashing |
| What is the N+1 problem? | Eager loading (`with('card.company')`) on the transactions list, before/after query count |
| How would you debug a bug reported in production? | Reproduce, read logs, write a failing test, fix, PR, review, deploy. Show a real example from your commit history. |
| Git: merge vs rebase, resolving conflicts, what makes a good PR? | Your PR history and template |
| What's MVC? What does Composer do? What are migrations? | Laravel structure, `composer.json`/`composer.lock`, migration files |
| How do you test before deploying? | Feature + unit tests, CI pipeline, staging check |
| How would you integrate with an existing internal system (e.g., an ERP)? | CSV export + signed webhook + idempotent API; reading an external SQL Server connection via a second Laravel DB connection |
| Domain question: how would you model fuel sales across currencies? | Price history + stored exchange rate at transaction time, so reports are reproducible |

## Caveats
- **Company tech stack is inferred.** Coral hasn't published its internal stack. The SQL Server/.NET inference rests on the posting itself plus one staff member's public profile, and the app-vendor attribution rests on a package name and a privacy policy. Don't state these as facts to the employer. Instead, say "I made sure the app also runs on SQL Server since the posting mentions it."
- **Company size and market-share figures conflict** across Coral's own pages (600+ vs 1,000+ employees) and third-party sources (14% market share vs "around 80 percent" of imports during the crisis).\[5\]\[10\]\[15\]\[16\] None of this changes the project recommendation.
- **Free hosting terms change frequently** (several providers changed plans in 2024–2026).\[36\] Re-check before deploying, and keep a video fallback.
- **Numbers in resume bullets must be real.** Replace the example counts (tables, endpoints, tests) with what you actually built.
- **The project guarantees nothing.** It maximizes your evidence against the posting, but interview performance, degree fit and timing still matter. Apply promptly; the MVP can be submitted as soon as week 3 ends, and you can keep pushing stretch features after applying, since visible ongoing commits are a plus.

## Sources

1. [Contact Nicholas Rafka, Email: n\*\*\*@coraloil.com & Phone Number | Program Manager at The Coral Oil - ZoomInfo](https://www.zoominfo.com/p/Nicholas-Rafka/1326495885)
2. [Database: Getting Started | Laravel 10.x - The clean stack for Artisans and agents](https://laravel.com/docs/10.x/database)
3. [About Us - Coral Oil](https://www.coraloil.com/about-us/)
4. [Careers - Coral Oil](https://www.coraloil.com/careers/)
5. [Contact Us - Coral Oil](https://www.coraloil.com/contact-us/)
6. [Hezbollah Uses "Coral" and “Liquigas” as "Business Shields" - to Control Lebanon's, Energy Market - Alma Research and Education Center](https://israel-alma.org/hezbollah-uses-coral-and-liquigas-as-business-shields-to-control-lebanons-energy-market/)
7. [Services - Coral Oil](https://www.coraloil.com/services/)
8. [A Century In Energy](https://www.coraloil.com/)
9. [EcoDiesel Home Delivery - Coral Oil](https://www.coraloil.com/ecodiesel-home-delivery/)
10. [News & Media Archives - Coral Oil](https://www.coraloil.com/category/newsandmedia/)
11. [Coral Lebanon - Apps on Google Play](https://play.google.com/store/apps/details?id=com.tedmob.coral.client)
12. [Coral Lebanon - Apps on Google Play](https://play.google.com/store/apps/details?id=com.tedmob.coral.client&hl=en_US)
13. [Loyalty App - Coral Oil](https://www.coraloil.com/coral-loyalty-app/)
14. [Lebanon - Coral Oil](https://www.coraloil.com/lebanon/)
15. [An oligopoly of 14 companies rule oil sector Cartel share market and ditch Iraq’s energy supply deal - Nowlebanon](https://nowlebanon.com/an-oligopoly-of-14-companies-rule-oil-sector-cartel-share-market-and-ditch-iraqs-energy-supply-deal/)
16. [Lebanon’s Grid Has Collapsed. What Comes Next?](https://tcf.org/content/commentary/lebanons-grid-has-collapsed-what-comes-next/)
17. [The Coral Oil - Overview, News & Similar companies | ZoomInfo.com](https://www.zoominfo.com/c/the-coral-oil-company-ltd/351485349)
18. [About | Petro One](https://petro-one.com/about/)
19. <https://sms-lb.com/privacy-policy>
20. [Liquigas Liban sal | LinkedIn](https://www.linkedin.com/company/liquigas-sal)
21. [Company jobs - Lebanon | Tanqeeb.com](https://lebanon.tanqeeb.com/jobs/search?company=Company&change_lang=1)
22. [The Coral Oil Company Limited | LinkedIn](https://www.linkedin.com/company/coral-oil-company-limited-the-)
23. [How to Build a Developer Portfolio That Gets You Hired in 2026 - DEV Community](https://dev.to/_d7eb1c1703182e3ce1782/how-to-build-a-developer-portfolio-that-gets-you-hired-in-2026-396g)
24. [Developer Portfolio Examples and How to Build Yours — ProoV Journal](https://projectstudy.in/blog/developer-portfolio-examples-india)
25. [GitHub Portfolio That Gets Freshers Hired — Guide](https://cloudemyedge.com/blog/github-portfolio-fresher-developer-lucknow/)
26. [How to Build a Portfolio That Gets You Hired | Bright Coding](https://www.blog.brightcoding.dev/2026/09/04/how-to-build-a-portfolio-that-gets-you-hired)
27. [How to Build a GitHub Portfolio That Actually Gets You Hired as a Fresher in India (2026) | Ucanly AI](https://ucanly.io/blog/github-portfolio-gets-freshers-hired-india-2026)
28. [How to Organize GitHub Repos for Recruiter Review](https://www.resumly.ai/blog/how-to-organize-github-repos-for-recruiter-review)
29. [Hiring Laravel Developers in 2025: Key Skills Beyond PHP (AI Integration, Cloud, DevOps, Security) | by Ravi Mansuriya | Medium](https://medium.com/@RaviMansuriya/hiring-laravel-developers-in-2025-key-skills-beyond-php-ai-integration-cloud-devops-security-0b98bac5aa30)
30. [Laravel 13 released: features and upgrade guide](https://benjamincrozat.com/laravel-13)
31. [Laravel Sanctum | Laravel 12.x - The clean stack for Artisans and agents](https://laravel.com/docs/12.x/sanctum)
32. [Laravel Sanctum API Authentication: The Complete Production Guide - DEV Community](https://dev.to/dewaldhugo/laravel-sanctum-api-authentication-the-complete-production-guide-58ac)
33. [ExchangeRate-API Has a Free Endpoint — Get Live Currency Rates Without an API Key - DEV Community](https://dev.to/0012303/exchangerate-api-has-a-free-endpoint-get-live-currency-rates-without-an-api-key-12cl)
34. [ExchangeRate-API - Open Access, No Key Required](https://www.exchangerate-api.com/docs/free)
35. [Iranian fuel export to Lebanon](https://en.wikipedia.org/wiki/Iranian_fuel_export_to_Lebanon)
36. [Every Free Cloud Deploy Platform in 2026 — Ranked \[Full ...](https://snapdeploy.dev/blog/free-cloud-deployment-platforms-2026-comparison)
37. [Free hosting that doesn’t sleep in 2026: what’s actually left](https://livemy.app/blog/free-hosting-that-doesnt-sleep)
