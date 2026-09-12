# Architecture

Source of truth for this system. Implementation follows this document; deviations require an
explicit decision, not an improvisation.

## 1. Problem

A payroll line carries a system-calculated base value. A payroll specialist may correct it manually
with a signed amount and a mandatory comment. Corrections accumulate, stay visible forever, and are
never edited or deleted — a mistake is fixed by adding a compensating correction.

The central rule: **once a line has received at least one manual correction, automatic system
recalculation may never change it again.** The first correction permanently freezes the last
system-calculated value.

At any point the system must expose the line's current value and the full audit history that
produced it.

## 2. Layering and boundaries

```
app/                       Laravel shell — Artisan commands, service provider
src/Domain/                pure PHP, zero Illuminate imports
src/Application/           use cases, ports, read model
src/Infrastructure/        MySQL event store, serializer, repository
```

Autoload: `Payroll\` → `src/`.

**The dependency rule:** `Domain` depends on nothing. `Application` depends on `Domain`. Only
`Infrastructure` and `app/` may reference Laravel. This is verified in CI by grepping `src/Domain`
and `src/Application` for `Illuminate`, and by the fact that domain unit tests run without booting
the framework.

Laravel is a delivery and persistence shell: Artisan, the service container, configuration,
migrations and the test harness. It does not shape the domain model.

## 3. Domain model

### Aggregate: `EarningLine`

State is deliberately minimal — only what is needed to make the next decision:

```php
Money $systemValue        // live before freeze, frozen value after
bool  $frozen
Money $adjustmentsTotal
```

plus identity, version and the uncommitted event buffer.

```
currentValue() = systemValue + adjustmentsTotal      // unconditional, never stored
```

There is no `frozenSystemValue` field: after the freeze the system value never changes again, so a
second field would encode the same number twice.

### Where the adjustment history lives

There is **no `ManualAdjustment` entity inside the aggregate.** The immutable history of corrections
*is* the stream of `ManualAdjustmentAdded` events. The read side reconstructs amounts, comments,
ordering and the audit view from that stream.

This is a deliberate trade-off and the most likely question a reviewer will ask, given the task is
titled *History of Manual Adjustments*. The reasoning: a collection inside the aggregate would be a
**second representation of the same truth** sitting next to the stream, and two representations
drift. The aggregate holds decision state; the stream holds history.

The honest cost: the aggregate cannot answer "how many adjustments do I have" without going to the
event store, and the append-only rule is no longer enforced by the absence of a mutator on a
collection — it rests on the append-only nature of the stream. Both are covered by tests (§8).

### Value objects

- **`Money`** — integer minor units, signed, immutable. No floats anywhere.
  `fromDecimalString()` parses **as a string**, never via float: `(int)((float)"0.29" * 100)` yields
  `28`, and this silently corrupts roughly one amount in fifteen.
- **`AdjustmentComment`** — required, non-empty after trim, valid UTF-8. Validates on the command
  side only (see §6).
- **`EarningLineId`** — UUID.

### Domain events

```
EarningLineCalculated       initial system calculation
SystemValueRecalculated     recalculation while not frozen (replaces the value)
SystemValueFrozen           the freeze, as a first-class fact
ManualAdjustmentAdded       Money $amount, string $comment
```

`SystemValueFrozen` exists so the read side can show the frozen base value **without
re-implementing the freeze rule**. Without it the query would have to scan for "the last
recalculation before the first adjustment" — putting the most important business rule in two places.

The first manual adjustment emits **two events in a single atomic append**:

```
SystemValueFrozen
ManualAdjustmentAdded
```

### Rejected recalculation is not recorded

A recalculation attempted after the freeze changes no state. `EarningLine::recalculate()` returns a
domain result:

```php
enum RecalculationResult { case Applied; case IgnoredBecauseFrozen; }
```

The decision belongs to the aggregate, never to a handler — otherwise the central invariant leaves
the domain.

No `SystemRecalculationIgnored` event is stored. The assignment requires the recalculation to be
ignored, not journalled. `payroll:demo` reports step 4 as ignored because it demonstrates a
scenario; `payroll:show` renders persisted business audit history, which is not a log of every
rejected command. If production required auditing rejected attempts, that would be a separate
operational concern.

## 4. Invariants

Enforced by the aggregate unless noted.

1. A line is created exactly once — private constructor plus a static factory, so a second
   `EarningLineCalculated` inside a stream is structurally impossible.
2. While not frozen, recalculation **replaces** the system value; it never accumulates.
3. The first manual adjustment **permanently** freezes the current system value.
4. After the freeze, recalculation changes nothing and emits no event.
5. The freeze is irreversible — there is no unfreeze method, by construction.
6. Adjustments are append-only: no update, no delete, no reordering.
7. Adjustment amount must be non-zero; negative amounts are valid.
8. Comment is required and non-empty after trim.
9. `currentValue()` is always derived, never stored as truth.
10. Loading a non-existent line raises `EarningLineNotFound` — enforced by the **repository**, since
    "not found" is not something an aggregate can assert about itself.

## 5. CQRS

Intentionally small, with no command or query bus.

**Write:** `Command` → `Handler` → `EarningLine` → events → `EventStore`.
**Read:** `EarningLineAuditHistory` reads the stream and maps it to DTOs. It never loads the
aggregate and never returns it.

This is separation at the **model** level, not at the storage level — the read side still depends on
the event classes and their serialization. A projection table would be the production next step and
is deliberately out of scope.

### Audit numbering

`Adjustment #1 … #N` is the **ordinal among `ManualAdjustmentAdded` events** in the version-ordered
stream. It is *not* the stream version: the stream also holds calculation and freeze events, so the
first adjustment typically sits at version 4.

## 6. Event store

```php
interface EventStore {
    public function load(EarningLineId $id): EventStream;
    public function append(EarningLineId $id, int $expectedVersion, array $events): void;
}
```

`EventStream` carries envelopes and the current version; it validates version contiguity on
construction:

```php
RecordedEvent { DomainEvent $event; int $streamVersion; DateTimeImmutable $recordedAt; }
EventStream   { RecordedEvent[] $events; int $currentVersion; }
```

Store metadata stays out of the domain events themselves.

### Schema

```sql
CREATE TABLE domain_events (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stream_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  stream_version INT UNSIGNED NOT NULL,
  event_type     VARCHAR(100) NOT NULL,
  payload        JSON         NOT NULL,
  recorded_at    DATETIME(6)  NOT NULL,
  UNIQUE KEY uniq_stream_version (stream_id, stream_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- First event is `stream_version = 1`; `expectedVersion` for a new stream is `0`.
- **Reads always use an explicit `ORDER BY stream_version`.** Everything depends on that order,
  including adjustment numbering. `recorded_at` is unusable for ordering — `NOW(6)` is evaluated
  once per statement, so every row of a batch insert shares a timestamp.
- No separate index on `stream_id`: the unique key's left prefix serves stream reads.
- `PRIMARY KEY (id)` for monotonic inserts, not for index size — both layouts measure the same.
- `id` is a display tie-break, **not** a cursor for projections: with
  `innodb_autoinc_lock_mode=2` values are assigned at insert time, not at commit time.
- `JSON` over `TEXT` for write-time validation: a serializer bug fails at append rather than years
  later during replay of an immutable log.
- `utf8mb4` explicitly — a comment is free text in an immutable log, where an encoding mistake is
  unrecoverable by definition.

### Serialization

Domain events know nothing about JSON. `EventSerializer` holds an explicit `match` over the four
event types, mapping to and from a logical type name and a primitive payload. JSON encoding lives
entirely in infrastructure and always uses `JSON_THROW_ON_ERROR`.

Logical names (`earning_line.manual_adjustment_added`) rather than FQCNs, so renaming a class does
not break reading recorded history. This is the only schema-evolution scenario covered — adding,
retyping or removing a field is not, and upcasting is deliberately out of scope.

**Which values an event carries:** a value object only when its reconstruction is total.
`Money::fromMinorUnits(int)` cannot fail, so events carry `Money`. `AdjustmentComment` has a
validating constructor, so the event carries a **primitive string** — the command side validates
input before the event exists, and a recorded event is a historical fact. **Replay never runs
validation:** otherwise tightening a rule later would make old streams unreadable.

### Concurrency

Optimistic, with the unique index as the sole arbiter.

- `Illuminate\Database\UniqueConstraintViolationException` → `ConcurrencyConflict`
- `Illuminate\Database\DeadlockException` → `ConcurrencyConflict` (crossing batches can produce
  1213/40001 rather than 1062)
- anything else propagates

Catch the specific type **above** `QueryException`, which it extends.

Laravel already narrows the first case to an actual unique violation rather than any integrity
error. We do not additionally check the index name: that value is parsed out of the server's message
text and is brittle across drivers and versions. Instead we rely on a structural fact — this table
has exactly one unique key and no foreign keys — and pin it with a test that provokes a real
duplicate key. **If a second constraint is ever added, this assumption must be revisited.**

`load()` and `append()` never share a transaction: `innodb_lock_wait_timeout` defaults to 50
seconds, so a competing writer would hang rather than fail fast.

## 7. Replay rules

- `reconstitute()` calls `apply()` directly and **never** `recordThat()` — otherwise replayed events
  land in the uncommitted buffer and get written to the stream a second time.
- Command methods validate invariants and record an event. **State changes only in `apply()`.**
- After a successful append the aggregate commits: the buffer is cleared and the version advances.

## 8. Testing

Domain unit tests use `InMemoryEventStore` and never boot Laravel. Integration tests run against
MySQL — note that the default Laravel `phpunit.xml` points at SQLite, which would let the
concurrency test pass without ever touching MySQL.

Tests that matter:

- **Assignment scenario** — the eight steps, at the aggregate level and end-to-end, asserting
  `$1,104.45` and the full audit history.
- **Replay equivalence** — live and reconstituted aggregates agree on every field and the version;
  `releaseEvents()` on a reconstituted aggregate is **empty**; a further command produces identical
  events.
- **Event store contract suite** — run against both `InMemoryEventStore` and `MySqlEventStore`, so
  two implementations of one port are proven to behave alike. In-memory must enforce
  `expectedVersion` too.
- **Money** — the values a naive float parser breaks on: `0.29`, `1.15`, `8.20`.
- **Serializer** — round-trip over every event class, so a missing map entry fails loudly.
- **Append-only proxies** — `EventStore` exposes no update or delete; earlier rows are byte-identical
  after later appends.
- **Ordering** — `load()` returns events by version, not by insertion order.
- **Round-trip with a non-ASCII comment.**
- Read-model `currentValue` equals the aggregate's, catching drift between the two folds.

## 9. Assumptions

- Zero-amount adjustments are rejected; the assignment describes corrections as positive or negative.
- Single currency; no `Currency` abstraction.
- No actor identity — the assignment does not specify one, and inventing an auth concern would be
  scope creep.
- No timestamps in the audit output; the expected table has none and no invariant depends on time.
- `"Correcting mistake in adjustment #4"` stays free business text; no typed compensation link,
  since the assignment defines no rules for such a relationship.
- Current value may be negative — deductions are legitimate.
- Terminology: the assignment body says *correction*, its tables say *Adjustment*; the code follows
  the tables.

## 10. Deliberately not built

Snapshots · projections · upcasting · event bus · queues · HTTP API · authentication · multi-tenancy
· generic aggregate base class · command/query buses.

Each would be justified in a production system with more than one aggregate, durable projections and
multiple event consumers. None earns its place in a proof of concept for a single aggregate.
