# M06 — API and independent simulator

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M06 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/05-API-CONTRACT.md, docs/api/openapi.json, postman/README.md, docs/examples/fixtures.json.

Implement M06 only. Complete the milestone's API routes and resources. Build the standalone PHP/Guzzle POS CLI under tools/pos-simulator with scenario assertions and environment-only credentials. Validate/update OpenAPI and Postman against implemented responses; delivery/report endpoints remain planned until their milestones.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Run success/replay/conflict/blocked/quota scenarios on fresh dedicated fixtures. Show expected HTTP codes and one ledger increment. Record rerun requirements without resetting unrelated data.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

