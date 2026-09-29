# M05 — Atomic POS ingestion

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M05 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/04-BUSINESS-RULES.md, docs/05-API-CONTRACT.md, docs/07-TEST-PLAN.md.

Implement M05 only. Implement the complete ingestion algorithm, station-derived scope, canonical replay hashing, card/counter locks, snapshot amounts, bounded unique-race/deadlock handling, balance and read-only reconciliation. Replays return 200; changed payloads 409. Preserve immutable rows.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Run real MySQL overlapping-worker tests including a shared ref against different cards. Prove no double spend, replay after mutable changes, rollback consistency and Beirut month assignment.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

