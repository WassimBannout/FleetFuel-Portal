# Screenshots (M09)

Real screenshots of the application, taken on 2026-10-01 in Chrome through the Chrome DevTools protocol, at desktop size (1366 × 900) and phone size (390 × 844).

The data is a freshly seeded demo database (`php artisan demo:seed`, as of that day) on a throwaway copy of the app, plus what the M09 walkthrough added: one POS purchase (20.00 L on `FF-ATLAS-001`, through the POS simulator) and one delivery order (#7). That copy was served on port 8091, which appears in the station page's example `curl` command. All companies, people and prices are fictional.

| File | What it shows |
| --- | --- |
| `dashboard-admin.webp` | Admin dashboard: this month next to last month, quota warnings, open deliveries, the USD/LBP rate in use with its source, and the latest purchases with each rate's source |
| `dashboard-manager-mobile.webp` | The Atlas manager's dashboard on a phone: Atlas only |
| `menu-mobile.webp` | The navigation as an off-canvas menu on a phone |
| `transactions-manager.webp` | The transaction list filtered to diesel: totals for the whole filter and the matching CSV link |
| `transactions-offline-error.webp` | The same list with the network offline: the old totals and rows are removed and **Try again** is offered |
| `purchase-detail-rate-provenance.webp` | A purchase converted at a manual-override rate, with its stored amounts |
| `delivery-timeline-manager.webp` | A delivery order's timeline, seen by the manager after the admin delivered it |
| `audit-log-admin.webp` | The audit log with its filters and before → after values |
| `station-operator.webp` | A station operator's home page: their own station only |

The images are WebP (quality 88–90) to keep the repository small.
