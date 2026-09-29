# M07 — Delivery state machine

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M07 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/03-DATA-MODEL.md, docs/04-BUSINESS-RULES.md, docs/05-API-CONTRACT.md, docs/06-UI-SPEC.md.

Implement M07 only. Implement scoped orders, lifecycle service, expected_status checks, scheduler/truck requirements, cancellation rules, atomic history/audit and Bootstrap/AJAX screens. Fulfillment does not debit a fuel card.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Demonstrate pending→scheduled→out_for_delivery→delivered, manager cancellation and rejected skip/stale/terminal changes. Run concurrent transition test.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

