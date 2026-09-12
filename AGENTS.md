# Agent entry point

Table of contents for agent-based tools. Read the linked documents rather than relying on this file
alone.

## Start here

| Document | What it holds |
|---|---|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | **Source of truth.** Boundaries, event sourcing, CQRS, invariants, event store design, key decisions and their trade-offs. |
| [`CLAUDE.md`](CLAUDE.md) | Working agreement, commands, coding rules, git conventions. Applies to any agent, not just Claude. |
| [`README.md`](README.md) | Reviewer-facing: problem statement, setup, assumptions. |

## What this project is

A proof of concept for payroll earning lines with manual corrections. A single event-sourced
aggregate (`EarningLine`), a MySQL event store, lightweight CQRS, and two Artisan commands. No HTTP
layer, no UI.

The defining business rule: the first manual correction permanently freezes the last
system-calculated value, and automatic recalculation may never change the line again.

## Hard rules

1. **`ARCHITECTURE.md` decisions are settled.** Disagree in writing; do not implement alternatives
   unilaterally.
2. **Never commit without the author's review.** Stop and ask.
3. **No floats for money.** Integer minor units only.
4. `src/Domain` and `src/Application` must not import `Illuminate`.
5. State changes only in `apply()`; replay never validates.

## Layout

```
app/                  Laravel shell — Artisan commands, service provider
src/Domain/           pure PHP, no framework
src/Application/      commands, handlers, ports, read model
src/Infrastructure/   MySQL event store, serializer, repository
tests/{Unit,Integration,Feature}
```

Autoload: `Payroll\` → `src/`.

## Commands

See [`CLAUDE.md`](CLAUDE.md#commands).
