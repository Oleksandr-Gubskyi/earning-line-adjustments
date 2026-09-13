# Earning Line Manual Adjustments

A proof of concept for manual corrections to payroll earning lines, modelled with an event-sourced
aggregate.

## The problem

A payroll line carries a base value the system calculates automatically. A payroll specialist
sometimes needs to correct it — a declined benefit, a late overtime approval — by entering a signed
amount with a mandatory comment explaining why.

Corrections accumulate over time and stay traceable forever: none is ever edited or deleted. A
mistake is fixed by adding a compensating correction, so both entries remain visible.

The rule that shapes the whole model: **once a line has received at least one manual correction,
automatic recalculation may never change it again.** The first correction permanently freezes the
last system-calculated value, even if the underlying source data changes later.

## Setup

Requires Docker. Nothing else needs to be installed -- PHP, Composer and MySQL all
run in containers.

```bash
git clone git@github.com:Oleksandr-Gubskyi/earning-line-adjustments.git
cd earning-line-adjustments

cp .env.example .env
docker compose up -d --build

docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

The app container waits for MySQL to report healthy before starting, so the
migration cannot race the database on a first run. Migrations are an explicit
step rather than a hidden entrypoint side effect.

Run the tests:

```bash
docker compose exec app vendor/bin/phpunit                      # everything
docker compose exec app vendor/bin/phpunit --testsuite=Unit     # domain only, no framework boot
docker compose exec app composer check                          # style, static analysis, tests
```

Integration tests run against MySQL in a separate `payroll_test` schema. They do
not run on SQLite: the schema uses MySQL specific types, and more importantly the
concurrency test would pass under SQLite while proving nothing about the database
this ships with.

Run the assignment scenario end to end:

```bash
docker compose exec app php artisan payroll:demo
```

It executes the eight steps from the specification through the real command
handlers against MySQL, prints the value after each step, renders the resulting
audit history, and checks the result against the expected `$1,104.45` -- exiting
non-zero if it ever stops matching. It prints the line id, which you can inspect
independently:

```bash
docker compose exec app php artisan payroll:show <lineId>
```

That command reads the persisted event stream and rebuilds the audit history from
it, in a separate process from the one that wrote it.

## Architecture summary

```
app/                  Laravel shell — Artisan commands, service provider, config
src/Domain/           pure PHP: EarningLine aggregate, Money, domain events
src/Application/      commands, handlers, ports, audit read model
src/Infrastructure/   MySQL event store, serializer, repository
```

`EarningLine` is an event-sourced aggregate root. Commands validate invariants and record domain
events; state changes only when those events are applied. The event stream in a single append-only
`domain_events` table is the source of truth, and the current value is always derived, never stored.

Laravel is a **thin shell**: Artisan, the service container, migrations and the test harness. The
domain and application layers contain no `Illuminate` imports at all — enforced in CI, and
demonstrated by domain tests that run without booting the framework.

CQRS is deliberately minimal: commands and handlers on the write side, a query that maps the event
stream to read DTOs on the other. There is no command bus and no query bus — one implementation
behind an interface would be ceremony, not separation.

Four domain events: `EarningLineCalculated`, `SystemValueRecalculated`, `SystemValueFrozen`,
`ManualAdjustmentAdded`. The first manual adjustment emits `SystemValueFrozen` and
`ManualAdjustmentAdded` in one atomic append, which lets the read side show the frozen base value
without re-implementing the freeze rule.

`ARCHITECTURE.md` covers the design in full, including the event store schema and the reasoning
behind each decision.

### Where the adjustment history lives

The aggregate holds three fields — the system value, whether it is frozen, and the adjustments
total. It does **not** hold a collection of adjustments.

Given the task is about a history of manual adjustments, this deserves an explicit answer: the
immutable history *is* the stream of `ManualAdjustmentAdded` events, and the read side rebuilds
amounts, comments and ordering from it. A collection inside the aggregate would be a second
representation of the same truth sitting next to the stream, and two representations drift.

The cost is real and worth stating: the aggregate cannot report how many adjustments it has without
consulting the event store, and the append-only guarantee rests on the stream rather than on the
absence of a mutator. Both are covered by tests.

## Assumptions

The specification leaves these open; each was decided deliberately.

- **Zero-amount adjustments are rejected.** Corrections are described as positive or negative, and an
  adjustment that changes nothing only pollutes an audit trail.
- **Rejected recalculations are not stored.** The specification requires a post-freeze recalculation
  to be ignored, not journalled, and the expected audit history does not contain it. `payroll:demo`
  reports step 4 as ignored because it demonstrates the scenario; `payroll:show` renders business
  audit history, which is not a log of every rejected command.
- **Single currency.** Every amount in the specification is USD, so there is no `Currency`
  abstraction.
- **No actor identity.** The specification names a payroll specialist but defines no users; modelling
  authentication would be inventing a requirement.
- **No timestamps in the audit output.** The expected table has none and no invariant depends on
  time. The store does record when each event was written.
- **`"Correcting mistake in adjustment #4"` stays free text.** A typed compensation link would need
  rules the specification does not define — whether a compensation can be compensated, whether
  amounts must be equal and opposite.
- **Adjustment numbering** is the ordinal among adjustment events in the version-ordered stream, not
  the stream version, which also counts calculation and freeze events.
- **A current value may be negative** — deductions are legitimate.

## Trade-offs

- **Event sourcing is not strictly required here.** A plain append-only ledger would satisfy every
  stated rule. It was chosen because it makes the append-only guarantee structural rather than
  conventional, keeps the history of system recalculations that a state model would overwrite, and
  matches the approach described for the production codebase. What it does not do is protect any
  invariant that a state model could not.
- **Laravel is not required either** — the specification says so explicitly. It is here as a thin
  shell because the domain model is what is being assessed and the framework costs the domain
  nothing. The boundary is verifiable rather than asserted.
- **The read side shares the write side's storage.** The query maps the event stream rather than a
  projection, so separation is at the model level, not the storage level. A projection table is the
  production next step.
- **Logical event names cover renames only.** Storing `earning_line.manual_adjustment_added` rather
  than a class name means renaming a class does not break reading history. Adding, retyping or
  removing a field is not covered — upcasting is out of scope.
- **Concurrency detection relies on one structural fact:** `domain_events` has exactly one unique key
  and no foreign keys, so a unique-constraint violation there can only mean a stream conflict. That
  is cheaper and more robust than parsing the index name out of a database message, and it is pinned
  by a test. A second constraint would require revisiting it.
- **Deliberately not built:** snapshots, projections, upcasting, an event bus, queues, an HTTP API,
  authentication. Each belongs in a system with more than one aggregate and more than one event
  consumer.
