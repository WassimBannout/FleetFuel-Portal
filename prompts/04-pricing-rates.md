# M04 — Historical pricing and exchange rates

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M04 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/03-DATA-MODEL.md, docs/04-BUSINESS-RULES.md, docs/SOURCES.md.

Implement M04 only. Implement decimal arithmetic, effective price resolution, ExchangeRateProvider, validated observation persistence, fixture/live modes, scheduled sync, bounded fallback and expiring audited manual overrides. Do not fetch FX in transactions or page requests. Add attribution where rates are used.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Verify exact 20-liter sample, boundary rounding, timeout/retry behavior, cache expiry and manual precedence with faked HTTP. Label any live sync separately.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

