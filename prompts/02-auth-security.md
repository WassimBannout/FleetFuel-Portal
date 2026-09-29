# M02 — Authentication and tenant isolation

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M02 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/01-PROJECT-BRIEF.md, docs/05-API-CONTRACT.md, docs/07-TEST-PLAN.md.

Implement M02 only. Implement Fortify session auth with Bootstrap views, role policies, explicit tenant scoping, disabled-user handling, Sanctum issue/revoke with fixed abilities/expiry, rate limits and API error envelopes. No public registration. Do not rely on token abilities alone.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Demonstrate manager A denied access to manager B's data, wrong-role denial and revoked-token denial. Explain authentication versus authorization.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

