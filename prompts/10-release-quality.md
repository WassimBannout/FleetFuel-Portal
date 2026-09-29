# M10 — Release verification

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M10 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/07-TEST-PLAN.md, docs/09-OPERATIONS-AND-PORTFOLIO.md, docs/api/openapi.json.

Implement M10 only. Audit all implemented acceptance cases, run complete CI-equivalent checks and fix actual findings with regression tests. Rehearse a clean checkout using isolated Compose volumes/ports. Validate route-contract and documentation-command parity. Record a real troubleshooting example.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Provide exact outcomes for lint, analysis, MySQL feature/concurrency tests, assets and clean setup. Mark missing evidence honestly; do not invent coverage, CI runs or SQL Server support.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

