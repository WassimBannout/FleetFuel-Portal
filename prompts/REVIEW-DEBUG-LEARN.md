# Reusable prompts

## Review the current milestone

```text
Read CLAUDE.md, docs/PROGRESS.md and the current milestone's specs.
Review the actual diff for correctness, tenant leaks, concurrency,
decimal/time mistakes and missing acceptance cases. Prioritize concrete
findings with file references and reproducible examples. Fix confirmed
issues with focused regression tests, rerun relevant checks, then update
the progress ledger. Do not add new product scope.
```

## Diagnose a failure

```text
The failing action and exact error are below. Reproduce it in the local
development/test environment, identify the cause, add a meaningful
regression test where appropriate, and fix it. Preserve unrelated work
and database volumes. Do not disable security or skip failing checks to
make the symptom disappear. Record the cause, fix and verification in
docs/PROGRESS.md.

[Paste the action, safe error text and relevant request ID here.]
```

## Understand code before advancing

```text
Using the implemented files from this milestone, trace one request from
route to response. Explain the main classes and one important tradeoff
in plain language. Then ask me three interview questions, one at a time,
and wait for my answers before evaluating them. Don't change code.
```

## Audit portfolio claims

```text
Compare README, résumé bullets and demo narration against implemented
routes, tests, CI evidence and deployment state. Replace unsupported
counts or claims with measured facts or explicit pending status.
Clearly identify the simulated POS and any optional unverified feature.
```
