# M09 — Dashboard and usable UI

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M09 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/06-UI-SPEC.md, docs/07-TEST-PLAN.md.

Implement M09 only. Finish the role-scoped dashboard, responsive Bootstrap navigation, AJAX filters/totals with stale-response protection, accessible forms and clear loading/empty/error states. Use escaped dynamic text and CSRF for browser writes. Capture real screenshots.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Run the five-minute walkthrough for all roles and manually check mobile/keyboard/expired-session/error flows. Explain query-count and filtering decisions.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

