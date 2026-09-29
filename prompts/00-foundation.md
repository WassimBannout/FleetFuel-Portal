# M00 — Foundation

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the M00 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/02-ARCHITECTURE.md, docs/SOURCES.md.

Implement M00 only. Check the actual environment. Scaffold Laravel safely into this nonempty root using a PHP-compatible Composer container. Implement the Docker/Makefile/test-database contract, locked dependencies, minimal CI and health page. Preserve every handoff file; merge README/.gitignore. Do not install host-wide tooling to bypass container issues.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Show the running local URL, resolved versions and exact setup/quality-check results. Repeat setup to verify it preserves APP_KEY and data.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

