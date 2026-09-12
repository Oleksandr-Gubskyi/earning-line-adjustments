# Instructions for Claude

## Before anything else

Read `ARCHITECTURE.md`. It is the source of truth for this system and the decisions in it are
settled.

## Working agreement

**Do not change approved decisions on your own.** If you believe a decision is wrong, say so and
wait — do not quietly implement an alternative. This applies especially to: dropping Laravel, MySQL
or Docker; adding a `ManualAdjustment` entity to the aggregate; storing a
`SystemRecalculationIgnored` event; introducing a command or query bus; adding snapshots or
projections.

**Stop before every commit.** The author reviews the working tree first. Never commit or push
without explicit approval.

**Stop after each infrastructure step** (scaffold, Docker, migrations) for the same reason.

## Commands

```bash
docker compose up -d                                  # MySQL + app container
docker compose exec app php artisan migrate           # run migrations explicitly
docker compose exec app vendor/bin/phpunit            # all tests
docker compose exec app vendor/bin/phpunit --testsuite=Unit   # domain only, no framework boot
docker compose exec app php artisan payroll:demo      # assignment scenario, prints the line id
docker compose exec app php artisan payroll:show {id} # audit history from the persisted stream
```

## Coding rules

- PHP 8.5. `declare(strict_types=1)` everywhere. `final` by default. `readonly` for value objects
  and events.
- **No floats for money, ever.** Integer minor units only. Parse decimal strings as strings.
- `src/Domain` and `src/Application` must not import `Illuminate`. CI enforces this.
- State changes only inside `apply()` methods. Command methods validate and record an event.
- `reconstitute()` calls `apply()` directly, never `recordThat()`.
- Replay never runs validation — recorded history is valid by definition.
- JSON encoding and decoding always use `JSON_THROW_ON_ERROR`, and only in infrastructure.
- Stream reads always carry an explicit `ORDER BY stream_version`.
- Comments explain **why**, never what. No docblocks that restate the signature.

## Tests

- Domain unit tests extend `PHPUnit\Framework\TestCase`, not Laravel's `TestCase`.
- Integration tests run against MySQL. The stock Laravel `phpunit.xml` points at SQLite — it must be
  changed, or concurrency tests will pass without touching MySQL.
- Test business rules from the assignment, not getters and not framework behaviour.

## Git

- Conventional Commits: `feat(domain): …`, `test(scenario): …`, `fix(infra): …`, `docs: …`.
- One commit per completed logical step, each with a green test suite.
- Never fabricate history with a bulk rebase at the end.
- Commit only after the author has reviewed the change.

## Out of scope

Do not add: HTTP API, controllers, frontend, authentication, Redis, queues, snapshots, projections,
upcasting infrastructure, or generic base classes. See `ARCHITECTURE.md` §10.
