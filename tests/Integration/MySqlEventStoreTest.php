<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Payroll\Application\Exception\ConcurrencyConflict;
use Payroll\Application\Port\EventStore;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\ManualAdjustmentAdded;
use Payroll\Domain\EarningLine\Event\SystemValueFrozen;
use Payroll\Domain\Money;
use Payroll\Infrastructure\EventStore\MySqlEventStore;
use Tests\Contract\EventStoreContract;
use Tests\TestCase;

/**
 * The same contract as the in-memory store, against a real MySQL database.
 *
 * This is the only place the optimistic concurrency guarantee is actually proven:
 * in memory it is a counter, here it is a unique index doing the work.
 */
final class MySqlEventStoreTest extends TestCase
{
    use EventStoreContract;
    use RefreshDatabase;

    protected function createStore(): EventStore
    {
        return new MySqlEventStore(
            $this->app->make(ConnectionInterface::class),
            $this->app->make(ConcurrencyErrorDetector::class),
        );
    }

    public function test_it_really_is_running_against_mysql(): void
    {
        // The stock Laravel phpunit.xml points tests at sqlite/:memory:, where this
        // whole suite would pass while proving nothing about the database we ship.
        self::assertSame('mysql', $this->app->make(ConnectionInterface::class)->getDriverName());
    }

    public function test_the_unique_index_exists(): void
    {
        $connection = $this->app->make(ConnectionInterface::class);

        $indexes = $connection->select('SHOW INDEX FROM domain_events WHERE Key_name = ?', ['uniq_stream_version']);

        // Without this index the optimistic concurrency check silently does nothing.
        self::assertCount(2, $indexes, 'Expected a two-column unique index.');
        self::assertSame(0, (int) $indexes[0]->Non_unique);
    }

    public function test_rows_are_written_exactly_as_the_schema_describes(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [
            new EarningLineCalculated(Money::fromDecimalString('1000.00')),
            new SystemValueFrozen(Money::fromDecimalString('1000.00')),
        ]);

        $rows = $this->app->make(ConnectionInterface::class)
            ->table('domain_events')
            ->where('stream_id', $id->toString())
            ->orderBy('stream_version')
            ->get();

        self::assertCount(2, $rows);
        self::assertSame('earning_line.calculated', $rows[0]->event_type);
        self::assertSame('earning_line.system_value_frozen', $rows[1]->event_type);
        self::assertSame(1, (int) $rows[0]->stream_version);
        self::assertSame(2, (int) $rows[1]->stream_version);

        // Logical names, never class names: a rename must not break stored history.
        self::assertStringNotContainsString('\\', $rows[0]->event_type);

        $payload = json_decode((string) $rows[0]->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(100000, $payload['system_value_minor_units']);
    }

    public function test_earlier_rows_are_untouched_by_later_appends(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();
        $connection = $this->app->make(ConnectionInterface::class);

        $store->append($id, 0, [new EarningLineCalculated(Money::fromDecimalString('1000.00'))]);
        $before = $connection->table('domain_events')->where('stream_id', $id->toString())->get()->toArray();

        $store->append($id, 1, [
            new SystemValueFrozen(Money::fromDecimalString('1000.00')),
            new ManualAdjustmentAdded(Money::fromMinorUnits(-4555), 'Reversing deduction'),
        ]);

        $after = $connection->table('domain_events')
            ->where('stream_id', $id->toString())
            ->where('stream_version', 1)
            ->get()
            ->toArray();

        // Append-only in the literal sense: the first row is byte for byte the same.
        self::assertEquals($before, $after);
    }

    public function test_a_duplicate_version_is_reported_as_a_concurrency_conflict(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [new EarningLineCalculated(Money::fromDecimalString('1000.00'))]);

        // Bypasses the store to force the exact database error the translation relies
        // on. If MySQL ever changes how it reports this, or the framework stops
        // recognising it, this test goes red rather than the conflict being mislabelled.
        $this->expectException(ConcurrencyConflict::class);

        $store->append($id, 0, [new EarningLineCalculated(Money::fromDecimalString('9999.00'))]);
    }

    public function test_a_non_ascii_comment_is_stored_without_mangling(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();
        $comment = 'Коригування — Zoë Müller 🙂';

        $store->append($id, 0, [
            new EarningLineCalculated(Money::fromDecimalString('1000.00')),
            new SystemValueFrozen(Money::fromDecimalString('1000.00')),
            new ManualAdjustmentAdded(Money::fromMinorUnits(-100), $comment),
        ]);

        // Read straight from the column, not through the serializer, so a broken
        // connection charset cannot be hidden by a symmetric round trip.
        $row = $this->app->make(ConnectionInterface::class)
            ->table('domain_events')
            ->where('stream_id', $id->toString())
            ->where('stream_version', 3)
            ->first();

        self::assertNotNull($row);
        self::assertStringContainsString($comment, (string) $row->payload);
    }
}
