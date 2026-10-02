# Demo script (about three minutes)

A walkthrough of the real application on fictional demo data, following the narration outline in docs/09-OPERATIONS-AND-PORTFOLIO.md. It works on the local stack today. On a hosted demo, use the same steps once one exists; there is no live URL yet.

## Before recording

- Start from a freshly seeded demo, so the simulator cards are unused and the dashboard has its history:
  - a new local copy (`make setup`), or the local production rehearsal stack;
  - on an existing database, add fresh simulator cards instead: `docker compose exec app php artisan demo:simulator-cards`.
- Sign the browser in with a password manager, or type the password off-screen. Never show `.env`, a token or the demo password in the recording. The simulator reads its password from the environment, as below.
- Use a 1366 × 900 browser window and a terminal with a large font.
- Rehearse once without recording. The steps below were each run in milestone M09's walkthrough and M11's rehearsal.

## Script

**0:00–0:25: the problem and the three roles.**
- Open http://localhost:8080/login.
- Say: "FleetFuel Portal is a fictional fuel distributor's portal. Companies run their vehicles on fuel cards with monthly quotas, stations sell fuel through a point-of-sale terminal, and managers order diesel deliveries. There are three roles: the distributor's admin, a company's fleet manager and a station operator. Everything you see is fictional."

**0:25–1:00: the manager's fleet and the tenant boundary.**
- Sign in as `manager.atlas@fleetfuel.test`. The dashboard shows Atlas Logistics only: this month next to last month, quota warnings and open deliveries.
- Open **Cards**, then `FF-ATLAS-001`: it has a 100.00 L monthly limit and nothing used yet.
- Say: "A manager sees only their own company. Asking for another company's record by its address returns 'not found', the same page as a record that does not exist."

**1:00–1:45: the POS purchase, the retry and the declines.**
- In the terminal:

  ```bash
  export POS_EMAIL=operator.beirut@fleetfuel.test
  export POS_PASSWORD="$(sed -n 's/^DEMO_PASSWORD=//p' .env)"
  make simulate
  ```

  The output shows:
  - `201 Created`;
  - the replay with `Idempotency-Replayed: true`;
  - a `409` for a changed request under the same reference;
  - a `403` for the blocked card and another for the exhausted quota;
  - `Result: 5 scenarios passed`.
- Reload the card page: 20.00 L used, 80.00 L left, one purchase listed.
- Say: "The terminal retried, and the card was charged exactly once. The card row is locked during each purchase, so two stations at the same moment cannot overspend the quota either. The tests prove it with genuinely parallel requests."

**1:45–2:15: a delivery and a report.**
- Open **Deliveries**, then one of the orders. Show the timeline: who changed the status, and when.
- Open **Reports**, then **Consumption**, then **Download CSV**. The CSV's totals match the table.
- Say: "Deliveries move through a fixed sequence of statuses. A stale browser tab cannot overwrite a newer status: the server refuses it with 409. Reports are plain SQL with bound values; the CSV streams every matching row and neutralizes spreadsheet formulas."

**2:15–3:00: snapshots, locking, a real bug, and what is simulated.**
- Open **Transactions** and then a purchase. Show its stored price, its exchange rate and the rate's source badge.
- Say:
  - "Each purchase stores the price and the USD/LBP rate of its own time. A new price never changes old purchases, and without a valid rate a purchase is refused rather than converted at a guessed one."
  - "One real bug from release testing: a failed database query's log message contained the bound card number. One configuration line masks it, and two tests keep it masked (docs/DEBUGGING-STORY.md)."
  - "What is simulated: the station terminal is a separate PHP program, and the exchange rates here are labelled fixtures."

## Notes for a live or hosted demo

- Follow the demo policy the owner chooses (docs/RUNBOOK.md, "Demo deployment"). The recommended default publishes only the manager accounts.
- Show the admin and POS parts in this recording instead of on the public site.
- Label the recording as a recording of a local run, not a live deployment.
