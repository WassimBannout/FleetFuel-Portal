# M01 — Data model and fixtures

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M01 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/03-DATA-MODEL.md, docs/06-UI-SPEC.md, docs/examples/fixtures.json.

Implement M01 only. Implement the schema, models, enums, relationships, constraints, factories and guarded repeatable demo seeding. Include all ledger snapshots and counters now. Separate historical dashboard data from simulator cards. Use a fixture builder until the domain service exists, keeping counters consistent.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Show migrations on MySQL, seed counts, local login instructions and the ER diagram. Explain ownership constraints and why financial history is append-only.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

