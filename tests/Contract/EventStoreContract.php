<?php

declare(strict_types=1);

namespace Tests\Contract;

use Payroll\Application\Exception\ConcurrencyConflict;
use Payroll\Application\Port\EventStore;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\ManualAdjustmentAdded;
use Payroll\Domain\EarningLine\Event\SystemValueFrozen;
use Payroll\Domain\EarningLine\Event\SystemValueRecalculated;
use Payroll\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every EventStore must have, run against each implementation.
 *
 * Two implementations of one port are worth nothing without this: the in-memory
 * store is only a legitimate stand-in for the fast suite if it is held to exactly
 * the same rules as the one that talks to a database.
 */
abstract class EventStoreContract extends TestCase
{
    abstract protected function createStore(): EventStore;

    public function test_an_unknown_stream_is_empty(): void
    {
        $stream = $this->createStore()->load(EarningLineId::generate());

        self::assertTrue($stream->isEmpty());
        self::assertSame(0, $stream->currentVersion);
        self::assertSame([], $stream->events);
    }

    public function test_appended_events_come_back_in_order(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [
            new EarningLineCalculated(Money::fromDecimalString('1000.00')),
            new SystemValueRecalculated(Money::fromDecimalString('1050.00')),
            new SystemValueFrozen(Money::fromDecimalString('1050.00')),
            new ManualAdjustmentAdded(Money::fromDecimalString('-45.55'), 'Reversing deduction'),
        ]);

        $events = $store->load($id)->domainEvents();

        self::assertCount(4, $events);
        self::assertInstanceOf(EarningLineCalculated::class, $events[0]);
        self::assertInstanceOf(SystemValueRecalculated::class, $events[1]);
        self::assertInstanceOf(SystemValueFrozen::class, $events[2]);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[3]);

        self::assertSame(100000, $events[0]->systemValue->minorUnits);
        self::assertSame(-4555, $events[3]->amount->minorUnits);
        self::assertSame('Reversing deduction', $events[3]->comment);
    }

    public function test_versions_are_assigned_from_one_and_are_contiguous(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [new EarningLineCalculated(Money::fromDecimalString('1000.00'))]);
        $store->append($id, 1, [
            new SystemValueFrozen(Money::fromDecimalString('1000.00')),
            new ManualAdjustmentAdded(Money::fromDecimalString('10.00'), 'Bonus'),
        ]);

        $stream = $store->load($id);

        self::assertSame([1, 2, 3], array_map(
            static fn ($recorded): int => $recorded->streamVersion,
            $stream->events,
        ));
        self::assertSame(3, $stream->currentVersion);
    }

    public function test_a_stale_expected_version_is_rejected(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [new EarningLineCalculated(Money::fromDecimalString('1000.00'))]);

        $this->expectException(ConcurrencyConflict::class);

        // The stream is at version 1 now, so another writer still holding 0 must lose.
        $store->append($id, 0, [new SystemValueRecalculated(Money::fromDecimalString('1050.00'))]);
    }

    public function test_only_one_of_two_writers_at_the_same_version_succeeds(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [new EarningLineCalculated(Money::fromDecimalString('1000.00'))]);

        // Both loaded the line at version 1 and decided independently.
        $store->append($id, 1, [new SystemValueRecalculated(Money::fromDecimalString('1050.00'))]);

        try {
            $store->append($id, 1, [new SystemValueRecalculated(Money::fromDecimalString('900.00'))]);
            self::fail('The second writer should have been rejected.');
        } catch (ConcurrencyConflict) {
            // expected
        }

        $stream = $store->load($id);
        self::assertSame(2, $stream->currentVersion);

        $events = $stream->domainEvents();
        self::assertInstanceOf(SystemValueRecalculated::class, $events[1]);
        self::assertSame(105000, $events[1]->systemValue->minorUnits, 'The winner must be the first writer.');
    }

    public function test_appending_nothing_changes_nothing(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [new EarningLineCalculated(Money::fromDecimalString('1000.00'))]);
        $store->append($id, 1, []);

        self::assertSame(1, $store->load($id)->currentVersion);
    }

    public function test_a_non_ascii_comment_survives_the_round_trip(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        // A comment is free text in an immutable log, where an encoding mistake
        // can never be corrected afterwards.
        $comment = 'Коригування для Zoë Müller — approved 🙂 "final"';

        $store->append($id, 0, [
            new EarningLineCalculated(Money::fromDecimalString('1000.00')),
            new SystemValueFrozen(Money::fromDecimalString('1000.00')),
            new ManualAdjustmentAdded(Money::fromDecimalString('-1.23'), $comment),
        ]);

        $events = $store->load($id)->domainEvents();
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[2]);
        self::assertSame($comment, $events[2]->comment);
    }

    public function test_negative_amounts_survive_the_round_trip(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [
            new EarningLineCalculated(Money::fromDecimalString('1000.00')),
            new SystemValueFrozen(Money::fromDecimalString('1000.00')),
            new ManualAdjustmentAdded(Money::fromMinorUnits(-4555), 'Negative'),
            new ManualAdjustmentAdded(Money::fromMinorUnits(-10), 'Small negative'),
        ]);

        $events = $store->load($id)->domainEvents();

        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[2]);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[3]);
        self::assertSame(-4555, $events[2]->amount->minorUnits);
        self::assertSame(-10, $events[3]->amount->minorUnits);
    }

    public function test_streams_do_not_leak_into_each_other(): void
    {
        $store = $this->createStore();
        $first = EarningLineId::generate();
        $second = EarningLineId::generate();

        $store->append($first, 0, [new EarningLineCalculated(Money::fromDecimalString('1000.00'))]);
        $store->append($second, 0, [new EarningLineCalculated(Money::fromDecimalString('2000.00'))]);

        self::assertSame(1, $store->load($first)->currentVersion);
        self::assertSame(1, $store->load($second)->currentVersion);

        $events = $store->load($second)->domainEvents();
        self::assertInstanceOf(EarningLineCalculated::class, $events[0]);
        self::assertSame(200000, $events[0]->systemValue->minorUnits);
    }

    public function test_every_event_of_one_append_shares_a_timestamp(): void
    {
        $store = $this->createStore();
        $id = EarningLineId::generate();

        $store->append($id, 0, [
            new EarningLineCalculated(Money::fromDecimalString('1000.00')),
            new SystemValueFrozen(Money::fromDecimalString('1000.00')),
        ]);

        $recorded = $store->load($id)->events;

        // Which is exactly why ordering must come from the version, never the clock.
        self::assertEquals($recorded[0]->recordedAt, $recorded[1]->recordedAt);
    }
}
