# Build FleetFuel Portal with Claude Code

This folder is a complete **implementation handoff**, prepared on 2026-09-28. The application has not been built yet. Everything needed to direct the build is here; you do not need the previous chat or the file in Downloads.

## 1. Open Claude Code in this folder

```bash
cd /home/wassim/code/FleetFuel-Portal
claude
```

Claude Code is already installed on this machine. Complete its sign-in if prompted. It reads the project `CLAUDE.md` instructions when started here. See the official [project memory guide](https://code.claude.com/docs/en/memory) and [quickstart](https://code.claude.com/docs/en/quickstart).

## 2. Paste this first message

```text
Read CLAUDE.md, docs/PROGRESS.md, docs/01-PROJECT-BRIEF.md,
docs/02-ARCHITECTURE.md, and docs/08-BUILD-PLAN.md.
Then execute prompts/00-foundation.md, implementing M00 only.
The existing files are a handoff kit, not a Laravel application.
Preserve the kit while bootstrapping Laravel at the repository root.
Use the Docker-based setup because host Composer is not installed.
Make the changes, run the milestone checks, fix failures, and update
docs/PROGRESS.md with evidence and the next action. Explain briefly what
you built and what I should understand. Stop after this milestone.
```

Do not paste all the prompts at once. Each milestone produces a small result you can run, inspect, and understand.

## 3. Build in order

The complete deliverables and pass conditions are in [the build plan](docs/08-BUILD-PLAN.md). Send the matching prompt filename to Claude for each step.

| Step | Prompt | Result |
| --- | --- | --- |
| M00 | [00-foundation](prompts/00-foundation.md) | Laravel, Docker, frontend build, initial CI |
| M01 | [01-data-model](prompts/01-data-model.md) | Schema, models, fixtures, demo data |
| M02 | [02-auth-security](prompts/02-auth-security.md) | Login, roles, tenant isolation |
| M03 | [03-fleet-crud](prompts/03-fleet-crud.md) | Companies, stations, fleet and cards UI |
| M04 | [04-pricing-rates](prompts/04-pricing-rates.md) | Historical pricing and exchange-rate integration |
| M05 | [05-pos-transactions](prompts/05-pos-transactions.md) | Atomic quotas and idempotent POS ingestion |
| M06 | [06-api-tooling](prompts/06-api-tooling.md) | Remaining fleet API, Postman and PHP POS simulator |
| M07 | [07-delivery-orders](prompts/07-delivery-orders.md) | Delivery workflow and status history |
| M08 | [08-reports-exports](prompts/08-reports-exports.md) | SQL reports and CSV exports |
| M09 | [09-ui-polish](prompts/09-ui-polish.md) | AJAX filtering, dashboard and accessible UI |
| M10 | [10-release-quality](prompts/10-release-quality.md) | Full CI, security review and clean-clone rehearsal |
| M11 | [11-shipping-portfolio](prompts/11-shipping-portfolio.md) | Deployment runbook, demo and honest portfolio evidence |
| S01 | [12-sql-server-stretch](prompts/12-sql-server-stretch.md) | Optional, separately verified SQL Server compatibility |

To advance, paste:

```text
Read CLAUDE.md and docs/PROGRESS.md. Execute the next incomplete MVP
milestone in docs/08-BUILD-PLAN.md using its matching file in prompts/.
Implement it, run its checks, fix failures, update the progress file,
and stop after that milestone. Explain the main decision and show me
how to verify the result locally.
```

## 4. End or resume a session

Before closing Claude:

```text
Update docs/PROGRESS.md with the exact current state, changed files,
commands run and their results, remaining failures, and the next action.
Do not mark incomplete work done. Give me one prompt to resume.
```

Next time, open this folder and run `claude`, then paste:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the working tree and resume
the first incomplete milestone, using its prompt and acceptance checks.
Do not restart the project. Verify the recorded state before continuing.
```

`claude -c` can also continue the most recent conversation in this directory; the progress file remains the durable handoff. See [Claude Code quickstart](https://code.claude.com/docs/en/quickstart).

## 5. What is included

- A digested product brief, architecture, database design with ER diagram, business rules, API contract, UI specification, testing matrix and deployment guide.
- A milestone plan with acceptance gates, 13 copy-ready build prompts, and review/debug/learning prompts.
- A machine-readable OpenAPI contract, a Postman collection and local environment template, deterministic example fixtures, and a preserved copy of your original brief.
- A persistent progress ledger and decision record to keep future sessions consistent.
- PR/issue templates, release and interview guidance, and a read-only prerequisites script.

All implementation choices are defaults you can change deliberately in `docs/DECISIONS.md`. The refined specifications take precedence over contradictions in the source research.

## Your machine at preparation time

| Tool | Observed |
| --- | --- |
| Claude Code | 2.1.278 |
| PHP | 8.3.6 |
| Composer | Not found on PATH; use the container |
| Node | 24.13.0 |
| Docker / Compose | 29.7.2 / 5.1.4 |
| Git | 2.55.0 |

Docker daemon access and Claude account access have not been tested. M00 checks them. There was no application or Git repository in this directory when the kit was prepared.

Run `bash scripts/check-prerequisites.sh` for a fresh, read-only check. Installing dependencies needs internet access; later application tests should use faked external responses.

## Pace and completion

The research estimated 2–3 weeks at 15–25 hours/week. Treat that as an optimistic scope estimate, not a promise. Work through the acceptance gates at your own pace; split a milestone across sessions when needed. SQL Server follows a finished MySQL MVP.

After each milestone, run the feature yourself and explain its main rule in your own words. A project you can explain is more useful in an interview than one you merely generated.
