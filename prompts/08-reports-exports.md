# M08 — SQL reporting and CSV

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M08 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/03-DATA-MODEL.md, docs/04-BUSINESS-RULES.md, docs/05-API-CONTRACT.md, docs/07-TEST-PLAN.md.

Implement M08 only. Implement bound SQL in ReportRepository for all listed reports, ID-based grouping, snapshot ownership, deterministic windows, correct predecessor lookback, tenant-scoped filtered totals and safe streaming CSV. Document actual query plans/indexes. Do not reprice history.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Verify report and CSV totals against fixtures and cross-tenant tests, quota reduction report, rapid-fill boundary, CSV formula neutralization and delivery SLA.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

