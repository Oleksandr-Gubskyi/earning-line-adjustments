<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Stream;

use DateTimeImmutable;
use Payroll\Application\Exception\CorruptedEventStream;
use Payroll\Application\Stream\EventStream;
use Payroll\Application\Stream\RecordedEvent;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\SystemValueRecalculated;
use Payroll\Domain\Money;
use PHPUnit\Framework\TestCase;

final class EventStreamTest extends TestCase
{
    public function test_an_empty_stream_is_at_version_zero(): void
    {
        $stream = EventStream::empty();

        self::assertTrue($stream->isEmpty());
        self::assertSame(0, $stream->currentVersion);
        self::assertSame([], $stream->domainEvents());
    }

    public function test_the_current_version_is_the_last_recorded_version(): void
    {
        $stream = EventStream::fromRecordedEvents([
            self::recorded(1),
            self::recorded(2),
            self::recorded(3),
        ]);

        self::assertFalse($stream->isEmpty());
        self::assertSame(3, $stream->currentVersion);
        self::assertCount(3, $stream->domainEvents());
    }

    public function test_a_gap_in_the_versions_is_rejected(): void
    {
        // Deriving a version from a plain count would hide this; everything
        // downstream, including the adjustment numbering, depends on the order.
        $this->expectException(CorruptedEventStream::class);

        EventStream::fromRecordedEvents([
            self::recorded(1),
            self::recorded(3),
        ]);
    }

    public function test_a_stream_that_does_not_start_at_one_is_rejected(): void
    {
        $this->expectException(CorruptedEventStream::class);

        EventStream::fromRecordedEvents([self::recorded(2)]);
    }

    public function test_events_out_of_order_are_rejected(): void
    {
        $this->expectException(CorruptedEventStream::class);

        EventStream::fromRecordedEvents([
            self::recorded(2),
            self::recorded(1),
        ]);
    }

    public function test_it_unwraps_the_domain_events_in_order(): void
    {
        $stream = EventStream::fromRecordedEvents([
            new RecordedEvent(
                new EarningLineCalculated(Money::fromDecimalString('1000.00')),
                1,
                new DateTimeImmutable,
            ),
            new RecordedEvent(
                new SystemValueRecalculated(Money::fromDecimalString('1050.00')),
                2,
                new DateTimeImmutable,
            ),
        ]);

        $events = $stream->domainEvents();

        self::assertInstanceOf(EarningLineCalculated::class, $events[0]);
        self::assertInstanceOf(SystemValueRecalculated::class, $events[1]);
        self::assertSame(105000, $events[1]->systemValue->minorUnits);
    }

    private static function recorded(int $version): RecordedEvent
    {
        return new RecordedEvent(
            new EarningLineCalculated(Money::fromDecimalString('1000.00')),
            $version,
            new DateTimeImmutable,
        );
    }
}
