# M11 — Shipping and presentation

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M11 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/09-OPERATIONS-AND-PORTFOLIO.md, docs/DECISIONS.md, .github/pull_request_template.md.

Implement M11 only. Prepare and locally verify production image, deploy/rollback/scheduler/backup-restore runbooks, actual README, screenshots, demo script and truthful résumé bullets. Finish this concrete release candidate before asking for hosting/account/publishing decisions. Publish only when the user requests it.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Distinguish locally verified release preparation from a real deployment. Record live URL/smoke test/CI evidence only if achieved; otherwise retain those tasks as pending.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

