# M03 — Fleet management UI

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M03 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/03-DATA-MODEL.md, docs/04-BUSINESS-RULES.md, docs/06-UI-SPEC.md.

Implement M03 only. Implement company/station/product/vehicle/driver/card screens and validation. Scope selectors as strictly as detail routes. Use shared card locks for quotas/status; audit changes and prevent ownership/used-card assignment changes. Archive records instead of deleting history.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Walk through company-to-card creation and a blocked card. Show T07/T08 and cross-tenant route checks.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

