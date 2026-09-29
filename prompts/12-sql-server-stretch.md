# S01 — Optional SQL Server compatibility

Paste this prompt into Claude Code, or ask it to execute this file:

```text
Read CLAUDE.md and docs/PROGRESS.md. Inspect the current working tree.
Read the S01 section of docs/08-BUILD-PLAN.md and its acceptance cases.
Also read: docs/02-ARCHITECTURE.md, docs/03-DATA-MODEL.md, docs/07-TEST-PLAN.md, docs/SOURCES.md.

Implement S01 only. Proceed only when the user requests S01 after the MySQL MVP. Verify current official driver/platform requirements, add an optional SQL Server profile, port actual migrations/queries/locking/error handling and run real integration/concurrency tests. Keep the default MySQL experience stable.

Work through code, meaningful tests and fixes; don't stop at a plan.
Preserve existing files and user changes. Use the documented stack and
rules. If a prerequisite is incomplete, finish or clearly diagnose it
before claiming this milestone works. Don't silently change scope.

Record tested SQL Server/ODBC/PHP driver versions, exact passing/failing checks and real SQL differences. An installed driver is not proof of compatibility.

Update docs/PROGRESS.md with actual changes, exact commands/results,
remaining gaps, the next action and a suggested commit message. Update
specs/contracts if an intentional decision changed. Explain the main
concept I should understand and how to verify the result. Stop after
this milestone. Do not claim checks or deployments you did not run.
```

