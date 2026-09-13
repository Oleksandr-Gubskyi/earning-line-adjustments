<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Repository;

use Payroll\Application\Exception\ConcurrencyConflict;
use Payroll\Application\Exception\EarningLineNotFound;
use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLine;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\Money;
use Payroll\Infrastructure\EventStore\InMemoryEventStore;
use Payroll\Infrastructure\Repository\EventSourcedEarningLineRepository;
use PHPUnit\Framework\TestCase;

final class EventSourcedEarningLineRepositoryTest extends TestCase
{
    public function test_an_unknown_line_is_reported_as_not_found(): void
    {
        $repository = new EventSourcedEarningLineRepository(new InMemoryEventStore);

        // Without this the aggregate would fail on an uninitialised typed property,
        // which is what `payroll:show <unknown-uuid>` would otherwise produce.
        $this->expectException(EarningLineNotFound::class);

        $repository->get(EarningLineId::generate());
    }

    public function test_a_saved_line_comes_back_with_the_same_state(): void
    {
        $repository = new EventSourcedEarningLineRepository(new InMemoryEventStore);
        $id = EarningLineId::generate();

        $line = EarningLine::calculate($id, Money::fromDecimalString('1000.00'));
        (void) $line->recalculate(Money::fromDecimalString('1050.00'));
        $line->addManualAdjustment(
            Money::fromDecimalString('-45.55'),
            AdjustmentComment::fromString('Reversing deduction'),
        );
        $repository->save($line);

        $loaded = $repository->get($id);

        self::assertSame('$1,004.45', $loaded->currentValue()->format());
        self::assertSame('$1,050.00', $loaded->systemValue()->format());
        self::assertTrue($loaded->isFrozen());
        self::assertSame(4, $loaded->version());
        self::assertSame([], $loaded->pendingEvents());
    }

    public function test_saving_marks_the_line_committed(): void
    {
        $repository = new EventSourcedEarningLineRepository(new InMemoryEventStore);

        $line = EarningLine::calculate(EarningLineId::generate(), Money::fromDecimalString('1000.00'));
        $repository->save($line);

        self::assertSame(1, $line->version());
        self::assertSame([], $line->pendingEvents());

        // The same instance can go on being used without replaying anything.
        (void) $line->recalculate(Money::fromDecimalString('1050.00'));
        $repository->save($line);

        self::assertSame(2, $line->version());
    }

    public function test_saving_a_line_with_nothing_recorded_is_a_no_op(): void
    {
        $store = new InMemoryEventStore;
        $repository = new EventSourcedEarningLineRepository($store);
        $id = EarningLineId::generate();

        $line = EarningLine::calculate($id, Money::fromDecimalString('1000.00'));
        $line->addManualAdjustment(Money::fromDecimalString('10.00'), AdjustmentComment::fromString('Bonus'));
        $repository->save($line);

        // An ignored recalculation records nothing, so saving must not write.
        (void) $line->recalculate(Money::fromDecimalString('999.00'));
        $repository->save($line);

        self::assertSame(3, $store->load($id)->currentVersion);
    }

    public function test_a_stale_line_cannot_overwrite_a_newer_one(): void
    {
        $store = new InMemoryEventStore;
        $repository = new EventSourcedEarningLineRepository($store);
        $id = EarningLineId::generate();

        $repository->save(EarningLine::calculate($id, Money::fromDecimalString('1000.00')));

        $first = $repository->get($id);
        $second = $repository->get($id);

        (void) $first->recalculate(Money::fromDecimalString('1050.00'));
        $repository->save($first);

        (void) $second->recalculate(Money::fromDecimalString('900.00'));

        $this->expectException(ConcurrencyConflict::class);

        $repository->save($second);
    }
}
